/**
 * Dashboard routes: list sites, enroll a site by config, refresh a site's
 * descriptor by pulling its signed /status endpoint.
 */

import { randomUUID } from 'node:crypto';
import type { FastifyInstance, FastifyRequest } from 'fastify';
import { applyDescriptor, enroll, getById, list, secretFor } from '../repo/sites.js';
import { deploy, pullMetrics, pullStatus, rollback, runAudit } from '../client/siteClient.js';
import { score } from '../score/scorer.js';
import { insertSnapshot, latestForSite, latestOverallBySite, snapshotCount } from '../repo/metrics.js';
import {
  changesFromKeys,
  getByDeployId,
  insertDeployment,
  latestAudit,
  listDeployments,
  saveAudit,
  setRolledBack,
} from '../repo/pipeline.js';
import { addPrompt, latestResults, listPrompts, recordResults } from '../repo/citation.js';
import { makeLlmClient } from '../llm/client.js';
import { sample } from '../citation/sampler.js';

/** The site's domain host for citation detection. */
function siteHost(url: string): string {
  try {
    return new URL(url).host;
  } catch {
    return '';
  }
}

interface EnrollForm {
  label?: string;
  reach_url?: string;
  key_id?: string;
  secret?: string;
}

export function registerDashboard(app: FastifyInstance): void {
  app.get('/', async (_request, reply) => {
    const sites = await list();
    const health = await latestOverallBySite();
    const healthBySite: Record<number, number | null> = {};
    for (const s of sites) {
      const h = health.get(s.id);
      healthBySite[s.id] = h ? h.overall : null;
    }
    return reply.view('sites.ejs', { title: 'Sites', sites, health: healthBySite, now: Date.now() });
  });

  app.get('/sites/:id', async (request: FastifyRequest<{ Params: { id: string } }>, reply) => {
    const id = Number(request.params.id);
    const site = Number.isFinite(id) ? await getById(id) : null;
    if (!site) {
      return reply.code(404).send({ error: 'site not found' });
    }
    const latest = await latestForSite(id);
    const count = await snapshotCount(id);
    const audit = await latestAudit(id);
    const deployments = await listDeployments(id);
    const prompts = await listPrompts(id);
    const citations = await latestResults(id);
    return reply.view('site-detail.ejs', {
      title: site.label || site.site_url,
      site,
      latest,
      count,
      audit,
      deployments,
      prompts,
      citations,
    });
  });

  app.get('/sites/new', async (_request, reply) => {
    return reply.view('new-site.ejs', { title: 'Enroll site', error: '' });
  });

  app.post('/sites', async (request: FastifyRequest<{ Body: EnrollForm }>, reply) => {
    const body = request.body ?? {};
    const label = (body.label ?? '').trim();
    const reachUrl = (body.reach_url ?? '').trim();
    const keyId = (body.key_id ?? '').trim();
    const secret = (body.secret ?? '').trim();

    if (reachUrl === '' || keyId === '' || secret === '') {
      return reply.code(400).view('new-site.ejs', {
        title: 'Enroll site',
        error: 'Reachable URL, key id, and secret are required.',
      });
    }

    try {
      await enroll({ label, reachUrl, keyId, secret });
    } catch (err) {
      const msg = err instanceof Error && /unique/i.test(err.message)
        ? 'A site with that key id is already enrolled.'
        : 'Could not enroll the site.';
      return reply.code(400).view('new-site.ejs', { title: 'Enroll site', error: msg });
    }

    return reply.redirect('/');
  });

  app.post('/sites/:id/refresh', async (request: FastifyRequest<{ Params: { id: string } }>, reply) => {
    const id = Number(request.params.id);
    const site = Number.isFinite(id) ? await getById(id) : null;
    if (!site) {
      return reply.code(404).send({ error: 'site not found' });
    }

    const secret = await secretFor(site.key_id);
    if (secret === null) {
      return reply.code(404).send({ error: 'site secret missing' });
    }

    const status = await pullStatus(site, secret);
    if (status.ok && status.descriptor) {
      await applyDescriptor(site.key_id, status.descriptor);
    }

    const metrics = await pullMetrics(site, secret);
    if (metrics.ok && metrics.signals) {
      const scores = score(metrics.signals);
      await insertSnapshot(site.id, metrics.signals, scores);
    }
    // Re-render the list regardless; failures simply leave stale data in place.
    return reply.redirect('/');
  });

  // --- Pipeline: audit → approve → deploy → rollback ---

  app.post('/sites/:id/audit', async (request: FastifyRequest<{ Params: { id: string } }>, reply) => {
    const id = Number(request.params.id);
    const site = Number.isFinite(id) ? await getById(id) : null;
    if (!site) {
      return reply.code(404).send({ error: 'site not found' });
    }
    const secret = await secretFor(site.key_id);
    if (secret === null) {
      return reply.code(404).send({ error: 'site secret missing' });
    }
    const res = await runAudit(site, secret);
    if (res.ok) {
      await saveAudit(site.id, res.findings);
    }
    return reply.redirect(`/sites/${site.id}`);
  });

  app.post(
    '/sites/:id/deploy',
    async (request: FastifyRequest<{ Params: { id: string }; Body: { keys?: string | string[] } }>, reply) => {
      const id = Number(request.params.id);
      const site = Number.isFinite(id) ? await getById(id) : null;
      if (!site) {
        return reply.code(404).send({ error: 'site not found' });
      }
      const secret = await secretFor(site.key_id);
      if (secret === null) {
        return reply.code(404).send({ error: 'site secret missing' });
      }

      const raw = request.body?.keys;
      const keys = Array.isArray(raw) ? raw : raw ? [raw] : [];
      const audit = await latestAudit(id);
      const changes = audit ? changesFromKeys(audit.findings, keys) : [];
      if (changes.length === 0) {
        return reply.redirect(`/sites/${site.id}`);
      }

      const deployId = `d_${randomUUID()}`;
      const res = await deploy(site, secret, deployId, changes);
      if (res.ok) {
        await insertDeployment(site.id, deployId, changes, res.data);
      }
      return reply.redirect(`/sites/${site.id}`);
    },
  );

  app.post(
    '/deployments/:deployId/rollback',
    async (request: FastifyRequest<{ Params: { deployId: string } }>, reply) => {
      const dep = await getByDeployId(request.params.deployId);
      if (!dep) {
        return reply.code(404).send({ error: 'deployment not found' });
      }
      const site = await getById(dep.site_id);
      const secret = site ? await secretFor(site.key_id) : null;
      if (!site || secret === null) {
        return reply.code(404).send({ error: 'site not found' });
      }
      const res = await rollback(site, secret, dep.deploy_id);
      if (res.ok) {
        await setRolledBack(dep.deploy_id, res.data);
      }
      return reply.redirect(`/sites/${site.id}`);
    },
  );

  // --- Citation tracking (QUEST-style prototype) ---

  app.post(
    '/sites/:id/citation/prompt',
    async (request: FastifyRequest<{ Params: { id: string }; Body: { prompt?: string } }>, reply) => {
      const id = Number(request.params.id);
      const site = Number.isFinite(id) ? await getById(id) : null;
      if (!site) {
        return reply.code(404).send({ error: 'site not found' });
      }
      const prompt = (request.body?.prompt ?? '').trim();
      if (prompt !== '') {
        await addPrompt(site.id, prompt);
      }
      return reply.redirect(`/sites/${site.id}`);
    },
  );

  app.post('/sites/:id/citation/run', async (request: FastifyRequest<{ Params: { id: string } }>, reply) => {
    const id = Number(request.params.id);
    const site = Number.isFinite(id) ? await getById(id) : null;
    if (!site) {
      return reply.code(404).send({ error: 'site not found' });
    }
    const prompts = await listPrompts(id);
    if (prompts.length > 0) {
      const results = await sample(makeLlmClient(), prompts, {
        domain: siteHost(site.site_url || site.reach_url),
        brand: site.label,
      });
      await recordResults(site.id, results);
    }
    return reply.redirect(`/sites/${site.id}`);
  });
}

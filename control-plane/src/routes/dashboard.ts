/**
 * Dashboard routes: list sites, enroll a site by config, refresh a site's
 * descriptor by pulling its signed /status endpoint.
 */

import { randomUUID } from 'node:crypto';
import type { FastifyInstance, FastifyRequest } from 'fastify';
import { enroll, getById, list, secretFor } from '../repo/sites.js';
import { deploy, rollback, runAction, runAudit } from '../client/siteClient.js';
import { latestForSite, latestOverallBySite, snapshotCount } from '../repo/metrics.js';
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
import { siteIdsForUsername } from '../repo/users.js';
import { makeLlmClient } from '../llm/client.js';
import { sample } from '../citation/sampler.js';
import { readSession } from '../auth/session.js';
import { refreshAllSites, refreshSite } from '../services/refresh.js';

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
  app.get('/', async (request, reply) => {
    const me = readSession(request);
    let sites = await list();
    if (me && me.role === 'client') {
      const allowed = new Set(await siteIdsForUsername(me.username));
      sites = sites.filter((s) => allowed.has(s.id));
    }
    const health = await latestOverallBySite();
    const healthBySite: Record<number, number | null> = {};
    for (const s of sites) {
      const h = health.get(s.id);
      healthBySite[s.id] = h ? h.overall : null;
    }
    return reply.view('sites.ejs', { title: 'Sites', user: readSession(request), sites, health: healthBySite, now: Date.now() });
  });

  app.get('/sites/:id', async (request: FastifyRequest<{ Params: { id: string } }>, reply) => {
    const id = Number(request.params.id);
    const me = readSession(request);
    const site = Number.isFinite(id) ? await getById(id) : null;
    if (!site) {
      return reply.code(404).send({ error: 'site not found' });
    }
    if (me && me.role === 'client') {
      const allowed = new Set(await siteIdsForUsername(me.username));
      if (!allowed.has(id)) {
        return reply.code(403).send('Forbidden');
      }
    }
    const latest = await latestForSite(id);
    const count = await snapshotCount(id);
    const audit = await latestAudit(id);
    const deployments = await listDeployments(id);
    const prompts = await listPrompts(id);
    const citations = await latestResults(id);
    return reply.view('site-detail.ejs', {
      title: site.label || site.site_url,
      user: readSession(request),
      site,
      latest,
      count,
      audit,
      deployments,
      prompts,
      citations,
    });
  });

  app.get('/sites/new', async (request, reply) => {
    return reply.view('new-site.ejs', { title: 'Enroll site', user: readSession(request), error: '' });
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
        user: readSession(request),
        error: 'Reachable URL, key id, and secret are required.',
      });
    }

    try {
      await enroll({ label, reachUrl, keyId, secret });
    } catch (err) {
      const msg = err instanceof Error && /unique/i.test(err.message)
        ? 'A site with that key id is already enrolled.'
        : 'Could not enroll the site.';
      return reply.code(400).view('new-site.ejs', { title: 'Enroll site', user: readSession(request), error: msg });
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

    await refreshSite(site, secret);
    // Re-render the list regardless; failures simply leave stale data in place.
    return reply.redirect('/');
  });

  app.post('/refresh-all', async (_request, reply) => {
    await refreshAllSites();
    return reply.redirect('/');
  });

  // --- Bulk operations: fan one action out across selected/all sites ---

  const BULK_ACTIONS = new Set(['sitemap_regen', 'llms_refresh', 'flush_rewrites']);

  app.post(
    '/bulk',
    async (
      request: FastifyRequest<{ Body: { op?: string; sites?: string | string[]; all?: string } }>,
      reply,
    ) => {
      const op = (request.body?.op ?? '').trim();
      let targets;
      if (request.body?.all !== undefined) {
        targets = await list();
      } else {
        const raw = request.body?.sites;
        const ids = new Set((Array.isArray(raw) ? raw : raw ? [raw] : []).map(Number));
        targets = (await list()).filter((s) => ids.has(s.id));
      }

      for (const site of targets) {
        const secret = await secretFor(site.key_id);
        if (secret === null) {
          continue;
        }
        if (op === 'refresh') {
          await refreshSite(site, secret);
        } else if (op === 'audit') {
          const res = await runAudit(site, secret);
          if (res.ok) {
            await saveAudit(site.id, res.findings);
          }
        } else if (BULK_ACTIONS.has(op)) {
          await runAction(site, secret, op);
        }
      }
      return reply.redirect('/');
    },
  );

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
      // Only flip to rolled_back when the site actually reverted something; if
      // every change was skipped (e.g. edited since deploy) the values are still
      // live, so the deployment stays "deployed".
      const restored = ((res.data as { restored?: number } | undefined)?.restored) ?? 0;
      if (res.ok && restored > 0) {
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

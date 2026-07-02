/**
 * Dashboard routes: list sites, enroll a site by config, refresh a site's
 * descriptor by pulling its signed /status endpoint.
 */

import { randomUUID } from 'node:crypto';
import type { FastifyInstance, FastifyRequest } from 'fastify';
import { enroll, getById, list, secretFor, setSiteTenant, siteIdsForTenant } from '../repo/sites.js';
import { pendingCounts, pendingCountFor, replayForSite } from '../repo/unroutedLeads.js';
import { listCrmTenants } from '../platform/crmTenants.js';
import { tenantIdFromClientUsername } from './sso-user.js';
import { deploy, rollback, runAction } from '../client/siteClient.js';
import { latestForSite, latestOverallBySite, snapshotCount } from '../repo/metrics.js';
import {
  changesFromKeys,
  getByDeployId,
  insertDeployment,
  latestAudit,
  listDeployments,
  setRolledBack,
} from '../repo/pipeline.js';
import { explainLatestAudit } from '../services/auditExplain.js';
import { generateAeo } from '../services/aeoGenerate.js';
import { consumeLlmCall } from '../repo/llmBudget.js';
import { config } from '../config.js';
import { pullGscOpportunities } from '../client/siteClient.js';
import { provisionSite } from '../platform/provisioning.js';
import { addPrompt, citationSummary, latestResults, listPrompts } from '../repo/citation.js';
import { siteIdsForUsername, idForUsername, setUserSites } from '../repo/users.js';
import { readSession } from '../auth/session.js';
import { refreshAllSites, refreshSite } from '../services/refresh.js';
import { auditSite } from '../services/audit.js';
import { citationSite } from '../services/citation.js';

interface EnrollForm {
  label?: string;
  reach_url?: string;
  key_id?: string;
  secret?: string;
  platform_tenant_id?: string;
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
    const parked = await pendingCounts();
    const parkedBySite: Record<number, number> = {};
    for (const s of sites) parkedBySite[s.id] = parked.get(s.id) ?? 0;
    return reply.view('sites.ejs', { title: 'Sites', user: readSession(request), sites, health: healthBySite, parked: parkedBySite, now: Date.now() });
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
    const citation = await citationSummary(id);
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
      citation,
      clients: await listCrmTenants(),
      parkedLeads: await pendingCountFor(id),
    });
  });

  app.get('/sites/new', async (request, reply) => {
    const me = readSession(request);
    // Only admins pick a client; a self-enrolling client is pinned to their own tenant.
    const clients = me?.role === 'admin' ? await listCrmTenants() : [];
    return reply.view('new-site.ejs', { title: 'Enroll site', user: me, error: '', clients });
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

    // Admins may map to any client; a client self-enrolling is PINNED to their own
    // tenant (from the session), so they can only connect a site to their own account.
    const session = readSession(request);
    const platformTenantId = session?.role === 'client'
      ? tenantIdFromClientUsername(session.username)
      : (body.platform_tenant_id ?? '').trim() || null;
    try {
      await enroll({ label, reachUrl, keyId, secret, platformTenantId });
      // A self-enrolling client: refresh their site scope so the new site shows
      // immediately (cp_user_sites is otherwise only set at SSO login).
      if (session?.role === 'client' && platformTenantId) {
        const uid = await idForUsername(session.username);
        if (uid != null) await setUserSites(uid, await siteIdsForTenant(platformTenantId));
      }
      // Zero-touch: push the tenant's platform embed keys to the freshly-enrolled
      // site so the chat widget (+ analytics) light up. Best-effort — never fails
      // the enrollment.
      if (platformTenantId) {
        const r = await provisionSite({ reach_url: reachUrl, key_id: keyId }, secret, platformTenantId);
        if (!r.ok && r.error) request.log.warn(`provisioning: ${r.error}`);
        else if (r.pushed.length) request.log.info(`provisioned ${r.pushed.join(', ')} to ${keyId}`);
      }
    } catch (err) {
      const msg = err instanceof Error && /unique/i.test(err.message)
        ? 'A site with that key id is already enrolled.'
        : 'Could not enroll the site.';
      return reply.code(400).view('new-site.ejs', { title: 'Enroll site', user: readSession(request), error: msg });
    }

    return reply.redirect('/');
  });

  // Map (or clear with an empty value) a site's CRM client. admin-only via the
  // global write-guard (server.ts). Drives the per-client SSO scope.
  app.post('/sites/:id/tenant', async (request: FastifyRequest<{ Params: { id: string }; Body: { platform_tenant_id?: string } }>, reply) => {
    const id = Number(request.params.id);
    if (!Number.isFinite(id) || !(await getById(id))) {
      return reply.code(404).send({ error: 'site not found' });
    }
    const tenantId = (request.body?.platform_tenant_id ?? '').trim() || null;
    await setSiteTenant(id, tenantId);
    // Mapping unblocks routing: replay any leads parked while the site was unmapped.
    if (tenantId) {
      const replayed = await replayForSite(id, tenantId);
      if (replayed > 0) request.log.info(`replayed ${replayed} parked lead(s) for site ${id} → tenant ${tenantId}`);
    }
    return reply.redirect(`/sites/${id}`);
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
          await auditSite(site, secret);
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
    await auditSite(site, secret);
    return reply.redirect(`/sites/${site.id}`);
  });

  // Generate (or refresh) the AI plain-language explanation of the latest audit.
  app.post('/sites/:id/audit/explain', async (request: FastifyRequest<{ Params: { id: string } }>, reply) => {
    const id = Number(request.params.id);
    const me = readSession(request);
    const site = Number.isFinite(id) ? await getById(id) : null;
    if (!site) return reply.code(404).send({ error: 'site not found' });
    if (me && me.role === 'client') {
      const allowed = new Set(await siteIdsForUsername(me.username));
      if (!allowed.has(id)) return reply.code(403).send('Forbidden');
    }
    if (site.platform_tenant_id && !(await consumeLlmCall(site.platform_tenant_id, config.seoLlmDailyLimit))) {
      return reply.code(429).send('Daily AI limit reached for this client. Please try again tomorrow.');
    }
    await explainLatestAudit(id, { force: true });
    return reply.redirect(`/sites/${site.id}`);
  });

  // AEO/GEO content generator: form (GET) + generate (POST), rendered on one page.
  async function aeoGuard(request: FastifyRequest<{ Params: { id: string } }>, reply: import('fastify').FastifyReply) {
    const id = Number(request.params.id);
    const me = readSession(request);
    const site = Number.isFinite(id) ? await getById(id) : null;
    if (!site) { reply.code(404).send({ error: 'site not found' }); return null; }
    if (me && me.role === 'client') {
      const allowed = new Set(await siteIdsForUsername(me.username));
      if (!allowed.has(id)) { reply.code(403).send('Forbidden'); return null; }
    }
    return { site, me };
  }

  app.get('/sites/:id/aeo', async (request: FastifyRequest<{ Params: { id: string } }>, reply) => {
    const ctx = await aeoGuard(request, reply);
    if (!ctx) return reply;
    return reply.view('aeo.ejs', { title: 'AI search content', user: ctx.me, site: ctx.site, result: null, form: {}, attempted: false });
  });

  app.post('/sites/:id/aeo', async (request: FastifyRequest<{ Params: { id: string }; Body: { topic?: string; url?: string; notes?: string } }>, reply) => {
    const ctx = await aeoGuard(request, reply);
    if (!ctx) return reply;
    const b = request.body ?? {};
    const form = { topic: (b.topic ?? '').trim(), url: (b.url ?? '').trim(), notes: (b.notes ?? '').trim() };
    if (form.topic && ctx.site.platform_tenant_id && !(await consumeLlmCall(ctx.site.platform_tenant_id, config.seoLlmDailyLimit))) {
      return reply.code(429).send('Daily AI limit reached for this client. Please try again tomorrow.');
    }
    const result = form.topic
      ? await generateAeo({ ...form, siteLabel: ctx.site.label || ctx.site.site_url })
      : null;
    return reply.view('aeo.ejs', { title: 'AI search content', user: ctx.me, site: ctx.site, result, form, attempted: !!form.topic });
  });

  // Deploy generated JSON-LD to a specific post via the signed /apply path.
  app.post('/sites/:id/aeo/deploy', async (request: FastifyRequest<{ Params: { id: string }; Body: { post_id?: string; jsonld?: string } }>, reply) => {
    const ctx = await aeoGuard(request, reply);
    if (!ctx) return reply;
    const b = request.body ?? {};
    const postId = Number(b.post_id);
    let nodes: unknown = null;
    try { nodes = JSON.parse(b.jsonld ?? '[]'); } catch { nodes = null; }
    const view = (deployMsg: string) =>
      reply.view('aeo.ejs', { title: 'AI search content', user: ctx.me, site: ctx.site, result: null, form: {}, attempted: false, deployMsg });

    if (!Number.isInteger(postId) || postId <= 0 || !Array.isArray(nodes) || nodes.length === 0) {
      return view('Enter a valid WordPress post ID and generate schema first.');
    }
    const secret = await secretFor(ctx.site.key_id);
    if (secret === null) return view('This site has no shared secret configured.');

    const deployId = `d_${randomUUID()}`;
    const changes = [{ type: 'post', id: postId, field: 'schema_jsonld', value: JSON.stringify(nodes) }];
    const res = await deploy(ctx.site, secret, deployId, changes);
    if (res.ok) {
      await insertDeployment(ctx.site.id, deployId, changes, res.data);
      return view(`✅ Schema deployed to post #${postId}. You can roll it back from the site’s Deployments.`);
    }
    return view(`Deploy failed: ${res.error ?? `the site returned ${res.status}`}. (The site’s plugin must be v0.5.0+.)`);
  });

  // Re-push the tenant's platform embed keys (chat widget / analytics) to the site.
  app.post('/sites/:id/provision', async (request: FastifyRequest<{ Params: { id: string } }>, reply) => {
    const ctx = await aeoGuard(request, reply);
    if (!ctx) return reply;
    const secret = await secretFor(ctx.site.key_id);
    if (secret !== null && ctx.site.platform_tenant_id) {
      const r = await provisionSite(ctx.site, secret, ctx.site.platform_tenant_id);
      if (!r.ok && r.error) request.log.warn(`re-provision: ${r.error}`);
    }
    return reply.redirect(`/sites/${ctx.site.id}`);
  });

  // GSC content-gap / search-opportunities miner: pull striking-distance queries +
  // low-CTR pages live from the site (plugin >= 0.2.0) and render them.
  app.get('/sites/:id/gsc', async (request: FastifyRequest<{ Params: { id: string } }>, reply) => {
    const ctx = await aeoGuard(request, reply);
    if (!ctx) return reply;
    const secret = await secretFor(ctx.site.key_id);
    let opp = null;
    let error: string | null = null;
    if (secret === null) {
      error = 'This site has no shared secret configured.';
    } else {
      const r = await pullGscOpportunities(ctx.site, secret);
      if (r.ok && r.data) opp = r.data;
      else error = r.status === 404 ? 'This site’s plugin is too old for search opportunities (needs v0.2.0+).' : (r.error ?? 'Could not reach the site.');
    }
    return reply.view('gsc.ejs', { title: 'Search opportunities', user: ctx.me, site: ctx.site, opp, error });
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
    await citationSite(site);
    return reply.redirect(`/sites/${site.id}`);
  });
}

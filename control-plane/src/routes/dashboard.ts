/**
 * Dashboard routes: list sites, enroll a site by config, refresh a site's
 * descriptor by pulling its signed /status endpoint.
 */

import type { FastifyInstance, FastifyRequest } from 'fastify';
import { applyDescriptor, enroll, getById, list, secretFor } from '../repo/sites.js';
import { pullStatus } from '../client/siteClient.js';

interface EnrollForm {
  label?: string;
  reach_url?: string;
  key_id?: string;
  secret?: string;
}

export function registerDashboard(app: FastifyInstance): void {
  app.get('/', async (_request, reply) => {
    const sites = await list();
    return reply.view('sites.ejs', { title: 'Sites', sites, now: Date.now() });
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

    const result = await pullStatus(site, secret);
    if (result.ok && result.descriptor) {
      await applyDescriptor(site.key_id, result.descriptor);
    }
    // Re-render the list regardless; failures simply leave stale data in place.
    return reply.redirect('/');
  });
}

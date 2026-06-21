/**
 * wp-admin SSO deep-link (P-D15 polish). GET /launch/:siteId — for a logged-in
 * control-plane user with access to the site, mint a short-lived HMAC-signed SSO
 * token and 302 to the site's wp-admin receiver, so "SEO" in the unified shell
 * lands authenticated in WordPress.
 */

import type { FastifyInstance, FastifyRequest } from 'fastify';
import { getById, secretFor } from '../repo/sites.js';
import { siteIdsForUsername } from '../repo/users.js';
import { readSession } from '../auth/session.js';
import { buildWpSsoUrl } from '../platform/wpSso.js';

export function registerLaunch(app: FastifyInstance): void {
  app.get('/launch/:siteId', async (request: FastifyRequest<{ Params: { siteId: string } }>, reply) => {
    const session = readSession(request);
    if (!session) {
      return reply.redirect('/login');
    }

    const siteId = Number(request.params.siteId);
    if (!Number.isInteger(siteId)) {
      return reply.code(400).send('invalid site id');
    }

    // Access control: admins see all sites; others only their assigned ones.
    if (session.role !== 'admin') {
      const allowed = await siteIdsForUsername(session.username);
      if (!allowed.includes(siteId)) {
        return reply.code(403).send('forbidden');
      }
    }

    const site = await getById(siteId);
    if (!site) {
      return reply.code(404).send('site not found');
    }
    const secret = await secretFor(site.key_id);
    if (secret === null) {
      return reply.code(502).send('site secret unavailable');
    }

    const url = buildWpSsoUrl(
      site.site_url,
      { username: session.username, role: session.role },
      secret,
      Math.floor(Date.now() / 1000),
    );
    return reply.redirect(url);
  });
}

/**
 * Branded, printable per-site SEO health report.
 *
 * Rendered as a standalone HTML document (NOT through the dashboard layout) so
 * it prints cleanly to PDF and carries the white-label branding. Accessible to
 * admin/viewer for any site and to a client for its own assigned sites.
 */

import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import ejs from 'ejs';
import type { FastifyInstance, FastifyRequest } from 'fastify';
import { getById } from '../repo/sites.js';
import { latestForSite } from '../repo/metrics.js';
import { latestAudit } from '../repo/pipeline.js';
import { getBranding } from '../repo/settings.js';
import { siteIdsForUsername } from '../repo/users.js';
import { readSession } from '../auth/session.js';

const reportTemplate = join(dirname(fileURLToPath(import.meta.url)), '..', 'views', 'report.ejs');

export function registerReports(app: FastifyInstance): void {
  app.get('/sites/:id/report', async (request: FastifyRequest<{ Params: { id: string } }>, reply) => {
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
    const audit = await latestAudit(id);
    const brand = await getBranding();
    const html = await ejs.renderFile(reportTemplate, {
      brand,
      site,
      latest,
      audit,
      generatedAt: new Date().toLocaleString(),
    });
    return reply.type('text/html').send(html);
  });
}

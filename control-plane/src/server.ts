/**
 * Control-plane HTTP server (Fastify).
 *
 * Registers the EJS view engine, a JSON parser that preserves the RAW body
 * (required for HMAC verification of signed announce requests), form-body
 * parsing for the dashboard, and the route groups.
 */

import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import Fastify, { type FastifyRequest } from 'fastify';
import formbody from '@fastify/formbody';
import view from '@fastify/view';
import ejs from 'ejs';
import { config } from './config.js';
import { registerAnnounce } from './routes/announce.js';
import { registerDashboard } from './routes/dashboard.js';
import { registerTemplates } from './routes/templates.js';
import { registerSettings } from './routes/settings.js';
import { registerReports } from './routes/reports.js';
import { registerAuth, seedAdmin } from './routes/auth.js';
import { getBranding } from './repo/settings.js';
import { readSession } from './auth/session.js';
import { startScheduler } from './scheduler.js';
import { startEventSpine } from './platform/spine.js';

const viewsDir = join(dirname(fileURLToPath(import.meta.url)), 'views');

// Paths reachable without a dashboard session: health, the login form, and the
// HMAC-signed site→plane announce endpoint (authenticated by its own signature).
const PUBLIC_PATHS = new Set(['/healthz', '/login', '/sites/announce']);

export async function build() {
  const app = Fastify({ logger: true });

  // Preserve the raw JSON body so signed requests can be verified byte-for-byte.
  app.addContentTypeParser(
    'application/json',
    { parseAs: 'string' },
    (request: FastifyRequest & { rawBody?: string }, body, done) => {
      const raw = typeof body === 'string' ? body : body.toString('utf8');
      request.rawBody = raw;
      if (raw === '') {
        done(null, {});
        return;
      }
      try {
        done(null, JSON.parse(raw));
      } catch (err) {
        done(err instanceof Error ? err : new Error('invalid JSON'), undefined);
      }
    },
  );

  await app.register(formbody);
  await app.register(view, { engine: { ejs }, root: viewsDir, layout: 'layout.ejs' });

  // Auth guard: public paths pass; everything else needs a session, and any
  // state-changing request (non-GET) needs the admin role (viewers are read-only).
  app.addHook('onRequest', async (request, reply) => {
    const path = request.url.split('?')[0];
    if (PUBLIC_PATHS.has(path)) {
      return;
    }
    const session = readSession(request);
    if (!session) {
      return reply.redirect('/login');
    }
    if (path === '/logout') {
      return; // any signed-in user may log out
    }
    if (request.method !== 'GET' && session.role !== 'admin') {
      return reply.code(403).send('Forbidden: your role is read-only.');
    }
  });

  // Inject white-label branding into every rendered view (cached after first read).
  app.addHook('preHandler', async (_request, reply) => {
    reply.locals = { ...(reply.locals ?? {}), brand: await getBranding() };
  });

  app.get('/healthz', async () => ({ ok: true }));
  registerAuth(app);
  registerAnnounce(app);
  registerDashboard(app);
  registerTemplates(app);
  registerSettings(app);
  registerReports(app);

  await seedAdmin(app.log);
  startScheduler(app.log);
  startEventSpine(app.log);
  return app;
}

const invokedDirectly = process.argv[1] === fileURLToPath(import.meta.url);
if (invokedDirectly) {
  build()
    .then((app) => app.listen({ port: config.port, host: '0.0.0.0' }))
    .catch((err) => {
      console.error('failed to start control plane:', err);
      process.exit(1);
    });
}

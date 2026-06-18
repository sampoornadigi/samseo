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

const viewsDir = join(dirname(fileURLToPath(import.meta.url)), 'views');

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

  app.get('/healthz', async () => ({ ok: true }));
  registerAnnounce(app);
  registerDashboard(app);

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

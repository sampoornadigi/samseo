// @fastify/view decorates the reply with `locals` (merged into every rendered
// view) but only types `view`. Declare `locals` so we can inject branding.
import '@fastify/view';

declare module 'fastify' {
  interface FastifyReply {
    locals?: Record<string, unknown>;
  }
}

# Platform integration — JWKS SSO + event spine (SEO control plane)

Wires the SEO control plane into the Sampoorna Growth Platform. Design lives in
the Sampark repo under `docs/platform/`. WordPress stays a thin **producer**
(P-D6) — it forwards lead capture to this control plane, which owns the outbox.

## SSO via JWKS (P-D7)
`jwksVerify.ts` verifies **Sampoorna-Identity** RS256 tokens against the published
JWKS — no shared secret. Use as a Fastify preHandler:

```ts
import { requirePlatformJwt } from './platform/jwksVerify.js';
app.get('/api/...', { preHandler: requirePlatformJwt }, handler); // request.principal = { userId, tenantId, role, entitlements }
```

Env: `PLATFORM_JWKS_URL`, `PLATFORM_JWT_ISSUER` (default `sampoorna-identity`).
The token's `tid` is the platform tenant id; map it to the local site/tenant.

## Event spine (P-D13)
Transactional **outbox → Redis Streams → idempotent consumers**, mirroring
Sampark. The outbox is the durable event store; Redis is transport.
- `events.ts` (envelope), `outbox.ts` (`enqueue` in the business tx + `relayBatch`),
  `consumer.ts` (`pump` — dedupe on `event.id`, pending-retry, DLQ).
- migration `009_platform_event_spine.sql` — `outbox`, `processed_events`, `event_dlq`.
- **Produces** `seo.lead.captured` (→ CRM). **Consumes** `identity.tenant.created`
  (provision a site/tenant). Run the relay + consumers from `scheduler.ts` with an
  `ioredis` connection to the shared Redis.

## Tests
`npm test` (vitest) — offline unit tests for JWKS verify (local keypair) + the
event envelope. No DB/Redis required.

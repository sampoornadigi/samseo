/**
 * POST /sites/lead — inbound site→plane lead capture (Phase 4 SEO→CRM flow).
 *
 * The WordPress plugin signs a form submission (same Handshake/Signer as
 * /sites/announce) and posts it here. We verify it, resolve the site's platform
 * tenant, and enqueue `seo.lead.captured` into the outbox; the relay publishes it
 * and the CRM consumer turns it into a contact (source=seo) + attribution.
 *
 * Carries raw lead PII (name/phone/email) so the CRM lead is directly
 * contactable — the visitor consented by submitting the form; the bus is internal.
 */

import { randomUUID } from 'node:crypto';
import type { FastifyInstance, FastifyRequest } from 'fastify';
import { verify } from '../crypto/signer.js';
import { config } from '../config.js';
import { secretFor } from '../repo/sites.js';
import { pool } from '../db/pool.js';

const ROUTE = '/sites/lead';

interface WithRawBody {
  rawBody?: string;
}

interface LeadBody {
  name?: string;
  email?: string;
  phone?: string;
  utm?: { source?: string; medium?: string; campaign?: string; term?: string; content?: string };
  gclid?: string;
  fbclid?: string;
  landingPage?: string;
  referrer?: string;
}

export function registerLead(app: FastifyInstance): void {
  app.post(ROUTE, async (request: FastifyRequest, reply) => {
    const keyId = String(request.headers['x-sampoorna-key-id'] ?? '');
    const timestamp = String(request.headers['x-sampoorna-timestamp'] ?? '');
    const signature = String(request.headers['x-sampoorna-signature'] ?? '');
    const rawBody = (request as FastifyRequest & WithRawBody).rawBody ?? '';

    if (keyId === '' || timestamp === '' || signature === '') {
      return reply.code(401).send({ error: 'missing signature headers' });
    }
    const secret = await secretFor(keyId);
    if (secret === null) return reply.code(401).send({ error: 'unknown key id' });

    const ts = Number(timestamp);
    if (!Number.isFinite(ts) || Math.abs(Math.floor(Date.now() / 1000) - ts) > config.skewSeconds) {
      return reply.code(401).send({ error: 'timestamp outside skew window' });
    }
    if (!verify('POST', ROUTE, timestamp, rawBody, signature, secret)) {
      return reply.code(401).send({ error: 'invalid signature' });
    }

    // Resolve the site's platform tenant (Phase 1 mapping). If unmapped, accept
    // the request but don't route — never fail the visitor's form submission.
    const { rows } = await pool.query<{ id: number; platform_tenant_id: string | null }>(
      'SELECT id, platform_tenant_id FROM sites WHERE key_id = $1',
      [keyId],
    );
    const site = rows[0];
    if (!site) return reply.code(401).send({ error: 'unknown key id' });
    if (!site.platform_tenant_id) {
      request.log.warn(`lead for unmapped site ${site.id} — set platform_tenant_id to route it`);
      return reply.code(202).send({ lead: 'accepted_unrouted' });
    }

    const b = (request.body ?? {}) as LeadBody;
    await pool.query(
      `INSERT INTO outbox (event_id, type, version, tenant_id, source, data)
       VALUES ($1, 'seo.lead.captured', 1, $2, 'seo', $3)`,
      [
        `evt_${randomUUID()}`,
        site.platform_tenant_id,
        JSON.stringify({
          name: b.name,
          phone: b.phone,
          email: b.email,
          utm: b.utm ?? {},
          gclid: b.gclid,
          fbclid: b.fbclid,
          landingPage: b.landingPage,
          referrer: b.referrer,
        }),
      ],
    );
    return reply.code(200).send({ lead: 'captured' });
  });
}

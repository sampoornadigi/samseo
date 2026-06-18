/**
 * POST /sites/announce — inbound site→plane handshake.
 *
 * Proves the site→plane direction: the plugin's Handshake::announce() signs the
 * descriptor (PHP) and we verify it here (Node). Authentication mirrors
 * Handshake::verify_request: key_id selects the secret, timestamp must be within
 * the skew window, and the HMAC is checked against the RAW request body.
 */

import type { FastifyInstance, FastifyRequest } from 'fastify';
import { verify } from '../crypto/signer.js';
import { config } from '../config.js';
import { applyDescriptor, secretFor, type Descriptor } from '../repo/sites.js';

const ROUTE = '/sites/announce';

// Raw body captured by the JSON content-type parser (see server.ts).
interface WithRawBody {
  rawBody?: string;
}

export function registerAnnounce(app: FastifyInstance): void {
  app.post(ROUTE, async (request: FastifyRequest, reply) => {
    const keyId = String(request.headers['x-sampoorna-key-id'] ?? '');
    const timestamp = String(request.headers['x-sampoorna-timestamp'] ?? '');
    const signature = String(request.headers['x-sampoorna-signature'] ?? '');
    const rawBody = (request as FastifyRequest & WithRawBody).rawBody ?? '';

    if (keyId === '' || timestamp === '' || signature === '') {
      return reply.code(401).send({ error: 'missing signature headers' });
    }

    const secret = await secretFor(keyId);
    if (secret === null) {
      return reply.code(401).send({ error: 'unknown key id' });
    }

    const ts = Number(timestamp);
    if (!Number.isFinite(ts) || Math.abs(Math.floor(Date.now() / 1000) - ts) > config.skewSeconds) {
      return reply.code(401).send({ error: 'timestamp outside skew window' });
    }

    if (!verify('POST', ROUTE, timestamp, rawBody, signature, secret)) {
      return reply.code(401).send({ error: 'invalid signature' });
    }

    const descriptor = (request.body ?? {}) as Descriptor;
    await applyDescriptor(keyId, descriptor);
    return reply.code(200).send({ announce: 'ok' });
  });
}

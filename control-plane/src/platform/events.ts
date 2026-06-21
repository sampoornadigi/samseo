/**
 * Platform event spine for the SEO control plane (decision P-D13). Mirrors the
 * Sampark implementation: transactional outbox → Redis Streams → idempotent
 * consumers. The outbox is the durable event store; Redis is transport. PII
 * crosses only as hashes (P-D8).
 */

import { randomUUID } from 'node:crypto';

export const EVENT_SOURCE = 'seo';

export const EventType = {
  LeadCaptured: 'seo.lead.captured', // produced → CRM
  TenantCreated: 'identity.tenant.created', // consumed → provision a site/tenant
} as const;

export interface PlatformEvent<T = Record<string, unknown>> {
  id: string;
  type: string;
  version: number;
  tenantId: string;
  occurredAt: string;
  source: string;
  data: T;
}

export function makeEvent<T extends Record<string, unknown>>(
  type: string,
  tenantId: string,
  data: T,
  version = 1,
): PlatformEvent<T> {
  return {
    id: `evt_${randomUUID()}`,
    type,
    version,
    tenantId,
    occurredAt: new Date().toISOString(),
    source: EVENT_SOURCE,
    data,
  };
}

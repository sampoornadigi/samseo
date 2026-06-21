-- Platform identity adoption (Phase 1): map each enrolled site to the shared
-- platform tenant id, so SEO usage can be metered into the one platform wallet
-- (keyed by tenantId). Nullable + backfilled out of band; no big-bang.
ALTER TABLE sites ADD COLUMN IF NOT EXISTS platform_tenant_id TEXT;

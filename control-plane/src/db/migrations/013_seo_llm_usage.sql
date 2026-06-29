-- Per-client daily LLM-call counter, so a single client can't run up unbounded
-- AI spend on the shared agency key. Entitlement gates ACCESS; this caps VOLUME.
-- One row per (client tenant, day); incremented atomically before each LLM call.
CREATE TABLE IF NOT EXISTS seo_llm_usage (
  platform_tenant_id text NOT NULL,
  usage_date date NOT NULL DEFAULT current_date,
  calls integer NOT NULL DEFAULT 0,
  PRIMARY KEY (platform_tenant_id, usage_date)
);

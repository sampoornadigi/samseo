-- Leads captured from a site with no platform_tenant_id mapping used to be
-- 202-acknowledged and DROPPED — the visitor's form data vanished before ever
-- reaching the CRM. Persist them instead; when the site is later mapped to a
-- tenant they are replayed onto the outbox (routed_at marks the replay).
CREATE TABLE IF NOT EXISTS unrouted_leads (
  id bigserial PRIMARY KEY,
  site_id integer NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
  payload jsonb NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now(),
  routed_at timestamptz
);
CREATE INDEX IF NOT EXISTS unrouted_leads_pending_idx ON unrouted_leads (site_id) WHERE routed_at IS NULL;

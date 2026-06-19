-- Audit findings (latest per site) and deployment records for the
-- audit → approve → deploy → rollback pipeline.
CREATE TABLE IF NOT EXISTS audits (
    id          SERIAL PRIMARY KEY,
    site_id     INTEGER     NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    captured_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    findings    JSONB       NOT NULL DEFAULT '[]'::jsonb
);

CREATE INDEX IF NOT EXISTS idx_audits_site_time ON audits (site_id, captured_at DESC);

CREATE TABLE IF NOT EXISTS deployments (
    id             SERIAL PRIMARY KEY,
    site_id        INTEGER     NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    deploy_id      TEXT        NOT NULL UNIQUE,
    status         TEXT        NOT NULL DEFAULT 'deployed',
    changes        JSONB       NOT NULL DEFAULT '[]'::jsonb,
    result         JSONB,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
    rolled_back_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_deployments_site_time ON deployments (site_id, created_at DESC);

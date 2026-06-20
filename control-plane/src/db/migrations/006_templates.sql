-- Config templates: named bundles of site-level settings (by vertical) that an
-- admin pushes to one or many sites. A push is delivered as an ordinary signed
-- deployment of option-type changes, so it shares the reversible deploy journal.
CREATE TABLE IF NOT EXISTS templates (
    id         SERIAL PRIMARY KEY,
    name       TEXT        NOT NULL,
    vertical   TEXT        NOT NULL DEFAULT '',
    settings   JSONB       NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Sites enrolled in the control plane. One row per WordPress install.
CREATE TABLE IF NOT EXISTS sites (
    id              SERIAL PRIMARY KEY,
    label           TEXT        NOT NULL DEFAULT '',
    -- Public home URL reported by the site (display only).
    site_url        TEXT        NOT NULL DEFAULT '',
    -- URL the plane actually fetches (may differ from site_url in dev/containers).
    reach_url       TEXT        NOT NULL,
    -- Rotation id selecting the shared secret; unique per site.
    key_id          TEXT        NOT NULL UNIQUE,
    -- Shared HMAC secret, AES-256-GCM encrypted at rest (see crypto/vault.ts).
    secret_enc      TEXT        NOT NULL,
    plugin_version  TEXT,
    wp_version      TEXT,
    modules         JSONB       NOT NULL DEFAULT '{}'::jsonb,
    last_seen       TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
);

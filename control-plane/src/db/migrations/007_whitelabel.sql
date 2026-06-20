-- White-label: a key/value settings store (branding, alerts) and a mapping of
-- client users to the sites they may see.
CREATE TABLE IF NOT EXISTS cp_settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
);

-- Client users (role = 'client') are scoped to the sites listed here. Admin and
-- viewer roles are unscoped (see all sites); this table is empty for them.
CREATE TABLE IF NOT EXISTS cp_user_sites (
    user_id INTEGER NOT NULL REFERENCES cp_users(id) ON DELETE CASCADE,
    site_id INTEGER NOT NULL REFERENCES sites(id)    ON DELETE CASCADE,
    PRIMARY KEY (user_id, site_id)
);

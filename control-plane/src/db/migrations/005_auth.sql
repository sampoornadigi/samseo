-- Control-plane operators (dashboard login). Roles: admin (full) | viewer (read-only).
CREATE TABLE IF NOT EXISTS cp_users (
    id            SERIAL PRIMARY KEY,
    username      TEXT        NOT NULL UNIQUE,
    password_hash TEXT        NOT NULL,
    role          TEXT        NOT NULL DEFAULT 'viewer',
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

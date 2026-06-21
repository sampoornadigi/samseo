-- Platform event spine (P-D13): transactional outbox (also the durable event
-- store), per-consumer idempotency, and a dead-letter table. Lets the SEO control
-- plane produce seo.lead.captured and consume identity.tenant.created reliably.
-- Mirrors Sampark's outbox/processed_events/event_dlq.

CREATE TABLE IF NOT EXISTS outbox (
    id               BIGSERIAL    PRIMARY KEY,
    event_id         TEXT         NOT NULL UNIQUE,   -- envelope id consumers dedupe on
    type             TEXT         NOT NULL,
    version          INTEGER      NOT NULL DEFAULT 1,
    tenant_id        TEXT         NOT NULL,
    source           TEXT         NOT NULL,
    data             JSONB        NOT NULL,
    occurred_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    created_at       TIMESTAMPTZ  NOT NULL DEFAULT now(),
    sent_at          TIMESTAMPTZ,                    -- null = unsent; set = published (retained for replay)
    publish_attempts INTEGER      NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS outbox_unsent_idx ON outbox (created_at) WHERE sent_at IS NULL;

CREATE TABLE IF NOT EXISTS processed_events (
    consumer     TEXT         NOT NULL,
    event_id     TEXT         NOT NULL,
    processed_at TIMESTAMPTZ  NOT NULL DEFAULT now(),
    PRIMARY KEY (consumer, event_id)
);

CREATE TABLE IF NOT EXISTS event_dlq (
    id        BIGSERIAL    PRIMARY KEY,
    consumer  TEXT         NOT NULL,
    event_id  TEXT         NOT NULL,
    type      TEXT         NOT NULL,
    tenant_id TEXT,
    data      JSONB        NOT NULL,
    error     TEXT,
    failed_at TIMESTAMPTZ  NOT NULL DEFAULT now()
);

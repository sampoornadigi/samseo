-- Health-score snapshots: one row per site per pull (history kept for trends).
CREATE TABLE IF NOT EXISTS site_metrics (
    id          SERIAL PRIMARY KEY,
    site_id     INTEGER     NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    captured_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    signals     JSONB       NOT NULL,
    scores      JSONB       NOT NULL,
    overall     INTEGER
);

CREATE INDEX IF NOT EXISTS idx_site_metrics_site_time
    ON site_metrics (site_id, captured_at DESC);

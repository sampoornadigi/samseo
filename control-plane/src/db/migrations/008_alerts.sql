-- Last alert state per site, so the alerter only fires on a state *change*
-- (healthy -> low/unreachable, or recovery back to ok) instead of every refresh.
CREATE TABLE IF NOT EXISTS alert_state (
    site_id    INTEGER     PRIMARY KEY REFERENCES sites(id) ON DELETE CASCADE,
    kind       TEXT        NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

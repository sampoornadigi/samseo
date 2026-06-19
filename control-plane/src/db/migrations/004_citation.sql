-- LLM citation tracking: per-site prompts and the sampled results.
CREATE TABLE IF NOT EXISTS citation_prompts (
    id         SERIAL PRIMARY KEY,
    site_id    INTEGER     NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    prompt     TEXT        NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_citation_prompts_site ON citation_prompts (site_id);

CREATE TABLE IF NOT EXISTS citation_results (
    id          SERIAL PRIMARY KEY,
    site_id     INTEGER     NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    prompt_id   INTEGER     REFERENCES citation_prompts(id) ON DELETE CASCADE,
    prompt      TEXT        NOT NULL,
    model       TEXT        NOT NULL DEFAULT '',
    cited       BOOLEAN     NOT NULL DEFAULT false,
    snippet     TEXT        NOT NULL DEFAULT '',
    captured_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_citation_results_site_time ON citation_results (site_id, captured_at DESC);

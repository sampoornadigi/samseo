-- Citation tracking: record which competitor domains an answer cited, so the
-- agency can see who wins the citations they want (not just whether they're cited).
ALTER TABLE citation_results
    ADD COLUMN IF NOT EXISTS competitors JSONB NOT NULL DEFAULT '[]'::jsonb;

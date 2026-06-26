-- Cache the AI explanation of an audit's findings (a prioritised, plain-language
-- "what to fix first" summary). One per audit row, generated on demand and reused
-- until the next audit runs. Shape: { headline, actions: [{title, why, fix, severity}] }.
ALTER TABLE audits ADD COLUMN IF NOT EXISTS ai_summary JSONB;

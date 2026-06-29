/**
 * Per-client daily LLM budget. Entitlement checks gate ACCESS to the AI features;
 * this caps how many on-demand LLM calls a single client can make per day on the
 * shared agency key, so one client can't run up everyone's bill.
 */

import { pool } from '../db/pool.js';

/**
 * Atomically consume one LLM call for a client, capping at `limit` per day.
 * Returns true if the call is allowed (and was counted), false if the cap is hit.
 * The first call of the day always inserts (count 1); later calls only increment
 * while still under the limit (a conditional ON CONFLICT update).
 */
export async function consumeLlmCall(platformTenantId: string, limit: number): Promise<boolean> {
  const res = await pool.query(
    `INSERT INTO seo_llm_usage (platform_tenant_id, usage_date, calls)
     VALUES ($1, current_date, 1)
     ON CONFLICT (platform_tenant_id, usage_date)
     DO UPDATE SET calls = seo_llm_usage.calls + 1
       WHERE seo_llm_usage.calls < $2
     RETURNING calls`,
    [platformTenantId, limit],
  );
  return (res.rowCount ?? 0) > 0;
}

/** Today's call count for a client (for display/limits). */
export async function llmCallsToday(platformTenantId: string): Promise<number> {
  const res = await pool.query<{ calls: number }>(
    `SELECT calls FROM seo_llm_usage WHERE platform_tenant_id = $1 AND usage_date = current_date`,
    [platformTenantId],
  );
  return res.rows[0]?.calls ?? 0;
}

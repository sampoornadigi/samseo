/**
 * Data access for citation tracking (prompts + sampled results).
 */

import { pool } from '../db/pool.js';
import type { CitationResult } from '../citation/sampler.js';

export interface Prompt {
  id: number;
  prompt: string;
  created_at: string;
}

export interface ResultRow {
  id: number;
  prompt: string;
  model: string;
  cited: boolean;
  snippet: string;
  competitors: string[];
  captured_at: string;
}

export interface CitationTrendPoint {
  day: string;
  cited: number;
  total: number;
}

export interface CompetitorCount {
  domain: string;
  n: number;
}

export interface CitationSummary {
  trend: CitationTrendPoint[];
  topCompetitors: CompetitorCount[];
}

export async function addPrompt(siteId: number, prompt: string): Promise<void> {
  await pool.query('INSERT INTO citation_prompts (site_id, prompt) VALUES ($1, $2)', [
    siteId,
    prompt,
  ]);
}

export async function listPrompts(siteId: number): Promise<Prompt[]> {
  const { rows } = await pool.query<Prompt>(
    'SELECT id, prompt, created_at FROM citation_prompts WHERE site_id = $1 ORDER BY id',
    [siteId],
  );
  return rows;
}

export async function recordResults(siteId: number, results: CitationResult[]): Promise<void> {
  for (const r of results) {
    await pool.query(
      `INSERT INTO citation_results (site_id, prompt_id, prompt, model, cited, snippet, competitors)
       VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb)`,
      [siteId, r.prompt_id, r.prompt, r.model, r.cited, r.snippet, JSON.stringify(r.competitors ?? [])],
    );
  }
}

export async function latestResults(siteId: number, limit = 50): Promise<ResultRow[]> {
  const { rows } = await pool.query<ResultRow>(
    `SELECT id, prompt, model, cited, snippet,
            COALESCE(competitors, '[]'::jsonb) AS competitors, captured_at
       FROM citation_results WHERE site_id = $1 ORDER BY captured_at DESC LIMIT $2`,
    [siteId, limit],
  );
  return rows;
}

/**
 * Citation-rate-over-time + the competitors getting cited instead — the two
 * questions an agency asks: "is my AI-search visibility improving?" and "who's
 * winning the citations I want?".
 */
export async function citationSummary(siteId: number): Promise<CitationSummary> {
  const { rows: trend } = await pool.query<CitationTrendPoint>(
    `SELECT to_char(date_trunc('day', captured_at), 'YYYY-MM-DD') AS day,
            count(*) FILTER (WHERE cited)::int AS cited,
            count(*)::int AS total
       FROM citation_results WHERE site_id = $1
       GROUP BY day ORDER BY day DESC LIMIT 14`,
    [siteId],
  );
  const { rows: topCompetitors } = await pool.query<CompetitorCount>(
    `SELECT comp AS domain, count(*)::int AS n
       FROM citation_results, jsonb_array_elements_text(COALESCE(competitors, '[]'::jsonb)) AS comp
       WHERE site_id = $1 AND captured_at > now() - interval '30 days'
       GROUP BY comp ORDER BY n DESC, domain LIMIT 8`,
    [siteId],
  );
  return { trend, topCompetitors };
}

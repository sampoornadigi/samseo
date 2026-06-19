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
  captured_at: string;
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
      `INSERT INTO citation_results (site_id, prompt_id, prompt, model, cited, snippet)
       VALUES ($1, $2, $3, $4, $5, $6)`,
      [siteId, r.prompt_id, r.prompt, r.model, r.cited, r.snippet],
    );
  }
}

export async function latestResults(siteId: number, limit = 50): Promise<ResultRow[]> {
  const { rows } = await pool.query<ResultRow>(
    `SELECT id, prompt, model, cited, snippet, captured_at
       FROM citation_results WHERE site_id = $1 ORDER BY captured_at DESC LIMIT $2`,
    [siteId, limit],
  );
  return rows;
}

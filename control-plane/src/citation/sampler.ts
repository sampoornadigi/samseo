/**
 * Citation-tracking sampler (QUEST-style prototype).
 *
 * For each tracked prompt, ask an LLM and record whether the client's brand or
 * domain appears in the answer — a rough proxy for "is this site cited by AI
 * answer engines". Prototype: deterministic detection over the LLM client's
 * text output; the LLM itself is pluggable (stub by default).
 */

import type { LlmClient } from '../llm/client.js';

export interface CitationContext {
  /** The site's domain host, e.g. "example.com". */
  domain: string;
  /** The site's brand/label, e.g. "Acme". */
  brand: string;
}

export interface CitationResult {
  prompt_id: number;
  prompt: string;
  model: string;
  cited: boolean;
  snippet: string;
}

/** Whether an answer cites the site (by domain or brand), plus a snippet. */
export function detectCitation(answer: string, ctx: CitationContext): { cited: boolean; snippet: string } {
  const a = answer.toLowerCase();
  const domain = ctx.domain.trim().toLowerCase();
  const brand = ctx.brand.trim().toLowerCase();
  const cited = (domain.length > 0 && a.includes(domain)) || (brand.length >= 3 && a.includes(brand));
  return { cited, snippet: answer.trim().slice(0, 200) };
}

/** Run the sampler over a set of prompts. */
export async function sample(
  client: LlmClient,
  prompts: Array<{ id: number; prompt: string }>,
  ctx: CitationContext,
): Promise<CitationResult[]> {
  const out: CitationResult[] = [];
  for (const p of prompts) {
    const answer = await client.ask(p.prompt);
    const { cited, snippet } = detectCitation(answer.text, ctx);
    out.push({ prompt_id: p.id, prompt: p.prompt, model: answer.model, cited, snippet });
  }
  return out;
}

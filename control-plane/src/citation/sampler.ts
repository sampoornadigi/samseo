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
  /** Competitor domains the answer cited instead of (or alongside) this site. */
  competitors: string[];
}

function escapeRegExp(s: string): string {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

const norm = (d: string): string => d.trim().toLowerCase().replace(/^www\./, '');

/** Same site? Equal host, a subdomain of the other, or its apex (either direction). */
function sameSite(a: string, b: string): boolean {
  if (!a || !b) return false;
  return a === b || a.endsWith(`.${b}`) || b.endsWith(`.${a}`);
}

// Hosts that are never "competitors" — platforms, directories, encyclopaedias.
const NOISE_DOMAINS = new Set([
  'schema.org', 'google.com', 'g.co', 'goo.gl', 'facebook.com', 'instagram.com',
  'youtube.com', 'youtu.be', 'twitter.com', 'x.com', 'linkedin.com', 'pinterest.com',
  'wikipedia.org', 'wikimedia.org', 'reddit.com', 'quora.com', 'medium.com',
  'amazon.com', 'apple.com', 'microsoft.com', 'example.com', 'yelp.com', 'justdial.com',
]);

/** Hostnames mentioned in free text (e.g. "try acme.com"). */
function domainsInText(text: string): string[] {
  const re = /\b((?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,})\b/gi;
  const out = new Set<string>();
  let m: RegExpExecArray | null;
  while ((m = re.exec(text)) !== null) out.add(norm(m[1]));
  return [...out];
}

/** Host of each URL (falls back to a text scan for bare strings). */
function hostsOf(urls: string[]): string[] {
  return urls.flatMap((u) => {
    try { return [norm(new URL(u).host)]; }
    catch { return domainsInText(u); }
  });
}

/**
 * Whether an answer cites the site, plus a snippet. Considers both the answer
 * text and (for answer engines like Perplexity) the cited source URLs.
 *
 * Domain is matched as a substring (domains are specific); brand is matched on
 * word boundaries so a generic brand (e.g. "Anu") doesn't false-positive inside
 * another word (e.g. "manual").
 */
export function detectCitation(
  answer: string,
  ctx: CitationContext,
  citations: string[] = [],
): { cited: boolean; snippet: string } {
  const a = answer.toLowerCase();
  const domain = norm(ctx.domain);
  const brand = ctx.brand.trim().toLowerCase();
  const sourceHosts = hostsOf(citations);
  let cited = domain.length > 0 && (a.includes(domain) || sourceHosts.some((h) => sameSite(h, domain)));
  if (!cited && brand.length >= 3) {
    cited = new RegExp(`\\b${escapeRegExp(brand)}\\b`).test(a);
  }
  return { cited, snippet: answer.trim().slice(0, 200) };
}

/** Competitor domains cited in the answer (sources + text), minus this site + noise. */
export function extractCompetitors(answer: string, citations: string[], ctx: CitationContext): string[] {
  const own = norm(ctx.domain);
  const all = new Set<string>([...hostsOf(citations), ...domainsInText(answer)]);
  return [...all]
    .filter((d) => d.includes('.') && !sameSite(d, own) && !NOISE_DOMAINS.has(d))
    .slice(0, 10);
}

/** Run the sampler over a set of prompts. */
export async function sample(
  client: LlmClient,
  prompts: Array<{ id: number; prompt: string }>,
  ctx: CitationContext,
): Promise<CitationResult[]> {
  const out: CitationResult[] = [];
  for (const p of prompts) {
    let answer;
    try {
      answer = await client.ask(p.prompt);
    } catch (err) {
      // Skip a failed prompt (API error/timeout) rather than recording a false
      // "not cited" that would pollute the citation-rate trend.
      console.error(`[citation] prompt ${p.id} failed: ${err instanceof Error ? err.message : String(err)}`);
      continue;
    }
    const citations = answer.citations ?? [];
    const { cited, snippet } = detectCitation(answer.text, ctx, citations);
    const competitors = extractCompetitors(answer.text, citations, ctx);
    out.push({ prompt_id: p.id, prompt: p.prompt, model: answer.model, cited, snippet, competitors });
  }
  return out;
}

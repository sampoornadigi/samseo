/**
 * AEO / GEO content generation — answer-engine & generative-engine optimization.
 *
 * Given a page topic (the plane has no page bodies, so the client supplies it),
 * generate the assets that win AI-search citations: a concise 40-60 word answer
 * block, an FAQ set, and (for procedural topics) how-to steps. The LLM returns
 * CONTENT only; we assemble the JSON-LD (FAQPage / Article / HowTo) in code so the
 * schema is always valid. Ready to paste into the page or an SEO plugin's schema box.
 *
 * Note: auto-deploying schema through the site connector needs a plugin-side field
 * that recognises JSON-LD; until then this is a generate-and-copy tool.
 */

import { makeReasoningClient, hasLlm } from '../llm/client.js';

export interface AeoInput {
  topic: string;
  url?: string;
  notes?: string;
  siteLabel?: string;
}

export interface AeoContent {
  answerBlock: string;
  faqs: { q: string; a: string }[];
  article: { headline: string; description: string };
  howto: { name: string; steps: { name: string; text: string }[] } | null;
}

export interface AeoResult extends AeoContent {
  jsonLd: { faq: object | null; article: object; howto: object | null };
}

const str = (v: unknown, max: number) => (typeof v === 'string' ? v.trim().slice(0, max) : '');

/** Extract the JSON object the model returned, tolerating fences / leading prose. */
export function parseAeoContent(raw: string): AeoContent | null {
  if (!raw || typeof raw !== 'string') return null;
  let text = raw.trim().replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/i, '').trim();
  let obj: unknown = null;
  try { obj = JSON.parse(text); } catch { /* slice */ }
  if (!obj) {
    const s = text.indexOf('{'), e = text.lastIndexOf('}');
    if (s !== -1 && e > s) { try { obj = JSON.parse(text.slice(s, e + 1)); } catch { return null; } }
  }
  const o = obj as Record<string, unknown>;
  if (!o || typeof o !== 'object') return null;

  const answerBlock = str(o.answerBlock, 600);
  const faqs = Array.isArray(o.faqs)
    ? o.faqs.slice(0, 8).map((f: Record<string, unknown>) => ({ q: str(f.q, 240), a: str(f.a, 800) })).filter((f) => f.q && f.a)
    : [];
  const articleSrc = (o.article ?? {}) as Record<string, unknown>;
  const article = { headline: str(articleSrc.headline, 160), description: str(articleSrc.description, 400) };
  let howto: AeoContent['howto'] = null;
  const hs = o.howto as Record<string, unknown> | undefined;
  if (hs && Array.isArray(hs.steps) && hs.steps.length) {
    const steps = hs.steps.slice(0, 12)
      .map((s: Record<string, unknown>) => ({ name: str(s.name, 160), text: str(s.text, 600) }))
      .filter((s) => s.text);
    if (steps.length) howto = { name: str(hs.name, 160) || str(o.answerBlock, 60), steps };
  }

  if (!answerBlock && !faqs.length) return null;
  return { answerBlock, faqs, article, howto };
}

function faqSchema(faqs: { q: string; a: string }[]): object | null {
  if (!faqs.length) return null;
  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: faqs.map((f) => ({
      '@type': 'Question',
      name: f.q,
      acceptedAnswer: { '@type': 'Answer', text: f.a },
    })),
  };
}

function articleSchema(article: { headline: string; description: string }, input: AeoInput): object {
  const schema: Record<string, unknown> = {
    '@context': 'https://schema.org',
    '@type': 'Article',
    headline: article.headline || input.topic,
  };
  if (article.description) schema.description = article.description;
  if (input.url) schema.mainEntityOfPage = { '@type': 'WebPage', '@id': input.url };
  if (input.siteLabel) schema.publisher = { '@type': 'Organization', name: input.siteLabel };
  return schema;
}

function howToSchema(howto: AeoContent['howto']): object | null {
  if (!howto) return null;
  return {
    '@context': 'https://schema.org',
    '@type': 'HowTo',
    name: howto.name,
    step: howto.steps.map((s, i) => ({ '@type': 'HowToStep', position: i + 1, name: s.name || `Step ${i + 1}`, text: s.text })),
  };
}

/** Assemble the JSON-LD blocks (valid by construction) around generated content. */
export function buildAeoResult(content: AeoContent, input: AeoInput): AeoResult {
  return {
    ...content,
    jsonLd: {
      faq: faqSchema(content.faqs),
      article: articleSchema(content.article, input),
      howto: howToSchema(content.howto),
    },
  };
}

/** Generate AEO/GEO content for a topic. Returns null when no model is configured. */
export async function generateAeo(input: AeoInput): Promise<AeoResult | null> {
  if (!input.topic?.trim() || !hasLlm()) return null;

  const prompt = `You are an SEO/AEO expert. For the page topic below, produce content optimised to be cited by AI answer engines (Google AI Overviews, ChatGPT, Perplexity).
${input.siteLabel ? `Site: ${input.siteLabel}\n` : ''}${input.url ? `Page URL: ${input.url}\n` : ''}Topic: ${input.topic}
${input.notes ? `Key points to include: ${input.notes}\n` : ''}
Return ONLY a JSON object (no markdown, no prose) shaped exactly:
{"answerBlock": "a direct, factual 40-60 word answer to the core question — the snippet an AI engine would quote",
 "faqs": [{"q": "natural-language question a user would ask", "a": "concise 1-3 sentence answer"}],
 "article": {"headline": "SEO headline for the page", "description": "meta description, 150-160 chars"},
 "howto": null}
Give 4-6 FAQs. If — and only if — the topic is a procedural / step-by-step task, set "howto" to {"name": "...", "steps": [{"name": "short step title", "text": "what to do"}]}; otherwise keep "howto": null.`;

  let answer;
  try {
    answer = await makeReasoningClient().ask(prompt, { maxTokens: 1800 });
  } catch (e) {
    console.error('[aeoGenerate] LLM call failed:', (e as Error).message);
    return null;
  }
  const content = parseAeoContent(answer.text);
  if (!content) return null;
  return buildAeoResult(content, input);
}

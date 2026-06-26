/**
 * LLM client for the citation-tracking sampler.
 *
 * Pluggable: with no CP_LLM_KEY set (tests, dev) it uses a deterministic stub
 * that needs no network and no key; with a key it calls the Anthropic Messages
 * API using the same contract as the plugin's Ai\AiClient (x-api-key +
 * anthropic-version 2023-06-01). The sampler depends only on the LlmClient
 * interface, so it stays hermetic in tests.
 */

import { config } from '../config.js';

export interface LlmAnswer {
  text: string;
  model: string;
  /** Source URLs the engine cited (Perplexity); empty for engines without web search. */
  citations?: string[];
}

export interface AskOptions {
  /** Output token budget; defaults to 512 (enough for a citation answer). */
  maxTokens?: number;
}

export interface LlmClient {
  ask(prompt: string, opts?: AskOptions): Promise<LlmAnswer>;
}

/** Deterministic, keyless, network-free client for tests/dev. */
export class StubLlmClient implements LlmClient {
  async ask(prompt: string): Promise<LlmAnswer> {
    // Echoes the prompt so citation detection is exercised deterministically:
    // a prompt that names the brand/domain yields an answer that contains it.
    return { text: `(stub answer) Regarding your question: ${prompt}`, model: 'stub' };
  }
}

/** Real Anthropic Messages API client (mirrors Ai\AiClient's contract). */
export class AnthropicLlmClient implements LlmClient {
  constructor(
    private readonly key: string,
    private readonly model: string,
  ) {}

  async ask(prompt: string, opts: AskOptions = {}): Promise<LlmAnswer> {
    const res = await fetch('https://api.anthropic.com/v1/messages', {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'x-api-key': this.key,
        'anthropic-version': '2023-06-01',
      },
      body: JSON.stringify({
        model: this.model,
        max_tokens: opts.maxTokens ?? 512,
        messages: [{ role: 'user', content: prompt }],
      }),
      signal: AbortSignal.timeout(30000),
    });
    // Throw on a non-2xx so a failed sample is SKIPPED, not recorded as a false
    // "not cited" (which would silently deflate the citation-rate trend).
    if (!res.ok) throw new Error(`Anthropic API ${res.status}`);
    const data = (await res.json()) as { content?: Array<{ text?: string }> };
    const text = Array.isArray(data.content) ? data.content.map((b) => b.text ?? '').join(' ').trim() : '';
    return { text, model: this.model };
  }
}

/**
 * Real Perplexity client — an actual answer engine (web search + source URLs).
 * The cited source URLs are the gold signal for "who gets cited"; we surface them
 * for both self-citation and competitor detection. OpenAI-compatible wire format.
 */
export class PerplexityLlmClient implements LlmClient {
  constructor(
    private readonly key: string,
    private readonly model: string,
  ) {}

  async ask(prompt: string, opts: AskOptions = {}): Promise<LlmAnswer> {
    const res = await fetch('https://api.perplexity.ai/chat/completions', {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        authorization: `Bearer ${this.key}`,
      },
      body: JSON.stringify({
        model: this.model,
        max_tokens: opts.maxTokens ?? 512,
        messages: [{ role: 'user', content: prompt }],
      }),
      signal: AbortSignal.timeout(30000),
    });
    if (!res.ok) throw new Error(`Perplexity API ${res.status}`);
    const data = (await res.json()) as {
      choices?: Array<{ message?: { content?: string } }>;
      citations?: string[];
    };
    const text = data.choices?.[0]?.message?.content?.trim() ?? '';
    const citations = Array.isArray(data.citations) ? data.citations.filter((u) => typeof u === 'string') : [];
    return { text, model: this.model, citations };
  }
}

/**
 * Build the configured client. Perplexity (a real answer engine) is preferred
 * when keyed, then Anthropic, else the deterministic stub for tests/dev.
 */
export function makeLlmClient(): LlmClient {
  if (config.perplexityKey) return new PerplexityLlmClient(config.perplexityKey, config.perplexityModel);
  if (config.llmKey) return new AnthropicLlmClient(config.llmKey, config.llmModel);
  return new StubLlmClient();
}

/**
 * For analysis/reasoning tasks (e.g. explaining audit findings), prefer a
 * reasoning model (Anthropic) over a web-search answer engine (Perplexity),
 * falling back to the stub when nothing is keyed.
 */
export function makeReasoningClient(): LlmClient {
  if (config.llmKey) return new AnthropicLlmClient(config.llmKey, config.llmModel);
  if (config.perplexityKey) return new PerplexityLlmClient(config.perplexityKey, config.perplexityModel);
  return new StubLlmClient();
}

/** True when a real (non-stub) model is configured. */
export function hasLlm(): boolean {
  return !!(config.llmKey || config.perplexityKey);
}

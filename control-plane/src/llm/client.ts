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
}

export interface LlmClient {
  ask(prompt: string): Promise<LlmAnswer>;
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

  async ask(prompt: string): Promise<LlmAnswer> {
    const res = await fetch('https://api.anthropic.com/v1/messages', {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'x-api-key': this.key,
        'anthropic-version': '2023-06-01',
      },
      body: JSON.stringify({
        model: this.model,
        max_tokens: 512,
        messages: [{ role: 'user', content: prompt }],
      }),
      signal: AbortSignal.timeout(30000),
    });
    const data = (await res.json()) as { content?: Array<{ text?: string }> };
    const text = Array.isArray(data.content) ? data.content.map((b) => b.text ?? '').join(' ').trim() : '';
    return { text, model: this.model };
  }
}

/** Build the configured client (Anthropic when keyed, else the stub). */
export function makeLlmClient(): LlmClient {
  return config.llmKey ? new AnthropicLlmClient(config.llmKey, config.llmModel) : new StubLlmClient();
}

import { describe, expect, it } from 'vitest';
import { detectCitation, sample } from '../src/citation/sampler.js';
import { StubLlmClient } from '../src/llm/client.js';

describe('detectCitation', () => {
  const ctx = { domain: 'example.com', brand: 'Acme' };

  it('detects the domain in the answer', () => {
    const r = detectCitation('You should try example.com for that.', ctx);
    expect(r.cited).toBe(true);
  });

  it('detects the brand in the answer', () => {
    const r = detectCitation('Acme is a good option.', ctx);
    expect(r.cited).toBe(true);
  });

  it('reports not cited when neither appears', () => {
    const r = detectCitation('Try some other vendor entirely.', ctx);
    expect(r.cited).toBe(false);
  });

  it('ignores too-short brands to avoid false positives', () => {
    const r = detectCitation('a and the', { domain: '', brand: 'AB' });
    expect(r.cited).toBe(false);
  });

  it('truncates the snippet', () => {
    const long = 'x'.repeat(500);
    expect(detectCitation(long, ctx).snippet.length).toBe(200);
  });
});

describe('sample (stub client)', () => {
  it('cites when the prompt names the domain (stub echoes the prompt)', async () => {
    const client = new StubLlmClient();
    const results = await sample(
      client,
      [
        { id: 1, prompt: 'What does example.com offer?' },
        { id: 2, prompt: 'Recommend a generic widget vendor.' },
      ],
      { domain: 'example.com', brand: 'Acme' },
    );
    expect(results).toHaveLength(2);
    expect(results[0].cited).toBe(true); // prompt (echoed by stub) contains the domain
    expect(results[1].cited).toBe(false);
    expect(results[0].model).toBe('stub');
  });
});

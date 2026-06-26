import { describe, expect, it } from 'vitest';
import { detectCitation, extractCompetitors, sample } from '../src/citation/sampler.js';
import { StubLlmClient, type LlmClient } from '../src/llm/client.js';

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

  it('matches the brand on word boundaries, not inside other words', () => {
    // "Anu" must not match inside "manual".
    expect(detectCitation('Follow the manual process.', { domain: '', brand: 'Anu' }).cited).toBe(false);
    expect(detectCitation('Anu Furniture is great.', { domain: '', brand: 'Anu' }).cited).toBe(true);
  });

  it('truncates the snippet', () => {
    const long = 'x'.repeat(500);
    expect(detectCitation(long, ctx).snippet.length).toBe(200);
  });

  it('detects the site when its domain appears in the cited sources (Perplexity)', () => {
    const r = detectCitation('Several options exist.', ctx, ['https://www.example.com/services', 'https://other.com']);
    expect(r.cited).toBe(true);
  });
});

describe('extractCompetitors', () => {
  const ctx = { domain: 'example.com', brand: 'Acme' };

  it('extracts competitor domains from sources + text, excluding the site and noise', () => {
    const comps = extractCompetitors(
      'You could also try rival.com or BestCo (bestco.in).',
      ['https://www.example.com/x', 'https://rival.com/a', 'https://en.wikipedia.org/Acme'],
      ctx,
    );
    expect(comps).toContain('rival.com');
    expect(comps).toContain('bestco.in');
    expect(comps).not.toContain('example.com'); // own
    expect(comps).not.toContain('wikipedia.org'); // noise
  });

  it('returns [] when only the site/noise are present', () => {
    expect(extractCompetitors('Visit example.com', ['https://example.com'], ctx)).toEqual([]);
  });

  it('treats the apex as the site when tracking a subdomain (not a competitor)', () => {
    const sub = { domain: 'shop.acme.com', brand: 'Acme' };
    const comps = extractCompetitors('See acme.com and rival.com', ['https://acme.com', 'https://rival.com'], sub);
    expect(comps).not.toContain('acme.com'); // own apex
    expect(comps).toContain('rival.com');
    // …and the apex source counts as a self-citation
    expect(detectCitation('here', sub, ['https://acme.com/x']).cited).toBe(true);
  });
});

describe('sample (error handling)', () => {
  it('skips a prompt whose LLM call fails instead of recording a false "not cited"', async () => {
    let n = 0;
    const flaky: LlmClient = {
      ask: async (prompt) => {
        n += 1;
        if (n === 1) throw new Error('Perplexity API 429');
        return { text: `mentions example.com for ${prompt}`, model: 'x' };
      },
    };
    const results = await sample(
      flaky,
      [{ id: 1, prompt: 'p1' }, { id: 2, prompt: 'p2' }],
      { domain: 'example.com', brand: 'Acme' },
    );
    expect(results).toHaveLength(1); // the failed prompt is dropped, not recorded
    expect(results[0].prompt_id).toBe(2);
    expect(results[0].cited).toBe(true);
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

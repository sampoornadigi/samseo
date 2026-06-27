import { test, expect } from 'vitest';
import { parseAeoContent, buildAeoResult } from '../src/services/aeoGenerate.js';

test('parses content and builds valid FAQ + Article JSON-LD', () => {
  const content = parseAeoContent(JSON.stringify({
    answerBlock: 'A modular kitchen in Bangalore typically costs ₹1.5–4 lakh.',
    faqs: [{ q: 'How much does it cost?', a: 'Between ₹1.5L and ₹4L.' }],
    article: { headline: 'Modular Kitchen Cost', description: 'A guide.' },
    howto: null,
  }));
  expect(content).not.toBeNull();
  const r = buildAeoResult(content!, { topic: 'modular kitchen', url: 'https://x.com/p', siteLabel: 'Venora' });
  const faq = r.jsonLd.faq as any;
  expect(faq['@type']).toBe('FAQPage');
  expect(faq.mainEntity[0]['@type']).toBe('Question');
  expect(faq.mainEntity[0].acceptedAnswer.text).toBe('Between ₹1.5L and ₹4L.');
  const article = r.jsonLd.article as any;
  expect(article['@type']).toBe('Article');
  expect(article.mainEntityOfPage['@id']).toBe('https://x.com/p');
  expect(article.publisher.name).toBe('Venora');
  expect(r.jsonLd.howto).toBeNull();
});

test('builds HowTo schema only when steps are present', () => {
  const content = parseAeoContent(JSON.stringify({
    answerBlock: 'Follow these steps.',
    faqs: [{ q: 'Q', a: 'A' }],
    article: { headline: 'H', description: 'D' },
    howto: { name: 'Install the plugin', steps: [{ name: 'Download', text: 'Get the zip.' }, { name: 'Upload', text: 'Upload it.' }] },
  }));
  const r = buildAeoResult(content!, { topic: 'install' });
  const ht = r.jsonLd.howto as any;
  expect(ht['@type']).toBe('HowTo');
  expect(ht.step).toHaveLength(2);
  expect(ht.step[0].position).toBe(1);
});

test('strips code fences and drops malformed faqs', () => {
  const content = parseAeoContent('```json\n{"answerBlock":"Hi","faqs":[{"q":"only q"},{"q":"Q","a":"A"}],"article":{}}\n```');
  expect(content?.faqs).toHaveLength(1);
  expect(content?.faqs[0].q).toBe('Q');
});

test('returns null when there is no usable content', () => {
  expect(parseAeoContent('garbage')).toBeNull();
  expect(parseAeoContent(JSON.stringify({ faqs: [] }))).toBeNull();
});

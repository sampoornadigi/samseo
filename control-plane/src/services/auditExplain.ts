/**
 * AI explanations on audit findings. Turns the raw audit checklist (missing
 * titles/descriptions/focus-keywords per post) into a prioritised, plain-language
 * "what to fix first and why" summary a non-technical site owner can act on.
 *
 * Cached per audit (audits.ai_summary), generated on demand. Falls back cleanly
 * when no model is configured (returns null; the UI shows a hint instead).
 */

import { makeReasoningClient, hasLlm } from '../llm/client.js';
import { latestAudit, saveAuditSummary, type AuditSummary, type Finding } from '../repo/pipeline.js';

const SEVERITIES = ['high', 'medium', 'low'];

/** Compact the findings into a prompt-sized list (cap to keep tokens bounded). */
function summariseFindings(findings: Finding[]): string {
  const byField: Record<string, number> = {};
  for (const f of findings) byField[f.field] = (byField[f.field] ?? 0) + 1;
  const counts = Object.entries(byField).map(([field, n]) => `${field}: ${n}`).join(', ');
  const examples = findings.slice(0, 25).map((f, i) =>
    `${i + 1}. [${f.type} "${f.label}"] ${f.field} — ${f.reason}`,
  ).join('\n');
  return `Totals by field: ${counts}\nTotal findings: ${findings.length}\n\nExamples:\n${examples}`;
}

export function parseAuditSummary(raw: string): AuditSummary | null {
  if (!raw || typeof raw !== 'string') return null;
  let text = raw.trim().replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/i, '').trim();
  let obj: unknown = null;
  try { obj = JSON.parse(text); } catch { /* slice below */ }
  if (!obj) {
    const s = text.indexOf('{'), e = text.lastIndexOf('}');
    if (s !== -1 && e > s) { try { obj = JSON.parse(text.slice(s, e + 1)); } catch { return null; } }
  }
  const o = obj as { headline?: unknown; actions?: unknown };
  if (!o || typeof o !== 'object') return null;
  const headline = typeof o.headline === 'string' ? o.headline.trim().slice(0, 300) : '';
  const actions = Array.isArray(o.actions)
    ? o.actions.slice(0, 8).map((a: Record<string, unknown>) => ({
        title: typeof a.title === 'string' ? a.title.trim().slice(0, 160) : '',
        why: typeof a.why === 'string' ? a.why.trim().slice(0, 400) : '',
        fix: typeof a.fix === 'string' ? a.fix.trim().slice(0, 400) : '',
        severity: SEVERITIES.includes(a.severity as string) ? (a.severity as string) : 'medium',
      })).filter((a) => a.title)
    : [];
  if (!headline && !actions.length) return null;
  return { headline: headline || 'Top SEO fixes for this site', actions };
}

/**
 * Return the AI summary for a site's latest audit, generating + caching it if
 * needed. `force` regenerates even when cached. Returns null when there's no
 * audit, no findings, no model configured, or the model/parse fails.
 */
export async function explainLatestAudit(siteId: number, { force = false } = {}): Promise<AuditSummary | null> {
  const audit = await latestAudit(siteId);
  if (!audit || !audit.findings?.length) return null;
  if (audit.ai_summary && !force) return audit.ai_summary;
  if (!hasLlm()) return null;

  const prompt = `You are an SEO consultant explaining a site audit to a busy small-business owner who is not technical.
Based ONLY on the audit findings below, write a short prioritised action plan. Group similar issues, explain the impact in plain English, and lead with what matters most for getting found on Google.

Audit findings:
${summariseFindings(audit.findings)}

Return ONLY a JSON object (no markdown, no prose) shaped exactly:
{"headline": "one sentence on the overall state and the single most important thing to do",
 "actions": [{"title": "short action name", "why": "why it matters for SEO, plain English, 1-2 sentences", "fix": "concrete step to take", "severity": "high|medium|low"}]}
List 3 to 6 actions, most impactful first.`;

  let answer;
  try {
    answer = await makeReasoningClient().ask(prompt, { maxTokens: 1300 });
  } catch (e) {
    console.error('[auditExplain] LLM call failed:', (e as Error).message);
    return null;
  }
  const summary = parseAuditSummary(answer.text);
  if (!summary) return null;
  await saveAuditSummary(audit.id, summary);
  return summary;
}

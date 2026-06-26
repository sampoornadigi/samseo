/**
 * Data access for the audit → deploy → rollback pipeline.
 */

import { pool } from '../db/pool.js';

export interface Finding {
  key: string;
  type: string;
  id: number;
  field: string;
  current: string;
  suggested: string;
  reason: string;
  label: string;
}

export interface Change {
  type: string;
  id: number;
  field: string;
  value: string;
}

export interface Deployment {
  id: number;
  site_id: number;
  deploy_id: string;
  status: string;
  changes: Change[];
  result: unknown;
  created_at: string;
  rolled_back_at: string | null;
}

export async function saveAudit(siteId: number, findings: Finding[]): Promise<void> {
  await pool.query('INSERT INTO audits (site_id, findings) VALUES ($1, $2)', [
    siteId,
    JSON.stringify(findings),
  ]);
}

export interface AuditSummary {
  headline: string;
  actions: { title: string; why: string; fix: string; severity: string }[];
}

export async function latestAudit(
  siteId: number,
): Promise<{ id: number; findings: Finding[]; captured_at: string; ai_summary: AuditSummary | null } | null> {
  const { rows } = await pool.query<{ id: number; findings: Finding[]; captured_at: string; ai_summary: AuditSummary | null }>(
    'SELECT id, findings, captured_at, ai_summary FROM audits WHERE site_id = $1 ORDER BY captured_at DESC LIMIT 1',
    [siteId],
  );
  return rows[0] ?? null;
}

export async function saveAuditSummary(auditId: number, summary: AuditSummary): Promise<void> {
  await pool.query('UPDATE audits SET ai_summary = $2 WHERE id = $1', [auditId, JSON.stringify(summary)]);
}

export async function insertDeployment(
  siteId: number,
  deployId: string,
  changes: Change[],
  result: unknown,
): Promise<void> {
  await pool.query(
    `INSERT INTO deployments (site_id, deploy_id, status, changes, result)
     VALUES ($1, $2, 'deployed', $3, $4)`,
    [siteId, deployId, JSON.stringify(changes), JSON.stringify(result ?? {})],
  );
}

export async function getByDeployId(deployId: string): Promise<Deployment | null> {
  const { rows } = await pool.query<Deployment>(
    'SELECT * FROM deployments WHERE deploy_id = $1',
    [deployId],
  );
  return rows[0] ?? null;
}

export async function listDeployments(siteId: number): Promise<Deployment[]> {
  const { rows } = await pool.query<Deployment>(
    'SELECT * FROM deployments WHERE site_id = $1 ORDER BY created_at DESC LIMIT 50',
    [siteId],
  );
  return rows;
}

export async function setRolledBack(deployId: string, result: unknown): Promise<void> {
  await pool.query(
    `UPDATE deployments SET status = 'rolled_back', rolled_back_at = now(), result = $2
      WHERE deploy_id = $1`,
    [deployId, JSON.stringify(result ?? {})],
  );
}

/**
 * Map operator-approved finding keys against an audit's findings to a changeset.
 * Unknown keys are ignored. Pure — unit-tested.
 */
export function changesFromKeys(findings: Finding[], keys: string[]): Change[] {
  const wanted = new Set(keys);
  return findings
    .filter((f) => wanted.has(f.key))
    .map((f) => ({ type: f.type, id: f.id, field: f.field, value: f.suggested }));
}

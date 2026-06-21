/**
 * Backfill sites.platform_tenant_id from a verified mapping file (Phase 1
 * no-big-bang). Dry-run by default; pass --apply to write. The mapping is YOURS
 * to verify (entity resolution is a human decision).
 *
 *   tsx scripts/backfill-platform-tenant.ts mapping.json          # dry-run
 *   tsx scripts/backfill-platform-tenant.ts mapping.json --apply  # write
 *
 * mapping.json: { "seo": [ { "legacyId": "<site_id>", "tenantId": "ten_…" } ] }
 */

import { readFile } from 'node:fs/promises';
import { pool } from '../src/db/pool.js';

interface Pair {
  legacyId: string;
  tenantId: string;
}

async function main(): Promise<void> {
  const file = process.argv[2];
  const apply = process.argv.includes('--apply');
  if (!file) {
    console.error('usage: tsx scripts/backfill-platform-tenant.ts <mapping.json> [--apply]');
    process.exit(1);
  }

  const mapping = JSON.parse(await readFile(file, 'utf8')) as { seo?: Pair[] };
  const rows = mapping.seo ?? [];
  console.log(`[backfill] ${rows.length} seo mapping(s); mode=${apply ? 'APPLY' : 'dry-run'}`);

  let changed = 0;
  for (const { legacyId, tenantId } of rows) {
    const siteId = Number(legacyId);
    const { rows: found } = await pool.query<{ label: string; platform_tenant_id: string | null }>(
      'SELECT label, platform_tenant_id FROM sites WHERE id = $1',
      [siteId],
    );
    if (found.length === 0) {
      console.warn(`  site_id=${legacyId} not found — skip`);
      continue;
    }
    const was = found[0].platform_tenant_id ? ` (was ${found[0].platform_tenant_id})` : '';
    console.log(`  site_id=${legacyId} (${found[0].label}) -> ${tenantId}${was}`);
    if (apply) {
      await pool.query('UPDATE sites SET platform_tenant_id = $2 WHERE id = $1', [siteId, tenantId]);
      changed += 1;
    }
  }

  console.log(apply ? `[backfill] updated ${changed} site(s)` : '[backfill] dry-run — re-run with --apply to write');
  await pool.end();
}

main().catch((err) => {
  console.error('[backfill] failed', err);
  process.exit(1);
});

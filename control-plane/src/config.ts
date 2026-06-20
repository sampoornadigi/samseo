/**
 * Environment-backed configuration for the control plane.
 *
 * Values are read once at import time. Docker provides them via compose;
 * for local runs copy .env.example to .env and load it before starting.
 */

function required(name: string): string {
  const value = process.env[name];
  if (value === undefined || value === '') {
    throw new Error(`Missing required environment variable: ${name}`);
  }
  return value;
}

export const config = {
  port: Number(process.env.PORT ?? 3000),
  databaseUrl: required('DATABASE_URL'),
  /** 32-byte AES-256-GCM key, hex-encoded (64 chars). */
  vaultKey: required('CP_VAULT_KEY'),
  /** Allowed signing clock skew in seconds (matches Handshake::MAX_SKEW). */
  skewSeconds: Number(process.env.SKEW_SECONDS ?? 300),
  /** Anthropic API key for the citation sampler; empty = use the deterministic stub. */
  llmKey: process.env.CP_LLM_KEY ?? '',
  /** Model for the citation sampler. */
  llmModel: process.env.CP_LLM_MODEL ?? 'claude-haiku-4-5',
  /** Secret for signing session cookies (falls back to the vault key). */
  sessionSecret: process.env.CP_SESSION_SECRET ?? required('CP_VAULT_KEY'),
  /** Username for the auto-seeded initial admin. */
  adminUser: process.env.CP_ADMIN_USER ?? 'admin',
  /** Password for the auto-seeded initial admin; empty = no auto-seed. */
  adminPassword: process.env.CP_ADMIN_PASSWORD ?? '',
  /** Minutes between scheduled metric refreshes of all sites; 0 disables. */
  refreshMinutes: Number(process.env.CP_REFRESH_MINUTES ?? 0),
  /** Minutes between scheduled audits of all sites; 0 disables. */
  auditMinutes: Number(process.env.CP_AUDIT_MINUTES ?? 0),
  /** Minutes between scheduled citation sampling of all sites; 0 disables. */
  citationMinutes: Number(process.env.CP_CITATION_MINUTES ?? 0),
  /** Google PageSpeed Insights API key for the UX/CWV score; empty disables UX scoring. */
  pagespeedKey: process.env.CP_PAGESPEED_KEY ?? '',
  /** PageSpeed strategy: 'mobile' (default) or 'desktop'. */
  pagespeedStrategy: process.env.CP_PAGESPEED_STRATEGY ?? 'mobile',
} as const;

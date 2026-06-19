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
} as const;

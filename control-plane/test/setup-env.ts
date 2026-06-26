// Pure unit tests import src/config.ts transitively (via the LLM client), and
// config validates these at module-load time. Provide harmless defaults so
// `npm test` runs on a bare checkout; a real CI/dev env (set first) is preserved.
process.env.DATABASE_URL ||= 'postgres://test:test@localhost:5432/test';
process.env.CP_VAULT_KEY ||= '0'.repeat(64);

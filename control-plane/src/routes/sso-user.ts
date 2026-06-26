/**
 * Resolve the local control-plane session for a verified platform (CRM) SSO
 * principal.
 *
 * - super_admin (agency staff) → an unscoped 'admin' session (unchanged).
 * - client_admin / client_agent (a CRM client) → a scoped 'client' session: a
 *   passwordless cp_users row whose cp_user_sites are exactly the sites enrolled
 *   under that client's platform tenant (sites.platform_tenant_id). The dashboard
 *   already confines role='client' to those sites, so the client sees only their
 *   own — and an empty list (not a 403) until a site is enrolled + mapped.
 *
 * Denials throw SsoDenied(status, message). DB access is injected so the decision
 * logic is unit-testable without a database.
 */

export class SsoDenied extends Error {
  constructor(public status: number, message: string) {
    super(message);
    this.name = 'SsoDenied';
  }
}

export interface SeoPrincipal {
  userId?: string | null;
  tenantId?: string | null;
  role?: string;
  entitlements?: string[];
}

export interface SeoSsoDeps {
  /** Create the cp_users row if absent (idempotent / ON CONFLICT DO NOTHING). */
  ensureUser(username: string, role: string): Promise<void>;
  idForUsername(username: string): Promise<number | null>;
  siteIdsForTenant(platformTenantId: string): Promise<number[]>;
  setUserSites(userId: number, siteIds: number[]): Promise<void>;
  /** True if the CRM has revoked SSO for this tenant (suspended/archived). */
  isTenantRevoked(tenantId: string): Promise<boolean>;
}

/** CRM platform role → control-plane role. null = not an admittable role. */
export function crmRoleToCpRole(role?: string): 'admin' | 'client' | null {
  if (role === 'super_admin') return 'admin';
  if (role === 'client_admin' || role === 'client_agent') return 'client';
  return null;
}

export function clientUsername(tenantId: string): string {
  return `platform-tenant-${tenantId}`;
}

/** Returns the { username, role } to mint a session for; provisions client scope. */
export async function resolveSeoSso(
  principal: SeoPrincipal,
  deps: SeoSsoDeps,
): Promise<{ username: string; role: string }> {
  const cpRole = crmRoleToCpRole(principal.role);
  if (!cpRole) {
    throw new SsoDenied(403, 'SEO access is not available for this account.');
  }

  if (cpRole === 'admin') {
    return { username: principal.userId ?? 'platform-admin', role: 'admin' };
  }

  // client_admin / client_agent → scoped client session.
  if (!principal.entitlements?.includes('seo')) {
    throw new SsoDenied(403, 'Your account is not entitled to SEO. Please contact your agency.');
  }
  if (!principal.tenantId) {
    throw new SsoDenied(403, 'SEO could not identify your account.');
  }
  // A still-valid token must not outlive the tenant's active status.
  if (await deps.isTenantRevoked(principal.tenantId)) {
    throw new SsoDenied(403, 'This account is not active. Please contact your agency.');
  }

  const username = clientUsername(principal.tenantId);
  await deps.ensureUser(username, 'client');
  const userId = await deps.idForUsername(username);
  if (userId != null) {
    // Refresh the scope on every login so newly-mapped sites appear (and removed
    // ones disappear) without a manual re-assignment.
    const siteIds = await deps.siteIdsForTenant(principal.tenantId);
    await deps.setUserSites(userId, siteIds);
  }
  return { username, role: 'client' };
}

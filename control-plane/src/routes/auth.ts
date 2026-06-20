/**
 * Authentication routes: login, logout, and (admin-only) user management.
 */

import type { FastifyInstance, FastifyRequest } from 'fastify';
import { hashPassword, verifyPassword } from '../crypto/password.js';
import { clearSessionCookie, makeToken, readSession, setSessionCookie } from '../auth/session.js';
import {
  countUsers,
  createUser,
  findByUsername,
  idForUsername,
  listUsers,
  setUserSites,
  siteAssignments,
} from '../repo/users.js';
import { list as listSites } from '../repo/sites.js';

const ROLES = ['admin', 'viewer', 'client'];

export function registerAuth(app: FastifyInstance): void {
  app.get('/login', async (request, reply) => {
    if (readSession(request)) {
      return reply.redirect('/');
    }
    return reply.view('login.ejs', { title: 'Sign in', error: '' });
  });

  app.post('/login', async (request: FastifyRequest<{ Body: { username?: string; password?: string } }>, reply) => {
    const username = (request.body?.username ?? '').trim();
    const password = request.body?.password ?? '';
    const user = username !== '' ? await findByUsername(username) : null;

    if (!user || !verifyPassword(password, user.password_hash)) {
      return reply.code(401).view('login.ejs', { title: 'Sign in', error: 'Invalid username or password.' });
    }
    setSessionCookie(reply, makeToken(user.username, user.role));
    return reply.redirect('/');
  });

  app.post('/logout', async (_request, reply) => {
    clearSessionCookie(reply);
    return reply.redirect('/login');
  });

  // Admin-only (enforced by the global guard: non-GET requires admin; GET /users guarded here).
  app.get('/users', async (request, reply) => {
    const me = readSession(request);
    if (!me || me.role !== 'admin') {
      return reply.code(403).send('Forbidden');
    }
    const users = await listUsers();
    const sites = await listSites();
    const assignments = await siteAssignments();
    return reply.view('users.ejs', {
      title: 'Users',
      user: me,
      users,
      sites,
      assignments,
      error: '',
    });
  });

  app.post(
    '/users',
    async (
      request: FastifyRequest<{ Body: { username?: string; password?: string; role?: string; sites?: string | string[] } }>,
      reply,
    ) => {
      const me = readSession(request);
      const username = (request.body?.username ?? '').trim();
      const password = request.body?.password ?? '';
      const role = ROLES.includes(request.body?.role ?? '') ? (request.body?.role as string) : 'viewer';

      if (username === '' || password.length < 8) {
        const users = await listUsers();
        const sites = await listSites();
        const assignments = await siteAssignments();
        return reply.code(400).view('users.ejs', {
          title: 'Users',
          user: me,
          users,
          sites,
          assignments,
          error: 'Username required and password must be at least 8 characters.',
        });
      }
      await createUser(username, hashPassword(password), role);

      // A client is scoped to the selected sites; other roles are unscoped.
      if (role === 'client') {
        const raw = request.body?.sites;
        const siteIds = (Array.isArray(raw) ? raw : raw ? [raw] : []).map(Number).filter((n) => Number.isFinite(n));
        const userId = await idForUsername(username);
        if (userId !== null) {
          await setUserSites(userId, siteIds);
        }
      }
      return reply.redirect('/users');
    },
  );
}

/** Seed the initial admin from env when no users exist yet. */
export async function seedAdmin(log: { info: (msg: string) => void }): Promise<void> {
  const { config } = await import('../config.js');
  if (config.adminPassword === '' || (await countUsers()) > 0) {
    return;
  }
  await createUser(config.adminUser, hashPassword(config.adminPassword), 'admin');
  log.info(`seeded initial admin user "${config.adminUser}"`);
}

/**
 * Authentication routes: login, logout, and (admin-only) user management.
 */

import type { FastifyInstance, FastifyRequest } from 'fastify';
import { hashPassword, verifyPassword } from '../crypto/password.js';
import { clearSessionCookie, makeToken, readSession, setSessionCookie } from '../auth/session.js';
import { countUsers, createUser, findByUsername, listUsers } from '../repo/users.js';

const ROLES = ['admin', 'viewer'];

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
    return reply.view('users.ejs', { title: 'Users', user: me, users, error: '' });
  });

  app.post(
    '/users',
    async (request: FastifyRequest<{ Body: { username?: string; password?: string; role?: string } }>, reply) => {
      const me = readSession(request);
      const username = (request.body?.username ?? '').trim();
      const password = request.body?.password ?? '';
      const role = ROLES.includes(request.body?.role ?? '') ? (request.body?.role as string) : 'viewer';

      if (username === '' || password.length < 8) {
        const users = await listUsers();
        return reply.code(400).view('users.ejs', {
          title: 'Users',
          user: me,
          users,
          error: 'Username required and password must be at least 8 characters.',
        });
      }
      await createUser(username, hashPassword(password), role);
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

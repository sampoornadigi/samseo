/**
 * White-label + alerting settings (admin only).
 *
 * Branding (name, logo, accent, support URL) is injected into every view via
 * reply.locals; alerting (webhook + health threshold) drives the alerts service.
 */

import type { FastifyInstance, FastifyRequest } from 'fastify';
import { getAll, setMany } from '../repo/settings.js';
import { sendTestAlert } from '../services/alerts.js';
import { readSession } from '../auth/session.js';

interface SettingsForm {
  brand_name?: string;
  brand_logo?: string;
  brand_accent?: string;
  support_url?: string;
  alert_webhook?: string;
  alert_threshold?: string;
}

export function registerSettings(app: FastifyInstance): void {
  app.get('/settings', async (request, reply) => {
    const me = readSession(request);
    if (!me || me.role !== 'admin') {
      return reply.code(403).send('Forbidden');
    }
    const settings = await getAll();
    return reply.view('settings.ejs', { title: 'Settings', user: me, settings, saved: false });
  });

  app.post('/settings', async (request: FastifyRequest<{ Body: SettingsForm }>, reply) => {
    const me = readSession(request);
    const b = request.body ?? {};
    const threshold = Number(b.alert_threshold);
    await setMany({
      brand_name: (b.brand_name ?? '').trim() || 'Sampoorna · Control Plane',
      brand_logo: (b.brand_logo ?? '').trim(),
      brand_accent: (b.brand_accent ?? '').trim() || '#2271b1',
      support_url: (b.support_url ?? '').trim(),
      alert_webhook: (b.alert_webhook ?? '').trim(),
      // Clamp to 0–100; keep 0 (a valid "only alert on unreachable" setting).
      alert_threshold: String(Number.isFinite(threshold) ? Math.min(100, Math.max(0, threshold)) : 50),
    });
    const settings = await getAll();
    return reply.view('settings.ejs', { title: 'Settings', user: me, settings, saved: true });
  });

  app.post('/settings/test-alert', async (request, reply) => {
    const me = readSession(request);
    const ok = await sendTestAlert();
    const settings = await getAll();
    return reply.view('settings.ejs', {
      title: 'Settings',
      user: me,
      settings,
      saved: false,
      testResult: ok ? 'sent' : 'failed',
    });
  });
}

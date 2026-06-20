/**
 * Config templating routes: define vertical setting bundles and push them to
 * one or many enrolled sites.
 *
 * A push is delivered as an ordinary signed deployment of option-type changes,
 * so it is journaled and reversible exactly like a meta deploy. The field set
 * below MUST stay in sync with the plugin's ControlPlane\Settings::fields().
 */

import { randomUUID } from 'node:crypto';
import type { FastifyInstance, FastifyRequest } from 'fastify';
import { create, getById as getTemplate, list as listTemplates, remove } from '../repo/templates.js';
import { getById as getSite, list as listSites, secretFor } from '../repo/sites.js';
import { deploy } from '../client/siteClient.js';
import { insertDeployment, type Change } from '../repo/pipeline.js';
import { readSession } from '../auth/session.js';

export interface TemplateField {
  field: string;
  label: string;
  type: 'text' | 'textarea' | 'bool' | 'enum';
  options?: string[];
}

/** Mirror of ControlPlane\Settings::fields() in the plugin. */
export const TEMPLATE_FIELDS: TemplateField[] = [
  { field: 'org_name', label: 'Organization name', type: 'text' },
  { field: 'org_logo', label: 'Organization logo URL', type: 'text' },
  { field: 'robots_txt', label: 'robots.txt body', type: 'textarea' },
  { field: 'llms_enabled', label: 'llms.txt enabled', type: 'bool' },
  { field: 'llms_intro', label: 'llms.txt intro', type: 'textarea' },
  { field: 'indexnow_enabled', label: 'IndexNow enabled', type: 'bool' },
  { field: 'digest_enabled', label: 'Search-console digest enabled', type: 'bool' },
  { field: 'digest_freq', label: 'Digest frequency', type: 'enum', options: ['daily', 'weekly'] },
];

const FIELD_KEYS = new Set(TEMPLATE_FIELDS.map((f) => f.field));

/** Build a settings map from a posted form, keeping only allow-listed, non-empty fields. */
function settingsFromForm(body: Record<string, unknown>): Record<string, string> {
  const out: Record<string, string> = {};
  for (const f of TEMPLATE_FIELDS) {
    const raw = body[`f_${f.field}`];
    if (f.type === 'bool') {
      // A checkbox only appears in the body when checked.
      if (raw !== undefined) {
        out[f.field] = '1';
      } else if (body[`has_${f.field}`] !== undefined) {
        out[f.field] = '0';
      }
      continue;
    }
    if (typeof raw === 'string' && raw.trim() !== '') {
      out[f.field] = raw.trim();
    }
  }
  return out;
}

export function registerTemplates(app: FastifyInstance): void {
  app.get('/templates', async (request, reply) => {
    const me = readSession(request);
    if (!me || me.role === 'client') {
      return reply.code(403).send('Forbidden');
    }
    const templates = await listTemplates();
    const sites = await listSites();
    return reply.view('templates.ejs', {
      title: 'Templates',
      user: readSession(request),
      templates,
      sites,
      fields: TEMPLATE_FIELDS,
    });
  });

  app.post(
    '/templates',
    async (request: FastifyRequest<{ Body: Record<string, unknown> }>, reply) => {
      const body = request.body ?? {};
      const name = String(body.name ?? '').trim();
      const vertical = String(body.vertical ?? '').trim();
      if (name === '') {
        return reply.redirect('/templates');
      }
      await create(name, vertical, settingsFromForm(body));
      return reply.redirect('/templates');
    },
  );

  app.post(
    '/templates/:id/delete',
    async (request: FastifyRequest<{ Params: { id: string } }>, reply) => {
      const id = Number(request.params.id);
      if (Number.isFinite(id)) {
        await remove(id);
      }
      return reply.redirect('/templates');
    },
  );

  app.post(
    '/templates/:id/push',
    async (
      request: FastifyRequest<{ Params: { id: string }; Body: { sites?: string | string[]; all?: string } }>,
      reply,
    ) => {
      const id = Number(request.params.id);
      const tpl = Number.isFinite(id) ? await getTemplate(id) : null;
      if (!tpl) {
        return reply.code(404).send({ error: 'template not found' });
      }

      const changes: Change[] = Object.entries(tpl.settings)
        .filter(([field]) => FIELD_KEYS.has(field))
        .map(([field, value]) => ({ type: 'option', id: 0, field, value: String(value) }));
      if (changes.length === 0) {
        return reply.redirect('/templates');
      }

      // Resolve the target site ids: explicit selection, or all when "all" is set.
      let targetIds: number[];
      if (request.body?.all !== undefined) {
        targetIds = (await listSites()).map((s) => s.id);
      } else {
        const raw = request.body?.sites;
        const arr = Array.isArray(raw) ? raw : raw ? [raw] : [];
        targetIds = arr.map(Number).filter((n) => Number.isFinite(n));
      }

      for (const siteId of targetIds) {
        const site = await getSite(siteId);
        if (!site) {
          continue;
        }
        const secret = await secretFor(site.key_id);
        if (secret === null) {
          continue;
        }
        const deployId = `tpl_${tpl.id}_${randomUUID()}`;
        const res = await deploy(site, secret, deployId, changes);
        if (res.ok) {
          await insertDeployment(site.id, deployId, changes, res.data);
        }
      }
      return reply.redirect('/templates');
    },
  );
}

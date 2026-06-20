/**
 * Citation service: run a site's saved prompts through the LLM citation sampler
 * and store the results. Shared by the manual dashboard action and the scheduler.
 *
 * Uses the LLM only — no signed site call — so it needs no secret. With no
 * CP_LLM_KEY the sampler is the deterministic stub (zero external cost).
 */

import { list, type Site } from '../repo/sites.js';
import { listPrompts, recordResults } from '../repo/citation.js';
import { makeLlmClient } from '../llm/client.js';
import { sample } from '../citation/sampler.js';

/** The site's domain host, for citation detection. */
function siteHost(url: string): string {
  try {
    return new URL(url).host;
  } catch {
    return '';
  }
}

/** Sample one site's prompts. Returns false when the site has no prompts. */
export async function citationSite(site: Site): Promise<boolean> {
  const prompts = await listPrompts(site.id);
  if (prompts.length === 0) {
    return false;
  }
  const results = await sample(makeLlmClient(), prompts, {
    domain: siteHost(site.site_url || site.reach_url),
    brand: site.label,
  });
  await recordResults(site.id, results);
  return true;
}

/** Sample every site that has prompts, isolating per-site failures. */
export async function citationAllSites(): Promise<{ sites: number; ran: number }> {
  const sites = await list();
  let ran = 0;
  for (const site of sites) {
    try {
      if (await citationSite(site)) {
        ran += 1;
      }
    } catch {
      // Isolate per-site failures.
    }
  }
  return { sites: sites.length, ran };
}

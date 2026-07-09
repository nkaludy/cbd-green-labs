---
name: new-niche-site
description: Scaffold a new affiliate site by cloning the master template into a fresh LocalWP install and rebranding it for a new niche. Manual invocation only.
disable-model-invocation: true
---

# Clone a New Niche Site

Creates a new affiliate site (instance) from this master template. Usage:
`/new-niche-site <site-name> <domain>` — but confirm details with the user first.

Requested: $ARGUMENTS

**Core doctrine:** This repo (master-template) is NEVER modified by this skill.
It stays a clean template. All work happens in the NEW LocalWP site cloned
from it. (See CLAUDE.md → Template vs. Instance.)

**Migrations are manual** — no migration plugins. Files move via SCP/manual
upload; databases via `wp db export`/`import` (or phpMyAdmin) + `wp search-replace`.

---

## Step 1 — Confirm the basics (don't guess)

Ask the user and confirm before proceeding:
- Site name (blogname), e.g. "CBD Green Labs"
- Domain, e.g. cbdgreenlabs.com
- Affiliate network + which CSV feed
- Local site slug for the new LocalWP install

## Step 2 — Clone the install in LocalWP (USER does this)

Claude can't drive LocalWP's UI. Instruct the user to:
- In LocalWP, right-click the master-template site → Clone (or create a new
  site and copy the repo files in).
- Name it for the new niche (e.g. cbd-green-labs).
- Open the new site's shell ("Open Site Shell") — this provides `wp`.
All remaining `wp` commands run in the NEW site's shell, never the master.

## Step 3 — Skeletonize the database

A fresh clone still contains the FULL die-cast database (13k products, the
"Die Cast Cars Collector" title, Pretty Links, etc.). Reset it:

    bash .claude/skills/new-niche-site/skeletonize-db.sh

The script has safety guards (refuses to run on master-template.local or
diecastcarscollector.com; requires typing the target domain). It removes
products, brand terms, import log, Pretty Links, transients, and resets the
taxonomy seed flag. Code/pages/taxonomy structure are untouched.

## Step 4 — Verify the CPT + fields carried over

The affiliate_product post type and its ACF fields are registered in PHP
(theme: inc/cpt-affiliate-product.php and inc/acf-fields-affiliate-product.php)
— they travel with the code, NOT via acf-json sync. Verify:

    wp post-type list        # affiliate_product present
Then open a new "affiliate_product" in wp-admin and confirm the custom fields
(main_image, price, etc.) render on the edit screen.

## Step 5 — Rebrand for the new niche

- Title/tagline:
    wp option update blogname "New Site Name"
    wp option update blogdescription "New tagline"
- Niche copy/vocab via the theme's filter hooks (do NOT hardcode in template
  code): affiliate_master_home_categories / _home_scales / _home_trust /
  _hero_tags, affiliate_master_seed_terms, afpi_product_type_rules,
  afpi_placeholder_image_signals, afpf_filter_taxonomies,
  afpf_hide_imageless_products, afpf_price_display. Add site-specific
  overrides in the child theme (or a small site plugin), not in the template.
- Taxonomy vocabulary re-seeds automatically on first load (seed flag was
  reset in Step 3).

## Step 6 — Per-clone manual gotchas (easy to forget)

- WPForms: replace `FORM_ID` placeholder in the contact template
  (page-contact.php) with the new site's form ID.
- robots.txt: fix the hardcoded sitemap URL (repo-root robots.txt still points
  at master-template.local) — or delete robots.txt to fall back to Rank Math's
  dynamic virtual one.
- Google Site Kit: OAuth is per-site, browser-only — set up in wp-admin.
- Leftover-reference sweep: grep the theme/config for any remaining hardcoded
  `master-template` references and fix them — the catch-all for environmental
  leaks beyond the known robots.txt case.
- Leave Rank Math / analytics defaults unless the user asks — don't invent
  site-specific SEO config.

## Step 7 — Prepare the product feed (CSV cleaner)

- Copy `affiliate-csv-cleaner/config/example_custom.json` to a new per-network
  config (e.g. `awin_cbd.json`).
- Remap the left-hand source column names to the new feed's CSV headers; keep
  the right-hand target fields.
- Adjust extraction regexes + SEO sentence templates for the niche (the
  defaults are die-cast-flavored — scale patterns, "die cast replica…"), or
  toggle the enrich/extract steps off if not needed.
- Run the cleaner, then import via the importer plugin — or hold off if the
  feed/content isn't ready; scaffolding the site now and importing products
  later is a normal path.

## Step 8 — Deploy to live (manual)

- Fresh WP install on the live host (or replace an existing site — back it up
  first).
- Push files via SCP; import the database via `wp db import` (or phpMyAdmin).
- Run `wp search-replace 'oldurl' 'newurl'` to swap the domain in the DB.
- Flush rewrites (`wp rewrite flush`) and caches; bump asset versions if CSS
  changed.

---

## Keep this skill current

If any step here proves wrong in practice, UPDATE this file. A stale skill is
worse than no skill — it sends future clones down wrong paths.

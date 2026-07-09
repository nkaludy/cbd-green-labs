# CLAUDE.md — Affiliate Site Master Template

This repository is the **master template** for a portfolio of AWIN/CSV
affiliate websites. It is the "cookie cutter" — the reusable code and tooling
that new sites are cloned from. Read this before making changes.

Traffic strategy is **Google SEO + Pinterest** (Pinterest matters when
choosing image sizes and share markup).

## Template vs. Instance (IMPORTANT)

- **This repo = the template.** It holds reusable code (theme, custom plugins,
  CSV cleaner) and clone tooling. It should stay niche-agnostic.
- **A "site" (instance) = a LocalWP install cloned from this template**, with
  its own database, branding, niche content, and live domain.
- The FIRST instance is **Die Cast Cars Collector** (live at
  diecastcarscollector.com). Historically it was built *in* this repo before
  the template was separated — so this install currently still carries the
  die-cast database. The split moves die cast to its own instance and keeps
  this repo clean.
- **Rule: niche-specific content lives in the instance's database or behind
  filters — never hardcoded into template code.** If you're adding something
  die-cast-specific to the code, stop and make it configurable instead.
- **Rule: keep code free of ENVIRONMENT specifics too** — site name, domain,
  DB credentials — because sites get sold ("flipped") and re-homed. This is a
  different failure mode from niche leakage: the hardcoded sitemap domain in
  `robots.txt` was an environmental leak (every clone must fix it), not a
  niche one.

## The Stack

- **WordPress** (core is git-ignored), **Astra** parent theme.
- **affiliate-master** — custom child theme (branding via `--am-*` CSS vars,
  homepage/hero/taxonomy templates, ACF field registration in PHP).
- **affiliate-product-importer** (v1.1.0, `AFPI_` prefix) — imports products
  from a cleaned CSV; assigns taxonomies; detects dead feed images.
- **affiliate-product-filter** (v1.1.7, `AFPF_` prefix; constants
  `AFPF_VERSION`, `AFPF_PLUGIN_DIR`, `AFPF_PLUGIN_URL`) — the `[affiliate_filter]`
  product grid + faceted sidebar.
- **Vendor plugins:** ACF, Rank Math, LiteSpeed Cache, Google Site Kit,
  Pretty Link, Copy Delete Posts, WPForms (+others).
- **affiliate-csv-cleaner/** — Python pipeline (at repo root) that cleans a raw
  AWIN CSV into import-ready form; per-network JSON configs under its `config/`.
- **Do NOT introduce WooCommerce or assume a cart/checkout flow** — products
  are a custom post type (`affiliate_product`), not Woo products.

## Where custom code goes

- **Never edit the Astra parent theme in place** (`wp-content/themes/astra`).
- **Importer/filter logic** lives in their own plugins
  (`affiliate-product-importer`, `affiliate-product-filter`) — not mu-plugins,
  not theme code.
- **Templates and styling** go in the `affiliate-master` child theme.

## Workflow

- **Build locally first** (LocalWP), commit to GitHub, deploy to live via SCP
  over SSH. Never edit live directly; the template/instance is the source of truth.
- **Live servers are plain files** (not git checkouts) — deploy by SCP'ing
  changed files, then flush caches / bump asset versions for cache-busting.
- **Commits go directly to `main`** (established convention). Remote is HTTPS:
  https://github.com/nkaludy/master-template
- **PHPCS** runs before commits, scoped to custom code only (see `phpcs.xml`).
  System `php`/`composer` are not installed — use Local's bundled PHP:

  ```
  "/Users/nathankaludy/Library/Application Support/Local/lightning-services/php-8.4.10+0/bin/darwin-arm64/bin/php" ./vendor/bin/phpcs
  ```

- **Migrations are MANUAL — do not reintroduce a migration plugin.** Files
  move via SCP/manual upload; databases move via manual export/import
  (`wp db export` / `wp db import` or phpMyAdmin) plus `wp search-replace`
  to swap the domain.
- `wp-config.php` is git-ignored; local DB credentials in it are not a leak.

## Cloning a New Site

See `.claude/skills/new-niche-site/SKILL.md` for the full step-by-step.
Key tools:
- **skeletonize-db.sh** (in that skill folder) — resets a fresh clone's
  database to template state. Has hard safety guards; never runs on die cast.
- Per-clone manual steps (can't be scripted): set blogname/tagline, replace
  WPForms `FORM_ID`, fix `robots.txt` sitemap URL, Site Kit OAuth, niche
  copy via the theme's filter hooks, and the CSV cleaner's per-network config.

## Configurable Hooks (for re-skinning per niche)

Niche behavior is exposed via filters so clones don't edit template code:
`affiliate_master_home_categories`, `_home_scales`, `_home_trust`,
`_hero_tags`, `affiliate_master_seed_terms`, `afpi_product_type_rules`,
`afpi_placeholder_image_signals`, `afpf_filter_taxonomies`,
`afpf_hide_imageless_products`, `afpf_price_display`.

## Code Style

- Comment the **why**, not the what. Explain non-obvious decisions and gotchas
  (e.g. the LiteSpeed piped-ID corruption, the `tv-movie` slug) so the next
  person — or the next Claude session — doesn't relearn them the hard way.
- Match existing conventions in the file you're editing.

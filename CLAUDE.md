# CLAUDE.md — CBD Green Labs (site instance)

**This is an INSTANCE, not the template.** This repo (`nkaludy/cbd-green-labs`)
is a single affiliate site — CBD Green Labs (cbdgreenlabs.com) — cloned from the
master-template. It is NOT the master template. Do not treat template point-of-view
text as describing this repo, and **do not reference Die Cast** — that's a separate
instance. Niche: CBD / hemp wellness. Traffic: Google SEO + Pinterest.

## Status (fresh build in progress)
- Building locally first: ~/Local Sites/cbd-green-labs/app/public (cbd-green-labs.local).
- Database has been skeletonized — clean instance, Die Cast data removed.
- An OLD CBD Green Labs site exists live and will be DELETED and rebuilt fresh from
  this instance. Back it up (files + DB, manual) before deleting.
- Live server / cPanel / SSH details: TBD — fill in at deploy time.

## Inherited from the master template (configure, don't rebuild)
- WordPress core (git-ignored) + Astra parent theme.
- affiliate-master child theme (branding via --am-* CSS vars, templates, ACF).
- affiliate-product-importer (AFPI_) — cleaned CSV → affiliate_product posts.
- affiliate-product-filter (AFPF_) — [affiliate_filter] grid + faceted sidebar.
- affiliate-csv-cleaner/ — Python CSV cleaner, per-network JSON configs.
- Vendor: ACF, Rank Math, LiteSpeed Cache, Site Kit, Pretty Link, WPForms.
- NO WooCommerce. Products are the affiliate_product CPT, not Woo products.

## Niche config goes through FILTERS, never code edits
- afpi_product_type_rules — CBD product-type detection (gummies, oils/tinctures,
  topicals, capsules, vapes, pet CBD, flower — final set TBD).
- afpf_filter_taxonomies — facets shown in the filter sidebar.
- affiliate_master_home_categories / _home_scales / _home_trust / _hero_tags — homepage.
- affiliate_master_seed_terms — starter taxonomy terms.
- afpf_price_display — price formatting (USD).
If about to hardcode CBD-specifics into shared code, STOP — make it a filter config.

## Workflow
- Build locally (LocalWP) → commit to main → deploy live via SCP over SSH.
- Never edit live directly. This local instance is the source of truth.
- Commits go straight to main. Remote: https://github.com/nkaludy/cbd-green-labs
- Migrations are MANUAL — no migration plugin. Files via SCP; DB via
  wp db export/import (or phpMyAdmin) + wp search-replace to swap the domain.

## Inherited gotchas (keep)
- Taxonomy slugs are SINGULAR everywhere (/brand/ not /brands/) — plural = 404.
- AWIN price column is search_price (cleaner renames it to price).
- Live PHP alias: alias wp='/opt/cpanel/ea-php82/root/usr/bin/php /usr/local/bin/wp'
- robots.txt hardcodes the sitemap domain — must be cbdgreenlabs.com.

## Cleanup owed (template leftovers in this clone)
This clone still carries template point-of-view files: the old CLAUDE.md (being
replaced now) and .claude/skills/new-niche-site/. Prune or adapt them so future
sessions aren't misled by template-POV text.

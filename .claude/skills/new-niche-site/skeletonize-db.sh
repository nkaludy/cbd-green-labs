#!/usr/bin/env bash
#
# skeletonize-db.sh — reset a FRESH CLONE's database to template state.
#
# A Local by Flywheel clone starts as a byte-copy of the source site,
# products and all. This script strips the instance data (products,
# import history, Pretty Links, caches) so the clone starts clean,
# while KEEPING the structural furniture every clone needs: pages,
# menus, and the seeded taxonomy terms the homepage links to
# (product-type, scale, material, vehicle-make).
#
# Provenance: this is the DB cleanup performed manually on 2026-07-05
# (recorded in commit 8c85b5a's message), turned into a script. All
# table names resolve through $wpdb->prefix at runtime — nothing is
# hardcoded to "wp_".
#
# USAGE — run from the CLONE's site shell (Local: right-click site >
# Open Site Shell, which puts a working `wp` on PATH), from the
# clone's public folder:
#
#     bash .claude/skills/new-niche-site/skeletonize-db.sh
#
# Or pass the WordPress path explicitly as $1. Override the wp binary
# with WP_BIN if needed (e.g. a server's ea-php wrapper).
#
# SAFETY:
#   - Refuses to run against the master template or the die cast
#     production domains, ever.
#   - Requires typing the target site's domain back before deleting.
#   - Deletions run inside `wp eval` loops, NOT `wp post list | xargs`:
#     LiteSpeed's WP-CLI hook prints "Purged all caches" lines into
#     stdout and corrupts piped ID lists (verified the hard way).

set -euo pipefail

WP_BIN="${WP_BIN:-wp}"
WP_PATH="${1:-$(pwd)}"

wp_run() {
	"$WP_BIN" --allow-root --path="$WP_PATH" "$@"
}

# Strip LiteSpeed's cache-purge chatter when capturing output.
wp_clean() {
	wp_run "$@" 2>/dev/null | grep -v "^Success: Purged" || true
}

echo "== skeletonize-db: target inspection =="
SITE_URL="$(wp_clean option get siteurl | tail -1)"
SITE_NAME="$(wp_clean option get blogname | tail -1)"
PRODUCT_COUNT="$(wp_clean post list --post_type=affiliate_product --post_status=any --format=count | grep -E '^[0-9]+$' | tail -1 || echo 0)"

echo "  path:     $WP_PATH"
echo "  site:     $SITE_NAME"
echo "  url:      $SITE_URL"
echo "  products: ${PRODUCT_COUNT:-0}"
echo ""

# ------------------------------------------------------------------
# HARD REFUSALS: never the master template, never production.
# A fresh Local clone gets its own .local domain, so matching these
# means someone pointed the script at the wrong install.
# ------------------------------------------------------------------
case "$SITE_URL" in
	*master-template.local*)
		echo "REFUSING: this is the master template install. This script is for clones." >&2
		exit 1
		;;
	*diecastcarscollector.com*)
		echo "REFUSING: this is the live die cast production site." >&2
		exit 1
		;;
esac

# ------------------------------------------------------------------
# TYPED CONFIRMATION GATE.
# ------------------------------------------------------------------
DOMAIN="${SITE_URL#*://}"
DOMAIN="${DOMAIN%%/*}"
echo "This will PERMANENTLY DELETE all ${PRODUCT_COUNT:-0} products, import history,"
echo "Pretty Links, and caches on: $DOMAIN"
printf 'Type the domain exactly to proceed: '
read -r CONFIRM
if [ "$CONFIRM" != "$DOMAIN" ]; then
	echo "Confirmation did not match ('$CONFIRM' != '$DOMAIN'). Nothing was changed."
	exit 1
fi

echo ""
echo "== 1/6 deleting affiliate_product posts (wp eval loop) =="
wp_run eval '
$deleted = 0;
while ( true ) {
	$ids = get_posts( array(
		"post_type"      => "affiliate_product",
		"post_status"    => "any",
		"posts_per_page" => 200,
		"fields"         => "ids",
	) );
	if ( empty( $ids ) ) {
		break;
	}
	foreach ( $ids as $id ) {
		if ( wp_delete_post( $id, true ) ) {
			$deleted++;
		}
	}
	// Keep a long run flat on memory.
	wp_cache_flush();
	if ( 0 === $deleted % 1000 ) {
		echo "  ... {$deleted}\n";
	}
}
echo "  deleted {$deleted} products\n";
'

echo "== 2/6 deleting import-residue brand terms (structural taxonomies kept) =="
wp_run eval '
$deleted = 0;
foreach ( (array) get_terms( array( "taxonomy" => "brand", "hide_empty" => false ) ) as $term ) {
	if ( ! is_wp_error( $term ) && ! is_wp_error( wp_delete_term( $term->term_id, "brand" ) ) ) {
		$deleted++;
	}
}
echo "  deleted {$deleted} brand terms (product-type/scale/material/vehicle-make untouched)\n";
'

echo "== 3/6 emptying importer + Pretty Links tables (prefix via \$wpdb) =="
wp_run eval '
global $wpdb;
foreach ( array( "afpi_import_log", "prli_links", "prli_link_metas", "prli_clicks" ) as $table ) {
	$full = $wpdb->prefix . $table;
	if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $full ) ) ) {
		$rows = (int) $wpdb->query( "DELETE FROM `{$full}`" ); // phpcs:ignore WordPress.DB
		echo "  {$full}: {$rows} rows deleted\n";
	} else {
		echo "  {$full}: not present, skipped\n";
	}
}
'

echo "== 4/6 clearing transients =="
wp_run transient delete --all 2>&1 | grep -v "^Success: Purged" || true
echo "  (a couple dozen core update-check transients recreate immediately — normal)"

echo "== 5/6 flushing object cache =="
wp_run cache flush 2>&1 | grep -v "^Success: Purged" || true

echo "== 6/6 resetting taxonomy seed flag =="
# May legitimately be absent (it is deleted by this very cleanup and
# only reappears on theme activation) — absence is not an error.
wp_run option delete affiliate_master_terms_seeded 2>/dev/null || echo "  (flag was already absent — fine)"

echo ""
echo "== DONE. Manual per-clone steps this script cannot do: =="
echo "  [ ] wp option update blogname / blogdescription (site identity)"
echo "  [ ] robots.txt: fix the hardcoded Sitemap URL (or delete the file"
echo "      to fall back to Rank Math's dynamic virtual robots.txt)"
echo "  [ ] page-contact.php: replace the WPForms FORM_ID placeholder"
echo "      after creating the clone's contact form"
echo "  [ ] Site Kit: browser OAuth setup (Search Console + GA4 per site)"
echo "  [ ] Homepage niche copy: hero text in front-page.php + the"
echo "      affiliate_master_home_*/hero_tags filters; importer keyword"
echo "      rules via afpi_product_type_rules; placeholder signals via"
echo "      afpi_placeholder_image_signals if the feed differs"
echo "  [ ] CSV cleaner: copy config/example_custom.json for the new network"
echo "  [ ] wp rewrite flush after any permalink-affecting changes"

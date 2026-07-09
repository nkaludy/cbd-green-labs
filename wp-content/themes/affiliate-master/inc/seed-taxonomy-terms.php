<?php
/**
 * [DOC-110] Seed the curated taxonomy vocabularies on theme activation.
 *
 * The brand / scale / product-type taxonomies fill themselves from
 * feed data during imports, but the other two (material,
 * vehicle-make) are hand-curated vocabularies — and terms live in
 * the database, not in git. Without this file, a site built from the
 * repo alone would start with empty dropdowns and someone would have
 * to retype ~20 terms by hand on every clone. Same reasoning as registering the ACF fields in PHP
 * ([DOC-007]): the code IS the config, and a fresh site is complete
 * the moment the theme is activated.
 *
 * @package Affiliate_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [DOC-111] The starting vocabulary for each curated taxonomy.
 *
 * A term is either a plain string (WordPress derives the slug) or a
 * name => slug pair for cases where auto-slugification mangles the
 * name (kept even though no current entry needs it — the price-range
 * vocabulary that motivated it is gone, but clones add their own
 * vocabularies through the filter below and will hit the same
 * slugification traps).
 *
 * Filterable ('affiliate_master_seed_terms') so a niche clone can
 * trim or extend the lists (an aviation site has no use for Plymouth)
 * without editing template code it would then have to merge around.
 *
 * @return array taxonomy => list of terms.
 */
function affiliate_master_get_seed_terms() {
	$terms = array(
		'material'     => array( 'Die Cast Metal', 'Plastic Snap-Fit', 'Resin' ),
		'vehicle-make' => array( 'Ford', 'Chevrolet', 'Dodge', 'Pontiac', 'Cadillac', 'Buick', 'Oldsmobile', 'Plymouth', 'Ferrari', 'Lamborghini', 'Porsche', 'Mercedes-Benz', 'BMW', 'Aston Martin', 'Boeing', 'Airbus', 'Douglas' ),
	);

	return apply_filters( 'affiliate_master_seed_terms', $terms );
}

/**
 * [DOC-112] Seed the terms, once per site.
 *
 * Runs on after_switch_theme (theme activation), guarded by a
 * one-time option flag rather than by term_exists() alone. The flag
 * matters: term_exists() would happily re-create any term a site
 * owner deliberately deleted the next time the theme was re-activated
 * (theme updates, debugging switches, etc.), silently undoing a
 * curation decision. With the flag, seeding happens exactly once in a
 * site's life; deleting the 'affiliate_master_terms_seeded' option is
 * the explicit opt-in to re-seed.
 *
 * Within that one run, existing terms are still skipped (never
 * duplicated, never modified), so activating the theme on a site that
 * already has data — like this template's own dev site — is safe.
 *
 * The taxonomies are registered on init but after_switch_theme fires
 * before init on the activation request, so registration is invoked
 * directly here — the same functions init would call, just earlier.
 */
function affiliate_master_seed_taxonomy_terms() {

	if ( get_option( 'affiliate_master_terms_seeded' ) ) {
		return;
	}

	// Ensure the taxonomies exist this early in the request. The
	// sentinel must be a registered taxonomy: it originally checked
	// 'era', which the taxonomy cull removed — a stale sentinel here
	// silently skips registration and seeds nothing.
	if ( ! taxonomy_exists( 'material' ) && function_exists( 'affiliate_master_register_taxonomies' ) ) {
		affiliate_master_register_taxonomies();
	}

	foreach ( affiliate_master_get_seed_terms() as $taxonomy => $terms ) {

		if ( ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}

		foreach ( $terms as $key => $value ) {
			// Plain entries are name-only; string keys carry an explicit slug.
			$name = is_string( $key ) ? $key : $value;
			$args = is_string( $key ) ? array( 'slug' => $value ) : array();

			if ( term_exists( $name, $taxonomy ) ) {
				continue;
			}

			wp_insert_term( $name, $taxonomy, $args );
		}
	}

	update_option( 'affiliate_master_terms_seeded', 1, false );
}
add_action( 'after_switch_theme', 'affiliate_master_seed_taxonomy_terms' );

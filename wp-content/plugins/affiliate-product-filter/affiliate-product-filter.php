<?php
/**
 * Plugin Name:       Affiliate Product Filter
 * Plugin URI:        https://github.com/nkaludy/master-template
 * Description:       AJAX-powered filtering and search for the affiliate_product catalog: taxonomy filters, live search, shareable URLs and a responsive product grid via the [affiliate_filter] shortcode.
 * Version:           1.1.7
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Master Template
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       affiliate-product-filter
 *
 * [DOC-113] Plugin bootstrap.
 *
 * Built from scratch (no FacetWP or filter frameworks) on purpose:
 * filtering is core to every site cloned from this template, and a
 * dependency-free implementation means no per-site license fees, no
 * upgrade treadmill, and full control over markup/SEO. Front-end
 * output is a single shortcode so page builders and block editors on
 * any clone can place the catalog anywhere.
 *
 * @package Affiliate_Product_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AFPF_VERSION', '1.1.7' );
define( 'AFPF_PLUGIN_FILE', __FILE__ );
define( 'AFPF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AFPF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AFPF_PLUGIN_DIR . 'includes/class-filter-query.php';
require_once AFPF_PLUGIN_DIR . 'includes/class-filter-ajax.php';
require_once AFPF_PLUGIN_DIR . 'includes/class-filter-shortcode.php';
require_once AFPF_PLUGIN_DIR . 'includes/class-filter-search.php';
require_once AFPF_PLUGIN_DIR . 'includes/class-filter-url.php';

/**
 * [DOC-114] The filterable taxonomies, keyed by their URL parameter.
 *
 * One canonical map consumed by everything: the panel template renders
 * a section per entry, the URL class parses/writes ?afpf_* params from
 * it, the query builder turns it into tax_query clauses, and filter.js
 * receives it via the localized config. Defining it once means adding
 * a taxonomy to the filter UI on a niche clone is ONE filter callback,
 * not five edits that must agree.
 *
 * Keys are short because they become URL parameters ("afpf_type=" is
 * friendlier to share than "afpf_product-type="); values are the
 * taxonomy names registered by the affiliate-master theme ([DOC-098]).
 * The 'afpf_' prefix on the eventual URL params is not cosmetic:
 * bare taxonomy query vars like ?brand=x are claimed by WordPress
 * itself (the taxonomies register query_var), and using them on a
 * filter page would make WP swap the main query for a taxonomy
 * archive.
 *
 * vehicle-make is a registered taxonomy but NOT in the default filter
 * panel — it keeps the panel to four usable groups; clones can add it
 * via this filter if their niche warrants it.
 *
 * @return array param key => taxonomy name.
 */
function afpf_get_filter_taxonomies() {
	return apply_filters(
		'afpf_filter_taxonomies',
		array(
			'type'     => 'product-type',
			'brand'    => 'brand',
			'scale'    => 'scale',
			'material' => 'material',
		)
	);
}

/**
 * [DOC-115] Load a plugin template, allowing theme overrides.
 *
 * Looks for the file in {child theme}/affiliate-product-filter/ first,
 * then falls back to the plugin's templates/ directory. Sites get
 * cloned and re-skinned from this template constantly; letting a
 * clone restyle a product card by copying one file into its child
 * theme (instead of forking the plugin) is what keeps every clone on
 * the same upgradable plugin code.
 *
 * $args is extracted into the template's local scope — the same
 * contract as WordPress core's get_template_part() since 5.5.
 *
 * @param string $template Template file name, e.g. 'product-card.php'.
 * @param array  $args     Variables to expose to the template.
 */
function afpf_get_template( $template, $args = array() ) {
	$theme_file = get_stylesheet_directory() . '/affiliate-product-filter/' . $template;
	$file       = file_exists( $theme_file ) ? $theme_file : AFPF_PLUGIN_DIR . 'templates/' . $template;

	if ( ! file_exists( $file ) ) {
		return;
	}

	// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Mirrors core's get_template_part() $args behaviour; keys are plugin-controlled, never user input.
	extract( $args, EXTR_SKIP );

	include $file;
}

/**
 * [DOC-201] Hide image-less products from BROWSE surfaces only.
 *
 * Products flagged _afpi_has_real_image = '0' by the importer
 * ([DOC-199]/[DOC-200]: dead feed image or no image at all) are
 * excluded from every query built by AFPF_Filter_Query::build() —
 * which is all of them: homepage strip, catalog, and every taxonomy
 * archive. Single-product pages use the main query and never pass
 * through build(), so they stay live and indexable by design.
 *
 * The OR clause is the load-bearing subtlety: only an EXPLICIT '0'
 * hides a product. '1' and — critically — NO FLAG AT ALL stay
 * visible, because most products are unflagged until the one-time
 * backfill runs; a bare value-check here would blank the catalog.
 *
 * Toggleable per clone: add_filter( 'afpf_hide_imageless_products',
 * '__return_false' ) turns the exclusion off without forking.
 *
 * @param array $args  WP_Query arguments from build().
 * @param array $state Sanitized filter state (unused).
 * @return array Amended arguments.
 */
function afpf_exclude_imageless_products( $args, $state ) {
	if ( ! apply_filters( 'afpf_hide_imageless_products', true ) ) {
		return $args;
	}

	$clause = array(
		'relation' => 'OR',
		array(
			'key'     => '_afpi_has_real_image',
			'compare' => 'NOT EXISTS',
		),
		array(
			'key'     => '_afpi_has_real_image',
			'value'   => '0',
			'compare' => '!=',
		),
	);

	if ( empty( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
		$args['meta_query'] = array( $clause ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One indexed-key clause on browse queries; the flag exists precisely so render time never does network checks.
	} else {
		$args['meta_query'][] = $clause;
	}

	return $args;
}
add_filter( 'afpf_query_args', 'afpf_exclude_imageless_products', 10, 2 );

/**
 * [DOC-116] Boot the plugin.
 *
 * The search integration and AJAX endpoints must exist on every
 * request type (admin-ajax.php runs with is_admin() true, and the
 * search hooks must be live whenever a filter query runs), so unlike
 * the importer plugin there is no is_admin() gate here — each class
 * is cheap to construct and does nothing until its hooks fire.
 */
function afpf_boot() {
	new AFPF_Filter_Search();
	new AFPF_Filter_Ajax();
	new AFPF_Filter_Shortcode();

	AFPF_Filter_Query::register_cache_invalidation();
}
add_action( 'plugins_loaded', 'afpf_boot' );

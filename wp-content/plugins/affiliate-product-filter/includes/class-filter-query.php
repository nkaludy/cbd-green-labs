<?php
/**
 * [DOC-117] Building WP_Query objects from filter state.
 *
 * The single translation point between "what the user selected" (the
 * sanitized state array produced by AFPF_Filter_URL) and an actual
 * database query. Both render paths — the initial server-side render
 * in the shortcode and every AJAX request — call the same build()
 * method, which is what guarantees a shared URL reproduces exactly
 * the results the sharer was looking at.
 *
 * @package Affiliate_Product_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns filter state into WP_Query args, and provides the cached
 * taxonomy term counts the filter panel displays.
 */
class AFPF_Filter_Query {

	/**
	 * Transient key for the term-count cache. See [DOC-120].
	 *
	 * @var string
	 */
	const COUNTS_TRANSIENT = 'afpf_term_counts';

	/**
	 * [DOC-118] Build a WP_Query for the given filter state.
	 *
	 * State shape (always pre-sanitized by AFPF_Filter_URL::parse()):
	 *   search  (string) free-text search term
	 *   page    (int)    1-based page for Load More
	 *   tax     (array)  taxonomy => [term slugs] from user filters
	 *   locked  (array)  taxonomy => [term slugs] from shortcode atts
	 *
	 * Decisions:
	 *   - Taxonomies combine with AND, terms within one taxonomy with
	 *     IN (OR): "brand: Maisto OR Jada, AND scale: 1/18" matches how
	 *     every commerce filter users know behaves — nobody expects
	 *     checking two brands to require a product be both brands.
	 *   - 'locked' terms (from [affiliate_filter taxonomy= term=]) are
	 *     merged as separate AND clauses so a pre-filtered brand page
	 *     stays inside its brand no matter what visitors check.
	 *   - no_found_rows is left ON (default) because found_posts powers
	 *     the "Showing X of Y" counter and the Load More button's
	 *     has-more calculation.
	 *   - Search sets both 's' (core title/content matching) and the
	 *     'afpf_search' flag that AFPF_Filter_Search keys on to extend
	 *     matching to the brand meta field ([DOC-131]).
	 *
	 * @param array $state Sanitized filter state.
	 * @param int   $per_page Products per page.
	 * @return WP_Query The executed query.
	 */
	public static function build( $state, $per_page = 24 ) {
		$args = array(
			'post_type'           => 'affiliate_product',
			'post_status'         => 'publish',
			'posts_per_page'      => max( 1, (int) $per_page ),
			'paged'               => max( 1, (int) ( $state['page'] ?? 1 ) ),
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
		);

		$tax_query = self::build_tax_query( $state );
		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Taxonomy filtering is the feature; term counts are cached separately ([DOC-120]).
		}

		if ( '' !== ( $state['search'] ?? '' ) ) {
			$args['s']           = $state['search'];
			$args['afpf_search'] = $state['search'];
		}

		/**
		 * Filters the final query args so clones can change ordering
		 * (e.g. price sorting) without forking the plugin.
		 *
		 * @param array $args  WP_Query arguments.
		 * @param array $state Sanitized filter state.
		 */
		$args = apply_filters( 'afpf_query_args', $args, $state );

		return new WP_Query( $args );
	}

	/**
	 * [DOC-119] Assemble the tax_query from user + locked selections.
	 *
	 * User selections within one taxonomy become a single IN clause;
	 * locked shortcode terms get their own clause per taxonomy. The
	 * top-level relation is AND. Slugs were sanitized upstream but the
	 * taxonomy names are validated against the canonical map here too,
	 * so a hand-crafted AJAX payload cannot query arbitrary
	 * taxonomies.
	 *
	 * @param array $state Sanitized filter state.
	 * @return array tax_query array (may be empty).
	 */
	private static function build_tax_query( $state ) {
		$allowed   = array_values( afpf_get_filter_taxonomies() );
		$allowed[] = 'vehicle-make';

		$tax_query = array();

		foreach ( array( 'tax', 'locked' ) as $group ) {
			foreach ( (array) ( $state[ $group ] ?? array() ) as $taxonomy => $slugs ) {
				if ( empty( $slugs ) || ! in_array( $taxonomy, $allowed, true ) || ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => array_values( $slugs ),
				);
			}
		}

		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		return $tax_query;
	}

	/**
	 * [DOC-120] Cached per-term product counts for the filter panel.
	 *
	 * Returns [taxonomy][term slug] => ['name','count'], cached in a
	 * 12-hour transient. The counts shown next to each checkbox are
	 * GLOBAL catalog counts, not faceted recounts relative to current
	 * filters — that is a deliberate trade: faceted counts would need
	 * a COUNT query per term per request (uncacheable, since they
	 * depend on every filter combination), while global counts are one
	 * cheap get_terms() per taxonomy amortised across 12 hours. On a
	 * catalog that changes only when a feed is imported, global counts
	 * are also simply true almost all of the time; the invalidation
	 * hook below covers the moments they aren't.
	 *
	 * hide_empty is on: a checkbox that can only ever produce "No
	 * products found" is UI noise.
	 *
	 * @return array taxonomy => [ slug => [ name, count ] ].
	 */
	public static function get_term_counts() {
		$counts = get_transient( self::COUNTS_TRANSIENT );

		if ( is_array( $counts ) ) {
			return $counts;
		}

		$counts = array();

		foreach ( afpf_get_filter_taxonomies() as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			$counts[ $taxonomy ] = array();
			foreach ( $terms as $term ) {
				$counts[ $taxonomy ][ $term->slug ] = array(
					'name'  => $term->name,
					'count' => (int) $term->count,
				);
			}
		}

		set_transient( self::COUNTS_TRANSIENT, $counts, 12 * HOUR_IN_SECONDS );

		return $counts;
	}

	/**
	 * [DOC-121] Invalidate the count cache when the catalog changes.
	 *
	 * Hooked to save_post_affiliate_product (fires on create, edit,
	 * trash-restore) and set_object_terms (fires when the importer or
	 * an editor assigns taxonomy terms — which can change counts
	 * without a post save). Deleting the transient rather than
	 * rebuilding it here keeps writes cheap during a 13k-row import;
	 * the next panel render pays the one rebuild.
	 */
	public static function register_cache_invalidation() {
		add_action( 'save_post_affiliate_product', array( __CLASS__, 'flush_count_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_count_cache' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'flush_count_cache' ) );
	}

	/**
	 * Delete the term-count transient. See [DOC-121].
	 */
	public static function flush_count_cache() {
		delete_transient( self::COUNTS_TRANSIENT );
	}

	/**
	 * [DOC-122] Total published products, for "Showing X of Y".
	 *
	 * Core already object-caches wp_count_posts(), so no
	 * transient needed on top.
	 *
	 * @return int Total published affiliate products.
	 */
	public static function get_total_products() {
		return (int) wp_count_posts( 'affiliate_product' )->publish;
	}
}

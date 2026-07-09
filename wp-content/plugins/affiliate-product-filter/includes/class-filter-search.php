<?php
/**
 * [DOC-129] Search across title, content AND the brand ACF field.
 *
 * Core's 's' parameter only matches post_title / post_content /
 * post_excerpt. On this catalog, "maisto" is the single most likely
 * thing a visitor types, and brand lives in postmeta — so without
 * this class the most natural search on the site would return
 * nothing. These filters graft the brand meta field into the search
 * WHERE clause of OUR queries only.
 *
 * @package Affiliate_Product_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extends WordPress search to the brand meta field for filter queries.
 */
class AFPF_Filter_Search {

	/**
	 * [DOC-130] Hook the query filters.
	 *
	 * The filters are registered permanently but each one no-ops
	 * unless the query carries the 'afpf_search' var that only
	 * AFPF_Filter_Query::build() sets ([DOC-118]) — so the main query,
	 * admin searches and other plugins' queries are never touched.
	 * That flag-check pattern (rather than adding/removing filters
	 * around each query) is race-free and costs one array lookup per
	 * query.
	 */
	public function __construct() {
		add_filter( 'posts_join', array( $this, 'join_brand_meta' ), 10, 2 );
		add_filter( 'posts_search', array( $this, 'extend_search' ), 10, 2 );
		add_filter( 'posts_distinct', array( $this, 'make_distinct' ), 10, 2 );
	}

	/**
	 * [DOC-131] Join the brand meta row onto the posts table.
	 *
	 * LEFT JOIN (not INNER) because products without a brand value
	 * must still be searchable by title/content. The join is
	 * constrained to meta_key = 'brand' in the ON clause so at most
	 * one meta row pairs with each post.
	 *
	 * @param string   $join  Current JOIN SQL.
	 * @param WP_Query $query Running query.
	 * @return string Possibly extended JOIN SQL.
	 */
	public function join_brand_meta( $join, $query ) {
		if ( '' === (string) $query->get( 'afpf_search' ) ) {
			return $join;
		}

		global $wpdb;

		$join .= " LEFT JOIN {$wpdb->postmeta} afpf_brand ON ( afpf_brand.post_id = {$wpdb->posts}.ID AND afpf_brand.meta_key = 'brand' ) ";

		return $join;
	}

	/**
	 * [DOC-132] Replace the search WHERE clause with the 3-field OR.
	 *
	 * The whole typed phrase is matched with one LIKE per field,
	 * REPLACING core's per-word clause building. Deliberate: core
	 * splits "die cast" into two terms that must EACH match somewhere,
	 * which interacts confusingly with the meta OR ("die" in title,
	 * "cast" in brand would match). Phrase matching is predictable —
	 * what you type is what gets found — and catalog searches are
	 * short brand/model strings, not prose queries.
	 *
	 * @param string   $search Current search WHERE fragment.
	 * @param WP_Query $query  Running query.
	 * @return string Replacement WHERE fragment.
	 */
	public function extend_search( $search, $query ) {
		$term = (string) $query->get( 'afpf_search' );

		if ( '' === $term ) {
			return $search;
		}

		global $wpdb;

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		return $wpdb->prepare(
			" AND ( ({$wpdb->posts}.post_title LIKE %s) OR ({$wpdb->posts}.post_content LIKE %s) OR (afpf_brand.meta_value LIKE %s) ) ",
			$like,
			$like,
			$like
		);
	}

	/**
	 * [DOC-133] DISTINCT guard against duplicate result rows.
	 *
	 * If a post ever carries two 'brand' meta rows (imports gone wrong,
	 * manual edits), the LEFT JOIN would emit the post twice and the
	 * grid would show doubled cards. DISTINCT costs nothing on result
	 * sets this size and makes that class of data problem invisible to
	 * visitors.
	 *
	 * @param string   $distinct Current DISTINCT clause.
	 * @param WP_Query $query    Running query.
	 * @return string 'DISTINCT' for flagged queries.
	 */
	public function make_distinct( $distinct, $query ) {
		if ( '' === (string) $query->get( 'afpf_search' ) ) {
			return $distinct;
		}

		return 'DISTINCT';
	}
}

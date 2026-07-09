<?php
/**
 * [DOC-134] Filter state <-> URL parameters.
 *
 * The URL is the single source of truth for filter state: filter.js
 * writes every change into the address bar (history.pushState) using
 * the parameter scheme defined here, and this class reads the same
 * scheme back on the server for the initial render. That is what
 * makes results shareable, bookmarkable and back-button-friendly —
 * a URL like ?afpf_brand=maisto,jada&afpf_scale=1-24 fully describes
 * what is on screen, wherever it is opened.
 *
 * Scheme: afpf_search=<text>, afpf_<key>=<slug>,<slug> for each
 * taxonomy key in afpf_get_filter_taxonomies(), afpf_page=<n> (AJAX
 * only — Load More is additive, so page never belongs in the URL).
 * Everything is afpf_-prefixed because bare taxonomy names are
 * registered WordPress query vars: a ?brand=maisto on a page URL
 * would make core replace the page with a taxonomy archive query
 * ([DOC-114]).
 *
 * @package Affiliate_Product_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses and builds the afpf_* URL parameter scheme.
 */
class AFPF_Filter_URL {

	/**
	 * [DOC-135] Parse a raw parameter array into sanitized state.
	 *
	 * Works on any source shaped like a query-string array — $_GET for
	 * page loads, $_POST for AJAX — so the plugin has exactly ONE
	 * sanitization boundary; there is no second parser to fall out of
	 * sync with. Everything is coerced, not trusted: search through
	 * sanitize_text_field, term lists split, slug-sanitized, deduped
	 * and capped at 20 per taxonomy (no legitimate visitor checks 20
	 * boxes in one group; a crafted request gets clipped, not
	 * honoured), page forced to a positive int.
	 *
	 * Locked shortcode pre-filters ride along as afpf_locked_taxonomy /
	 * afpf_locked_term on AJAX requests; they are re-validated against
	 * real taxonomies in the query builder ([DOC-119]).
	 *
	 * @param array $source Raw request parameters (already unslashed).
	 * @return array {
	 *     @type string $search Search phrase ('' when none).
	 *     @type int    $page   1-based results page.
	 *     @type array  $tax    taxonomy => [slugs].
	 *     @type array  $locked taxonomy => [slugs].
	 * }
	 */
	public static function parse( $source ) {
		$state = array(
			'search' => '',
			'page'   => 1,
			'tax'    => array(),
			'locked' => array(),
		);

		if ( ! is_array( $source ) ) {
			return $state;
		}

		if ( isset( $source['afpf_search'] ) ) {
			$state['search'] = sanitize_text_field( (string) $source['afpf_search'] );
		}

		if ( isset( $source['afpf_page'] ) ) {
			$state['page'] = max( 1, absint( $source['afpf_page'] ) );
		}

		foreach ( afpf_get_filter_taxonomies() as $key => $taxonomy ) {
			$param = 'afpf_' . $key;
			if ( empty( $source[ $param ] ) ) {
				continue;
			}

			$slugs = array();
			foreach ( explode( ',', (string) $source[ $param ] ) as $slug ) {
				$slug = sanitize_title( $slug );
				if ( '' !== $slug ) {
					$slugs[] = $slug;
				}
			}

			$slugs = array_slice( array_unique( $slugs ), 0, 20 );
			if ( ! empty( $slugs ) ) {
				$state['tax'][ $taxonomy ] = $slugs;
			}
		}

		$locked_taxonomy = isset( $source['afpf_locked_taxonomy'] ) ? sanitize_key( $source['afpf_locked_taxonomy'] ) : '';
		$locked_term     = isset( $source['afpf_locked_term'] ) ? sanitize_title( $source['afpf_locked_term'] ) : '';
		if ( '' !== $locked_taxonomy && '' !== $locked_term ) {
			$state['locked'][ $locked_taxonomy ] = array( $locked_term );
		}

		return $state;
	}

	/**
	 * [DOC-136] The current request's filter state, from $_GET.
	 *
	 * Used by the shortcode's initial render so shared URLs come up
	 * pre-filtered. Read-only display state — no action is taken on
	 * behalf of the user — hence no nonce on the GET side (nonces
	 * would also break shareability, which is the entire point of
	 * URL state).
	 *
	 * @return array Sanitized state (see parse()).
	 */
	public static function current_state() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only display state; sanitized field-by-field in parse(); a nonce would break shareable URLs.
		return self::parse( wp_unslash( $_GET ) );
	}

	/**
	 * [DOC-137] Build a shareable URL for a given state.
	 *
	 * The PHP mirror of what filter.js writes with pushState — used
	 * server-side for fallback links (e.g. the Clear All href, which
	 * is this with an empty state). Empty values are omitted entirely
	 * so the "nothing selected" URL is the clean page permalink, not
	 * a trail of empty parameters.
	 *
	 * @param array  $state Sanitized state (see parse()).
	 * @param string $base  Base URL; defaults to the current permalink.
	 * @return string URL with afpf_* parameters.
	 */
	public static function build_url( $state, $base = '' ) {
		if ( '' === $base ) {
			$base = get_permalink();
		}

		$params = array();

		if ( '' !== ( $state['search'] ?? '' ) ) {
			$params['afpf_search'] = $state['search'];
		}

		foreach ( afpf_get_filter_taxonomies() as $key => $taxonomy ) {
			if ( ! empty( $state['tax'][ $taxonomy ] ) ) {
				$params[ 'afpf_' . $key ] = implode( ',', $state['tax'][ $taxonomy ] );
			}
		}

		return empty( $params ) ? $base : add_query_arg( array_map( 'rawurlencode', $params ), $base );
	}
}

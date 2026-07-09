<?php
/**
 * [DOC-123] AJAX endpoint for filter requests.
 *
 * The front-end JS POSTs the current filter state here; the response carries
 * rendered product-card HTML plus the numbers the UI needs (found
 * count, has-more flag). Rendering the cards SERVER-side and shipping
 * HTML — rather than shipping JSON data for the client to template —
 * is deliberate: the product card markup lives in one PHP template
 * used by both the initial page render and every AJAX update, so the
 * two can never drift apart and theme overrides ([DOC-115]) apply to
 * both automatically.
 *
 * @package Affiliate_Product_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and serves the afpf_filter AJAX action.
 */
class AFPF_Filter_Ajax {

	/**
	 * [DOC-124] Register both logged-in and visitor endpoints.
	 *
	 * The wp_ajax_nopriv_ hook is essential here (unlike the importer, which
	 * deliberately has none): filtering is a public catalog feature
	 * and almost every user is a logged-out visitor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_afpf_filter', array( $this, 'handle_filter' ) );
		add_action( 'wp_ajax_nopriv_afpf_filter', array( $this, 'handle_filter' ) );
	}

	/**
	 * [DOC-125] Serve one filter request.
	 *
	 * Flow: nonce check → sanitize state through the SAME parser the
	 * initial page render uses (AFPF_Filter_URL::parse — one
	 * sanitization boundary, not two that must agree) → run the query
	 * → render cards → JSON out.
	 *
	 * The nonce here is CSRF protection-in-depth rather than a
	 * capability gate (the endpoint reads public data), matching the
	 * spec that all AJAX verifies a nonce. Note for future caching
	 * work: if LiteSpeed page-caches a shortcode page beyond nonce
	 * lifetime (24h), stale nonces would start failing here — the
	 * filter page should be excluded from full-page cache or given a
	 * TTL under 12h.
	 *
	 * Locked terms from the shortcode travel with each request as
	 * their own params; they're validated against real taxonomies in
	 * the query builder ([DOC-119]), so a tampered payload can at
	 * worst filter by a different public term — the same thing the
	 * checkboxes do.
	 */
	public function handle_filter() {
		check_ajax_referer( 'afpf_filter', 'nonce' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_POST is sanitized field-by-field inside AFPF_Filter_URL::parse().
		$state = AFPF_Filter_URL::parse( wp_unslash( $_POST ) );

		$per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 24;
		$per_page = min( 48, max( 1, $per_page ) );

		$query = AFPF_Filter_Query::build( $state, $per_page );

		$html = '';
		if ( $query->have_posts() ) {
			ob_start();
			while ( $query->have_posts() ) {
				$query->the_post();
				afpf_get_template( 'product-card.php' );
			}
			$html = ob_get_clean();
			wp_reset_postdata();
		}

		$found   = (int) $query->found_posts;
		$showing = min( $found, $state['page'] * $per_page );

		wp_send_json_success(
			array(
				'html'     => $html,
				'found'    => $found,
				'showing'  => $showing,
				'total'    => AFPF_Filter_Query::get_total_products(),
				'has_more' => $state['page'] < (int) $query->max_num_pages,
			)
		);
	}
}

<?php
/**
 * [DOC-172] Static page template — always full width.
 *
 * Overrides Astra's page.php with the sidebar plumbing removed:
 * static pages on these sites are catalogs, comparisons and landing
 * pages, and every one of them wants the full content width (the
 * /catalog/ filter page literally has its own sidebar). The filter
 * below forces Astra's layout system to agree, so the Customizer's
 * global sidebar setting can never squeeze page content into a
 * with-sidebar column width — belt and braces with the missing
 * get_sidebar() call.
 *
 * Astra's own loop (astra_content_page_loop) is retained rather than
 * a hand-rolled while-loop: it fires the entry hooks that Astra
 * addons and schema markup rely on, and renders the_content() inside
 * Astra's expected containers.
 *
 * @package Affiliate_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'astra_page_layout', 'affiliate_master_force_page_fullwidth' );

/**
 * Force the no-sidebar layout while this template renders.
 *
 * @return string Layout slug Astra understands.
 */
function affiliate_master_force_page_fullwidth() {
	return 'no-sidebar';
}

get_header(); ?>

	<div id="primary" <?php astra_primary_class(); ?>>

		<?php astra_primary_content_top(); ?>

		<?php astra_content_page_loop(); ?>

		<?php astra_primary_content_bottom(); ?>

	</div><!-- #primary -->

<?php get_footer(); ?>

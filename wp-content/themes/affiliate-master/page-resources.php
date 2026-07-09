<?php
/**
 * Template Name: Resources Page
 *
 * [DOC-178] Resources page template.
 *
 * A thin full-width wrapper on purpose: the actual resource cards live
 * in the PAGE CONTENT as plain markup using the .am-resource-cards /
 * .am-resource-card classes (styled at [DOC-179]), not hardcoded in
 * this template. Resources are exactly the content that differs on
 * every clone (different disclosure wording, different recommended
 * tools, different affiliate links) — an editor must be able to add a
 * card from the WordPress admin without touching PHP. The template
 * supplies the container and the title; the content supplies the
 * cards. The seeded page created alongside this template shows the
 * card markup pattern to copy.
 *
 * @package Affiliate_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'affiliate_master_force_page_fullwidth' ) ) {
	/**
	 * Force the no-sidebar layout while this template renders.
	 *
	 * @return string Layout slug Astra understands.
	 */
	function affiliate_master_force_page_fullwidth() {
		return 'no-sidebar';
	}
}
add_filter( 'astra_page_layout', 'affiliate_master_force_page_fullwidth' );

get_header(); ?>

	<div id="primary" <?php astra_primary_class(); ?>>

		<?php astra_primary_content_top(); ?>

		<?php
		while ( have_posts() ) :
			the_post();
			?>

			<article id="post-<?php the_ID(); ?>" <?php post_class( 'am-page am-page--resources' ); ?>>
				<div class="am-page__container">

					<header class="am-page__header">
						<h1 class="am-page__title"><?php the_title(); ?></h1>
					</header>

					<div class="am-prose">
						<?php the_content(); ?>
					</div>

				</div>
			</article>

			<?php
		endwhile;
		?>

		<?php astra_primary_content_bottom(); ?>

	</div><!-- #primary -->

<?php get_footer(); ?>

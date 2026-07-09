<?php
/**
 * Template Name: About Page
 *
 * [DOC-176] About page template.
 *
 * A 900px reading column — wider than the 820px blog article because
 * about pages mix prose with future imagery/timeline blocks, but still
 * narrow enough to keep line lengths readable. Typography comes from
 * the shared .am-prose component ([DOC-179]) so this page, Contact and
 * Resources all read like the blog without duplicating rules.
 *
 * The layout filter + hand-rolled loop (instead of page.php's
 * astra_content_page_loop) is what buys the custom container width:
 * Astra's loop renders inside Astra's container, whose width belongs
 * to the Customizer. The function_exists guard matters because only
 * ONE template file loads per request — this template can never lean
 * on the copy of the function defined in page.php ([DOC-172]).
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

			<article id="post-<?php the_ID(); ?>" <?php post_class( 'am-page am-page--about' ); ?>>
				<div class="am-page__container am-page__container--about">

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

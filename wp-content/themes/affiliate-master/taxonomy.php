<?php
/**
 * [DOC-195] Taxonomy archive template — product grid for all eight
 * product vocabularies.
 *
 * Without this file, term archives (/product-type/trucks/,
 * /brand/motormax/, /scale/1-18/, ...) fell through to Astra's
 * generic archive: blog-style cards with dates and excerpts — dates
 * on PRODUCTS — instead of the catalog grid.
 *
 * One file covers every product taxonomy because of where
 * taxonomy.php sits in the hierarchy: custom taxonomies resolve
 * taxonomy-{tax}-{term}.php -> taxonomy-{tax}.php -> taxonomy.php,
 * while blog categories and tags resolve through category.php /
 * tag.php and never reach here. The is-it-a-product-taxonomy guard
 * below exists for the day a clone registers a custom taxonomy on
 * some OTHER post type — those fall back to Astra's own loop instead
 * of showing an empty product grid.
 *
 * The grid itself is the filter plugin's shortcode locked to the
 * current term ([DOC-128]): visitors can search and stack MORE
 * filters on top, but cannot remove the term they navigated to —
 * and there is exactly one grid implementation to maintain.
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

get_header();

$am_term         = get_queried_object();
$am_taxonomy     = ( $am_term instanceof WP_Term ) ? get_taxonomy( $am_term->taxonomy ) : null;
$am_products_tax = $am_taxonomy && in_array( 'affiliate_product', (array) $am_taxonomy->object_type, true );
?>

	<div id="primary" <?php astra_primary_class(); ?>>

		<?php astra_primary_content_top(); ?>

		<?php if ( $am_products_tax ) : ?>

			<section class="am-band am-tax-header">
				<div class="am-container">
					<nav class="am-tax-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'affiliate-master' ); ?>">
						<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'affiliate-master' ); ?></a>
						<span aria-hidden="true">&rarr;</span>
						<span><?php echo esc_html( $am_taxonomy->labels->singular_name ); ?></span>
						<span aria-hidden="true">&rarr;</span>
						<span><?php echo esc_html( $am_term->name ); ?></span>
					</nav>

					<h1 class="am-tax-title"><?php echo esc_html( $am_term->name ); ?></h1>

					<p class="am-tax-count">
						<?php
						printf(
							/*
							 * [CBD-08] Niche-neutral wording — the old string
							 * said "die cast models", a die-cast-era leftover
							 * this instance must not inherit.
							 */
							/* translators: 1: product count, 2: term name. */
							esc_html__( 'Browsing %1$s products in %2$s', 'affiliate-master' ),
							esc_html( number_format_i18n( (int) $am_term->count ) ),
							esc_html( $am_term->name )
						);
						?>
					</p>

					<?php if ( term_description() ) : ?>
						<div class="am-tax-desc">
							<?php echo wp_kses_post( term_description() ); ?>
						</div>
					<?php endif; ?>
				</div>
			</section>

			<div class="am-tax-products am-container">
				<?php
				// Plugin-built markup (grid, filters, buy buttons);
				// escaping would destroy it. Term lock per [DOC-128].
				echo do_shortcode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					sprintf(
						'[affiliate_filter taxonomy="%s" term="%s" per_page="40" columns="4"]',
						esc_attr( $am_term->taxonomy ),
						esc_attr( $am_term->slug )
					)
				);
				?>
			</div>

		<?php else : ?>

			<?php
			// Non-product custom taxonomy: Astra's archive loop renders
			// whatever post type this term belongs to.
			if ( function_exists( 'astra_content_loop' ) ) {
				astra_content_loop();
			} else {
				while ( have_posts() ) {
					the_post();
					the_title( '<h2 class="entry-title"><a href="' . esc_url( get_permalink() ) . '">', '</a></h2>' );
				}
			}
			?>

		<?php endif; ?>

		<?php astra_primary_content_bottom(); ?>

	</div><!-- #primary -->

<?php get_footer(); ?>

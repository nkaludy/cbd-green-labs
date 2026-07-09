<?php
/**
 * [DOC-139] Product results grid template.
 *
 * The right-hand results area: grid of product cards, Load More
 * button, loading spinner and the empty state. filter.js only ever
 * replaces/appends INSIDE the [data-afpf-grid] element, so the
 * surrounding controls survive every AJAX update. Theme-overridable
 * per [DOC-115].
 *
 * Received variables:
 *
 * @var WP_Query $query    The executed filter query.
 * @var int      $columns  Desktop column count (1-5).
 * @var bool     $has_more Whether more pages exist.
 *
 * @package Affiliate_Product_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="afpf-results" data-afpf-results>

	<?php
	/*
	 * The column override travels as a CSS custom property consumed
	 * only by the desktop media query, so shortcode columns="2" can
	 * never break the tablet/mobile breakpoints ([DOC-141]).
	 */
	?>
	<div class="afpf-grid" data-afpf-grid style="--afpf-cols-desktop: <?php echo esc_attr( $columns ); ?>;">
		<?php
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				afpf_get_template( 'product-card.php' );
			}
			wp_reset_postdata();
		}
		?>
	</div>

	<p class="afpf-no-results" data-afpf-empty <?php echo $query->have_posts() ? 'hidden' : ''; ?>>
		<?php esc_html_e( 'No products found. Try removing a filter or changing your search.', 'affiliate-product-filter' ); ?>
	</p>

	<div class="afpf-spinner" data-afpf-spinner hidden aria-hidden="true"></div>

	<div class="afpf-load-wrap">
		<button type="button" class="afpf-load-more" data-afpf-load-more <?php echo $has_more ? '' : 'hidden'; ?>>
			<?php esc_html_e( 'Load More', 'affiliate-product-filter' ); ?>
		</button>
	</div>
</section>

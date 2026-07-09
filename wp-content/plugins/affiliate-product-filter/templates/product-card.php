<?php
/**
 * [DOC-140] Single product card template.
 *
 * One card in the results grid, rendered inside the loop (global
 * $post is set). This exact template serves the initial page render
 * AND every AJAX batch ([DOC-123]), so restyling a card — usually by
 * copying this file into the child theme ([DOC-115]) — automatically
 * applies everywhere.
 *
 * @package Affiliate_Product_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$afpf_post_id = get_the_ID();

/*
 * Image: the main_image ACF field first (the importer stores the
 * sideloaded local URL there — [DOC-046]), falling back to the
 * featured image for hand-created products. get_post_meta fallback
 * keeps the card rendering even with ACF deactivated, mirroring the
 * importer's degradation strategy.
 */
$afpf_image = function_exists( 'get_field' ) ? get_field( 'main_image', $afpf_post_id ) : get_post_meta( $afpf_post_id, 'main_image', true );
if ( empty( $afpf_image ) ) {
	$afpf_image = get_the_post_thumbnail_url( $afpf_post_id, 'medium_large' );
}

$afpf_price = function_exists( 'get_field' ) ? get_field( 'price', $afpf_post_id ) : get_post_meta( $afpf_post_id, 'price', true );
$afpf_url   = function_exists( 'get_field' ) ? get_field( 'affiliate_url', $afpf_post_id ) : get_post_meta( $afpf_post_id, 'affiliate_url', true );

/*
 * Badges come from the TAXONOMIES (not the ACF text fields): terms are
 * the normalized, deduplicated vocabulary, so badges always match the
 * filter checkboxes character-for-character.
 */
$afpf_brand_terms = get_the_terms( $afpf_post_id, 'brand' );
$afpf_scale_terms = get_the_terms( $afpf_post_id, 'scale' );
$afpf_brand       = ( $afpf_brand_terms && ! is_wp_error( $afpf_brand_terms ) ) ? $afpf_brand_terms[0]->name : '';
$afpf_scale       = ( $afpf_scale_terms && ! is_wp_error( $afpf_scale_terms ) ) ? $afpf_scale_terms[0]->name : '';
?>
<article class="afpf-card">
	<a class="afpf-card-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
		<?php if ( $afpf_image ) : ?>
			<img src="<?php echo esc_url( $afpf_image ); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy" />
		<?php else : ?>
			<?php
			/*
			 * [DOC-197] Image-less products get a branded "image not
			 * available" card instead of the old empty grey span: feeds
			 * ship placeholder/dead image URLs routinely, and a blank
			 * box reads as a broken site where branded art reads as an
			 * honest gap. Same markup shape as the real image above so
			 * the .afpf-card-media styling applies unchanged. Plugin-
			 * relative URL so every clone serves its own copy.
			 */
			?>
			<img src="<?php echo esc_url( AFPF_PLUGIN_URL . 'assets/img/image-not-available.jpg' ); ?>"
				alt="<?php esc_attr_e( 'Product image not available', 'affiliate-product-filter' ); ?>"
				loading="lazy" />
		<?php endif; ?>

		<?php if ( $afpf_brand ) : ?>
			<span class="afpf-badge-brand"><?php echo esc_html( $afpf_brand ); ?></span>
		<?php endif; ?>
		<?php if ( $afpf_scale ) : ?>
			<span class="afpf-badge-scale"><?php echo esc_html( $afpf_scale ); ?></span>
		<?php endif; ?>
	</a>

	<div class="afpf-card-body">
		<h3 class="afpf-card-title">
			<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
		</h3>

		<?php
		/*
		 * [DOC-194] Price renders as "$48.95", not the raw "48.95" the
		 * feed ships — a bare number under a product title doesn't read
		 * as a price. Zero prices are suppressed entirely: "0.00" is a
		 * feed gap, not a free product, and printing it looks broken.
		 * The display string is filterable for non-USD clones
		 * (afpf_price_display receives the raw meta as context).
		 */
		$afpf_price_number = (float) preg_replace( '/[^0-9.]/', '', (string) $afpf_price );
		if ( $afpf_price_number > 0 ) :
			$afpf_price_text = apply_filters(
				'afpf_price_display',
				'$' . number_format_i18n( $afpf_price_number, 2 ),
				$afpf_price
			);
			?>
			<p class="afpf-card-price"><?php echo esc_html( $afpf_price_text ); ?></p>
		<?php endif; ?>

		<div class="afpf-card-actions">
			<a class="afpf-btn afpf-btn-view" href="<?php the_permalink(); ?>">
				<?php esc_html_e( 'View', 'affiliate-product-filter' ); ?>
			</a>
			<?php if ( $afpf_url ) : ?>
				<?php
				/*
				 * rel: nofollow (affiliate link — no PageRank), noopener
				 * (new-tab security), sponsored (Google's requested
				 * attribute for paid/affiliate links).
				 */
				?>
				<a class="afpf-btn afpf-btn-buy" href="<?php echo esc_url( $afpf_url ); ?>"
					target="_blank" rel="nofollow noopener sponsored">
					<?php esc_html_e( 'Buy Now', 'affiliate-product-filter' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
</article>

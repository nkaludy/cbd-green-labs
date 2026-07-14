<?php
/**
 * [DOC-009] Single template for the "affiliate_product" post type.
 *
 * WordPress's template hierarchy looks for a file named
 * "single-{post_type}.php" before falling back to single.php or
 * Astra's own single.php. Naming this file single-affiliate_product.php
 * is what makes WordPress automatically use it for every individual
 * product URL (e.g. /products/some-product/) without any manual
 * template-selection step.
 *
 * Structurally this mirrors Astra's own single.php (get_header(),
 * sidebar handling via astra_page_layout(), the #primary wrapper with
 * astra_primary_class()) so the product page still inherits Astra's
 * header, footer, spacing, and container width instead of looking
 * like a foreign, unstyled page bolted onto the theme. The only
 * change from Astra's default is swapping astra_content_loop() for
 * our own product markup below.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package Affiliate_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header(); ?>

<?php if ( function_exists( 'astra_page_layout' ) && astra_page_layout() === 'left-sidebar' ) { ?>
	<?php get_sidebar(); ?>
<?php } ?>

	<div id="primary" <?php astra_primary_class(); ?>>

		<?php astra_primary_content_top(); ?>

		<?php
		while ( have_posts() ) :
			the_post();

			/**
			 * [DOC-008] Read every ACF field for this product up front.
			 *
			 * Each field is read once via get_field() into a local
			 * variable rather than sprinkled inline throughout the
			 * markup below. This keeps the HTML section easy to scan
			 * and means every field this template depends on is
			 * visible in one place, matching the field list defined
			 * in inc/acf-fields-affiliate-product.php.
			 */
			$affiliate_url      = get_field( 'affiliate_url' );
			$sku                = get_field( 'sku' );
			$main_image         = get_field( 'main_image' );
			$gallery_images_raw = get_field( 'gallery_images' );
			$brand              = get_field( 'brand' );
			$scale              = get_field( 'scale' );
			$features_raw       = get_field( 'features' );
			$affiliate_network  = get_field( 'affiliate_network' );
			$price              = get_field( 'price' );

			// gallery_images and features are stored as one-item-per-line
			// textareas (see [DOC-008]), so split them into arrays for
			// looping and drop any blank lines from trailing newlines.
			$gallery_images = $gallery_images_raw ? array_filter( array_map( 'trim', explode( "\n", $gallery_images_raw ) ) ) : array();
			$features       = $features_raw ? array_filter( array_map( 'trim', explode( "\n", $features_raw ) ) ) : array();
			?>

			<article id="post-<?php the_ID(); ?>" <?php post_class( 'affiliate-product' ); ?>>

				<header class="affiliate-product__header">
					<h1 class="affiliate-product__title"><?php the_title(); ?></h1>

					<?php if ( $brand || $scale || $sku ) : ?>
						<p class="affiliate-product__meta">
							<?php if ( $brand ) : ?>
								<span class="affiliate-product__brand"><?php echo esc_html( $brand ); ?></span>
							<?php endif; ?>
							<?php if ( $scale ) : ?>
								<span class="affiliate-product__scale"><?php echo esc_html( $scale ); ?></span>
							<?php endif; ?>
							<?php if ( $sku ) : ?>
								<span class="affiliate-product__sku"><?php esc_html_e( 'SKU:', 'affiliate-master' ); ?> <?php echo esc_html( $sku ); ?></span>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</header>

				<div class="affiliate-product__body">

					<div class="affiliate-product__media">
						<?php
						/**
						 * The main_image field is stored as a plain URL
						 * string (see [DOC-008]), not a WP attachment, so
						 * it is rendered directly with an <img> tag rather
						 * than through wp_get_attachment_image(). Fall
						 * back to the post's featured image if no
						 * main_image URL was entered.
						 */
						if ( $main_image ) :
							?>
							<img
								class="affiliate-product__main-image"
								src="<?php echo esc_url( $main_image ); ?>"
								alt="<?php echo esc_attr( get_the_title() ); ?>"
								loading="eager"
							/>
						<?php elseif ( has_post_thumbnail() ) : ?>
							<?php the_post_thumbnail( 'large', array( 'class' => 'affiliate-product__main-image' ) ); ?>
						<?php endif; ?>

						<?php if ( ! empty( $gallery_images ) ) : ?>
							<div class="affiliate-product__gallery">
								<?php foreach ( $gallery_images as $image_url ) : ?>
									<img
										class="affiliate-product__gallery-image"
										src="<?php echo esc_url( $image_url ); ?>"
										alt="<?php echo esc_attr( get_the_title() ); ?>"
										loading="lazy"
									/>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>

					<div class="affiliate-product__details">

						<?php
						/*
						 * [DOC-194] Same price treatment as the filter
						 * plugin's cards: "$" prefix (bare numbers don't
						 * read as prices), zero/gap prices suppressed,
						 * display string filterable for non-USD clones.
						 */
						$price_number = (float) preg_replace( '/[^0-9.]/', '', (string) $price );
						if ( $price_number > 0 ) :
							$price_text = apply_filters(
								'afpf_price_display',
								'$' . number_format_i18n( $price_number, 2 ),
								$price
							);
							?>
							<p class="affiliate-product__price"><?php echo esc_html( $price_text ); ?></p>
						<?php endif; ?>

						<?php
						/**
						 * [DOC-010] The "Buy Now" affiliate call-to-action.
						 *
						 * Only rendered if an affiliate_url was actually
						 * entered — an empty/missing URL would otherwise
						 * produce a dead or self-referencing link.
						 *
						 * The three link attributes here are all
						 * deliberate SEO/compliance choices, not defaults:
						 *
						 *   - target="_blank"
						 *       Opens the merchant site in a new tab so
						 *       clicking "Buy Now" doesn't navigate the
						 *       visitor away from (and lose) our page.
						 *
						 *   - rel="nofollow noopener sponsored"
						 *       - "sponsored": Google's required
						 *         annotation for paid/affiliate/compensated
						 *         links (see Google's link spam guidance).
						 *         Marking affiliate links correctly avoids
						 *         manual-action / spam penalties.
						 *       - "nofollow": belt-and-suspenders alongside
						 *         "sponsored" telling search engines not to
						 *         pass PageRank/link equity to the
						 *         merchant's site.
						 *       - "noopener": security best practice for
						 *         any target="_blank" link — prevents the
						 *         new tab from getting a reference back to
						 *         `window.opener`, which would otherwise
						 *         let the destination page redirect/tamper
						 *         with our original tab (reverse
						 *         tabnabbing).
						 *
						 * esc_url() escapes the field value for safe
						 * output in an href attribute, guarding against a
						 * malformed/malicious URL being saved in the
						 * field.
						 */
						if ( $affiliate_url ) :
							?>
							<a
								class="affiliate-product__buy-now"
								href="<?php echo esc_url( $affiliate_url ); ?>"
								target="_blank"
								rel="nofollow noopener sponsored"
							>
								<?php esc_html_e( 'Buy Now', 'affiliate-master' ); ?>
							</a>

							<?php if ( $affiliate_network ) : ?>
								<p class="affiliate-product__disclosure">
									<?php
									printf(
										/* translators: %s: affiliate network name, e.g. "Amazon Associates". */
										esc_html__( 'As an affiliate partner of %s, we may earn a commission from qualifying purchases made through this link.', 'affiliate-master' ),
										esc_html( $affiliate_network )
									);
									?>
								</p>
							<?php endif; ?>
						<?php endif; ?>

						<?php if ( ! empty( $features ) ) : ?>
							<div class="affiliate-product__features">
								<h2><?php esc_html_e( 'Features', 'affiliate-master' ); ?></h2>
								<ul>
									<?php foreach ( $features as $feature ) : ?>
										<li><?php echo esc_html( $feature ); ?></li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>

						<?php if ( get_the_content() ) : ?>
							<div class="affiliate-product__description">
								<?php the_content(); ?>
							</div>
						<?php endif; ?>

					</div><!-- .affiliate-product__details -->

				</div><!-- .affiliate-product__body -->

			</article>

			<?php
			/*
			 * [CBD-14] Bottom block — related products with the FULL
			 * filter component.
			 *
			 * One term-locked [affiliate_filter] with show_filters ON:
			 * that single switch brings the plugin's standard
			 * .afpf-topbar (pill search, live count, mobile Filters
			 * button + drawer) and the desktop facet sidebar — the
			 * identical interactive component the catalog and archive
			 * pages render, no rebuilt markup. The former "keep
			 * shopping" section (custom search + category pill row) is
			 * gone; the topbar replaces it.
			 *
			 * The current product is excluded from the FIRST paint via
			 * a render-scoped afpf_query_args hook ([DOC-201]-style);
			 * AJAX refetches (facet clicks, Load More) may bring it
			 * back — harmless, and the alternative is a plugin change.
			 * Hidden below two siblings, as before.
			 */
			$am_type_terms = get_the_terms( get_the_ID(), 'product-type' );
			$am_type_term  = ( $am_type_terms && ! is_wp_error( $am_type_terms ) ) ? $am_type_terms[0] : null;

			if ( $am_type_term && ( (int) $am_type_term->count - 1 ) >= 2 ) :
				$am_type_label = wp_specialchars_decode( $am_type_term->name, ENT_QUOTES );
				$am_type_link  = get_term_link( $am_type_term );
				?>
				<section class="am-product-related">
					<div class="am-section-head">
						<h2>
							<?php
							/* translators: %s: product-type term name. */
							echo esc_html( sprintf( __( 'More %s Products', 'affiliate-master' ), $am_type_label ) );
							?>
						</h2>
						<?php if ( ! is_wp_error( $am_type_link ) ) : ?>
							<a class="am-link-more" href="<?php echo esc_url( $am_type_link ); ?>"><?php esc_html_e( 'View all →', 'affiliate-master' ); ?></a>
						<?php endif; ?>
					</div>
					<?php
					$am_exclude_current = static function ( $args ) {
						$args['post__not_in'] = array( get_the_ID() );
						return $args;
					};
					add_filter( 'afpf_query_args', $am_exclude_current );

					// Plugin-built markup (cards, prices, buy buttons);
					// escaping would destroy it.
					echo do_shortcode(
						sprintf(
							'[affiliate_filter taxonomy="product-type" term="%s" per_page="8" columns="4" show_filters="true"]',
							esc_attr( $am_type_term->slug )
						)
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

					remove_filter( 'afpf_query_args', $am_exclude_current );
					?>
				</section>
			<?php endif; ?>


			<?php
		endwhile;
		?>

		<?php astra_primary_content_bottom(); ?>

	</div><!-- #primary -->

<?php if ( function_exists( 'astra_page_layout' ) && astra_page_layout() === 'right-sidebar' ) { ?>
	<?php get_sidebar(); ?>
<?php } ?>

<?php get_footer(); ?>

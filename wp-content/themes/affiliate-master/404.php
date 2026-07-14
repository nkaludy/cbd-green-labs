<?php
/**
 * [CBD-18] Smart 404: recover the visitor, keep the 404 status.
 *
 * Old-site product URLs still rank and get clicked; a bare "not
 * found" wastes that visit. This template parses the requested slug
 * into product search terms, finds the closest matching products
 * (progressively broadening until something matches), and renders
 * them in the standard card grid with a pre-filled search bar and
 * the category boxes as a fallback path.
 *
 * CRITICAL: WordPress has already sent the real HTTP 404 by the time
 * this template runs, and nothing here touches the status — Google
 * must keep seeing 404 (a 200 here would be a penalized soft-404).
 * We change what the PAGE SHOWS, never what the SERVER SAYS.
 *
 * @package Affiliate_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turn the requested path into product search terms.
 *
 * Last path segment only (prefixes like /product/ or
 * /product-category/ carry no signal), URL-decoded, junk bytes from
 * mangled encodings stripped, hyphens to spaces, stopwords and bare
 * measurements dropped: the leftovers are what the visitor was
 * actually looking for.
 *
 * @param string $request_path Raw request path.
 * @return string Space-separated search terms ('' if nothing usable).
 */
function affiliate_master_404_terms( $request_path ) {
	$path = rawurldecode( (string) $request_path );

	$segments = array_values( array_filter( explode( '/', (string) wp_parse_url( $path, PHP_URL_PATH ) ) ) );
	if ( ! $segments ) {
		return '';
	}
	$slug = end( $segments );

	// Mangled-encoding junk (%C2%A2 and friends decode to non-ASCII):
	// keep only letters, digits, hyphens.
	$slug = preg_replace( '/[^a-z0-9-]+/i', '', str_replace( array( '-', '_' ), '-', strtolower( $slug ) ) );

	$stop = array( 'cbd', 'hemp', 'infused', 'bundle', 'buy', 'best', 'the', 'and', 'for', 'sale', 'online', 'product', 'products', 'mg', 'ml', 'oz', 'count' );

	$words = array();
	foreach ( explode( '-', $slug ) as $word ) {
		if ( '' === $word || in_array( $word, $stop, true ) ) {
			continue;
		}
		// Pure numbers and bare measurements (500mg, 30ml, 4oz, 30ct).
		if ( preg_match( '/^\d+(mg|ml|oz|ct|g)?$/', $word ) ) {
			continue;
		}
		$words[] = $word;
	}

	return implode( ' ', $words );
}

/**
 * Find matching products, broadening the terms until something hits.
 *
 * Full terms first; no results, drop words from the RIGHT and retry
 * (leading words in product slugs are usually brand/line, the
 * strongest signal). Empty terms fall through to the newest products
 * so the page is never a dead end.
 *
 * @param string $terms Space-separated search terms.
 * @return array{posts: int[], terms: string, fallback: bool}
 */
function affiliate_master_404_products( $terms ) {
	$words = array_values( array_filter( explode( ' ', $terms ) ) );

	while ( $words ) {
		$try   = implode( ' ', $words );
		$found = get_posts(
			array(
				'post_type'      => 'affiliate_product',
				'post_status'    => 'publish',
				's'              => $try,
				'posts_per_page' => 8,
				'fields'         => 'ids',
			)
		);
		if ( $found ) {
			return array(
				'posts'    => $found,
				'terms'    => $try,
				'fallback' => false,
			);
		}
		array_pop( $words );
	}

	return array(
		'posts'    => get_posts(
			array(
				'post_type'      => 'affiliate_product',
				'post_status'    => 'publish',
				'posts_per_page' => 8,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		),
		'terms'    => '',
		'fallback' => true,
	);
}

/*
 * The card grid below uses the plugin's template directly (no
 * shortcode), which skips the shortcode's own [DOC-127] safety-net
 * enqueue. The style must be enqueued ON the wp_enqueue_scripts hook
 * (priority 20, after the plugin registers at 10) — this template
 * runs before get_header() fires wp_head, so a bare wp_style_is()
 * check here would look before registration exists and silently
 * skip, shipping unstyled cards.
 */
add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( wp_style_is( 'afpf-filter', 'registered' ) ) {
			wp_enqueue_style( 'afpf-filter' );
		}
	},
	20
);

get_header();

$am_requested = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display context on a 404.
$am_terms     = affiliate_master_404_terms( $am_requested );
$am_match     = affiliate_master_404_products( $am_terms );
$am_search    = '' !== $am_match['terms'] ? $am_match['terms'] : $am_terms;
?>

	<div id="primary" <?php astra_primary_class(); ?>>

		<?php astra_primary_content_top(); ?>

		<main class="am-404">

			<section class="am-404__head">
				<p class="am-eyebrow"><?php esc_html_e( '404 · Not found', 'affiliate-master' ); ?></p>
				<h1 class="am-404__title">
					<?php
					echo esc_html(
						$am_match['fallback']
							? __( 'That page is no longer available.', 'affiliate-master' )
							: __( 'That product is no longer available — here’s what we found instead.', 'affiliate-master' )
					);
					?>
				</h1>
			</section>

			<?php /* Standard product search, pre-filled with the parsed terms ([CBD-10] pattern: the plugin's own param, products only). */ ?>
			<form class="am-home-search" role="search" method="get" action="<?php echo esc_url( home_url( '/catalog/' ) ); ?>">
				<svg class="am-home-search__icon" aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
					<circle cx="11" cy="11" r="7"></circle>
					<line x1="21" y1="21" x2="16.5" y2="16.5"></line>
				</svg>
				<label class="screen-reader-text" for="am-404-search-input"><?php esc_html_e( 'Search products', 'affiliate-master' ); ?></label>
				<input
					type="search"
					id="am-404-search-input"
					name="afpf_search"
					value="<?php echo esc_attr( $am_search ); ?>"
					placeholder="<?php esc_attr_e( 'Search CBD products…', 'affiliate-master' ); ?>"
				/>
				<button type="submit" class="am-home-search__btn"><?php esc_html_e( 'Search', 'affiliate-master' ); ?></button>
			</form>

			<?php if ( ! empty( $am_match['posts'] ) ) : ?>
				<section class="am-404__products">
					<?php if ( $am_match['fallback'] ) : ?>
						<h2 class="am-404__subtitle"><?php esc_html_e( 'Recently added products', 'affiliate-master' ); ?></h2>
					<?php endif; ?>
					<?php
					/*
					 * The plugin's own card template inside its own grid
					 * wrapper classes, so the cards are pixel-identical to
					 * every other listing (and restyled for free when the
					 * skin changes). Static markup only — no topbar, no
					 * AJAX — a 404 is a landing surface, not a browser.
					 */
					?>
					<div class="afpf-wrap afpf-wrap--static">
						<div class="afpf-layout">
							<div class="afpf-results">
								<div class="afpf-grid" style="--afpf-cols-desktop: 4;">
									<?php
									global $post;
									foreach ( $am_match['posts'] as $am_pid ) :
										$post = get_post( $am_pid ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standard loop emulation, reset below.
										setup_postdata( $post );
										afpf_get_template( 'product-card.php' );
									endforeach;
									wp_reset_postdata();
									?>
								</div>
							</div>
						</div>
					</div>
				</section>
			<?php endif; ?>

			<?php
			// Category boxes as the browse-from-scratch fallback — the
			// same component the homepage renders ([CBD-13]/[CBD-16]).
			$am_categories = affiliate_master_get_product_type_categories( array( 'other' ) );
			$am_icon_map   = apply_filters( 'affiliate_master_category_icons', array() );
			if ( ! empty( $am_categories ) ) :
				?>
				<section class="am-404__cats">
					<h2 class="am-404__subtitle"><?php esc_html_e( 'Or browse by category', 'affiliate-master' ); ?></h2>
					<div class="am-cats am-cats--compact">
						<?php
						foreach ( $am_categories as $am_category ) :
							$am_icon_key = isset( $am_category['slug'], $am_icon_map[ $am_category['slug'] ] ) ? $am_icon_map[ $am_category['slug'] ] : 'other';
							$am_icon_svg = affiliate_master_inline_icon( $am_icon_key );
							if ( '' === $am_icon_svg ) {
								$am_icon_svg = affiliate_master_inline_icon( 'other' );
							}
							?>
							<a class="am-cat am-cat--sm" href="<?php echo esc_url( $am_category['url'] ); ?>">
								<?php if ( $am_icon_svg ) : ?>
									<span class="am-cat__icon" aria-hidden="true">
										<?php echo $am_icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses-sanitized in affiliate_master_inline_icon(). ?>
									</span>
								<?php endif; ?>
								<span class="am-cat__name"><?php echo esc_html( $am_category['label'] ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

		</main>

		<?php astra_primary_content_bottom(); ?>

	</div><!-- #primary -->

<?php get_footer(); ?>

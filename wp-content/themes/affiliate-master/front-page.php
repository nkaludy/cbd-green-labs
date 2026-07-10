<?php
/**
 * [DOC-181 / CBD-01] Homepage template — CBD Green Labs instance.
 *
 * Rebuilt from the master template's seven-band homepage to the CBD
 * mockup's four sections: hero panel, category cards, quote band,
 * featured products (plus the auto-hidden guides section, which only
 * renders once blog posts exist). The die-cast search hero, scale
 * pills, trust icons and CTA banner were removed — the CBD design's
 * page-shell aesthetic uses inset rounded panels, not full-bleed
 * bands, and the footer already provides the closing dark panel.
 *
 * Sections are inset panels inside the page shell (styled in the
 * CBD skin layer of style.css); the .am-band full-bleed breakout is
 * neutralized there, so the class is retained on sections only for
 * selector continuity.
 *
 * Niche DATA still flows through the affiliate_master_home_* filters
 * (category entries now carry num/label/desc/url — CBD values are
 * supplied from functions.php). Copy marked "TODO: final copy" is
 * placeholder curator voice — this site reviews and recommends
 * third-party products; it does not grow, make, or ship anything
 * (see the implementation brief §6).
 *
 * @package Affiliate_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header(); ?>

	<div id="primary" <?php astra_primary_class(); ?>>

		<?php astra_primary_content_top(); ?>

		<main class="am-home">

			<?php
			/*
			---------------------------------------------------------
			 * Section 1 — Hero: sage panel, copy left / image right.
			 *
			 * Search moved out of the hero (the catalog owns search);
			 * the two CTAs are the mockup's pair. The hero image is
			 * the mockup's diagonal-hatch placeholder until a real
			 * lifestyle shot exists.
			 * -------------------------------------------------------
			 */
			?>
			<section class="am-band am-hero">
				<div class="am-hero__copy">
					<div class="am-eyebrow"><?php esc_html_e( 'Curated CBD · Independently reviewed', 'affiliate-master' ); ?></div>
					<h1 class="am-hero__title">
						<?php
						/* TODO: final copy — placeholder headline per the brief. */
						echo wp_kses(
							sprintf(
								/* translators: %s: line break tag. */
								__( 'Calm,%scultivated.', 'affiliate-master' ),
								'<br>'
							),
							array( 'br' => array() )
						);
						?>
					</h1>
					<p class="am-hero__sub">
						<?php
						/* TODO: final copy — curator voice, no producer claims. */
						esc_html_e( 'We research, compare, and recommend third-party lab-tested CBD from trusted brands — so you can skip the guesswork.', 'affiliate-master' );
						?>
					</p>
					<div class="am-hero__actions">
						<a class="am-btn-primary" href="<?php echo esc_url( home_url( '/catalog/' ) ); ?>"><?php esc_html_e( 'Shop the collection', 'affiliate-master' ); ?></a>
						<a class="am-btn-light" href="<?php echo esc_url( home_url( '/about/' ) ); ?>"><?php esc_html_e( 'Our story', 'affiliate-master' ); ?></a>
					</div>
				</div>
				<div class="am-hero__img am-ph"><span><?php esc_html_e( 'lifestyle hero shot', 'affiliate-master' ); ?></span></div>
			</section>

			<?php
			/*
			---------------------------------------------------------
			 * Section 2 — Category boxes (compact, one per term).
			 *
			 * [CBD-10] Built dynamically from the product-type
			 * taxonomy (hide_empty), so the row grows on its own as
			 * imports land — busiest categories first. The result
			 * still flows through affiliate_master_home_categories:
			 * the CBD instance uses it to pluralize display labels
			 * (functions.php), other clones can reorder or replace.
			 * -------------------------------------------------------
			 */
			// [CBD-13] Shared builder (also feeds the single product
			// page's keep-shopping links); label overrides included.
			$am_categories = affiliate_master_get_product_type_categories();

			if ( ! empty( $am_categories ) ) :
				?>
				<section class="am-cats am-cats--compact">
					<?php foreach ( $am_categories as $am_category ) : ?>
						<a class="am-cat am-cat--sm" href="<?php echo esc_url( $am_category['url'] ); ?>">
							<span class="am-cat__name"><?php echo esc_html( $am_category['label'] ); ?></span>
							<?php if ( ! empty( $am_category['count'] ) ) : ?>
								<span class="am-cat__count">&middot; <?php echo esc_html( number_format_i18n( $am_category['count'] ) ); ?></span>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</section>
			<?php endif; ?>

			<?php
			/*
			---------------------------------------------------------
			 * Section 2b — Product search.
			 *
			 * [CBD-10] A plain GET form aimed at the catalog page,
			 * carrying the filter plugin's OWN query param: the
			 * plugin parses afpf_search from $_GET server-side
			 * ([DOC-154] shareable-URL state), so the first paint of
			 * /catalog/ arrives already filtered to affiliate_product
			 * results — blog posts and pages can never appear. No JS,
			 * no new endpoint, no WP search fallback needed.
			 * -------------------------------------------------------
			 */
			?>
			<section class="am-home-search-band">
				<form class="am-home-search" role="search" method="get" action="<?php echo esc_url( home_url( '/catalog/' ) ); ?>">
					<svg class="am-home-search__icon" aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
						<circle cx="11" cy="11" r="7"></circle>
						<line x1="21" y1="21" x2="16.5" y2="16.5"></line>
					</svg>
					<label class="screen-reader-text" for="am-home-search-input"><?php esc_html_e( 'Search products', 'affiliate-master' ); ?></label>
					<input
						type="search"
						id="am-home-search-input"
						name="afpf_search"
						placeholder="<?php esc_attr_e( 'Search CBD products…', 'affiliate-master' ); ?>"
					/>
					<button type="submit" class="am-home-search__btn"><?php esc_html_e( 'Search', 'affiliate-master' ); ?></button>
				</form>
			</section>

			<?php
			/*
			---------------------------------------------------------
			 * Section 3 — Quote band (forest panel).
			 *
			 * Curator voice, NOT the mockup's manufacturer voice — we
			 * recommend; we don't grow or bottle anything (brief §6).
			 * -------------------------------------------------------
			 */
			?>
			<section class="am-quote-band">
				<div class="am-quote-band__inner">
					<p>
						<?php
						/* TODO: final copy — placeholder curator quote. */
						esc_html_e( '“Every product we feature is third-party lab-tested and vetted against our standards — we do the research so what reaches you is worth your shelf.”', 'affiliate-master' );
						?>
					</p>
					<div class="am-quote-band__sig"><?php esc_html_e( 'Independently curated · CBD Green Labs', 'affiliate-master' ); ?></div>
				</div>
			</section>

			<?php
			/*
			---------------------------------------------------------
			 * Section 4 — Featured products.
			 *
			 * The filter plugin's static teaser ([DOC-180]); three
			 * cards per the mockup. Renders its empty state until the
			 * CBD feed is imported — expected during the build.
			 * -------------------------------------------------------
			 */
			?>
			<section class="am-featured-products">
				<div class="am-section-head">
					<h2><?php esc_html_e( 'Featured', 'affiliate-master' ); ?></h2>
					<a class="am-link-more" href="<?php echo esc_url( home_url( '/catalog/' ) ); ?>"><?php esc_html_e( 'View all →', 'affiliate-master' ); ?></a>
				</div>
				<?php
				// Plugin-built markup (cards, prices, buy buttons);
				// escaping would destroy it.
				echo do_shortcode( '[affiliate_filter per_page="3" show_filters="false" columns="3"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</section>

			<?php
			/*
			---------------------------------------------------------
			 * Section 5 — Latest guides (auto-hidden while no posts).
			 * -------------------------------------------------------
			 */
			$am_guides = new WP_Query(
				array(
					'post_type'           => 'post',
					'posts_per_page'      => 3,
					'orderby'             => 'date',
					'order'               => 'DESC',
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
				)
			);

			if ( $am_guides->have_posts() ) :
				$am_blog_page = (int) get_option( 'page_for_posts' );
				$am_blog_url  = $am_blog_page ? get_permalink( $am_blog_page ) : home_url( '/blog/' );
				?>
				<section class="am-blog-posts">
					<div class="am-section-head">
						<h2><?php esc_html_e( 'From the journal', 'affiliate-master' ); ?></h2>
						<a class="am-link-more" href="<?php echo esc_url( $am_blog_url ); ?>"><?php esc_html_e( 'View all →', 'affiliate-master' ); ?></a>
					</div>
					<div class="am-blog-posts__grid">
						<?php
						while ( $am_guides->have_posts() ) :
							$am_guides->the_post();
							?>
							<article class="am-blog-card">
								<a class="am-blog-card__link" href="<?php the_permalink(); ?>">
									<?php if ( has_post_thumbnail() ) : ?>
										<div class="am-blog-card__thumb">
											<?php the_post_thumbnail( 'medium_large' ); ?>
										</div>
									<?php endif; ?>
									<div class="am-blog-card__body">
										<h3 class="am-blog-card__title"><?php the_title(); ?></h3>
										<p class="am-blog-card__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 20 ) ); ?></p>
										<span class="am-blog-card__more"><?php esc_html_e( 'Read More →', 'affiliate-master' ); ?></span>
									</div>
								</a>
							</article>
						<?php endwhile; ?>
					</div>
				</section>
				<?php
				wp_reset_postdata();
			endif;
			?>

		</main>

		<?php astra_primary_content_bottom(); ?>

	</div><!-- #primary -->

<?php get_footer(); ?>

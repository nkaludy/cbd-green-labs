<?php
/**
 * [DOC-181] Homepage template (front-page.php).
 *
 * The front-page.php template sits at the very top of the template
 * hierarchy: once it exists, WordPress uses it for the site front
 * page no matter what
 * "Your homepage displays" is set to. The static "Home" page created
 * alongside it exists so the admin UI (Settings > Reading, the
 * Customizer, the page list) reflects reality — its content is never
 * rendered; this template is the homepage.
 *
 * Seven full-width bands: hero, categories, scales, featured
 * products, guides, trust signals, closing CTA. The child header.php
 * hardcodes Astra's .ast-container ([DOC-168]), which boxes all
 * content to the site width — so each band uses the .am-band
 * negative-margin breakout ([DOC-182]) to reclaim the full viewport,
 * then re-centers its content with .am-container. That trick is why
 * this template needs no Astra layout filters and no header changes.
 *
 * Category cards, scale pills and trust items are data arrays run
 * through affiliate_master_home_* filters, so a niche clone can
 * re-target the homepage from a small plugin or functions.php without
 * editing template markup (drone site: swap six taxonomy slugs, done).
 *
 * The featured strip reuses the filter plugin's grid via
 * [affiliate_filter show_filters="false"] ([DOC-180]) instead of a
 * hand-rolled product query: one grid implementation means the
 * homepage cards can never drift out of sync with the catalog's.
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
			 * Section 1 — Hero (Hero C: search-forward, [DOC-187]).
			 *
			 * The search box is the primary CTA: it redirects to
			 * /catalog/?afpf_search=..., the SAME parameter the filter
			 * plugin's own search box writes ([DOC-134]), so the
			 * catalog lands pre-filtered with zero new query plumbing.
			 *
			 * Quick-tag URLs are verified against THIS site's actual
			 * rewrites: taxonomy archives where a term exists
			 * (/brand/motormax/, not the /brands/ spelling from the
			 * mockup), catalog search URLs where one doesn't ("Muscle
			 * Cars", "Fast & Furious") — a tag that 404s is worse than
			 * a tag that lands on a live filtered catalog.
			 * ------------------------------------------------------- */
			$am_hero_tags = apply_filters(
				'affiliate_master_hero_tags',
				array(
					array(
						'label' => __( '1/18 Scale', 'affiliate-master' ),
						'url'   => home_url( '/scale/1-18/' ),
					),
					array(
						'label' => __( 'Motormax', 'affiliate-master' ),
						'url'   => home_url( '/brand/motormax/' ),
					),
					array(
						'label' => __( 'Muscle Cars', 'affiliate-master' ),
						'url'   => home_url( '/catalog/?afpf_search=muscle' ),
					),
					array(
						'label' => __( 'Aircraft', 'affiliate-master' ),
						'url'   => home_url( '/product-type/aircraft/' ),
					),
					array(
						'label' => __( 'Fast & Furious', 'affiliate-master' ),
						'url'   => home_url( '/catalog/?afpf_search=fast%20furious' ),
					),
				)
			);
			?>
			<section class="am-band am-hero">
				<div class="am-hero-inner">
					<div class="am-hero-eyebrow"><?php esc_html_e( '✦ Your Complete Die Cast Resource', 'affiliate-master' ); ?></div>
					<h1 class="am-hero-title">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %1$s / %2$s: opening/closing markup around the gold-accented words. */
								__( 'Find Your Next %1$sDie Cast%2$s Model', 'affiliate-master' ),
								'<span class="am-hero-accent">',
								'</span>'
							),
							array( 'span' => array( 'class' => array() ) )
						);
						?>
					</h1>
					<p class="am-hero-sub"><?php esc_html_e( 'Search 13,000+ models from Motormax, Jada, Maisto, GeminiJets and more.', 'affiliate-master' ); ?></p>
					<div class="am-hero-search">
						<label class="screen-reader-text" for="am-hero-search-input"><?php esc_html_e( 'Search products', 'affiliate-master' ); ?></label>
						<input type="text" placeholder="<?php esc_attr_e( 'Search by brand, scale, or model name...', 'affiliate-master' ); ?>" id="am-hero-search-input" />
						<button id="am-hero-search-btn" type="button"><?php esc_html_e( 'Search', 'affiliate-master' ); ?></button>
					</div>
					<div class="am-hero-tags">
						<?php foreach ( $am_hero_tags as $am_hero_tag ) : ?>
							<a href="<?php echo esc_url( $am_hero_tag['url'] ); ?>" class="am-hero-tag"><?php echo esc_html( $am_hero_tag['label'] ); ?></a>
						<?php endforeach; ?>
					</div>
				</div>
			</section>
			<script>
			/* [DOC-187] Hero search -> catalog handoff. Plain inline JS
			   (no enqueue) because it is four lines coupled to this one
			   template, and afpf_search is the filter plugin's public
			   URL contract ([DOC-134]). */
			( function () {
				var input  = document.getElementById( 'am-hero-search-input' );
				var button = document.getElementById( 'am-hero-search-btn' );
				// wp_json_encode output is safe, JS-context-correct JSON.
				var catalog = <?php echo wp_json_encode( home_url( '/catalog/' ) ); ?>; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				function go() {
					var term = input.value.trim();
					window.location.href = term ? catalog + '?afpf_search=' + encodeURIComponent( term ) : catalog;
				}
				button.addEventListener( 'click', go );
				input.addEventListener( 'keydown', function ( event ) {
					if ( 'Enter' === event.key ) {
						event.preventDefault();
						go();
					}
				} );
			}() );
			</script>

			<?php
			/*
			---------------------------------------------------------
			 * Section 2 — Shop By Category ([DOC-193] compact 8-up).
			 *
			 * Entries carry a full URL (not a product-type slug)
			 * because the row mixes targets: seven taxonomy archives
			 * plus the catalog-wide "All Models" box. URLs point at
			 * the terms' REAL slugs — notably tv-movie, not the
			 * tv-and-movie spelling mockups keep reaching for. A
			 * clone overrides the whole list through the filter
			 * (note: pre-DOC-193 the array carried 'slug' keys; any
			 * clone filter written against that shape needs its
			 * entries updated to 'url').
			 * ------------------------------------------------------- */
			$am_categories = apply_filters(
				'affiliate_master_home_categories',
				array(
					array(
						'icon'  => '🚗',
						'label' => __( 'Cars', 'affiliate-master' ),
						'url'   => home_url( '/product-type/cars/' ),
					),
					array(
						'icon'  => '🚚',
						'label' => __( 'Trucks', 'affiliate-master' ),
						'url'   => home_url( '/product-type/trucks/' ),
					),
					array(
						'icon'  => '✈️',
						'label' => __( 'Aircraft', 'affiliate-master' ),
						'url'   => home_url( '/product-type/aircraft/' ),
					),
					array(
						'icon'  => '🚒',
						'label' => __( 'Emergency', 'affiliate-master' ),
						'url'   => home_url( '/product-type/emergency-vehicles/' ),
					),
					array(
						'icon'  => '🎬',
						'label' => __( 'TV & Movie', 'affiliate-master' ),
						'url'   => home_url( '/product-type/tv-movie/' ),
					),
					array(
						'icon'  => '🪖',
						'label' => __( 'Military', 'affiliate-master' ),
						'url'   => home_url( '/product-type/military/' ),
					),
					array(
						'icon'  => '🚜',
						'label' => __( 'Farm Vehicles', 'affiliate-master' ),
						'url'   => home_url( '/product-type/farm-vehicles/' ),
					),
					array(
						'icon'  => '⭐',
						'label' => __( 'All Models', 'affiliate-master' ),
						'url'   => home_url( '/catalog/' ),
					),
				)
			);
			?>
			<section class="am-band am-categories">
				<div class="am-container">
					<h2 class="am-section-heading"><?php esc_html_e( 'Shop By Category', 'affiliate-master' ); ?></h2>
					<div class="am-categories-grid">
						<?php foreach ( $am_categories as $am_category ) : ?>
							<a class="am-category-card" href="<?php echo esc_url( $am_category['url'] ); ?>">
								<span class="am-category-icon" aria-hidden="true"><?php echo esc_html( $am_category['icon'] ); ?></span>
								<span class="am-category-name"><?php echo esc_html( $am_category['label'] ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			</section>

			<?php
			/*
			---------------------------------------------------------
			 * Section 3 — Shop By Scale.
			 *
			 * Slugs derive from the label ("1/18" -> "1-18"), matching
			 * how the importer sanitizes scale terms.
			 * ------------------------------------------------------- */
			$am_scales = apply_filters(
				'affiliate_master_home_scales',
				array( '1/18', '1/24', '1/32', '1/43', '1/64' )
			);
			?>
			<section class="am-band am-scales">
				<div class="am-container">
					<h2 class="am-section-heading"><?php esc_html_e( 'Shop By Scale', 'affiliate-master' ); ?></h2>
					<div class="am-scales__row">
						<?php foreach ( $am_scales as $am_scale ) : ?>
							<a class="am-scale-pill" href="<?php echo esc_url( home_url( '/scale/' . str_replace( '/', '-', $am_scale ) . '/' ) ); ?>">
								<?php echo esc_html( $am_scale ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			</section>

			<?php
			/*
			---------------------------------------------------------
			 * Section 4 — Featured products (static teaser, [DOC-180]).
			 * ------------------------------------------------------- */
			?>
			<section class="am-band am-featured-products">
				<div class="am-container">
					<h2 class="am-section-heading"><?php esc_html_e( 'Featured Die Cast Models', 'affiliate-master' ); ?></h2>
					<?php
					// Plugin-built markup (cards, prices, buy buttons);
					// escaping would destroy it.
					echo do_shortcode( '[affiliate_filter per_page="15" show_filters="false" columns="5"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					<div class="am-view-all">
						<a class="am-btn-outline" href="<?php echo esc_url( home_url( '/catalog/' ) ); ?>"><?php esc_html_e( 'View All Products →', 'affiliate-master' ); ?></a>
					</div>
				</div>
			</section>

			<?php
			/*
			---------------------------------------------------------
			 * Section 5 — Latest guides.
			 *
			 * no_found_rows: three cards, no pagination, skip the
			 * count query.
			 * ------------------------------------------------------- */
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
				<section class="am-band am-blog-posts">
					<div class="am-container">
						<h2 class="am-section-heading"><?php esc_html_e( 'Collector Guides and Reviews', 'affiliate-master' ); ?></h2>
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
						<div class="am-view-all">
							<a class="am-btn-outline" href="<?php echo esc_url( $am_blog_url ); ?>"><?php esc_html_e( 'View All Guides →', 'affiliate-master' ); ?></a>
						</div>
					</div>
				</section>
				<?php
				wp_reset_postdata();
			endif;

			/*
			---------------------------------------------------------
			 * Section 6 — Trust signals.
			 * ------------------------------------------------------- */
			$am_trust_items = apply_filters(
				'affiliate_master_home_trust',
				array(
					array(
						'icon'    => '🏆',
						'heading' => __( '13,000+ Models', 'affiliate-master' ),
						'text'    => __( 'The largest die cast catalog online', 'affiliate-master' ),
					),
					array(
						'icon'    => '⭐',
						'heading' => __( 'Expert Reviews', 'affiliate-master' ),
						'text'    => __( '25 years of collecting experience', 'affiliate-master' ),
					),
					array(
						'icon'    => '🛡️',
						'heading' => __( 'Trusted Retailers', 'affiliate-master' ),
						'text'    => __( 'Only verified affiliate partners', 'affiliate-master' ),
					),
				)
			);
			?>
			<section class="am-band am-trust">
				<div class="am-container">
					<div class="am-trust__grid">
						<?php foreach ( $am_trust_items as $am_trust_item ) : ?>
							<div class="am-trust__item">
								<span class="am-trust__icon" aria-hidden="true"><?php echo esc_html( $am_trust_item['icon'] ); ?></span>
								<h3 class="am-trust__heading"><?php echo esc_html( $am_trust_item['heading'] ); ?></h3>
								<p class="am-trust__text"><?php echo esc_html( $am_trust_item['text'] ); ?></p>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</section>

			<?php
			/*
			---------------------------------------------------------
			 * Section 7 — Closing CTA banner.
			 * ------------------------------------------------------- */
			?>
			<section class="am-band am-cta-banner">
				<div class="am-container">
					<h2 class="am-cta-banner__title"><?php esc_html_e( 'Ready to Find Your Next Favourite Model?', 'affiliate-master' ); ?></h2>
					<p class="am-cta-banner__sub"><?php esc_html_e( 'Browse our complete collection of die cast cars, trucks and aircraft', 'affiliate-master' ); ?></p>
					<a class="am-btn-primary" href="<?php echo esc_url( home_url( '/catalog/' ) ); ?>"><?php esc_html_e( 'Browse the Collection', 'affiliate-master' ); ?></a>
				</div>
			</section>

		</main>

		<?php astra_primary_content_bottom(); ?>

	</div><!-- #primary -->

<?php get_footer(); ?>

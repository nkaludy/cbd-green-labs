<?php
/**
 * [DOC-173] Single blog post template.
 *
 * Until Phase 10, ordinary posts were left to Astra's own renderer and
 * merely skinned by style.css ([DOC-158]). This override exists because
 * the content plan adds two things Astra does not render at all: the
 * author bio box (E-E-A-T signal — review sites live and die on
 * demonstrable authorship) and the related-posts grid (internal links
 * that keep a reader on the site instead of bouncing back to Google).
 *
 * The markup deliberately reuses the exact class names [DOC-158]
 * already targets (post-thumb, entry-title, entry-meta, entry-content),
 * so the existing skin applies unchanged and no rules had to move.
 * Only the two new components get new am-* classes ([DOC-178]).
 *
 * Structure mirrors single-affiliate_product.php ([DOC-009]): Astra's
 * header/footer/#primary wrappers are kept so the post inherits the
 * site chrome, and only the loop content is ours.
 *
 * @package Affiliate_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header(); ?>

	<div id="primary" <?php astra_primary_class(); ?>>

		<?php astra_primary_content_top(); ?>

		<?php
		while ( have_posts() ) :
			the_post();
			?>

			<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>

				<?php if ( has_post_thumbnail() ) : ?>
					<div class="post-thumb">
						<?php the_post_thumbnail( 'large' ); ?>
					</div>
				<?php endif; ?>

				<header class="entry-header">
					<h1 class="entry-title"><?php the_title(); ?></h1>

					<div class="entry-meta">
						<span class="entry-meta__author">
							<?php
							printf(
								/* translators: %s: post author display name. */
								esc_html__( 'By %s', 'affiliate-master' ),
								esc_html( get_the_author() )
							);
							?>
						</span>
						<span class="entry-meta__sep">&middot;</span>
						<span class="entry-meta__date"><?php echo esc_html( get_the_date() ); ?></span>
						<?php if ( has_category() ) : ?>
							<span class="entry-meta__sep">&middot;</span>
							<span class="entry-meta__categories"><?php the_category( ', ' ); ?></span>
						<?php endif; ?>
					</div>
				</header>

				<div class="entry-content">
					<?php the_content(); ?>
				</div>

				<?php
				/**
				 * [DOC-174] Author bio box.
				 *
				 * Rendered even when the profile has no biography yet: an
				 * empty slot would silently hide the E-E-A-T signal this
				 * box exists for, whereas a generic fallback line still
				 * shows a human byline and reminds the site builder to
				 * fill in the profile (Users > Profile > Biographical
				 * Info). get_avatar() supplies the placeholder image on
				 * its own when the author has no Gravatar.
				 */
				$author_bio = get_the_author_meta( 'description' );
				if ( ! $author_bio ) {
					/* translators: %s: site name. */
					$author_bio = sprintf( __( 'Part of the editorial team at %s.', 'affiliate-master' ), get_bloginfo( 'name' ) );
				}
				?>
				<aside class="am-author-box">
					<div class="am-author-box__avatar">
						<?php echo get_avatar( get_the_author_meta( 'ID' ), 96 ); ?>
					</div>
					<div class="am-author-box__text">
						<p class="am-author-box__label"><?php esc_html_e( 'Written by', 'affiliate-master' ); ?></p>
						<h2 class="am-author-box__name"><?php echo esc_html( get_the_author() ); ?></h2>
						<p class="am-author-box__bio"><?php echo esc_html( $author_bio ); ?></p>
					</div>
				</aside>

			</article>

			<?php
			/**
			 * [DOC-175] Related posts: the 3 most recent OTHER posts.
			 *
			 * "Recent" rather than "same category" on purpose: these
			 * niche sites launch with a handful of posts spread across
			 * categories, and a same-category query would render an
			 * empty (or one-card) section for months. Recent posts
			 * guarantee a full row from the third post onward; revisit
			 * if a clone ever grows past ~50 posts per category.
			 *
			 * no_found_rows skips the SQL_CALC_FOUND_ROWS pass (no
			 * pagination here), keeping the extra query cheap.
			 */
			$am_related = new WP_Query(
				array(
					'post_type'           => 'post',
					'posts_per_page'      => 3,
					'post__not_in'        => array( get_the_ID() ),
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
				)
			);

			if ( $am_related->have_posts() ) :
				?>
				<section class="am-related">
					<h2 class="am-related__title"><?php esc_html_e( 'Related Posts', 'affiliate-master' ); ?></h2>
					<div class="am-related__grid">
						<?php
						while ( $am_related->have_posts() ) :
							$am_related->the_post();
							?>
							<article class="am-related__card">
								<a class="am-related__link" href="<?php the_permalink(); ?>">
									<?php if ( has_post_thumbnail() ) : ?>
										<div class="am-related__thumb">
											<?php the_post_thumbnail( 'medium' ); ?>
										</div>
									<?php endif; ?>
									<div class="am-related__body">
										<h3 class="am-related__card-title"><?php the_title(); ?></h3>
										<span class="am-related__date"><?php echo esc_html( get_the_date() ); ?></span>
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

			<?php
		endwhile;
		?>

		<?php astra_primary_content_bottom(); ?>

	</div><!-- #primary -->

<?php get_footer(); ?>

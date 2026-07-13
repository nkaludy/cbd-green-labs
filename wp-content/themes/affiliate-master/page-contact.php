<?php
/**
 * Template Name: Contact Page
 *
 * [DOC-177] Contact page template: form left, contact info right.
 *
 * The form column renders a WPForms shortcode. FORM_ID below is a
 * deliberate placeholder — WPForms assigns IDs per site, so a cloned
 * niche site MUST create its form (WPForms > Add New, the "Simple
 * Contact Form" starter is fine) and replace FORM_ID here. Until
 * then, or when WPForms is deactivated, the template degrades to a
 * visible instruction instead of an empty column: a silent gap looks
 * like a bug, an instruction reads as the setup step it is.
 *
 * The info column pulls the admin email rather than hardcoding one:
 * this repo is cloned per niche site and a literal address would leak
 * across every clone ([CLAUDE.md portability rule]).
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

			<article id="post-<?php the_ID(); ?>" <?php post_class( 'am-page am-page--contact' ); ?>>
				<div class="am-page__container">

					<header class="am-page__header">
						<h1 class="am-page__title"><?php the_title(); ?></h1>
					</header>

					<?php if ( get_the_content() ) : ?>
						<div class="am-prose am-contact__intro">
							<?php the_content(); ?>
						</div>
					<?php endif; ?>

					<div class="am-contact">

						<div class="am-contact__form">
							<h2 class="am-contact__heading"><?php esc_html_e( 'Send us a message', 'affiliate-master' ); ?></h2>
							<?php
							$am_form_html = '';
							if ( shortcode_exists( 'wpforms' ) ) {
								$am_form_html = do_shortcode( '[wpforms id="FORM_ID"]' );
							}

							if ( trim( $am_form_html ) ) {
								// WPForms builds this markup (form fields, nonce,
								// scripts); escaping it would break the form.
								echo $am_form_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							} else {
								?>
								<p class="am-contact__form-note">
									<?php esc_html_e( 'Contact form coming soon. (Site builder: create a form in WPForms and replace FORM_ID in page-contact.php with its ID.)', 'affiliate-master' ); ?>
								</p>
								<?php
							}
							?>
						</div>

						<aside class="am-contact__info">
							<h2 class="am-contact__heading"><?php esc_html_e( 'Get in touch', 'affiliate-master' ); ?></h2>
							<p class="am-contact__text">
								<?php esc_html_e( 'Questions about a product, a review, or working with us? We read every message and usually reply within two business days.', 'affiliate-master' ); ?>
							</p>
							<?php
							/*
							 * No public email here by choice: the form is
							 * the contact channel, and the old fallback
							 * printed the site's admin_email — a private
							 * inbox that should never render on the front
							 * end of this instance.
							 */
							?>
						</aside>

					</div><!-- .am-contact -->

				</div>
			</article>

			<?php
		endwhile;
		?>

		<?php astra_primary_content_bottom(); ?>

	</div><!-- #primary -->

<?php get_footer(); ?>

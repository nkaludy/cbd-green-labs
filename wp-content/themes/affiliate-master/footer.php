<?php
/**
 * [DOC-169] Site footer template (child override of Astra's).
 *
 * The opening half mirrors Astra's footer.php exactly — it must,
 * because it closes the .ast-container and #content divs that
 * header.php opened; a mismatch here breaks the layout of every page
 * on the site. The astra_footer() call is the ONE thing replaced:
 * instead of Astra's footer builder, the site renders its own
 * two-section footer below (widgets/links area + copyright bar with
 * the affiliate disclosure). The astra_footer_before/after hooks are
 * kept so addons that inject around the footer still work.
 *
 * @package Affiliate_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<?php astra_content_bottom(); ?>
	</div> <!-- ast-container -->
	</div><!-- #content -->
<?php
	astra_content_after();

	astra_footer_before();
?>

	<footer class="am-footer">
		<div class="ast-container">

			<?php
			/*
			 * [DOC-170] Footer columns: the widget area first
			 * ([DOC-167]) so each site curates its own columns; when no
			 * widgets are placed yet, a sensible default renders — the
			 * site identity plus the footer menu — so a fresh clone
			 * never ships an empty green void.
			 */
			?>
			<div class="am-footer-widgets">
				<?php if ( is_active_sidebar( 'footer-columns' ) ) : ?>
					<?php dynamic_sidebar( 'footer-columns' ); ?>
				<?php else : ?>
					<div class="am-footer-col">
						<h3 class="widget-title"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h3>
						<p><?php echo esc_html( get_bloginfo( 'description' ) ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( has_nav_menu( 'footer' ) ) : ?>
					<nav class="am-footer-col am-footer-menu" aria-label="<?php esc_attr_e( 'Footer navigation', 'affiliate-master' ); ?>">
						<h3 class="widget-title"><?php esc_html_e( 'Explore', 'affiliate-master' ); ?></h3>
						<?php
						wp_nav_menu(
							array(
								'theme_location' => 'footer',
								'depth'          => 1,
								'container'      => false,
								'fallback_cb'    => false,
							)
						);
						?>
					</nav>
				<?php endif; ?>
			</div>

			<div class="am-footer-copyright">
				&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?>.
				<?php esc_html_e( 'All rights reserved.', 'affiliate-master' ); ?>
			</div>

			<?php
			/*
			 * [DOC-171] Affiliate disclosure — required by every
			 * affiliate program's ToS and by the FTC, so it renders on
			 * EVERY page unconditionally. The site name is pulled
			 * dynamically (never hardcoded): this template is cloned
			 * into many differently-named sites that eventually get
			 * sold, and a baked-in domain would quietly misidentify
			 * every clone (see CLAUDE.md's portability rule).
			 */
			?>
			<div class="am-footer-disclosure">
				<?php
				printf(
					/* translators: %s: site name. */
					esc_html__( '%s is a participant in affiliate advertising programs. We may earn a commission when you click links to retailers and make a purchase.', 'affiliate-master' ),
					esc_html( get_bloginfo( 'name' ) )
				);
				?>
			</div>

			<?php
			/*
			 * [CBD-05] Niche compliance lines (e.g. the CBD FDA
			 * statement). Empty by default so non-regulated niches
			 * render nothing; instances add lines via the filter in
			 * functions.php — same portability rule as [DOC-171].
			 */
			$am_compliance_lines = apply_filters( 'affiliate_master_footer_compliance', array() );
			foreach ( $am_compliance_lines as $am_compliance_line ) :
				?>
				<div class="am-footer-disclosure am-footer-compliance"><?php echo esc_html( $am_compliance_line ); ?></div>
			<?php endforeach; ?>
		</div>
	</footer>

<?php
	astra_footer_after();
?>
	</div><!-- #page -->
<?php
	astra_body_bottom();
	wp_footer();
?>
	</body>
</html>

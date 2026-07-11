<?php
/**
 * [DOC-210] Native site analytics: GA4 + Search Console verification.
 *
 * Replaces Google Site Kit. Two reasons the plugin had to go:
 *
 *   1. It claimed to place the GA tag but did not render it for
 *      logged-out visitors — the exact traffic analytics exists to
 *      measure — which made every "is tracking working?" check a
 *      confusing dead end.
 *   2. Its per-site OAuth connection is a manual, undocumentable
 *      chore that would have to be repeated on every one of the
 *      cloned niche sites.
 *
 * This module is the template-owned replacement: a Settings screen
 * holding two per-site OPTIONS (the GA4 measurement ID and the
 * Search Console verification token) and a wp_head renderer that
 * prints the standard tags when — and only when — those options are
 * filled in. The template stays universal: nothing is hardcoded, no
 * OAuth exists at all, and each clone types its own two values into
 * wp-admin once (Settings > Site Analytics) as part of site setup.
 *
 * @package Affiliate_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [DOC-211] Register the two analytics options.
 *
 * Registered via the Settings API so saving runs through
 * options.php's own capability + nonce verification; the sanitize
 * callbacks below are the single write-gate for both values.
 * Separate options (not one array) so wp_head can read each with a
 * plain autoloaded get_option() and a clone can manage them
 * individually over WP-CLI (wp option update affiliate_master_ga4_id …).
 */
function affiliate_master_analytics_register_settings() {

	register_setting(
		'affiliate_master_analytics',
		'affiliate_master_ga4_id',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'affiliate_master_sanitize_ga4_id',
			'default'           => '',
		)
	);

	register_setting(
		'affiliate_master_analytics',
		'affiliate_master_gsc_verification',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'affiliate_master_sanitize_gsc_verification',
			'default'           => '',
		)
	);

	add_settings_section(
		'affiliate_master_analytics_section',
		__( 'Google Analytics & Search Console', 'affiliate-master' ),
		'affiliate_master_analytics_section_intro',
		'affiliate-master-analytics'
	);

	add_settings_field(
		'affiliate_master_ga4_id',
		__( 'GA4 Measurement ID', 'affiliate-master' ),
		'affiliate_master_ga4_id_field',
		'affiliate-master-analytics',
		'affiliate_master_analytics_section'
	);

	add_settings_field(
		'affiliate_master_gsc_verification',
		__( 'Search Console Verification Code', 'affiliate-master' ),
		'affiliate_master_gsc_verification_field',
		'affiliate-master-analytics',
		'affiliate_master_analytics_section'
	);
}
add_action( 'admin_init', 'affiliate_master_analytics_register_settings' );

/**
 * Sanitize the GA4 measurement ID.
 *
 * Accepts the "G-XXXXXXXXXX" shape (uppercased, whitespace trimmed).
 * Anything else keeps the PREVIOUS value and surfaces a settings
 * error instead of silently storing junk that would then render as
 * a broken gtag config on every page of the site.
 *
 * @param string $value Raw submitted value.
 * @return string Sanitized ID, or the previous value on bad input.
 */
function affiliate_master_sanitize_ga4_id( $value ) {
	$value = strtoupper( trim( sanitize_text_field( (string) $value ) ) );

	if ( '' === $value ) {
		return '';
	}

	if ( ! preg_match( '/^G-[A-Z0-9]{4,20}$/', $value ) ) {
		add_settings_error(
			'affiliate_master_ga4_id',
			'affiliate_master_ga4_id_invalid',
			__( 'The GA4 Measurement ID was not saved: it should look like G-XXXXXXXXXX (Google Analytics > Admin > Data Streams).', 'affiliate-master' )
		);
		return (string) get_option( 'affiliate_master_ga4_id', '' );
	}

	return $value;
}

/**
 * Sanitize the Search Console verification token.
 *
 * Site owners routinely paste Google's ENTIRE meta tag instead of
 * just the content value, so that case is unwrapped rather than
 * rejected. The token itself is restricted to the base64ish charset
 * Google issues; anything else keeps the previous value with a
 * settings error.
 *
 * @param string $value Raw submitted value.
 * @return string Sanitized token, or the previous value on bad input.
 */
function affiliate_master_sanitize_gsc_verification( $value ) {
	$value = trim( (string) wp_unslash( $value ) );

	// Forgive a full <meta … content="TOKEN" …> paste.
	if ( preg_match( '/content\s*=\s*["\']([^"\']+)["\']/i', $value, $matches ) ) {
		$value = $matches[1];
	}

	$value = trim( sanitize_text_field( $value ) );

	if ( '' === $value ) {
		return '';
	}

	if ( ! preg_match( '/^[A-Za-z0-9_\-]{10,100}$/', $value ) ) {
		add_settings_error(
			'affiliate_master_gsc_verification',
			'affiliate_master_gsc_verification_invalid',
			__( 'The verification code was not saved: paste only the content value from Google\'s HTML-tag method (Search Console > add property > HTML tag).', 'affiliate-master' )
		);
		return (string) get_option( 'affiliate_master_gsc_verification', '' );
	}

	return $value;
}

/**
 * Section intro copy.
 */
function affiliate_master_analytics_section_intro() {
	echo '<p>' . esc_html__( 'Both tags render in the site head for every visitor as soon as a value is saved; an empty field outputs nothing. No plugin, no OAuth — each site owns its own two values here.', 'affiliate-master' ) . '</p>';
}

/**
 * GA4 ID field markup.
 */
function affiliate_master_ga4_id_field() {
	?>
	<input
		type="text"
		id="affiliate_master_ga4_id"
		name="affiliate_master_ga4_id"
		class="regular-text code"
		value="<?php echo esc_attr( get_option( 'affiliate_master_ga4_id', '' ) ); ?>"
		placeholder="G-XXXXXXXXXX"
	/>
	<p class="description"><?php esc_html_e( 'Google Analytics > Admin > Data Streams.', 'affiliate-master' ); ?></p>
	<?php
}

/**
 * Search Console verification field markup.
 */
function affiliate_master_gsc_verification_field() {
	?>
	<input
		type="text"
		id="affiliate_master_gsc_verification"
		name="affiliate_master_gsc_verification"
		class="regular-text code"
		value="<?php echo esc_attr( get_option( 'affiliate_master_gsc_verification', '' ) ); ?>"
	/>
	<p class="description"><?php esc_html_e( 'Search Console > add property > HTML tag method — paste just the content value (the full meta tag is unwrapped automatically).', 'affiliate-master' ); ?></p>
	<?php
}

/**
 * [DOC-212] Settings > Site Analytics screen.
 *
 * A plain Settings API page: settings_fields() prints the nonce +
 * option-group fields, options.php enforces the manage_options
 * capability registered here, and do_settings_sections renders the
 * section/fields built in [DOC-211].
 */
function affiliate_master_analytics_menu() {
	add_options_page(
		__( 'Site Analytics', 'affiliate-master' ),
		__( 'Site Analytics', 'affiliate-master' ),
		'manage_options',
		'affiliate-master-analytics',
		'affiliate_master_analytics_page'
	);
}
add_action( 'admin_menu', 'affiliate_master_analytics_menu' );

/**
 * Render the settings page.
 */
function affiliate_master_analytics_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'affiliate_master_analytics' );
			do_settings_sections( 'affiliate-master-analytics' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

/**
 * [DOC-213] Print the Search Console verification meta tag.
 *
 * Early in the head (priority 2) because Google's verifier reads the
 * top of the document. Empty option = no output at all.
 */
function affiliate_master_render_gsc_verification() {
	/**
	 * One switch for all analytics head output.
	 *
	 * @param bool $render Whether to render the analytics tags.
	 */
	if ( ! apply_filters( 'affiliate_master_render_analytics', true ) ) {
		return;
	}

	$token = (string) get_option( 'affiliate_master_gsc_verification', '' );
	if ( '' === $token ) {
		return;
	}

	echo '<meta name="google-site-verification" content="' . esc_attr( $token ) . '">' . "\n";
}
add_action( 'wp_head', 'affiliate_master_render_gsc_verification', 2 );

/**
 * [DOC-214] Print the GA4 gtag.js snippet.
 *
 * The standard two-script snippet from Google's own install
 * instructions, rendered for EVERY visitor — deliberately including
 * logged-in users. Site Kit's invisible logged-out/logged-in
 * asymmetry is precisely the confusion this module removes: what an
 * admin sees in view-source is what every visitor gets. Filter
 * traffic in GA4 itself if admin visits need excluding.
 *
 * Empty option = no output. The ID was locked to ^G-[A-Z0-9]+$ at
 * save time, so the esc_attr/esc_js here are belt-and-braces.
 */
function affiliate_master_render_ga4() {
	/** This filter is documented in [DOC-213]. */
	if ( ! apply_filters( 'affiliate_master_render_analytics', true ) ) {
		return;
	}

	$ga4_id = (string) get_option( 'affiliate_master_ga4_id', '' );
	if ( '' === $ga4_id ) {
		return;
	}
	?>
	<!-- [DOC-214] GA4 (native, replaces Site Kit) -->
	<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( rawurlencode( $ga4_id ) ); ?>"></script>
	<script>
		window.dataLayer = window.dataLayer || [];
		function gtag(){dataLayer.push(arguments);}
		gtag('js', new Date());
		gtag('config', '<?php echo esc_js( $ga4_id ); ?>');
	</script>
	<?php
}
add_action( 'wp_head', 'affiliate_master_render_ga4', 5 );

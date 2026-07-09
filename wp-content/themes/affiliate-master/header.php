<?php
/**
 * [DOC-168] Site header template (child override of Astra's).
 *
 * Structurally this mirrors Astra's own header.php on purpose. The
 * branding and primary navigation the design calls for are rendered
 * BY the astra_header() call below — Astra's header builder outputs
 * the custom logo / site title and the primary menu through its own
 * nav walker, which is what keeps the mobile hamburger drawer,
 * submenu toggles and sticky behaviour working (they are wired to
 * Astra's markup and JS). Hand-rolling a <nav> here would produce a
 * header that looks right and then breaks the moment a phone visits.
 *
 * The visual identity (racing-green bar, gold top border, white nav
 * links, sticky shadow) is applied entirely in style.css [DOC-160] —
 * skin in CSS, structure from the parent. Every astra_* hook is
 * preserved so Astra addons and the Customizer keep their injection
 * points.
 *
 * The file exists in the child theme (rather than relying on the
 * parent's copy) so future phases can add markup around the header —
 * announcement bars, schema, etc. — without touching Astra.
 *
 * @package Affiliate_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?><!DOCTYPE html>
<?php astra_html_before(); ?>
<html <?php language_attributes(); ?>>
<head>
<?php astra_head_top(); ?>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
<?php astra_head_bottom(); ?>
</head>

<body <?php astra_schema_body(); ?> <?php body_class(); ?>>
<?php astra_body_top(); ?>
<?php wp_body_open(); ?>

<a class="skip-link screen-reader-text" href="#content">
	<?php echo esc_html( astra_default_strings( 'string-header-skip-link', false ) ); ?>
</a>

<div
<?php
	echo wp_kses_post(
		astra_attr(
			'site',
			array(
				'id'    => 'page',
				'class' => 'hfeed site',
			)
		)
	);
	?>
>
	<?php
	astra_header_before();

	// Branding + primary navigation, rendered by the parent (see the
	// file header for why this is not hand-rolled markup).
	astra_header();

	astra_header_after();

	astra_content_before();
	?>
	<div id="content" class="site-content">
		<div class="ast-container">
		<?php astra_content_top(); ?>

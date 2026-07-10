<?php
/**
 * [DOC-002 / DOC-003] Affiliate Master child theme bootstrap file.
 *
 * This is the single entry point WordPress loads for the child theme.
 * Its only two jobs, kept deliberately narrow so the theme stays easy
 * to reason about, are:
 *
 *   1. Enqueue the parent (Astra) stylesheet and our own child
 *      stylesheet in the correct order. [DOC-002]
 *   2. Pull in the modular files under /inc/ that register the custom
 *      post type and the ACF field group, so this file itself never
 *      grows into a dumping ground of unrelated logic. [DOC-003]
 *
 * Every other file in this theme documents itself with a [DOC-xxx]
 * tag in its header comment. Those tags map 1:1 to sections in the
 * project documentation we will write later, so searching the
 * codebase for a tag (e.g. "DOC-007") jumps straight to the code the
 * docs are describing.
 *
 * @package Affiliate_Master
 */

// Exit if this file is loaded directly instead of through WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [DOC-002] Enqueue parent and child theme stylesheets.
 *
 * Astra (the parent theme) ships its own style.css with the base
 * layout/typography rules. A child theme's style.css is NOT loaded
 * automatically by WordPress just because it exists — only its
 * header comment is read for theme metadata (see style.css /
 * [DOC-001]). We therefore have to explicitly enqueue both:
 *
 *   - 'astra-parent-style' first, so the parent's CSS loads first and
 *     establishes the base design.
 *   - 'affiliate-master-style' next, DEPENDENT on the parent handle,
 *     so our overrides are guaranteed to load after (and therefore
 *     win any CSS specificity ties against) Astra's rules.
 *
 * We also enqueue a dedicated affiliate-master.css file from
 * /assets/css/ (rather than writing rules into style.css) so the
 * "Buy Now" button styling documented in [DOC-011] lives in one
 * obvious, single-purpose file.
 */
function affiliate_master_enqueue_styles() {
	$parent_style = 'astra-parent-style';

	wp_enqueue_style(
		$parent_style,
		get_template_directory_uri() . '/style.css',
		array(),
		wp_get_theme( 'astra' )->get( 'Version' )
	);

	wp_enqueue_style(
		'affiliate-master-product-styles',
		get_stylesheet_directory_uri() . '/assets/css/affiliate-master.css',
		array( $parent_style ),
		wp_get_theme()->get( 'Version' )
	);

	/*
	 * [CBD-02] Brand fonts, enqueued (NOT @import-ed — the mockup's
	 * @import would serialize the download behind the stylesheet).
	 * Archivo = display, Hanken Grotesk = body, Space Mono = eyebrows
	 * and labels; consumed via the --am-font-* tokens in the CBD skin.
	 */
	wp_enqueue_style(
		'affiliate-master-fonts',
		'https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800&family=Hanken+Grotesk:wght@400;500;600;700&family=Space+Mono&display=swap',
		array(),
		null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google versions the URL itself; a WP param would only bust its cache.
	);

	/*
	 * style.css now depends on BOTH the parent and the legacy product
	 * stylesheet: since Phase 9 it carries the comprehensive design
	 * system ([DOC-155]), and loading last means its refinements of the
	 * older product rules win the cascade without editing that file.
	 */
	wp_enqueue_style(
		'affiliate-master-style',
		get_stylesheet_directory_uri() . '/style.css',
		array( $parent_style, 'affiliate-master-product-styles', 'affiliate-master-fonts' ),
		wp_get_theme()->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'affiliate_master_enqueue_styles' );

/**
 * [CBD-03] CBD Green Labs niche configuration.
 *
 * Instance data flows through the template's filter hooks (per this
 * repo's CLAUDE.md: filters, never hardcoded shared code). Category
 * archive links will 404 until the CBD product-type terms are seeded
 * from the real feed — expected during the build (brief §7).
 */
function cbd_home_categories() {
	// Slugs match the real terms the [CBD-09] import rules create
	// (Oil & Tincture -> oil-tincture, Topical -> topical); the labels
	// stay in friendly plural marketing voice.
	return array(
		array(
			'num'   => '01',
			'label' => __( 'Oils & Tinctures', 'affiliate-master' ),
			'desc'  => __( 'Daily drops for steady balance →', 'affiliate-master' ),
			'url'   => home_url( '/product-type/oil-tincture/' ),
		),
		array(
			'num'   => '02',
			'label' => __( 'Gummies', 'affiliate-master' ),
			'desc'  => __( 'Calm & sleep, one chew at a time →', 'affiliate-master' ),
			'url'   => home_url( '/product-type/gummies/' ),
		),
		array(
			'num'   => '03',
			'label' => __( 'Topicals', 'affiliate-master' ),
			'desc'  => __( 'Balms for where it aches →', 'affiliate-master' ),
			'url'   => home_url( '/product-type/topical/' ),
		),
	);
}
add_filter( 'affiliate_master_home_categories', 'cbd_home_categories' );

// The die-cast scale pills have no CBD equivalent; the strength
// dimension becomes a catalog facet when the feed lands (brief §7).
add_filter( 'affiliate_master_home_scales', '__return_empty_array' );

/**
 * [CBD-09] CBD product-type detection rules for the importer.
 *
 * Replaces the die-cast table wholesale (the filter's designed use —
 * see detect_product_type in the importer). ORDER IS LOAD-BEARING:
 * first matching rule wins, so specific categories sit above generic
 * ones — "CBD Oil Dog Treats" must land in Pet before "oil" can pull
 * it into Oil & Tincture, and "Vape Cartridge" must beat any later
 * keyword. Term slugs derive from the names (Pre-Roll -> pre-roll,
 * Oil & Tincture -> oil-tincture, Capsule & Tablet -> capsule-tablet).
 *
 * @return array Term name => title keywords, in priority order.
 */
function cbd_product_type_rules() {
	return array(
		'Pet'              => array( 'for dogs', 'for cats', 'for pets', 'pet cbd', 'canine', 'dog treat', 'cat treat' ),
		'Pre-Roll'         => array( 'pre-roll', 'preroll', 'joint', 'blunt' ),
		'Flower'           => array( 'flower', 'bud', 'eighth', 'ounce', 'indica', 'sativa', 'hybrid' ),
		'Vape'             => array( 'vape', 'cartridge', 'cart', 'disposable', 'pod', '510' ),
		'Concentrate'      => array( 'dab', 'wax', 'rosin', 'resin', 'badder', 'shatter', 'crumble', 'concentrate' ),
		'Gummies'          => array( 'gummies', 'gummy' ),
		'Edible'           => array( 'chocolate', 'cookie', 'waffle', 'candy', 'brownie', 'honey', 'caramel', 'syrup', 'cereal' ),
		'Beverage'         => array( 'drink', 'beverage', 'seltzer', 'soda', 'coffee', 'tea', 'lemonade', 'shot' ),
		'Oil & Tincture'   => array( 'tincture', 'oil', 'drops', 'mct', 'liquid' ),
		'Topical'          => array( 'cream', 'balm', 'salve', 'lotion', 'roll-on', 'topical', 'gel', 'rub', 'stick', 'patch', 'serum' ),
		'Capsule & Tablet' => array( 'capsule', 'softgel', 'tablet', 'pill', 'caplet' ),
		'Accessory'        => array( 't-shirt', 'shirt', 'gift card', 'hoodie', 'hat', 'grinder', 'tray', 'merch', 'apparel' ),
	);
}
add_filter( 'afpi_product_type_rules', 'cbd_product_type_rules' );

/**
 * Unmatched titles land in Other, not the die-cast default (Cars).
 *
 * @return string
 */
function cbd_product_type_default() {
	return 'Other';
}
add_filter( 'afpi_product_type_default', 'cbd_product_type_default' );

/**
 * FDA statement — required footer compliance line for a CBD site
 * (renders via the [CBD-05] hook in footer.php, next to the FTC
 * affiliate disclosure).
 *
 * @param array $lines Compliance lines queued so far.
 * @return array
 */
function cbd_footer_compliance( $lines ) {
	$lines[] = __( 'These statements have not been evaluated by the FDA. Products featured here are not intended to diagnose, treat, cure, or prevent any disease.', 'affiliate-master' );
	return $lines;
}
add_filter( 'affiliate_master_footer_compliance', 'cbd_footer_compliance' );

/**
 * [DOC-164] Theme supports, nav menus and image sizes.
 *
 * Runs at after_setup_theme priority 11 — AFTER Astra's own setup —
 * so the checks below can see what the parent already registered.
 *
 * Several add_theme_support() calls duplicate what Astra registers
 * (title-tag, post-thumbnails, custom-logo, html5). That is
 * deliberate belt-and-braces: the supports are hard requirements of
 * THIS theme's templates (header.php prints no <title>; the product
 * grid needs thumbnails), so the child declares its own dependencies
 * instead of silently leaning on parent internals that an Astra
 * update could reshuffle. add_theme_support() is idempotent — the
 * duplicates cost nothing.
 */
function affiliate_master_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'custom-logo' );
	add_theme_support( 'customize-selective-refresh-widgets' );
	add_theme_support(
		'html5',
		array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' )
	);

	/*
	 * Nav menus: Astra already registers 'primary' — re-registering it
	 * would clobber Astra's label and confuse the Menus screen, so the
	 * child only adds it on a hypothetical parent that didn't. 'footer'
	 * is ours and always registered (rendered by footer.php,
	 * [DOC-171]).
	 */
	$menus = array( 'footer' => __( 'Footer Menu', 'affiliate-master' ) );
	if ( ! array_key_exists( 'primary', get_registered_nav_menus() ) ) {
		$menus['primary'] = __( 'Primary Menu', 'affiliate-master' );
	}
	register_nav_menus( $menus );

	/*
	 * [DOC-165] 600x600 hard-cropped product thumb: product images are
	 * square-ish catalog shots, and one guaranteed-square size means
	 * grids never jiggle from mixed aspect ratios. 600px covers a
	 * 300px card at 2x DPR.
	 */
	add_image_size( 'affiliate-product-thumb', 600, 600, true );
}
add_action( 'after_setup_theme', 'affiliate_master_setup', 11 );

/**
 * [DOC-166] Suppress Astra's automatic page title on single products.
 *
 * The single-affiliate_product.php template renders its own <h1> inside the
 * product header ([DOC-009]); without this filter Astra prints a
 * second title above the template — two H1s on every product page,
 * which is both visually broken and an SEO smell.
 *
 * @param bool $enabled Whether Astra should output the title.
 * @return bool False on single affiliate products.
 */
function affiliate_master_remove_product_title( $enabled ) {
	if ( is_singular( 'affiliate_product' ) ) {
		return false;
	}

	return $enabled;
}
add_filter( 'astra_the_title_enabled', 'affiliate_master_remove_product_title' );

/**
 * [DOC-167] Footer widget area, rendered by footer.php ([DOC-171]).
 *
 * One flexible area rather than the classic footer-1/2/3 trio: the
 * footer's flex layout ([DOC-161]) turns each widget into a column
 * automatically, so one area holds any number of columns without
 * per-site re-registration.
 */
function affiliate_master_widgets_init() {
	register_sidebar(
		array(
			'name'          => __( 'Footer Columns', 'affiliate-master' ),
			'id'            => 'footer-columns',
			'description'   => __( 'Widgets here become columns in the site footer.', 'affiliate-master' ),
			'before_widget' => '<div id="%1$s" class="am-footer-col widget %2$s">',
			'after_widget'  => '</div>',
			'before_title'  => '<h3 class="widget-title">',
			'after_title'   => '</h3>',
		)
	);
}
add_action( 'widgets_init', 'affiliate_master_widgets_init' );

/**
 * [DOC-003] Load the custom post type and ACF field group definitions.
 *
 * Both the CPT registration and the ACF field group are big enough,
 * and conceptually separate enough, that they get their own files
 * under /inc/ instead of living inline here:
 *
 *   - inc/cpt-affiliate-product.php  registers the "affiliate_product"
 *     post type. See [DOC-004]-[DOC-006].
 *   - inc/acf-fields-affiliate-product.php registers the matching ACF
 *     field group in PHP (not via the ACF UI), so the fields are
 *     version-controlled alongside the theme instead of living only
 *     in the database. See [DOC-007]-[DOC-008].
 *
 * require_once (not include) is used because both files are required
 * for the theme to function correctly — if either is missing, we want
 * a fatal error immediately rather than a silently broken site.
 */
require_once get_stylesheet_directory() . '/inc/cpt-affiliate-product.php';
require_once get_stylesheet_directory() . '/inc/acf-fields-affiliate-product.php';
require_once get_stylesheet_directory() . '/inc/seed-taxonomy-terms.php';

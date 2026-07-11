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
function cbd_home_categories( $categories ) {
	/*
	 * [CBD-10] The homepage now builds this list dynamically from the
	 * product-type terms (front-page.php); this filter only swaps the
	 * singular term names ([CBD-09] rules) for plural marketing labels
	 * on the category boxes. Unmapped names pass through untouched.
	 */
	$labels = array(
		'Oil & Tincture'   => __( 'Oils & Tinctures', 'affiliate-master' ),
		'Topical'          => __( 'Topicals', 'affiliate-master' ),
		'Vape'             => __( 'Vapes', 'affiliate-master' ),
		'Edible'           => __( 'Edibles', 'affiliate-master' ),
		'Beverage'         => __( 'Beverages', 'affiliate-master' ),
		'Pre-Roll'         => __( 'Pre-Rolls', 'affiliate-master' ),
		'Concentrate'      => __( 'Concentrates', 'affiliate-master' ),
		'Capsule & Tablet' => __( 'Capsules & Tablets', 'affiliate-master' ),
		'Accessory'        => __( 'Accessories', 'affiliate-master' ),
	);

	foreach ( $categories as &$category ) {
		if ( isset( $labels[ $category['label'] ] ) ) {
			$category['label'] = $labels[ $category['label'] ];
		}
	}

	return $categories;
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
 * [CBD-13] Product-type category list, shared by the homepage boxes
 * and the single product page's "keep shopping" links.
 *
 * Built dynamically from the taxonomy (hide_empty, busiest first) and
 * passed through affiliate_master_home_categories so display-label
 * overrides ([CBD-10] pluralization) apply everywhere the list shows.
 *
 * @return array[] Each: label, count, url.
 */
function affiliate_master_get_product_type_categories() {
	$type_terms = get_terms(
		array(
			'taxonomy'   => 'product-type',
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
		)
	);

	$categories = array();
	if ( ! is_wp_error( $type_terms ) ) {
		foreach ( $type_terms as $type_term ) {
			$term_link = get_term_link( $type_term );
			if ( is_wp_error( $term_link ) ) {
				continue;
			}
			$categories[] = array(
				// Decoded: WP stores "&" in term names as "&amp;", which
				// would sail past the label map; esc_html re-encodes.
				'label' => wp_specialchars_decode( $type_term->name, ENT_QUOTES ),
				'count' => (int) $type_term->count,
				'url'   => $term_link,
			);
		}
	}

	return apply_filters( 'affiliate_master_home_categories', $categories );
}

/**
 * [CBD-11] CBD taxonomy set: retire the die-cast browse dimensions,
 * register the CBD ones ([DOC-205] hook).
 *
 * material stays for now (unused die-cast vocabulary, pending a
 * decision); brand and product-type carry over as-is.
 *
 * @param array $taxonomies slug => singular/plural label pairs.
 * @return array
 */
function cbd_product_taxonomies( $taxonomies ) {
	unset( $taxonomies['scale'], $taxonomies['vehicle-make'] );

	$taxonomies['cannabinoid'] = array(
		'singular' => __( 'Cannabinoid', 'affiliate-master' ),
		'plural'   => __( 'Cannabinoids', 'affiliate-master' ),
	);
	$taxonomies['strength']    = array(
		'singular' => __( 'Strength', 'affiliate-master' ),
		'plural'   => __( 'Strengths', 'affiliate-master' ),
	);

	return $taxonomies;
}
add_filter( 'affiliate_master_product_taxonomies', 'cbd_product_taxonomies' );

/**
 * Cannabinoid from a product title — first matching rule wins.
 *
 * Tolerant patterns (the feed writes "Delta 8", "delta-8" and "D8"
 * interchangeably); \bcbd\b sits LAST among the anchored rules so
 * "CBD + CBG Oil" style titles land on the more specific cannabinoid,
 * and an unanchored cbd catches brand-compound titles ("PlusCBD
 * Softgels") the word boundary can't see.
 *
 * @param string $title Product title.
 * @return string Term name, '' when nothing matches.
 */
function cbd_detect_cannabinoid( $title ) {
	$rules = array(
		'THCA'     => '/\bthc-?a\b/i',
		'Delta-8'  => '/\b(?:delta[\s-]?8|d8)\b/i',
		'Delta-9'  => '/\b(?:delta[\s-]?9|d9)\b/i',
		'Delta-10' => '/\b(?:delta[\s-]?10|d10)\b/i',
		'THC-P'    => '/\bthc[\s-]?p\b/i',
		'THC-O'    => '/\bthc[\s-]?o\b/i',
		'HHC'      => '/\bhhc\b/i',
		'CBG'      => '/\bcbg\b/i',
		'CBN'      => '/\bcbn\b/i',
		'CBD'      => '/\bcbd\b/i',
	);

	foreach ( $rules as $term => $pattern ) {
		if ( preg_match( $pattern, $title ) ) {
			return $term;
		}
	}

	return false !== stripos( $title, 'cbd' ) ? 'CBD' : '';
}

/**
 * Strength bucket from the mg value in a product title.
 *
 * The LARGEST mg wins when a title carries several ("150mg, 600mg"
 * variant listings, per-piece + total pairs) — the biggest number is
 * the total/strongest option, which is what a strength facet should
 * sort by. No mg (flower grams, bundles) = no term. 1000 exactly
 * lands in 500-1000mg.
 *
 * @param string $title Product title.
 * @return string Term name, '' when the title has no mg value.
 */
function cbd_detect_strength( $title ) {
	if ( ! preg_match_all( '/(\d[\d,]*(?:\.\d+)?)\s*mg\b/i', $title, $matches ) ) {
		return '';
	}

	$max = 0.0;
	foreach ( $matches[1] as $value ) {
		$max = max( $max, (float) str_replace( ',', '', $value ) );
	}

	if ( $max <= 0 ) {
		return '';
	}
	if ( $max < 500 ) {
		return 'Under 500mg';
	}
	return $max <= 1000 ? '500-1000mg' : '1000mg+';
}

/**
 * Wire both detectors into the importer ([DOC-206]).
 *
 * @param array $detectors taxonomy => callable map.
 * @return array
 */
function cbd_title_term_detectors( $detectors ) {
	$detectors['cannabinoid'] = 'cbd_detect_cannabinoid';
	$detectors['strength']    = 'cbd_detect_strength';
	return $detectors;
}
add_filter( 'afpi_title_term_detectors', 'cbd_title_term_detectors' );

/**
 * Seed vocabulary: the three strength buckets need explicit slugs
 * ("1000mg+" would auto-slugify to "1000mg", losing the "plus");
 * cannabinoid terms self-create on import with clean slugs. The
 * die-cast vehicle-make list is dropped (taxonomy retired above).
 *
 * @param array $terms taxonomy => term list ([DOC-111] shape).
 * @return array
 */
function cbd_seed_terms( $terms ) {
	unset( $terms['vehicle-make'] );

	$terms['strength'] = array(
		'Under 500mg' => 'under-500mg',
		'500-1000mg'  => '500-1000mg',
		'1000mg+'     => '1000mg-plus',
	);

	return $terms;
}
add_filter( 'affiliate_master_seed_terms', 'cbd_seed_terms' );

/**
 * Catalog facets: refinement dimensions for a CBD shopper. scale and
 * material leave the panel; on term-locked archives the plugin drops
 * the locked taxonomy from this map itself ([DOC-207]).
 *
 * @return array URL param key => taxonomy name.
 */
function cbd_filter_taxonomies() {
	return array(
		'type'        => 'product-type',
		'cannabinoid' => 'cannabinoid',
		'strength'    => 'strength',
		'brand'       => 'brand',
	);
}
add_filter( 'afpf_filter_taxonomies', 'cbd_filter_taxonomies' );

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
// [DOC-210] Native GA4/Search Console integration (replaces Site Kit).
require_once get_stylesheet_directory() . '/inc/site-analytics.php';

<?php
/**
 * [DOC-004 / DOC-005 / DOC-006] Register the "affiliate_product" custom post type.
 *
 * This file's only responsibility is registering the post type used to
 * store each affiliate product. It is loaded from functions.php
 * (see [DOC-003]) via require_once, so it always runs on every page
 * load, but the actual registration happens on the 'init' hook below
 * because register_post_type() must not be called before WordPress
 * has finished bootstrapping (query vars, rewrite rules, l10n, etc.
 * are not ready before 'init').
 *
 * @package Affiliate_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the affiliate_product post type.
 */
function affiliate_master_register_cpt() {

	/**
	 * [DOC-004] Admin-facing labels.
	 *
	 * WordPress uses these strings throughout wp-admin: the CPT menu
	 * item, the "Add New" button, list table headings, etc. Writing
	 * out the full label set (rather than letting WordPress guess from
	 * just "name"/"singular_name") gives editors a coherent admin
	 * experience and avoids the generic "Post"/"Posts" fallback text
	 * that shows up in several screens if labels are left incomplete.
	 */
	$labels = array(
		'name'                  => _x( 'Affiliate Products', 'Post type general name', 'affiliate-master' ),
		'singular_name'         => _x( 'Affiliate Product', 'Post type singular name', 'affiliate-master' ),
		'menu_name'             => _x( 'Affiliate Products', 'Admin Menu text', 'affiliate-master' ),
		'name_admin_bar'        => _x( 'Affiliate Product', 'Add New on Toolbar', 'affiliate-master' ),
		'add_new'               => __( 'Add New', 'affiliate-master' ),
		'add_new_item'          => __( 'Add New Affiliate Product', 'affiliate-master' ),
		'new_item'              => __( 'New Affiliate Product', 'affiliate-master' ),
		'edit_item'             => __( 'Edit Affiliate Product', 'affiliate-master' ),
		'view_item'             => __( 'View Affiliate Product', 'affiliate-master' ),
		'view_items'            => __( 'View Affiliate Products', 'affiliate-master' ),
		'all_items'             => __( 'All Affiliate Products', 'affiliate-master' ),
		'search_items'          => __( 'Search Affiliate Products', 'affiliate-master' ),
		'parent_item_colon'     => __( 'Parent Affiliate Products:', 'affiliate-master' ),
		'not_found'             => __( 'No affiliate products found.', 'affiliate-master' ),
		'not_found_in_trash'    => __( 'No affiliate products found in Trash.', 'affiliate-master' ),
		'featured_image'        => __( 'Product Image', 'affiliate-master' ),
		'set_featured_image'    => __( 'Set product image', 'affiliate-master' ),
		'remove_featured_image' => __( 'Remove product image', 'affiliate-master' ),
		'use_featured_image'    => __( 'Use as product image', 'affiliate-master' ),
		'archives'              => __( 'Affiliate Product Archives', 'affiliate-master' ),
		'insert_into_item'      => __( 'Insert into product', 'affiliate-master' ),
		'uploaded_to_this_item' => __( 'Uploaded to this product', 'affiliate-master' ),
		'filter_items_list'     => __( 'Filter affiliate products list', 'affiliate-master' ),
		'items_list_navigation' => __( 'Affiliate products list navigation', 'affiliate-master' ),
		'items_list'            => __( 'Affiliate products list', 'affiliate-master' ),
	);

	/**
	 * [DOC-005] Registration arguments, chosen for SEO and discoverability.
	 *
	 * Key decisions and why:
	 *
	 * - 'public' => true
	 *     Master switch that makes the post type visible on the front
	 *     end, queryable, and shown in admin. A "false" here would
	 *     silently make every product invisible to Google.
	 *
	 * - 'publicly_queryable' => true
	 *     Confirms front-end URL queries (e.g. /products/some-product/)
	 *     actually resolve, independent of 'public'. Belt-and-suspenders
	 *     for SEO — this is what lets search engines and users load the
	 *     single product page at all.
	 *
	 * - 'show_ui' / 'show_in_menu' / 'show_in_admin_bar' / 'show_in_nav_menus' => true
	 *     Gives editors a normal wp-admin experience: a menu item, list
	 *     table, "+ New" in the admin bar, and the ability to add
	 *     products to nav menus.
	 *
	 * - 'show_in_rest' => true
	 *     Exposes the post type over the WP REST API and enables the
	 *     block editor. This also matters for SEO plugins (Rank Math,
	 *     Yoast, etc.) which rely on REST availability to inject their
	 *     meta boxes and generate sitemap entries for custom post types.
	 *
	 * - 'has_archive' => true
	 *     Creates an automatic /products/ archive/listing page, which
	 *     gives search engines a natural hub page that links out to
	 *     every individual product — good internal linking structure.
	 *
	 * - 'exclude_from_search' => false
	 *     Explicitly (not just by default) keeps products included in
	 *     WordPress's own search results.
	 *
	 * - 'query_var' => true
	 *     Allows querying products via ?affiliate_product=slug and via
	 *     WP_Query( array( 'affiliate_product' => 'slug' ) ), which
	 *     related-products/shortcode features will likely need later.
	 *
	 * - 'capability_type' => 'post'
	 *     Reuses the standard post capabilities (edit_posts, etc.) so
	 *     existing Author/Editor/Admin roles can manage products
	 *     without any extra capability mapping work.
	 *
	 * - 'hierarchical' => false
	 *     Products are flat items (like Posts), not parent/child pages
	 *     (like Pages) — there's no meaningful parent-product concept.
	 *
	 * - 'menu_icon' => dashicons-cart
	 *     Purely a UX nicety so the CPT is easy to spot in the admin
	 *     sidebar among other menu items.
	 *
	 * - 'supports' => title, editor, thumbnail, excerpt, custom-fields
	 *     - title:    the product name.
	 *     - editor:   a free-text review/description body, useful for
	 *                 unique on-page content (thin/duplicate content is
	 *                 a common SEO problem on affiliate sites, so giving
	 *                 editors a real content area matters).
	 *     - thumbnail: powers the fallback OG/Twitter share image many
	 *                 SEO plugins auto-pull from the featured image.
	 *     - excerpt:  usable as a concise meta-description source.
	 *     - custom-fields: ensures the meta box API / ACF integration
	 *                 has the hooks it expects, even though our fields
	 *                 are managed by ACF rather than raw meta boxes.
	 *
	 * - 'can_export' => true
	 *     Lets products be included in WordPress's built-in
	 *     Tools > Export, for backups/migration.
	 */
	$args = array(
		'labels'              => $labels,
		'description'         => __( 'Individual affiliate products displayed with a Buy Now call-to-action linking to the merchant.', 'affiliate-master' ),
		'public'              => true,
		'publicly_queryable'  => true,
		'show_ui'             => true,
		'show_in_menu'        => true,
		'show_in_admin_bar'   => true,
		'show_in_nav_menus'   => true,
		'show_in_rest'        => true,
		'query_var'           => true,
		'capability_type'     => 'post',
		'has_archive'         => true,
		'hierarchical'        => false,
		'exclude_from_search' => false,
		'can_export'          => true,
		'delete_with_user'    => false,
		'menu_position'       => 20,
		'menu_icon'           => 'dashicons-cart',
		'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),

		/**
		 * [DOC-006] Rewrite / permalink structure.
		 *
		 * 'slug' => 'products' produces clean URLs like
		 * /products/some-product-name/ instead of the default
		 * /affiliate_product/some-product-name/. Short, readable,
		 * keyword-relevant URLs are a well-established on-page SEO
		 * signal and are also just nicer for users to read/share.
		 *
		 * 'with_front' => false prevents WordPress from prefixing the
		 * slug with the site's front-page base (relevant if the
		 * homepage is ever set to a static page under a subpath),
		 * keeping URLs at the clean /products/... form regardless of
		 * front-page configuration.
		 */
		'rewrite'             => array(
			'slug'       => 'products',
			'with_front' => false,
		),
	);

	register_post_type( 'affiliate_product', $args );
}
add_action( 'init', 'affiliate_master_register_cpt' );

/**
 * [DOC-097] Build the full label set for one product taxonomy.
 *
 * Same reasoning as the CPT labels ([DOC-004]): WordPress falls back
 * to generic "Category"/"Categories" text in a dozen admin screens if
 * labels are incomplete, which gets confusing fast with five
 * taxonomies side by side. Generated from the singular/plural pair so
 * five hand-written 15-line label arrays don't drift apart.
 *
 * @param string $singular Singular label, e.g. "Brand".
 * @param string $plural   Plural label, e.g. "Brands".
 * @return array Labels for register_taxonomy().
 */
function affiliate_master_taxonomy_labels( $singular, $plural ) {
	return array(
		'name'              => $plural,
		'singular_name'     => $singular,
		'menu_name'         => $plural,
		/* translators: %s: taxonomy plural name. */
		'all_items'         => sprintf( __( 'All %s', 'affiliate-master' ), $plural ),
		/* translators: %s: taxonomy singular name. */
		'edit_item'         => sprintf( __( 'Edit %s', 'affiliate-master' ), $singular ),
		/* translators: %s: taxonomy singular name. */
		'view_item'         => sprintf( __( 'View %s', 'affiliate-master' ), $singular ),
		/* translators: %s: taxonomy singular name. */
		'update_item'       => sprintf( __( 'Update %s', 'affiliate-master' ), $singular ),
		/* translators: %s: taxonomy singular name. */
		'add_new_item'      => sprintf( __( 'Add New %s', 'affiliate-master' ), $singular ),
		/* translators: %s: taxonomy singular name. */
		'new_item_name'     => sprintf( __( 'New %s Name', 'affiliate-master' ), $singular ),
		/* translators: %s: taxonomy singular name. */
		'parent_item'       => sprintf( __( 'Parent %s', 'affiliate-master' ), $singular ),
		/* translators: %s: taxonomy singular name. */
		'parent_item_colon' => sprintf( __( 'Parent %s:', 'affiliate-master' ), $singular ),
		/* translators: %s: taxonomy plural name. */
		'search_items'      => sprintf( __( 'Search %s', 'affiliate-master' ), $plural ),
		/* translators: %s: taxonomy plural name. */
		'not_found'         => sprintf( __( 'No %s found.', 'affiliate-master' ), strtolower( $plural ) ),
		/* translators: %s: taxonomy plural name. */
		'back_to_items'     => sprintf( __( '← Back to %s', 'affiliate-master' ), $plural ),
	);
}

/**
 * [DOC-098] Register the five product taxonomies.
 *
 * These are taxonomies (not more ACF fields) because they exist for
 * ARCHIVES: every term gets its own crawlable listing page
 * (/brand/motormax/, /scale/1-18/), and those pages are the SEO
 * backbone of a niche affiliate site — each one targets a query
 * shoppers actually search for and internally links every matching
 * product. ACF fields display data on a single product; taxonomies
 * group products into landing pages. The importer fills brand/scale/
 * product-type automatically ([DOC-108]); the rest are curated by
 * hand as the niche develops.
 *
 * A note on archives, because register_taxonomy() has no
 * 'has_archive' argument (that arg belongs to post types): for
 * taxonomies, term archive pages come from 'public' => true plus a
 * rewrite slug — which every entry below has. That combination IS the
 * archive switch.
 *
 * All five share the same behavioural args (see [DOC-107]); only
 * identity differs, so each taxonomy is one compact config entry.
 * Hooked on init AFTER the CPT registration above so the object type
 * they attach to always exists first.
 */
function affiliate_master_register_taxonomies() {

	$taxonomies = array(

		/*
		 * [DOC-099] product-type: the top-level browse dimension (Cars,
		 * Aircraft, Trucks, Military, Emergency Vehicles, TV & Movie).
		 * This is the taxonomy the main navigation is built from, and
		 * the importer maps the feed's merchant_category column into it.
		 */
		'product-type' => array(
			'singular' => __( 'Product Type', 'affiliate-master' ),
			'plural'   => __( 'Product Types', 'affiliate-master' ),
		),

		/*
		 * [DOC-100] brand: the model MANUFACTURER (Motormax, Jada,
		 * Maisto, GeminiJets…) — collectors are brand-loyal and search
		 * "maisto 1/18" style queries, so brand archives rank. Distinct
		 * from vehicle-make below: Maisto (brand) makes a Ford (make).
		 * Mirrors the ACF brand field the importer already fills; the
		 * taxonomy adds the archive page the meta field can't provide.
		 */
		'brand'        => array(
			'singular' => __( 'Brand', 'affiliate-master' ),
			'plural'   => __( 'Brands', 'affiliate-master' ),
		),

		/*
		 * [DOC-101] scale: 1/18, 1/24, 1/64… — THE defining attribute
		 * of die cast collecting; serious collectors buy in one scale.
		 * Term names keep the slash ("1/18"); WordPress slugifies the
		 * URL to /scale/1-18/ automatically, giving clean archive URLs
		 * without mangling the display name.
		 */
		'scale'        => array(
			'singular' => __( 'Scale', 'affiliate-master' ),
			'plural'   => __( 'Scales', 'affiliate-master' ),
		),

		/*
		 * [DOC-103] material: Die Cast Metal, Plastic Snap-Fit, Resin —
		 * a quality/price signal (resin = premium display, snap-fit =
		 * entry level) that doubles as a filter dimension.
		 */
		'material'     => array(
			'singular' => __( 'Material', 'affiliate-master' ),
			'plural'   => __( 'Materials', 'affiliate-master' ),
		),

		/*
		 * [DOC-105] vehicle-make: the REAL vehicle's manufacturer
		 * (Ford, Chevrolet, Lamborghini, Boeing, Airbus…) — the other
		 * half of the brand distinction in [DOC-100]. "Lamborghini
		 * diecast" searchers don't care who made the model; this
		 * taxonomy gives that query a landing page.
		 */
		'vehicle-make' => array(
			'singular' => __( 'Vehicle Make', 'affiliate-master' ),
			'plural'   => __( 'Vehicle Makes', 'affiliate-master' ),
		),
	);

	foreach ( $taxonomies as $slug => $tax ) {
		/*
		 * [DOC-107] Shared behavioural args, and why:
		 *
		 * - hierarchical => true: category-style checkboxes in admin
		 *   (no free-tagging typos across thousands of imported
		 *   products) and room for parent/child terms later
		 *   (Cars > Muscle Cars).
		 * - public/publicly_queryable => true: front-end term archive
		 *   pages exist — the whole point ([DOC-098]).
		 * - show_in_rest => true: block editor metaboxes + REST
		 *   exposure, consistent with the CPT and ACF group.
		 * - show_in_nav_menus => true: term pages can be added to menus
		 *   from Appearance > Menus, which is how the niche nav gets
		 *   built on each cloned site.
		 * - show_admin_column => true: each taxonomy gets a column in
		 *   the product list table — with bulk-imported catalogues,
		 *   spotting "products missing a brand" at a glance matters.
		 * - rewrite: the taxonomy slug verbatim, with_front false —
		 *   same clean-URL reasoning as the CPT ([DOC-006]), giving
		 *   /brand/motormax/, /scale/1-18/ etc.
		 */
		register_taxonomy(
			$slug,
			'affiliate_product',
			array(
				'labels'             => affiliate_master_taxonomy_labels( $tax['singular'], $tax['plural'] ),
				'hierarchical'       => true,
				'public'             => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_admin_column'  => true,
				'show_in_nav_menus'  => true,
				'show_in_rest'       => true,
				'query_var'          => true,
				'rewrite'            => array(
					'slug'       => $slug,
					'with_front' => false,
				),
			)
		);
	}
}
add_action( 'init', 'affiliate_master_register_taxonomies', 11 );

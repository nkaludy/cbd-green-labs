<?php
/**
 * [DOC-007 / DOC-008] Register the ACF field group for "affiliate_product".
 *
 * These fields are registered here in PHP, via ACF's Local Fields API
 * (acf_add_local_field_group), rather than being created by hand in
 * the ACF admin UI. That choice is deliberate:
 *
 *   - The field definitions live in version control alongside the
 *     theme, so a fresh install of this theme has the exact same
 *     fields immediately — no manual "recreate the field group in
 *     the ACF UI" step for every new site.
 *   - There is nothing to import/export or keep in sync between
 *     environments (dev/staging/production); the code IS the config.
 *
 * The trade-off is that fields defined this way are read-only in the
 * ACF admin UI (a banner reminds editors of this) — that's expected
 * and correct for this project: field structure changes should go
 * through this file, not through a database-only UI edit that could
 * be lost on the next deploy.
 *
 * @package Affiliate_Master
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the field group, guarded by function_exists() so the theme
 * never fatals if Advanced Custom Fields is deactivated — the site
 * would simply lose the extra product fields instead of breaking.
 */
function affiliate_master_register_acf_fields() {

	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	/**
	 * [DOC-008] Field definitions.
	 *
	 * All nine fields requested for the product, in the order editors
	 * will see them in wp-admin. Field types were kept deliberately
	 * simple (url/text/textarea only) so this field group works on
	 * both ACF free and ACF PRO — none of these fields depend on
	 * PRO-only field types like Repeater or Gallery.
	 *
	 *   - affiliate_url    (url)      The outbound merchant link used
	 *                                 by the "Buy Now" button on the
	 *                                 single template. See [DOC-010].
	 *   - sku              (text)     Merchant/manufacturer SKU or
	 *                                 model number, useful for exact
	 *                                 product matching/search.
	 *   - main_image       (text)     URL of the primary product image.
	 *                                 Stored as a plain URL string
	 *                                 (rather than the WP Media
	 *                                 Library) so product data can be
	 *                                 bulk-imported/synced from a
	 *                                 merchant feed without uploading
	 *                                 media into WordPress.
	 *   - gallery_images   (textarea) Additional image URLs, one per
	 *                                 line. A plain textarea (rather
	 *                                 than the PRO-only Gallery field)
	 *                                 keeps this compatible with ACF
	 *                                 free and easy to populate via
	 *                                 import scripts.
	 *   - brand            (text)     Manufacturer/brand name.
	 *   - scale            (text)     Product scale (e.g. "1:24"),
	 *                                 relevant for scale-model/diecast
	 *                                 style affiliate niches.
	 *   - features         (textarea) Free-text bullet list of product
	 *                                 features/specs.
	 *   - affiliate_network (text)    Which affiliate network/program
	 *                                 the link belongs to (e.g. Amazon
	 *                                 Associates, ShareASale), useful
	 *                                 for reporting and for disclosure
	 *                                 text on the front end.
	 *   - price            (text)     Display price as a formatted
	 *                                 string (e.g. "$24.99"), since
	 *                                 affiliate prices are informational
	 *                                 only and often include currency
	 *                                 symbols or "from" prefixes rather
	 *                                 than being a pure number.
	 */
	acf_add_local_field_group(
		array(
			'key'                   => 'group_affiliate_master_product_fields',
			'title'                 => __( 'Affiliate Product Details', 'affiliate-master' ),
			'fields'                => array(
				array(
					'key'          => 'field_am_affiliate_url',
					'label'        => __( 'Affiliate URL', 'affiliate-master' ),
					'name'         => 'affiliate_url',
					'type'         => 'url',
					'instructions' => __( 'The outbound merchant link. Used by the Buy Now button on the front end.', 'affiliate-master' ),
					'required'     => 1,
				),
				array(
					'key'          => 'field_am_sku',
					'label'        => __( 'SKU', 'affiliate-master' ),
					'name'         => 'sku',
					'type'         => 'text',
					'instructions' => __( 'Merchant or manufacturer SKU / model number.', 'affiliate-master' ),
				),
				array(
					'key'          => 'field_am_main_image',
					'label'        => __( 'Main Image URL', 'affiliate-master' ),
					'name'         => 'main_image',
					'type'         => 'text',
					'instructions' => __( 'Full URL of the primary product image.', 'affiliate-master' ),
				),
				array(
					'key'          => 'field_am_gallery_images',
					'label'        => __( 'Gallery Images', 'affiliate-master' ),
					'name'         => 'gallery_images',
					'type'         => 'textarea',
					'instructions' => __( 'Additional image URLs, one per line.', 'affiliate-master' ),
					'rows'         => 4,
				),
				array(
					'key'   => 'field_am_brand',
					'label' => __( 'Brand', 'affiliate-master' ),
					'name'  => 'brand',
					'type'  => 'text',
				),
				array(
					'key'          => 'field_am_scale',
					'label'        => __( 'Scale', 'affiliate-master' ),
					'name'         => 'scale',
					'type'         => 'text',
					'instructions' => __( 'E.g. 1:24, 1:18.', 'affiliate-master' ),
				),
				array(
					'key'          => 'field_am_features',
					'label'        => __( 'Features', 'affiliate-master' ),
					'name'         => 'features',
					'type'         => 'textarea',
					'instructions' => __( 'One feature per line.', 'affiliate-master' ),
					'rows'         => 5,
				),
				array(
					'key'   => 'field_am_affiliate_network',
					'label' => __( 'Affiliate Network', 'affiliate-master' ),
					'name'  => 'affiliate_network',
					'type'  => 'text',
				),
				array(
					'key'          => 'field_am_price',
					'label'        => __( 'Price', 'affiliate-master' ),
					'name'         => 'price',
					'type'         => 'text',
					'instructions' => __( 'Display price as text, e.g. "$24.99".', 'affiliate-master' ),
				),
			),

			/**
			 * [DOC-007] Location rules: attach this field group only to
			 * the affiliate_product post type, so these fields never
			 * appear on regular Posts/Pages.
			 */
			'location'              => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'affiliate_product',
					),
				),
			),
			'menu_order'            => 0,
			'position'              => 'normal',
			'style'                 => 'default',
			'label_placement'       => 'top',
			'instruction_placement' => 'label',

			/**
			 * Expose these fields over the REST API, matching the CPT's
			 * own 'show_in_rest' => true (see [DOC-005]) so any future
			 * headless/REST consumer can read the full product record
			 * in one request.
			 */
			'show_in_rest'          => 1,
		)
	);
}
add_action( 'acf/init', 'affiliate_master_register_acf_fields' );

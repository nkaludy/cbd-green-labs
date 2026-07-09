<?php
/**
 * [DOC-027] Column-to-field mapping.
 *
 * This class is the single source of truth for which target fields exist
 * on an imported product, and it turns one raw CSV row plus a user-chosen
 * mapping into a clean, sanitized array of field values. Keeping the field
 * catalogue here (rather than hardcoding it into the admin dropdowns AND
 * the post creator) means the Import tab dropdowns, saved mapping
 * profiles, the Settings duplicate-field selector and the actual import
 * can never drift out of sync with each other.
 *
 * @package Affiliate_Product_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the importable target fields and applies a column mapping to a
 * parsed CSV row, sanitizing every value on the way in.
 */
class AFPI_Field_Mapper {

	/**
	 * [DOC-028] The catalogue of importable target fields.
	 *
	 * Keys are the storage names: 'post_title' / 'post_content' map to the
	 * WP post object, everything else is the exact ACF field name
	 * registered by the affiliate-master child theme (see the theme's
	 * inc/acf-fields-affiliate-product.php) — the names must match or
	 * update_field() would write orphan meta the front end never reads.
	 *
	 * Each entry declares a 'type' that drives sanitization in map_row():
	 *   - text      → sanitize_text_field (single line)
	 *   - url       → esc_url_raw
	 *   - multiline → sanitize_textarea_field (newlines preserved — the
	 *                 gallery_images and features ACF textareas are
	 *                 one-entry-per-line by convention)
	 *   - urls      → like multiline, but each line is validated as a URL
	 *   - html      → wp_kses_post, or wp_strip_all_tags when the
	 *                 "strip HTML" setting is on (affiliate feeds often
	 *                 ship descriptions full of merchant markup/tracking
	 *                 pixels that should never hit the front end verbatim)
	 *
	 * post_content is offered as a target even though it is not an ACF
	 * field because product descriptions are the main defence against
	 * thin-content SEO problems on affiliate sites — feeds that include a
	 * description column should be able to land it in the post body.
	 *
	 * The list is filterable ('afpi_target_fields') so a niche-site clone
	 * that adds an ACF field can expose it to the importer without editing
	 * this plugin.
	 *
	 * @return array[] field_name => array( 'label' => string, 'type' => string ).
	 */
	public static function get_target_fields() {
		$fields = array(
			'post_title'        => array(
				'label' => __( 'Product Title (post title)', 'affiliate-product-importer' ),
				'type'  => 'text',
			),
			'post_content'      => array(
				'label' => __( 'Description (post content)', 'affiliate-product-importer' ),
				'type'  => 'html',
			),
			'affiliate_url'     => array(
				'label' => __( 'Affiliate URL', 'affiliate-product-importer' ),
				'type'  => 'url',
			),
			'sku'               => array(
				'label' => __( 'SKU', 'affiliate-product-importer' ),
				'type'  => 'text',
			),
			'main_image'        => array(
				'label' => __( 'Main Image URL', 'affiliate-product-importer' ),
				'type'  => 'url',
			),
			'gallery_images'    => array(
				'label' => __( 'Gallery Image URLs', 'affiliate-product-importer' ),
				'type'  => 'urls',
			),
			'brand'             => array(
				'label' => __( 'Brand', 'affiliate-product-importer' ),
				'type'  => 'text',
			),
			'scale'             => array(
				'label' => __( 'Scale', 'affiliate-product-importer' ),
				'type'  => 'text',
			),
			'features'          => array(
				'label' => __( 'Features', 'affiliate-product-importer' ),
				'type'  => 'multiline',
			),
			'affiliate_network' => array(
				'label' => __( 'Affiliate Network', 'affiliate-product-importer' ),
				'type'  => 'text',
			),
			'price'             => array(
				'label' => __( 'Price', 'affiliate-product-importer' ),
				'type'  => 'text',
			),

			/*
			 * [DOC-109] The "category" taxonomy-only target was removed
			 * ([DOC-192]): product-type is now detected from the TITLE
			 * in the post creator, because AWIN's merchant_category
			 * ships brand names, not categories. Keeping the target in
			 * this catalogue would invite mapping a column into a
			 * control that silently does nothing. Old saved profiles
			 * that still contain a "category" key are harmless — the
			 * sanitizer below drops unknown targets on next save.
			 */
		);

		return apply_filters( 'afpi_target_fields', $fields );
	}

	/**
	 * [DOC-029] Sanitize a user-submitted mapping array.
	 *
	 * The mapping arrives from the browser as target_field => csv_column
	 * pairs. Everything client-side is untrusted, so this drops any target
	 * field that is not in the catalogue (a tampered request could
	 * otherwise write arbitrary meta keys) and any empty column choices
	 * (the "— skip this field —" dropdown option submits an empty string).
	 * CSV column names are kept verbatim apart from text sanitization,
	 * because they must exactly match the header strings the parser
	 * produced.
	 *
	 * @param array $raw_mapping Untrusted mapping from the request.
	 * @return array Clean mapping: known target field => CSV column name.
	 */
	public static function sanitize_mapping( $raw_mapping ) {
		$clean  = array();
		$fields = self::get_target_fields();

		if ( ! is_array( $raw_mapping ) ) {
			return $clean;
		}

		foreach ( $raw_mapping as $target => $column ) {
			$target = sanitize_key( $target );
			$column = sanitize_text_field( (string) $column );

			if ( '' === $column || ! isset( $fields[ $target ] ) ) {
				continue;
			}

			$clean[ $target ] = $column;
		}

		return $clean;
	}

	/**
	 * [DOC-030] Apply a mapping to one parsed CSV row.
	 *
	 * Takes the header-keyed row from AFPI_CSV_Parser and the clean
	 * mapping, and returns target_field => sanitized value. Missing
	 * columns yield no entry at all (rather than an empty string) so the
	 * post creator can distinguish "feed has no brand column" from "this
	 * product's brand cell is blank" — only mapped fields are ever written.
	 *
	 * Sanitization happens here, at the trust boundary between feed data
	 * and the database, instead of in the post creator: whatever new code
	 * path consumes mapped rows in the future gets safe values for free.
	 *
	 * @param array $row     Associative row (csv column => raw value).
	 * @param array $mapping Clean mapping from sanitize_mapping().
	 * @return array target field => sanitized value.
	 */
	public function map_row( $row, $mapping ) {
		$fields   = self::get_target_fields();
		$settings = afpi_get_settings();
		$mapped   = array();

		foreach ( $mapping as $target => $column ) {
			if ( ! isset( $fields[ $target ] ) || ! array_key_exists( $column, $row ) ) {
				continue;
			}

			$raw               = (string) $row[ $column ];
			$mapped[ $target ] = $this->sanitize_value( $raw, $fields[ $target ]['type'], $settings );
		}

		return $mapped;
	}

	/**
	 * [DOC-031] Sanitize one cell value according to its target field type.
	 *
	 * See [DOC-028] for the type table. Two details worth calling out:
	 *
	 *   - 'urls' validates line by line and silently drops lines that are
	 *     not valid URLs, because a single junk line in a gallery cell
	 *     (feeds love trailing "N/A") should not poison the whole gallery.
	 *   - 'html' honours the plugin's strip_html setting so site builders
	 *     can decide per site whether merchant description markup is kept
	 *     (kses-filtered) or flattened to plain text.
	 *
	 * @param string $value    Raw cell value.
	 * @param string $type     Field type from the catalogue.
	 * @param array  $settings Plugin settings (passed in to avoid re-reading per cell).
	 * @return string Sanitized value.
	 */
	private function sanitize_value( $value, $type, $settings ) {
		$value = trim( $value );

		switch ( $type ) {
			case 'url':
				return esc_url_raw( $value );

			case 'urls':
				$lines = preg_split( '/\r\n|\r|\n/', $value );
				$urls  = array();
				foreach ( $lines as $line ) {
					$url = esc_url_raw( trim( $line ) );
					if ( '' !== $url ) {
						$urls[] = $url;
					}
				}
				return implode( "\n", $urls );

			case 'multiline':
				return sanitize_textarea_field( $value );

			case 'html':
				if ( ! empty( $settings['strip_html'] ) ) {
					return sanitize_textarea_field( wp_strip_all_tags( $value ) );
				}
				return wp_kses_post( $value );

			case 'text':
			default:
				return sanitize_text_field( $value );
		}
	}
}

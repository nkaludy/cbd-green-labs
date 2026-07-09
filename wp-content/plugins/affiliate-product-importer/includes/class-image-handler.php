<?php
/**
 * [DOC-036] Remote image sideloading for imported products.
 *
 * Feed CSVs reference images on the merchant's CDN. Hotlinking those is
 * fragile (merchants rotate CDNs, block hotlinks, or kill URLs when
 * products retire) and bad for SEO (Google Images indexes the merchant's
 * domain, not ours). This class downloads each image into the site's own
 * media library with an SEO-friendly filename and alt text, so the site
 * owns its images permanently — which also matters when a site gets
 * flipped: the buyer receives self-contained media, not links into a
 * feed that will stop matching their account.
 *
 * @package Affiliate_Product_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Downloads remote image URLs into the media library, names them after
 * the product, sets alt text and attaches them to the product post.
 */
class AFPI_Image_Handler {

	/**
	 * [DOC-198] Signals that identify a feed's "no image" placeholder.
	 *
	 * AWIN's image proxy answers dead merchant images with a 302 to a
	 * tiny noimage.gif (959 bytes, md5 below, verified 2026-07-08)
	 * instead of a 404 — so "the download succeeded" does not mean
	 * "we got a product photo". These signals let the importer tell
	 * the difference and are consumed by is_placeholder_file() /
	 * is_placeholder_redirect(), which feed the _afpi_has_real_image
	 * meta flag ([DOC-199]) that a later browse-exclusion query reads.
	 *
	 * Filterable because every network invents its own placeholder:
	 * a clone on a different feed adjusts the md5/size/URL fragment
	 * from a small filter callback instead of forking the importer.
	 *
	 * @return array {
	 *     @type int      $max_bytes      Files at or under this size that
	 *                                    are GIFs count as placeholders.
	 *     @type string[] $md5s           Exact md5s of known placeholders.
	 *     @type string[] $url_substrings Case-insensitive fragments that
	 *                                    mark a redirect Location as a
	 *                                    placeholder.
	 * }
	 */
	public static function get_placeholder_signals() {
		return apply_filters(
			'afpi_placeholder_image_signals',
			array(
				'max_bytes'      => 2048,
				'md5s'           => array( '84fe74f622f163b924b962997adda0e1' ),
				'url_substrings' => array( 'noimage' ),
			)
		);
	}

	/**
	 * [DOC-198] Is this downloaded file a known placeholder image?
	 *
	 * Two signals, strongest first: an exact md5 match against known
	 * placeholder files, then the shape heuristic (a GIF at or under
	 * max_bytes — no real product photo is a sub-2KB gif). FAIL-SAFE
	 * BY DESIGN: any error, missing file, or doubt returns false
	 * ("looks real"), because wrongly hiding a sellable product costs
	 * more than wrongly showing a placeholder card.
	 *
	 * @param string $file Absolute path to a local image file.
	 * @return bool True only when confidently a placeholder.
	 */
	public static function is_placeholder_file( $file ) {
		try {
			$file = (string) $file;
			if ( '' === $file || ! @file_exists( $file ) ) {
				return false;
			}

			$signals = self::get_placeholder_signals();
			$size    = (int) @filesize( $file );

			if ( $size <= 0 ) {
				return false;
			}

			if ( ! empty( $signals['md5s'] ) && in_array( (string) @md5_file( $file ), (array) $signals['md5s'], true ) ) {
				return true;
			}

			if ( $size <= (int) $signals['max_bytes']
				&& function_exists( 'exif_imagetype' )
				&& IMAGETYPE_GIF === @exif_imagetype( $file ) ) {
				return true;
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- fail-safe: detection must never break an import.
			// Fall through to "looks real".
		}

		return false;
	}

	/**
	 * [DOC-198] Does this URL redirect to a known placeholder?
	 *
	 * One HEAD request with redirects disabled: a 3xx whose Location
	 * contains a placeholder fragment (e.g. "noimage") is the proxy
	 * admitting the image is dead. Used in --skip-images mode where
	 * no bytes are ever downloaded. Same fail-safe rule as
	 * is_placeholder_file(): any error, timeout, non-redirect, or
	 * ambiguity returns false ("looks real").
	 *
	 * @param string $url Remote image URL.
	 * @return bool True only when confidently a placeholder redirect.
	 */
	public static function is_placeholder_redirect( $url ) {
		try {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				return false;
			}

			$response = wp_remote_head(
				$url,
				array(
					'redirection' => 0,
					'timeout'     => 5,
				)
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 300 || $code >= 400 ) {
				return false;
			}

			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			$signals  = self::get_placeholder_signals();

			foreach ( (array) $signals['url_substrings'] as $needle ) {
				if ( '' !== (string) $needle && false !== stripos( $location, (string) $needle ) ) {
					return true;
				}
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- fail-safe: detection must never break an import.
			// Fall through to "looks real".
		}

		return false;
	}

	/**
	 * [DOC-037] Sideload one remote image and attach it to a post.
	 *
	 * The flow is download_url() → rename to an SEO filename →
	 * media_handle_sideload(). Using WordPress's own sideload pipeline
	 * (rather than a manual copy into uploads/) is deliberate: it gives us
	 * MIME validation, all registered thumbnail sizes, and correct
	 * attachment metadata for free — everything the theme and ShortPixel
	 * expect a "real" upload to have.
	 *
	 * SEO filename: the file is renamed to "{product-slug}.jpg" (or
	 * "{product-slug}-2.jpg" for the 2nd gallery image, etc.) before
	 * sideloading, because feed image URLs are junk like
	 * "81gkpZ0XwSL._AC_SL1500_.jpg" — a descriptive filename is a small
	 * but free image-SEO signal, and WordPress derives the attachment
	 * slug/URL from it. The original extension is kept when the URL has a
	 * recognisable image extension; otherwise .jpg is assumed (query-string
	 * CDN URLs often hide the extension, and media_handle_sideload will
	 * still reject the file if it is not really an image).
	 *
	 * Alt text is set to the product title — the title ("Bburago 1:24
	 * Ferrari F40") is exactly the accessible/SEO description of a
	 * product shot, and feeds provide nothing better.
	 *
	 * Failures return WP_Error instead of throwing or dying: a dead image
	 * URL should cost the row its image, not the import its progress. The
	 * caller records the error and moves on.
	 *
	 * @param string $image_url     Remote image URL from the CSV.
	 * @param int    $post_id       Product post to attach the image to.
	 * @param string $product_title Product title used for filename + alt text.
	 * @param int    $sequence      1 for the main image, 2+ for gallery images
	 *                              (appended to the filename to keep them unique).
	 * @return int|WP_Error Attachment ID, or WP_Error on failure.
	 */
	public function sideload_image( $image_url, $post_id, $product_title, $sequence = 1 ) {
		$image_url = esc_url_raw( trim( (string) $image_url ) );

		if ( '' === $image_url ) {
			return new WP_Error( 'afpi_no_image_url', __( 'No image URL provided.', 'affiliate-product-importer' ) );
		}

		// media_handle_sideload() and download_url() live in admin-only
		// files that are not loaded during AJAX requests by default.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp_file = download_url( $image_url, 30 );

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		/*
		 * [DOC-038] Build the SEO filename from the product title.
		 *
		 * sanitize_title() gives the same slug WordPress would use for the
		 * post itself, so image URL and product URL share keywords. The
		 * sideload pipeline handles collisions (wp_unique_filename), so two
		 * products with identical titles will not overwrite each other.
		 */
		$extension = strtolower( (string) pathinfo( (string) wp_parse_url( $image_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ), true ) ) {
			$extension = 'jpg';
		}

		$slug = sanitize_title( $product_title );
		if ( '' === $slug ) {
			$slug = 'affiliate-product-' . $post_id;
		}
		if ( $sequence > 1 ) {
			$slug .= '-' . $sequence;
		}

		$file_array = array(
			'name'     => $slug . '.' . $extension,
			'tmp_name' => $tmp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			// media_handle_sideload cleans up after itself on success, but
			// the temp file survives some failure paths — remove it so
			// failed imports don't litter the uploads temp dir.
			wp_delete_file( $tmp_file );
			return $attachment_id;
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $product_title ) );

		return $attachment_id;
	}

	/**
	 * [DOC-039] Import the main image and set it as the featured image.
	 *
	 * The featured image is what the theme's archive cards and the SEO
	 * plugins' OG/Twitter tags pull from, so the feed's main image maps to
	 * it directly. Returns the new *local* URL so the post creator can
	 * overwrite the main_image ACF field with it — after import the front
	 * end should never reference the merchant CDN again (see [DOC-036]).
	 *
	 * @param string $image_url     Remote main image URL.
	 * @param int    $post_id       Product post ID.
	 * @param string $product_title Product title for filename/alt.
	 * @return string|WP_Error Local attachment URL, or WP_Error on failure.
	 */
	public function import_main_image( $image_url, $post_id, $product_title ) {
		$attachment_id = $this->sideload_image( $image_url, $post_id, $product_title, 1 );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		set_post_thumbnail( $post_id, $attachment_id );

		/*
		 * [DOC-199] Flag whether this product got a REAL image or a feed
		 * placeholder. The sideloaded bytes are checked via the final
		 * attached file (same bytes download_url fetched — checking here
		 * instead of mid-sideload_image() keeps that method generic for
		 * gallery images, which are not flagged). '1' = real, '0' =
		 * placeholder; consumed by the browse-exclusion query in a later
		 * step. Fail-safe: is_placeholder_file() returns false on any
		 * doubt, so uncertainty always lands as '1'.
		 */
		$is_real = ! self::is_placeholder_file( (string) get_attached_file( $attachment_id ) );
		update_post_meta( $post_id, '_afpi_has_real_image', $is_real ? '1' : '0' );

		return (string) wp_get_attachment_url( $attachment_id );
	}

	/**
	 * [DOC-040] Import the gallery images.
	 *
	 * Takes the newline-separated URL list from the gallery_images CSV
	 * cell, sideloads each, and returns the local URLs (again newline-
	 * separated) for storage back into the gallery_images ACF textarea —
	 * same one-URL-per-line format the theme already renders, just served
	 * from our own media library.
	 *
	 * Per-image failures are collected, not fatal: a gallery of 5 with one
	 * dead URL should import 4 images, and the import row summary reports
	 * the one that failed. Numbering starts at 2 because the main image
	 * owns the base filename ([DOC-037]).
	 *
	 * @param string $urls_blob     Newline-separated remote image URLs.
	 * @param int    $post_id       Product post ID.
	 * @param string $product_title Product title for filenames/alt.
	 * @return array {
	 *     @type string   $urls   Newline-separated local URLs of imported images.
	 *     @type string[] $errors Human-readable messages for images that failed.
	 * }
	 */
	public function import_gallery_images( $urls_blob, $post_id, $product_title ) {
		$remote_urls = preg_split( '/\r\n|\r|\n/', trim( (string) $urls_blob ) );
		$local_urls  = array();
		$errors      = array();
		$sequence    = 2;

		foreach ( $remote_urls as $remote_url ) {
			$remote_url = trim( $remote_url );
			if ( '' === $remote_url ) {
				continue;
			}

			$attachment_id = $this->sideload_image( $remote_url, $post_id, $product_title, $sequence );

			if ( is_wp_error( $attachment_id ) ) {
				$errors[] = sprintf(
					/* translators: 1: image URL, 2: error message. */
					__( 'Gallery image %1$s failed: %2$s', 'affiliate-product-importer' ),
					$remote_url,
					$attachment_id->get_error_message()
				);
				continue;
			}

			$local_urls[] = (string) wp_get_attachment_url( $attachment_id );
			++$sequence;
		}

		return array(
			'urls'   => implode( "\n", $local_urls ),
			'errors' => $errors,
		);
	}
}

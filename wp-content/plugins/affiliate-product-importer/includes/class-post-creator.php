<?php
/**
 * [DOC-041] Turning one mapped CSV row into a published product post.
 *
 * This class owns the whole per-row pipeline: duplicate check → post
 * insert → ACF fields → image sideloading → Pretty Link. It is the only
 * place that knows the order those steps must happen in (the post must
 * exist before images can attach to it; the slug must exist before the
 * Pretty Link can be named after it), so the AJAX batch handler stays a
 * dumb loop and the same pipeline can be driven from WP-CLI or tests.
 *
 * @package Affiliate_Product_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a product post (with fields, images and Pretty Link) from a
 * mapped row, or reports it as skipped/errored.
 */
class AFPI_Post_Creator {

	/**
	 * Duplicate checker shared across the batch (keeps its seen-cache warm).
	 *
	 * @var AFPI_Duplicate_Checker
	 */
	private $duplicate_checker;

	/**
	 * Image handler used for main/gallery image sideloading.
	 *
	 * @var AFPI_Image_Handler
	 */
	private $image_handler;

	/**
	 * [DOC-095] Whether to skip image downloads entirely.
	 *
	 * Set by the WP-CLI --skip-images flag. When true, rows import with
	 * their REMOTE image URLs stored in the ACF fields (no sideload, no
	 * featured image) — the same state a row ends up in when a sideload
	 * fails ([DOC-046]), just chosen deliberately and without warnings.
	 * Exists because downloading ~13k images dominates big imports by
	 * hours; skipping them turns a first pass into minutes.
	 *
	 * @var bool
	 */
	private $skip_images = false;

	/**
	 * [DOC-042] Constructor with optional dependency injection.
	 *
	 * The collaborators default to fresh instances so ad-hoc callers
	 * (WP-CLI, the smoke test) can just `new AFPI_Post_Creator()`, but the
	 * AJAX batch loop passes in shared instances so the duplicate
	 * checker's per-request cache survives across the rows of a batch.
	 *
	 * @param AFPI_Duplicate_Checker|null $duplicate_checker Optional shared checker.
	 * @param AFPI_Image_Handler|null     $image_handler     Optional shared image handler.
	 * @param bool                        $skip_images       Skip image downloads (see [DOC-095]).
	 */
	public function __construct( $duplicate_checker = null, $image_handler = null, $skip_images = false ) {
		$this->duplicate_checker = $duplicate_checker instanceof AFPI_Duplicate_Checker ? $duplicate_checker : new AFPI_Duplicate_Checker();
		$this->image_handler     = $image_handler instanceof AFPI_Image_Handler ? $image_handler : new AFPI_Image_Handler();
		$this->skip_images       = (bool) $skip_images;
	}

	/**
	 * [DOC-043] Import one mapped row.
	 *
	 * Returns a small result array instead of throwing: the batch loop
	 * needs to keep going whatever happens to an individual row, and the
	 * Import History log wants per-row messages, so every outcome —
	 * imported, skipped (duplicate), error — comes back the same shape.
	 *
	 * Row-level rules:
	 *   - A row with no post_title is an error, not a skip: a title is the
	 *     one thing a product page cannot exist without, and reporting it
	 *     as an error (rather than silently skipping) surfaces mapping
	 *     mistakes — the most likely cause is the title dropdown being
	 *     left unmapped.
	 *   - Duplicate check happens BEFORE the post insert so a duplicate
	 *     costs one SELECT, not an insert + delete.
	 *   - Image failures downgrade to warnings on an otherwise-imported
	 *     row ([DOC-046]); the post still publishes.
	 *
	 * @param array $mapped Sanitized field => value array from AFPI_Field_Mapper.
	 * @return array {
	 *     @type string   $status   'imported', 'skipped' or 'error'.
	 *     @type int      $post_id  Created post ID (0 unless imported).
	 *     @type string   $message  One-line summary for the results table / log.
	 *     @type string[] $warnings Non-fatal issues (e.g. a dead image URL).
	 * }
	 */
	public function create_from_row( $mapped ) {
		$settings = afpi_get_settings();
		$title    = isset( $mapped['post_title'] ) ? trim( $mapped['post_title'] ) : '';

		if ( '' === $title ) {
			return array(
				'status'   => 'error',
				'post_id'  => 0,
				'message'  => __( 'Row has no product title (is the Product Title column mapped?).', 'affiliate-product-importer' ),
				'warnings' => array(),
			);
		}

		$dup_field = $this->duplicate_checker->get_duplicate_field();
		$dup_value = isset( $mapped[ $dup_field ] ) ? $mapped[ $dup_field ] : '';

		if ( $this->duplicate_checker->exists( $dup_value ) ) {
			return array(
				'status'   => 'skipped',
				'post_id'  => 0,
				'message'  => sprintf(
					/* translators: 1: duplicate field name, 2: duplicate value, 3: product title. */
					__( 'Skipped "%3$s": %1$s "%2$s" already exists.', 'affiliate-product-importer' ),
					$dup_field,
					$dup_value,
					$title
				),
				'warnings' => array(),
			);
		}

		/*
		 * [DOC-044] Insert the post first, fields second.
		 *
		 * Products are published immediately (not drafted) because the
		 * whole point of the importer is bulk go-live; anything that needs
		 * human review shouldn't come in via CSV. Content is already
		 * sanitized by the field mapper, so it goes in as-is.
		 */
		$post_id = wp_insert_post(
			array(
				'post_type'    => $settings['post_type'],
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => isset( $mapped['post_content'] ) ? $mapped['post_content'] : '',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return array(
				'status'   => 'error',
				'post_id'  => 0,
				'message'  => sprintf(
					/* translators: 1: product title, 2: error message. */
					__( 'Could not create "%1$s": %2$s', 'affiliate-product-importer' ),
					$title,
					$post_id->get_error_message()
				),
				'warnings' => array(),
			);
		}

		$warnings = array();

		/*
		 * [DOC-045] Write the ACF fields.
		 *
		 * Everything except the post fields and the two image fields is a
		 * straight write. update_field() is preferred over update_post_meta()
		 * because it also writes ACF's field-key reference meta (_sku =>
		 * field_am_sku), without which the values would be invisible in the
		 * ACF edit UI. The function_exists() guard keeps the importer
		 * functional (values land as plain post meta) even if ACF is
		 * deactivated — same degradation strategy the theme uses.
		 */
		$post_fields   = array( 'post_title', 'post_content' );
		$image_fields  = array( 'main_image', 'gallery_images' );
		$taxonomy_only = array( 'category' );

		foreach ( $mapped as $field => $value ) {
			if ( in_array( $field, $post_fields, true )
				|| in_array( $field, $image_fields, true )
				|| in_array( $field, $taxonomy_only, true ) ) {
				continue;
			}
			$this->update_acf_field( $field, $value, $post_id );
		}

		$this->assign_taxonomies( $post_id, $mapped );

		/*
		 * [DOC-046] Sideload images, falling back to the remote URL.
		 *
		 * If the main image downloads, the main_image ACF field gets the
		 * new LOCAL attachment URL and the attachment becomes the featured
		 * image. If the download fails (dead URL, hotlink protection), the
		 * original remote URL is stored instead and a warning is recorded —
		 * a product with a merchant-hosted image beats a product with no
		 * image, and the warning tells the site owner which products to
		 * revisit. Same logic per-line for the gallery.
		 */

		/*
		 * [DOC-096] --skip-images short-circuit.
		 *
		 * Store the remote URLs verbatim and move on — no downloads, no
		 * featured image, no warnings. Deliberately no per-row warning
		 * either: the user asked for this mode explicitly, and 13,000
		 * "image skipped" messages would bury the log entries that
		 * actually need reading.
		 */
		if ( $this->skip_images ) {
			foreach ( $image_fields as $image_field ) {
				if ( isset( $mapped[ $image_field ] ) && '' !== $mapped[ $image_field ] ) {
					$this->update_acf_field( $image_field, $mapped[ $image_field ], $post_id );
				}
			}

			/*
			 * [DOC-200] Dead-image flag in skip-images mode. Rules:
			 *   - EMPTY/missing main_image        -> '0' (no image at
			 *     all; browse pages are real-photos-only, so image-less
			 *     products are excluded exactly like dead-image ones)
			 *   - URL 302s to the feed placeholder -> '0' (one 5s HEAD,
			 *     redirects off — [DOC-198])
			 *   - URL PRESENT but the check errors/times out -> '1'
			 *     (fail-safe: never hide a product on uncertainty, and
			 *     detection must never halt the import loop)
			 * Consumed by the browse-exclusion query in a later step.
			 */
			if ( ! isset( $mapped['main_image'] ) || '' === trim( (string) $mapped['main_image'] ) ) {
				$has_real_image = '0';
			} else {
				$has_real_image = '1';
				try {
					if ( AFPI_Image_Handler::is_placeholder_redirect( $mapped['main_image'] ) ) {
						$has_real_image = '0';
					}
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- fail-safe: URL present, keep '1'.
					$has_real_image = '1';
				}
			}
			update_post_meta( $post_id, '_afpi_has_real_image', $has_real_image );
		} elseif ( isset( $mapped['main_image'] ) && '' !== $mapped['main_image'] ) {
			$local_url = $this->image_handler->import_main_image( $mapped['main_image'], $post_id, $title );

			if ( is_wp_error( $local_url ) ) {
				$warnings[] = sprintf(
					/* translators: 1: image URL, 2: error message. */
					__( 'Main image %1$s failed: %2$s (remote URL stored instead).', 'affiliate-product-importer' ),
					$mapped['main_image'],
					$local_url->get_error_message()
				);
				$this->update_acf_field( 'main_image', $mapped['main_image'], $post_id );

				/*
				 * [DOC-200] Download failed, so no bytes were checkable:
				 * fail-safe flag '1' — never hide a product because a
				 * check could not run. (The success path's flag is set
				 * inside import_main_image(), [DOC-199].)
				 */
				update_post_meta( $post_id, '_afpi_has_real_image', '1' );
			} else {
				$this->update_acf_field( 'main_image', $local_url, $post_id );
			}
		} else {
			/*
			 * [DOC-200] Sideload mode with NO main_image in the feed at
			 * all: same rule as the skip-images branch — empty OR dead
			 * image => '0'; only a check-error-with-URL-present earns
			 * the fail-safe '1'.
			 */
			update_post_meta( $post_id, '_afpi_has_real_image', '0' );
		}

		if ( ! $this->skip_images && isset( $mapped['gallery_images'] ) && '' !== $mapped['gallery_images'] ) {
			$gallery  = $this->image_handler->import_gallery_images( $mapped['gallery_images'], $post_id, $title );
			$warnings = array_merge( $warnings, $gallery['errors'] );

			if ( '' !== $gallery['urls'] ) {
				$this->update_acf_field( 'gallery_images', $gallery['urls'], $post_id );
			} else {
				// Every gallery download failed — keep the remote URLs so
				// the data survives for a later retry.
				$this->update_acf_field( 'gallery_images', $mapped['gallery_images'], $post_id );
			}
		}

		$pretty_warning = $this->create_pretty_link( $post_id, $mapped );
		if ( '' !== $pretty_warning ) {
			$warnings[] = $pretty_warning;
		}

		// Tell the checker this value now exists so a repeat of the same
		// SKU later in this batch is skipped from cache. See [DOC-086].
		$this->duplicate_checker->mark_exists( $dup_value );

		return array(
			'status'   => 'imported',
			'post_id'  => $post_id,
			'message'  => sprintf(
				/* translators: %s: product title. */
				__( 'Imported "%s".', 'affiliate-product-importer' ),
				$title
			),
			'warnings' => $warnings,
		);
	}

	/**
	 * [DOC-047] Create the Pretty Link for the product's affiliate URL.
	 *
	 * Uses Pretty Link's local PHP API (prli_create_pretty_link) with the
	 * product's post slug as the link slug, so /products/bburago-ferrari/
	 * gets a matching cloaked link at /bburago-ferrari/ — memorable, and
	 * trivially greppable back to its product. The redirect target is the
	 * raw affiliate_url from the feed.
	 *
	 * Two guards:
	 *   - function_exists(): Pretty Link is a separate plugin; if it is
	 *     deactivated the import must not fatal, it just notes the miss.
	 *   - prli_get_link_from_slug(): re-running an import after deleting
	 *     products (or two products sanitizing to the same slug) could
	 *     collide with an existing pretty link; reusing it is correct when
	 *     the target matches conceptually, and creating a duplicate slug
	 *     would fail inside Pretty Link anyway.
	 *
	 * The created link's ID and public URL are stored as post meta
	 * (_afpi_pretty_link_id / _afpi_pretty_link_url). The affiliate_url
	 * ACF field intentionally keeps the ORIGINAL merchant URL — the pretty
	 * link is derived data, and templates/shortcodes can opt into using
	 * the meta when the site wants cloaked front-end links.
	 *
	 * @param int   $post_id Product post ID.
	 * @param array $mapped  Mapped row values.
	 * @return string Empty string on success, otherwise a warning message.
	 */
	private function create_pretty_link( $post_id, $mapped ) {
		$affiliate_url = isset( $mapped['affiliate_url'] ) ? $mapped['affiliate_url'] : '';

		if ( '' === $affiliate_url ) {
			return '';
		}

		if ( ! function_exists( 'prli_create_pretty_link' ) ) {
			return __( 'Pretty Link plugin is not active — no pretty link was created.', 'affiliate-product-importer' );
		}

		$post = get_post( $post_id );
		$slug = $post ? $post->post_name : '';

		if ( '' === $slug ) {
			return __( 'Product has no slug — no pretty link was created.', 'affiliate-product-importer' );
		}

		$existing = prli_get_link_from_slug( $slug );

		if ( ! empty( $existing ) && ! empty( $existing->id ) ) {
			$link_id = (int) $existing->id;
		} else {
			$link_id = prli_create_pretty_link( $affiliate_url, $slug, get_the_title( $post_id ) );
		}

		if ( empty( $link_id ) ) {
			return sprintf(
				/* translators: %s: link slug. */
				__( 'Pretty Link creation failed for slug "%s".', 'affiliate-product-importer' ),
				$slug
			);
		}

		update_post_meta( $post_id, '_afpi_pretty_link_id', (int) $link_id );
		update_post_meta( $post_id, '_afpi_pretty_link_url', esc_url_raw( (string) prli_get_pretty_link_url( $link_id ) ) );

		return '';
	}

	/**
	 * [DOC-108] Assign taxonomy terms from the mapped row.
	 *
	 * Maps imported field values onto the affiliate-master theme's
	 * product taxonomies so every imported product lands on the SEO
	 * archive pages ([DOC-098] in the theme) without a manual tagging
	 * pass: brand -> brand, scale -> scale. The map is filterable
	 * ('afpi_taxonomy_map') so a niche clone that adds taxonomies can
	 * wire more fields in without touching the plugin.
	 *
	 * product-type is NOT field-mapped anymore ([DOC-192]): AWIN's
	 * merchant_category ships brand names and merchandising labels,
	 * not product categories — mapping it seeded the taxonomy with
	 * junk. The product type is detected from the TITLE instead,
	 * which in this niche reliably names what the thing is.
	 *
	 * Values are passed to wp_set_object_terms() as strings, which
	 * creates missing terms on the fly — exactly what a bulk import
	 * wants (nobody pre-creates 40 brand terms), and WordPress
	 * slugifies names like "1/24" to clean archive URLs (/scale/1-24/)
	 * automatically.
	 *
	 * Every assignment is guarded by taxonomy_exists(): the taxonomies
	 * live in the THEME, and the importer must keep working (fields
	 * only, no terms) if a clone runs a different theme — same
	 * degradation strategy as the ACF guard in [DOC-045].
	 *
	 * @param int   $post_id Product post ID.
	 * @param array $mapped  Sanitized field => value array.
	 */
	private function assign_taxonomies( $post_id, $mapped ) {
		$taxonomy_map = apply_filters(
			'afpi_taxonomy_map',
			array(
				'brand' => 'brand',
				'scale' => 'scale',
			)
		);

		foreach ( $taxonomy_map as $field => $taxonomy ) {
			$value = isset( $mapped[ $field ] ) ? trim( (string) $mapped[ $field ] ) : '';

			if ( '' === $value || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			wp_set_object_terms( $post_id, array( $value ), $taxonomy, false );
		}

		if ( taxonomy_exists( 'product-type' ) && ! empty( $mapped['post_title'] ) ) {
			$product_type = self::detect_product_type( (string) $mapped['post_title'] );
			wp_set_object_terms( $post_id, array( $product_type ), 'product-type', false );
		}
	}

	/**
	 * [DOC-192] Detect the product type from a product title.
	 *
	 * Ordered keyword rules; FIRST matching group wins, so order is
	 * load-bearing: Emergency Vehicles sits above Trucks because a
	 * "Fire Truck" is an emergency vehicle, not a truck; Trucks sits
	 * above Farm Vehicles so a "Tractor Trailer" rig outranks the
	 * bare "tractor" farm keyword; Military above Trucks catches
	 * "Army Truck". Values are term NAMES, not slugs — they resolve
	 * to the theme's existing terms by name (crucially "TV & Movie",
	 * whose slug is tv-movie; assigning a tv-and-movie slug would
	 * mint a duplicate term and orphan the homepage category card).
	 *
	 * Keywords match on word boundaries, not substrings — bare "tv"
	 * must not fire on "ATV" nor "van" on "Vantage". Multi-word
	 * keywords are literal phrases; Fast & Furious is listed in its
	 * ampersand, "and", and bare-"furious" spellings because Jada
	 * titles all three ways.
	 *
	 * Anything unmatched is a car: this is a die cast CAR portfolio,
	 * so cars are the population and everything else is the
	 * exception. Rules are filterable ('afpi_product_type_rules') per
	 * niche clone — a drone site replaces the whole table.
	 *
	 * @param string $title Product title.
	 * @return string Product-type term name.
	 */
	public static function detect_product_type( $title ) {
		$rules = apply_filters(
			'afpi_product_type_rules',
			array(
				'Aircraft'           => array( 'boeing', 'airbus', 'aircraft', 'airplane', 'airliner', 'helicopter', 'aviation' ),
				'Emergency Vehicles' => array( 'fire truck', 'fire engine', 'ladder truck', 'pumper', 'police', 'sheriff', 'law enforcement', 'patrol' ),
				'Military'           => array( 'military', 'tank', 'army', 'navy', 'combat', 'warbird', 'fighter jet' ),
				'TV & Movie'         => array( 'walking dead', 'hollywood rides', 'fast & furious', 'fast and furious', 'furious', 'tv', 'movie' ),
				'Trucks'             => array( 'truck', 'pickup', 'semi', 'tractor trailer', 'hauler', 'van' ),
				'Farm Vehicles'      => array( 'tractor', 'farm', 'john deere', 'case ih' ),
			)
		);

		foreach ( $rules as $term_name => $keywords ) {
			foreach ( $keywords as $keyword ) {
				if ( preg_match( '/\b' . preg_quote( $keyword, '/' ) . '\b/i', $title ) ) {
					return $term_name;
				}
			}
		}

		return apply_filters( 'afpi_product_type_default', 'Cars' );
	}

	/**
	 * [DOC-048] Write one field value, via ACF when available.
	 *
	 * See [DOC-045] for why update_field() is preferred and why the
	 * fallback exists. Centralised in one tiny method so the ACF-or-meta
	 * decision is made in exactly one place.
	 *
	 * @param string $field   Field name (ACF field name / meta key).
	 * @param mixed  $value   Sanitized value to store.
	 * @param int    $post_id Post to write to.
	 */
	private function update_acf_field( $field, $value, $post_id ) {
		if ( function_exists( 'update_field' ) ) {
			update_field( $field, $value, $post_id );
		} else {
			update_post_meta( $post_id, $field, $value );
		}
	}
}

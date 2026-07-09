<?php
/**
 * [DOC-032] Duplicate detection for imported products.
 *
 * Affiliate feeds get re-downloaded and re-imported regularly (price
 * refreshes, catalogue updates), so the same SKU showing up again is the
 * normal case, not the edge case. This class answers one question — "does
 * a product with this identifier already exist?" — and the import loop
 * skips the row when it does, per the project's skip-don't-update policy.
 *
 * @package Affiliate_Product_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks whether an imported row's duplicate-detection value (SKU by
 * default, configurable in Settings) already exists on a post of the
 * target post type.
 */
class AFPI_Duplicate_Checker {

	/**
	 * Post type the current import targets.
	 *
	 * @var string
	 */
	private $post_type;

	/**
	 * Meta key used for duplicate detection (e.g. 'sku').
	 *
	 * @var string
	 */
	private $meta_key;

	/**
	 * Per-request cache of values KNOWN to exist (value => true).
	 *
	 * Only positive results are ever cached. A negative result must not
	 * be cached because the import itself invalidates it: row 1 checks
	 * "SKU X exists?" (no), imports it, then a later row in the same file
	 * checks the same SKU — a cached "no" here would let the duplicate
	 * straight through. (That exact bug was caught by the plugin's first
	 * smoke test.)
	 *
	 * @var array
	 */
	private $seen = array();

	/**
	 * [DOC-033] Constructor: read the target CPT and dedupe field from Settings.
	 *
	 * Both values come from afpi_get_settings() rather than being passed
	 * per-call so a whole import batch is guaranteed to check against the
	 * same post type and field, even if an admin saves new settings in
	 * another tab mid-import.
	 */
	public function __construct() {
		$settings        = afpi_get_settings();
		$this->post_type = $settings['post_type'];
		$this->meta_key  = $settings['duplicate_field'];
	}

	/**
	 * [DOC-034] Does a post with this duplicate-field value already exist?
	 *
	 * Runs one prepared query against postmeta joined to posts instead of
	 * a WP_Query meta_query: the import loop calls this once per CSV row,
	 * and WP_Query would fire ~4 queries plus object-cache churn per call
	 * for a question that needs exactly one indexed lookup. The join
	 * restricts matches to the target post type, and trashed posts are
	 * excluded on purpose — if an admin trashed a product, re-importing
	 * the feed should be able to bring it back as a fresh post rather
	 * than being blocked by a corpse in the trash.
	 *
	 * Duplicate rows *within the same CSV file* are also caught: the
	 * import creates each post (and calls mark_exists()) before checking
	 * the next row, so the second occurrence sees the first. The $seen
	 * cache only ever stores positive answers — see its property doc for
	 * why caching a negative here would break exactly that case.
	 *
	 * An empty value returns false (not a duplicate): rows without a SKU
	 * can never be deduplicated, and treating "" as an existing value
	 * would wrongly skip every SKU-less row after the first.
	 *
	 * @param string $value The row's value for the duplicate-detection field.
	 * @return bool True if a non-trashed post of the target type already has this value.
	 */
	public function exists( $value ) {
		global $wpdb;

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return false;
		}

		if ( isset( $this->seen[ $value ] ) ) {
			return $this->seen[ $value ];
		}

		$post_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Hot path of the import loop; a single indexed lookup per row, result memoized in $this->seen.
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.post_type = %s
				   AND p.post_status != 'trash'
				   AND pm.meta_key = %s
				   AND pm.meta_value = %s
				 LIMIT 1",
				$this->post_type,
				$this->meta_key,
				$value
			)
		);

		if ( ! empty( $post_id ) ) {
			$this->seen[ $value ] = true;
			return true;
		}

		return false;
	}

	/**
	 * [DOC-086] Record that a value now exists (called after each import).
	 *
	 * The post creator calls this immediately after successfully creating
	 * a product, so repeat occurrences of the same SKU later in the batch
	 * are caught from cache without waiting for the meta write to be
	 * re-queried. Purely a correctness/efficiency aid for within-batch
	 * duplicates — cross-batch duplicates are found by the DB query.
	 *
	 * @param string $value The duplicate-field value that was just imported.
	 */
	public function mark_exists( $value ) {
		$value = trim( (string) $value );

		if ( '' !== $value ) {
			$this->seen[ $value ] = true;
		}
	}

	/**
	 * [DOC-035] Which mapped field is the duplicate key?
	 *
	 * Exposed so the import loop can pull the right value out of a mapped
	 * row without re-reading settings itself — keeps "what field do we
	 * dedupe on" answered by exactly one class.
	 *
	 * @return string Meta key used for duplicate detection.
	 */
	public function get_duplicate_field() {
		return $this->meta_key;
	}
}

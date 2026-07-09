<?php
/**
 * [DOC-049] Import history logging.
 *
 * Every completed import writes one row to a dedicated custom table, and
 * the Import History tab reads from it. A custom table (rather than a
 * hidden CPT or a growing option) is the right store here because log
 * rows are pure structured data — nobody edits them, they never appear on
 * the front end, and an option would be one unbounded serialized blob
 * loaded on every request. The table costs nothing when idle and keeps
 * history queryable (ORDER BY date, LIMIT) forever.
 *
 * @package Affiliate_Product_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates the log table on activation, records one row per finished
 * import, and reads rows back for the Import History tab.
 */
class AFPI_Import_Logger {

	/**
	 * [DOC-050] Fully-prefixed log table name.
	 *
	 * Always derived from $wpdb->prefix at call time (never cached in a
	 * constant) so the plugin keeps working on cloned sites where the
	 * table prefix differs — remember, this template is duplicated into
	 * many niche sites and prefixes are commonly randomised there.
	 *
	 * @return string Table name including the site's prefix.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'afpi_import_log';
	}

	/**
	 * [DOC-051] Create (or migrate) the log table.
	 *
	 * Runs on plugin activation ([DOC-015]). dbDelta both creates the
	 * table on first activation and alters it in place if a future plugin
	 * version changes this schema, so there is no separate migration
	 * system to maintain.
	 *
	 * Columns mirror exactly what the Import History tab displays: date,
	 * filename, imported/skipped/error counts, plus a JSON blob of the
	 * per-row error and warning messages ('messages'). JSON in LONGTEXT
	 * (rather than a second, per-message table) is a deliberate
	 * simplification: messages are only ever read back as one list for
	 * display, never queried individually.
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			import_date DATETIME NOT NULL,
			filename VARCHAR(255) NOT NULL DEFAULT '',
			rows_imported INT(11) UNSIGNED NOT NULL DEFAULT 0,
			rows_skipped INT(11) UNSIGNED NOT NULL DEFAULT 0,
			rows_errors INT(11) UNSIGNED NOT NULL DEFAULT 0,
			messages LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY import_date (import_date)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * [DOC-052] Record one finished import.
	 *
	 * Called exactly once per import, when the final AJAX batch completes
	 * — not once per batch — so one CSV file always equals one history
	 * row, which is how a human thinks about "an import". import_date
	 * uses current_time( 'mysql' ) so the history tab shows the site's
	 * local timezone, matching every other date in wp-admin.
	 *
	 * @param string   $filename Original name of the uploaded CSV.
	 * @param int      $imported Rows successfully imported.
	 * @param int      $skipped  Rows skipped as duplicates.
	 * @param int      $errors   Rows that failed outright.
	 * @param string[] $messages Error/warning messages collected during the import.
	 */
	public static function log( $filename, $imported, $skipped, $errors, $messages = array() ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin's own custom table; no WP API covers it.
			self::table_name(),
			array(
				'import_date'   => current_time( 'mysql' ),
				'filename'      => sanitize_file_name( $filename ),
				'rows_imported' => (int) $imported,
				'rows_skipped'  => (int) $skipped,
				'rows_errors'   => (int) $errors,
				'messages'      => wp_json_encode( array_values( array_map( 'sanitize_text_field', (array) $messages ) ) ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * [DOC-053] Read the most recent imports for the history tab.
	 *
	 * Newest first, LIMIT-ed rather than unbounded: a site that imports
	 * weekly for two years has ~100 rows and nobody scrolls past the
	 * recent ones — if pagination is ever needed the LIMIT/OFFSET is
	 * already in place to extend. The %i placeholder identifies the table
	 * safely (available since WP 6.2; this project requires 6.5).
	 *
	 * Each returned row's 'messages' is decoded back to an array so the
	 * template never touches JSON.
	 *
	 * @param int $limit Maximum number of log rows to return.
	 * @return array[] Log rows, newest first.
	 */
	public static function get_logs( $limit = 50 ) {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own custom table, read on one admin screen only.
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY import_date DESC, id DESC LIMIT %d',
				self::table_name(),
				(int) $limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$decoded         = json_decode( (string) $row['messages'], true );
			$row['messages'] = is_array( $decoded ) ? $decoded : array();
		}
		unset( $row );

		return $rows;
	}
}

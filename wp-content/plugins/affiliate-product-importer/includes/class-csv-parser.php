<?php
/**
 * [DOC-018] CSV parsing for affiliate network exports.
 *
 * All raw CSV access lives in this one class so the rest of the plugin
 * only ever sees clean PHP arrays. Affiliate network exports are messy in
 * predictable ways — UTF-8 BOMs from Excel, semicolon delimiters from
 * European networks, stray blank lines — and this class absorbs all of
 * that here instead of letting each caller re-handle it.
 *
 * @package Affiliate_Product_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses an uploaded CSV file: headers, preview rows, row counts and
 * offset-based row batches for the AJAX import loop.
 */
class AFPI_CSV_Parser {

	/**
	 * Absolute path to the CSV file being parsed.
	 *
	 * @var string
	 */
	private $file_path;

	/**
	 * The delimiter detected for this file (see detect_delimiter()).
	 *
	 * @var string
	 */
	private $delimiter;

	/**
	 * [DOC-019] Constructor: bind the parser to one file and sniff its delimiter.
	 *
	 * The delimiter is detected once at construction (not per-read) because
	 * every subsequent operation on the same file must agree on it —
	 * detecting per-call could theoretically disagree between the preview
	 * and the import if detection logic ever changed mid-request.
	 *
	 * @param string $file_path Absolute path to the CSV file.
	 */
	public function __construct( $file_path ) {
		$this->file_path = $file_path;
		$this->delimiter = $this->detect_delimiter();
	}

	/**
	 * [DOC-020] Detect the field delimiter from the first line.
	 *
	 * Counts occurrences of the three delimiters seen in real affiliate
	 * exports (comma, semicolon, tab) in the header row and picks the most
	 * frequent. This simple frequency approach beats asking the user to
	 * choose: the header row of a product feed always contains many
	 * delimiters and almost never contains the *other* candidates, so the
	 * count is a reliable signal. Falls back to comma, which is correct
	 * for the Amazon/ShareASale-style feeds this template targets.
	 *
	 * @return string Single-character delimiter.
	 */
	private function detect_delimiter() {
		$first_line = '';
		$handle     = $this->open();

		if ( $handle ) {
			$first_line = (string) fgets( $handle );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming CSV read; WP_Filesystem has no line-by-line API.
		}

		$candidates = array( ',', ';', "\t" );
		$best       = ',';
		$best_count = 0;

		foreach ( $candidates as $candidate ) {
			$count = substr_count( $first_line, $candidate );
			if ( $count > $best_count ) {
				$best_count = $count;
				$best       = $candidate;
			}
		}

		return $best;
	}

	/**
	 * [DOC-021] Open the file and strip Excel's UTF-8 BOM if present.
	 *
	 * Excel-saved CSVs start with the bytes EF BB BF. If they are not
	 * consumed here, they become part of the first header name (e.g.
	 * "\xEF\xBB\xBFsku" !== "sku"), which silently breaks both column
	 * mapping and saved mapping profiles. Consuming the BOM at open time
	 * fixes this for every read path in one place.
	 *
	 * Direct fopen() (rather than WP_Filesystem) is intentional: the file
	 * is a local temp upload we created ourselves, and fgetcsv() streaming
	 * is the whole point — WP_Filesystem only offers whole-file reads,
	 * which would load a 50k-row feed into memory at once.
	 *
	 * @return resource|false File handle positioned after any BOM, or false on failure.
	 */
	private function open() {
		if ( ! file_exists( $this->file_path ) || ! is_readable( $this->file_path ) ) {
			return false;
		}

		$handle = fopen( $this->file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming CSV read; WP_Filesystem has no line-by-line API.

		if ( ! $handle ) {
			return false;
		}

		$bom = fread( $handle, 3 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reading 3 bytes to detect a BOM on a streamed handle.
		if ( "\xEF\xBB\xBF" !== $bom ) {
			rewind( $handle );
		}

		return $handle;
	}

	/**
	 * [DOC-022] Read the header row.
	 *
	 * Returns the first row of the file as a trimmed array of column
	 * names. Trimming matters because feeds frequently pad headers with
	 * spaces ("sku , price") and an untrimmed header would make the saved
	 * mapping profiles unmatchable on the next, differently-padded export.
	 *
	 * @return string[] Column headers, or an empty array if the file is unreadable/empty.
	 */
	public function get_headers() {
		$handle = $this->open();

		if ( ! $handle ) {
			return array();
		}

		$headers = fgetcsv( $handle, 0, $this->delimiter, '"', '\\' );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming CSV read; WP_Filesystem has no line-by-line API.

		if ( ! is_array( $headers ) ) {
			return array();
		}

		return array_map( 'trim', $headers );
	}

	/**
	 * [DOC-023] Count the data rows (excluding the header).
	 *
	 * Used once per import to size the JavaScript progress bar. Streams
	 * the whole file with fgetcsv rather than counting newlines because a
	 * quoted CSV field may legally contain embedded newlines — a raw line
	 * count would overstate the total and the progress bar would never
	 * reach 100%.
	 *
	 * @return int Number of non-empty data rows.
	 */
	public function count_rows() {
		$handle = $this->open();

		if ( ! $handle ) {
			return 0;
		}

		$count    = 0;
		$is_first = true;

		while ( true ) {
			$row = fgetcsv( $handle, 0, $this->delimiter, '"', '\\' );
			if ( false === $row ) {
				break;
			}
			if ( $is_first ) {
				$is_first = false;
				continue;
			}
			if ( $this->is_blank_row( $row ) ) {
				continue;
			}
			++$count;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming CSV read; WP_Filesystem has no line-by-line API.

		return $count;
	}

	/**
	 * [DOC-024] Fetch a batch of data rows keyed by header name.
	 *
	 * The AJAX import loop calls this with a moving offset (0, 10, 20 …)
	 * so each HTTP request only processes a small batch — that is what
	 * keeps a multi-thousand-row feed import inside PHP's max_execution_time
	 * and lets the UI show real progress. The file is re-streamed from the
	 * start on every call; that is O(n²) across a whole import but the
	 * skip-forward pass is just fgetcsv with no processing, which is far
	 * cheaper than the image sideloading each row triggers, so it is not
	 * the bottleneck in practice.
	 *
	 * Rows are returned as header-keyed associative arrays so downstream
	 * code (the field mapper) never has to care about column positions.
	 * Short rows are padded and long rows truncated to the header length,
	 * which protects the mapper from ragged feeds.
	 *
	 * @param int $offset Zero-based index of the first data row to return.
	 * @param int $limit  Maximum number of rows to return.
	 * @return array[] List of associative arrays (header => value).
	 */
	public function get_rows( $offset, $limit ) {
		$headers = $this->get_headers();

		if ( empty( $headers ) ) {
			return array();
		}

		$handle = $this->open();

		if ( ! $handle ) {
			return array();
		}

		// Skip the header row.
		fgetcsv( $handle, 0, $this->delimiter, '"', '\\' );

		$rows         = array();
		$collected    = 0;
		$row_index    = 0;
		$header_count = count( $headers );

		while ( $collected < $limit ) {
			$row = fgetcsv( $handle, 0, $this->delimiter, '"', '\\' );
			if ( false === $row ) {
				break;
			}
			if ( $this->is_blank_row( $row ) ) {
				continue;
			}
			if ( $row_index >= $offset ) {
				$row    = array_pad( array_slice( $row, 0, $header_count ), $header_count, '' );
				$rows[] = array_combine( $headers, $row );
				++$collected;
			}
			++$row_index;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming CSV read; WP_Filesystem has no line-by-line API.

		return $rows;
	}

	/**
	 * [DOC-025] Convenience wrapper: the first N rows, for the preview table.
	 *
	 * The Import tab shows the first 3 rows under the mapping dropdowns so
	 * the user can sanity-check that (say) the "Brand" column really
	 * contains brands before committing to a full import. Delegates to
	 * get_rows() so preview and import are guaranteed to parse rows
	 * identically — a separate preview parser could lie about what the
	 * import will actually do.
	 *
	 * @param int $count How many rows to preview.
	 * @return array[] List of associative arrays (header => value).
	 */
	public function get_preview_rows( $count = 3 ) {
		return $this->get_rows( 0, $count );
	}

	/**
	 * [DOC-026] Detect rows that are effectively empty.
	 *
	 * Feeds exported from spreadsheets often end with rows of empty cells
	 * (",,,,,"). Importing those would create title-less draft posts and
	 * pollute the skip/error counts, so both count_rows() and get_rows()
	 * ignore any row whose cells are all empty after trimming.
	 *
	 * @param array $row Raw row from fgetcsv.
	 * @return bool True if every cell is empty.
	 */
	private function is_blank_row( $row ) {
		if ( ! is_array( $row ) ) {
			return true;
		}

		foreach ( $row as $cell ) {
			if ( '' !== trim( (string) $cell ) ) {
				return false;
			}
		}

		return true;
	}
}

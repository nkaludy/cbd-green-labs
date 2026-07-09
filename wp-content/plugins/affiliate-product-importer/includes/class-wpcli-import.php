<?php
/**
 * [DOC-087] WP-CLI command: server-side CSV imports.
 *
 * The admin-page import ([DOC-067]) drives the pipeline through
 * browser AJAX batches, which is right for a 50-row feed but wrong for
 * a 13,000-product catalogue: the tab has to stay open for hours and a
 * dropped connection orphans the run. This command drives the exact
 * same pipeline classes (parser → mapper → duplicate checker → post
 * creator → logger) from the shell instead, so big imports can run
 * under nohup/cron on the server with no browser involved.
 *
 * Usage:
 *   wp afpi import --file=/path/to/cleaned.csv --profile="AWIN Default"
 *   wp afpi import --file=feed.csv --profile="AWIN Default" --batch-size=100 --skip-images
 *
 * @package Affiliate_Product_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers `wp afpi import`. Only loaded/registered when WP-CLI is
 * running (see the guard in affiliate-product-importer.php).
 */
class AFPI_WPCLI_Import {

	/**
	 * [DOC-088] Import products from a cleaned CSV file.
	 *
	 * The docblock sections below (OPTIONS/EXAMPLES) are not decoration:
	 * WP-CLI parses them to power `wp help afpi import` and to validate
	 * flags before this method even runs — which is why the parameter
	 * validation here only covers semantics (file readable, profile
	 * exists), not syntax.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : Path to the cleaned CSV file to import.
	 *
	 * --profile=<name>
	 * : Name of a saved mapping profile (created on the plugin's
	 * Mapping Profiles tab, stored in the afpi_mapping_profiles option).
	 * Mapped brand / scale / category values are also assigned as
	 * brand / scale / product-type taxonomy terms automatically
	 * (see [DOC-108] in class-post-creator.php).
	 *
	 * [--batch-size=<rows>]
	 * : Rows to process per batch. Between batches the object cache is
	 * flushed to keep memory flat on very large files.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--skip-images]
	 * : Do not download images. Products are created with the remote
	 * image URLs stored in their fields, which is orders of magnitude
	 * faster; images can be sideloaded later by re-importing without
	 * this flag after deleting the posts, or handled by a separate pass.
	 *
	 * ## EXAMPLES
	 *
	 *     wp afpi import --file=/path/to/cleaned.csv --profile="AWIN Default"
	 *     wp afpi import --file=feed.csv --profile="AWIN Default" --batch-size=100 --skip-images
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative flags from WP-CLI.
	 */
	public function import( $args, $assoc_args ) {
		$file       = isset( $assoc_args['file'] ) ? (string) $assoc_args['file'] : '';
		$profile    = isset( $assoc_args['profile'] ) ? (string) $assoc_args['profile'] : '';
		$batch_size = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 50;
		$skip_imgs  = ! empty( $assoc_args['skip-images'] );

		if ( $batch_size < 1 ) {
			WP_CLI::error( '--batch-size must be a positive number.' );
		}

		$this->validate_file( $file );
		$mapping = $this->resolve_profile( $profile );

		$parser = new AFPI_CSV_Parser( $file );
		$total  = $parser->count_rows();

		if ( 0 === $total ) {
			WP_CLI::error( 'The file contains no data rows.' );
		}

		WP_CLI::log( sprintf( 'Importing %d rows from %s (profile: %s%s)', $total, basename( $file ), $profile, $skip_imgs ? ', images skipped' : '' ) );

		/*
		 * [DOC-089] The batch loop.
		 *
		 * Same shared instances across the whole run as one AJAX batch
		 * uses ([DOC-042]): the duplicate checker's cache stays warm for
		 * within-file duplicates, and the post creator is told once
		 * whether to sideload images. Each row is additionally wrapped
		 * in try/catch: one corrupt row throwing (bad serialized data,
		 * a plugin conflict mid-insert) must cost that row an error
		 * entry, not the remaining 12,000 rows.
		 *
		 * wp_cache_flush() between batches is what makes batch size
		 * matter server-side: every created post/attachment parks
		 * objects in the in-process cache, and on a six-figure-row run
		 * that grows to gigabytes if never released.
		 */
		$checker  = new AFPI_Duplicate_Checker();
		$creator  = new AFPI_Post_Creator( $checker, new AFPI_Image_Handler(), $skip_imgs );
		$start    = microtime( true );
		$imported = 0;
		$skipped  = 0;
		$errors   = 0;
		$messages = array();
		$offset   = 0;
		$mapper   = new AFPI_Field_Mapper();

		$progress = \WP_CLI\Utils\make_progress_bar( 'Importing', $total );

		while ( $offset < $total ) {
			$rows = $parser->get_rows( $offset, $batch_size );

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				try {
					$result = $creator->create_from_row( $mapper->map_row( $row, $mapping ) );
				} catch ( Throwable $e ) {
					$result = array(
						'status'   => 'error',
						'message'  => sprintf( 'Row %d threw: %s', $offset + 1, $e->getMessage() ),
						'warnings' => array(),
					);
				}

				if ( 'imported' === $result['status'] ) {
					++$imported;
				} elseif ( 'skipped' === $result['status'] ) {
					++$skipped;
					$messages[] = $result['message'];
				} else {
					++$errors;
					$messages[] = $result['message'];
				}

				foreach ( $result['warnings'] as $warning ) {
					$messages[] = $warning;
				}

				$progress->tick();
			}

			$offset += count( $rows );

			// Running count after each batch, matching the admin UI's
			// live results so both interfaces "read" the same.
			WP_CLI::log( sprintf( 'Imported: %d | Skipped: %d | Errors: %d', $imported, $skipped, $errors ) );

			wp_cache_flush();
		}

		$progress->finish();

		/*
		 * [DOC-090] One history row per run, same as the browser path.
		 *
		 * Messages are capped at 500 exactly like the AJAX handler
		 * ([DOC-067]) so a CLI import of a pathological feed cannot
		 * write a megabyte log row; the counts stay exact regardless.
		 */
		AFPI_Import_Logger::log(
			basename( $file ),
			$imported,
			$skipped,
			$errors,
			array_slice( $messages, 0, 500 )
		);

		$elapsed = microtime( true ) - $start;

		WP_CLI::log( '' );
		WP_CLI::log( 'Import complete.' );
		WP_CLI::log( sprintf( '  Imported:   %d', $imported ) );
		WP_CLI::log( sprintf( '  Skipped:    %d', $skipped ) );
		WP_CLI::log( sprintf( '  Errors:     %d', $errors ) );
		WP_CLI::log( sprintf( '  Time taken: %s', $this->format_duration( $elapsed ) ) );

		if ( $errors > 0 ) {
			WP_CLI::warning( 'Some rows failed — details are in the Import History tab (Affiliate Importer > Import History).' );
		} else {
			WP_CLI::success( 'Logged to Import History.' );
		}
	}

	/**
	 * [DOC-091] Validate the --file argument.
	 *
	 * Checked here (not left to the parser) because the parser was
	 * built to return empty results rather than fail loudly — right
	 * for the AJAX flow where the upload was already vetted, wrong for
	 * a CLI where a typo'd path should stop the run with a clear
	 * message instead of "0 rows imported" confusion.
	 *
	 * @param string $file Path supplied on the command line.
	 */
	private function validate_file( $file ) {
		if ( '' === $file ) {
			WP_CLI::error( 'Missing --file=<path>.' );
		}

		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			WP_CLI::error( sprintf( 'File not found or unreadable: %s', $file ) );
		}
	}

	/**
	 * [DOC-092] Look up a saved mapping profile by name.
	 *
	 * Reads the same afpi_mapping_profiles option the admin UI writes,
	 * so a profile saved once in the browser drives every future CLI
	 * run — no separate CLI mapping format to maintain. The mapping is
	 * re-sanitized through the field mapper even though the UI already
	 * sanitized it at save time, because options are editable by other
	 * code/db tools and this command trusts nothing it didn't verify.
	 *
	 * On a miss, the error lists the available names: the most common
	 * failure will be a quoting/spelling slip, and showing what DOES
	 * exist turns a dead end into a fix.
	 *
	 * @param string $name Profile name from --profile.
	 * @return array Clean mapping (target field => CSV column).
	 */
	private function resolve_profile( $name ) {
		if ( '' === $name ) {
			WP_CLI::error( 'Missing --profile=<name>.' );
		}

		$profiles = get_option( 'afpi_mapping_profiles', array() );

		if ( ! is_array( $profiles ) || ! isset( $profiles[ $name ] ) ) {
			$available = ( is_array( $profiles ) && ! empty( $profiles ) )
				? implode( '", "', array_keys( $profiles ) )
				: '';
			WP_CLI::error(
				'' !== $available
					? sprintf( 'Profile "%s" not found. Available profiles: "%s".', $name, $available )
					: sprintf( 'Profile "%s" not found and no profiles are saved yet. Create one on the Mapping Profiles tab first.', $name )
			);
		}

		$mapping = AFPI_Field_Mapper::sanitize_mapping( $profiles[ $name ] );

		if ( ! isset( $mapping['post_title'] ) ) {
			WP_CLI::error( sprintf( 'Profile "%s" does not map the post_title field — imports need a product title.', $name ) );
		}

		return $mapping;
	}

	/**
	 * [DOC-093] Human-readable duration for the final summary.
	 *
	 * Seconds alone stop being readable past a few minutes, and CLI
	 * imports are exactly the runs that take minutes-to-hours (that is
	 * why they're on the CLI). 90 seconds reads better as "1 min 30 sec".
	 *
	 * @param float $seconds Elapsed seconds.
	 * @return string Formatted duration.
	 */
	private function format_duration( $seconds ) {
		if ( $seconds < 60 ) {
			return sprintf( '%.1f sec', $seconds );
		}

		$minutes = floor( $seconds / 60 );

		if ( $minutes < 60 ) {
			return sprintf( '%d min %d sec', $minutes, round( $seconds - ( $minutes * 60 ) ) );
		}

		$hours = floor( $minutes / 60 );

		return sprintf( '%d hr %d min', $hours, $minutes - ( $hours * 60 ) );
	}
}

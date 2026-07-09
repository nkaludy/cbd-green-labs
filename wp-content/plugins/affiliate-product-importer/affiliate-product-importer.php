<?php
/**
 * Plugin Name:       Affiliate Product Importer
 * Plugin URI:        https://github.com/nkaludy/master-template
 * Description:       Bulk-imports affiliate products from affiliate network CSV exports into the affiliate_product custom post type, including ACF field mapping, media sideloading, duplicate skipping and Pretty Link creation.
 * Version:           1.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Master Template
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       affiliate-product-importer
 *
 * [DOC-012] Plugin bootstrap.
 *
 * This is the only file WordPress loads directly; everything else lives in
 * includes/ and is pulled in below. The importer is its own plugin (rather
 * than part of the affiliate-master child theme) on purpose: the theme can
 * be swapped or restyled per niche site without losing the import tooling,
 * and the plugin can be updated across all site clones independently of
 * theme changes. See CLAUDE.md ("Where custom code goes").
 *
 * @package Affiliate_Product_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [DOC-013] Plugin constants.
 *
 * Defined once here so every include can build paths/URLs without
 * re-deriving them. AFPI_VERSION is appended to enqueued assets as a
 * cache-buster, so bump it whenever admin.css/admin.js change — otherwise
 * LiteSpeed Cache (and browsers) will happily serve the stale file on
 * cloned production sites.
 */
define( 'AFPI_VERSION', '1.1.0' );
define( 'AFPI_PLUGIN_FILE', __FILE__ );
define( 'AFPI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AFPI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * [DOC-014] Load the plugin's classes.
 *
 * Plain require_once (no autoloader) is deliberate: the plugin has a fixed,
 * small set of classes, and an spl_autoload_register callback would add
 * indirection without saving anything measurable. Order only matters in
 * that class-admin-page.php news up the other classes at runtime, not at
 * load time, so any order would actually work — this list is alphabetical
 * for easy scanning.
 */
require_once AFPI_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once AFPI_PLUGIN_DIR . 'includes/class-csv-parser.php';
require_once AFPI_PLUGIN_DIR . 'includes/class-duplicate-checker.php';
require_once AFPI_PLUGIN_DIR . 'includes/class-field-mapper.php';
require_once AFPI_PLUGIN_DIR . 'includes/class-image-handler.php';
require_once AFPI_PLUGIN_DIR . 'includes/class-import-logger.php';
require_once AFPI_PLUGIN_DIR . 'includes/class-post-creator.php';

/**
 * [DOC-094] Register the WP-CLI command (CLI runs only).
 *
 * The class file is required inside the guard (unlike the includes
 * above) because web requests never need it — no point parsing a
 * CLI-only class on every front-end hit. Registered as `wp afpi`,
 * giving `wp afpi import` for browserless server-side imports of
 * large feeds; see includes/class-wpcli-import.php ([DOC-087]).
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once AFPI_PLUGIN_DIR . 'includes/class-wpcli-import.php';
	WP_CLI::add_command( 'afpi', 'AFPI_WPCLI_Import' );
}

/**
 * [DOC-015] Activation: create the import-history table.
 *
 * The Import History tab reads from a dedicated custom table (see
 * class-import-logger.php for why a table beats a CPT/option here).
 * Creating it on activation via dbDelta means a fresh site clone gets the
 * table the moment the plugin is switched on — no manual SQL step. dbDelta
 * is also idempotent, so re-activation on a site that already has the
 * table is a harmless no-op.
 */
register_activation_hook( __FILE__, array( 'AFPI_Import_Logger', 'create_table' ) );

/**
 * [DOC-016] Boot the admin UI.
 *
 * The entire plugin is admin-only (there is no front-end output), so the
 * admin page class is only instantiated inside is_admin(). Front-end
 * requests on high-traffic affiliate sites therefore pay zero cost for
 * this plugin beyond the file includes above. Hooked on plugins_loaded so
 * ACF (a separate plugin) is guaranteed to be loaded before any of our
 * code calls its functions.
 */
function afpi_boot() {
	if ( is_admin() ) {
		new AFPI_Admin_Page();
	}
}
add_action( 'plugins_loaded', 'afpi_boot' );

/**
 * [DOC-017] Central accessor for the plugin's settings.
 *
 * A tiny helper instead of scattering get_option() calls: every class that
 * needs a setting funnels through here, so the option name and the default
 * values exist in exactly one place. Defaults intentionally match this
 * template's data model (affiliate_product CPT, SKU-based dedupe) so the
 * importer works out of the box on every site cloned from the template,
 * before anyone visits the Settings tab.
 *
 * @return array {
 *     Plugin settings.
 *
 *     @type string $post_type       Target post type for imports.
 *     @type string $duplicate_field Field used for duplicate detection.
 *     @type bool   $strip_html      Whether to strip HTML from imported descriptions.
 * }
 */
function afpi_get_settings() {
	$defaults = array(
		'post_type'       => 'affiliate_product',
		'duplicate_field' => 'sku',
		'strip_html'      => true,
	);

	$settings = get_option( 'afpi_settings', array() );

	return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
}

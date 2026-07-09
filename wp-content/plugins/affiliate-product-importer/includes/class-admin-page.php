<?php
/**
 * [DOC-054] The Affiliate Importer admin screen.
 *
 * One class owns everything the user sees: the menu item, the four tabs
 * (Import / Mapping Profiles / Import History / Settings), the asset
 * enqueues and every AJAX endpoint the Import tab's JavaScript talks to.
 * The tabs are rendered together on a single page load and switched
 * client-side, NOT as separate ?tab= page loads — that is a deliberate
 * choice, because the Mapping Profiles tab must be able to populate the
 * Import tab's dropdowns ("load profile") without throwing away the
 * uploaded CSV state, which a full page reload would destroy.
 *
 * @package Affiliate_Product_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the admin menu/page, enqueues assets, and handles all AJAX
 * and form submissions for the importer UI.
 */
class AFPI_Admin_Page {

	/**
	 * Menu/page slug, reused for the hook suffix check in enqueue_assets().
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'affiliate-importer';

	/**
	 * [DOC-055] Rows processed per AJAX import request.
	 *
	 * Kept deliberately small (3) because each row can trigger several
	 * remote image downloads with 30-second timeouts — the batch must
	 * reliably finish inside PHP's max_execution_time on cheap shared
	 * hosting, where these niche sites end up living. Filterable
	 * ('afpi_batch_size') for image-less feeds where bigger batches are
	 * safe.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 3;

	/**
	 * [DOC-056] Wire up every hook the admin UI needs.
	 *
	 * All wp_ajax_ endpoints are registered here (admin-only, per
	 * [DOC-016]) — there are intentionally no wp_ajax_nopriv_ twins,
	 * since nothing about importing products should ever be reachable
	 * logged-out.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_afpi_upload_csv', array( $this, 'ajax_upload_csv' ) );
		add_action( 'wp_ajax_afpi_import_batch', array( $this, 'ajax_import_batch' ) );
		add_action( 'wp_ajax_afpi_save_profile', array( $this, 'ajax_save_profile' ) );
		add_action( 'wp_ajax_afpi_delete_profile', array( $this, 'ajax_delete_profile' ) );

		add_action( 'admin_post_afpi_save_settings', array( $this, 'handle_save_settings' ) );
	}

	/**
	 * [DOC-057] Register the top-level "Affiliate Importer" menu item.
	 *
	 * A top-level menu (not a submenu under Tools or the CPT) because
	 * importing is the primary recurring workflow on these sites — feeds
	 * get re-imported every time the catalogue refreshes, so it earns a
	 * first-class spot. Capability is manage_options: importing creates
	 * published posts, downloads remote files and writes settings, which
	 * is administrator territory.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Affiliate Importer', 'affiliate-product-importer' ),
			__( 'Affiliate Importer', 'affiliate-product-importer' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-upload',
			58
		);
	}

	/**
	 * [DOC-058] Enqueue admin.css / admin.js on our screen only.
	 *
	 * The $hook check means no other admin screen pays for these assets.
	 * Everything the JavaScript needs from PHP — endpoint URL, nonce, the
	 * field catalogue for building the mapping dropdowns, saved profiles
	 * and translatable UI strings — travels via wp_localize_script, so
	 * admin.js contains zero hardcoded server state and works unchanged
	 * on every cloned site.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'afpi-admin',
			AFPI_PLUGIN_URL . 'assets/admin.css',
			array(),
			AFPI_VERSION
		);

		wp_enqueue_script(
			'afpi-admin',
			AFPI_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			AFPI_VERSION,
			true
		);

		$field_labels = array();
		foreach ( AFPI_Field_Mapper::get_target_fields() as $name => $field ) {
			$field_labels[ $name ] = $field['label'];
		}

		wp_localize_script(
			'afpi-admin',
			'afpiData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'afpi_ajax' ),
				'fields'    => $field_labels,
				'profiles'  => $this->get_profiles(),
				'batchSize' => (int) apply_filters( 'afpi_batch_size', self::BATCH_SIZE ),
				'i18n'      => array(
					'chooseFile'    => __( 'Please choose a CSV file first.', 'affiliate-product-importer' ),
					'uploading'     => __( 'Uploading…', 'affiliate-product-importer' ),
					'uploadPreview' => __( 'Upload & Preview', 'affiliate-product-importer' ),
					'importing'     => __( 'Importing…', 'affiliate-product-importer' ),
					'startImport'   => __( 'Start Import', 'affiliate-product-importer' ),
					'skipColumn'    => __( '— skip this field —', 'affiliate-product-importer' ),
					'requestFailed' => __( 'The request failed. Please try again.', 'affiliate-product-importer' ),
					'needTitle'     => __( 'Map the Product Title field before importing.', 'affiliate-product-importer' ),
					'needUpload'    => __( 'Upload a CSV on the Import tab first — profiles fill its dropdowns.', 'affiliate-product-importer' ),
					'needName'      => __( 'Enter a name for the profile.', 'affiliate-product-importer' ),
					'chooseProfile' => __( 'Choose a profile first.', 'affiliate-product-importer' ),
					'confirmDelete' => __( 'Delete this mapping profile?', 'affiliate-product-importer' ),
					'profileLoaded' => __( 'Profile loaded — dropdowns on the Import tab have been filled in.', 'affiliate-product-importer' ),
					/* translators: 1: imported count, 2: skipped count, 3: error count. */
					'summary'       => __( 'Done: %1$s imported, %2$s skipped, %3$s errors.', 'affiliate-product-importer' ),
					'noProfiles'    => __( 'No saved profiles yet.', 'affiliate-product-importer' ),
				),
			)
		);
	}

	/**
	 * [DOC-059] Render the page shell: nav tabs + the four panels.
	 *
	 * Uses core's .nav-tab markup so the tabs look native to wp-admin.
	 * All four panels are in the DOM at once (hidden with CSS); admin.js
	 * shows the one whose tab is active. The initial tab honours a ?tab=
	 * query arg so the Settings save redirect can land the user back on
	 * the Settings tab — read-only display logic, hence no nonce.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'affiliate-product-importer' ) );
		}

		$active_tab = 'import';
		if ( isset( $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI state: choosing which tab starts visible.
			$requested = sanitize_key( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same read-only UI state as above.
			if ( in_array( $requested, array( 'import', 'profiles', 'history', 'settings' ), true ) ) {
				$active_tab = $requested;
			}
		}

		$tabs = array(
			'import'   => __( 'Import', 'affiliate-product-importer' ),
			'profiles' => __( 'Mapping Profiles', 'affiliate-product-importer' ),
			'history'  => __( 'Import History', 'affiliate-product-importer' ),
			'settings' => __( 'Settings', 'affiliate-product-importer' ),
		);
		?>
		<div class="wrap afpi-wrap">
			<h1><?php esc_html_e( 'Affiliate Product Importer', 'affiliate-product-importer' ); ?></h1>

			<nav class="nav-tab-wrapper afpi-nav">
				<?php foreach ( $tabs as $tab_id => $tab_label ) : ?>
					<a href="#<?php echo esc_attr( $tab_id ); ?>"
						class="nav-tab <?php echo $tab_id === $active_tab ? 'nav-tab-active' : ''; ?>"
						data-afpi-tab="<?php echo esc_attr( $tab_id ); ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			$this->render_import_tab( 'import' === $active_tab );
			$this->render_profiles_tab( 'profiles' === $active_tab );
			$this->render_history_tab( 'history' === $active_tab );
			$this->render_settings_tab( 'settings' === $active_tab );
			?>
		</div>
		<?php
	}

	/**
	 * [DOC-060] Tab 1 — Import.
	 *
	 * Renders the static skeleton only: file picker, and empty containers
	 * for the mapping table, preview, progress bar and results. Everything
	 * inside those containers is built by admin.js from the upload
	 * response, because none of it (headers, preview rows) exists until a
	 * CSV has been uploaded in this browser session.
	 *
	 * @param bool $active Whether this panel starts visible.
	 */
	private function render_import_tab( $active ) {
		?>
		<div class="afpi-tab-panel <?php echo $active ? 'is-active' : ''; ?>" data-afpi-panel="import">
			<h2><?php esc_html_e( 'Step 1: Upload a CSV file', 'affiliate-product-importer' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Upload an affiliate network CSV export. The column headers will be detected automatically and the first rows previewed below.', 'affiliate-product-importer' ); ?>
			</p>

			<form id="afpi-upload-form" enctype="multipart/form-data">
				<input type="file" id="afpi-csv-file" name="afpi_csv" accept=".csv,.txt,text/csv" />
				<button type="submit" class="button button-secondary" id="afpi-upload-btn">
					<?php esc_html_e( 'Upload & Preview', 'affiliate-product-importer' ); ?>
				</button>
			</form>

			<div id="afpi-mapping-section" class="afpi-hidden">
				<h2><?php esc_html_e( 'Step 2: Map CSV columns to product fields', 'affiliate-product-importer' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Columns whose header matches a field name are pre-selected for you. Product Title is required; leave any field you do not need on "skip".', 'affiliate-product-importer' ); ?>
				</p>
				<div id="afpi-mapping-table"></div>

				<h3><?php esc_html_e( 'Preview (first 3 rows)', 'affiliate-product-importer' ); ?></h3>
				<div id="afpi-preview-table" class="afpi-preview-scroll"></div>

				<h2><?php esc_html_e( 'Step 3: Import', 'affiliate-product-importer' ); ?></h2>
				<p>
					<button type="button" class="button button-primary button-hero" id="afpi-start-import">
						<?php esc_html_e( 'Start Import', 'affiliate-product-importer' ); ?>
					</button>
					<span id="afpi-row-count" class="afpi-row-count"></span>
				</p>

				<div id="afpi-progress" class="afpi-hidden">
					<div class="afpi-progress-track"><div class="afpi-progress-bar" id="afpi-progress-bar"></div></div>
					<p id="afpi-progress-text"></p>
				</div>

				<div id="afpi-results" class="afpi-hidden">
					<h3><?php esc_html_e( 'Results', 'affiliate-product-importer' ); ?></h3>
					<p id="afpi-results-summary"></p>
					<ul id="afpi-results-messages"></ul>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * [DOC-061] Tab 2 — Mapping Profiles.
	 *
	 * Profiles exist because every affiliate network exports the same
	 * column layout every time: map it once, save it as (say) "Amazon
	 * feed", and every future import of that network is two clicks. The
	 * save button snapshots the CURRENT dropdown state from the Import
	 * tab; the load button pushes a saved mapping back into those
	 * dropdowns — which is exactly why the tabs are client-side panels
	 * on one page ([DOC-054]).
	 *
	 * @param bool $active Whether this panel starts visible.
	 */
	private function render_profiles_tab( $active ) {
		?>
		<div class="afpi-tab-panel <?php echo $active ? 'is-active' : ''; ?>" data-afpi-panel="profiles">
			<h2><?php esc_html_e( 'Save current mapping', 'affiliate-product-importer' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Saves the column mapping currently selected on the Import tab under a name, e.g. "Amazon feed".', 'affiliate-product-importer' ); ?>
			</p>
			<p>
				<input type="text" id="afpi-profile-name" class="regular-text"
					placeholder="<?php esc_attr_e( 'Profile name', 'affiliate-product-importer' ); ?>" />
				<button type="button" class="button button-primary" id="afpi-save-profile">
					<?php esc_html_e( 'Save Profile', 'affiliate-product-importer' ); ?>
				</button>
			</p>

			<h2><?php esc_html_e( 'Saved profiles', 'affiliate-product-importer' ); ?></h2>
			<p>
				<select id="afpi-profile-select"></select>
				<button type="button" class="button" id="afpi-load-profile">
					<?php esc_html_e( 'Load', 'affiliate-product-importer' ); ?>
				</button>
				<button type="button" class="button afpi-delete-btn" id="afpi-delete-profile">
					<?php esc_html_e( 'Delete', 'affiliate-product-importer' ); ?>
				</button>
			</p>
			<p class="description">
				<?php esc_html_e( 'Loading a profile fills in the mapping dropdowns on the Import tab (a CSV must be uploaded first so the columns exist).', 'affiliate-product-importer' ); ?>
			</p>
			<div id="afpi-profile-feedback"></div>
		</div>
		<?php
	}

	/**
	 * [DOC-062] Tab 3 — Import History.
	 *
	 * A plain server-rendered table straight from AFPI_Import_Logger —
	 * history is read-only and only changes when an import finishes, so
	 * there is nothing to gain from making this dynamic. Error/warning
	 * messages hide inside a <details> element per row: the counts are
	 * what you scan; the messages are what you open when a count looks
	 * wrong.
	 *
	 * @param bool $active Whether this panel starts visible.
	 */
	private function render_history_tab( $active ) {
		$logs = AFPI_Import_Logger::get_logs( 50 );
		?>
		<div class="afpi-tab-panel <?php echo $active ? 'is-active' : ''; ?>" data-afpi-panel="history">
			<h2><?php esc_html_e( 'Import History', 'affiliate-product-importer' ); ?></h2>

			<?php if ( empty( $logs ) ) : ?>
				<p><?php esc_html_e( 'No imports have been run yet.', 'affiliate-product-importer' ); ?></p>
			<?php else : ?>
				<table class="widefat striped afpi-history-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'affiliate-product-importer' ); ?></th>
							<th><?php esc_html_e( 'File', 'affiliate-product-importer' ); ?></th>
							<th><?php esc_html_e( 'Imported', 'affiliate-product-importer' ); ?></th>
							<th><?php esc_html_e( 'Skipped', 'affiliate-product-importer' ); ?></th>
							<th><?php esc_html_e( 'Errors', 'affiliate-product-importer' ); ?></th>
							<th><?php esc_html_e( 'Messages', 'affiliate-product-importer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $log['import_date'] ) ); ?></td>
								<td><?php echo esc_html( $log['filename'] ); ?></td>
								<td><?php echo esc_html( $log['rows_imported'] ); ?></td>
								<td><?php echo esc_html( $log['rows_skipped'] ); ?></td>
								<td><?php echo esc_html( $log['rows_errors'] ); ?></td>
								<td>
									<?php if ( empty( $log['messages'] ) ) : ?>
										&mdash;
									<?php else : ?>
										<details>
											<summary>
												<?php
												printf(
													/* translators: %d: number of messages. */
													esc_html( _n( '%d message', '%d messages', count( $log['messages'] ), 'affiliate-product-importer' ) ),
													(int) count( $log['messages'] )
												);
												?>
											</summary>
											<ul class="afpi-log-messages">
												<?php foreach ( $log['messages'] as $message ) : ?>
													<li><?php echo esc_html( $message ); ?></li>
												<?php endforeach; ?>
											</ul>
										</details>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * [DOC-063] Tab 4 — Settings.
	 *
	 * A classic admin-post.php form (not the Settings API) because these
	 * three options live on a custom page with custom tabs, and the
	 * Settings API's register_setting/do_settings_sections machinery buys
	 * nothing here except indirection. The form posts to
	 * admin_post_afpi_save_settings ([DOC-068]) and redirects back to
	 * this tab.
	 *
	 * The post-type selector lists public, UI-visible post types so a
	 * clone that renames or adds a CPT can retarget the importer without
	 * code changes; the duplicate-field selector offers only the meta
	 * (ACF) fields from the catalogue — post_title/post_content are
	 * excluded because duplicate detection queries postmeta ([DOC-034]).
	 *
	 * @param bool $active Whether this panel starts visible.
	 */
	private function render_settings_tab( $active ) {
		$settings = afpi_get_settings();

		$post_types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);
		unset( $post_types['attachment'] );

		$meta_fields = AFPI_Field_Mapper::get_target_fields();
		unset( $meta_fields['post_title'], $meta_fields['post_content'] );

		$updated = isset( $_GET['afpi-updated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only "saved" notice; the save itself is nonce-checked in handle_save_settings().
		?>
		<div class="afpi-tab-panel <?php echo $active ? 'is-active' : ''; ?>" data-afpi-panel="settings">
			<h2><?php esc_html_e( 'Settings', 'affiliate-product-importer' ); ?></h2>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'affiliate-product-importer' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="afpi_save_settings" />
				<?php wp_nonce_field( 'afpi_save_settings', 'afpi_settings_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="afpi-post-type"><?php esc_html_e( 'Import into post type', 'affiliate-product-importer' ); ?></label>
						</th>
						<td>
							<select id="afpi-post-type" name="afpi_post_type">
								<?php foreach ( $post_types as $post_type ) : ?>
									<option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( $settings['post_type'], $post_type->name ); ?>>
										<?php echo esc_html( $post_type->labels->singular_name . ' (' . $post_type->name . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Products are created as posts of this type. Default: Affiliate Product.', 'affiliate-product-importer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="afpi-duplicate-field"><?php esc_html_e( 'Duplicate detection field', 'affiliate-product-importer' ); ?></label>
						</th>
						<td>
							<select id="afpi-duplicate-field" name="afpi_duplicate_field">
								<?php foreach ( $meta_fields as $field_name => $field ) : ?>
									<option value="<?php echo esc_attr( $field_name ); ?>" <?php selected( $settings['duplicate_field'], $field_name ); ?>>
										<?php echo esc_html( $field['label'] . ' (' . $field_name . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Rows whose value for this field already exists are skipped. Default: SKU.', 'affiliate-product-importer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Descriptions', 'affiliate-product-importer' ); ?></th>
						<td>
							<label for="afpi-strip-html">
								<input type="checkbox" id="afpi-strip-html" name="afpi_strip_html" value="1" <?php checked( ! empty( $settings['strip_html'] ) ); ?> />
								<?php esc_html_e( 'Strip HTML from imported descriptions', 'affiliate-product-importer' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Affiliate feeds often include merchant markup and tracking pixels in description columns; stripping to plain text is the safe default.', 'affiliate-product-importer' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'affiliate-product-importer' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * [DOC-064] AJAX: receive the CSV upload, return headers + preview.
	 *
	 * The file goes through wp_handle_upload (so WordPress performs its
	 * normal MIME/extension vetting) but into a private afpi-tmp/
	 * subfolder of uploads via the upload_dir filter below — feed files
	 * are working data, not media, and must not clutter the month-based
	 * media folders. The server-side path is remembered in a PER-USER
	 * transient and only an original-filename echo goes back to the
	 * browser: the client never sees or supplies a filesystem path, which
	 * closes off path-traversal games in the import step.
	 *
	 * Responds with: detected headers, the first 3 rows for the preview
	 * table, and the total row count (for the progress bar).
	 */
	public function ajax_upload_csv() {
		check_ajax_referer( 'afpi_ajax', 'nonce' );
		$this->verify_capability();

		if ( empty( $_FILES['afpi_csv'] ) || ! isset( $_FILES['afpi_csv']['name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file was uploaded.', 'affiliate-product-importer' ) ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES is passed intact to wp_handle_upload(), which performs its own validation/sanitization.
		$file = $_FILES['afpi_csv'];

		require_once ABSPATH . 'wp-admin/includes/file.php';

		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
		$uploaded = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				// Some systems report CSVs as text/plain, so both are allowed;
				// wp_handle_upload still rejects anything else.
				'mimes'     => array(
					'csv' => 'text/csv',
					'txt' => 'text/plain',
				),
			)
		);
		remove_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		if ( isset( $uploaded['error'] ) ) {
			wp_send_json_error( array( 'message' => $uploaded['error'] ) );
		}

		$parser  = new AFPI_CSV_Parser( $uploaded['file'] );
		$headers = $parser->get_headers();

		if ( empty( $headers ) ) {
			wp_delete_file( $uploaded['file'] );
			wp_send_json_error( array( 'message' => __( 'The file appears to be empty or is not a readable CSV.', 'affiliate-product-importer' ) ) );
		}

		$original_name = sanitize_file_name( wp_unslash( $file['name'] ) );

		/*
		 * [DOC-065] Upload state transient.
		 *
		 * Keyed per user so two admins importing simultaneously cannot
		 * clobber each other's file. 12-hour expiry: long enough to map
		 * columns over a coffee, short enough that abandoned uploads
		 * don't accumulate state forever (the transient's file is
		 * deleted when the import completes; an expired-but-never-
		 * imported file is overwritten info-wise on the next upload).
		 */
		set_transient(
			$this->upload_key(),
			array(
				'path' => $uploaded['file'],
				'name' => $original_name,
			),
			12 * HOUR_IN_SECONDS
		);
		delete_transient( $this->state_key() );

		wp_send_json_success(
			array(
				'filename' => $original_name,
				'headers'  => $headers,
				'preview'  => $parser->get_preview_rows( 3 ),
				'total'    => $parser->count_rows(),
			)
		);
	}

	/**
	 * [DOC-066] Route importer uploads into uploads/afpi-tmp/.
	 *
	 * Public because it must be a valid 'upload_dir' filter callback;
	 * added/removed tightly around our own wp_handle_upload call in
	 * ajax_upload_csv() so ordinary media uploads are never affected.
	 *
	 * @param array $dirs Upload directory info from WordPress.
	 * @return array Modified directory info.
	 */
	public function filter_upload_dir( $dirs ) {
		$dirs['subdir'] = '/afpi-tmp';
		$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
		$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];

		return $dirs;
	}

	/**
	 * [DOC-067] AJAX: process one batch of rows.
	 *
	 * The admin.js batch loop calls this with a moving offset until 'done'
	 * comes back true. Splitting the import across many small HTTP
	 * requests is what makes the progress bar honest AND keeps each
	 * request within execution limits ([DOC-055]).
	 *
	 * Running totals live in a per-user transient rather than the
	 * browser, so the numbers written to the Import History log are the
	 * server's own count — a user closing the tab mid-import loses the
	 * progress display, not the integrity of the log (the log row is
	 * simply never written for an unfinished import, and re-running the
	 * import skips everything already created via duplicate detection).
	 *
	 * On the final batch: the history row is written, the temp CSV is
	 * deleted and both transients are cleaned up.
	 */
	public function ajax_import_batch() {
		check_ajax_referer( 'afpi_ajax', 'nonce' );
		$this->verify_capability();

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$total  = isset( $_POST['total'] ) ? absint( $_POST['total'] ) : 0;

		$raw_mapping = array();
		if ( isset( $_POST['mapping'] ) ) {
			$decoded = json_decode( sanitize_text_field( wp_unslash( $_POST['mapping'] ) ), true );
			if ( is_array( $decoded ) ) {
				$raw_mapping = $decoded;
			}
		}
		$mapping = AFPI_Field_Mapper::sanitize_mapping( $raw_mapping );

		if ( ! isset( $mapping['post_title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The Product Title field must be mapped to a column.', 'affiliate-product-importer' ) ) );
		}

		$upload = get_transient( $this->upload_key() );
		if ( empty( $upload['path'] ) || ! file_exists( $upload['path'] ) ) {
			wp_send_json_error( array( 'message' => __( 'The uploaded file is no longer available — please upload it again.', 'affiliate-product-importer' ) ) );
		}

		// Fresh import run: zero the running totals.
		if ( 0 === $offset ) {
			delete_transient( $this->state_key() );
		}

		$state = get_transient( $this->state_key() );
		if ( ! is_array( $state ) ) {
			$state = array(
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => 0,
				'messages' => array(),
			);
		}

		$parser  = new AFPI_CSV_Parser( $upload['path'] );
		$mapper  = new AFPI_Field_Mapper();
		$checker = new AFPI_Duplicate_Checker();
		$creator = new AFPI_Post_Creator( $checker, new AFPI_Image_Handler() );

		$batch_size = (int) apply_filters( 'afpi_batch_size', self::BATCH_SIZE );
		$rows       = $parser->get_rows( $offset, $batch_size );
		$row_notes  = array();

		foreach ( $rows as $row ) {
			$mapped = $mapper->map_row( $row, $mapping );
			$result = $creator->create_from_row( $mapped );

			if ( 'imported' === $result['status'] ) {
				++$state['imported'];
			} elseif ( 'skipped' === $result['status'] ) {
				++$state['skipped'];
				$row_notes[] = $result['message'];
			} else {
				++$state['errors'];
				$row_notes[] = $result['message'];
			}

			foreach ( $result['warnings'] as $warning ) {
				$row_notes[] = $warning;
			}
		}

		/*
		 * Messages are capped so a pathological feed (10k dead image
		 * URLs) cannot balloon the transient/log row into megabytes;
		 * the counts stay exact either way.
		 */
		$state['messages'] = array_slice( array_merge( $state['messages'], $row_notes ), 0, 500 );

		$processed = count( $rows );
		$done      = $processed < $batch_size || ( $total > 0 && ( $offset + $processed ) >= $total );

		if ( $done ) {
			AFPI_Import_Logger::log(
				$upload['name'],
				$state['imported'],
				$state['skipped'],
				$state['errors'],
				$state['messages']
			);
			wp_delete_file( $upload['path'] );
			delete_transient( $this->upload_key() );
			delete_transient( $this->state_key() );
		} else {
			set_transient( $this->state_key(), $state, 12 * HOUR_IN_SECONDS );
		}

		wp_send_json_success(
			array(
				'processed'   => $processed,
				'next_offset' => $offset + $processed,
				'done'        => $done,
				'imported'    => $state['imported'],
				'skipped'     => $state['skipped'],
				'errors'      => $state['errors'],
				'notes'       => $row_notes,
			)
		);
	}

	/**
	 * [DOC-068] Handle the Settings form (admin-post.php).
	 *
	 * Every value is validated against a whitelist, not just sanitized:
	 * the post type must actually exist and the duplicate field must be
	 * in the field catalogue. That protects the importer from writing
	 * into a nonsense post type on a clone where someone removed the CPT
	 * but left the plugin active.
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'affiliate-product-importer' ) );
		}

		check_admin_referer( 'afpi_save_settings', 'afpi_settings_nonce' );

		$current = afpi_get_settings();

		$post_type = isset( $_POST['afpi_post_type'] ) ? sanitize_key( wp_unslash( $_POST['afpi_post_type'] ) ) : '';
		if ( ! post_type_exists( $post_type ) ) {
			$post_type = $current['post_type'];
		}

		$duplicate_field = isset( $_POST['afpi_duplicate_field'] ) ? sanitize_key( wp_unslash( $_POST['afpi_duplicate_field'] ) ) : '';
		$meta_fields     = AFPI_Field_Mapper::get_target_fields();
		unset( $meta_fields['post_title'], $meta_fields['post_content'] );
		if ( ! isset( $meta_fields[ $duplicate_field ] ) ) {
			$duplicate_field = $current['duplicate_field'];
		}

		update_option(
			'afpi_settings',
			array(
				'post_type'       => $post_type,
				'duplicate_field' => $duplicate_field,
				'strip_html'      => ! empty( $_POST['afpi_strip_html'] ),
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE_SLUG,
					'tab'          => 'settings',
					'afpi-updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * [DOC-069] AJAX: save the current mapping as a named profile.
	 *
	 * Profiles live in one option ('afpi_mapping_profiles') as
	 * name => mapping. An option (not a CPT/table) is right-sized here:
	 * a site realistically has a handful of profiles — one per affiliate
	 * network — and they are always read all-at-once to fill the profile
	 * dropdown. Saving under an existing name overwrites it, which is
	 * the intuitive "update my Amazon profile" behaviour.
	 *
	 * Responds with the full refreshed profile list so admin.js can
	 * re-render the dropdown without a second round trip.
	 */
	public function ajax_save_profile() {
		check_ajax_referer( 'afpi_ajax', 'nonce' );
		$this->verify_capability();

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		$raw_mapping = array();
		if ( isset( $_POST['mapping'] ) ) {
			$decoded = json_decode( sanitize_text_field( wp_unslash( $_POST['mapping'] ) ), true );
			if ( is_array( $decoded ) ) {
				$raw_mapping = $decoded;
			}
		}
		$mapping = AFPI_Field_Mapper::sanitize_mapping( $raw_mapping );

		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Please provide a profile name.', 'affiliate-product-importer' ) ) );
		}

		if ( empty( $mapping ) ) {
			wp_send_json_error( array( 'message' => __( 'There is no column mapping to save — upload a CSV and map its columns first.', 'affiliate-product-importer' ) ) );
		}

		$profiles          = $this->get_profiles();
		$profiles[ $name ] = $mapping;
		update_option( 'afpi_mapping_profiles', $profiles, false );

		wp_send_json_success(
			array(
				'message'  => sprintf(
					/* translators: %s: profile name. */
					__( 'Profile "%s" saved.', 'affiliate-product-importer' ),
					$name
				),
				'profiles' => $profiles,
			)
		);
	}

	/**
	 * [DOC-070] AJAX: delete a named mapping profile.
	 *
	 * Deleting an unknown name is reported as an error rather than a
	 * silent success so a stale dropdown (another tab deleted it first)
	 * surfaces instead of lying.
	 */
	public function ajax_delete_profile() {
		check_ajax_referer( 'afpi_ajax', 'nonce' );
		$this->verify_capability();

		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$profiles = $this->get_profiles();

		if ( '' === $name || ! isset( $profiles[ $name ] ) ) {
			wp_send_json_error( array( 'message' => __( 'That profile no longer exists.', 'affiliate-product-importer' ) ) );
		}

		unset( $profiles[ $name ] );
		update_option( 'afpi_mapping_profiles', $profiles, false );

		wp_send_json_success(
			array(
				'message'  => sprintf(
					/* translators: %s: profile name. */
					__( 'Profile "%s" deleted.', 'affiliate-product-importer' ),
					$name
				),
				'profiles' => $profiles,
			)
		);
	}

	/**
	 * [DOC-071] Shared capability guard for every AJAX endpoint.
	 *
	 * Each endpoint calls check_ajax_referer( 'afpi_ajax' ) inline (PHPCS's
	 * nonce sniff requires the verification to be visible in the same
	 * function that reads the request) and then this capability check.
	 * Both halves matter: the nonce protects against CSRF, while the
	 * capability check matters independently because nonces are per-user,
	 * not per-role.
	 */
	private function verify_capability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'affiliate-product-importer' ) ) );
		}
	}

	/**
	 * Saved mapping profiles (always an array).
	 *
	 * @return array name => mapping.
	 */
	private function get_profiles() {
		$profiles = get_option( 'afpi_mapping_profiles', array() );

		return is_array( $profiles ) ? $profiles : array();
	}

	/**
	 * Per-user transient key for the uploaded file info. See [DOC-065].
	 *
	 * @return string Transient key.
	 */
	private function upload_key() {
		return 'afpi_upload_' . get_current_user_id();
	}

	/**
	 * Per-user transient key for the import's running totals. See [DOC-067].
	 *
	 * @return string Transient key.
	 */
	private function state_key() {
		return 'afpi_state_' . get_current_user_id();
	}
}

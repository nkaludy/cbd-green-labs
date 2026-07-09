/**
 * [DOC-073] Affiliate Product Importer — admin screen behaviour.
 *
 * Everything dynamic on the importer screen lives here: client-side tab
 * switching, the CSV upload + preview, building the mapping dropdowns,
 * the batched import loop with its progress bar, and the mapping-profile
 * save/load/delete actions.
 *
 * All server state (endpoint URL, nonce, field catalogue, saved
 * profiles, translated strings) arrives via the `afpiData` object that
 * PHP localizes in [DOC-058] — nothing environment-specific is hardcoded
 * here, which keeps the file identical across every cloned niche site.
 *
 * DOM strings that originate from the CSV or the server are always
 * inserted with .text() / text nodes, never string-concatenated into
 * HTML, so a malicious CSV header like `<img onerror=…>` renders as
 * harmless text instead of executing in wp-admin.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.afpiData || {};

	/**
	 * State for the current browser session. csvHeaders doubles as the
	 * "has a CSV been uploaded?" flag that profile-loading checks.
	 */
	var csvHeaders = [];
	var totalRows = 0;

	/* ------------------------------------------------------------------
	 * Tabs
	 * ------------------------------------------------------------------ */

	/**
	 * [DOC-074] Switch the visible tab panel.
	 *
	 * Pure class toggling — panels stay in the DOM so the Import tab
	 * keeps its uploaded-CSV state while the user visits Profiles or
	 * Settings. The URL hash is updated so a hard refresh (or a shared
	 * link) reopens the same tab.
	 *
	 * @param {string} tab Tab id: import|profiles|history|settings.
	 */
	function activateTab( tab ) {
		$( '.afpi-nav .nav-tab' ).removeClass( 'nav-tab-active' );
		$( '.afpi-nav .nav-tab[data-afpi-tab="' + tab + '"]' ).addClass( 'nav-tab-active' );
		$( '.afpi-tab-panel' ).removeClass( 'is-active' );
		$( '.afpi-tab-panel[data-afpi-panel="' + tab + '"]' ).addClass( 'is-active' );

		if ( window.history.replaceState ) {
			window.history.replaceState( null, '', '#' + tab );
		}
	}

	$( document ).on( 'click', '.afpi-nav .nav-tab', function ( e ) {
		e.preventDefault();
		activateTab( $( this ).data( 'afpiTab' ) );
	} );

	/* ------------------------------------------------------------------
	 * Upload & preview
	 * ------------------------------------------------------------------ */

	/**
	 * [DOC-075] Upload the chosen CSV and render mapping + preview.
	 *
	 * Sent as FormData so the file goes up in the same AJAX request as
	 * the action/nonce (processData/contentType false are what let
	 * jQuery pass the multipart body through untouched). On success the
	 * server returns headers, a 3-row preview and the total row count —
	 * see [DOC-064].
	 */
	$( document ).on( 'submit', '#afpi-upload-form', function ( e ) {
		e.preventDefault();

		var fileInput = document.getElementById( 'afpi-csv-file' );
		if ( ! fileInput || ! fileInput.files.length ) {
			window.alert( cfg.i18n.chooseFile );
			return;
		}

		var formData = new FormData();
		formData.append( 'action', 'afpi_upload_csv' );
		formData.append( 'nonce', cfg.nonce );
		formData.append( 'afpi_csv', fileInput.files[ 0 ] );

		var $btn = $( '#afpi-upload-btn' ).prop( 'disabled', true ).text( cfg.i18n.uploading );

		$.ajax( {
			url: cfg.ajaxUrl,
			method: 'POST',
			data: formData,
			processData: false,
			contentType: false,
		} )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					window.alert( ( res && res.data && res.data.message ) || cfg.i18n.requestFailed );
					return;
				}

				csvHeaders = res.data.headers;
				totalRows = res.data.total;

				buildMappingTable( res.data.headers );
				buildPreviewTable( res.data.headers, res.data.preview );

				$( '#afpi-row-count' ).text( res.data.filename + ' — ' + totalRows + ' rows' );
				$( '#afpi-mapping-section' ).removeClass( 'afpi-hidden' );
				$( '#afpi-progress, #afpi-results' ).addClass( 'afpi-hidden' );
			} )
			.fail( function () {
				window.alert( cfg.i18n.requestFailed );
			} )
			.always( function () {
				$btn.prop( 'disabled', false ).text( cfg.i18n.uploadPreview );
			} );
	} );

	/**
	 * [DOC-076] Guess which CSV column belongs to a target field.
	 *
	 * Headers and field names are both normalized (lowercased,
	 * non-alphanumerics collapsed to underscores) and compared, with a
	 * small alias table for the spellings affiliate networks actually
	 * use ("Product Name", "Image URL", "Link"…). This only PRE-selects
	 * dropdowns — the user always sees and can correct the guess before
	 * importing, so a rare false match costs one dropdown change, while
	 * a good guess saves ten of them on every import.
	 *
	 * @param {string} field   Target field name (e.g. "post_title").
	 * @param {Array}  headers CSV header strings.
	 * @return {string} Matching header, or '' if none.
	 */
	function guessColumn( field, headers ) {
		var aliases = {
			post_title: [ 'post_title', 'title', 'product_title', 'product_name', 'name' ],
			post_content: [ 'post_content', 'description', 'product_description', 'long_description' ],
			affiliate_url: [ 'affiliate_url', 'affiliate_link', 'product_url', 'buy_url', 'deep_link', 'url', 'link' ],
			sku: [ 'sku', 'product_sku', 'model', 'model_number', 'item_number' ],
			main_image: [ 'main_image', 'image', 'image_url', 'main_image_url', 'thumbnail' ],
			gallery_images: [ 'gallery_images', 'gallery', 'additional_images', 'alternate_images', 'images' ],
			brand: [ 'brand', 'manufacturer', 'brand_name' ],
			scale: [ 'scale' ],
			features: [ 'features', 'specifications', 'specs', 'short_description' ],
			affiliate_network: [ 'affiliate_network', 'network', 'program' ],
			price: [ 'price', 'retail_price', 'sale_price', 'current_price' ],
		};

		var candidates = aliases[ field ] || [ field ];
		var normalized = {};

		headers.forEach( function ( header ) {
			normalized[ header.toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' ) ] = header;
		} );

		for ( var i = 0; i < candidates.length; i++ ) {
			if ( normalized[ candidates[ i ] ] ) {
				return normalized[ candidates[ i ] ];
			}
		}

		return '';
	}

	/**
	 * [DOC-077] Build the field→column dropdown table.
	 *
	 * One row per target field from the localized catalogue, each with a
	 * select of every CSV header plus a "skip" option. Rebuilt from
	 * scratch on every upload so switching to a different CSV can never
	 * leave stale column options behind.
	 *
	 * @param {Array} headers CSV header strings.
	 */
	function buildMappingTable( headers ) {
		var $table = $( '<table class="afpi-mapping-grid"></table>' );

		$.each( cfg.fields, function ( field, label ) {
			var $select = $( '<select></select>' )
				.attr( 'data-afpi-field', field )
				.append( $( '<option></option>' ).val( '' ).text( cfg.i18n.skipColumn ) );

			headers.forEach( function ( header ) {
				$select.append( $( '<option></option>' ).val( header ).text( header ) );
			} );

			$select.val( guessColumn( field, headers ) );

			var $label = $( '<th></th>' ).text( label );
			if ( 'post_title' === field ) {
				$label.append( $( '<span class="afpi-required"> *</span>' ) );
			}

			$table.append( $( '<tr></tr>' ).append( $label ).append( $( '<td></td>' ).append( $select ) ) );
		} );

		$( '#afpi-mapping-table' ).empty().append( $table );
	}

	/**
	 * [DOC-078] Render the first-rows preview table.
	 *
	 * Shown under the dropdowns so the user can eyeball that the column
	 * they mapped to "Brand" really contains brands. Cell content comes
	 * straight from the CSV, hence .text() everywhere (see file header).
	 *
	 * @param {Array} headers CSV header strings.
	 * @param {Array} rows    Preview rows (objects keyed by header).
	 */
	function buildPreviewTable( headers, rows ) {
		var $table = $( '<table></table>' );
		var $head = $( '<tr></tr>' );

		headers.forEach( function ( header ) {
			$head.append( $( '<th></th>' ).text( header ) );
		} );
		$table.append( $( '<thead></thead>' ).append( $head ) );

		var $body = $( '<tbody></tbody>' );
		rows.forEach( function ( row ) {
			var $tr = $( '<tr></tr>' );
			headers.forEach( function ( header ) {
				$tr.append( $( '<td></td>' ).text( row[ header ] || '' ) );
			} );
			$body.append( $tr );
		} );
		$table.append( $body );

		$( '#afpi-preview-table' ).empty().append( $table );
	}

	/**
	 * [DOC-079] Read the current dropdown state into a mapping object.
	 *
	 * Skipped fields (empty value) are omitted entirely — the server
	 * treats "not in the mapping" as "don't touch this field", which is
	 * how partial feeds import cleanly.
	 *
	 * @return {Object} target field → CSV column.
	 */
	function currentMapping() {
		var mapping = {};

		$( '#afpi-mapping-table select' ).each( function () {
			var column = $( this ).val();
			if ( column ) {
				mapping[ $( this ).data( 'afpiField' ) ] = column;
			}
		} );

		return mapping;
	}

	/* ------------------------------------------------------------------
	 * Import loop
	 * ------------------------------------------------------------------ */

	/**
	 * [DOC-080] Kick off the batched import.
	 *
	 * Validates that Product Title is mapped (the server enforces this
	 * too — this check just fails faster), resets the progress UI and
	 * starts the batch chain at offset 0.
	 */
	$( document ).on( 'click', '#afpi-start-import', function () {
		var mapping = currentMapping();

		if ( ! mapping.post_title ) {
			window.alert( cfg.i18n.needTitle );
			return;
		}

		$( this ).prop( 'disabled', true ).text( cfg.i18n.importing );
		$( '#afpi-progress' ).removeClass( 'afpi-hidden' );
		$( '#afpi-results' ).removeClass( 'afpi-hidden' );
		$( '#afpi-results-summary' ).text( '' );
		$( '#afpi-results-messages' ).empty();
		setProgress( 0 );

		runBatch( 0, mapping );
	} );

	/**
	 * [DOC-081] Process one batch, then recurse until done.
	 *
	 * Sequential (each request starts only after the previous one
	 * returns) rather than parallel on purpose: parallel batches would
	 * race the duplicate checker — two simultaneous requests could both
	 * see "SKU not found" and both insert it. The server tells us the
	 * next offset and whether we're finished; see [DOC-067].
	 *
	 * @param {number} offset  Zero-based row offset for this batch.
	 * @param {Object} mapping Field→column mapping captured at start.
	 */
	function runBatch( offset, mapping ) {
		$.post( cfg.ajaxUrl, {
			action: 'afpi_import_batch',
			nonce: cfg.nonce,
			offset: offset,
			total: totalRows,
			mapping: JSON.stringify( mapping ),
		} )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					finishImport( ( res && res.data && res.data.message ) || cfg.i18n.requestFailed );
					return;
				}

				var d = res.data;

				setProgress( totalRows > 0 ? ( d.next_offset / totalRows ) * 100 : 100 );
				$( '#afpi-progress-text' ).text( d.next_offset + ' / ' + totalRows );

				d.notes.forEach( function ( note ) {
					$( '#afpi-results-messages' ).append( $( '<li></li>' ).text( note ) );
				} );

				if ( d.done ) {
					setProgress( 100 );
					finishImport(
						cfg.i18n.summary
							.replace( '%1$s', d.imported )
							.replace( '%2$s', d.skipped )
							.replace( '%3$s', d.errors )
					);
				} else {
					runBatch( d.next_offset, mapping );
				}
			} )
			.fail( function () {
				finishImport( cfg.i18n.requestFailed );
			} );
	}

	/**
	 * Update the progress bar width (0–100).
	 *
	 * @param {number} percent Progress percentage.
	 */
	function setProgress( percent ) {
		$( '#afpi-progress-bar' ).css( 'width', Math.min( 100, percent ) + '%' );
	}

	/**
	 * Re-enable the import button and print the summary line.
	 *
	 * @param {string} summary Final human-readable summary.
	 */
	function finishImport( summary ) {
		$( '#afpi-start-import' ).prop( 'disabled', false ).text( cfg.i18n.startImport );
		$( '#afpi-results' ).removeClass( 'afpi-hidden' );
		$( '#afpi-results-summary' ).text( summary );
	}

	/* ------------------------------------------------------------------
	 * Mapping profiles
	 * ------------------------------------------------------------------ */

	/**
	 * [DOC-082] Fill the profile dropdown from cfg.profiles.
	 *
	 * cfg.profiles is refreshed from every save/delete AJAX response, so
	 * this render function is the single place the dropdown is built.
	 */
	function renderProfileSelect() {
		var $select = $( '#afpi-profile-select' ).empty();
		var names = Object.keys( cfg.profiles || {} );

		if ( ! names.length ) {
			$select.append( $( '<option></option>' ).val( '' ).text( cfg.i18n.noProfiles ) );
			return;
		}

		names.forEach( function ( name ) {
			$select.append( $( '<option></option>' ).val( name ).text( name ) );
		} );
	}

	/**
	 * Show a small feedback notice on the Profiles tab.
	 *
	 * @param {string}  message Feedback text.
	 * @param {boolean} isError Whether to style as an error.
	 */
	function profileFeedback( message, isError ) {
		$( '#afpi-profile-feedback' )
			.empty()
			.append(
				$( '<div class="notice ' + ( isError ? 'notice-error' : 'notice-success' ) + '"></div>' )
					.append( $( '<p></p>' ).text( message ) )
			);
	}

	/**
	 * [DOC-083] Save the Import tab's current mapping as a named profile.
	 *
	 * The mapping is read live from the dropdowns at click time (not
	 * cached), so "what you see on the Import tab is what gets saved"
	 * holds by construction.
	 */
	$( document ).on( 'click', '#afpi-save-profile', function () {
		var name = $.trim( $( '#afpi-profile-name' ).val() );
		var mapping = currentMapping();

		if ( ! name ) {
			profileFeedback( cfg.i18n.needName, true );
			return;
		}
		if ( ! Object.keys( mapping ).length ) {
			profileFeedback( cfg.i18n.needUpload, true );
			return;
		}

		$.post( cfg.ajaxUrl, {
			action: 'afpi_save_profile',
			nonce: cfg.nonce,
			name: name,
			mapping: JSON.stringify( mapping ),
		} ).done( function ( res ) {
			if ( res && res.success ) {
				cfg.profiles = res.data.profiles;
				renderProfileSelect();
				$( '#afpi-profile-select' ).val( name );
				profileFeedback( res.data.message, false );
			} else {
				profileFeedback( ( res && res.data && res.data.message ) || cfg.i18n.requestFailed, true );
			}
		} );
	} );

	/**
	 * [DOC-084] Load a profile into the Import tab's dropdowns.
	 *
	 * Works entirely client-side (profiles were localized / refreshed by
	 * AJAX), then jumps the user to the Import tab so the effect is
	 * visible. Columns saved in the profile that don't exist in the
	 * currently uploaded CSV are silently left on "skip" — a profile
	 * from a richer feed still loads cleanly against a slimmer one.
	 */
	$( document ).on( 'click', '#afpi-load-profile', function () {
		var name = $( '#afpi-profile-select' ).val();

		if ( ! name || ! cfg.profiles[ name ] ) {
			profileFeedback( cfg.i18n.chooseProfile, true );
			return;
		}
		if ( ! csvHeaders.length ) {
			profileFeedback( cfg.i18n.needUpload, true );
			return;
		}

		var mapping = cfg.profiles[ name ];

		$( '#afpi-mapping-table select' ).each( function () {
			var field = $( this ).data( 'afpiField' );
			var column = mapping[ field ] || '';
			$( this ).val( csvHeaders.indexOf( column ) !== -1 ? column : '' );
		} );

		profileFeedback( cfg.i18n.profileLoaded, false );
		activateTab( 'import' );
	} );

	/**
	 * [DOC-085] Delete the selected profile (with confirm).
	 *
	 * The confirm() guard is deliberately low-tech: profiles are cheap
	 * to recreate but annoying to lose by mis-click, and a native
	 * confirm is exactly proportionate to that.
	 */
	$( document ).on( 'click', '#afpi-delete-profile', function () {
		var name = $( '#afpi-profile-select' ).val();

		if ( ! name || ! cfg.profiles[ name ] ) {
			profileFeedback( cfg.i18n.chooseProfile, true );
			return;
		}
		if ( ! window.confirm( cfg.i18n.confirmDelete ) ) {
			return;
		}

		$.post( cfg.ajaxUrl, {
			action: 'afpi_delete_profile',
			nonce: cfg.nonce,
			name: name,
		} ).done( function ( res ) {
			if ( res && res.success ) {
				cfg.profiles = res.data.profiles;
				renderProfileSelect();
				profileFeedback( res.data.message, false );
			} else {
				profileFeedback( ( res && res.data && res.data.message ) || cfg.i18n.requestFailed, true );
			}
		} );
	} );

	/* ------------------------------------------------------------------
	 * Boot
	 * ------------------------------------------------------------------ */

	$( function () {
		renderProfileSelect();

		// Reopen the tab from the URL hash (set by activateTab) so a
		// refresh lands where the user was.
		var hash = window.location.hash.replace( '#', '' );
		if ( [ 'import', 'profiles', 'history', 'settings' ].indexOf( hash ) !== -1 ) {
			activateTab( hash );
		}
	} );
} )( jQuery );

/**
 * [DOC-142] Affiliate Product Filter — front-end behaviour.
 *
 * Vanilla JS with zero dependencies (no jQuery): this file ships on
 * every catalog page of every cloned site, so its payload matters
 * more than the admin-side importer's, and nothing here needs more
 * than fetch/URLSearchParams/classList.
 *
 * Responsibilities: collect filter state from the controls, fetch
 * results over AJAX, keep the address bar in sync (pushState) so
 * results are shareable and the back button works, and drive the UX
 * details — chips, live counts, badge, bottom drawer, brand search,
 * Load More.
 *
 * Server config arrives via the localized `afpfConfig` object
 * ([DOC-127]); everything user-generated is inserted with
 * textContent, never innerHTML, except the product-card HTML which
 * the server rendered and escaped ([DOC-123]).
 */
( function () {
	'use strict';

	var cfg = window.afpfConfig || {};

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = document.querySelector( '.afpf-wrap' );
		if ( ! wrap || ! cfg.ajaxUrl ) {
			return;
		}
		// [DOC-180] show_filters="false" renders a static teaser with
		// none of the interactive elements this script binds to.
		if ( wrap.classList.contains( 'afpf-wrap--static' ) ) {
			return;
		}
		init( wrap );
	} );

	/**
	 * [DOC-143] Wire up one filter instance.
	 *
	 * All lookups are scoped to the wrapper element rather than the
	 * document so the plugin never collides with theme markup, and a
	 * future multi-instance page only needs an instance loop here.
	 *
	 * @param {Element} wrap The .afpf-wrap element.
	 */
	function init( wrap ) {
		var els = {
			search: wrap.querySelector( '.afpf-search-input' ),
			grid: wrap.querySelector( '[data-afpf-grid]' ),
			empty: wrap.querySelector( '[data-afpf-empty]' ),
			spinner: wrap.querySelector( '[data-afpf-spinner]' ),
			loadMore: wrap.querySelector( '[data-afpf-load-more]' ),
			chips: wrap.querySelector( '[data-afpf-chips]' ),
			badge: wrap.querySelector( '[data-afpf-badge]' ),
			showing: wrap.querySelector( '[data-afpf-showing]' ),
			total: wrap.querySelector( '[data-afpf-total]' ),
			panel: wrap.querySelector( '[data-afpf-panel]' ),
			overlay: wrap.querySelector( '.afpf-drawer-overlay' ),
		};

		var page = 1;
		var searchTimer = null;
		// The pristine empty-state message, captured before any error
		// text can overwrite it ([DOC-148]'s catch path reuses the
		// element).
		var emptyText = els.empty ? els.empty.textContent : '';

		/**
		 * [DOC-144] Read the current UI state from the controls.
		 *
		 * The DOM is the working copy of state; the URL is the durable
		 * copy. Reading checkboxes directly (instead of mirroring
		 * state in a JS object) means the two can never disagree.
		 *
		 * @return {Object} { search, tax: { paramKey: [slugs] } }.
		 */
		function readState() {
			var state = { search: els.search ? els.search.value.trim() : '', tax: {} };

			wrap.querySelectorAll( '[data-afpf-tax]:checked' ).forEach( function ( box ) {
				var key = box.getAttribute( 'data-afpf-param' );
				if ( ! state.tax[ key ] ) {
					state.tax[ key ] = [];
				}
				state.tax[ key ].push( box.value );
			} );

			return state;
		}

		/**
		 * [DOC-145] Build the POST body for the AJAX endpoint.
		 *
		 * Uses the same afpf_* parameter names as the URL scheme
		 * ([DOC-134]) so the server needs exactly one parser for both
		 * transports.
		 *
		 * @param {Object} state    Current state from readState().
		 * @param {number} pageNum  1-based page to request.
		 * @return {URLSearchParams} Request body.
		 */
		function buildBody( state, pageNum ) {
			var body = new URLSearchParams();
			body.set( 'action', 'afpf_filter' );
			body.set( 'nonce', cfg.nonce );
			body.set( 'per_page', wrap.getAttribute( 'data-per-page' ) || '24' );
			body.set( 'afpf_page', String( pageNum ) );

			if ( state.search ) {
				body.set( 'afpf_search', state.search );
			}

			Object.keys( state.tax ).forEach( function ( key ) {
				body.set( 'afpf_' + key, state.tax[ key ].join( ',' ) );
			} );

			var lockedTax = wrap.getAttribute( 'data-locked-taxonomy' );
			var lockedTerm = wrap.getAttribute( 'data-locked-term' );
			if ( lockedTax && lockedTerm ) {
				body.set( 'afpf_locked_taxonomy', lockedTax );
				body.set( 'afpf_locked_term', lockedTerm );
			}

			return body;
		}

		/**
		 * [DOC-146] Mirror the state into the address bar.
		 *
		 * pushState (not replaceState) so every filter change is a
		 * back-button stop — matching how users treat filtered views
		 * as "pages". The page number never goes into the URL: Load
		 * More is additive display state, and a shared link should
		 * open at the natural first page.
		 *
		 * @param {Object} state Current state.
		 */
		function syncURL( state ) {
			var params = new URLSearchParams( window.location.search );

			params.delete( 'afpf_search' );
			Object.keys( cfg.taxonomies || {} ).forEach( function ( key ) {
				params.delete( 'afpf_' + key );
			} );

			if ( state.search ) {
				params.set( 'afpf_search', state.search );
			}
			Object.keys( state.tax ).forEach( function ( key ) {
				params.set( 'afpf_' + key, state.tax[ key ].join( ',' ) );
			} );

			var query = params.toString();
			window.history.pushState( { afpf: true }, '', query ? '?' + query : window.location.pathname );
		}

		/**
		 * [DOC-147] Push URL state back INTO the controls (popstate).
		 *
		 * The inverse of syncURL, used when the visitor navigates
		 * back/forward: the URL is authoritative, the controls are
		 * updated to match, then results are refetched WITHOUT another
		 * pushState (that would corrupt the history stack).
		 */
		function applyURLToControls() {
			var params = new URLSearchParams( window.location.search );

			if ( els.search ) {
				els.search.value = params.get( 'afpf_search' ) || '';
			}

			wrap.querySelectorAll( '[data-afpf-tax]' ).forEach( function ( box ) {
				var key = box.getAttribute( 'data-afpf-param' );
				var slugs = ( params.get( 'afpf_' + key ) || '' ).split( ',' );
				box.checked = slugs.indexOf( box.value ) !== -1;
			} );
		}

		/**
		 * [DOC-148] Fetch results and paint the grid.
		 *
		 * append=true (Load More) adds cards after the existing ones;
		 * otherwise the grid is replaced. New cards get the fade-in
		 * class; a stale-response guard (request counter) drops
		 * out-of-order responses so fast typing can't paint old
		 * results over new ones.
		 *
		 * @param {boolean} append   Append instead of replace.
		 * @param {boolean} pushUrl  Whether to sync the URL after.
		 */
		var requestSeq = 0;
		function fetchResults( append, pushUrl ) {
			var state = readState();

			if ( ! append ) {
				page = 1;
			}

			var seq = ++requestSeq;

			els.spinner.hidden = false;
			els.grid.classList.add( 'afpf-loading' );
			if ( els.loadMore ) {
				els.loadMore.disabled = true;
			}

			window.fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: buildBody( state, page ).toString(),
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( seq !== requestSeq ) {
						return; // A newer request superseded this one.
					}
					if ( ! json || ! json.success ) {
						throw new Error( 'afpf request failed' );
					}
					paint( json.data, append );
					if ( pushUrl ) {
						syncURL( state );
					}
					renderChips( state );
					updateBadge( state );
				} )
				.catch( function () {
					if ( seq === requestSeq ) {
						els.empty.hidden = false;
						els.empty.textContent = cfg.i18n.error;
					}
				} )
				.then( function () {
					if ( seq === requestSeq ) {
						els.spinner.hidden = true;
						els.grid.classList.remove( 'afpf-loading' );
						if ( els.loadMore ) {
							els.loadMore.disabled = false;
						}
					}
				} );
		}

		/**
		 * [DOC-149] Apply one AJAX payload to the DOM.
		 *
		 * The card HTML was rendered and escaped server-side by the
		 * same template as the initial page ([DOC-123]); counts are
		 * plain numbers inserted via textContent.
		 *
		 * @param {Object}  data   Response payload.
		 * @param {boolean} append Append mode.
		 */
		function paint( data, append ) {
			var scratch = document.createElement( 'div' );
			scratch.innerHTML = data.html;

			var cards = Array.prototype.slice.call( scratch.children );
			cards.forEach( function ( card ) {
				card.classList.add( 'afpf-fade-in' );
			} );

			if ( ! append ) {
				els.grid.innerHTML = '';
			}
			cards.forEach( function ( card ) {
				els.grid.appendChild( card );
			} );

			els.empty.hidden = data.found > 0;
			if ( data.found === 0 ) {
				els.empty.textContent = emptyText;
			}

			if ( els.showing ) {
				els.showing.textContent = String( data.showing );
			}
			if ( els.total ) {
				els.total.textContent = String( data.found );
			}
			if ( els.loadMore ) {
				els.loadMore.hidden = ! data.has_more;
			}
		}

		/**
		 * [DOC-150] Render the removable active-filter chips.
		 *
		 * One chip per checked term (labelled with the human name the
		 * checkbox carries) plus one for the search phrase. Chips are
		 * rebuilt from state on every change — cheap at this scale and
		 * immune to drift. Each X unchecks its control and refetches,
		 * so chips and checkboxes are always two views of the same
		 * state.
		 *
		 * @param {Object} state Current state.
		 */
		function renderChips( state ) {
			els.chips.innerHTML = '';
			var count = 0;

			function addChip( label, onRemove ) {
				count++;
				var chip = document.createElement( 'span' );
				chip.className = 'afpf-chip';
				chip.appendChild( document.createTextNode( label ) );

				var x = document.createElement( 'button' );
				x.type = 'button';
				x.setAttribute( 'aria-label', 'Remove filter: ' + label );
				x.textContent = '×';
				x.addEventListener( 'click', onRemove );

				chip.appendChild( x );
				els.chips.appendChild( chip );
			}

			if ( state.search ) {
				addChip( '“' + state.search + '”', function () {
					els.search.value = '';
					fetchResults( false, true );
				} );
			}

			wrap.querySelectorAll( '[data-afpf-tax]:checked' ).forEach( function ( box ) {
				addChip( box.getAttribute( 'data-afpf-name' ) || box.value, function () {
					box.checked = false;
					fetchResults( false, true );
				} );
			} );

			els.chips.hidden = count === 0;
		}

		/**
		 * Update the mobile Filters button badge with the active count.
		 *
		 * @param {Object} state Current state.
		 */
		function updateBadge( state ) {
			var count = ( state.search ? 1 : 0 );
			Object.keys( state.tax ).forEach( function ( key ) {
				count += state.tax[ key ].length;
			} );
			els.badge.textContent = String( count );
			els.badge.hidden = count === 0;
		}

		/* --------------------------------------------------------------
		 * Event wiring.
		 * -------------------------------------------------------------- */

		// Checkbox changes fetch immediately.
		wrap.addEventListener( 'change', function ( event ) {
			if ( event.target.hasAttribute( 'data-afpf-tax' ) ) {
				fetchResults( false, true );
			}
		} );

		// [DOC-151] Search debounce: 350ms of quiet before a request.
		// Every keystroke firing a query would hammer the server and
		// paint intermediate results the visitor never wanted; 350ms
		// is under the perception threshold for "instant" but above
		// normal inter-keystroke gaps.
		if ( els.search ) {
			els.search.addEventListener( 'input', function () {
				window.clearTimeout( searchTimer );
				searchTimer = window.setTimeout( function () {
					fetchResults( false, true );
				}, 350 );
			} );
		}

		// Load More: next page, append mode, no URL change ([DOC-146]).
		if ( els.loadMore ) {
			els.loadMore.addEventListener( 'click', function () {
				page++;
				fetchResults( true, false );
			} );
		}

		// Clear All: reset every control, then one fetch.
		wrap.addEventListener( 'click', function ( event ) {
			if ( ! event.target.closest( '[data-afpf-clear]' ) ) {
				return;
			}
			if ( els.search ) {
				els.search.value = '';
			}
			wrap.querySelectorAll( '[data-afpf-tax]:checked' ).forEach( function ( box ) {
				box.checked = false;
			} );
			fetchResults( false, true );
			closeDrawer();
		} );

		// [DOC-152] Mobile drawer open/close + body scroll lock.
		function openDrawer() {
			wrap.classList.add( 'afpf-drawer-open' );
			document.body.classList.add( 'afpf-no-scroll' );
			if ( els.overlay ) {
				els.overlay.hidden = false;
			}
			var toggle = wrap.querySelector( '[data-afpf-drawer-open]' );
			if ( toggle ) {
				toggle.setAttribute( 'aria-expanded', 'true' );
			}
		}

		function closeDrawer() {
			wrap.classList.remove( 'afpf-drawer-open' );
			document.body.classList.remove( 'afpf-no-scroll' );
			if ( els.overlay ) {
				els.overlay.hidden = true;
			}
			var toggle = wrap.querySelector( '[data-afpf-drawer-open]' );
			if ( toggle ) {
				toggle.setAttribute( 'aria-expanded', 'false' );
			}
		}

		wrap.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-afpf-drawer-open]' ) ) {
				openDrawer();
			}
			if ( event.target.closest( '[data-afpf-drawer-close]' ) ) {
				closeDrawer();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				closeDrawer();
			}
		} );

		// [DOC-153] Brand group: client-side search + top-5 expand.
		// Filtering happens entirely in the DOM (show/hide labels) —
		// the full term list already shipped with the page, so a
		// server round-trip would add latency for zero fresh data.
		wrap.querySelectorAll( '[data-afpf-group-search]' ).forEach( function ( input ) {
			input.addEventListener( 'input', function () {
				var needle = input.value.trim().toLowerCase();
				var group = input.closest( '[data-afpf-group]' );

				group.classList.toggle( 'afpf-group-open', needle.length > 0 );
				group.querySelectorAll( '.afpf-check' ).forEach( function ( label ) {
					var name = ( label.textContent || '' ).toLowerCase();
					label.classList.toggle( 'afpf-check-filtered', needle.length > 0 && name.indexOf( needle ) === -1 );
				} );
			} );
		} );

		wrap.querySelectorAll( '[data-afpf-group-more]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var group = button.closest( '[data-afpf-group]' );
				var open = group.classList.toggle( 'afpf-group-open' );
				button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				button.textContent = open ? cfg.i18n.showLess : cfg.i18n.showAll;
			} );
		} );

		// [DOC-154] Back/forward: URL wins, controls follow, no push.
		window.addEventListener( 'popstate', function () {
			applyURLToControls();
			fetchResults( false, false );
		} );

		// Initial chips/badge for pre-filtered (shared URL) loads.
		var initialState = readState();
		renderChips( initialState );
		updateBadge( initialState );
	}
} )();

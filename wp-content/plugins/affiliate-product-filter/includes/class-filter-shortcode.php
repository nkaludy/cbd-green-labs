<?php
/**
 * [DOC-126] The [affiliate_filter] shortcode.
 *
 * Renders the whole filter experience — search bar, filter panel,
 * product grid — and owns asset loading. Everything the visitor first
 * sees is rendered SERVER-side from the URL's filter state, so a
 * shared/bookmarked link shows the right products even before (or
 * without) JavaScript; filter.js then takes over for live updates.
 *
 * @package Affiliate_Product_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the shortcode and conditionally enqueues the assets.
 */
class AFPF_Filter_Shortcode {

	/**
	 * Whether the current page render used the shortcode.
	 *
	 * @var bool
	 */
	private $shortcode_used = false;

	/**
	 * Hook registration.
	 */
	public function __construct() {
		add_shortcode( 'affiliate_filter', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * [DOC-127] Register (not enqueue) the assets, and enqueue early
	 * when the queried post is known to contain the shortcode.
	 *
	 * Two-stage loading keeps the promise "assets only on pages using
	 * the shortcode": has_shortcode() on the queried post catches the
	 * normal case at the proper hook (stylesheet in <head>, no flash
	 * of unstyled grid); the enqueue call inside render() is the
	 * safety net for shortcodes injected by widgets/builders that
	 * has_shortcode() can't see — those pay a footer-printed
	 * stylesheet, which beats loading the assets sitewide.
	 */
	public function register_assets() {
		wp_register_style( 'afpf-filter', AFPF_PLUGIN_URL . 'assets/filter.css', array(), AFPF_VERSION );
		wp_register_script( 'afpf-filter', AFPF_PLUGIN_URL . 'assets/filter.js', array(), AFPF_VERSION, true );

		if ( is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'affiliate_filter' ) ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * Enqueue the registered assets plus the localized JS config.
	 *
	 * The config gives filter.js everything server-side it can't know:
	 * endpoint, nonce, the param->taxonomy map ([DOC-114]) and UI
	 * strings (translatable in PHP, not hardcoded in JS).
	 */
	private function enqueue_assets() {
		wp_enqueue_style( 'afpf-filter' );
		wp_enqueue_script( 'afpf-filter' );

		wp_localize_script(
			'afpf-filter',
			'afpfConfig',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'afpf_filter' ),
				'taxonomies' => afpf_get_filter_taxonomies(),
				'i18n'       => array(
					/* translators: 1: shown count, 2: total count. */
					'showing'  => __( 'Showing %1$s of %2$s products', 'affiliate-product-filter' ),
					'loadMore' => __( 'Load More', 'affiliate-product-filter' ),
					'loading'  => __( 'Loading…', 'affiliate-product-filter' ),
					'error'    => __( 'Something went wrong — please try again.', 'affiliate-product-filter' ),
					'showAll'  => __( 'Show all', 'affiliate-product-filter' ),
					'showLess' => __( 'Show less', 'affiliate-product-filter' ),
				),
			)
		);
	}

	/**
	 * [DOC-128] Render the shortcode.
	 *
	 * Attributes:
	 *   columns      (int)    desktop grid columns 1-5, default 4
	 *                         ([DOC-184] was 3; catalog density pass)
	 *   per_page     (int)    products per load, default 40
	 *                         ([DOC-184] was 24; 4 columns x 10 rows)
	 *   taxonomy     (string) with term: locks a pre-filter the visitor
	 *   term         (string) cannot remove — e.g. a "Motormax models"
	 *                         landing page that filters WITHIN the brand.
	 *   show_filters (bool)   default true. [DOC-180] false renders a
	 *                         STATIC product teaser: no search bar, no
	 *                         filter panel, no count, no Load More, and
	 *                         the JS bails out (afpf-wrap--static class).
	 *                         Built for the Phase 11 homepage "Featured
	 *                         Products" strip, where the grid is a door
	 *                         into /catalog/, not a place to filter —
	 *                         interactive controls there would compete
	 *                         with the page's own CTAs.
	 *
	 * The initial results honour ?afpf_* URL params so shared links
	 * land pre-filtered ([DOC-134]). Column override travels as a CSS
	 * custom property consumed only by the desktop media query
	 * ([DOC-141]) so it can never break the responsive breakpoints.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string Rendered HTML.
	 */
	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'columns'      => 4,
				'per_page'     => 40,
				'taxonomy'     => '',
				'term'         => '',
				'show_filters' => 'true',
			),
			$atts,
			'affiliate_filter'
		);

		// Cap raised 4 -> 5 for the homepage teaser strip ([DOC-183]);
		// five compact cards is the widest the 1200px container fits
		// without the cards collapsing into slivers.
		$columns  = min( 5, max( 1, absint( $atts['columns'] ) ) );
		$per_page = min( 48, max( 1, absint( $atts['per_page'] ) ) );

		// FILTER_VALIDATE_BOOLEAN accepts the spellings people actually
		// type in shortcodes: "false", "0", "no", "off" all disable.
		$show_filters = filter_var( $atts['show_filters'], FILTER_VALIDATE_BOOLEAN );

		$state = AFPF_Filter_URL::current_state();

		$locked_taxonomy = sanitize_key( $atts['taxonomy'] );
		$locked_term     = sanitize_title( $atts['term'] );
		if ( '' !== $locked_taxonomy && '' !== $locked_term && taxonomy_exists( $locked_taxonomy ) ) {
			$state['locked'][ $locked_taxonomy ] = array( $locked_term );
		}

		// Safety-net enqueue for render paths wp_enqueue_scripts can't
		// predict (widgets, builders) — see [DOC-127].
		$this->enqueue_assets();

		$query   = AFPF_Filter_Query::build( $state, $per_page );
		$counts  = AFPF_Filter_Query::get_term_counts();
		$found   = (int) $query->found_posts;
		$showing = min( $found, $per_page );

		ob_start();
		?>
		<div class="afpf-wrap<?php echo $show_filters ? '' : ' afpf-wrap--static'; ?>"
			data-per-page="<?php echo esc_attr( $per_page ); ?>"
			data-locked-taxonomy="<?php echo esc_attr( $locked_taxonomy ); ?>"
			data-locked-term="<?php echo esc_attr( $locked_term ); ?>">

			<?php if ( $show_filters ) : ?>
				<div class="afpf-topbar">
					<div class="afpf-search">
						<label class="screen-reader-text" for="afpf-search-input"><?php esc_html_e( 'Search products', 'affiliate-product-filter' ); ?></label>
						<input type="search" id="afpf-search-input" class="afpf-search-input"
							placeholder="<?php esc_attr_e( 'Search products…', 'affiliate-product-filter' ); ?>"
							value="<?php echo esc_attr( $state['search'] ); ?>" />
					</div>
					<p class="afpf-count" data-afpf-count aria-live="polite">
						<?php
						// X = cards on screen, Y = products matching the
						// current filters — both numbers change together as
						// the visitor filters and Loads More.
						printf(
							/* translators: 1: shown count, 2: matching product count. */
							esc_html__( 'Showing %1$s of %2$s products', 'affiliate-product-filter' ),
							'<span data-afpf-showing>' . esc_html( number_format_i18n( $showing ) ) . '</span>',
							'<span data-afpf-total>' . esc_html( number_format_i18n( $found ) ) . '</span>'
						);
						?>
					</p>
					<button type="button" class="afpf-mobile-toggle" data-afpf-drawer-open aria-expanded="false">
						<?php esc_html_e( 'Filters', 'affiliate-product-filter' ); ?>
						<span class="afpf-badge" data-afpf-badge hidden>0</span>
					</button>
				</div>

				<div class="afpf-active-filters" data-afpf-chips hidden></div>
			<?php endif; ?>

			<div class="afpf-layout">
				<?php
				if ( $show_filters ) {
					afpf_get_template(
						'filter-panel.php',
						array(
							'counts' => $counts,
							'state'  => $state,
						)
					);
				}

				afpf_get_template(
					'product-grid.php',
					array(
						'query'    => $query,
						'columns'  => $columns,
						// A static teaser never paginates: Load More is an
						// AJAX control and the JS is disabled below.
						'has_more' => $show_filters && $query->max_num_pages > 1,
					)
				);
				?>
			</div>
		</div>
		<?php
		wp_reset_postdata();

		return ob_get_clean();
	}
}

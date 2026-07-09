<?php
/**
 * [DOC-138] Filter panel template.
 *
 * The taxonomy filter sidebar (desktop) / bottom drawer (mobile —
 * same DOM, restyled by CSS, so there is exactly one set of controls
 * to keep in sync). Copy this file to
 * {child-theme}/affiliate-product-filter/filter-panel.php to override
 * per site ([DOC-115]).
 *
 * Received variables:
 *
 * @var array $counts taxonomy => [ slug => [name, count] ] ([DOC-120]).
 * @var array $state  Current sanitized filter state ([DOC-135]).
 *
 * @package Affiliate_Product_Filter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Brand lists on a real catalog run to dozens of terms; every other
 * taxonomy here is a handful. Only 'brand' gets the in-panel search
 * box and the visible-count collapse, per the design spec — so the
 * limit below is effectively brand-only. [DOC-185] Raised 5 -> 10:
 * with a 40-product page the top-5 cut hid brands that still had
 * double-digit counts one click away.
 */
$afpf_collapsible = array( 'brand' );
$afpf_show_limit  = 10;
?>
<div class="afpf-drawer-overlay" data-afpf-drawer-close hidden></div>

<aside class="afpf-panel" data-afpf-panel aria-label="<?php esc_attr_e( 'Product filters', 'affiliate-product-filter' ); ?>">

	<div class="afpf-panel-head">
		<span class="afpf-panel-title"><?php esc_html_e( 'Filters', 'affiliate-product-filter' ); ?></span>
		<button type="button" class="afpf-drawer-close" data-afpf-drawer-close aria-label="<?php esc_attr_e( 'Close filters', 'affiliate-product-filter' ); ?>">&times;</button>
	</div>

	<?php foreach ( afpf_get_filter_taxonomies() as $afpf_key => $afpf_taxonomy ) : ?>
		<?php
		$afpf_tax_object = get_taxonomy( $afpf_taxonomy );
		$afpf_terms      = isset( $counts[ $afpf_taxonomy ] ) ? $counts[ $afpf_taxonomy ] : array();

		if ( ! $afpf_tax_object || empty( $afpf_terms ) ) {
			continue;
		}

		$afpf_selected    = isset( $state['tax'][ $afpf_taxonomy ] ) ? $state['tax'][ $afpf_taxonomy ] : array();
		$afpf_collapse    = in_array( $afpf_taxonomy, $afpf_collapsible, true ) && count( $afpf_terms ) > $afpf_show_limit;
		$afpf_group_class = 'afpf-group' . ( $afpf_collapse ? ' afpf-group-collapsed' : '' );
		?>
		<fieldset class="<?php echo esc_attr( $afpf_group_class ); ?>" data-afpf-group="<?php echo esc_attr( $afpf_taxonomy ); ?>">
			<legend class="afpf-group-title"><?php echo esc_html( $afpf_tax_object->labels->singular_name ); ?></legend>

			<?php if ( $afpf_collapse ) : ?>
				<?php
				/*
				 * The placeholder is built in a variable and echoed inside a
				 * SINGLE-LINE attribute on purpose: splitting the attribute
				 * value across indented template lines bakes literal
				 * newlines/tabs into it, and the HTML spec forbids line
				 * breaks in placeholder — browsers clip the text when they
				 * hit one (this rendered as a "truncated" input in testing).
				 */
				/* translators: %s: taxonomy plural label, lowercase. */
				$afpf_group_placeholder = sprintf( __( 'Search %s…', 'affiliate-product-filter' ), strtolower( $afpf_tax_object->labels->name ) );
				?>
				<input type="search" class="afpf-group-search" data-afpf-group-search placeholder="<?php echo esc_attr( $afpf_group_placeholder ); ?>" />
			<?php endif; ?>

			<div class="afpf-group-options">
				<?php
				$afpf_index = 0;
				foreach ( $afpf_terms as $afpf_slug => $afpf_term ) :
					++$afpf_index;
					// Checked terms always render visible so an active
					// filter can't hide behind the top-10 collapse.
					$afpf_checked  = in_array( $afpf_slug, $afpf_selected, true );
					$afpf_overflow = $afpf_collapse && $afpf_index > $afpf_show_limit && ! $afpf_checked;
					?>
					<label class="afpf-check<?php echo $afpf_overflow ? ' afpf-check-overflow' : ''; ?>">
						<input type="checkbox"
							data-afpf-tax="<?php echo esc_attr( $afpf_taxonomy ); ?>"
							data-afpf-param="<?php echo esc_attr( $afpf_key ); ?>"
							data-afpf-name="<?php echo esc_attr( $afpf_term['name'] ); ?>"
							value="<?php echo esc_attr( $afpf_slug ); ?>"
							<?php checked( $afpf_checked ); ?> />
						<span class="afpf-check-name"><?php echo esc_html( $afpf_term['name'] ); ?></span>
						<span class="afpf-check-count"><?php echo esc_html( number_format_i18n( $afpf_term['count'] ) ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>

			<?php if ( $afpf_collapse ) : ?>
				<button type="button" class="afpf-group-more" data-afpf-group-more aria-expanded="false">
					<?php
					printf(
						/* translators: %s: number of additional terms. */
						esc_html__( 'Show all (%s)', 'affiliate-product-filter' ),
						esc_html( number_format_i18n( count( $afpf_terms ) ) )
					);
					?>
				</button>
			<?php endif; ?>
		</fieldset>
	<?php endforeach; ?>

	<button type="button" class="afpf-clear-all" data-afpf-clear>
		<?php esc_html_e( 'Clear All Filters', 'affiliate-product-filter' ); ?>
	</button>
</aside>

<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="notice notice-success analyst-notice">
	<p>
		<strong class="analyst-plugin-name"><?php echo esc_html( $notice->getPluginName() ); ?></strong>
		<?php echo wp_kses_post( $notice->getBody() ); ?>
	</p>

	<button type="button" class="analyst-notice-dismiss notice-dismiss" analyst-notice-id="<?php echo esc_attr( $notice->getId() ); ?>">
		<span class="screen-reader-text">Dismiss this notice.</span>
	</button>
</div>

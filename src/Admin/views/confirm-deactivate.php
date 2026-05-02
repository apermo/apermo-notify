<?php

declare(strict_types=1);

/**
 * Confirmation screen for plugin-name deactivation.
 *
 * Variables provided by Plugin_Name\Admin\DeactivationFlow::render_confirm_page():
 *
 * @var string $deactivate    Standard WP deactivate URL (with nonce) for the "keep data" path.
 * @var string $delete_url    admin.php URL the destructive form submits to.
 * @var string $delete_action admin_action_* handler key for the destructive submission.
 * @var string $delete_nonce  Nonce value for the destructive submission.
 *
 * Adding a third "export backup before delete" card: insert another <div class="card">
 * between the two existing cards and POST to your own admin-post.php action.
 */

defined( 'ABSPATH' ) || exit();

?>
<div class="wrap">
	<h1><?php esc_html_e( 'Deactivate plugin-name', 'plugin-name' ); ?></h1>

	<p>
		<?php esc_html_e( 'Choose what should happen to data captured by this plugin.', 'plugin-name' ); ?>
	</p>

	<div class="card" style="max-width: 720px;">
		<h2><?php esc_html_e( 'Keep data (recommended)', 'plugin-name' ); ?></h2>
		<p>
			<?php
			esc_html_e(
				'Plugin settings and any captured data stay in the database. Reactivating the plugin restores everything.',
				'plugin-name',
			);
			?>
		</p>
		<p>
			<a href="<?php echo esc_url( $deactivate ); ?>" class="button button-primary">
				<?php esc_html_e( 'Deactivate (keep data)', 'plugin-name' ); ?>
			</a>
		</p>
	</div>

	<div class="card" style="max-width: 720px; border-left: 4px solid #d63638;">
		<h2 style="color: #d63638;">
			<?php esc_html_e( 'Permanently delete all plugin data', 'plugin-name' ); ?>
		</h2>
		<p><strong><?php esc_html_e( 'This cannot be undone.', 'plugin-name' ); ?></strong></p>
		<p>
			<?php
			esc_html_e(
				'Plugin options, captured data, and transients owned by this plugin are removed before the plugin is deactivated.',
				'plugin-name',
			);
			?>
		</p>

		<form method="post" action="<?php echo esc_url( $delete_url ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $delete_action ); ?>" />
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $delete_nonce ); ?>" />

			<p>
				<label>
					<input type="checkbox" name="confirm" value="1" />
					<?php esc_html_e( 'I understand all plugin-owned data will be permanently deleted.', 'plugin-name' ); ?>
				</label>
			</p>

			<p>
				<button type="submit" class="button button-secondary" style="color: #d63638; border-color: #d63638;">
					<?php esc_html_e( 'Delete data and deactivate', 'plugin-name' ); ?>
				</button>
			</p>
		</form>
	</div>
</div>

<?php
/**
 * History box on the review screen: the audit trail, newest first.
 *
 * @package Verification_Expiry\Templates
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/*
 * Set by Hpve_Review::render_box() before this file is included.
 */
/** @var \HivePress\Models\Hpve_Request $request */

$hpve_log = hivepress()->hpve_request->get_log( (int) $request->get_id() );
?>
<div class="hpve-history">
	<?php if ( ! $hpve_log ) : ?>
		<p class="description"><?php esc_html_e( 'Nothing yet.', 'verification-expiry-for-hivepress' ); ?></p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'verification-expiry-for-hivepress' ); ?></th>
					<th><?php esc_html_e( 'Who', 'verification-expiry-for-hivepress' ); ?></th>
					<th><?php esc_html_e( 'What', 'verification-expiry-for-hivepress' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $hpve_log as $hpve_line ) : ?>
					<tr>
						<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $hpve_line->get_created_date() ) ); ?></td>
						<td><?php echo esc_html( (string) $hpve_line->get_author() ); ?></td>
						<td>
							<?php echo esc_html( (string) $hpve_line->get_text() ); ?>
							<?php if ( $hpve_line->is_visible() ) : ?>
								<span class="description"><?php esc_html_e( '(shown to the applicant)', 'verification-expiry-for-hivepress' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

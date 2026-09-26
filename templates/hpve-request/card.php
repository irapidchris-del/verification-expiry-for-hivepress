<?php
/**
 * The verification status card.
 *
 * Every class is core's except three of ours (assets/css/frontend.css). The block hands in a $card
 * array of already-translated strings; this part only lays them out and escapes them.
 *
 * @package Verification_Expiry\Templates
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$hpve_card       = isset( $card ) && is_array( $card ) ? $card : [];
$hpve_show_title = ! isset( $show_title ) || $show_title;
?>
<div class="hp-block hpve-card hpve-card--<?php echo esc_attr( isset( $hpve_card['state'] ) ? $hpve_card['state'] : '' ); ?>">
	<div class="hpve-card__header">
		<?php if ( $hpve_show_title ) : ?>
			<h2 class="hp-section__title"><?php esc_html_e( 'Verification', 'verification-expiry-for-hivepress' ); ?></h2>
		<?php endif; ?>
		<?php if ( ! empty( $hpve_card['pill'] ) ) : ?>
			<span class="hp-status hp-status--<?php echo esc_attr( $hpve_card['modifier'] ); ?>"><span><?php echo esc_html( $hpve_card['pill'] ); ?></span></span>
		<?php endif; ?>
	</div>
	<?php foreach ( (array) $hpve_card['sentences'] as $hpve_sentence ) : ?>
		<p><?php echo esc_html( $hpve_sentence ); ?></p>
	<?php endforeach; ?>
	<?php if ( ! empty( $hpve_card['quote'] ) ) : ?>
		<blockquote class="hpve-card__note"><?php echo wp_kses( nl2br( esc_html( $hpve_card['quote'] ) ), [ 'br' => [] ] ); ?></blockquote>
	<?php endif; ?>
	<?php if ( ! empty( $hpve_card['footer'] ) ) : ?>
		<p><?php echo esc_html( $hpve_card['footer'] ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $hpve_card['facts'] ) ) : ?>
		<div class="hp-meta hpve-card__facts">
			<?php foreach ( (array) $hpve_card['facts'] as $hpve_label => $hpve_value ) : ?>
				<span><?php echo esc_html( $hpve_label ); ?>: <?php echo esc_html( $hpve_value ); ?></span>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<?php if ( ! empty( $hpve_card['actions'] ) ) : ?>
		<div class="hpve-card__actions">
			<?php foreach ( (array) $hpve_card['actions'] as $hpve_action ) : ?>
				<?php
				/*
				 * An action with no page to go to (Start verification with Stripe, url "#") is a real
				 * button: the script posts on click, and a button gets Space-key activation and a
				 * working `disabled` while the request runs, which a link does not. Everything with a
				 * URL stays a link.
				 */
				$hpve_is_button = empty( $hpve_action['url'] ) || '#' === $hpve_action['url'];
				?>
				<?php if ( $hpve_is_button ) : ?>
					<button type="button" class="hp-button button <?php echo esc_attr( $hpve_action['class'] ); ?>"
				<?php else : ?>
					<a href="<?php echo esc_url( $hpve_action['url'] ); ?>" class="hp-button button <?php echo esc_attr( $hpve_action['class'] ); ?>"
				<?php endif; ?>
					<?php
					if ( ! empty( $hpve_action['attrs'] ) ) {
						foreach ( (array) $hpve_action['attrs'] as $hpve_attr => $hpve_attr_value ) {
							echo ' ' . esc_attr( $hpve_attr ) . '="' . esc_attr( $hpve_attr_value ) . '"';
						}
					}
					?>
				><span><?php echo esc_html( $hpve_action['label'] ); ?></span>
				<?php if ( $hpve_is_button ) : ?>
					</button>
				<?php else : ?>
					</a>
				<?php endif; ?>
			<?php endforeach; ?>
			<span class="hpve-card__message hp-form__messages hp-form__messages--error" hidden></span>
		</div>
	<?php endif; ?>
</div>

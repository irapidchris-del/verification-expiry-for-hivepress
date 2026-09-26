<?php
/**
 * Verification block: the status card and the document form.
 *
 * Carrying a label registers a Gutenberg block AND the shortcode [hivepress_hpve_verification]
 * (components/class-editor.php:338-368, core 1.7.31), so the public Get Verified page can embed the
 * same card the account page shows. In wp-admin and block previews core renders a placeholder.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Blocks;

use HivePress\Helpers as hp;
use HivePress\Forms;
use Verification_Expiry\Logic\Hpve_Request_State as State;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Renders the verification card.
 */
class Hpve_Verification extends Block {

	/**
	 * "full" (status and form) or "button" (status and a link to the account page).
	 *
	 * @var string
	 */
	protected $mode = 'full';

	/**
	 * Class initializer.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'    => esc_html__( 'Verification', 'verification-expiry-for-hivepress' ),

				'settings' => [
					'mode' => [
						'label'    => esc_html__( 'Mode', 'verification-expiry-for-hivepress' ),
						'type'     => 'select',
						'default'  => 'full',
						'required' => true,
						'_order'   => 10,

						'options'  => [
							'full'   => esc_html__( 'Status and form', 'verification-expiry-for-hivepress' ),
							'button' => esc_html__( 'Status and button', 'verification-expiry-for-hivepress' ),
						],
					],
				],
			],
			$meta
		);

		parent::init( $meta );
	}

	/**
	 * Renders block HTML.
	 *
	 * @return string
	 */
	public function render() {
		$component = hivepress()->hpve_request;

		if ( ! $component || ! $component->is_enabled() ) {
			return '';
		}

		if ( ! is_user_logged_in() ) {
			return ( new Part(
				[
					'path'    => 'hpve-request/guest',
					'context' => [
						'login_url'    => hivepress()->router->get_return_url( 'user_login_page' ),
						'register_url' => hivepress()->router->get_url( 'user_register_page' ),
					],
				]
			) )->render();
		}

		$user_id = get_current_user_id();
		$vendor  = $component->get_vendor_for_user( $user_id );
		$request = $component->get_for_user( $user_id );

		if ( ! $request && $vendor ) {
			$request = $component->get_or_create( $user_id, $vendor->get_id() );
		}

		$state    = $component->get_card_state( $request, $vendor );
		$provider = hivepress()->hpve_provider->get_provider();
		$card     = $this->build_card( $state, $request, $vendor, $provider );

		/*
		 * A register provider needs the form even when documents are switched off, because the form
		 * is where the applicant types the company or VAT number and there is no hosted page to send
		 * them to instead. Without this the card would offer no way forward at all: form_open() is
		 * gated on documents alone, so "Documents with Automated Checks" unticked used to hide the
		 * only control the check has.
		 */
		$needs_reference = null !== hivepress()->hpve_provider->get_reference_provider();
		$form_open       = State::form_open( $state, $provider->supports_documents() || $needs_reference );

		$output = ( new Part(
			[
				'path'    => 'hpve-request/card',
				'context' => [
					'card'       => $card,
					'request'    => $request,

					// On the account page HivePress already prints "Verification" as the page title
					// (h1.hp-page__title), so a second heading on the card read as a doubled title.
					// Anywhere else (a page the owner placed the block on) the card keeps its own.
					'show_title' => 'hpve_verification_page' !== hivepress()->router->get_current_route_name(),
				],
			]
		) )->render();

		if ( $request && 'button' !== $this->mode && $form_open ) {
			$intro = trim( (string) $component->get_option( 'request_intro', '' ) );

			if ( '' !== $intro ) {
				$output .= '<div class="hp-block hpve-intro">' . wp_kses_post( wpautop( $intro ) ) . '</div>';
			}

			// Only a hosted flow has an "instead": with a register provider the form below IS the
			// check, so offering it as the fallback would read as a second, optional route.
			if ( 'manual' !== $provider->get_name() && $provider->is_configured() && ! $needs_reference ) {
				$output .= '<p class="hpve-or">' . esc_html__( 'Or send your documents to us instead:', 'verification-expiry-for-hivepress' ) . '</p>';
			}

			$output .= ( new Forms\Hpve_Request_Submit( [ 'model' => $request ] ) )->render();
		} elseif ( $request && 'pending' === $state ) {
			$output .= ( new Part(
				[
					'path'    => 'hpve-request/document-list',
					'context' => [
						'request'   => $request,
						'documents' => $component->get_documents( $request->get_id() ),
						'types'     => $component->get_document_types( true ),
					],
				]
			) )->render();
		}

		return $output;
	}

	/**
	 * Words, facts and buttons for one card state.
	 *
	 * @param string      $state Card state.
	 * @param object|null $request Request.
	 * @param object|null $vendor Vendor.
	 * @param object      $provider Active provider.
	 * @return array
	 */
	protected function build_card( $state, $request, $vendor, $provider ) {
		$component = hivepress()->hpve_request;
		$expiry    = hivepress()->hpve_verification;
		$payment   = hivepress()->hpve_payment;

		$review_days = $component->get_number_option( 'review_days', 3 );
		$retention   = $component->get_number_option( 'doc_retention_days', 30 );

		$review_note = '';

		if ( $review_days > 0 ) {
			/* translators: %d: number of working days. */
			$review_note = ', ' . sprintf( _n( 'usually within %d working day', 'usually within %d working days', $review_days, 'verification-expiry-for-hivepress' ), $review_days );
		}

		$card = [
			'state'     => $state,
			'modifier'  => State::pill_modifier( $state ),
			'pill'      => '',
			'sentences' => [],
			'quote'     => '',
			'facts'     => [],
			'actions'   => [],
			'footer'    => '',
		];

		$account_url = hivepress()->router->get_url( 'hpve_verification_page' );
		$can_buy     = $payment && $payment->has_product();

		/*
		 * "Hosted" means the applicant is sent somewhere and comes back: Stripe Identity today. A
		 * register provider is automated too but has no page to visit, so it must not reach the
		 * Start button below, which polls for a redirect URL that will never arrive.
		 */
		$needs_reference = null !== hivepress()->hpve_provider->get_reference_provider( $provider->get_name() );
		$is_hosted       = 'manual' !== $provider->get_name() && $provider->is_configured() && ! $needs_reference;

		$retention_note = '';

		if ( $retention > 0 ) {
			/* translators: %d: number of days. */
			$retention_note = ' ' . sprintf( esc_html__( 'Your documents are private: only you and the site\'s reviewers can open them, and they are deleted %d days after a decision.', 'verification-expiry-for-hivepress' ), $retention );
		} else {
			$retention_note = ' ' . esc_html__( 'Your documents are private: only you and the site\'s reviewers can open them.', 'verification-expiry-for-hivepress' );
		}

		switch ( $state ) {
			case 'no_vendor':
				$card['sentences'][] = esc_html__( 'Verification is for Vendors with a profile on this site. Add your first Listing to create your profile, then come back here.', 'verification-expiry-for-hivepress' );
				$card['actions'][]   = [
					'label' => esc_html__( 'Add a Listing', 'verification-expiry-for-hivepress' ),
					'url'   => hivepress()->router->get_url( 'listing_submit_page' ),
					'class' => 'button--primary',
				];
				break;

			case 'not_started':
				$card['pill'] = esc_html__( 'Not started', 'verification-expiry-for-hivepress' );
				/* translators: %s: a phrase such as ", usually within 3 working days" or nothing. */
				$card['sentences'][] = sprintf( esc_html__( 'A verified badge on your profile shows clients that we have checked who you are. Upload the documents below and we will review them%s.', 'verification-expiry-for-hivepress' ), $review_note ) . $retention_note;

				if ( $can_buy && ! $component->is_payment_required() && $request && ! $request->is_paid() ) {
					$card['sentences'][] = esc_html__( 'Want a faster review? Paid requests are looked at first.', 'verification-expiry-for-hivepress' );
					$card['actions'][]   = [
						'label' => esc_html__( 'Buy verification', 'verification-expiry-for-hivepress' ),
						'url'   => hivepress()->router->get_url( 'hpve_verification_buy_page' ),
						'class' => 'button--secondary',
					];
				}
				break;

			case 'payment_needed':
				$card['pill'] = esc_html__( 'Payment needed', 'verification-expiry-for-hivepress' );
				/* translators: 1: the price, 2: a phrase such as ", usually within 3 working days" or nothing. */
				$card['sentences'][] = sprintf( esc_html__( 'Verification costs %1$s. Pay once, then upload your documents and we will review them%2$s.', 'verification-expiry-for-hivepress' ), $payment ? $payment->get_price_text() : '', $review_note );
				$card['actions'][]   = [
					'label' => esc_html__( 'Buy verification', 'verification-expiry-for-hivepress' ),
					'url'   => hivepress()->router->get_url( 'hpve_verification_buy_page' ),
					'class' => 'button--primary',
				];
				break;

			case 'pending':
				$card['pill'] = esc_html__( 'Pending review', 'verification-expiry-for-hivepress' );
				/* translators: %s: a phrase such as ", usually within 3 working days" or nothing. */
				$card['sentences'][] = sprintf( esc_html__( 'Thanks, we have your documents. We will email you when the review is done%s. You cannot change your documents while they are being reviewed.', 'verification-expiry-for-hivepress' ), $review_note );

				if ( $request && $request->get_submitted_time() ) {
					$card['facts'][ esc_html__( 'Submitted', 'verification-expiry-for-hivepress' ) ] = wp_date( get_option( 'date_format' ), (int) $request->get_submitted_time() );
				}

				if ( $request && $request->is_paid() ) {
					/* translators: %s: the order number. */
					$card['facts'][ esc_html__( 'Paid', 'verification-expiry-for-hivepress' ) ] = $request->get_order_id() ? sprintf( esc_html__( 'order #%s', 'verification-expiry-for-hivepress' ), $request->get_order_id() ) : esc_html__( 'yes', 'verification-expiry-for-hivepress' );

					if ( $component->get_option( 'paid_priority', true ) ) {
						$card['sentences'][] = esc_html__( 'Paid requests are reviewed first.', 'verification-expiry-for-hivepress' );
					}
				}
				break;

			case 'processing':
				$card['pill']        = esc_html__( 'Being checked', 'verification-expiry-for-hivepress' );
				$card['sentences'][] = esc_html__( 'Stripe is checking your document. This normally takes a few minutes and we will email you when it is done.', 'verification-expiry-for-hivepress' );
				break;

			case 'needs_info':
				$card['pill']        = esc_html__( 'More information needed', 'verification-expiry-for-hivepress' );
				$card['sentences'][] = esc_html__( 'We looked at your documents but need something more before we can approve you:', 'verification-expiry-for-hivepress' );
				$card['quote']       = $request ? (string) $request->get_note() : '';
				$card['footer']      = esc_html__( 'Update your documents below and send them again.', 'verification-expiry-for-hivepress' );
				break;

			case 'verified':
				$card['pill']        = esc_html__( 'Verified', 'verification-expiry-for-hivepress' );
				$card['sentences'][] = esc_html__( 'Your profile is verified and the badge is showing.', 'verification-expiry-for-hivepress' );

				$until = $vendor ? (string) get_post_meta( $vendor->get_id(), \HivePress\Components\Hpve_Verification::META_UNTIL, true ) : '';

				if ( '' !== $until && $expiry ) {
					$reminder = hpve_get_number_option( HPVE_OPTION_PREFIX . 'reminder_days', 7 );

					if ( $reminder > 0 ) {
						/* translators: 1: a date, 2: number of days. */
						$card['sentences'][] = sprintf( esc_html__( 'Your verification is due for review on %1$s, and we will email you %2$d days before.', 'verification-expiry-for-hivepress' ), $expiry->format_date( $until ), $reminder );
					} else {
						/* translators: %s: a date. */
						$card['sentences'][] = sprintf( esc_html__( 'Your verification is due for review on %s.', 'verification-expiry-for-hivepress' ), $expiry->format_date( $until ) );
					}
				} else {
					$card['sentences'][] = esc_html__( 'It does not expire.', 'verification-expiry-for-hivepress' );
				}

				if ( $request && $request->get_reviewed_time() ) {
					$card['facts'][ esc_html__( 'Approved', 'verification-expiry-for-hivepress' ) ] = wp_date( get_option( 'date_format' ), (int) $request->get_reviewed_time() );
				}

				if ( $vendor && 'publish' === (string) $vendor->get_status() ) {
					$card['actions'][] = [
						'label' => esc_html__( 'View your profile', 'verification-expiry-for-hivepress' ),
						'url'   => hivepress()->router->get_url( 'vendor_view_page', [ 'vendor_id' => $vendor->get_id() ] ),
						'class' => 'button--secondary',
					];
				}

				if ( $request && $request->get_docs_deleted_time() ) {
					$card['footer'] = esc_html__( 'Your documents have been deleted.', 'verification-expiry-for-hivepress' );
				} elseif ( $request && $request->get_reviewed_time() && $retention > 0 && $component->count_documents( $request->get_id() ) ) {
					/* translators: %s: a date. */
					$card['footer'] = sprintf( esc_html__( 'Your documents will be deleted on %s.', 'verification-expiry-for-hivepress' ), wp_date( get_option( 'date_format' ), (int) $request->get_reviewed_time() + $retention * DAY_IN_SECONDS ) );
				}
				break;

			case 'renewal_due':
			case 'renewing':
				$card['pill'] = 'renewal_due' === $state ? esc_html__( 'Renewal due', 'verification-expiry-for-hivepress' ) : esc_html__( 'Renewing', 'verification-expiry-for-hivepress' );

				$until = $vendor ? (string) get_post_meta( $vendor->get_id(), \HivePress\Components\Hpve_Verification::META_UNTIL, true ) : '';

				/* translators: 1: a date, 2: a phrase such as ", usually within 3 working days" or nothing. */
				$card['sentences'][] = sprintf( esc_html__( 'Your verification is due for review on %1$s. Upload up-to-date documents below and we will review them%2$s. Your badge stays on while we do, and a renewal adds a full period from that date, so renewing early loses nothing.', 'verification-expiry-for-hivepress' ), $expiry ? $expiry->format_date( $until ) : $until, $review_note ) . $retention_note;

				if ( '' !== $until && $expiry ) {
					$card['facts'][ esc_html__( 'Due for review', 'verification-expiry-for-hivepress' ) ] = $expiry->format_date( $until );
				}

				// A request verified before payment was switched on has never been paid for.
				if ( $can_buy && $component->is_payment_required() && $request && ! $request->is_paid() ) {
					$card['actions'][] = [
						'label' => esc_html__( 'Buy verification', 'verification-expiry-for-hivepress' ),
						'url'   => hivepress()->router->get_url( 'hpve_verification_buy_page' ),
						'class' => 'button--primary',
					];
				}
				break;

			case 'rejected':
				$card['pill']        = esc_html__( 'Not approved', 'verification-expiry-for-hivepress' );
				$card['sentences'][] = esc_html__( 'We could not verify you this time:', 'verification-expiry-for-hivepress' );
				$card['quote']       = $request ? (string) $request->get_reason() : '';
				$card['footer']      = esc_html__( 'You can apply again with different documents below. If you think this is a mistake, please contact us.', 'verification-expiry-for-hivepress' );
				break;

			case 'expired':
				$card['pill'] = esc_html__( 'Expired', 'verification-expiry-for-hivepress' );

				$expired = $vendor ? absint( get_post_meta( $vendor->get_id(), \HivePress\Components\Hpve_Verification::META_EXPIRED, true ) ) : 0;

				/* translators: %s: a date. */
				$card['sentences'][] = sprintf( esc_html__( 'Your verification expired on %s and the badge has been removed. Upload your documents again below to get it back.', 'verification-expiry-for-hivepress' ), $expired ? wp_date( get_option( 'date_format' ), $expired ) : '' );
				break;

			case 'cancelled':
				$card['pill']        = esc_html__( 'Not started', 'verification-expiry-for-hivepress' );
				$card['sentences'][] = esc_html__( 'Your Stripe check was cancelled. You can start again below.', 'verification-expiry-for-hivepress' );
				break;
		}

		// The automated route, offered on every open state.
		if ( $request && $is_hosted && State::form_open( $state, true ) && 'payment_needed' !== $state ) {
			$limit    = $component->get_number_option( 'stripe_attempt_limit', 3 );
			$attempts = (int) $request->get_provider_attempts();

			if ( $attempts < max( 1, $limit ) ) {
				$selfie = $component->get_option( 'stripe_require_selfie', true ) ? esc_html__( ' and take a selfie', 'verification-expiry-for-hivepress' ) : '';

				/* translators: %s: " and take a selfie" or nothing. */
				$card['sentences'][] = sprintf( esc_html__( 'You will be taken to Stripe\'s secure page to photograph your ID%s. It takes about two minutes and you come straight back here.', 'verification-expiry-for-hivepress' ), $selfie );

				$card['actions'][] = [
					'label' => esc_html__( 'Start verification with Stripe', 'verification-expiry-for-hivepress' ),
					'url'   => '#',
					'class' => 'button--primary',
					'attrs' => [
						'data-hpve-start' => hivepress()->router->get_url( 'hpve_request_start_action', [ 'request_id' => $request->get_id() ] ),
						'data-hpve-poll'  => hivepress()->router->get_url( 'hpve_request_redirect_action', [ 'request_id' => $request->get_id() ] ),
					],
				];
			}
		}

		// The short version for the public page.
		if ( 'button' === $this->mode && $request && State::form_open( $state, $provider->supports_documents() || $needs_reference ) ) {
			$card['actions'][] = [
				'label' => esc_html__( 'Get verified', 'verification-expiry-for-hivepress' ),
				'url'   => $account_url,
				'class' => 'button--primary',
			];
		}

		return $card;
	}
}

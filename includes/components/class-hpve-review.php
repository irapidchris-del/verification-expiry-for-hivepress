<?php
/**
 * Review component: the Verifications queue in wp-admin.
 *
 * The menu entry and its pending badge come from core (components/class-admin.php:166-177, core
 * 1.7.31) because the post type registers with no show_in_menu. Everything else here is the list
 * table, the review screen and the two admin-post handlers. Decisions only ever go through
 * hivepress()->hpve_request->apply_event(); the Publish box is removed so nobody publishes a
 * request by accident, and reviewer text is written only by the nonce-checked handler.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;
use HivePress\Models;
use Verification_Expiry\Logic\Hpve_Document_Types as Doc_Types;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Admin review.
 *
 * @class Hpve_Review
 */
final class Hpve_Review extends Component {

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {
		if ( ! is_admin() ) {
			return;
		}

		// List table.
		add_filter( 'manage_' . Hpve_Request::POST_TYPE . '_posts_columns', [ $this, 'add_columns' ] );
		add_action( 'manage_' . Hpve_Request::POST_TYPE . '_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
		add_filter( 'manage_edit-' . Hpve_Request::POST_TYPE . '_sortable_columns', [ $this, 'add_sortable_columns' ] );
		add_filter( 'display_post_states', [ $this, 'add_post_states' ], 10, 2 );
		add_filter( 'views_edit-' . Hpve_Request::POST_TYPE, [ $this, 'relabel_views' ] );
		add_action( 'restrict_manage_posts', [ $this, 'render_filter' ] );
		add_action( 'pre_get_posts', [ $this, 'alter_query' ] );
		add_filter( 'post_row_actions', [ $this, 'add_row_actions' ], 10, 2 );
		add_filter( 'bulk_actions-edit-' . Hpve_Request::POST_TYPE, [ $this, 'add_bulk_actions' ] );
		add_filter( 'handle_bulk_actions-edit-' . Hpve_Request::POST_TYPE, [ $this, 'handle_bulk_actions' ], 10, 3 );
		add_action( 'admin_notices', [ $this, 'render_notice' ] );

		// Review screen.
		add_action( 'add_meta_boxes_' . Hpve_Request::POST_TYPE, [ $this, 'add_meta_boxes' ] );
		add_filter( 'enter_title_here', [ $this, 'set_title_placeholder' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Handlers.
		add_action( 'admin_post_hpve_review', [ $this, 'handle_review' ] );
		add_action( 'admin_post_hpve_delete_documents', [ $this, 'handle_delete_documents' ] );

		// Vendor edit screen: a note when a request is open.
		add_action( 'admin_notices', [ $this, 'render_vendor_notice' ] );

		parent::__construct( $args );
	}

	/*
	--------------------------------------------------------------------------
	Helpers.
	--------------------------------------------------------------------------
	*/

	/**
	 * The edit screen URL of a request.
	 *
	 * @param int $request_id Request ID.
	 * @return string
	 */
	public function get_review_url( $request_id ) {
		return admin_url( 'post.php?post=' . absint( $request_id ) . '&action=edit' );
	}

	/**
	 * A translated word for the card state, for the admin.
	 *
	 * @param string $state Card state.
	 * @return string
	 */
	public function get_state_label( $state ) {
		$labels = [
			'not_started'    => esc_html__( 'Draft', 'verification-expiry-for-hivepress' ),
			'payment_needed' => esc_html__( 'Awaiting payment', 'verification-expiry-for-hivepress' ),
			'pending'        => esc_html__( 'Pending review', 'verification-expiry-for-hivepress' ),
			'processing'     => esc_html__( 'Being checked', 'verification-expiry-for-hivepress' ),
			'needs_info'     => esc_html__( 'Needs information', 'verification-expiry-for-hivepress' ),
			'verified'       => esc_html__( 'Approved', 'verification-expiry-for-hivepress' ),
			'rejected'       => esc_html__( 'Not approved', 'verification-expiry-for-hivepress' ),
			'expired'        => esc_html__( 'Expired', 'verification-expiry-for-hivepress' ),
			'cancelled'      => esc_html__( 'Cancelled', 'verification-expiry-for-hivepress' ),
			'no_vendor'      => esc_html__( 'No Vendor', 'verification-expiry-for-hivepress' ),
		];

		return isset( $labels[ $state ] ) ? $labels[ $state ] : $state;
	}

	/**
	 * The admin pill for a card state, drawn with wp-admin's own status classes.
	 *
	 * @param string $state Card state.
	 * @return string HTML.
	 */
	public function render_pill( $state ) {
		$class = 'hpve-pill';

		if ( in_array( $state, [ 'pending', 'processing', 'needs_info' ], true ) ) {
			$class .= ' hpve-pill--pending';
		} elseif ( 'verified' === $state ) {
			$class .= ' hpve-pill--approved';
		} elseif ( in_array( $state, [ 'rejected', 'expired' ], true ) ) {
			$class .= ' hpve-pill--closed';
		}

		return '<span class="' . esc_attr( $class ) . '">' . esc_html( $this->get_state_label( $state ) ) . '</span>';
	}

	/**
	 * The payment description of a request.
	 *
	 * @param object $request Request.
	 * @return string
	 */
	public function get_payment_text( $request ) {
		$state = (string) $request->get_payment_state();

		if ( 'refunded' === $state ) {
			return esc_html__( 'Refunded', 'verification-expiry-for-hivepress' );
		}

		if ( $request->get_subscription_id() ) {
			/* translators: %s: the subscription number. */
			return sprintf( esc_html__( 'Subscription #%s', 'verification-expiry-for-hivepress' ), $request->get_subscription_id() ) . ( 'paid' !== $state && '' !== $state ? ' (' . esc_html( $state ) . ')' : '' );
		}

		if ( $request->is_paid() ) {
			/* translators: %s: the order number. */
			return $request->get_order_id() ? sprintf( esc_html__( 'Paid, order #%s', 'verification-expiry-for-hivepress' ), $request->get_order_id() ) : esc_html__( 'Paid', 'verification-expiry-for-hivepress' );
		}

		return esc_html__( 'Free', 'verification-expiry-for-hivepress' );
	}

	/**
	 * The second line of the Vendors screen column, or nothing.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return string HTML.
	 */
	public function get_vendor_column_line( $vendor_id ) {
		$request = hivepress()->hpve_request->get_for_vendor( $vendor_id );

		if ( ! $request ) {
			return '';
		}

		$state = hivepress()->hpve_request->get_card_state( $request, null );
		$text  = '';

		switch ( $state ) {
			case 'pending':
			case 'processing':
				/* translators: %s: a date. */
				$text = $request->get_submitted_time() ? sprintf( esc_html__( 'Request pending since %s', 'verification-expiry-for-hivepress' ), wp_date( get_option( 'date_format' ), (int) $request->get_submitted_time() ) ) : esc_html__( 'Request pending', 'verification-expiry-for-hivepress' );
				break;
			case 'needs_info':
				$text = esc_html__( 'Needs information', 'verification-expiry-for-hivepress' );
				break;
			case 'rejected':
				/* translators: %s: a date. */
				$text = $request->get_reviewed_time() ? sprintf( esc_html__( 'Not approved %s', 'verification-expiry-for-hivepress' ), wp_date( get_option( 'date_format' ), (int) $request->get_reviewed_time() ) ) : esc_html__( 'Not approved', 'verification-expiry-for-hivepress' );
				break;
		}

		if ( 'refunded' === (string) $request->get_payment_state() ) {
			$text .= ( '' !== $text ? ', ' : '' ) . esc_html__( 'Refunded', 'verification-expiry-for-hivepress' );
		}

		if ( '' === $text ) {
			return '';
		}

		return '<a href="' . esc_url( $this->get_review_url( $request->get_id() ) ) . '">' . esc_html( $text ) . '</a>';
	}

	/*
	--------------------------------------------------------------------------
	List table.
	--------------------------------------------------------------------------
	*/

	/**
	 * Replaces the default columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_columns( $columns ) {
		return [
			'cb'             => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
			'title'          => esc_html__( 'Applicant', 'verification-expiry-for-hivepress' ),
			'hpve_status'    => esc_html__( 'Status', 'verification-expiry-for-hivepress' ),
			'hpve_documents' => esc_html__( 'Documents', 'verification-expiry-for-hivepress' ),
			'hpve_paid'      => esc_html__( 'Paid', 'verification-expiry-for-hivepress' ),
			'hpve_submitted' => esc_html__( 'Submitted', 'verification-expiry-for-hivepress' ),
			'hpve_reviewer'  => esc_html__( 'Reviewed by', 'verification-expiry-for-hivepress' ),
		];
	}

	/**
	 * Renders one column.
	 *
	 * @param string $column Column name.
	 * @param int    $post_id Request ID.
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		$request = hivepress()->hpve_request->get_request( $post_id );

		if ( ! $request ) {
			return;
		}

		switch ( $column ) {
			case 'hpve_status':
				echo $this->render_pill( hivepress()->hpve_request->get_card_state( $request, null ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render_pill().

				$vendor_id = (int) $request->get_vendor__id();

				if ( $vendor_id ) {
					echo '<br><a href="' . esc_url( get_edit_post_link( $vendor_id ) ) . '">' . esc_html( get_the_title( $vendor_id ) ) . '</a>';
				} else {
					echo '<br><span class="description">' . esc_html__( 'No Vendor profile yet', 'verification-expiry-for-hivepress' ) . '</span>';
				}
				break;

			case 'hpve_documents':
				if ( 'stripe_identity' === (string) $request->get_provider() && $request->get_provider_ref() ) {
					echo esc_html( 'Stripe' );
				} else {
					$counts = hivepress()->hpve_request->count_documents( $post_id );
					$types  = hivepress()->hpve_request->get_document_types();

					/* translators: 1: number of document types with a file, 2: number of document types. */
					echo esc_html( sprintf( __( '%1$d of %2$d types', 'verification-expiry-for-hivepress' ), count( array_filter( $counts ) ), count( $types ) ) );
				}
				break;

			case 'hpve_paid':
				echo esc_html( $this->get_payment_text( $request ) );
				break;

			case 'hpve_submitted':
				echo $request->get_submitted_time() ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $request->get_submitted_time() ) ) : '&mdash;';
				break;

			case 'hpve_reviewer':
				$reviewer_id = (int) $request->get_reviewer__id();

				if ( $request->get_reviewed_time() ) {
					$user = $reviewer_id ? get_userdata( $reviewer_id ) : null;
					$name = $user ? $user->display_name : ( 'stripe_identity' === (string) $request->get_provider() ? 'Stripe Identity' : esc_html__( 'System', 'verification-expiry-for-hivepress' ) );

					echo esc_html( $name ) . '<br>' . esc_html( wp_date( get_option( 'date_format' ), (int) $request->get_reviewed_time() ) );
				} else {
					echo '&mdash;';
				}
				break;
		}
	}

	/**
	 * Makes Submitted sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public function add_sortable_columns( $columns ) {
		$columns['hpve_submitted'] = 'hpve_submitted';

		return $columns;
	}

	/**
	 * Adds "Paid" and "Stripe" after the title.
	 *
	 * @param array    $states States.
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	public function add_post_states( $states, $post ) {
		if ( ! $post || Hpve_Request::POST_TYPE !== $post->post_type ) {
			return $states;
		}

		if ( get_post_meta( $post->ID, 'hp_hpve_paid', true ) ) {
			$states['hpve_paid'] = esc_html__( 'Paid', 'verification-expiry-for-hivepress' );
		}

		if ( 'stripe_identity' === (string) get_post_meta( $post->ID, 'hp_hpve_provider', true ) ) {
			$states['hpve_stripe'] = 'Stripe';
		}

		return $states;
	}

	/**
	 * Relabels the status views.
	 *
	 * @param array $views Views.
	 * @return array
	 */
	public function relabel_views( $views ) {
		$labels = [
			'pending' => esc_html__( 'Pending', 'verification-expiry-for-hivepress' ),
			'publish' => esc_html__( 'Approved', 'verification-expiry-for-hivepress' ),
			'draft'   => esc_html__( 'Needs action', 'verification-expiry-for-hivepress' ),
		];

		foreach ( $labels as $key => $label ) {
			if ( isset( $views[ $key ] ) ) {
				$views[ $key ] = preg_replace( '/>[^<]*<span class="count">/', '>' . $label . ' <span class="count">', $views[ $key ], 1 );
			}
		}

		return $views;
	}

	/**
	 * The sub-state dropdown beside the date filter.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	public function render_filter( $post_type ) {
		if ( Hpve_Request::POST_TYPE !== $post_type ) {
			return;
		}

		$current = isset( $_GET['hpve_filter'] ) ? sanitize_key( wp_unslash( $_GET['hpve_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.

		$options = [
			''           => esc_html__( 'All requests', 'verification-expiry-for-hivepress' ),
			'needs_info' => esc_html__( 'Needs information', 'verification-expiry-for-hivepress' ),
			'rejected'   => esc_html__( 'Not approved', 'verification-expiry-for-hivepress' ),
			'expired'    => esc_html__( 'Expired', 'verification-expiry-for-hivepress' ),
			'paid'       => esc_html__( 'Paid only', 'verification-expiry-for-hivepress' ),
		];

		echo '<select name="hpve_filter">';

		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		echo '</select>';
	}

	/**
	 * Applies the sub-state filter, the default order and the Submitted sort.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public function alter_query( $query ) {
		if ( ! $query->is_main_query() || Hpve_Request::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		global $pagenow;

		if ( 'edit.php' !== $pagenow ) {
			return;
		}

		$meta_query = array_filter( (array) $query->get( 'meta_query' ) );

		$filter = isset( $_GET['hpve_filter'] ) ? sanitize_key( wp_unslash( $_GET['hpve_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.

		if ( in_array( $filter, [ 'needs_info', 'rejected', 'expired' ], true ) ) {
			$meta_query[] = [
				'key'   => 'hp_hpve_outcome',
				'value' => $filter,
			];
		} elseif ( 'paid' === $filter ) {
			$meta_query[] = [
				'key'     => 'hp_hpve_paid',
				'compare' => 'EXISTS',
			];
		}

		$orderby = (string) $query->get( 'orderby' );

		if ( 'hpve_submitted' === $orderby ) {
			$query->set( 'meta_key', 'hp_hpve_submitted_time' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the admin list of a small post type.
			$query->set( 'orderby', 'meta_value_num' );
		} elseif ( '' === $orderby ) {

			// Paid first, then the oldest submission, so the top row is the next one to do.
			$meta_query['hpve_submitted'] = [
				'key'     => 'hp_hpve_submitted_time',
				'compare' => 'EXISTS',
				'type'    => 'NUMERIC',
			];

			$query->set(
				'orderby',
				[
					'menu_order'     => 'DESC',
					'hpve_submitted' => 'ASC',
				]
			);
		}

		if ( $meta_query ) {
			$query->set( 'meta_query', $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the admin list of a small post type.
		}
	}

	/**
	 * Row actions: Review, Approve (nonce-checked admin-post), Reject.
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	public function add_row_actions( $actions, $post ) {
		if ( ! $post || Hpve_Request::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		unset( $actions['inline hide-if-no-js'], $actions['view'] );

		$new = [];

		if ( current_user_can( 'edit_post', $post->ID ) ) {
			$new['edit'] = '<a href="' . esc_url( $this->get_review_url( $post->ID ) ) . '">' . esc_html__( 'Review', 'verification-expiry-for-hivepress' ) . '</a>';

			if ( 'pending' === $post->post_status ) {
				$approve_url = wp_nonce_url( admin_url( 'admin-post.php?action=hpve_review&decision=approve&request_id=' . (int) $post->ID ), 'hpve_review_' . (int) $post->ID );

				$new['hpve_approve'] = '<a href="' . esc_url( $approve_url ) . '">' . esc_html__( 'Approve', 'verification-expiry-for-hivepress' ) . '</a>';
				$new['hpve_reject']  = '<a href="' . esc_url( $this->get_review_url( $post->ID ) . '#hpve_reason' ) . '">' . esc_html__( 'Reject', 'verification-expiry-for-hivepress' ) . '</a>';
			}
		}

		unset( $actions['edit'] );

		return $new + $actions;
	}

	/**
	 * Bulk actions: Approve only. A rejection needs a reason the applicant can act on.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public function add_bulk_actions( $actions ) {
		unset( $actions['edit'] );

		$actions['hpve_approve'] = esc_html__( 'Approve', 'verification-expiry-for-hivepress' );

		return $actions;
	}

	/**
	 * Handles bulk Approve. WordPress checked the list table's nonce before this filter fires.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action Action.
	 * @param array  $post_ids Selected IDs.
	 * @return string
	 */
	public function handle_bulk_actions( $redirect, $action, $post_ids ) {
		if ( 'hpve_approve' !== $action ) {
			return $redirect;
		}

		$approved = 0;
		$skipped  = 0;

		foreach ( (array) $post_ids as $post_id ) {
			$post_id = absint( $post_id );

			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
				++$skipped;

				continue;
			}

			if ( true === hivepress()->hpve_request->apply_event( $post_id, 'approve' ) ) {
				++$approved;
			} else {
				++$skipped;
			}
		}

		return add_query_arg(
			[
				'hpve_notice'   => 'bulk_approved',
				'hpve_approved' => $approved,
				'hpve_skipped'  => $skipped,
			],
			$redirect
		);
	}

	/**
	 * The notice after a decision or a bulk action.
	 *
	 * @return void
	 */
	public function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only, worded from query arguments after a redirect.
		if ( ! isset( $_GET['hpve_notice'] ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['hpve_notice'] ) );
		$name   = isset( $_GET['hpve_name'] ) ? sanitize_text_field( wp_unslash( $_GET['hpve_name'] ) ) : '';
		$until  = isset( $_GET['hpve_until'] ) ? sanitize_text_field( wp_unslash( $_GET['hpve_until'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$class   = 'notice-success';
		$message = '';

		switch ( $notice ) {
			case 'approved':
				/* translators: 1: the applicant's name, 2: a date. */
				$message = '' !== $until ? sprintf( esc_html__( 'Approved. %1$s is now verified until %2$s.', 'verification-expiry-for-hivepress' ), $name, $until ) : sprintf( esc_html__( 'Approved. %s is now verified.', 'verification-expiry-for-hivepress' ), $name );
				break;
			case 'needs_info':
				$message = esc_html__( 'The applicant has been asked for more information.', 'verification-expiry-for-hivepress' );
				break;
			case 'rejected':
				$message = esc_html__( 'The request was not approved and the applicant has been told why.', 'verification-expiry-for-hivepress' );
				break;
			case 'reopened':
				$message = esc_html__( 'The request is open for review again.', 'verification-expiry-for-hivepress' );
				break;
			case 'documents_deleted':
				$message = esc_html__( 'The documents have been deleted.', 'verification-expiry-for-hivepress' );
				break;
			case 'bulk_approved':
				$approved = isset( $_GET['hpve_approved'] ) ? absint( $_GET['hpve_approved'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$skipped  = isset( $_GET['hpve_skipped'] ) ? absint( $_GET['hpve_skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				/* translators: 1: number approved, 2: number skipped. */
				$message = sprintf( esc_html__( '%1$d request(s) approved, %2$d skipped (not pending, no Vendor profile, or not yours to approve).', 'verification-expiry-for-hivepress' ), $approved, $skipped );
				break;
			case 'no_vendor':
				$class   = 'notice-error';
				$message = esc_html__( 'This applicant has no Vendor profile yet, so there is no Verified box to tick. Ask them to add a Listing first.', 'verification-expiry-for-hivepress' );
				break;
			case 'reason_required':
				$class   = 'notice-error';
				$message = esc_html__( 'A reason is needed before a request can be rejected; it is sent to the applicant.', 'verification-expiry-for-hivepress' );
				break;
			case 'invalid':
				$class   = 'notice-error';
				$message = esc_html__( 'That decision is not possible in the request\'s current state. Reload the page to see it.', 'verification-expiry-for-hivepress' );
				break;
			default:
				return;
		}

		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/*
	--------------------------------------------------------------------------
	Review screen.
	--------------------------------------------------------------------------
	*/

	/**
	 * Replaces the Publish box with the five review boxes.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function add_meta_boxes( $post ) {
		remove_meta_box( 'submitdiv', Hpve_Request::POST_TYPE, 'side' );
		remove_meta_box( 'slugdiv', Hpve_Request::POST_TYPE, 'normal' );

		add_meta_box( 'hpve_decision', esc_html__( 'Decision', 'verification-expiry-for-hivepress' ), [ $this, 'render_box' ], Hpve_Request::POST_TYPE, 'normal', 'high', [ 'part' => 'decision' ] );
		add_meta_box( 'hpve_documents', esc_html__( 'Documents', 'verification-expiry-for-hivepress' ), [ $this, 'render_box' ], Hpve_Request::POST_TYPE, 'normal', 'high', [ 'part' => 'documents' ] );
		add_meta_box( 'hpve_history', esc_html__( 'History', 'verification-expiry-for-hivepress' ), [ $this, 'render_box' ], Hpve_Request::POST_TYPE, 'normal', 'default', [ 'part' => 'history' ] );
		add_meta_box( 'hpve_applicant', esc_html__( 'Applicant', 'verification-expiry-for-hivepress' ), [ $this, 'render_box' ], Hpve_Request::POST_TYPE, 'side', 'high', [ 'part' => 'applicant' ] );

		if ( class_exists( 'WooCommerce' ) ) {
			add_meta_box( 'hpve_payment', esc_html__( 'Payment', 'verification-expiry-for-hivepress' ), [ $this, 'render_box' ], Hpve_Request::POST_TYPE, 'side', 'default', [ 'part' => 'payment' ] );
		}
	}

	/**
	 * Renders one box from templates/hpve-request/admin/{part}.php.
	 *
	 * @param \WP_Post $post Post.
	 * @param array    $box Box arguments.
	 * @return void
	 */
	public function render_box( $post, $box ) {
		$request = hivepress()->hpve_request->get_request( $post->ID );

		if ( ! $request ) {
			return;
		}

		$part = isset( $box['args']['part'] ) ? sanitize_key( $box['args']['part'] ) : '';
		$file = plugin_dir_path( HPVE_FILE ) . 'templates/hpve-request/admin/' . $part . '.php';

		if ( '' === $part || ! file_exists( $file ) ) {
			return;
		}

		$review = $this;
		$state  = hivepress()->hpve_request->get_card_state( $request, null );

		include $file;
	}

	/**
	 * The title is generated, so the placeholder says so.
	 *
	 * @param string   $text Placeholder.
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public function set_title_placeholder( $text, $post ) {
		if ( $post && Hpve_Request::POST_TYPE === $post->post_type ) {
			return esc_html__( 'Verification request', 'verification-expiry-for-hivepress' );
		}

		return $text;
	}

	/**
	 * Loads the admin stylesheet on the two request screens.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || Hpve_Request::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$path = plugin_dir_path( HPVE_FILE );
		$url  = plugin_dir_url( HPVE_FILE );

		wp_enqueue_style( 'hpve-backend', $url . 'assets/css/backend.css', [], HPVE_VERSION . '.' . (int) filemtime( $path . 'assets/css/backend.css' ) );
		wp_enqueue_script( 'hpve-backend', $url . 'assets/js/backend.js', [ 'jquery' ], HPVE_VERSION . '.' . (int) filemtime( $path . 'assets/js/backend.js' ), true );
	}

	/*
	--------------------------------------------------------------------------
	Handlers.
	--------------------------------------------------------------------------
	*/

	/**
	 * Approve, ask for more information, reject or re-open. Nonce and capability first.
	 *
	 * @return void
	 */
	public function handle_review() {
		$request_id = isset( $_REQUEST['request_id'] ) ? absint( $_REQUEST['request_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked on the next line.

		check_admin_referer( 'hpve_review_' . $request_id );

		if ( ! $request_id || ! current_user_can( 'edit_post', $request_id ) ) {
			wp_die( esc_html__( 'You are not allowed to review this request.', 'verification-expiry-for-hivepress' ), '', [ 'response' => 403 ] );
		}

		$decision = isset( $_REQUEST['decision'] ) ? sanitize_key( wp_unslash( $_REQUEST['decision'] ) ) : '';
		$note     = isset( $_POST['hpve_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['hpve_note'] ) ) : '';
		$reason   = isset( $_POST['hpve_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['hpve_reason'] ) ) : '';

		$request = hivepress()->hpve_request->get_request( $request_id );
		$back    = wp_get_referer() ? wp_get_referer() : $this->get_review_url( $request_id );
		$back    = remove_query_arg( [ 'hpve_notice', 'hpve_name', 'hpve_until', 'hpve_approved', 'hpve_skipped' ], $back );

		if ( ! $request || ! in_array( $decision, [ 'approve', 'needs_info', 'reject', 'reopen' ], true ) ) {
			wp_safe_redirect( add_query_arg( 'hpve_notice', 'invalid', $back ) );

			exit;
		}

		if ( 'reject' === $decision && '' === trim( $reason ) ) {
			wp_safe_redirect( add_query_arg( 'hpve_notice', 'reason_required', $back ) );

			exit;
		}

		$args = [];

		if ( 'needs_info' === $decision ) {
			$args['note'] = $note;
		} elseif ( 'reject' === $decision ) {
			$args['reason'] = $reason;
		}

		$result = hivepress()->hpve_request->apply_event( $request, $decision, $args );

		if ( 'no_vendor' === $result ) {
			wp_safe_redirect( add_query_arg( 'hpve_notice', 'no_vendor', $back ) );

			exit;
		}

		if ( true !== $result ) {
			wp_safe_redirect( add_query_arg( 'hpve_notice', 'invalid', $back ) );

			exit;
		}

		$query = [
			'hpve_notice' => 'approve' === $decision ? 'approved' : ( 'reject' === $decision ? 'rejected' : ( 'reopen' === $decision ? 'reopened' : 'needs_info' ) ),
		];

		if ( 'approve' === $decision ) {
			$user = get_userdata( (int) $request->get_user__id() );

			$query['hpve_name'] = $user ? $user->display_name : '';

			$vendor_id = (int) $request->get_vendor__id();
			$until     = $vendor_id ? (string) get_post_meta( $vendor_id, Hpve_Verification::META_UNTIL, true ) : '';

			if ( '' !== $until ) {
				$query['hpve_until'] = hivepress()->hpve_verification->format_date( $until );
			}
		}

		wp_safe_redirect( add_query_arg( $query, $back ) );

		exit;
	}

	/**
	 * Deletes every document of a request now.
	 *
	 * @return void
	 */
	public function handle_delete_documents() {
		$request_id = isset( $_REQUEST['request_id'] ) ? absint( $_REQUEST['request_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked on the next line.

		check_admin_referer( 'hpve_delete_documents_' . $request_id );

		if ( ! $request_id || ! current_user_can( 'edit_post', $request_id ) ) {
			wp_die( esc_html__( 'You are not allowed to change this request.', 'verification-expiry-for-hivepress' ), '', [ 'response' => 403 ] );
		}

		$request = hivepress()->hpve_request->get_request( $request_id );

		if ( $request ) {
			hivepress()->hpve_request->delete_documents( $request, wp_get_current_user()->display_name );
		}

		wp_safe_redirect( add_query_arg( 'hpve_notice', 'documents_deleted', $this->get_review_url( $request_id ) ) );

		exit;
	}

	/**
	 * A note on the Vendor edit screen while a request is open.
	 *
	 * @return void
	 */
	public function render_vendor_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'post' !== $screen->base || 'hp_vendor' !== $screen->post_type ) {
			return;
		}

		$vendor_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.

		if ( ! $vendor_id ) {
			return;
		}

		$request = hivepress()->hpve_request->get_for_vendor( $vendor_id );

		if ( ! $request || 'pending' !== (string) $request->get_status() ) {
			return;
		}

		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'A verification request from this Vendor is pending. Decide it under Verifications rather than ticking the Verified box here; ticking still works and is recorded as an approval.', 'verification-expiry-for-hivepress' ) . ' <a href="' . esc_url( $this->get_review_url( $request->get_id() ) ) . '">' . esc_html__( 'Open the request', 'verification-expiry-for-hivepress' ) . '</a></p></div>';
	}
}

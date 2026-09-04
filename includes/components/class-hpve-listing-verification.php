<?php
/**
 * Listing verification component.
 *
 * The listing half of the plugin: a period and a date under each listing's own Verified box, the
 * hourly checks that revoke and remind, the three emails to the listing's owner, and the Listings
 * screen column and bulk actions. It mirrors class-hpve-verification.php, which does the same for
 * vendors, and borrows that component's period and date helpers rather than carrying a copy.
 *
 * It acts only while "Badges to Expire" is "Vendor badge only". In sync mode every listing badge
 * follows its vendor's, so a listing has no verification of its own to time: the edit screen
 * fields, the field hooks, the hourly jobs and the column all stand down, and the dates stored on
 * listings are cleared when sync mode is chosen (see clear_all_clocks()).
 *
 * HivePress instantiates every class under includes/components/ of a registered extension on
 * every request (hivepress/includes/class-core.php:364, core 1.7.31); the Hpve prefix on the class
 * and file name keeps it from colliding with any other extension's component of the same name.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;
use HivePress\Models;
use HivePress\Emails;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Listing verification component class.
 *
 * @class Hpve_Listing_Verification
 */
final class Hpve_Listing_Verification extends Component {

	/**
	 * How many listings each hourly run handles, for revoking and for reminding separately.
	 */
	const BATCH_SIZE = 25;

	/**
	 * Clock changes requested while the listing edit screen is saving, keyed by listing ID.
	 *
	 * @var array<int, string>
	 */
	protected $pending = [];

	/**
	 * How many save_post runs for the listing being edited are currently open.
	 *
	 * @var int
	 */
	protected $depth = 0;

	/**
	 * Listings that have just been marked as verified and are owed the verified email, keyed by ID.
	 *
	 * @var array<int, bool>
	 */
	protected $notify = [];

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// The model fields are registered whatever the mode: Listing::query() can only filter on
		// a field the model knows, and the per-field hooks below only fire for a known field.
		add_filter( 'hivepress/v1/models/listing', [ $this, 'add_model_fields' ] );

		// The two fields under the Verified box on the listing edit screen.
		add_filter( 'hivepress/v1/meta_boxes/listing_settings', [ $this, 'add_meta_box_fields' ] );

		// The Verified Listings section, added to the tab the vendor component registers at the
		// default priority.
		add_filter( 'hivepress/v1/settings', [ $this, 'add_settings' ], 20 );

		// React to the fields changing, whoever changed them.
		add_action( 'hivepress/v1/models/listing/update_verified', [ $this, 'update_verified' ], 10, 2 );
		add_action( 'hivepress/v1/models/listing/update_hpve_verified_period', [ $this, 'update_period' ], 10, 2 );
		add_action( 'hivepress/v1/models/listing/update_hpve_verified_until', [ $this, 'update_until' ], 10, 2 );

		// The deferred clock, for the same reason as on vendors: core's meta box save writes the
		// fields one at a time and the form's own empty date would overwrite a computed one.
		add_action( 'save_post', [ $this, 'enter_save' ], 1 );
		add_action( 'save_post', [ $this, 'flush_pending' ], 100 );

		// Revoke and remind on core's own hourly event.
		add_action( 'hivepress/v1/events/hourly', [ $this, 'expire_listings' ] );
		add_action( 'hivepress/v1/events/hourly', [ $this, 'remind_listings' ] );

		// Listing dates have no meaning once badges follow the vendor, so they are cleared when
		// sync mode is chosen. Both option hooks, because the first save of the settings tab adds
		// the option rather than updating it.
		add_action( 'update_option_hp_' . HPVE_OPTION_PREFIX . 'scope', [ $this, 'update_scope' ], 10, 2 );
		add_action( 'add_option_hp_' . HPVE_OPTION_PREFIX . 'scope', [ $this, 'add_scope' ], 10, 2 );

		if ( is_admin() ) {

			// Listings screen: a column and two bulk actions.
			add_filter( 'manage_hp_listing_posts_columns', [ $this, 'add_admin_columns' ] );
			add_action( 'manage_hp_listing_posts_custom_column', [ $this, 'render_admin_columns' ], 10, 2 );
			add_filter( 'bulk_actions-edit-hp_listing', [ $this, 'add_bulk_actions' ] );
			add_filter( 'handle_bulk_actions-edit-hp_listing', [ $this, 'handle_bulk_actions' ], 10, 3 );
			add_action( 'admin_notices', [ $this, 'render_bulk_notice' ] );
		}

		parent::__construct( $args );
	}

	/*
	--------------------------------------------------------------------------
	Mode, periods and dates.
	--------------------------------------------------------------------------
	*/

	/**
	 * Whether listings carry their own verification dates.
	 *
	 * True in the default "Vendor badge only" mode. In sync mode the vendor component drives
	 * every listing badge, and this component does nothing.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return 'both' !== (string) hpve_get_option( HPVE_OPTION_PREFIX . 'scope', '' );
	}

	/**
	 * The vendor component, which owns the shared period and date arithmetic.
	 *
	 * @return Hpve_Verification
	 */
	protected function get_vendor_component() {
		return hivepress()->hpve_verification;
	}

	/**
	 * The site-wide default period for listings, or an empty string for "does not expire".
	 *
	 * Separate from the vendor default on purpose: a site may want a vendor checked yearly and
	 * each listing checked every few months, or the other way round.
	 *
	 * @return string
	 */
	public function get_default_period() {
		$period = (string) hpve_get_option( HPVE_OPTION_PREFIX . 'listing_default_period', '' );

		return array_key_exists( $period, Hpve_Verification::get_periods() ) ? $period : '';
	}

	/**
	 * The period that actually applies to one listing.
	 *
	 * @param int $listing_id Listing ID.
	 * @return string Period key, or an empty string for "does not expire".
	 */
	public function resolve_period( $listing_id ) {
		$period = (string) get_post_meta( $listing_id, Hpve_Verification::META_PERIOD, true );

		if ( 'never' === $period ) {
			return '';
		}

		if ( array_key_exists( $period, Hpve_Verification::get_periods() ) ) {
			return $period;
		}

		return $this->get_default_period();
	}

	/**
	 * Today's date in the site's timezone, as Y-m-d.
	 *
	 * @return string
	 */
	protected function get_today() {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Starts (or restarts) the listing's clock from today, using whatever period applies.
	 *
	 * Same rules as the vendor clock (Hpve_Verification::start_clock()): the record of an earlier
	 * expiry and the reminder marker always go, and a stored date is only cleared on a period
	 * change, never on a verification that merely has no period.
	 *
	 * @param int  $listing_id Listing ID.
	 * @param bool $clear Whether to clear a stored date when no period applies.
	 * @return string The date set, or an empty string when no period applies.
	 */
	public function start_clock( $listing_id, $clear = true ) {
		$until = $this->get_vendor_component()->calculate_until( $this->resolve_period( $listing_id ) );

		delete_post_meta( $listing_id, Hpve_Verification::META_EXPIRED );
		delete_post_meta( $listing_id, Hpve_Verification::META_REMINDED );

		if ( '' !== $until ) {
			update_post_meta( $listing_id, Hpve_Verification::META_UNTIL, $until );
		} elseif ( $clear ) {
			delete_post_meta( $listing_id, Hpve_Verification::META_UNTIL );
		}

		return $until;
	}

	/**
	 * Stops the listing's clock without touching its verified status.
	 *
	 * @param int $listing_id Listing ID.
	 * @return void
	 */
	public function stop_clock( $listing_id ) {
		delete_post_meta( $listing_id, Hpve_Verification::META_UNTIL );
		delete_post_meta( $listing_id, Hpve_Verification::META_REMINDED );
	}

	/**
	 * Whether the listing is currently verified.
	 *
	 * Read straight from the meta core's checkbox writes (models/class-listing.php:96, the
	 * external "verified" field, alias hp_verified), for the same reason as on vendors: a model
	 * object built earlier in the request can be stale inside a per-field hook.
	 *
	 * @param int $listing_id Listing ID.
	 * @return bool
	 */
	protected function is_verified( $listing_id ) {
		return (bool) get_post_meta( $listing_id, 'hp_verified', true );
	}

	/*
	--------------------------------------------------------------------------
	Model, meta box and settings.
	--------------------------------------------------------------------------
	*/

	/**
	 * Adds the plugin's fields to the listing model.
	 *
	 * The same four fields as on the vendor model, with the same meta keys, so the one
	 * uninstaller covers both post types. See Hpve_Verification::add_model_fields() for why the
	 * period is a text field here and a select on the edit screen.
	 *
	 * @param array $args Model arguments.
	 * @return array
	 */
	public function add_model_fields( $args ) {
		$args['fields']['hpve_verified_period'] = [
			'type'       => 'text',
			'max_length' => 32,
			'_external'  => true,
		];

		$args['fields']['hpve_verified_until'] = [
			'type'      => 'date',
			'format'    => 'Y-m-d',
			'_external' => true,
		];

		$args['fields']['hpve_verified_expired_time'] = [
			'type'      => 'number',
			'_external' => true,
		];

		$args['fields']['hpve_reminded_until'] = [
			'type'      => 'date',
			'format'    => 'Y-m-d',
			'_external' => true,
		];

		return $args;
	}

	/**
	 * Adds the period and date fields under core's Verified checkbox on the listing edit screen.
	 *
	 * Core's listing settings meta box puts Verified at order 20 and Featured at 30
	 * (hivepress/includes/configs/meta-boxes.php, listing_settings), so 21 and 22 sit directly
	 * under the box they belong to. Both carry `_parent => 'verified'`, so they show only while
	 * the box is ticked. Nothing is added in sync mode, where the badge is not the listing's own.
	 *
	 * @param array $meta_box Meta box arguments.
	 * @return array
	 */
	public function add_meta_box_fields( $meta_box ) {
		if ( ! $this->is_enabled() || ! isset( $meta_box['fields'] ) || ! is_array( $meta_box['fields'] ) ) {
			return $meta_box;
		}

		$default = $this->get_default_period();

		if ( $default ) {
			/* translators: %s: the period chosen under HivePress, Settings, Verification Expiry, e.g. "3 months". */
			$default_label = sprintf( esc_html__( 'Site default (%s)', 'verification-expiry-for-hivepress' ), Hpve_Verification::get_periods()[ $default ] );
		} else {
			$default_label = esc_html__( 'Site default (does not expire)', 'verification-expiry-for-hivepress' );
		}

		// The blank key is the "follow the site default" choice on purpose; see the vendor
		// component for the placeholder core would otherwise add.
		$options = [ '' => $default_label ] + [ 'never' => esc_html__( 'Does not expire', 'verification-expiry-for-hivepress' ) ] + Hpve_Verification::get_periods();

		$meta_box['fields']['hpve_verified_period'] = [
			'label'       => esc_html__( 'Verification Period', 'verification-expiry-for-hivepress' ),
			'description' => esc_html__( 'How long this listing\'s verification lasts. The date below is filled in from this each time the listing is verified, and again whenever this choice changes.', 'verification-expiry-for-hivepress' ),
			'type'        => 'select',
			'options'     => $options,
			'_parent'     => 'verified',
			'_order'      => 21,
		];

		$meta_box['fields']['hpve_verified_until'] = [
			'label'       => esc_html__( 'Verified Until', 'verification-expiry-for-hivepress' ),
			'description' => esc_html__( 'The day after this date the listing\'s verified badge is removed and its owner is emailed. Pick a date of your own, or leave it to be filled in from the period above. Blank means it does not expire.', 'verification-expiry-for-hivepress' ),
			'type'        => 'date',
			'format'      => 'Y-m-d',
			'_parent'     => 'verified',
			'_order'      => 22,
		];

		return $meta_box;
	}

	/**
	 * Adds the Verified Listings section to the plugin's settings tab.
	 *
	 * The reminder lead time is shared with vendors on purpose: one "days before" number is
	 * easier to reason about than two, and nothing about a listing makes a different lead time
	 * likely.
	 *
	 * @param array $settings Settings configuration.
	 * @return array
	 */
	public function add_settings( $settings ) {
		if ( ! isset( $settings[ Hpve_Verification::SETTINGS_TAB ]['sections'] ) ) {
			return $settings;
		}

		$settings[ Hpve_Verification::SETTINGS_TAB ]['sections'][ HPVE_OPTION_PREFIX . 'listings' ] = [
			'title'       => esc_html__( 'Verified Listings', 'verification-expiry-for-hivepress' ),
			'description' => esc_html__( 'HivePress also has a Verified box on each listing, separate from the vendor\'s. While "Badges to Expire" above is "Vendor badge only", each listing\'s verification gets its own period and date, shown under the Verified box on the listing\'s edit screen, and the listing\'s owner is emailed when it is verified, before the date and when the badge is removed. The reminder goes out the number of days before expiry set above. In sync mode listing badges follow the vendor, so these settings are not used.', 'verification-expiry-for-hivepress' ),
			'_order'      => 20,

			'fields'      => [
				HPVE_OPTION_PREFIX . 'listing_default_period' => [
					'label'       => esc_html__( 'Default Listing Verification Period', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'How long a listing\'s verification lasts unless its own edit screen says otherwise. "Does not expire" keeps the current HivePress behaviour, where a verified listing stays verified until you untick the box. Changing this does not move dates already set; select listings on the Listings screen and use the "Apply verification period" bulk action for that.', 'verification-expiry-for-hivepress' ),
					'type'        => 'select',
					'options'     => [ '' => esc_html__( 'Does not expire', 'verification-expiry-for-hivepress' ) ] + Hpve_Verification::get_periods(),
					'_order'      => 10,
				],

				HPVE_OPTION_PREFIX . 'listing_expiry_email' => [
					'label'       => esc_html__( 'Listing Expiry Email', 'verification-expiry-for-hivepress' ),
					'caption'     => esc_html__( 'Email the listing\'s owner when its verified badge is removed', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'The email asks them to check the listing and their contact details and to get in touch to have it verified again. Untick to remove the badge silently.', 'verification-expiry-for-hivepress' ),
					'type'        => 'checkbox',
					'default'     => true,
					'_order'      => 20,
				],

				HPVE_OPTION_PREFIX . 'listing_verified_email' => [
					'label'       => esc_html__( 'Listing Verified Email', 'verification-expiry-for-hivepress' ),
					'caption'     => esc_html__( 'Email the listing\'s owner when it is marked as verified', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'Tells them the badge now shows on the listing and, when its verification has a date, when it is due for review. Not sent for the bulk action or when only the period changes.', 'verification-expiry-for-hivepress' ),
					'type'        => 'checkbox',
					'default'     => true,
					'_order'      => 30,
				],
			],
		];

		return $settings;
	}

	/*
	--------------------------------------------------------------------------
	Field events.
	--------------------------------------------------------------------------
	*/

	/**
	 * Starts or stops the clock when the listing's Verified checkbox changes.
	 *
	 * In sync mode the change is the vendor component's doing (or an admin's, about to be
	 * overridden by the vendor's state) and no listing clock is involved.
	 *
	 * @param int   $listing_id Listing ID.
	 * @param mixed $value New value.
	 * @return void
	 */
	public function update_verified( $listing_id, $value ) {
		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( $value ) {
			$this->notify[ (int) $listing_id ] = true;
		} else {
			unset( $this->notify[ (int) $listing_id ] );
		}

		$this->request_clock( $listing_id, $value ? 'start' : 'stop' );
	}

	/**
	 * Restarts the clock when the listing's period changes, if it is verified.
	 *
	 * @param int   $listing_id Listing ID.
	 * @param mixed $value New value.
	 * @return void
	 */
	public function update_period( $listing_id, $value ) {
		if ( $this->is_enabled() && $this->is_verified( $listing_id ) ) {
			$this->request_clock( $listing_id, 'restart' );
		}
	}

	/**
	 * Forgets the reminder marker when the expiry date changes, so the new date gets its own.
	 *
	 * @param int   $listing_id Listing ID.
	 * @param mixed $value New value.
	 * @return void
	 */
	public function update_until( $listing_id, $value ) {
		delete_post_meta( $listing_id, Hpve_Verification::META_REMINDED );
	}

	/**
	 * Applies a clock change now, or queues it until the listing edit screen has finished saving.
	 *
	 * The trap, and the reason for the queue, is written up on
	 * Hpve_Verification::request_clock(): core saves the meta box fields one at a time in
	 * `_order`, so a date computed from the period hook is overwritten by the form's own empty
	 * Verified Until input a moment later. Queued changes are applied from save_post at
	 * priority 100, after every field has been written.
	 *
	 * @param int    $listing_id Listing ID.
	 * @param string $mode "start", "restart" or "stop".
	 * @return void
	 */
	protected function request_clock( $listing_id, $mode ) {
		if ( $this->is_editing( $listing_id ) ) {
			$this->pending[ (int) $listing_id ] = $mode;

			return;
		}

		$this->apply_clock( $listing_id, $mode );
	}

	/**
	 * Whether core's meta box save for this listing is running right now.
	 *
	 * The post type check keeps this component's save counter to listing saves; the vendor
	 * component keeps its own.
	 *
	 * @param int $listing_id Listing ID.
	 * @return bool
	 */
	protected function is_editing( $listing_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only check of which save is running; core verified this request before its own handler wrote anything.
		return doing_action( 'save_post' ) && isset( $_POST['action'], $_POST['post_ID'] ) && 'editpost' === $_POST['action'] && absint( $_POST['post_ID'] ) === (int) $listing_id && 'hp_listing' === get_post_type( (int) $listing_id );
	}

	/**
	 * Carries out one clock change.
	 *
	 * @param int    $listing_id Listing ID.
	 * @param string $mode "start", "restart" or "stop".
	 * @return void
	 */
	protected function apply_clock( $listing_id, $mode ) {
		$listing_id = (int) $listing_id;

		if ( 'stop' === $mode ) {
			$this->stop_clock( $listing_id );

			unset( $this->notify[ $listing_id ] );

			return;
		}

		$this->start_clock( $listing_id, 'restart' === $mode );

		// The verified email, now that the date it mentions is known.
		if ( ! empty( $this->notify[ $listing_id ] ) ) {
			unset( $this->notify[ $listing_id ] );

			if ( hpve_get_option( HPVE_OPTION_PREFIX . 'listing_verified_email', true ) ) {
				$listing = Models\Listing::query()->get_by_id( $listing_id );

				if ( $listing ) {
					$this->send_email( 'verified', $listing, (string) get_post_meta( $listing_id, Hpve_Verification::META_UNTIL, true ) );
				}
			}
		}
	}

	/**
	 * Counts a save_post run for the listing being edited in.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function enter_save( $post_id ) {
		if ( $this->is_editing( $post_id ) ) {
			++$this->depth;
		}
	}

	/**
	 * Counts a save_post run out and, once the outermost one is done, applies the queued change.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function flush_pending( $post_id ) {
		$post_id = (int) $post_id;

		if ( $this->is_editing( $post_id ) ) {
			$this->depth = max( 0, $this->depth - 1 );

			if ( $this->depth > 0 ) {
				return;
			}
		}

		if ( ! isset( $this->pending[ $post_id ] ) ) {
			return;
		}

		$mode = $this->pending[ $post_id ];

		unset( $this->pending[ $post_id ] );

		$this->apply_clock( $post_id, $mode );
	}

	/*
	--------------------------------------------------------------------------
	Scheduled work.
	--------------------------------------------------------------------------
	*/

	/**
	 * Removes the verified badge from listings whose date has passed, and emails their owners.
	 *
	 * Inclusive like the vendor date: revoked on the first hourly run of the day after. The
	 * statuses are the ones a listing can be seen or moderated in; a listing in the bin is left
	 * alone. Revoking goes through the model so core's own listing hooks run as they do when an
	 * admin unticks the box.
	 *
	 * @return void
	 */
	public function expire_listings() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$listings = Models\Listing::query()->filter(
			[
				'verified'                => true,
				'hpve_verified_until__lt' => $this->get_today(),
				'status__in'              => [ 'draft', 'pending', 'publish' ],
			]
		)->limit( self::BATCH_SIZE )
		->get();

		$send_email = (bool) hpve_get_option( HPVE_OPTION_PREFIX . 'listing_expiry_email', true );

		foreach ( $listings as $listing ) {
			$listing_id = $listing->get_id();

			// Read the date now: unticking the box below clears it through update_verified().
			$until = (string) get_post_meta( $listing_id, Hpve_Verification::META_UNTIL, true );

			update_post_meta( $listing_id, Hpve_Verification::META_EXPIRED, time() );

			$listing->set_verified( false )->save_verified();

			if ( $send_email ) {
				$this->send_email( 'expire', $listing, $until );
			}
		}
	}

	/**
	 * Emails the owners of listings whose verification ends within the reminder window, once per date.
	 *
	 * @return void
	 */
	public function remind_listings() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$days = hpve_get_number_option( HPVE_OPTION_PREFIX . 'reminder_days', 7 );

		if ( $days < 1 ) {
			return;
		}

		$today = $this->get_today();
		$limit = ( new \DateTimeImmutable( $today, wp_timezone() ) )->add( new \DateInterval( 'P' . $days . 'D' ) )->format( 'Y-m-d' );

		$listings = Models\Listing::query()->filter(
			[
				'verified'                 => true,
				'hpve_verified_until__gte' => $today,
				'hpve_verified_until__lte' => $limit,
				'hpve_reminded_until'      => null,
				'status__in'               => [ 'draft', 'pending', 'publish' ],
			]
		)->limit( self::BATCH_SIZE )
		->get();

		foreach ( $listings as $listing ) {
			$listing_id = $listing->get_id();

			$until = (string) get_post_meta( $listing_id, Hpve_Verification::META_UNTIL, true );

			// Mark first, so a failure inside the mailer cannot repeat the email every hour.
			update_post_meta( $listing_id, Hpve_Verification::META_REMINDED, $until );

			$this->send_email( 'remind', $listing, $until );
		}
	}

	/**
	 * Sends the verified, reminder or expiry email to the listing's owner.
	 *
	 * @param string $type "verified", "remind" or "expire".
	 * @param object $listing Listing object.
	 * @param string $until Expiry date as Y-m-d, or an empty string for none.
	 * @return void
	 */
	protected function send_email( $type, $listing, $until ) {
		$user = $listing->get_user();

		if ( ! $user || ! $user->get_email() ) {
			return;
		}

		$expiry_date = '' === $until ? '' : $this->get_vendor_component()->format_date( $until );

		// A whole sentence or nothing, with a trailing space, for the same reason as the vendor
		// emails: the default verified body puts this token directly before its next sentence.
		$expiry_note = '';

		if ( '' !== $expiry_date ) {
			/* translators: %s: date. */
			$expiry_note = sprintf( esc_html__( 'It is due for review on %s, so please keep the listing and your contact details up to date before then.', 'verification-expiry-for-hivepress' ), $expiry_date ) . ' ';
		}

		$tokens = [
			'user'          => $user,
			'listing'       => $listing,
			'user_name'     => $user->get_display_name(),
			'listing_title' => $listing->get_title(),
			'listing_url'   => hivepress()->router->get_url( 'listing_view_page', [ 'listing_id' => $listing->get_id() ] ),
			'expiry_date'   => $expiry_date,
			'expiry_note'   => $expiry_note,
		];

		$args = [
			'recipient' => $user->get_email(),
			'tokens'    => $tokens,
		];

		if ( 'verified' === $type ) {
			( new Emails\Hpve_Listing_Verification_Verified( $args ) )->send();
		} elseif ( 'remind' === $type ) {
			( new Emails\Hpve_Listing_Verification_Remind( $args ) )->send();
		} else {
			( new Emails\Hpve_Listing_Verification_Expire( $args ) )->send();
		}
	}

	/*
	--------------------------------------------------------------------------
	Sync mode.
	--------------------------------------------------------------------------
	*/

	/**
	 * Clears every listing's own dates when sync mode is chosen.
	 *
	 * Once badges follow the vendor a listing's own date is meaningless, and leaving it stored
	 * would have two costs: the Listings column would keep saying "Verified until" about a date
	 * nothing checks, and switching back later would revoke, on the next hourly run, every
	 * listing whose forgotten date had meanwhile passed. The settings description says the dates
	 * are cleared, so it is not a surprise.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value New value.
	 * @return void
	 */
	public function update_scope( $old_value, $value ) {
		if ( 'both' !== (string) $value || 'both' === (string) $old_value ) {
			return;
		}

		$this->clear_all_clocks();
	}

	/**
	 * The add_option_{name} twin of update_scope(), for the first save of the settings tab.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value Saved value.
	 * @return void
	 */
	public function add_scope( $option, $value ) {
		$this->update_scope( '', $value );
	}

	/**
	 * Removes the period, date, expiry record and reminder marker from every listing.
	 *
	 * Through the model in batches rather than one SQL statement, so the meta cache and core's
	 * own update hooks see each change as they would for any other edit.
	 *
	 * @return int Listings cleared.
	 */
	public function clear_all_clocks() {
		$cleared = 0;
		$batch   = 100;

		do {
			$listings = Models\Listing::query()->filter(
				[
					'hpve_verified_until__exists' => true,
					'status__in'                  => [ 'auto-draft', 'draft', 'pending', 'publish', 'trash' ],
				]
			)->limit( $batch )
			->get();

			$found = count( $listings );

			foreach ( $listings as $listing ) {
				$listing_id = $listing->get_id();

				delete_post_meta( $listing_id, Hpve_Verification::META_PERIOD );
				delete_post_meta( $listing_id, Hpve_Verification::META_EXPIRED );

				$this->stop_clock( $listing_id );

				++$cleared;
			}
		} while ( $found === $batch );

		return $cleared;
	}

	/*
	--------------------------------------------------------------------------
	Listings screen.
	--------------------------------------------------------------------------
	*/

	/**
	 * Adds a Verification column after the title, in the mode where listings have their own dates.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_admin_columns( $columns ) {
		if ( ! $this->is_enabled() ) {
			return $columns;
		}

		$new_columns = [];

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'title' === $key ) {
				$new_columns['hpve_verification'] = esc_html_x( 'Verification', 'listing', 'verification-expiry-for-hivepress' );
			}
		}

		if ( ! isset( $new_columns['hpve_verification'] ) ) {
			$new_columns['hpve_verification'] = esc_html_x( 'Verification', 'listing', 'verification-expiry-for-hivepress' );
		}

		return $new_columns;
	}

	/**
	 * Renders the Verification column.
	 *
	 * @param string $column Column name.
	 * @param int    $post_id Listing ID.
	 * @return void
	 */
	public function render_admin_columns( $column, $post_id ) {
		if ( 'hpve_verification' !== $column ) {
			return;
		}

		$text = $this->get_status_text( $post_id );

		if ( '' === $text ) {
			echo '&mdash;';
		} else {
			echo esc_html( $text );
		}
	}

	/**
	 * One line describing the listing's verification, for the column.
	 *
	 * @param int $listing_id Listing ID.
	 * @return string Empty when the listing is not verified and never was through this plugin.
	 */
	protected function get_status_text( $listing_id ) {
		if ( $this->is_verified( $listing_id ) ) {
			$until = (string) get_post_meta( $listing_id, Hpve_Verification::META_UNTIL, true );

			if ( '' !== $until ) {
				/* translators: %s: date. */
				return sprintf( esc_html__( 'Verified until %s', 'verification-expiry-for-hivepress' ), $this->get_vendor_component()->format_date( $until ) );
			}

			return esc_html_x( 'Verified', 'listing', 'verification-expiry-for-hivepress' );
		}

		$expired = absint( get_post_meta( $listing_id, Hpve_Verification::META_EXPIRED, true ) );

		if ( $expired ) {
			/* translators: %s: date. */
			return sprintf( esc_html__( 'Expired %s', 'verification-expiry-for-hivepress' ), wp_date( get_option( 'date_format' ), $expired ) );
		}

		return '';
	}

	/**
	 * Adds the two bulk actions.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function add_bulk_actions( $actions ) {
		if ( $this->is_enabled() ) {
			$actions['hpve_apply']  = esc_html__( 'Apply verification period', 'verification-expiry-for-hivepress' );
			$actions['hpve_remove'] = esc_html__( 'Remove expiry date', 'verification-expiry-for-hivepress' );
		}

		return $actions;
	}

	/**
	 * Handles the two bulk actions.
	 *
	 * WordPress has already checked the list table's nonce before this filter fires, so only the
	 * per-post capability is checked here. "Apply" restarts the clock from today for the selected
	 * VERIFIED listings; "Remove" clears the date and leaves them verified.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action Bulk action.
	 * @param array  $post_ids Selected listing IDs.
	 * @return string
	 */
	public function handle_bulk_actions( $redirect, $action, $post_ids ) {
		if ( ! $this->is_enabled() || ! in_array( $action, [ 'hpve_apply', 'hpve_remove' ], true ) ) {
			return $redirect;
		}

		$count = 0;

		foreach ( (array) $post_ids as $post_id ) {
			$post_id = absint( $post_id );

			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			if ( 'hpve_apply' === $action ) {
				if ( ! $this->is_verified( $post_id ) ) {
					continue;
				}

				$this->start_clock( $post_id );
			} else {
				$this->stop_clock( $post_id );
			}

			++$count;
		}

		// Distinct values from the vendor screen's, so each component words its own notice.
		return add_query_arg(
			[
				'hpve_bulk'  => 'hpve_apply' === $action ? 'apply_listings' : 'remove_listings',
				'hpve_count' => $count,
			],
			$redirect
		);
	}

	/**
	 * Confirms what a bulk action did.
	 *
	 * @return void
	 */
	public function render_bulk_notice() {
		// Display only: the two query arguments are read to word a notice and nothing is written.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only, no state change.
		if ( ! isset( $_GET['hpve_bulk'], $_GET['hpve_count'] ) ) {
			return;
		}

		$bulk  = sanitize_key( wp_unslash( $_GET['hpve_bulk'] ) );
		$count = absint( $_GET['hpve_count'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'apply_listings' === $bulk ) {
			/* translators: %d: number of listings. */
			$message = sprintf( _n( 'Expiry date set on %d verified listing. Listings that are not verified were skipped.', 'Expiry dates set on %d verified listings. Listings that are not verified were skipped.', $count, 'verification-expiry-for-hivepress' ), $count );
		} elseif ( 'remove_listings' === $bulk ) {
			/* translators: %d: number of listings. */
			$message = sprintf( _n( 'Expiry date removed from %d listing.', 'Expiry dates removed from %d listings.', $count, 'verification-expiry-for-hivepress' ), $count );
		} else {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}

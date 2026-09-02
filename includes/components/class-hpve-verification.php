<?php
/**
 * Verification component.
 *
 * Everything the plugin does lives here: the two extra fields on a vendor's edit screen, the
 * settings tab, the hourly checks that revoke and remind, and the Vendors screen column and
 * bulk actions. HivePress instantiates it on every request because the plugin is registered as
 * an extension and core globs includes/components/*.php across every registered extension
 * (hivepress/includes/class-core.php:364, core 1.7.31).
 *
 * Class and file name both carry the Hpve prefix on purpose. Core loads exactly one file per
 * class name across ALL extensions, so a second extension shipping class-verification.php would
 * silently stop one of the two loading (resources/hivepress-framework.md, "Class name collisions
 * are really file name collisions").
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
 * Verification component class.
 *
 * @class Hpve_Verification
 */
final class Hpve_Verification extends Component {

	/**
	 * Key of this plugin's tab on the HivePress settings screen.
	 */
	const SETTINGS_TAB = 'verification_expiry';

	/**
	 * Meta key holding the vendor's own verification period ("month", "quarter", "half", "year",
	 * "never", or empty for "follow the site default").
	 *
	 * All four meta keys are "hp_" + the model field name, which is what HivePress derives for an
	 * external field with no explicit alias (hivepress/includes/models/class-model.php:136-137),
	 * and what the meta box saves to for a field with no alias (class-admin.php:1180-1190). The
	 * model field, the meta box field and these constants must always agree, because the
	 * per-field update hooks below only fire when core can map the meta key back to a field.
	 */
	const META_PERIOD = 'hp_hpve_verified_period';

	/**
	 * Meta key holding the date (Y-m-d, site timezone) on which verified status ends.
	 */
	const META_UNTIL = 'hp_hpve_verified_until';

	/**
	 * Meta key holding the Unix time at which this plugin last revoked the vendor's verification.
	 */
	const META_EXPIRED = 'hp_hpve_verified_expired_time';

	/**
	 * Meta key holding the expiry date a reminder has already been sent for.
	 */
	const META_REMINDED = 'hp_hpve_reminded_until';

	/**
	 * How many vendors each hourly run handles, for revoking and for reminding separately.
	 *
	 * Core's own listing expiry takes ten per hour (components/class-listing.php:298). Each vendor
	 * here costs a model save plus an email, so a bounded batch keeps one run short on a site
	 * where hundreds of vendors were verified on the same day; the rest follow next hour.
	 */
	const BATCH_SIZE = 25;

	/**
	 * Clock changes requested while the vendor edit screen is saving, keyed by vendor ID.
	 *
	 * Values are "start", "restart" or "stop"; see request_clock() for why they wait.
	 *
	 * @var array<int, string>
	 */
	protected $pending = [];

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Add the model fields, so the dates can be queried and the per-field hooks fire.
		add_filter( 'hivepress/v1/models/vendor', [ $this, 'add_model_fields' ] );

		// Add the fields to the vendor edit screen.
		add_filter( 'hivepress/v1/meta_boxes/vendor_settings', [ $this, 'add_meta_box_fields' ] );

		// Add the settings tab.
		add_filter( 'hivepress/v1/settings', [ $this, 'add_settings' ] );

		// React to the fields changing, whoever changed them.
		add_action( 'hivepress/v1/models/vendor/update_verified', [ $this, 'update_verified' ], 10, 2 );
		add_action( 'hivepress/v1/models/vendor/update_hpve_verified_period', [ $this, 'update_period' ], 10, 2 );
		add_action( 'hivepress/v1/models/vendor/update_hpve_verified_until', [ $this, 'update_until' ], 10, 2 );

		// Apply clock changes queued during a vendor edit screen save, once core has saved every
		// field. Priority 100 so it runs after core's own save_post handler at 10.
		add_action( 'save_post_hp_vendor', [ $this, 'flush_pending' ], 100 );

		// Revoke and remind on core's own hourly event (resources/hivepress-framework.md, "Scheduling").
		add_action( 'hivepress/v1/events/hourly', [ $this, 'expire_vendors' ] );
		add_action( 'hivepress/v1/events/hourly', [ $this, 'remind_vendors' ] );

		if ( is_admin() ) {

			// Vendors screen: a column and two bulk actions.
			add_filter( 'manage_hp_vendor_posts_columns', [ $this, 'add_admin_columns' ] );
			add_action( 'manage_hp_vendor_posts_custom_column', [ $this, 'render_admin_columns' ], 10, 2 );
			add_filter( 'bulk_actions-edit-hp_vendor', [ $this, 'add_bulk_actions' ] );
			add_filter( 'handle_bulk_actions-edit-hp_vendor', [ $this, 'handle_bulk_actions' ], 10, 3 );
			add_action( 'admin_notices', [ $this, 'render_bulk_notice' ] );

			// Settings screen chrome.
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );
		}

		parent::__construct( $args );
	}

	/*
	--------------------------------------------------------------------------
	Periods and dates.
	--------------------------------------------------------------------------
	*/

	/**
	 * The periods an admin can choose from, keyed by the value stored.
	 *
	 * @return array<string, string>
	 */
	public static function get_periods() {
		return [
			'month'   => esc_html__( '1 month', 'verification-expiry-for-hivepress' ),
			'quarter' => esc_html__( '3 months', 'verification-expiry-for-hivepress' ),
			'half'    => esc_html__( '6 months', 'verification-expiry-for-hivepress' ),
			'year'    => esc_html__( '1 year', 'verification-expiry-for-hivepress' ),
		];
	}

	/**
	 * The site-wide default period, or an empty string when verification does not expire by default.
	 *
	 * @return string
	 */
	public function get_default_period() {
		$period = (string) hpve_get_option( HPVE_OPTION_PREFIX . 'default_period', '' );

		return array_key_exists( $period, self::get_periods() ) ? $period : '';
	}

	/**
	 * The period that actually applies to one vendor.
	 *
	 * The vendor's own choice wins; "never" is an explicit "does not expire" that also overrides
	 * a site default; an empty or unknown value falls through to the site default.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return string Period key, or an empty string for "does not expire".
	 */
	public function resolve_period( $vendor_id ) {
		$period = (string) get_post_meta( $vendor_id, self::META_PERIOD, true );

		if ( 'never' === $period ) {
			return '';
		}

		if ( array_key_exists( $period, self::get_periods() ) ) {
			return $period;
		}

		return $this->get_default_period();
	}

	/**
	 * The expiry date a period chosen today would give, in the site's timezone.
	 *
	 * Calendar months rather than a day count, so "1 month" from the 15th is the 15th of next
	 * month, which is what an admin reading "1 month" expects. PHP's own overflow rule applies at
	 * month ends (31 January + 1 month is 3 March, not 28 February); a day or two either way on a
	 * verification that lasts months is not worth a second code path.
	 *
	 * @param string $period Period key.
	 * @return string Date as Y-m-d, or an empty string for no expiry.
	 */
	public function calculate_until( $period ) {
		$intervals = [
			'month'   => 'P1M',
			'quarter' => 'P3M',
			'half'    => 'P6M',
			'year'    => 'P1Y',
		];

		if ( ! isset( $intervals[ $period ] ) ) {
			return '';
		}

		$now = new \DateTimeImmutable( 'now', wp_timezone() );

		return $now->add( new \DateInterval( $intervals[ $period ] ) )->format( 'Y-m-d' );
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
	 * Formats a stored Y-m-d date the way the site displays dates.
	 *
	 * The stored date is a calendar day in the site's timezone with no time of its own, so it is
	 * read in that timezone and formatted with wp_date(), which also formats in the site's
	 * timezone. Going through strtotime() would read the day as UTC midnight, which on a site
	 * west of Greenwich displays as the day BEFORE.
	 *
	 * @param string $date Date as Y-m-d.
	 * @return string
	 */
	public function format_date( $date ) {
		$object = date_create_immutable_from_format( '!Y-m-d', (string) $date, wp_timezone() );

		if ( ! $object ) {
			return '';
		}

		return (string) wp_date( get_option( 'date_format' ), $object->getTimestamp() );
	}

	/**
	 * Starts (or restarts) the vendor's clock from today, using whatever period applies.
	 *
	 * Idempotent, so it is safe to call from several hooks in one request: the result depends
	 * only on today's date and the period. Either way the record of an earlier expiry goes,
	 * because this is a fresh verification, and so does the reminder marker, because a new date
	 * deserves a new reminder.
	 *
	 * When the period resolves to "does not expire" there is no date to compute, and what
	 * happens to any date already stored depends on who is asking. A period CHANGE to "does not
	 * expire" clears it, because that is what the admin just asked for. A verification with no
	 * period leaves it alone, because on the edit screen the admin may have typed a date of
	 * their own in the same save, and wiping it would make the Verified Until field a lie.
	 *
	 * @param int  $vendor_id Vendor ID.
	 * @param bool $clear Whether to clear a stored date when no period applies.
	 * @return string The date set, or an empty string when no period applies.
	 */
	public function start_clock( $vendor_id, $clear = true ) {
		$until = $this->calculate_until( $this->resolve_period( $vendor_id ) );

		delete_post_meta( $vendor_id, self::META_EXPIRED );
		delete_post_meta( $vendor_id, self::META_REMINDED );

		if ( '' !== $until ) {
			update_post_meta( $vendor_id, self::META_UNTIL, $until );
		} elseif ( $clear ) {
			delete_post_meta( $vendor_id, self::META_UNTIL );
		}

		return $until;
	}

	/**
	 * Stops the vendor's clock without touching their verified status.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return void
	 */
	public function stop_clock( $vendor_id ) {
		delete_post_meta( $vendor_id, self::META_UNTIL );
		delete_post_meta( $vendor_id, self::META_REMINDED );
	}

	/**
	 * Whether the vendor is currently verified.
	 *
	 * Read straight from the meta core's checkbox writes (models/class-vendor.php:57-60, the
	 * external "verified" field, alias hp_verified), so this answers correctly from inside a
	 * per-field hook, where a model object built moments earlier could be stale.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return bool
	 */
	protected function is_verified( $vendor_id ) {
		return (bool) get_post_meta( $vendor_id, 'hp_verified', true );
	}

	/*
	--------------------------------------------------------------------------
	Model, meta box and settings.
	--------------------------------------------------------------------------
	*/

	/**
	 * Adds the plugin's fields to the vendor model.
	 *
	 * Registering them on the model is what makes two things work. Vendor::query() can filter on
	 * them (queries/class-query.php:206-249 builds a meta_query only for a field the model knows,
	 * and takes the DATE comparison type from the field class), and the Hook component can map
	 * the meta key back to a field name so `hivepress/v1/models/vendor/update_{field}` fires
	 * (components/class-model.php:93-119). Without this, none of the hooks below would ever run.
	 *
	 * The period is a plain text field here rather than a select: a model field with options
	 * is validated against them on every save of the whole object, so an unexpected stored value
	 * would block the vendor from saving anything at all. The meta box field is the select, and
	 * core validates that one on its own.
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
	 * Adds the period and date fields under core's own Verified checkbox on the vendor edit screen.
	 *
	 * Both carry `_parent => 'verified'`, which core renders as a data-parent attribute so the
	 * rows show only while the checkbox is ticked (class-admin.php:1400-1402 and core's
	 * common.js). The date deliberately has no minimum: a date in the past is harmless (the next
	 * hourly run revokes), and core silently refuses to save a field that fails validation, which
	 * would leave the admin's date unsaved with nothing to say why (resources/hivepress-settings.md,
	 * "Meta boxes").
	 *
	 * @param array $meta_box Meta box arguments.
	 * @return array
	 */
	public function add_meta_box_fields( $meta_box ) {
		if ( ! isset( $meta_box['fields'] ) || ! is_array( $meta_box['fields'] ) ) {
			return $meta_box;
		}

		$default = $this->get_default_period();

		if ( $default ) {
			/* translators: %s: the period chosen under HivePress, Settings, Verification Expiry, e.g. "3 months". */
			$default_label = sprintf( esc_html__( 'Site default (%s)', 'verification-expiry-for-hivepress' ), self::get_periods()[ $default ] );
		} else {
			$default_label = esc_html__( 'Site default (does not expire)', 'verification-expiry-for-hivepress' );
		}

		// The blank key is the "follow the site default" choice on purpose. Core's Select field
		// prepends its own em-dash placeholder whenever the options array has no '' key
		// (fields/class-select.php:171-178), which would read as "nothing chosen".
		$options = [ '' => $default_label ] + [ 'never' => esc_html__( 'Does not expire', 'verification-expiry-for-hivepress' ) ] + self::get_periods();

		$meta_box['fields']['hpve_verified_period'] = [
			'label'       => esc_html__( 'Verification Period', 'verification-expiry-for-hivepress' ),
			'description' => esc_html__( 'How long each verification lasts. The date below is filled in from this each time the vendor is verified, and again whenever this choice changes.', 'verification-expiry-for-hivepress' ),
			'type'        => 'select',
			'options'     => $options,
			'_parent'     => 'verified',
			'_order'      => 21,
		];

		$meta_box['fields']['hpve_verified_until'] = [
			'label'       => esc_html__( 'Verified Until', 'verification-expiry-for-hivepress' ),
			'description' => esc_html__( 'The day after this date the verified status is removed and the vendor is emailed. Pick a date of your own, or leave it to be filled in from the period above. Blank means it does not expire.', 'verification-expiry-for-hivepress' ),
			'type'        => 'date',
			'format'      => 'Y-m-d',
			'_parent'     => 'verified',
			'_order'      => 22,
		];

		return $meta_box;
	}

	/**
	 * Adds the settings tab.
	 *
	 * @param array $settings Settings configuration.
	 * @return array
	 */
	public function add_settings( $settings ) {
		$settings[ self::SETTINGS_TAB ] = [
			'title'    => esc_html__( 'Verification Expiry', 'verification-expiry-for-hivepress' ),
			'_order'   => 210,

			'sections' => [
				HPVE_OPTION_PREFIX . 'defaults' => [
					'title'       => esc_html__( 'Verified Vendors', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'A verified vendor keeps the badge until the date on their profile passes; then it is removed and they are emailed to bring their details up to date. These defaults apply to every vendor unless a different period is chosen on that vendor\'s own edit screen. Changing the default here does not move dates already set; select vendors on the Vendors screen and use the "Apply verification period" bulk action for that. Both emails can be reworded under HivePress, Emails.', 'verification-expiry-for-hivepress' ),
					'_order'      => 10,

					'fields'      => [
						HPVE_OPTION_PREFIX . 'default_period' => [
							'label'       => esc_html__( 'Default Verification Period', 'verification-expiry-for-hivepress' ),
							'description' => esc_html__( 'How long a verification lasts unless the vendor\'s own edit screen says otherwise. "Does not expire" keeps the current HivePress behaviour, where a verified vendor stays verified until you untick the box.', 'verification-expiry-for-hivepress' ),
							'type'        => 'select',
							'options'     => [ '' => esc_html__( 'Does not expire', 'verification-expiry-for-hivepress' ) ] + self::get_periods(),
							'_order'      => 10,
						],

						HPVE_OPTION_PREFIX . 'reminder_days'  => [
							'label'       => esc_html__( 'Reminder (days before expiry)', 'verification-expiry-for-hivepress' ),
							'description' => esc_html__( 'Email the vendor this many days before their verified status ends, so they can update their details in time. Set 0 to send no reminder.', 'verification-expiry-for-hivepress' ),
							'type'        => 'number',
							'min_value'   => 0,
							'max_value'   => 365,
							'default'     => 7,
							'_order'      => 20,
						],

						HPVE_OPTION_PREFIX . 'expiry_email'   => [
							'label'       => esc_html__( 'Expiry Email', 'verification-expiry-for-hivepress' ),
							'caption'     => esc_html__( 'Email the vendor when their verified status is removed', 'verification-expiry-for-hivepress' ),
							'description' => esc_html__( 'The email asks them to check their profile, listings and contact details and to get in touch to be verified again. Untick to remove the badge silently.', 'verification-expiry-for-hivepress' ),
							'type'        => 'checkbox',
							'default'     => true,
							'_order'      => 30,
						],
					],
				],

				HPVE_OPTION_PREFIX . 'removal'  => [
					'title'       => esc_html__( 'Removing the Plugin', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'Your settings and every vendor\'s expiry date are kept if you delete this plugin, whatever the delete screen\'s generic warning says, unless you tick the box below. Deleting the plugin never removes anyone\'s verified status; it only stops the dates being checked.', 'verification-expiry-for-hivepress' ),
					'_order'      => 100,

					'fields'      => [
						HPVE_OPTION_PREFIX . 'delete_data' => [
							'label'       => esc_html__( 'Delete All Data', 'verification-expiry-for-hivepress' ),
							'caption'     => esc_html__( 'Delete this plugin\'s settings and vendor expiry dates when the plugin is deleted', 'verification-expiry-for-hivepress' ),
							'description' => esc_html__( 'With this ticked, deleting the plugin also removes every setting on this page, the period and expiry date stored on each vendor, and your edited versions of its two emails, with no confirmation step and no undo. Vendors stay verified either way.', 'verification-expiry-for-hivepress' ),
							'type'        => 'checkbox',
							'_order'      => 10,
						],
					],
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
	 * Starts or stops the clock when the Verified checkbox changes.
	 *
	 * Fires from core's Hook component whenever the hp_verified meta is added, changed or deleted
	 * (components/class-hook.php:308), so it covers the admin edit screen, a bulk action, and any
	 * code that saves the model. Core deletes the meta for an unticked box, in which case the
	 * value arrives as null.
	 *
	 * @param int   $vendor_id Vendor ID.
	 * @param mixed $value New value.
	 * @return void
	 */
	public function update_verified( $vendor_id, $value ) {
		$this->request_clock( $vendor_id, $value ? 'start' : 'stop' );
	}

	/**
	 * Restarts the clock when the vendor's period changes, if they are verified.
	 *
	 * For a vendor who is not verified nothing happens: the clock starts when the box is ticked.
	 *
	 * @param int   $vendor_id Vendor ID.
	 * @param mixed $value New value.
	 * @return void
	 */
	public function update_period( $vendor_id, $value ) {
		if ( $this->is_verified( $vendor_id ) ) {
			$this->request_clock( $vendor_id, 'restart' );
		}
	}

	/**
	 * Forgets the reminder marker when the expiry date changes, so the new date gets its own.
	 *
	 * The start_clock() method also lands here after it writes the date, which deletes a marker
	 * it has just deleted. Harmless, and cheaper than a guard that a future change could get wrong.
	 *
	 * @param int   $vendor_id Vendor ID.
	 * @param mixed $value New value.
	 * @return void
	 */
	public function update_until( $vendor_id, $value ) {
		delete_post_meta( $vendor_id, self::META_REMINDED );
	}

	/**
	 * Applies a clock change now, or queues it until the vendor edit screen has finished saving.
	 *
	 * THE TRAP THIS EXISTS FOR, found on staging2 on 2026-09-02 with the 1.0.0 release: core's
	 * meta box save writes the fields one at a time, in `_order` (class-admin.php:1271-1300,
	 * core 1.7.31). Verified (20) is saved, then Verification Period (21), then Verified Until
	 * (22). The per-field hook for the period fired, this plugin wrote the computed date, and
	 * core then saved the form's own Verified Until input, which was EMPTY, on top of it. The
	 * admin chose "1 year", pressed Update, and got a period with no date and a badge that would
	 * never expire. The local harness never caught it because it wrote the meta directly and
	 * never went through the form.
	 *
	 * So while that save is in progress the change is only recorded, and flush_pending() applies
	 * it from save_post at priority 100, after core's handler at 10 has written every field.
	 * The last request in a save wins, which is the right reading of the form: unticking the box
	 * stops the clock whatever the period field says, and a changed period restarts it even
	 * though ticking the box a moment earlier already started it.
	 *
	 * Outside that save, which is any programmatic change, a bulk action or the hourly job, the
	 * change applies immediately, exactly as before.
	 *
	 * @param int    $vendor_id Vendor ID.
	 * @param string $mode "start", "restart" or "stop".
	 * @return void
	 */
	protected function request_clock( $vendor_id, $mode ) {
		if ( $this->is_editing( $vendor_id ) ) {
			$this->pending[ (int) $vendor_id ] = $mode;

			return;
		}

		$this->apply_clock( $vendor_id, $mode );
	}

	/**
	 * Whether core's meta box save for this vendor is running right now.
	 *
	 * Mirrors core's own gate for that save (class-admin.php:1245-1265: the editpost action, the
	 * post ID, and save_post), so it is true in exactly the window where a later field write
	 * could undo an earlier one. Core has already checked the nonce and the capability by the
	 * time any per-field hook fires from inside it; nothing here is written from the request.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return bool
	 */
	protected function is_editing( $vendor_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only check of which save is running; core verified this request before its own handler wrote anything.
		return doing_action( 'save_post' ) && isset( $_POST['action'], $_POST['post_ID'] ) && 'editpost' === $_POST['action'] && absint( $_POST['post_ID'] ) === (int) $vendor_id;
	}

	/**
	 * Carries out one clock change.
	 *
	 * @param int    $vendor_id Vendor ID.
	 * @param string $mode "start", "restart" or "stop".
	 * @return void
	 */
	protected function apply_clock( $vendor_id, $mode ) {
		if ( 'stop' === $mode ) {
			$this->stop_clock( $vendor_id );
		} else {
			$this->start_clock( $vendor_id, 'restart' === $mode );
		}
	}

	/**
	 * Applies the clock change queued for this vendor during its edit screen save.
	 *
	 * @param int $post_id Vendor ID.
	 * @return void
	 */
	public function flush_pending( $post_id ) {
		$post_id = (int) $post_id;

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
	 * Removes verified status from vendors whose date has passed, and emails them.
	 *
	 * The date is inclusive: a vendor verified until the 12th stays verified all day on the 12th
	 * and is revoked on the first hourly run of the 13th, which is what "verified until" means
	 * to the person who typed it.
	 *
	 * Revoking goes through the model rather than a bare delete_post_meta(), so core's own
	 * vendor update hooks run exactly as they do when an admin unticks the box.
	 *
	 * @return void
	 */
	public function expire_vendors() {
		$vendors = Models\Vendor::query()->filter(
			[
				'verified'                => true,
				'hpve_verified_until__lt' => $this->get_today(),
			]
		)->limit( self::BATCH_SIZE )
		->get();

		$send_email = (bool) hpve_get_option( HPVE_OPTION_PREFIX . 'expiry_email', true );

		foreach ( $vendors as $vendor ) {
			$vendor_id = $vendor->get_id();

			// Read the date now: unticking the box below clears it through update_verified().
			$until = (string) get_post_meta( $vendor_id, self::META_UNTIL, true );

			update_post_meta( $vendor_id, self::META_EXPIRED, time() );

			$vendor->set_verified( false )->save_verified();

			if ( $send_email ) {
				$this->send_email( 'expire', $vendor, $until );
			}
		}
	}

	/**
	 * Emails vendors whose verified status ends within the reminder window, once per date.
	 *
	 * The marker meta records the date a reminder was sent for, and every path that changes the
	 * date clears it, so a vendor is reminded once for each expiry date they are given and never
	 * twice for the same one.
	 *
	 * @return void
	 */
	public function remind_vendors() {
		$days = hpve_get_number_option( HPVE_OPTION_PREFIX . 'reminder_days', 7 );

		if ( $days < 1 ) {
			return;
		}

		$today = $this->get_today();
		$limit = ( new \DateTimeImmutable( $today, wp_timezone() ) )->add( new \DateInterval( 'P' . $days . 'D' ) )->format( 'Y-m-d' );

		// Two clauses on one field are fine: core keys each meta clause by field and operator
		// (queries/class-query.php:249). A null value becomes NOT EXISTS (:226-229).
		$vendors = Models\Vendor::query()->filter(
			[
				'verified'                 => true,
				'hpve_verified_until__gte' => $today,
				'hpve_verified_until__lte' => $limit,
				'hpve_reminded_until'      => null,
			]
		)->limit( self::BATCH_SIZE )
		->get();

		foreach ( $vendors as $vendor ) {
			$vendor_id = $vendor->get_id();

			$until = (string) get_post_meta( $vendor_id, self::META_UNTIL, true );

			// Mark first, so a failure inside the mailer cannot repeat the email every hour.
			update_post_meta( $vendor_id, self::META_REMINDED, $until );

			$this->send_email( 'remind', $vendor, $until );
		}
	}

	/**
	 * Sends the expiry or reminder email to the vendor's user.
	 *
	 * @param string $type "expire" or "remind".
	 * @param object $vendor Vendor object.
	 * @param string $until Expiry date as Y-m-d.
	 * @return void
	 */
	protected function send_email( $type, $vendor, $until ) {
		$user = $vendor->get_user();

		if ( ! $user || ! $user->get_email() ) {
			return;
		}

		$tokens = [
			'user'        => $user,
			'vendor'      => $vendor,
			'user_name'   => $user->get_display_name(),
			'vendor_name' => $vendor->get_name(),
			'vendor_url'  => hivepress()->router->get_url( 'vendor_view_page', [ 'vendor_id' => $vendor->get_id() ] ),
			'expiry_date' => $this->format_date( $until ),
		];

		$args = [
			'recipient' => $user->get_email(),
			'tokens'    => $tokens,
		];

		if ( 'remind' === $type ) {
			( new Emails\Hpve_Vendor_Verification_Remind( $args ) )->send();
		} else {
			( new Emails\Hpve_Vendor_Verification_Expire( $args ) )->send();
		}
	}

	/*
	--------------------------------------------------------------------------
	Vendors screen.
	--------------------------------------------------------------------------
	*/

	/**
	 * Adds a Verification column after the title.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_admin_columns( $columns ) {
		$new_columns = [];

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'title' === $key ) {
				$new_columns['hpve_verification'] = esc_html_x( 'Verification', 'vendor', 'verification-expiry-for-hivepress' );
			}
		}

		if ( ! isset( $new_columns['hpve_verification'] ) ) {
			$new_columns['hpve_verification'] = esc_html_x( 'Verification', 'vendor', 'verification-expiry-for-hivepress' );
		}

		return $new_columns;
	}

	/**
	 * Renders the Verification column.
	 *
	 * @param string $column Column name.
	 * @param int    $post_id Vendor ID.
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
	 * One line describing the vendor's verification, for the column.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return string Empty when the vendor is not verified and never was through this plugin.
	 */
	protected function get_status_text( $vendor_id ) {
		if ( $this->is_verified( $vendor_id ) ) {
			$until = (string) get_post_meta( $vendor_id, self::META_UNTIL, true );

			if ( '' !== $until ) {
				/* translators: %s: date. */
				return sprintf( esc_html__( 'Verified until %s', 'verification-expiry-for-hivepress' ), $this->format_date( $until ) );
			}

			return esc_html_x( 'Verified', 'vendor', 'verification-expiry-for-hivepress' );
		}

		$expired = absint( get_post_meta( $vendor_id, self::META_EXPIRED, true ) );

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
		$actions['hpve_apply']  = esc_html__( 'Apply verification period', 'verification-expiry-for-hivepress' );
		$actions['hpve_remove'] = esc_html__( 'Remove expiry date', 'verification-expiry-for-hivepress' );

		return $actions;
	}

	/**
	 * Handles the two bulk actions.
	 *
	 * WordPress has already checked the list table's nonce before this filter fires
	 * (wp-admin/edit.php, the bulk-posts referer check), so only the per-post capability is
	 * checked here. "Apply" restarts the clock from today for the selected VERIFIED vendors,
	 * using each one's own period or the site default, which is how a period is put on vendors
	 * who were verified before this plugin was installed. "Remove" clears the date and leaves
	 * them verified.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action Bulk action.
	 * @param array  $post_ids Selected vendor IDs.
	 * @return string
	 */
	public function handle_bulk_actions( $redirect, $action, $post_ids ) {
		if ( ! in_array( $action, [ 'hpve_apply', 'hpve_remove' ], true ) ) {
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

		return add_query_arg(
			[
				'hpve_bulk'  => 'hpve_apply' === $action ? 'apply' : 'remove',
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

		if ( 'apply' === $bulk ) {
			/* translators: %d: number of vendors. */
			$message = sprintf( _n( 'Expiry date set on %d verified vendor. Vendors that are not verified were skipped.', 'Expiry dates set on %d verified vendors. Vendors that are not verified were skipped.', $count, 'verification-expiry-for-hivepress' ), $count );
		} elseif ( 'remove' === $bulk ) {
			/* translators: %d: number of vendors. */
			$message = sprintf( _n( 'Expiry date removed from %d vendor.', 'Expiry dates removed from %d vendors.', $count, 'verification-expiry-for-hivepress' ), $count );
		} else {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/*
	--------------------------------------------------------------------------
	Settings screen chrome.
	--------------------------------------------------------------------------
	*/

	/**
	 * Checks whether the settings tab being rendered is this plugin's own.
	 *
	 * The address cannot answer the question: HivePress falls back to the FIRST tab whenever
	 * `tab` is absent (class-admin.php, get_settings_tab()), so `admin.php?page=hp_settings`
	 * renders a real tab that the address does not name. The registered fields can answer it:
	 * Admin::register_settings() builds the sections and fields for exactly one tab and calls
	 * add_settings_field() with the prefixed option name (class-admin.php:275-325, core 1.7.31),
	 * so after admin_init the wp_settings_fields global holds this plugin's keys on its own tab
	 * and on no other. Full rule: resources/hivepress-settings.md, "The tab IS knowable
	 * server-side: ask the registered fields".
	 *
	 * @return bool
	 */
	protected function is_settings_tab() {
		if ( ! isset( $GLOBALS['wp_settings_fields']['hp_settings'] ) || ! is_array( $GLOBALS['wp_settings_fields']['hp_settings'] ) ) {
			return false;
		}

		foreach ( $GLOBALS['wp_settings_fields']['hp_settings'] as $section ) {
			foreach ( array_keys( (array) $section ) as $field ) {
				if ( 0 === strpos( (string) $field, 'hp_' . HPVE_OPTION_PREFIX ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Dresses this plugin's settings tab with the shared settings-screen chrome.
	 *
	 * The quick-links anchor nav, the sideways floating Save control and the back-to-top button
	 * are copied from the reference implementation in Account Menu Enhancer for HivePress, so
	 * every extension in this family puts the same controls in the same places
	 * (resources/hivepress-settings.md, "The settings anchor nav: one shared marker class"). It
	 * has to be added client-side: HivePress renders the tab through do_settings_sections(),
	 * which prints each section as a bare h2 with no id and no hook between sections.
	 *
	 * Two gates on the chrome, and neither replaces the other: is_settings_tab() decides whether
	 * the files load, and the script's own field-prefix test decides whether it acts.
	 *
	 * @return void
	 */
	public function enqueue_settings_assets() {
		// Screen detection only, no form data is read or written.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of which admin page is rendering.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'hp_settings' !== $page || ! $this->is_settings_tab() ) {
			return;
		}

		$path = plugin_dir_path( HPVE_FILE );
		$url  = plugin_dir_url( HPVE_FILE );

		// The file time rides along in the version so caches refresh whenever the file changes.
		wp_enqueue_style(
			'hpve-backend',
			$url . 'assets/css/backend.css',
			[],
			HPVE_VERSION . '.' . (int) filemtime( $path . 'assets/css/backend.css' )
		);

		wp_enqueue_script(
			'hpve-backend',
			$url . 'assets/js/backend.js',
			[ 'jquery' ],
			HPVE_VERSION . '.' . (int) filemtime( $path . 'assets/js/backend.js' ),
			true
		);

		wp_localize_script(
			'hpve-backend',
			'hpveBackendData',
			[
				'labels' => [
					// The colon is part of the wording: it reads as a lead-in to the links that
					// follow it, not as a heading over them.
					'jumpTo'    => esc_html__( 'Jump to a section:', 'verification-expiry-for-hivepress' ),
					'save'      => esc_html__( 'Save Changes', 'verification-expiry-for-hivepress' ),
					'backToTop' => esc_html__( 'Back to top', 'verification-expiry-for-hivepress' ),
				],
			]
		);
	}
}

<?php
/**
 * Provider component: the registry, the settings sections and the scheduler callbacks.
 *
 * The provider classes live under includes/providers/ in the Verification_Expiry namespace, outside
 * the HivePress one, so core's class glob never instantiates them; the main plugin file requires
 * them. The active provider is the "provider" setting, falling back to manual whenever the chosen
 * one is not configured, so a half-set-up Stripe never blocks anybody.
 *
 * @package HivePress\Verification_Expiry
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;
use Verification_Expiry\Providers\Hpve_Provider_Manual;
use Verification_Expiry\Providers\Hpve_Provider_Stripe;
use Verification_Expiry\Providers\Hpve_Provider_Registry;
use Verification_Expiry\Providers\Hpve_Provider_Companies_House;
use Verification_Expiry\Providers\Hpve_Provider_Vat;
use Verification_Expiry\Providers\Hpve_Provider_Hosted;
use Verification_Expiry\Providers\Hpve_Provider_Persona;
use Verification_Expiry\Providers\Hpve_Provider_Complycube;
use Verification_Expiry\Logic\Hpve_Document_Types as Doc_Types;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Providers and their settings.
 *
 * @class Hpve_Provider
 */
final class Hpve_Provider extends Component {

	/**
	 * Provider instances, keyed by name.
	 *
	 * @var array|null
	 */
	protected $providers = null;

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// The three new sections on the tab the Vendor component registers at the default priority.
		add_filter( 'hivepress/v1/settings', [ $this, 'add_settings' ], 30 );

		// A document type that has files cannot be dropped from the settings.
		add_filter( 'pre_update_option_hp_' . HPVE_OPTION_PREFIX . 'doc_types', [ $this, 'keep_types_with_documents' ], 10, 2 );

		// Background jobs.
		add_action( 'hpve_stripe_create_session', [ $this, 'run_create_session' ], 10, 2 );
		add_action( 'hpve_stripe_sync', [ $this, 'run_sync' ], 10, 2 );
		add_action( 'hpve_stripe_redact', [ $this, 'run_redact' ] );

		/*
		 * The provider-agnostic job. The three hooks above name Stripe because they predate any
		 * second provider and are still queued by name in scheduled actions on live sites; renaming
		 * them would strand every job already in the queue at the moment of an update. New
		 * providers use this one, which carries the provider's name as an argument.
		 */
		add_action( 'hpve_provider_sync', [ $this, 'run_provider_sync' ], 10, 3 );
		add_action( 'hpve_provider_start', [ $this, 'run_provider_start' ], 10, 3 );

		if ( is_admin() ) {

			// The show/hide toggle on the two masked secret fields, our tab only.
			add_action( 'admin_head', [ $this, 'print_secret_styles' ] );
			add_action( 'admin_footer', [ $this, 'print_secret_toggles' ] );
		}

		parent::__construct( $args );
	}

	/*
	--------------------------------------------------------------------------
	Registry.
	--------------------------------------------------------------------------
	*/

	/**
	 * Every registered provider, instantiated once.
	 *
	 * @return array Map of name to instance.
	 */
	public function get_providers() {
		if ( null !== $this->providers ) {
			return $this->providers;
		}

		/**
		 * Filters the verification providers.
		 *
		 * @hook hpve_verification_providers
		 * @param {array} $providers Map of provider name to class name.
		 * @return {array} Providers.
		 */
		$classes = apply_filters(
			'hpve_verification_providers',
			[
				'manual'          => Hpve_Provider_Manual::class,
				'stripe_identity' => Hpve_Provider_Stripe::class,
				'companies_house' => Hpve_Provider_Companies_House::class,
				'vat_number'      => Hpve_Provider_Vat::class,
				'persona'         => Hpve_Provider_Persona::class,
				'complycube'      => Hpve_Provider_Complycube::class,
			]
		);

		$this->providers = [];

		foreach ( (array) $classes as $name => $class ) {
			if ( is_string( $class ) && class_exists( $class ) && in_array( 'Verification_Expiry\Providers\Hpve_Provider_Interface', (array) class_implements( $class ), true ) ) {
				$instance = new $class();

				$this->providers[ $instance->get_name() ] = $instance;
			}
		}

		if ( ! isset( $this->providers['manual'] ) ) {
			$this->providers['manual'] = new Hpve_Provider_Manual();
		}

		return $this->providers;
	}

	/**
	 * Replaces a provider instance, for tests that inject a fake HTTP client.
	 *
	 * @param object $provider Provider.
	 * @return void
	 */
	public function set_provider( $provider ) {
		$this->get_providers();

		$this->providers[ $provider->get_name() ] = $provider;
	}

	/**
	 * The name of the provider new attempts use.
	 *
	 * @return string
	 */
	public function get_active_name() {
		$name      = sanitize_key( (string) hivepress()->hpve_request->get_option( 'provider', 'manual' ) );
		$providers = $this->get_providers();

		if ( isset( $providers[ $name ] ) && $providers[ $name ]->is_configured() ) {
			return $name;
		}

		return 'manual';
	}

	/**
	 * Gets a provider by name, or the active one.
	 *
	 * @param string|null $name Provider name.
	 * @return object
	 */
	public function get_provider( $name = null ) {
		$providers = $this->get_providers();

		if ( null === $name ) {
			$name = $this->get_active_name();
		}

		return isset( $providers[ $name ] ) ? $providers[ $name ] : $providers['manual'];
	}

	/**
	 * The webhook address, for the settings tab and the report.
	 *
	 * @return string
	 */
	public function get_webhook_url() {
		return hivepress()->router->get_url( 'hpve_stripe_webhook_action' );
	}

	/**
	 * Dispatches a webhook to the Stripe provider.
	 *
	 * @param string $body Raw body.
	 * @param array  $headers Lower-cased headers.
	 * @return int HTTP status.
	 */
	public function handle_webhook( $body, array $headers ) {
		return (int) $this->get_provider( 'stripe_identity' )->handle_webhook( $body, $headers );
	}

	/*
	--------------------------------------------------------------------------
	Jobs.
	--------------------------------------------------------------------------
	*/

	/**
	 * Runs the session creation job.
	 *
	 * @param int $request_id Request ID.
	 * @param int $attempt Attempt.
	 * @return void
	 */
	public function run_create_session( $request_id, $attempt = 1 ) {
		$provider = $this->get_provider( 'stripe_identity' );

		if ( $provider instanceof Hpve_Provider_Stripe ) {
			$provider->create_session_job( absint( $request_id ), absint( $attempt ) );
		}
	}

	/**
	 * Runs the sync job.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $event_id Event id.
	 * @return void
	 */
	public function run_sync( $request_id, $event_id = 'manual' ) {
		$this->get_provider( 'stripe_identity' )->sync( absint( $request_id ), sanitize_text_field( (string) $event_id ) );
	}

	/**
	 * Runs the redaction job.
	 *
	 * @param int $request_id Request ID.
	 * @return void
	 */
	public function run_redact( $request_id ) {
		$provider = $this->get_provider( 'stripe_identity' );

		if ( $provider instanceof Hpve_Provider_Stripe ) {
			$provider->redact_job( absint( $request_id ) );
		}
	}

	/**
	 * Runs the provider-agnostic sync job.
	 *
	 * The provider is looked up by the name the job carries, never by the active setting: a job
	 * queued before the owner changed providers must still finish with the provider that started
	 * it, or a half-finished check would be handed to a provider that knows nothing about it.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $provider Provider name.
	 * @param string $event_id Event id.
	 * @return void
	 */
	public function run_provider_sync( $request_id, $provider = '', $event_id = 'manual' ) {
		$name      = sanitize_key( (string) $provider );
		$providers = $this->get_providers();

		if ( ! isset( $providers[ $name ] ) ) {
			return;
		}

		$providers[ $name ]->sync( absint( $request_id ), sanitize_text_field( (string) $event_id ) );
	}

	/**
	 * Runs the provider-agnostic session-creation job.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $provider Provider name.
	 * @param int    $attempt Attempt number.
	 * @return void
	 */
	public function run_provider_start( $request_id, $provider = '', $attempt = 1 ) {
		$name      = sanitize_key( (string) $provider );
		$providers = $this->get_providers();

		if ( ! isset( $providers[ $name ] ) || ! $providers[ $name ] instanceof Hpve_Provider_Hosted ) {
			return;
		}

		$providers[ $name ]->create_session_job( absint( $request_id ), absint( $attempt ) );
	}

	/**
	 * Dispatches a webhook to a named provider.
	 *
	 * An unknown name is a 404 rather than a silent 200: it means somebody has pointed a webhook at
	 * an address this site does not serve, and the sending service should be told so it shows up in
	 * their dashboard instead of looking like a delivery that worked.
	 *
	 * @param string $name Provider name.
	 * @param string $body Raw body.
	 * @param array  $headers Lower-cased headers.
	 * @return int HTTP status.
	 */
	public function handle_named_webhook( $name, $body, array $headers ) {
		$providers = $this->get_providers();
		$name      = sanitize_key( (string) $name );

		if ( ! isset( $providers[ $name ] ) ) {
			return 404;
		}

		return (int) $providers[ $name ]->handle_webhook( (string) $body, $headers );
	}

	/**
	 * The webhook address for one provider.
	 *
	 * @param string $name Provider name.
	 * @return string
	 */
	public function get_provider_webhook_url( $name ) {
		return (string) hivepress()->router->get_url( 'hpve_provider_webhook_action', [ 'provider' => sanitize_key( $name ) ] );
	}

	/**
	 * The provider a request should collect a typed reference for, or null.
	 *
	 * @param string|null $name Provider name, or null for the active one.
	 * @return Hpve_Provider_Registry|null
	 */
	public function get_reference_provider( $name = null ) {
		$provider = $this->get_provider( $name );

		return $provider instanceof Hpve_Provider_Registry && $provider->is_configured() ? $provider : null;
	}

	/*
	--------------------------------------------------------------------------
	Settings.
	--------------------------------------------------------------------------
	*/

	/**
	 * Adds the Verification Requests, Documents and Automated Checks sections.
	 *
	 * @param array $settings Settings configuration.
	 * @return array
	 */
	public function add_settings( $settings ) {
		if ( ! isset( $settings[ Hpve_Verification::SETTINGS_TAB ]['sections'] ) ) {
			return $settings;
		}

		$storage = hivepress()->hpve_storage;

		$documents_description = ( $storage ? $storage->describe_mode() . ' ' : '' ) . esc_html__( 'Reviewers are users who can edit other people\'s posts (Editors, Administrators and, on WooCommerce sites, Shop Managers). A developer can change that with the hpve_verification_review_capability filter.', 'verification-expiry-for-hivepress' );

		$override = $this->get_template_override_note();

		$settings[ Hpve_Verification::SETTINGS_TAB ]['sections'][ HPVE_OPTION_PREFIX . 'requests' ] = [
			'title'       => esc_html__( 'Verification Requests', 'verification-expiry-for-hivepress' ),
			'description' => esc_html__( 'Vendors apply from the Verification page in their account, or from any page carrying the Verification block or the [hivepress_hpve_verification] shortcode. Requests land under Verifications in the admin menu. Approving one ticks the Verified box on the Vendor, so the period, reminder and expiry settings above apply from that moment.', 'verification-expiry-for-hivepress' ) . $override,
			'_order'      => 30,

			'fields'      => [
				HPVE_OPTION_PREFIX . 'request_enable'      => [
					'label'       => esc_html__( 'Verification Requests', 'verification-expiry-for-hivepress' ),
					'caption'     => esc_html__( 'Let Vendors apply for verification', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'Switches the account page, the block and the "Get verified" prompt on or off. Existing requests are kept either way.', 'verification-expiry-for-hivepress' ),
					'type'        => 'checkbox',
					'default'     => true,
					'_order'      => 10,
				],

				HPVE_OPTION_PREFIX . 'review_days'         => [
					'label'       => esc_html__( 'Typical review time (working days)', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'Shown to applicants as "usually within N working days". Set 0 to leave that sentence out.', 'verification-expiry-for-hivepress' ),
					'type'        => 'number',
					'min_value'   => 0,
					'max_value'   => 30,
					'default'     => 3,
					'_order'      => 20,
				],

				HPVE_OPTION_PREFIX . 'request_intro'       => [
					'label'       => esc_html__( 'Text above the form', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'Optional wording of your own shown above the document form, for example which certificate you accept. Plain text; leave blank for none.', 'verification-expiry-for-hivepress' ),
					'type'        => 'textarea',
					'max_length'  => 1000,
					'html'        => false,
					'_order'      => 30,
				],

				HPVE_OPTION_PREFIX . 'request_emails'      => [
					'label'       => esc_html__( 'Applicant Emails', 'verification-expiry-for-hivepress' ),
					'caption'     => esc_html__( 'Email applicants when their request is received, needs more information or is not approved', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'The approval email is the existing Vendor Verified email. The "now send your documents" email after a payment is always sent. All of them can be reworded under HivePress, Emails.', 'verification-expiry-for-hivepress' ),
					'type'        => 'checkbox',
					'default'     => true,
					'_order'      => 40,
				],

				HPVE_OPTION_PREFIX . 'request_admin_email' => [
					'label'       => esc_html__( 'New Request Email', 'verification-expiry-for-hivepress' ),
					'caption'     => esc_html__( 'Email the site address when a request is sent for review', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'Goes to the email address under Settings, General. Untick if you check the Verifications screen regularly.', 'verification-expiry-for-hivepress' ),
					'type'        => 'checkbox',
					'default'     => true,
					'_order'      => 50,
				],
			],
		];

		$format_options = [];

		foreach ( Doc_Types::FORMATS as $format ) {
			$format_options[ $format ] = strtoupper( $format );
		}

		$settings[ Hpve_Verification::SETTINGS_TAB ]['sections'][ HPVE_OPTION_PREFIX . 'documents' ] = [
			'title'       => esc_html__( 'Documents', 'verification-expiry-for-hivepress' ),
			'description' => $documents_description,
			'_order'      => 40,

			'fields'      => [
				HPVE_OPTION_PREFIX . 'doc_types'          => [
					'label'       => esc_html__( 'Document Types', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'One row per document you ask for. The key is a short name for storage and cannot change once files exist for it; the name and help sentence are what applicants read. Only JPG, PNG, WEBP and PDF can ever be accepted.', 'verification-expiry-for-hivepress' ),
					'type'        => 'repeater',
					'caption'     => esc_html__( 'Add document type', 'verification-expiry-for-hivepress' ),
					'_order'      => 10,

					'fields'      => [
						'key'       => [
							'label'       => esc_html__( 'Key', 'verification-expiry-for-hivepress' ),
							'description' => esc_html__( 'Lower-case letters, numbers and underscores, 2 to 32 characters, for example photo_id.', 'verification-expiry-for-hivepress' ),
							'type'        => 'text',
							'max_length'  => 32,
							'pattern'     => '[a-z0-9_]{2,32}',
							'required'    => true,
							'_order'      => 10,
						],

						'label'     => [
							'label'      => esc_html__( 'Name', 'verification-expiry-for-hivepress' ),
							'type'       => 'text',
							'max_length' => 128,
							'required'   => true,
							'_order'     => 20,
						],

						'help'      => [
							'label'       => esc_html__( 'Help sentence', 'verification-expiry-for-hivepress' ),
							'description' => esc_html__( 'Shown under the field, for example which documents count.', 'verification-expiry-for-hivepress' ),
							'type'        => 'text',
							'max_length'  => 256,
							'_order'      => 30,
						],

						'enabled'   => [
							'label'   => esc_html__( 'Enabled', 'verification-expiry-for-hivepress' ),
							'caption' => esc_html__( 'Ask for this document', 'verification-expiry-for-hivepress' ),
							'type'    => 'checkbox',
							'_order'  => 40,
						],

						'required'  => [
							'label'   => esc_html__( 'Required', 'verification-expiry-for-hivepress' ),
							'caption' => esc_html__( 'Applicants must upload this before sending', 'verification-expiry-for-hivepress' ),
							'type'    => 'checkbox',
							'_order'  => 50,
						],

						'formats'   => [
							'label'    => esc_html__( 'File types', 'verification-expiry-for-hivepress' ),
							'type'     => 'select',
							'options'  => $format_options,
							'multiple' => true,
							'_order'   => 60,
						],

						'max_mb'    => [
							'label'     => esc_html__( 'Size limit per file (MB)', 'verification-expiry-for-hivepress' ),
							'type'      => 'number',
							'min_value' => 1,
							'max_value' => Doc_Types::MAX_MB_CAP,
							'_order'    => 70,
						],

						'max_files' => [
							'label'     => esc_html__( 'Files allowed', 'verification-expiry-for-hivepress' ),
							'type'      => 'number',
							'min_value' => 1,
							'max_value' => Doc_Types::MAX_FILES_CAP,
							'_order'    => 80,
						],
					],
				],

				HPVE_OPTION_PREFIX . 'doc_retention_days' => [
					'label'       => esc_html__( 'Delete documents after (days)', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'Counted from the decision. The files are deleted; the request and its history are kept. Set 0 to keep documents until you delete them by hand.', 'verification-expiry-for-hivepress' ),
					'type'        => 'number',
					'min_value'   => 0,
					'max_value'   => 3650,
					'default'     => 30,
					'_order'      => 20,
				],

				HPVE_OPTION_PREFIX . 'doc_collect_with_provider' => [
					'label'       => esc_html__( 'Documents with Automated Checks', 'verification-expiry-for-hivepress' ),
					'caption'     => esc_html__( 'Still collect documents when an automated check is the provider', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'With Stripe Identity chosen below, tick this to keep the document form as well, for example for insurance certificates that Stripe cannot check.', 'verification-expiry-for-hivepress' ),
					'type'        => 'checkbox',
					'_order'      => 30,
				],
			],
		];

		$provider_options = [];
		$notice           = '';

		foreach ( $this->get_providers() as $name => $provider ) {
			$provider_options[ $name ] = $provider->get_label();

			if ( 'manual' !== $name && ! $provider->is_configured() ) {
				$notice .= ' ' . $provider->get_label() . ': ' . $provider->get_setup_notice();
			}
		}

		$stripe = $this->get_provider( 'stripe_identity' );
		$status = $stripe instanceof Hpve_Provider_Stripe ? ' ' . $stripe->describe_gateway() : '';

		$settings[ Hpve_Verification::SETTINGS_TAB ]['sections'][ HPVE_OPTION_PREFIX . 'provider' ] = [
			'title'       => esc_html__( 'Automated Checks', 'verification-expiry-for-hivepress' ),
			'description' => sprintf(
				/* translators: 1: the webhook address, 2: the gateway status line, 3: any set-up notices. */
				esc_html__( 'Manual review means a person reads the documents under Verifications. Stripe Identity sends the applicant to Stripe to photograph their ID and uses the WooCommerce Stripe gateway\'s key in whichever mode the gateway is in; Stripe charges a fee per completed check. Add a webhook in your Stripe Dashboard pointing at %1$s for the identity.verification_session events and paste its signing secret below. Companies House and the VAT number check are free and are set up in the next section; they confirm that a business exists and that its registered name matches, which is not the same as proving who the account holder is.%2$s%3$s', 'verification-expiry-for-hivepress' ),
				$this->get_webhook_url(),
				$status,
				$notice
			),
			'_order'      => 60,

			'fields'      => [
				HPVE_OPTION_PREFIX . 'provider'      => [
					'label'       => esc_html__( 'Provider', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'Applies to new attempts. If the chosen provider is not fully set up, manual review is used until it is.', 'verification-expiry-for-hivepress' ),
					'type'        => 'radio',
					'options'     => $provider_options,
					'default'     => 'manual',
					'_order'      => 10,
				],

				HPVE_OPTION_PREFIX . 'stripe_require_selfie' => [
					'label'       => esc_html__( 'Selfie', 'verification-expiry-for-hivepress' ),
					'caption'     => esc_html__( 'Ask Stripe to match a selfie to the ID photo', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'Stronger, and the price Stripe quotes for document plus selfie applies.', 'verification-expiry-for-hivepress' ),
					'type'        => 'checkbox',
					'default'     => true,
					'_order'      => 20,
				],

				HPVE_OPTION_PREFIX . 'stripe_webhook_secret_test' => [
					'label'        => esc_html__( 'Webhook signing secret (test)', 'verification-expiry-for-hivepress' ),
					/* translators: %s: the webhook address. */
					'description'  => sprintf( esc_html__( 'From Stripe Dashboard, Developers, Webhooks, in test mode, for the endpoint %s.', 'verification-expiry-for-hivepress' ), $this->get_webhook_url() ),
					'type'         => 'text',
					'display_type' => 'password',
					'max_length'   => 256,
					'attributes'   => [ 'autocomplete' => 'new-password' ],
					'_order'       => 30,
				],

				HPVE_OPTION_PREFIX . 'stripe_webhook_secret_live' => [
					'label'        => esc_html__( 'Webhook signing secret (live)', 'verification-expiry-for-hivepress' ),
					/* translators: %s: the webhook address. */
					'description'  => sprintf( esc_html__( 'The same, from the live-mode endpoint for %s. Used when the gateway is in live mode.', 'verification-expiry-for-hivepress' ), $this->get_webhook_url() ),
					'type'         => 'text',
					'display_type' => 'password',
					'max_length'   => 256,
					'attributes'   => [ 'autocomplete' => 'new-password' ],
					'_order'       => 40,
				],

				HPVE_OPTION_PREFIX . 'stripe_attempt_limit' => [
					'label'       => esc_html__( 'Attempts per request', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'After this many failed Stripe checks the request is marked not approved with the reason Stripe gave, and the document form is offered instead.', 'verification-expiry-for-hivepress' ),
					'type'        => 'number',
					'min_value'   => 1,
					'max_value'   => 10,
					'default'     => 3,
					'_order'      => 50,
				],

				HPVE_OPTION_PREFIX . 'stripe_admin_signoff' => [
					'label'       => esc_html__( 'Admin Sign-off', 'verification-expiry-for-hivepress' ),
					'caption'     => esc_html__( 'A verified Stripe result waits for an admin to approve it', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'Unticked, a passed check ticks the Verified box straight away. Ticked, the request stays pending and the site address is emailed.', 'verification-expiry-for-hivepress' ),
					'type'        => 'checkbox',
					'_order'      => 60,
				],

				HPVE_OPTION_PREFIX . 'stripe_redact' => [
					'label'       => esc_html__( 'Redact at Stripe', 'verification-expiry-for-hivepress' ),
					'caption'     => esc_html__( 'Ask Stripe to redact the session when documents are deleted here', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'Removes the images and personal details Stripe holds, on the same day the retention setting above deletes documents here. Redaction cannot be undone.', 'verification-expiry-for-hivepress' ),
					'type'        => 'checkbox',
					'_order'      => 70,
				],
			],
		];

		$settings[ Hpve_Verification::SETTINGS_TAB ]['sections'][ HPVE_OPTION_PREFIX . 'registry' ] = [
			'title'       => esc_html__( 'Free Business Registers', 'verification-expiry-for-hivepress' ),
			'description' => esc_html__( 'Two checks that cost nothing per lookup. Choose one of them as the Provider above to use it. The applicant types a company number or a VAT number instead of uploading anything, and the register is asked whether that business exists, whether it is still trading and what name is on it. Both prove a business, not a person: someone could type a real company number that is not theirs, so pair them with documents or with an identity provider if that matters on your site. Sole traders below the VAT threshold have neither number, so do not make one of these the only route to a badge unless every Vendor of yours is a registered business.', 'verification-expiry-for-hivepress' ),
			'_order'      => 65,

			'fields'      => [
				HPVE_OPTION_PREFIX . 'ch_api_key' => [
					'label'        => esc_html__( 'Companies House API key', 'verification-expiry-for-hivepress' ),
					'description'  => esc_html__( 'Free. Register at developer.company-information.service.gov.uk, create an application, then copy its API key here. The VAT number check needs no key at all.', 'verification-expiry-for-hivepress' ),
					'type'         => 'text',
					'display_type' => 'password',
					'max_length'   => 256,
					'attributes'   => [ 'autocomplete' => 'new-password' ],
					'_order'       => 10,
				],

				HPVE_OPTION_PREFIX . 'registry_require_name' => [
					'label'       => esc_html__( 'Name Match', 'verification-expiry-for-hivepress' ),
					'caption'     => esc_html__( 'The registered name must match the Vendor name', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'Ticked, a number registered to a different name is sent back to the applicant to correct rather than approved. Endings such as Ltd and Limited are ignored when comparing, so "Ivy Lane Studio" matches "IVY LANE STUDIO LTD". Untick it if your Vendors trade under names that differ from their registered ones.', 'verification-expiry-for-hivepress' ),
					'type'        => 'checkbox',
					'default'     => true,
					'_order'      => 20,
				],

				HPVE_OPTION_PREFIX . 'registry_admin_signoff' => [
					'label'       => esc_html__( 'Admin Sign-off', 'verification-expiry-for-hivepress' ),
					'caption'     => esc_html__( 'A passed register check waits for an admin to approve it', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'Unticked, a match ticks the Verified box straight away. Ticked, the request stays pending and the site address is emailed. A company the register lists as in liquidation or administration always waits for a person, whichever way this is set.', 'verification-expiry-for-hivepress' ),
					'type'        => 'checkbox',
					'_order'      => 30,
				],
			],
		];

		$complycube = $this->get_provider( 'complycube' );
		$cc_status  = $complycube instanceof Hpve_Provider_Complycube ? ' ' . $complycube->describe_key() : '';

		$settings[ Hpve_Verification::SETTINGS_TAB ]['sections'][ HPVE_OPTION_PREFIX . 'identity' ] = [
			'title'       => esc_html__( 'Other Identity Services', 'verification-expiry-for-hivepress' ),
			'description' => sprintf(
				/* translators: 1: the Persona webhook address, 2: the ComplyCube webhook address, 3: the ComplyCube key status line. */
				esc_html__( 'Two alternatives to Stripe Identity, for sites that would rather not add Stripe or want a cheaper price per check. Both send the applicant to the service\'s own page and both charge per completed check, so read their pricing before you switch one on. Persona has a free allowance for low volumes; ComplyCube is UK based and its key decides the mode, a key starting test_ being their sandbox. Add a webhook at each service pointing at its own address here: Persona %1$s, ComplyCube %2$s.%3$s', 'verification-expiry-for-hivepress' ),
				$this->get_provider_webhook_url( 'persona' ),
				$this->get_provider_webhook_url( 'complycube' ),
				$cc_status
			),
			'_order'      => 67,

			'fields'      => [
				HPVE_OPTION_PREFIX . 'persona_api_key'     => [
					'label'        => esc_html__( 'Persona API key', 'verification-expiry-for-hivepress' ),
					'description'  => esc_html__( 'From the Persona Dashboard, API Keys. Leave empty if you are not using Persona.', 'verification-expiry-for-hivepress' ),
					'type'         => 'text',
					'display_type' => 'password',
					'max_length'   => 256,
					'attributes'   => [ 'autocomplete' => 'new-password' ],
					'_order'       => 10,
				],

				HPVE_OPTION_PREFIX . 'persona_template_id' => [
					'label'       => esc_html__( 'Persona inquiry template', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'The template id, starting itmpl_. That template must have "Create a one-time link" switched on, or applicants have nowhere to go and every check stops before it starts.', 'verification-expiry-for-hivepress' ),
					'type'        => 'text',
					'max_length'  => 128,
					'_order'      => 20,
				],

				HPVE_OPTION_PREFIX . 'persona_webhook_secret' => [
					'label'        => esc_html__( 'Persona webhook secret', 'verification-expiry-for-hivepress' ),
					/* translators: %s: the webhook address. */
					'description'  => sprintf( esc_html__( 'From the Persona webhook you created for %s. Without it a result can only arrive if the applicant returns to the site, so it is required rather than optional.', 'verification-expiry-for-hivepress' ), $this->get_provider_webhook_url( 'persona' ) ),
					'type'         => 'text',
					'display_type' => 'password',
					'max_length'   => 256,
					'attributes'   => [ 'autocomplete' => 'new-password' ],
					'_order'       => 30,
				],

				HPVE_OPTION_PREFIX . 'persona_admin_signoff' => [
					'label'       => esc_html__( 'Persona sign-off', 'verification-expiry-for-hivepress' ),
					'caption'     => esc_html__( 'A passed Persona check waits for an admin to approve it', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'A result Persona marks for review always waits for a person, whichever way this is set.', 'verification-expiry-for-hivepress' ),
					'type'        => 'checkbox',
					'_order'      => 40,
				],

				HPVE_OPTION_PREFIX . 'complycube_api_key'  => [
					'label'        => esc_html__( 'ComplyCube API key', 'verification-expiry-for-hivepress' ),
					'description'  => esc_html__( 'From your ComplyCube dashboard. A key starting test_ uses their sandbox, where nothing is charged and no result is real; live_ runs for real. Leave empty if you are not using ComplyCube.', 'verification-expiry-for-hivepress' ),
					'type'         => 'text',
					'display_type' => 'password',
					'max_length'   => 256,
					'attributes'   => [ 'autocomplete' => 'new-password' ],
					'_order'       => 50,
				],

				HPVE_OPTION_PREFIX . 'complycube_template_id' => [
					'label'       => esc_html__( 'ComplyCube workflow', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'The workflow template id, from Workflows in their dashboard. It decides which checks an applicant is asked to complete.', 'verification-expiry-for-hivepress' ),
					'type'        => 'text',
					'max_length'  => 128,
					'_order'      => 60,
				],

				HPVE_OPTION_PREFIX . 'complycube_webhook_secret' => [
					'label'        => esc_html__( 'ComplyCube webhook secret', 'verification-expiry-for-hivepress' ),
					/* translators: %s: the webhook address. */
					'description'  => sprintf( esc_html__( 'From the ComplyCube webhook you created for %s. Required, for the same reason as Persona\'s.', 'verification-expiry-for-hivepress' ), $this->get_provider_webhook_url( 'complycube' ) ),
					'type'         => 'text',
					'display_type' => 'password',
					'max_length'   => 256,
					'attributes'   => [ 'autocomplete' => 'new-password' ],
					'_order'       => 70,
				],

				HPVE_OPTION_PREFIX . 'complycube_admin_signoff' => [
					'label'       => esc_html__( 'ComplyCube sign-off', 'verification-expiry-for-hivepress' ),
					'caption'     => esc_html__( 'A passed ComplyCube check waits for an admin to approve it', 'verification-expiry-for-hivepress' ),
					'description' => esc_html__( 'An outcome of "attention", which means their checks found something worth reading, always waits for a person whichever way this is set.', 'verification-expiry-for-hivepress' ),
					'type'        => 'checkbox',
					'_order'      => 80,
				],
			],
		];

		return $settings;
	}

	/**
	 * A sentence naming a published template that overrides the Verification page, or nothing.
	 *
	 * @return string
	 */
	protected function get_template_override_note() {
		$count = wp_count_posts( 'hp_template' );

		if ( empty( $count->publish ) ) {
			return '';
		}

		$posts = get_posts(
			[
				'post_type'      => 'hp_template',
				'post_status'    => 'publish',
				'post_name__in'  => [ 'hpve_verification_page' ],
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]
		);

		if ( ! $posts ) {
			return '';
		}

		return ' ' . esc_html__( 'Note: a customised "Verification Page" template is published under HivePress, Templates, so that page shows the template\'s content instead of the card and form.', 'verification-expiry-for-hivepress' );
	}

	/**
	 * Keeps a document type that has files even when the settings screen dropped it.
	 *
	 * The row comes back disabled, so it stops being asked for but its files stay reachable and its
	 * meta key stays known.
	 *
	 * @param mixed $value New value.
	 * @param mixed $old_value Old value.
	 * @return mixed
	 */
	public function keep_types_with_documents( $value, $old_value ) {
		$old_rows = Doc_Types::normalise( $old_value, true );

		if ( ! $old_rows ) {
			return $value;
		}

		$new_keys = [];

		foreach ( Doc_Types::normalise( $value, true ) as $row ) {
			$new_keys[ $row['key'] ] = true;
		}

		$rows = is_array( $value ) ? array_values( $value ) : [];

		foreach ( $old_rows as $row ) {
			if ( isset( $new_keys[ $row['key'] ] ) ) {
				continue;
			}

			$count = \HivePress\Models\Attachment::query()->filter(
				[
					'parent_model' => 'hpve_request',
					'parent_field' => Doc_Types::field_name( $row['key'] ),
				]
			)->get_count();

			if ( $count ) {
				$row['enabled'] = false;
				$rows[]         = $row;
			}
		}

		return $rows ? $rows : $value;
	}

	/*
	--------------------------------------------------------------------------
	Secret fields.
	--------------------------------------------------------------------------
	*/

	/**
	 * The two masked field names.
	 *
	 * @return array
	 */
	protected function get_secret_fields() {
		return [
			'hp_' . HPVE_OPTION_PREFIX . 'stripe_webhook_secret_test',
			'hp_' . HPVE_OPTION_PREFIX . 'stripe_webhook_secret_live',
			'hp_' . HPVE_OPTION_PREFIX . 'ch_api_key',
			'hp_' . HPVE_OPTION_PREFIX . 'persona_api_key',
			'hp_' . HPVE_OPTION_PREFIX . 'persona_webhook_secret',
			'hp_' . HPVE_OPTION_PREFIX . 'complycube_api_key',
			'hp_' . HPVE_OPTION_PREFIX . 'complycube_webhook_secret',
		];
	}

	/**
	 * Whether the settings screen being rendered carries the secret fields.
	 *
	 * Asks the registered fields rather than the address (resources/hivepress-settings.md, "The tab IS
	 * knowable server-side"). HivePress registers on admin_init 10; both callers run later.
	 *
	 * @return bool
	 */
	protected function is_secret_screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( ! isset( $GLOBALS['wp_settings_fields']['hp_settings'] ) || ! is_array( $GLOBALS['wp_settings_fields']['hp_settings'] ) ) {
			return false;
		}

		$names = $this->get_secret_fields();

		foreach ( $GLOBALS['wp_settings_fields']['hp_settings'] as $section ) {
			foreach ( $names as $name ) {
				if ( isset( $section[ $name ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Sizes the masked fields like core's text fields (copied from Twilio for HivePress).
	 *
	 * @return void
	 */
	public function print_secret_styles() {
		if ( ! $this->is_secret_screen() ) {
			return;
		}

		$selectors = [];

		foreach ( $this->get_secret_fields() as $name ) {
			$selectors[] = '.hp-form--table input[name="' . esc_attr( $name ) . '"]';
		}

		echo '<style>' . esc_html( implode( ', ', $selectors ) ) . '{width:25em;max-width:100%;box-sizing:border-box}'
			. '.hp-form--table .hpve-secret-wrap{position:relative;display:inline-block;width:25em;max-width:100%}'
			. '.hp-form--table .hpve-secret-wrap input{width:100%;box-sizing:border-box;padding-right:2.2em}'
			. '.hpve-secret-toggle{position:absolute;right:.4em;top:50%;transform:translateY(-50%);background:none;border:0;padding:0;margin:0;cursor:pointer;color:#787c82;line-height:1}'
			. '@media screen and (max-width:782px){' . esc_html( implode( ', ', $selectors ) ) . ',.hp-form--table .hpve-secret-wrap{width:100%}}'
			. '</style>';
	}

	/**
	 * Adds the show/hide toggle to the masked fields. Flips input.type in place; never copies the value.
	 *
	 * @return void
	 */
	public function print_secret_toggles() {
		if ( ! $this->is_secret_screen() ) {
			return;
		}

		$names = wp_json_encode( $this->get_secret_fields() );
		$show  = wp_json_encode( __( 'Show', 'verification-expiry-for-hivepress' ) );
		$hide  = wp_json_encode( __( 'Hide', 'verification-expiry-for-hivepress' ) );
		?>
		<script>
		( function() {
			var names = <?php echo $names; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output. ?>,
				labels = { show: <?php echo $show; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>, hide: <?php echo $hide; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> };

			names.forEach( function( name ) {
				var input = document.querySelector( 'input[name="' + name + '"]' );

				if ( ! input ) {
					return;
				}

				var wrap = document.createElement( 'span' );

				wrap.className = 'hpve-secret-wrap';
				input.parentNode.insertBefore( wrap, input );
				wrap.appendChild( input );

				var button = document.createElement( 'button' ),
					icon = document.createElement( 'span' );

				button.type = 'button';
				button.className = 'hpve-secret-toggle';
				button.setAttribute( 'aria-label', labels.show );
				button.title = labels.show;
				icon.className = 'dashicons dashicons-visibility';
				button.appendChild( icon );

				button.addEventListener( 'click', function() {
					var hidden = 'password' === input.type;

					input.type = hidden ? 'text' : 'password';
					icon.className = 'dashicons ' + ( hidden ? 'dashicons-hidden' : 'dashicons-visibility' );
					button.setAttribute( 'aria-label', hidden ? labels.hide : labels.show );
					button.title = hidden ? labels.hide : labels.show;
				} );

				wrap.appendChild( button );
			} );
		} )();
		</script>
		<?php
	}
}

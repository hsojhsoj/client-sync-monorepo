<?php
/**
 * File: src/shared/includes/core/class-plugin.php
 * The core plugin class.
 * FINAL FIX: Reverted to a simple, robust manual dependency loading system
 * using __DIR__ to eliminate all pathing and autoloader conflicts.
 *
 * UPDATED: Added HIPAA compliance constants, services, and audit logging.
 *
 * @package    ClientSync
 * @author     Joshua Jordan
 */

namespace DependentMedia\ClientSync;

use DependentMedia\ClientSync\Core\PostType_Manager;
use DependentMedia\ClientSync\Core\Cron;
use DependentMedia\ClientSync\Core\Data_Integrity_Manager;
use DependentMedia\ClientSync\Core\Async_Tasks;
use DependentMedia\ClientSync\Core\Notifications;
use DependentMedia\ClientSync\Core\Ical_Generator;
use DependentMedia\ClientSync\Core\Database_Manager;
use DependentMedia\ClientSync\Core\Table_Schema_Manager;
use DependentMedia\ClientSync\Services\Slot_Query_Service;
use DependentMedia\ClientSync\Services\Slot_Mutation_Service;
use DependentMedia\ClientSync\Services\Dimension_Query_Service;
use DependentMedia\ClientSync\Services\Availability_Service;
use DependentMedia\ClientSync\Repositories\Slot_Repository;
use DependentMedia\ClientSync\Admin\Admin;
use DependentMedia\ClientSync\Admin\Dimension_Manager;
use DependentMedia\ClientSync\Admin\Dimension_CPT_Manager;
use DependentMedia\ClientSync\Admin\Relationship_Manager;
use DependentMedia\ClientSync\Frontend\Frontend;
use DependentMedia\ClientSync\Ajax\Admin_Ajax_Handler;
use DependentMedia\ClientSync\Ajax\Appointments_Ajax_Handler;
use DependentMedia\ClientSync\Ajax\Booking_Ajax_Handler;
use DependentMedia\ClientSync\Ajax\Calendar_Ajax_Handler;
use DependentMedia\ClientSync\Ajax\Slots_Ajax_Handler;
use DependentMedia\ClientSync\Integrations\WooCommerce_Integration;
use DependentMedia\ClientSync\Core\Migration_Runner;
use DependentMedia\ClientSync\Core\Waitlist_Manager;
use DependentMedia\ClientSync\Integrations\Stripe_Integration;
use DependentMedia\ClientSync\Utility\Query_Monitor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin {

    protected $plugin_name;
    protected $version;
    protected $db_manager;

    public function __construct() {
        $this->plugin_name = 'clisyc';
        if ( defined( 'clisyc_PLUGIN_DIR' ) ) {
            $plugin_data = get_file_data( clisyc_PLUGIN_DIR . 'client-sync.php', [ 'Version' => 'Version' ] );
            $this->version = $plugin_data['Version'];
        } else {
            $this->version = '3.5.1'; // Fallback
        }
    }

    public function run() {
        $this->define_constants();
        $this->load_dependencies();
        $this->define_hooks();

        /**
         * Fires after Client Sync has fully initialized all services and hooks.
         *
         * Extensions and add-ons can use this hook to register their own
         * functionality once the core plugin is ready.
         *
         * @since 3.5.2
         *
         * @param Plugin $plugin The plugin instance.
         */
        do_action( 'clisyc_loaded', $this );
    }

    private function define_constants() {
        // Load the Constants class first — it must be available before any Constants:: references below.
        require_once \CLISYC_SHARED_DIR . 'includes/class-constants.php';

        // Backward-compatible global defines — values now sourced from Constants class.
        if ( ! defined( 'clisyc_VERSION' ) )                            define( 'clisyc_VERSION', $this->version );
        if ( ! defined( 'clisyc_TIME_SLOT' ) )                          define( 'clisyc_TIME_SLOT', Constants::META_TIME_SLOT );
        if ( ! defined( 'clisyc_OPTION_WC_INTEGRATION_ENABLED' ) )      define( 'clisyc_OPTION_WC_INTEGRATION_ENABLED', Constants::OPTION_WC_ENABLED );
        if ( ! defined( 'clisyc_OPTION_WC_APPOINTMENT_PRODUCT_ID' ) )   define( 'clisyc_OPTION_WC_APPOINTMENT_PRODUCT_ID', Constants::OPTION_WC_PRODUCT_ID );
        if ( ! defined( 'clisyc_PAYMENT_META_ORDER_ID' ) )              define( 'clisyc_PAYMENT_META_ORDER_ID', Constants::META_WC_ORDER_ID );
        if ( ! defined( 'clisyc_PAYMENT_META_ORDER_LINK' ) )            define( 'clisyc_PAYMENT_META_ORDER_LINK', Constants::META_WC_ORDER_LINK );
        if ( ! defined( 'clisyc_PAYMENT_META_STATUS' ) )                define( 'clisyc_PAYMENT_META_STATUS', Constants::META_PAYMENT_STATUS );
        if ( ! defined( 'clisyc_PAYMENT_META_DUE_DATE' ) )              define( 'clisyc_PAYMENT_META_DUE_DATE', Constants::META_PAYMENT_DUE_DATE );
        if ( ! defined( 'clisyc_PAYMENT_META_TOKEN_ID' ) )              define( 'clisyc_PAYMENT_META_TOKEN_ID', Constants::META_WC_TOKEN_ID );
        if ( ! defined( 'clisyc_OPTION_AUTO_GEN_ENABLED' ) )            define( 'clisyc_OPTION_AUTO_GEN_ENABLED', Constants::OPTION_AUTO_GEN_ENABLED );
        if ( ! defined( 'clisyc_OPTION_AUTO_GEN_LOOKAHEAD' ) )          define( 'clisyc_OPTION_AUTO_GEN_LOOKAHEAD', Constants::OPTION_AUTO_GEN_LOOKAHEAD );
        if ( ! defined( 'clisyc_OPTION_AUTO_GEN_DAYS' ) )               define( 'clisyc_OPTION_AUTO_GEN_DAYS', Constants::OPTION_AUTO_GEN_DAYS );
        if ( ! defined( 'clisyc_PAYMENTS_PAGE_SLUG' ) )                 define( 'clisyc_PAYMENTS_PAGE_SLUG', Constants::PAYMENTS_PAGE_SLUG );
        if ( ! defined( 'clisyc_OPTION_APPOINTMENT_INITIAL_VIEW' ) )    define( 'clisyc_OPTION_APPOINTMENT_INITIAL_VIEW', Constants::OPTION_INITIAL_VIEW );
        if ( ! defined( 'clisyc_OPTION_LOGIN_PAGE_URL' ) )              define( 'clisyc_OPTION_LOGIN_PAGE_URL', Constants::OPTION_LOGIN_PAGE_URL );
        if ( ! defined( 'clisyc_AVAILABILITY_DIMENSIONS_FIELDS_OPTION' ) ) define( 'clisyc_AVAILABILITY_DIMENSIONS_FIELDS_OPTION', Constants::OPTION_AVAILABILITY_DIM_FIELDS );
        if ( ! defined( 'clisyc_ACTION_FREQUENT_MAINTENANCE' ) )        define( 'clisyc_ACTION_FREQUENT_MAINTENANCE', Constants::ACTION_FREQUENT_MAINTENANCE );
        if ( ! defined( 'clisyc_OPTION_MIN_BOOKING_NOTICE' ) )          define( 'clisyc_OPTION_MIN_BOOKING_NOTICE', Constants::OPTION_MIN_BOOKING_NOTICE );
        if ( ! defined( 'clisyc_OPTION_REMINDER_SETTINGS' ) )           define( 'clisyc_OPTION_REMINDER_SETTINGS', Constants::OPTION_REMINDER_SETTINGS );
        if ( ! defined( 'clisyc_APPOINTMENT_VIEW_PAGE_SLUG_OPTION' ) )  define( 'clisyc_APPOINTMENT_VIEW_PAGE_SLUG_OPTION', Constants::OPTION_APPOINTMENT_VIEW_SLUG );
        if ( ! defined( 'clisyc_OPTION_GLOBAL_BUFFER_BEFORE' ) )        define( 'clisyc_OPTION_GLOBAL_BUFFER_BEFORE', Constants::OPTION_GLOBAL_BUFFER_BEFORE );
        if ( ! defined( 'clisyc_OPTION_GLOBAL_BUFFER_AFTER' ) )         define( 'clisyc_OPTION_GLOBAL_BUFFER_AFTER', Constants::OPTION_GLOBAL_BUFFER_AFTER );

        // =========================================================================
        // HIPAA COMPLIANCE CONSTANTS
        // =========================================================================
        // Note: CLISYC_HIPAA_MODE and CLISYC_ENCRYPTION_KEY should be defined in
        // wp-config.php for security. These option names are for internal use.
        if ( ! defined( 'clisyc_OPTION_HIPAA_MODE' ) )                  define( 'clisyc_OPTION_HIPAA_MODE', Constants::OPTION_HIPAA_MODE );
        if ( ! defined( 'clisyc_OPTION_AUDIT_LOG_RETENTION' ) )         define( 'clisyc_OPTION_AUDIT_LOG_RETENTION', Constants::OPTION_AUDIT_LOG_RETENTION );
    }

    private function load_dependencies() {
        // All class loading is now handled by the PSR-4 autoloader
        // registered in autoloader.php (loaded from client-sync.php).
        //
        // View templates (admin/views/, frontend/views/) are still loaded
        // via include() in their respective parent classes — they have no
        // namespace and are not autoloadable.
    }

    private function define_hooks() {
        register_activation_hook( clisyc_PLUGIN_DIR . 'client-sync.php', [ $this, 'activate' ] );
        register_deactivation_hook( clisyc_PLUGIN_DIR . 'client-sync.php', [ $this, 'deactivate' ] );

        add_action( 'admin_init', [ $this, 'run_upgrade_routines' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_api_routes' ] );
        add_action( 'activated_plugin', [ $this, 'clear_cpt_cache' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_vendor_chunks' ], 1 );
        add_action( 'admin_enqueue_scripts', [ $this, 'register_vendor_chunks' ], 1 );

        // =========================================================================
        // SERVICE CONTAINER SETUP
        // =========================================================================
        $container = Service_Container::get_instance();

        $container->singleton( Table_Schema_Manager::class, function () {
            return new Table_Schema_Manager();
        } );

        $container->singleton( Dimension_Query_Service::class, function ( $c ) {
            return new Dimension_Query_Service( $c->make( Table_Schema_Manager::class ) );
        } );

        $container->singleton( Slot_Query_Service::class, function ( $c ) {
            return new Slot_Query_Service(
                $c->make( Table_Schema_Manager::class ),
                $c->make( Dimension_Query_Service::class )
            );
        } );

        $container->singleton( Slot_Mutation_Service::class, function ( $c ) {
            return new Slot_Mutation_Service(
                $c->make( Table_Schema_Manager::class ),
                $c->make( Slot_Query_Service::class )
            );
        } );

        $container->singleton( Database_Manager::class, function () {
            return new Database_Manager();
        } );

        $container->singleton( Slot_Repository::class, function ( $c ) {
            return new Slot_Repository( $c->make( Slot_Query_Service::class ) );
        } );

        $container->singleton( Availability_Service::class, function ( $c ) {
            return new Availability_Service( $c->make( Slot_Repository::class ) );
        } );

        $this->db_manager = $container->make( Database_Manager::class );
        
        $post_type_manager     = new PostType_Manager();
        $cron_manager          = new Cron( $this->db_manager );
        $integrity_manager     = new Data_Integrity_Manager( $this->db_manager );
        $async_tasks           = new Async_Tasks( $this->db_manager );
        $notifications_manager = new Notifications();
        $ical_generator        = new Ical_Generator();
        $admin_ajax_handler        = new Admin_Ajax_Handler( $this->db_manager );
        $appointments_ajax_handler = new Appointments_Ajax_Handler();
        $booking_ajax_handler      = new Booking_Ajax_Handler( $this->db_manager );
        $calendar_ajax_handler     = new Calendar_Ajax_Handler( $this->db_manager );
        $slots_ajax_handler        = new Slots_Ajax_Handler( $this->db_manager );
        $wc_integration        = new WooCommerce_Integration();
        $stripe_integration    = new Stripe_Integration();
        $dimension_manager     = new Dimension_Manager();
        $dimension_cpt_manager = new Dimension_CPT_Manager();

        $post_type_manager->register_hooks();
        $cron_manager->register_hooks();
        $integrity_manager->register_hooks();
        $async_tasks->register_hooks();
        $notifications_manager->register_hooks();
        $ical_generator->register_hooks();
        $admin_ajax_handler->register_hooks();
        $appointments_ajax_handler->register_hooks();
        $booking_ajax_handler->register_hooks();
        $calendar_ajax_handler->register_hooks();
        $slots_ajax_handler->register_hooks();
        $wc_integration->register_hooks();
        $stripe_integration->register_hooks();
        ( new Query_Monitor() )->register_hooks();
        Waitlist_Manager::register_hooks();
        $dimension_manager->register_hooks();
        $dimension_cpt_manager->register_hooks();

        // =========================================================================
        // HIPAA COMPLIANCE HOOKS
        // =========================================================================
        // HIPAA Helper (admin notices for encryption key warnings)
        $hipaa_helper = \DependentMedia\ClientSync\Services\HIPAA_Helper::get_instance();
        $hipaa_helper->register_hooks();
        
        // HIPAA Migration tools (WP-CLI commands + Admin AJAX handlers)
        $hipaa_migration = \DependentMedia\ClientSync\Services\HIPAA_Migration::get_instance();
        $hipaa_migration->register_hooks();
        
        // HIPAA Audit Logger (tracks all PHI access and modifications)
        $audit_logger = \DependentMedia\ClientSync\Services\Audit_Logger::get_instance();
        $audit_logger->register_hooks();
        
        // Register admin_post actions for frontend form handlers (must run in both contexts)
        // These handle form submissions that POST to admin-post.php from frontend pages
        $manager_shortcodes = new \DependentMedia\ClientSync\Frontend\Manager_Shortcodes( $this->plugin_name, $this->version );
        $manager_shortcodes->register_admin_post_handlers();
        
        if ( is_admin() ) {
            $admin_handler = new Admin( $this->plugin_name, $this->version, $this->db_manager );
            $admin_handler->register_hooks();
            $graph_node_manager = new \DependentMedia\ClientSync\Admin\Graph_Node_Manager();
            $graph_node_manager->register_hooks();
        } else {
            $frontend_handler = new Frontend( $this->plugin_name, $this->version );
            $frontend_handler->register_hooks();
            // Register shortcodes (only needed on frontend)
            $manager_shortcodes->register_all();

            if ( class_exists('\DependentMedia\ClientSync\Shortcodes\Hybrid_Booking_Shortcode') ) {
                $hybrid_shortcode = new \DependentMedia\ClientSync\Shortcodes\Hybrid_Booking_Shortcode( $this->version );
                // The constructor of this class already calls add_shortcode.
            }

            // Staff/Practitioner Dashboard shortcode
            $staff_dashboard_shortcode = new \DependentMedia\ClientSync\Shortcodes\Staff_Dashboard_Shortcode( $this->plugin_name, $this->version );
            $staff_dashboard_shortcode->register();
        }
    }

    public function activate() {
        $post_type_manager = new PostType_Manager();
        $post_type_manager->register_cpt();
        $post_type_manager->register_custom_statuses();

        flush_rewrite_rules();

        $this->db_manager = new Database_Manager();
        $this->db_manager->create_tables();
        
        // =========================================================================
        // HIPAA COMPLIANCE: Create audit log table
        // =========================================================================
        if ( class_exists( '\DependentMedia\ClientSync\Services\Audit_Logger' ) ) {
            $audit_logger = \DependentMedia\ClientSync\Services\Audit_Logger::get_instance();
            $audit_logger->create_table();
        }
        
        $this->setup_default_options();

        $cron_manager = new Cron( $this->db_manager );
        $cron_manager->schedule_cron_jobs();

        $integrity_manager = new Data_Integrity_Manager( $this->db_manager );
        $integrity_manager->schedule();

        // Schedule audit log cleanup cron (avoids wp_next_scheduled() on every request).
        if ( ! wp_next_scheduled( 'clisyc_audit_log_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'clisyc_audit_log_cleanup' );
        }

        if ( class_exists( '\DependentMedia\ClientSync\Admin\Graph_Node_Manager' ) ) {
            $graph_node_manager = new \DependentMedia\ClientSync\Admin\Graph_Node_Manager();
            $graph_node_manager->rebuild_index();
        }

        set_transient( 'clisyc_activation_redirect', true, Constants::TRANSIENT_ACTIVATION_REDIRECT_TTL );
    }

    public function deactivate() {
        flush_rewrite_rules();
        if ( ! $this->db_manager ) {
            $this->db_manager = new Database_Manager();
        }
        $cron_manager = new Cron( $this->db_manager );
        $cron_manager->unschedule_cron_jobs();

        $integrity_manager = new Data_Integrity_Manager( $this->db_manager );
        $integrity_manager->unschedule();

        // Unschedule audit log cleanup cron.
        $audit_timestamp = wp_next_scheduled( 'clisyc_audit_log_cleanup' );
        if ( $audit_timestamp ) {
            wp_unschedule_event( $audit_timestamp, 'clisyc_audit_log_cleanup' );
        }
    }
    
    private function setup_default_options() {
        $this->setup_default_options_public();
    }

    public function setup_default_options_public() {
        add_option( Constants::OPTION_CALENDAR_START_TIME, '08:00' );
        add_option( Constants::OPTION_CALENDAR_END_TIME, '18:00' );
        add_option( 'clisyc_standard_schedule_calendar_start_time', '09:00' );
        add_option( 'clisyc_standard_schedule_calendar_end_time', '17:00' );
        add_option( Constants::OPTION_CALENDAR_SLOT_DURATION, '00:15:00' );
        add_option( Constants::OPTION_CALENDAR_SMART_START, false );
        add_option( Constants::OPTION_CALENDAR_WEEK_STARTS, -1 );
        add_option( Constants::OPTION_CUSTOM_FIELDS, [] );
        add_option( Constants::OPTION_CUSTOM_FIELDS_ORDER, [] );
        add_option( Constants::OPTION_APPOINTMENT_FIELDS, [] );
        add_option( Constants::OPTION_CUSTOM_FIELDS_ORDER, [] );
        add_option( Constants::OPTION_NOTIFICATION_SETTINGS, [], '', 'no' );
        add_option( Constants::OPTION_EMAIL_FROM_NAME, get_bloginfo( 'name' ) );
        add_option( Constants::OPTION_EMAIL_FROM_ADDRESS, get_option( 'admin_email' ) );
        add_option( Constants::OPTION_NOTIFICATION_ADMINS, get_option( 'admin_email' ) );
        add_option( Constants::OPTION_REMINDER_SETTINGS, [ 'enabled' => false, 'lead_time_value' => 24, 'lead_time_unit' => 'hours' ] );
        add_option( Constants::OPTION_AUTO_GEN_ENABLED, false );
        add_option( Constants::OPTION_AUTO_GEN_LOOKAHEAD, 14 );
        add_option( Constants::OPTION_AUTO_GEN_DAYS, [ 1, 2, 3, 4, 5 ] );
        add_option( Constants::OPTION_WC_ENABLED, false );
        add_option( Constants::OPTION_WC_PRODUCT_ID, 0 );
        add_option( Constants::OPTION_FRONTEND_CALENDAR_STYLE, [ Constants::OPTION_INITIAL_VIEW => 'timeGridWeek' ] );
        add_option( Constants::OPTION_LOGIN_PAGE_URL, '' );
        add_option( Constants::OPTION_APPOINTMENT_VIEW_PAGE, 0 );
        add_option( Constants::OPTION_MIN_BOOKING_NOTICE, 60 );
        add_option( Constants::OPTION_GLOBAL_BUFFER_BEFORE, 0 );
        add_option( Constants::OPTION_GLOBAL_BUFFER_AFTER, 0 );
        add_option( Constants::OPTION_ENABLE_SELF_SERVICE, false );
        add_option( Constants::OPTION_CANCEL_CUTOFF_VALUE, 24 );
        add_option( Constants::OPTION_CANCEL_CUTOFF_UNIT, 'hours' );

        // =========================================================================
        // WAITLIST DEFAULTS
        // =========================================================================
        add_option( Constants::OPTION_WAITLIST_ENABLED, false );
        add_option( Constants::OPTION_WAITLIST_MAX_SIZE, 0 );
        add_option( Constants::OPTION_WAITLIST_AUTO_PROMOTE, true );

        // =========================================================================
        // HIPAA COMPLIANCE DEFAULTS
        // =========================================================================
        add_option( Constants::OPTION_HIPAA_MODE, false );
        add_option( Constants::OPTION_AUDIT_LOG_RETENTION, 2555 ); // ~7 years (HIPAA requirement)
        add_option( 'clisyc_anonymize_external_sync', true );
    }

    public function run_upgrade_routines() {
        $runner = new Migration_Runner();
        $runner->run_pending_migrations();
    }

    public function register_rest_api_routes() {
        $controllers = [
            '\DependentMedia\ClientSync\RestAPI\Slots_Controller',
            '\DependentMedia\ClientSync\RestAPI\Filter_Options_Controller',
            '\DependentMedia\ClientSync\RestAPI\Dimensions_Controller',
            '\DependentMedia\ClientSync\RestAPI\Price_Controller',
            '\DependentMedia\ClientSync\RestAPI\Users_Controller',
            '\DependentMedia\ClientSync\RestAPI\Timeline_Controller',
            '\DependentMedia\ClientSync\RestAPI\Appointments_Rest_Controller',
        ];

        /**
         * Filters the list of REST API controller classes to register.
         *
         * Extensions can add their own controllers to this array.
         *
         * @since 3.5.2
         *
         * @param string[] $controllers Array of fully-qualified controller class names.
         */
        $controllers = apply_filters( 'clisyc_rest_controllers', $controllers );

        foreach ( $controllers as $controller_class ) {
            if ( class_exists( $controller_class ) ) {
                $controller = new $controller_class();
                $controller->register_routes();
            }
        }

        /**
         * Fires after all Client Sync REST API routes are registered.
         *
         * @since 3.5.2
         */
        do_action( 'clisyc_rest_api_init' );
    }

    public function clear_cpt_cache() {
        delete_transient('clisyc_all_cpts_cache');
    }

    /**
     * Pre-register vendor chunk scripts created by webpack splitChunks.
     *
     * Vendor chunks (e.g. vendor-fullcalendar, vendor-reactflow) are shared
     * libraries extracted into separate files to avoid duplication across
     * entry points. They must be registered before any entry script that
     * depends on them is enqueued.
     */
    public function register_vendor_chunks(): void {
        $dist_dir  = clisyc_PLUGIN_DIR . 'assets/dist/';
        $dist_url  = clisyc_PLUGIN_URL . 'assets/dist/';

        // Auto-discover vendor chunks by looking for vendor-*/index.js files.
        $vendor_glob = glob( $dist_dir . 'vendor-*/index.js' );
        if ( ! is_array( $vendor_glob ) ) {
            return;
        }

        foreach ( $vendor_glob as $vendor_js ) {
            $chunk_dir  = dirname( $vendor_js );
            $chunk_name = basename( $chunk_dir ); // e.g. "vendor-fullcalendar"
            $handle     = 'clisyc-' . $chunk_name;

            // Load the auto-generated asset file for dependencies & version.
            $asset_path = $chunk_dir . '/index.asset.php';
            $asset      = file_exists( $asset_path )
                ? require $asset_path
                : [ 'dependencies' => [], 'version' => $this->version ];

            wp_register_script(
                $handle,
                $dist_url . $chunk_name . '/index.js',
                $asset['dependencies'],
                $asset['version'],
                true
            );
        }
    }
}
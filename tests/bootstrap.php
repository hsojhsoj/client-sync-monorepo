<?php
/**
 * PHPUnit bootstrap file.
 */

// Define constants BEFORE Composer autoload, because our PSR-4 autoloader
// (loaded via Composer's "files" directive) checks for CLISYC_SHARED_DIR
// in its guard clause to allow loading outside of WordPress.
if ( ! defined( 'CLISYC_SHARED_DIR' ) ) {
    define( 'CLISYC_SHARED_DIR', dirname( __DIR__ ) . '/src/shared/' );
}
if ( ! defined( 'CLISYC_PRO_DIR' ) ) {
    define( 'CLISYC_PRO_DIR', dirname( __DIR__ ) . '/src/pro/' );
}

// Encryption key for EncryptionServiceTest (AES-256 requires 32+ chars)
if ( ! defined( 'CLISYC_ENCRYPTION_KEY' ) ) {
    define( 'CLISYC_ENCRYPTION_KEY', 'phpunit-test-encryption-key-32chars!' );
}

// Load Composer autoloader (for dependencies like Google, Twilio, etc.)
// Must come AFTER the constant definitions above.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// The WP_TESTS_DIR is set by an env var in phpunit.xml.dist
$wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $wp_tests_dir ) {
    $wp_tests_dir = dirname( __DIR__ ) . '/tmp/wordpress-tests-lib'; // Fallback
}

// --- START: THE DEFINITIVE FIX ---
// Define the path to our config file BEFORE loading the WP test suite functions.
// The WP test suite's bootstrap process will look for and use this constant.
define( 'WP_TESTS_CONFIG_FILE_PATH', dirname( __DIR__ ) . '/wp-tests-config.php' );
// --- END: THE DEFINITIVE FIX ---

// Now, load the WP test suite bootstrap functions.
if ( ! file_exists( $wp_tests_dir . '/includes/functions.php' ) ) {
    echo "Could not find {$wp_tests_dir}/includes/functions.php, have you run `./build.sh test-setup` ?" . PHP_EOL;
    exit( 1 );
}
require_once $wp_tests_dir . '/includes/functions.php';

// Manually loads the plugins we want to activate for testing.
function _manually_load_plugins() {
    $wc_plugin_path = dirname( __DIR__ ) . '/tmp/wordpress/wp-content/plugins/woocommerce/woocommerce.php';
    if ( file_exists( $wc_plugin_path ) ) {
        require_once $wc_plugin_path;
    }
    
    update_option('clisyc_pro_license_data', ['key' => 'test-key-for-phpunit', 'status' => 'Active']);

    require_once dirname( __DIR__ ) . '/src/free/client-sync.php';
    require_once dirname( __DIR__ ) . '/src/pro/client-sync-pro.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugins' );

// The main setup function for the entire test suite.
function _install_and_setup() {
    if ( class_exists( 'WC_Install' ) ) {
        \WC_Install::install();
    }
    
    (new \ClientSyncPro\Modules\Memberships\Membership_CPT())->register_cpt();
    (new \DependentMedia\ClientSync\Admin\Dimension_CPT_Manager())->register_custom_dimensions_cpts();
    
    update_option('clisyc_custom_dimension_types', [
        'clisyc_service' => ['singular' => 'Service', 'plural' => 'Services', 'icon' => 'dashicons-admin-generic', 'public' => true]
    ]);
    update_option('clisyc_dimension_registry', [
        'dimensions' => [ 'clisyc_service' => ['enabled' => true, 'primary' => true] ],
    ]);
    
    if ( class_exists( '\DependentMedia\ClientSync\Plugin' ) ) {
        $plugin_instance = new \DependentMedia\ClientSync\Plugin();
        $plugin_instance->activate();
    }
}
tests_add_filter( 'setup_theme', '_install_and_setup' );

// Start up the WP testing environment's own bootstrap file.
require $wp_tests_dir . '/includes/bootstrap.php';
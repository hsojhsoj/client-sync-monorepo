#!/bin/bash
# This line ensures the correct PHP version is used for this script and its child processes.
export PATH=/opt/plesk/php/8.3/bin:$PATH
set -e

# Start timing
SECONDS=0

# --- Configuration ---
LOCAL_WP_PLUGINS_DIR="/var/www/vhosts/testblankwp.dependentmedia.com/httpdocs/wp-content/plugins"
LOCAL_WP_OWNER_GROUP="testblan:psacln"
PLUGIN_VERSION="3.7.3"
PRO_VERSION="1.6.2"
SRC_DIR="src"
BUILD_DIR="build"

# Test environment settings.
#
# TEST_DB_PASS is read from the environment (or from a local, git-ignored
# `.env.test` file if present) rather than being hard-coded here — this
# repo is public on GitHub, and even a local-dev password has no business
# being committed. The test harness only needs a value; what it is doesn't
# matter. Override for your local setup by either:
#     export TEST_DB_PASS='whatever'
# or by creating a `.env.test` file with:
#     TEST_DB_PASS=whatever
if [ -z "${TEST_DB_PASS:-}" ] && [ -f "${SCRIPT_DIR:-$(dirname "$0")}/.env.test" ]; then
    # shellcheck disable=SC1091
    . "${SCRIPT_DIR:-$(dirname "$0")}/.env.test"
fi
TEST_DB_NAME="${TEST_DB_NAME:-client-sync-monorepo}"
TEST_DB_USER="${TEST_DB_USER:-test-cs-monorepo}"
TEST_DB_PASS="${TEST_DB_PASS:-}"
TEST_WP_VERSION="${TEST_WP_VERSION:-latest}"
TEST_TMP_DIR="tmp"

# Build mode (sequential or parallel)
BUILD_MODE="${BUILD_MODE:-sequential}"

# --- Function Definitions ---
deploy_local() {
    local plugin_name="$1"
    local source_dir="$BUILD_DIR/$plugin_name"
    echo "-> Deploying '$plugin_name' to local WordPress install..."
    if [ ! -d "$LOCAL_WP_PLUGINS_DIR" ]; then
        echo "❌ ERROR: Local plugins directory not found at '$LOCAL_WP_PLUGINS_DIR'."
        exit 1
    fi
    local dest_dir="$LOCAL_WP_PLUGINS_DIR/$plugin_name"
    if [ -d "$dest_dir" ]; then
        rm -rf "$dest_dir"
    fi
    cp -R "$source_dir" "$dest_dir"
    if [ -n "$LOCAL_WP_OWNER_GROUP" ]; then
        echo "   - Setting ownership to '$LOCAL_WP_OWNER_GROUP'..."
        chown -R "$LOCAL_WP_OWNER_GROUP" "$dest_dir"
    fi
    echo "   - Deployment of '$plugin_name' complete."
}

# Fix l10n.php files to include ABSPATH check (required by WordPress Plugin Check)
# Poedit generates these files without the security check, so we inject it post-build.
fix_l10n_direct_access() {
    local target_dir="$1"
    local languages_dir="$target_dir/languages"
    
    if [ ! -d "$languages_dir" ]; then
        return 0  # No languages directory, nothing to fix
    fi
    
    echo "   - Fixing direct access protection in l10n.php files..."
    
    # Find all .l10n.php files
    find "$languages_dir" -name "*.l10n.php" -type f | while read -r l10n_file; do
        # Check if the file already has ABSPATH protection
        if ! grep -q "defined( 'ABSPATH' )" "$l10n_file"; then
            echo "     - Adding ABSPATH check to: $(basename "$l10n_file")"
            
            # Create a temp file with the fix
            # We insert the check after the comment line (line 2)
            sed -i '2a\
if ( ! defined( '\''ABSPATH'\'' ) ) {\
	exit;\
}' "$l10n_file"
        fi
    done
}

build_free() {
    echo "-------------------------------------"
    echo "Building Client Sync (Free) v$PLUGIN_VERSION..."
    local dest="$BUILD_DIR/client-sync"
    mkdir -p "$dest"
    
    # Exclude webpack source files (assets/src/) — only ship compiled dist/ output
    rsync -a --exclude '.DS_Store' --exclude 'src/' "$SRC_DIR/assets/" "$dest/assets/"
    rsync -a --exclude '.DS_Store' "$SRC_DIR/shared/" "$dest/"
    
    cp "$SRC_DIR/free/client-sync.php" "$dest/"
    cp "$SRC_DIR/free/readme.txt" "$dest/"
    cp "$SRC_DIR/free/uninstall.php" "$dest/"
    
    if [ -d "build/blocks" ]; then
      mkdir -p "$dest/build/blocks"
      cp -R "build/blocks/." "$dest/build/blocks/"
    fi
    
    # NOTE: We do NOT copy composer.json to Free, as it lists Pro requirements.

    echo "   - Copying vendor directory..."
    cp -R "vendor" "$dest/vendor"

    # --- Vendor Optimization & Pro Cleanup ---
    echo "   - Removing Pro-only libraries from Free build..."
    
    # 1. Remove the main Pro libraries
    rm -rf "$dest/vendor/google"
    rm -rf "$dest/vendor/twilio"
    rm -rf "$dest/vendor/dompdf"
    
    # 2. Remove dependencies of those libraries (Dead code for Free)
    # Google/Twilio dependencies:
    rm -rf "$dest/vendor/phpseclib"
    rm -rf "$dest/vendor/monolog"
    rm -rf "$dest/vendor/guzzlehttp"
    rm -rf "$dest/vendor/psr"
    rm -rf "$dest/vendor/firebase"
    rm -rf "$dest/vendor/paragonie"
    rm -rf "$dest/vendor/ralouphie"
    rm -rf "$dest/vendor/symfony"
    
    # DomPDF dependencies:
    rm -rf "$dest/vendor/sabberworm"  # CSS Parser
    rm -rf "$dest/vendor/phenx"       # Font/SVG libs
    rm -rf "$dest/vendor/masterminds" # HTML5 parser
    
    # 3. General cleanup (docs, tests, git)
    echo "   - Cleaning up metadata..."
    find "$dest/vendor" -type d -name ".git" -exec rm -rf {} +
    find "$dest/vendor" -type d -name "tests" -exec rm -rf {} +
    find "$dest/vendor" -type d -name "doc" -exec rm -rf {} +
    find "$dest/vendor" -type f -name "*.md" -delete
    find "$dest/vendor" -type f -name "composer.json" -delete
    find "$dest/vendor" -type f -name "composer.lock" -delete
    
    # Fix l10n.php files for WordPress Plugin Check compliance
    fix_l10n_direct_access "$dest"
    
    echo "✅ Free build complete."
}

zip_free() {
    echo "   - Zipping free version..."
    cd "$BUILD_DIR" && zip -qr "client-sync-v$PLUGIN_VERSION.zip" "client-sync" && cd ..
}

build_pro() {
    echo "-------------------------------------"
    echo "Building Client Sync Pro v$PRO_VERSION..."
    local dest="$BUILD_DIR/client-sync-pro"
    mkdir -p "$dest"
    
    # Copy PRO specific files (exclude co-located JS source/dist — now built to assets/dist/)
    rsync -a --exclude '.DS_Store' \
        --exclude 'includes/modules/forms/js/src/' \
        --exclude 'includes/modules/forms/js/dist/' \
        --exclude 'includes/modules/outputs/js/src/' \
        --exclude 'includes/modules/outputs/js/dist/' \
        "$SRC_DIR/pro/" "$dest/"
    
    if [ -d "build/blocks" ]; then
      mkdir -p "$dest/build/blocks"
      cp -R "build/blocks/." "$dest/build/blocks/"
    fi
    
    # Copy Composer files
    cp "composer.json" "$dest/"
    cp "composer.lock" "$dest/"
    
    echo "   - Copying complete vendor directory..."
    cp -R "vendor" "$dest/vendor"

    echo "   - Cleaning up vendor bloat..."
    
    # 1. Standard Clean (Docs, Tests, Git)
    find "$dest/vendor" -type d -name ".git" -exec rm -rf {} +
    find "$dest/vendor" -type d -name "tests" -exec rm -rf {} +
    find "$dest/vendor" -type d -name "Test" -exec rm -rf {} +
    find "$dest/vendor" -type d -name "doc" -exec rm -rf {} +
    find "$dest/vendor" -type d -name "docs" -exec rm -rf {} +
    find "$dest/vendor" -type d -name "examples" -exec rm -rf {} +
    find "$dest/vendor" -type f -name "*.md" -delete
    find "$dest/vendor" -type f -name "*.dist" -delete
    
    # 2. AGGRESSIVE GOOGLE CLEANUP (FIXED PATH)
    # The services are directly in src/
    
    GOOGLE_SVC_DIR="$dest/vendor/google/apiclient-services/src"
    
    if [ -d "$GOOGLE_SVC_DIR" ]; then
        echo "   - Stripping unused Google Services from: $GOOGLE_SVC_DIR"
        
        # We need to be careful not to delete the 'Google' folder itself if it exists,
        # but based on your tree, the services are direct children of src.
        
        # Delete unused Service FOLDERS (e.g., YouTube/, Drive/)
        # We keep 'Calendar', 'Oauth2', and 'PeopleService' (just in case)
        find "$GOOGLE_SVC_DIR" -mindepth 1 -maxdepth 1 -type d \
            -not -name "Calendar" \
            -not -name "Oauth2" \
            -not -name "PeopleService" \
            -exec rm -rf {} +
        
        # Delete unused Service FILES (e.g., YouTube.php)
        # Some versions have the main class file in the root of src
        find "$GOOGLE_SVC_DIR" -mindepth 1 -maxdepth 1 -type f \
            -not -name "Calendar.php" \
            -not -name "Oauth2.php" \
            -not -name "PeopleService.php" \
            -delete
            
    else
        echo "⚠️ WARNING: Could not find Google Services directory at $GOOGLE_SVC_DIR"
    fi
    
    # Fix l10n.php files for WordPress Plugin Check compliance
    fix_l10n_direct_access "$dest"
    
    echo "✅ Pro build complete."
}

zip_pro() {
    echo "   - Zipping pro version..."
    cd "$BUILD_DIR" && zip -qr "client-sync-pro-v$PRO_VERSION.zip" "client-sync-pro" && cd ..
}

build_assets() {
    echo "Installing NPM dependencies and building assets..."
    npm install
    
    if [ "$BUILD_MODE" = "parallel" ]; then
        echo "Building assets in PARALLEL mode (faster)..."
        npm run build:parallel
    else
        echo "Building assets in SEQUENTIAL mode..."
        npm run build
    fi
}

setup_tests() {
    echo "-------------------------------------"
    echo "Setting up PHPUnit test environment..."
    echo "   - Installing Composer dependencies..."
    composer install

    echo "   - Setting up WordPress test suite..."
    
    local WP_CORE_DIR="${TEST_TMP_DIR}/wordpress"
    local WP_TESTS_DIR="${TEST_TMP_DIR}/wordpress-tests-lib"
    
    local resolved_wp_version="$TEST_WP_VERSION"
    if [ "$resolved_wp_version" == "latest" ]; then
        resolved_wp_version=$(curl -s "https://api.wordpress.org/core/version-check/1.7/" | grep -oE '"version":"[^"]+"' | head -1 | cut -d'"' -f4)
    fi

    if [ ! -d "$WP_CORE_DIR" ]; then
        echo "     - Downloading WordPress v${resolved_wp_version}..."
        mkdir -p "$WP_CORE_DIR"
        curl -s "https://wordpress.org/wordpress-${resolved_wp_version}.tar.gz" | tar --strip-components=1 -zx -C "$WP_CORE_DIR"
    fi

    if [ ! -d "$WP_TESTS_DIR" ]; then
        echo "     - Downloading WP Test Suite for v${resolved_wp_version}..."
        svn co --quiet "https://develop.svn.wordpress.org/tags/${resolved_wp_version}/tests/phpunit/" "$WP_TESTS_DIR"
    fi
    
    echo "     - Creating wp-tests-config.php with correct paths..."
    
    local project_root_path=$(pwd)
    
    cat > wp-tests-config.php <<-EOF
<?php
// Path to the WordPress codebase to test.
define( 'ABSPATH', '${project_root_path}/${WP_CORE_DIR}/' );
define( 'WP_TESTS_DIR', '${project_root_path}/${WP_TESTS_DIR}/' );

// Test Database Credentials
define( 'DB_NAME',     '${TEST_DB_NAME}' );
define( 'DB_USER',     '${TEST_DB_USER}' );
define( 'DB_PASSWORD', '${TEST_DB_PASS}' );
define( 'DB_HOST',     'localhost' );
define( 'DB_CHARSET',  'utf8' );
define( 'DB_COLLATE',  '' );

// Test Table Prefix
\$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
EOF

    echo "   - Installing WooCommerce for testing..."
    local wc_zip="woocommerce.zip"
    local wc_plugin_dir="${TEST_TMP_DIR}/wordpress/wp-content/plugins/"
    local wc_dest_dir="${wc_plugin_dir}woocommerce"
    if [ ! -d "$wc_dest_dir" ]; then
        echo "     - Creating plugins directory..."
        mkdir -p "$wc_plugin_dir"
        
        echo "     - Downloading WooCommerce..."
        curl -s -L https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip -o "$wc_zip"
        echo "     - Unzipping WooCommerce..."
        unzip -q "$wc_zip" -d "$wc_plugin_dir"
        rm "$wc_zip"
    else
        echo "     - WooCommerce already installed. Skipping."
    fi

    echo "✅ Test environment setup complete."
    echo "You can now run tests with: ./build.sh test"
    echo "-------------------------------------"
}

# --- Main Execution Logic ---
COMMAND=${1:-all}

if [ "$COMMAND" != "test-setup" ] && [ "$COMMAND" != "test" ] && [ "$COMMAND" != "test-coverage" ] && [ "$COMMAND" != "test-all" ] && [ "$COMMAND" != "sync" ] && [ "$COMMAND" != "deploy" ] && [ "$COMMAND" != "sign" ]; then
    echo "Cleaning up old build files..."
    rm -rf "$BUILD_DIR"
fi

# Proactively remove macOS system files from the entire project before building
find . -name ".DS_Store" -delete

case "$COMMAND" in
    test-setup)
        setup_tests
        ;;
    test)
        echo "Running PHPUnit tests..."
        vendor/bin/phpunit --colors=always
        ;;
    test-coverage)
        echo "Running PHPUnit tests with code coverage..."
        echo "(Requires Xdebug or PCOV PHP extension)"
        mkdir -p tests/coverage
        XDEBUG_MODE=coverage vendor/bin/phpunit --colors=always --coverage-html tests/coverage/html --coverage-clover tests/coverage/clover.xml
        echo "✅ Coverage report: tests/coverage/html/index.html"
        ;;
    test-all)
        echo "Running all tests (PHP + JavaScript)..."
        echo ""
        echo "=== PHPUnit ==="
        vendor/bin/phpunit --colors=always
        echo ""
        echo "=== Jest ==="
        npm run test:js
        echo ""
        echo "✅ All tests complete."
        ;;
    sign)
        # Interactive helper that signs a built Pro ZIP using an Ed25519 secret
        # key pasted at runtime. Produces the $signed_payload and $signature
        # values to drop into src/pro/update-server/update-info.php.
        #
        # The secret key is entered with echo disabled and lives only in the
        # child PHP process — it never hits disk, shell history, or env.
        # See AI_HANDOFF.md → "Pro update signing" for the full procedure.
        ZIP_ARG="${2:-}"
        if [ -z "$ZIP_ARG" ]; then
            # Default: most recent Pro ZIP in build/
            ZIP_ARG=$(ls -t "$BUILD_DIR"/client-sync-pro-v*.zip 2>/dev/null | head -n 1 || true)
        fi
        if [ -z "$ZIP_ARG" ] || [ ! -f "$ZIP_ARG" ]; then
            echo "❌ ERROR: No Pro ZIP found. Usage: ./build.sh sign [path/to/client-sync-pro-vX.Y.Z.zip]"
            echo "   Or run ./build.sh zip first to produce one."
            exit 1
        fi

        # Derive the version from the filename: client-sync-pro-vX.Y.Z.zip -> X.Y.Z
        ZIP_BASENAME=$(basename "$ZIP_ARG")
        SIGN_VERSION=$(echo "$ZIP_BASENAME" | sed -E 's/^client-sync-pro-v(.+)\.zip$/\1/')
        if [ "$SIGN_VERSION" = "$ZIP_BASENAME" ]; then
            echo "❌ ERROR: Could not derive version from filename '$ZIP_BASENAME'."
            echo "   Expected format: client-sync-pro-vX.Y.Z.zip"
            exit 1
        fi

        if command -v shasum >/dev/null 2>&1; then
            ZIP_HASH=$(shasum -a 256 "$ZIP_ARG" | awk '{print $1}')
        elif command -v sha256sum >/dev/null 2>&1; then
            ZIP_HASH=$(sha256sum "$ZIP_ARG" | awk '{print $1}')
        else
            echo "❌ ERROR: Neither shasum nor sha256sum is available."
            exit 1
        fi

        RELEASED_AT=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
        SIGNED_PAYLOAD=$(printf '{"version":"%s","zip_sha256":"%s","released_at":"%s"}' \
            "$SIGN_VERSION" "$ZIP_HASH" "$RELEASED_AT")

        echo "-------------------------------------"
        echo "Signing Pro release:"
        echo "   ZIP        : $ZIP_ARG"
        echo "   Version    : $SIGN_VERSION"
        echo "   SHA256     : $ZIP_HASH"
        echo "   Released at: $RELEASED_AT"
        echo "-------------------------------------"

        # Sign in a child PHP process. Secret key is read from stdin with echo
        # suppressed and zeroed with sodium_memzero after signing.
        SIGNATURE_B64=$(php -r '
            $payload = $argv[1];
            fwrite( STDERR, "Paste base64 Ed25519 secret key (input hidden, then Enter): " );
            system( "stty -echo" );
            $sk_b64 = trim( fgets( STDIN ) );
            system( "stty echo" );
            fwrite( STDERR, "\n" );
            $sk = base64_decode( $sk_b64, true );
            if ( false === $sk || strlen( $sk ) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ) {
                fwrite( STDERR, "❌ ERROR: Invalid secret key (wrong length or bad base64).\n" );
                exit( 1 );
            }
            $sig = sodium_crypto_sign_detached( $payload, $sk );
            echo base64_encode( $sig );
            sodium_memzero( $sk );
        ' "$SIGNED_PAYLOAD")

        if [ -z "$SIGNATURE_B64" ]; then
            echo "❌ ERROR: Signing failed — no signature produced."
            exit 1
        fi

        echo ""
        echo "✅ Signed. Paste these into src/pro/update-server/update-info.php:"
        echo ""
        echo "\$signed_payload = '${SIGNED_PAYLOAD}';"
        echo "\$signature      = '${SIGNATURE_B64}';"
        echo ""
        echo "Also update the outer 'version' field in update-info.php to '${SIGN_VERSION}'."
        echo "-------------------------------------"
        ;;
    sync)
        REMOTE_USER="testblan"
        REMOTE_HOST="44.240.240.195"
        REMOTE_PATH="/var/www/vhosts/testblankwp.dependentmedia.com/projects/client-sync-monorepo"

        echo "Syncing project to ${REMOTE_USER}@${REMOTE_HOST}..."
        echo "Remote path: ${REMOTE_PATH}"
        echo ""

        # Pull composer.lock from server first (if it exists) so it's included
        # in the push and not deleted by --delete. This avoids expensive
        # dependency re-resolution on every build.
        if [ ! -f "composer.lock" ]; then
            rsync -az "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}/composer.lock" ./composer.lock 2>/dev/null || true
        fi

        rsync -avz --delete \
            --exclude 'node_modules/' \
            --exclude 'vendor/' \
            --exclude 'tmp/' \
            --exclude '.phpunit.result.cache' \
            --exclude 'tests/coverage/' \
            --exclude '__MACOSX/' \
            --exclude '.DS_Store' \
            --exclude 'build/' \
            --exclude 'wp-tests-config.php' \
            ./ "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}/"

        echo ""
        echo "✅ Sync complete."
        echo ""
        echo "To run tests on the server:"
        echo "  ssh ${REMOTE_USER}@${REMOTE_HOST} 'cd ${REMOTE_PATH} && ./build.sh test'"
        ;;
    deploy)
        REMOTE_USER="testblan"
        REMOTE_HOST="44.240.240.195"
        REMOTE_PATH="/var/www/vhosts/testblankwp.dependentmedia.com/projects/client-sync-monorepo"

        # Pull composer.lock first so it's not deleted by --delete.
        if [ ! -f "composer.lock" ]; then
            rsync -az "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}/composer.lock" ./composer.lock 2>/dev/null || true
        fi

        echo "=== Step 1/2: Syncing to server ==="
        rsync -avz --delete \
            --exclude 'node_modules/' \
            --exclude 'vendor/' \
            --exclude 'tmp/' \
            --exclude '.phpunit.result.cache' \
            --exclude 'tests/coverage/' \
            --exclude '__MACOSX/' \
            --exclude '.DS_Store' \
            --exclude 'build/' \
            --exclude 'wp-tests-config.php' \
            ./ "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}/"

        echo ""
        echo "=== Step 2/2: Building & deploying on server ==="
        ssh "${REMOTE_USER}@${REMOTE_HOST}" "cd ${REMOTE_PATH} && export PATH=/opt/plesk/php/8.3/bin:\$PATH && composer update --no-dev --no-interaction && bash build.sh free-pro"

        # Pull back updated composer.lock so it stays in sync locally.
        echo ""
        echo "=== Syncing composer.lock back from server ==="
        rsync -az "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}/composer.lock" ./composer.lock 2>/dev/null || true

        echo ""
        echo "✅ Deploy complete. Free + Pro plugins are live on the test site."
        ;;
    free)
        composer install --no-dev
        build_assets
        build_free
        deploy_local "client-sync"
        ;;
    pro)
        composer install --no-dev
        build_assets
        build_pro
        deploy_local "client-sync-pro"
        ;;
    free-pro)
        composer install --no-dev
        build_assets
        build_free
        build_pro
        deploy_local "client-sync"
        deploy_local "client-sync-pro"
        ;;
    zip)
        composer install --no-dev
        build_assets
        build_free
        zip_free
        build_pro
        zip_pro
        composer install
        ;;
    fast|parallel)
        # Fast/parallel build mode
        export BUILD_MODE="parallel"
        composer install --no-dev
        build_assets
        build_free
        zip_free
        deploy_local "client-sync"
        build_pro
        zip_pro
        deploy_local "client-sync-pro"
        composer install
        ;;
    *) # This is the 'all' case
        composer install --no-dev
        build_assets
        build_free
        zip_free
        deploy_local "client-sync"
        build_pro
        zip_pro
        deploy_local "client-sync-pro"
        composer install
        ;;
esac

echo "-------------------------------------"
echo "Build process complete!"

# --- TIME TRACKING LOGIC ---
duration=$SECONDS
minutes=$((duration / 60))
seconds=$((duration % 60))
echo "Total run time: ${minutes} minute(s) and ${seconds} second(s)."
echo "Build finished at: $(date)"
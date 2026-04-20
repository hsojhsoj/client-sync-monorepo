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
PRO_VERSION="1.6.3"
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

# Flags appended to every local `composer install` / `composer install --no-dev`
# invocation. Default: skip the PHP version check (but KEEP extension checks,
# unlike --ignore-platform-reqs). Rationale:
#   - The built ZIP is consumed on WordPress sites whose PHP version may
#     legitimately differ from the developer's local PHP. The runtime
#     compatibility floor is the plugin header's `Requires PHP: 7.4`, not
#     whatever the dev has installed.
#   - Without this, developers on PHP ≥ 8.5 can't run `build.sh zip`
#     locally because `sabberworm/php-css-parser` (a dompdf transitive
#     dep) caps at PHP 8.4. That's a cosmetic block, not a real
#     incompatibility — the packages run fine on any supported PHP.
#   - `--ignore-platform-req=php` is strictly narrower than
#     `--ignore-platform-reqs`: it ignores ONLY the PHP version, not
#     required extensions like ext-mbstring — so a genuinely broken
#     environment still fails loudly.
# Override with `COMPOSER_LOCAL_FLAGS= bash build.sh zip` to force strict
# platform matching locally.
COMPOSER_LOCAL_FLAGS="${COMPOSER_LOCAL_FLAGS:---ignore-platform-req=php}"

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
    composer install $COMPOSER_LOCAL_FLAGS

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

# Subcommands that need to preserve build/ (either they read from it, or they
# don't produce new artefacts and shouldn't force a rebuild on unrelated runs).
case "$COMMAND" in
    test-setup|test|test-coverage|test-all|sync|deploy|sign|publish-pro|publish-free|release) ;;
    *)
        echo "Cleaning up old build files..."
        rm -rf "$BUILD_DIR"
        ;;
esac

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
        # key pasted at runtime. By default patches the result directly into
        # src/pro/update-server/update-info.php. Pass `--print` as the second
        # arg to get the old print-only behaviour instead (useful for CI or
        # out-of-band pipelines).
        #
        # The secret key is entered with echo disabled and lives only in the
        # child PHP process — it never hits disk, shell history, or env.
        # See AI_HANDOFF.md → "Pro update signing" for the full procedure.
        SIGN_MODE="write"
        ZIP_ARG=""
        for arg in "${@:2}"; do
            case "$arg" in
                --print) SIGN_MODE="print" ;;
                --write) SIGN_MODE="write" ;;
                *)       ZIP_ARG="$arg" ;;
            esac
        done

        if [ -z "$ZIP_ARG" ]; then
            ZIP_ARG=$(ls -t "$BUILD_DIR"/client-sync-pro-v*.zip 2>/dev/null | head -n 1 || true)
        fi
        if [ -z "$ZIP_ARG" ] || [ ! -f "$ZIP_ARG" ]; then
            echo "❌ ERROR: [sign] No Pro ZIP found. Usage: ./build.sh sign [--print] [path/to/client-sync-pro-vX.Y.Z.zip]"
            echo "   Or run ./build.sh zip first to produce one."
            exit 1
        fi

        # Derive the version from the filename.
        ZIP_BASENAME=$(basename "$ZIP_ARG")
        SIGN_VERSION=$(echo "$ZIP_BASENAME" | sed -E 's/^client-sync-pro-v(.+)\.zip$/\1/')
        if [ "$SIGN_VERSION" = "$ZIP_BASENAME" ]; then
            echo "❌ ERROR: [sign] Could not derive version from filename '$ZIP_BASENAME'."
            exit 1
        fi

        if command -v shasum >/dev/null 2>&1; then
            ZIP_HASH=$(shasum -a 256 "$ZIP_ARG" | awk '{print $1}')
        elif command -v sha256sum >/dev/null 2>&1; then
            ZIP_HASH=$(sha256sum "$ZIP_ARG" | awk '{print $1}')
        else
            echo "❌ ERROR: [sign] Neither shasum nor sha256sum is available."
            exit 1
        fi

        RELEASED_AT=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
        SIGNED_PAYLOAD=$(printf '{"version":"%s","zip_sha256":"%s","released_at":"%s"}' \
            "$SIGN_VERSION" "$ZIP_HASH" "$RELEASED_AT")

        echo "[sign] step: summarize"
        echo "   ZIP        : $ZIP_ARG"
        echo "   Version    : $SIGN_VERSION"
        echo "   SHA256     : $ZIP_HASH"
        echo "   Released at: $RELEASED_AT"
        echo "   Mode       : $SIGN_MODE"

        echo "[sign] step: sign payload (child PHP; key never touches disk)"
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
            echo "❌ ERROR: [sign] Signing failed — no signature produced."
            exit 1
        fi

        if [ "$SIGN_MODE" = "print" ]; then
            echo ""
            echo "[sign] step: output (print mode)"
            echo "\$signed_payload = '${SIGNED_PAYLOAD}';"
            echo "\$signature      = '${SIGNATURE_B64}';"
            echo ""
            echo "Also confirm the outer 'version' field in update-info.php matches '${SIGN_VERSION}'."
            echo "✅ [sign] done"
            exit 0
        fi

        # --write mode: patch update-info.php in place. Use PHP rather than sed
        # so we don't have to worry about escaping the JSON payload's `/` or `$`.
        UPDATE_INFO="src/pro/update-server/update-info.php"
        if [ ! -f "$UPDATE_INFO" ]; then
            echo "❌ ERROR: [sign] $UPDATE_INFO not found — can't --write. Re-run with --print."
            exit 1
        fi

        echo "[sign] step: patch $UPDATE_INFO"
        php -r '
            $file    = $argv[1];
            $payload = $argv[2];
            $sig     = $argv[3];
            $version = $argv[4];
            $src     = file_get_contents( $file );
            if ( false === $src ) { fwrite( STDERR, "Cannot read $file\n" ); exit( 1 ); }

            // Replace the two executable assignment lines (NOT the docblock examples).
            // The assignments are at the top-level (no leading whitespace before $).
            // Use callback replacements with a count assertion so a missing line
            // fails loudly instead of silently no-op-ing.
            $count = 0;
            $src = preg_replace(
                "/^\\\$signed_payload\s*=\s*\x27[^\x27]*\x27;/m",
                "\$signed_payload = " . var_export( $payload, true ) . ";",
                $src, 1, $count
            );
            if ( $count !== 1 ) { fwrite( STDERR, "Could not locate \$signed_payload assignment\n" ); exit( 1 ); }

            $src = preg_replace(
                "/^\\\$signature\s*=\s*\x27[^\x27]*\x27;/m",
                "\$signature      = " . var_export( $sig, true ) . ";",
                $src, 1, $count
            );
            if ( $count !== 1 ) { fwrite( STDERR, "Could not locate \$signature assignment\n" ); exit( 1 ); }

            // Also confirm the outer version is consistent.
            $src = preg_replace(
                "/(\x27version\x27[[:space:]]*=>[[:space:]]*\x27)[^\x27]+(\x27)/",
                "$1" . $version . "$2",
                $src, 1, $count
            );
            if ( $count !== 1 ) { fwrite( STDERR, "Could not locate outer version key\n" ); exit( 1 ); }

            file_put_contents( $file, $src );
        ' "$UPDATE_INFO" "$SIGNED_PAYLOAD" "$SIGNATURE_B64" "$SIGN_VERSION"

        # Cheap verify: syntax-check the patched file and re-verify the signature.
        php -l "$UPDATE_INFO" >/dev/null || {
            echo "❌ ERROR: [sign] Patched file has PHP syntax errors."
            exit 1
        }
        echo "[sign] step: verify signature against installed pubkey"
        php -r '
            $pubkey_src = file_get_contents( "src/pro/includes/class-update-manager.php" );
            if ( ! preg_match( "/UPDATE_SIGNING_PUBKEY_BASE64\s*=\s*\x27([^\x27]+)\x27/", $pubkey_src, $m ) ) {
                fwrite( STDERR, "Could not read pubkey from class-update-manager.php\n" ); exit( 1 );
            }
            $pk  = base64_decode( $m[1], true );
            $sig = base64_decode( $argv[1], true );
            $ok  = sodium_crypto_sign_verify_detached( $sig, $argv[2], $pk );
            if ( ! $ok ) { fwrite( STDERR, "Signature did NOT verify against pubkey\n" ); exit( 1 ); }
        ' "$SIGNATURE_B64" "$SIGNED_PAYLOAD" || exit 1

        echo "✅ [sign] done — $UPDATE_INFO patched and signature verified"
        ;;
    publish-pro)
        # Upload a signed Pro release (ZIP + update-info.php) to
        # pass.dependentmedia.com and verify the endpoint. Idempotent —
        # re-running against the same release just re-uploads the same bytes.
        #
        # Expects the zip at build/client-sync-pro-v$PRO_VERSION.zip and a
        # signed src/pro/update-server/update-info.php (run `./build.sh sign`
        # first if not signed yet).
        echo "[publish-pro] step: locate artefacts"
        PRO_ZIP="$BUILD_DIR/client-sync-pro-v${PRO_VERSION}.zip"
        UPDATE_INFO="src/pro/update-server/update-info.php"
        if [ ! -f "$PRO_ZIP" ]; then
            echo "❌ ERROR: [publish-pro] $PRO_ZIP not found. Run './build.sh zip' first."
            exit 1
        fi
        if [ ! -f "$UPDATE_INFO" ]; then
            echo "❌ ERROR: [publish-pro] $UPDATE_INFO not found."
            exit 1
        fi

        # Sanity: confirm update-info.php is signed (not stub empty strings).
        # Parse statically rather than include()'ing, because update-info.php
        # calls echo json_encode(...) at the bottom and would leak it to stdout.
        echo "[publish-pro] step: verify update-info.php is signed"
        php -r '
            $src = file_get_contents( $argv[1] );
            if ( ! preg_match( "/^\\\$signed_payload\s*=\s*\x27([^\x27]*)\x27;/m", $src, $m_sp ) ||
                 ! preg_match( "/^\\\$signature\s*=\s*\x27([^\x27]*)\x27;/m",      $src, $m_sig ) ) {
                fwrite( STDERR, "Could not parse signed_payload / signature from update-info.php\n" ); exit( 1 );
            }
            if ( $m_sp[1] === "" || $m_sig[1] === "" ) {
                fwrite( STDERR, "signed_payload or signature is empty — run ./build.sh sign first\n" ); exit( 1 );
            }
            $payload = json_decode( $m_sp[1] );
            if ( empty( $payload->version ) || $payload->version !== $argv[2] ) {
                fwrite( STDERR, sprintf( "signed_payload version (%s) does not match PRO_VERSION (%s)\n", $payload->version ?? "?", $argv[2] ) );
                exit( 1 );
            }
        ' "$UPDATE_INFO" "$PRO_VERSION" || exit 1

        echo "[publish-pro] step: upload zip to build server /tmp"
        scp -q "$PRO_ZIP" testblan@44.240.240.195:/tmp/client-sync-pro.zip

        echo "[publish-pro] step: upload update-info.php to update-server vhost"
        scp -q -i ~/.ssh/pass_dm "$UPDATE_INFO" \
            updates.dependentmedia.com_43485@44.240.240.195:httpdocs/plugin-updates/client-sync-pro/update-info.php.new

        echo "[publish-pro] step: atomic swap on update-server vhost"
        ssh -q -i ~/.ssh/pass_dm updates.dependentmedia.com_43485@44.240.240.195 "
            set -e
            cd httpdocs/plugin-updates/client-sync-pro/
            cp /tmp/client-sync-pro.zip client-sync-pro.zip.new
            mv -f client-sync-pro.zip.new client-sync-pro.zip
            mv -f update-info.php.new update-info.php
        " || { echo "❌ ERROR: [publish-pro] remote swap failed"; exit 1; }

        echo "[publish-pro] step: verify endpoint"
        ENDPOINT_JSON=$(curl -sf https://pass.dependentmedia.com/plugin-updates/client-sync-pro/update-info.php)
        if [ -z "$ENDPOINT_JSON" ]; then
            echo "❌ ERROR: [publish-pro] endpoint returned nothing"
            exit 1
        fi
        php -r '
            $data = json_decode( $argv[1] );
            if ( empty( $data->version ) || $data->version !== $argv[2] ) {
                fwrite( STDERR, sprintf( "endpoint version (%s) != expected (%s)\n", $data->version ?? "?", $argv[2] ) ); exit( 1 );
            }
            if ( empty( $data->signature ) || empty( $data->signed_payload ) ) {
                fwrite( STDERR, "endpoint signature/signed_payload is empty\n" ); exit( 1 );
            }
        ' "$ENDPOINT_JSON" "$PRO_VERSION" || exit 1

        echo "✅ [publish-pro] done — Pro $PRO_VERSION live at pass.dependentmedia.com"
        ;;

    publish-free)
        # Publish the Free plugin to WordPress.org via SVN. Idempotent: safe to
        # re-run; if the tag already exists SVN will complain but trunk will be
        # re-synced cleanly.
        #
        # We stop before `svn commit` because that requires an interactive
        # password prompt — emit the exact command for the human to run.
        SVN_DIR="${SVN_CLIENT_SYNC_DIR:-/Users/joshuajordan/Projects/Code/SVN/client-sync}"
        FREE_BUILD="$BUILD_DIR/client-sync"

        echo "[publish-free] step: sanity"
        if [ ! -d "$SVN_DIR/trunk" ]; then
            echo "❌ ERROR: [publish-free] SVN checkout not found at $SVN_DIR"
            echo "   Check it out with: svn checkout https://plugins.svn.wordpress.org/client-sync/ $SVN_DIR"
            exit 1
        fi
        if [ ! -d "$FREE_BUILD" ]; then
            echo "❌ ERROR: [publish-free] $FREE_BUILD not found. Run './build.sh zip' first."
            exit 1
        fi
        if ! command -v svn >/dev/null 2>&1; then
            echo "❌ ERROR: [publish-free] svn not in PATH. Install with: brew install subversion"
            exit 1
        fi

        echo "[publish-free] step: svn update (pull latest)"
        ( cd "$SVN_DIR" && svn update --quiet ) || { echo "❌ ERROR: svn update failed"; exit 1; }

        echo "[publish-free] step: mirror $FREE_BUILD → $SVN_DIR/trunk"
        rsync -a --delete --exclude='.svn/' "$FREE_BUILD/" "$SVN_DIR/trunk/"

        echo "[publish-free] step: stage adds/removes"
        ( cd "$SVN_DIR" && svn status | grep '^!' | awk '{print $2}' | xargs -I{} svn rm --quiet {} 2>/dev/null || true )
        ( cd "$SVN_DIR" && svn status | grep '^?' | awk '{print $2}' | xargs -I{} svn add --quiet --force {} 2>/dev/null || true )

        TAG_PATH="$SVN_DIR/tags/$PLUGIN_VERSION"
        if [ -d "$TAG_PATH" ]; then
            echo "[publish-free] step: tag $PLUGIN_VERSION already exists — skipping svn copy"
        else
            echo "[publish-free] step: svn copy trunk → tags/$PLUGIN_VERSION"
            ( cd "$SVN_DIR" && svn copy --quiet trunk "tags/$PLUGIN_VERSION" ) || { echo "❌ ERROR: svn copy failed"; exit 1; }
        fi

        # Summary
        echo "[publish-free] step: stage summary"
        ( cd "$SVN_DIR" && svn status | awk '{print $1}' | sort | uniq -c )

        echo ""
        echo "✅ [publish-free] staged. Final commit is interactive — run this yourself:"
        echo ""
        echo "   cd $SVN_DIR && svn commit --username hsojhsoj -m \"Release $PLUGIN_VERSION\""
        echo ""
        echo "(password prompt is WP.org — input hidden)"
        ;;

    release)
        # Top-level orchestrator. Prompts for new versions, runs the full
        # release pipeline for either free, pro, or both. Intentionally stops
        # at the final interactive commits (secret-key paste, SVN password) —
        # everything else is automated.
        echo "[release] step: prompt for versions"
        CURRENT_FREE="$PLUGIN_VERSION"
        CURRENT_PRO="$PRO_VERSION"
        echo "   Current free: $CURRENT_FREE"
        echo "   Current pro : $CURRENT_PRO"
        printf "New free version (Enter to skip): "
        read -r NEW_FREE
        printf "New pro  version (Enter to skip): "
        read -r NEW_PRO

        if [ -z "$NEW_FREE" ] && [ -z "$NEW_PRO" ]; then
            echo "❌ ERROR: [release] must specify at least one new version"
            exit 1
        fi

        BUMP_ARGS=""
        [ -n "$NEW_FREE" ] && BUMP_ARGS="$BUMP_ARGS --free $NEW_FREE"
        [ -n "$NEW_PRO"  ] && BUMP_ARGS="$BUMP_ARGS --pro $NEW_PRO"

        echo "[release] step: open changelog for this release in \$EDITOR"
        CHANGELOG_TMP=$(mktemp -t clisyc-release-XXXXXX)
        cat > "$CHANGELOG_TMP" <<'CL_HINT'
# One changelog bullet per line. Lines starting with "#" are ignored.
# This text will be added to both readme.txt files and update-info.php's
# changelog. Use Markdown-style emphasis (*word* or **word**) — the
# release script converts as needed per destination.
#
# Example:
#   **Fix:** The booking form no longer crashes on Tuesdays.
#   **Enhancement:** Dimension grid respects the Appearance text-size setting.
CL_HINT
        ${EDITOR:-vi} "$CHANGELOG_TMP"
        CHANGELOG_BODY=$(grep -v '^#' "$CHANGELOG_TMP" | sed '/^[[:space:]]*$/d')
        rm -f "$CHANGELOG_TMP"
        if [ -z "$CHANGELOG_BODY" ]; then
            echo "❌ ERROR: [release] changelog is empty — aborting"
            exit 1
        fi

        echo "[release] step: bump versions"
        bash bump-version.sh $BUMP_ARGS || { echo "❌ ERROR: [release] bump-version failed"; exit 1; }

        echo "[release] step: insert changelog entries"
        # bump-version.sh doesn't touch changelogs — do it here.
        TODAY=$(date +%Y-%m-%d)
        if [ -n "$NEW_FREE" ]; then
            # src/free/readme.txt style: "= X.Y.Z =" + bullet lines prefixed "*   "
            php -r '
                $file    = "src/free/readme.txt";
                $version = $argv[1];
                $bullets = array_filter( explode( "\n", $argv[2] ) );
                $block   = "= $version =\n";
                foreach ( $bullets as $b ) { $block .= "*   " . trim( $b ) . "\n"; }
                $block .= "\n";
                $src = file_get_contents( $file );
                $src = preg_replace( "/== Changelog ==\n\n/", "== Changelog ==\n\n" . $block, $src, 1 );
                file_put_contents( $file, $src );
            ' "$NEW_FREE" "$CHANGELOG_BODY" || exit 1
        fi
        if [ -n "$NEW_PRO" ]; then
            php -r '
                $file    = "src/pro/readme.txt";
                $version = $argv[1];
                $bullets = array_filter( explode( "\n", $argv[2] ) );
                $block   = "= $version =\n";
                foreach ( $bullets as $b ) { $block .= "*   " . trim( $b ) . "\n"; }
                $block .= "\n";
                $src = file_get_contents( $file );
                $src = preg_replace( "/== Changelog ==\n\n/", "== Changelog ==\n\n" . $block, $src, 1 );
                file_put_contents( $file, $src );
            ' "$NEW_PRO" "$CHANGELOG_BODY" || exit 1

            # Also prepend to the update-info.php changelog HTML.
            php -r '
                $file    = "src/pro/update-server/update-info.php";
                $version = $argv[1];
                $bullets = array_filter( explode( "\n", $argv[2] ) );
                $html    = "<h4>$version</h4><ul>";
                foreach ( $bullets as $b ) {
                    // markdown-ish: **x** -> <strong>x</strong>
                    $b = preg_replace( "/\\*\\*(.+?)\\*\\*/", "<strong>$1</strong>", trim( $b ) );
                    $b = preg_replace( "/\\*(.+?)\\*/", "<em>$1</em>", $b );
                    $html .= "<li>$b</li>";
                }
                $html .= "</ul>";
                $src = file_get_contents( $file );
                // Insert immediately after the sections.changelog open-quote.
                $replaced = 0;
                $src = preg_replace(
                    "/(\x27changelog\x27[[:space:]]*=>[[:space:]]*\x27)/",
                    "$1" . addcslashes( $html, "\x27\\\\" ),
                    $src, 1, $replaced
                );
                if ( $replaced !== 1 ) { fwrite( STDERR, "Could not locate changelog field\n" ); exit( 1 ); }
                file_put_contents( $file, $src );
            ' "$NEW_PRO" "$CHANGELOG_BODY" || exit 1
        fi

        echo "[release] step: rebuild from new sources"
        # Re-source the new versions from the just-bumped build.sh (current process still has the old ones).
        PLUGIN_VERSION_NEW="${NEW_FREE:-$PLUGIN_VERSION}"
        PRO_VERSION_NEW="${NEW_PRO:-$PRO_VERSION}"
        # Temporarily export so the child build.sh invocation picks them up.
        # (Easier than re-exec'ing ourselves.)
        ( bash build.sh zip ) || { echo "❌ ERROR: [release] zip build failed"; exit 1; }

        if [ -n "$NEW_PRO" ]; then
            echo "[release] step: sign pro zip + patch update-info.php"
            bash build.sh sign "$BUILD_DIR/client-sync-pro-v${PRO_VERSION_NEW}.zip" || { echo "❌ ERROR: [release] sign failed"; exit 1; }
        fi

        echo "[release] step: git commit"
        git add build.sh bump-version.sh src/free/readme.txt src/free/client-sync.php src/pro/client-sync-pro.php src/pro/readme.txt src/pro/update-server/update-info.php 2>/dev/null || true
        COMMIT_MSG="Release:"
        [ -n "$NEW_FREE" ] && COMMIT_MSG="$COMMIT_MSG Client Sync $NEW_FREE"
        [ -n "$NEW_PRO"  ] && COMMIT_MSG="$COMMIT_MSG${NEW_FREE:+,} Pro $NEW_PRO"
        git commit -m "$COMMIT_MSG

$CHANGELOG_BODY" || { echo "❌ ERROR: [release] git commit failed"; exit 1; }

        echo "[release] step: git push"
        git push || { echo "⚠️  [release] git push failed — continue manually"; }

        if [ -n "$NEW_PRO" ]; then
            PRO_VERSION="$NEW_PRO" bash build.sh publish-pro || { echo "❌ ERROR: [release] publish-pro failed"; exit 1; }
        fi
        if [ -n "$NEW_FREE" ]; then
            PLUGIN_VERSION="$NEW_FREE" bash build.sh publish-free || { echo "❌ ERROR: [release] publish-free failed"; exit 1; }
        fi

        echo ""
        echo "✅ [release] done."
        [ -n "$NEW_FREE" ] && echo "   → Run the final svn commit printed above to finish WP.org publish."
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
            --exclude 'wp-svn/' \
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
        composer install --no-dev $COMPOSER_LOCAL_FLAGS
        build_assets
        build_free
        deploy_local "client-sync"
        ;;
    pro)
        composer install --no-dev $COMPOSER_LOCAL_FLAGS
        build_assets
        build_pro
        deploy_local "client-sync-pro"
        ;;
    free-pro)
        composer install --no-dev $COMPOSER_LOCAL_FLAGS
        build_assets
        build_free
        build_pro
        deploy_local "client-sync"
        deploy_local "client-sync-pro"
        ;;
    zip)
        composer install --no-dev $COMPOSER_LOCAL_FLAGS
        build_assets
        build_free
        zip_free
        build_pro
        zip_pro
        composer install $COMPOSER_LOCAL_FLAGS
        ;;
    fast|parallel)
        # Fast/parallel build mode
        export BUILD_MODE="parallel"
        composer install --no-dev $COMPOSER_LOCAL_FLAGS
        build_assets
        build_free
        zip_free
        deploy_local "client-sync"
        build_pro
        zip_pro
        deploy_local "client-sync-pro"
        composer install $COMPOSER_LOCAL_FLAGS
        ;;
    *) # This is the 'all' case
        composer install --no-dev $COMPOSER_LOCAL_FLAGS
        build_assets
        build_free
        zip_free
        deploy_local "client-sync"
        build_pro
        zip_pro
        deploy_local "client-sync-pro"
        composer install $COMPOSER_LOCAL_FLAGS
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
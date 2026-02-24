#!/bin/bash
#
# MODIFIED: This version uses a local `tmp` directory within the project.
#
set -e

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}

# Use a local tmp directory, not the system /tmp
WP_PHPUNIT_DIR="tmp"
WP_CORE_DIR="${WP_PHPUNIT_DIR}/wordpress"
WP_TESTS_DIR="${WP_PHPUNIT_DIR}/wordpress-tests-lib"

# Check for Composer-installed PHPUnit
if [ ! -f "vendor/bin/phpunit" ]; then
    echo "PHPUnit not found. Please run 'composer install'."
    exit 1
fi

download() {
    if [ `which curl` ]; then
        curl -s "$1" > "$2"
    elif [ `which wget` ]; then
        wget -nv -O "$2" "$1"
    fi
}

install_wp() {
    if [ -d "$WP_CORE_DIR" ]; then
        echo "WordPress core already exists in local tmp. Skipping download."
        return
    fi

    mkdir -p "$WP_CORE_DIR"

    if [ "$WP_VERSION" == "latest" ]; then
        local LATEST_VERSION=$(curl -s "https://api.wordpress.org/core/version-check/1.7/" | grep -oE '"version":"[^"]+"' | head -1 | cut -d'"' -f4)
        WP_VERSION=$LATEST_VERSION
    fi

    echo "Downloading WordPress $WP_VERSION to local tmp directory..."
    download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "${WP_PHPUNIT_DIR}/wordpress.tar.gz"
    tar --strip-components=1 -zxmf "${WP_PHPUNIT_DIR}/wordpress.tar.gz" -C "$WP_CORE_DIR"
    rm "${WP_PHPUNIT_DIR}/wordpress.tar.gz"
}

install_test_suite() {
    # Sourced from https://develop.svn.wordpress.org/trunk/tests/phpunit/bin/install-wp-tests.sh
    # fetch the latest tags
    local LATEST_TAG=$(svn ls "https://develop.svn.wordpress.org/tags/" | grep '^[0-9.]\+$' | sort -V | tail -n 1)

    if [ -d "$WP_TESTS_DIR" ]; then
         echo "WordPress test suite already exists in local tmp. Skipping download."
    else
        echo "Downloading WordPress test suite to local tmp directory..."
        # install the test suite
        svn co "https://develop.svn.wordpress.org/tags/${LATEST_TAG}/tests/phpunit/" "$WP_TESTS_DIR"
    fi

    # The wp-tests-config.php file is now created in the local tests dir
    if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
        download "https://develop.svn.wordpress.org/tags/${LATEST_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
    fi

    sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s:__DIR__ . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR/wp-tests-config.php"

    sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/localhost/$DB_HOST/" "$WP_TESTS_DIR/wp-tests-config.php"
}

# Main execution
install_wp
install_test_suite
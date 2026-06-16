#!/usr/bin/env bash
# Install the WordPress test library + a test database.
# Usage: install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]
# Adapted from the canonical `wp scaffold plugin-tests` installer.
set -euo pipefail

DB_NAME=${1-wordpress_test}
DB_USER=${2-root}
DB_PASS=${3-root}
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

download() {
	if command -v curl >/dev/null 2>&1; then
		curl -s "$1" >"$2"
	elif command -v wget >/dev/null 2>&1; then
		wget -nv -O "$2" "$1"
	else
		echo "Need curl or wget." >&2; exit 1
	fi
}

# Resolve "latest" to a concrete version.
if [[ "$WP_VERSION" == "latest" ]]; then
	download http://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
	WP_VERSION=$(grep -o '"version":"[^"]*' /tmp/wp-latest.json | head -1 | sed 's/.*"version":"//')
fi
echo "Using WordPress ${WP_VERSION}"

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		echo "WordPress core already present at $WP_CORE_DIR"
		return
	fi
	mkdir -p "$WP_CORE_DIR"
	local archive=/tmp/wordpress.tar.gz
	download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "$archive"
	tar --strip-components=1 -zxmf "$archive" -C "$WP_CORE_DIR"
	download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php"
}

install_test_suite() {
	mkdir -p "$WP_TESTS_DIR"
	# The test library lives in the develop repo; svn-export the two folders we need.
	if [ ! -d "$WP_TESTS_DIR/includes" ]; then
		svn export --quiet "https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes" \
			|| svn export --quiet "https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
	fi
	if [ ! -d "$WP_TESTS_DIR/data" ]; then
		svn export --quiet "https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/data/" "$WP_TESTS_DIR/data" \
			|| svn export --quiet "https://develop.svn.wordpress.org/trunk/tests/phpunit/data/" "$WP_TESTS_DIR/data"
	fi

	if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
		download https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php "$WP_TESTS_DIR/wp-tests-config.php"
		# Point the config at our core + DB.
		sed -i "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}/':" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s/youremptytestdbnamehere/${DB_NAME}/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s/yourusernamehere/${DB_USER}/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s/yourpasswordhere/${DB_PASS}/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
	fi
}

create_db() {
	if [ "$SKIP_DB_CREATE" = "true" ]; then
		return
	fi
	# The MariaDB client (default-mysql-client) forces TLS to the MySQL 8 `db`
	# service, whose cert is untrusted; --skip-ssl avoids that failure. Using
	# CREATE DATABASE IF NOT EXISTS (rather than `mysqladmin create || echo ok`)
	# means a real connection/permission error surfaces instead of being masked
	# as a false "already exists".
	mysql --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" --protocol=tcp --skip-ssl \
		-e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;"
	echo "Database ${DB_NAME} ready"
}

install_wp
install_test_suite
create_db
echo "Done. WP_TESTS_DIR=${WP_TESTS_DIR}  WP_CORE_DIR=${WP_CORE_DIR}"

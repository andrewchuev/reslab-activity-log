<?php
/**
 * WP PHPUnit test-suite config. Points at a dedicated `reslab_al_wp_test`
 * database (never the site's real `reslab_al_wp` DB) since the test suite
 * installs/truncates tables as part of its bootstrap.
 */

// Credentials are read from the environment (the same MYSQL_* vars the
// "php" container already gets from the stack's .env via docker-compose's
// env_file:) — never hardcoded here, since this file is part of the plugin.
define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'reslab_al_wp_test' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: getenv( 'MYSQL_USER' ) );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: getenv( 'MYSQL_PASSWORD' ) );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: 'db' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Reslab Activity Log Test Suite' );

define( 'WP_PHP_BINARY', 'php' );

define( 'WPLANG', '' );

// Use the real, already-installed WordPress core rather than a wordpress-develop build.
define( 'ABSPATH', '/var/www/html/' );

<?php
/**
 * Load official WordPress parser/filter code only, without wp-load.php, a server,
 * wp-config.php, plugin activation, database credentials or a database connection.
 * The calling test supplies an isolated extracted core through LUNARA_WP_TEST_ROOT.
 */
$wordpress_root = realpath( (string) getenv( 'LUNARA_WP_TEST_ROOT' ) );
if ( ! $wordpress_root || ! is_file( $wordpress_root . '/wp-includes/kses.php' ) ) {
    throw new RuntimeException( 'LUNARA_WP_TEST_ROOT must identify the extracted WordPress fixture.' );
}
define( 'ABSPATH', $wordpress_root . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_DEBUG', false );
// Native get_option short-circuits instead of attempting database/cache access.
define( 'WP_SETUP_CONFIG', true );
foreach ( array( 'compat-utf8.php', 'compat.php', 'load.php', 'plugin.php', 'utf8.php', 'formatting.php', 'functions.php', 'class-wp-error.php', 'class-wp-token-map.php' ) as $core_file ) {
    require_once ABSPATH . WPINC . '/' . $core_file;
}
add_filter( 'pre_option_blog_charset', function () { return 'UTF-8'; } );
foreach ( array( 'html5-named-character-references.php', 'class-wp-html-attribute-token.php', 'class-wp-html-span.php', 'class-wp-html-doctype-info.php', 'class-wp-html-text-replacement.php', 'class-wp-html-decoder.php', 'class-wp-html-tag-processor.php' ) as $core_file ) {
    require_once ABSPATH . WPINC . '/html-api/' . $core_file;
}
require_once ABSPATH . WPINC . '/kses.php';

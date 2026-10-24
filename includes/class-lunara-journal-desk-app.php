<?php
/** Private, same-origin Journal Desk. No theme or extra hosting required. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Lunara_Journal_Desk_App {
    public static function bootstrap() {
        add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_post_lunara_open_journal_desk', array( __CLASS__, 'open_desk' ) );
    }

    public static function url() { return home_url( '/journal-desk/' ); }

    public static function register_menu() {
        add_submenu_page( 'edit.php?post_type=journal', 'Journal Desk', 'Open Journal Desk', 'manage_options', 'lunara-journal-desk', array( __CLASS__, 'menu_page' ) );
    }

    public static function menu_page() {
        echo '<div class="wrap"><h1>Journal Desk</h1><p><a class="button button-primary" href="' . esc_url( self::url() ) . '">Open Journal Desk</a></p><p>Review drafts, refine the voice, and manage Dispatch.</p></div>';
    }

    public static function open_desk() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'You do not have access to Journal Desk.', '', array( 'response' => 403 ) ); }
        wp_safe_redirect( self::url() );
        exit;
    }

    public static function route_kind( $request_path ) {
        $base = wp_parse_url( self::url(), PHP_URL_PATH );
        if ( rtrim( (string) $request_path, '/' ) === rtrim( (string) $base, '/' ) ) { return 'app'; }
        if ( (string) $request_path === trailingslashit( (string) $base ) . 'manifest.webmanifest' ) { return 'manifest'; }
        return '';
    }

    public static function maybe_render() {
        $path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH );
        $kind = self::route_kind( $path );
        if ( '' === $kind ) { return; }
        if ( 'manifest' === $kind ) {
            nocache_headers();
            status_header( 200 );
            header( 'Content-Type: application/manifest+json; charset=utf-8' );
            echo wp_json_encode( array(
                'id' => self::url(), 'name' => 'LUNARA Journal Desk', 'short_name' => 'Journal Desk',
                'start_url' => self::url(), 'scope' => self::url(), 'display' => 'standalone',
                'background_color' => '#0a1520', 'theme_color' => '#0a1520',
                'icons' => array( array( 'src' => plugins_url( 'assets/desk/icon.svg', LUNARA_JOURNAL_FOUNDATION_FILE ), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any' ) ),
            ) );
            exit;
        }
        if ( ! is_user_logged_in() ) { auth_redirect(); exit; }
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Journal Desk is private.', '', array( 'response' => 403 ) ); }
        nocache_headers();
        status_header( 200 );
        header( 'Content-Type: text/html; charset=utf-8' );
        header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
        header( 'Referrer-Policy: same-origin' );
        header( 'X-Content-Type-Options: nosniff' );
        header( "Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' https: data:; connect-src 'self'; font-src 'self'; manifest-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'" );
        $user = wp_get_current_user();
        $bootstrap = array(
            'apiBase' => rest_url( 'lunara/v1/' ), 'nonce' => wp_create_nonce( 'wp_rest' ),
            'siteUrl' => home_url( '/' ), 'deskUrl' => self::url(),
            'settingsUrl' => Lunara_Journal_Control_Plane::admin_url(),
            'loginUrl' => wp_login_url( self::url() ),
            'name' => $user->display_name ? $user->display_name : $user->user_login,
            'version' => LUNARA_JOURNAL_FOUNDATION_VERSION,
            'timezone' => wp_timezone()->getName(),
            'maxUploadBytes' => min( 20 * 1024 * 1024, wp_max_upload_size() ),
        );
        $asset_base = plugins_url( 'assets/desk/', LUNARA_JOURNAL_FOUNDATION_FILE );
        $asset_version = rawurlencode( LUNARA_JOURNAL_FOUNDATION_VERSION );
        ?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0a1520"><meta name="robots" content="noindex,nofollow">
<meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="Journal Desk">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Journal Desk — LUNARA FILM</title>
<link rel="manifest" href="<?php echo esc_url( self::url() . 'manifest.webmanifest' ); ?>">
<link rel="icon" type="image/svg+xml" href="<?php echo esc_url( $asset_base . 'icon.svg' ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( $asset_base . 'desk.css?v=' . $asset_version ); ?>">
</head><body>
<a href="#main" class="skip-link">Skip to workspace</a>
<div id="journal-desk"><main id="main" class="initial-state"><p class="eyebrow">LUNARA FILM</p><h1>Journal Desk</h1><p role="status">Opening your drafts…</p></main></div>
<div id="announcements" class="sr-only" aria-live="polite" aria-atomic="true"></div>
<noscript><p class="initial-state">Enable JavaScript to use Journal Desk, or <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=journal' ) ); ?>">open your Journal in WordPress</a>.</p></noscript>
<script id="desk-config" type="application/json"><?php echo wp_json_encode( $bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
<script src="<?php echo esc_url( $asset_base . 'desk-state.js?v=' . $asset_version ); ?>" defer></script>
<script src="<?php echo esc_url( $asset_base . 'desk.js?v=' . $asset_version ); ?>" defer></script>
</body></html><?php
        exit;
    }
}

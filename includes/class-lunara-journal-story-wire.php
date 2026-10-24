<?php
/** Private, model-free film discovery over Dispatch's canonical pitch store. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Lunara_Journal_Story_Wire {
    const REFRESH_OPTION = 'lunara_journal_story_wire_refresh';
    const LOCK_OPTION = 'lunara_journal_story_wire_lock';
    const LOCK_SECONDS = 300;
    const REFRESH_SECONDS = 120;
    const MAX_AGE_SECONDS = 604800;
    const MAX_SCAN = 500;
    const COVERAGE_LIMIT = 250;

    public static function bootstrap() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    public static function register_rest_routes() {
        register_rest_route( 'lunara/v1', '/journal/app/wire', array(
            'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_list' ),
            'permission_callback' => array( __CLASS__, 'permissions_check' ),
        ) );
        register_rest_route( 'lunara/v1', '/journal/app/wire/refresh', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_refresh' ),
            'permission_callback' => array( __CLASS__, 'permissions_check' ),
        ) );
    }

    public static function permissions_check( WP_REST_Request $request ) {
        return Lunara_Journal_Desk_API::session_permissions_check( $request, 'manage_options' );
    }

    private static function available() {
        // Dispatch loads its feed services lazily; normal REST reads do not run its worker.
        if ( ! class_exists( 'Lunara_Dispatch_Feed_Fetcher' ) && class_exists( 'Lunara_Dispatch_Plugin' )
            && method_exists( 'Lunara_Dispatch_Plugin', 'instance' ) && method_exists( 'Lunara_Dispatch_Plugin', 'ensure_services' ) ) {
            try {
                Lunara_Dispatch_Plugin::instance()->ensure_services();
            } catch ( Throwable $error ) {
                return false;
            }
        }
        return class_exists( 'Lunara_Dispatch_Pitches' ) && method_exists( 'Lunara_Dispatch_Pitches', 'all' )
            && method_exists( 'Lunara_Dispatch_Pitches', 'add_items' ) && method_exists( 'Lunara_Dispatch_Pitches', 'pitch_id' )
            && class_exists( 'Lunara_Dispatch_Feed_Fetcher' ) && method_exists( 'Lunara_Dispatch_Feed_Fetcher', 'fetch_all' )
            && method_exists( 'Lunara_Dispatch_Feed_Fetcher', 'mark_seen' );
    }

    public static function rest_list( WP_REST_Request $request ) {
        $permission = self::permissions_check( $request );
        if ( is_wp_error( $permission ) ) { return $permission; }
        $query = self::query( $request );
        return is_wp_error( $query ) ? $query : self::response( self::snapshot( $query ) );
    }

    private static function query( WP_REST_Request $request ) {
        $query = array();
        foreach ( array( 'search', 'source' ) as $key ) {
            $value = $request->get_param( $key );
            if ( null !== $value && ( ! is_string( $value ) || strlen( $value ) > 200 ) ) {
                return self::error( 'lunara_wire_query', 'Use a short search term and source name.', 400 );
            }
            $query[$key] = sanitize_text_field( (string) $value );
        }
        $limit = $request->get_param( 'limit' );
        if ( null !== $limit && ( ! is_scalar( $limit ) || ! ctype_digit( (string) $limit ) || (int) $limit < 1 ) ) {
            return self::error( 'lunara_wire_query', 'Use a positive story limit.', 400 );
        }
        $query['limit'] = null === $limit ? 36 : min( 60, (int) $limit );
        return $query;
    }

    /** Refresh never calls the Dispatch worker: approved pitches cannot trigger AI here. */
    public static function rest_refresh( WP_REST_Request $request ) {
        $permission = self::permissions_check( $request );
        if ( is_wp_error( $permission ) ) { return $permission; }
        $query = self::query( $request );
        if ( is_wp_error( $query ) ) { return $query; }
        if ( ! self::available() ) {
            return self::error( 'lunara_wire_dispatch_required', 'Activate a compatible Lunara Dispatch plugin to discover film stories.', 503 );
        }
        $owner = wp_json_encode( array( 'owner' => wp_generate_uuid4(), 'expires' => time() + self::LOCK_SECONDS ) );
        if ( ! add_option( self::LOCK_OPTION, $owner, '', false ) ) {
            $existing = get_option( self::LOCK_OPTION, '' );
            $lock = is_string( $existing ) ? json_decode( $existing, true ) : null;
            if ( ! is_array( $lock ) || empty( $lock['expires'] ) || (int) $lock['expires'] >= time()
                || ! self::delete_lock( $existing ) || ! add_option( self::LOCK_OPTION, $owner, '', false ) ) {
                return self::error( 'lunara_wire_busy', 'Film sources are already refreshing. Try again shortly.', 409 );
            }
        }
        try {
            $state = get_option( self::REFRESH_OPTION, array() );
            $state = is_array( $state ) ? $state : array();
            $retry = self::REFRESH_SECONDS - ( time() - (int) ( $state['attempted_at'] ?? 0 ) );
            if ( $retry > 0 ) {
                $payload = self::snapshot( $query );
                $payload['refresh'] = array( 'added' => 0, 'skipped' => 0, 'errors' => array(), 'throttled' => true, 'retry_after' => $retry );
                return self::response( $payload );
            }
            $state['attempted_at'] = time();
            update_option( self::REFRESH_OPTION, $state, false );
            $fetcher = new Lunara_Dispatch_Feed_Fetcher();
            $fetched = $fetcher->fetch_all();
            if ( ! is_array( $fetched ) || ! isset( $fetched['items'] ) || ! is_array( $fetched['items'] ) ) {
                return self::error( 'lunara_wire_fetch_failed', 'Film source discovery returned an invalid response.', 502 );
            }
            $eligible = array();
            $skipped = 0;
            foreach ( array_slice( $fetched['items'], 0, 60 ) as $item ) {
                if ( ! is_array( $item ) || ! self::eligible( $item ) ) { $skipped++; continue; }
                $eligible[] = $item;
            }
            $added = Lunara_Dispatch_Pitches::add_items( $eligible );
            // The store has its own batch cap. Read it back before acknowledging any source.
            $stored = array();
            foreach ( Lunara_Dispatch_Pitches::all() as $pitch ) {
                if ( ! empty( $pitch['id'] ) ) { $stored[(string) $pitch['id']] = true; }
            }
            $seen = array();
            foreach ( $eligible as $item ) {
                if ( isset( $stored[Lunara_Dispatch_Pitches::pitch_id( $item )] ) && empty( $item['automation_signal_id'] ) ) { $seen[] = $item; }
            }
            if ( $seen ) { $fetcher->mark_seen( $seen ); }
            $errors = array();
            foreach ( array_slice( (array) ( $fetched['errors'] ?? array() ), 0, 12, true ) as $source => $message ) {
                $errors[] = array( 'source' => self::display_text( $source, 120 ), 'message' => self::display_text( $message, 240 ) );
            }
            $state['refreshed_at'] = gmdate( 'c' );
            $state['errors'] = $errors;
            update_option( self::REFRESH_OPTION, $state, false );
            $payload = self::snapshot( $query );
            $payload['refresh'] = array( 'added' => (int) $added, 'skipped' => $skipped + (int) ( $fetched['skipped_duplicates'] ?? 0 ), 'errors' => $errors, 'throttled' => false, 'retry_after' => self::REFRESH_SECONDS );
            return self::response( $payload );
        } catch ( Throwable $error ) {
            return self::error( 'lunara_wire_fetch_failed', 'Film sources could not be refreshed. Try again shortly.', 502 );
        } finally {
            self::delete_lock( $owner );
        }
    }

    private static function snapshot( array $query ) {
        $ready = self::available();
        $state = get_option( self::REFRESH_OPTION, array() );
        $state = is_array( $state ) ? $state : array();
        $payload = array(
            'stories' => array(), 'sources' => array(),
            'counts' => array( 'total' => 0, 'matched' => 0, 'returned' => 0, 'covered' => 0, 'filtered' => 0, 'undated' => 0 ),
            'refreshed_at' => (string) ( $state['refreshed_at'] ?? '' ),
            'availability' => array( 'ready' => $ready, 'can_refresh' => $ready, 'message' => $ready ? '' : 'Activate a compatible Lunara Dispatch plugin to discover film stories.' ),
            'inventory_truncated' => false, 'coverage_window' => self::COVERAGE_LIMIT,
        );
        if ( ! $ready ) { return $payload; }
        $all = array_reverse( Lunara_Dispatch_Pitches::all() );
        $payload['inventory_truncated'] = count( $all ) > self::MAX_SCAN;
        $coverage = self::coverage();
        $sources = array();
        $stories = array();
        foreach ( array_slice( $all, 0, self::MAX_SCAN ) as $pitch ) {
            if ( 'pending' !== ( $pitch['status'] ?? '' ) ) { continue; }
            $item = isset( $pitch['item'] ) && is_array( $pitch['item'] ) ? $pitch['item'] : array();
            if ( ! self::eligible( $item ) ) { $payload['counts']['filtered']++; continue; }
            $source = self::display_text( $item['source_label'] ?? '', 120 );
            $source_id = sanitize_title( $source );
            if ( ! isset( $sources[$source] ) ) { $sources[$source] = array( 'id' => $source_id, 'label' => $source, 'count' => 0 ); }
            $sources[$source]['count']++;
            $match = $coverage[self::canonical_url( $item['url'] )] ?? array();
            $story = array(
                'id' => self::text( $pitch['id'] ?? '', 80 ), 'title' => self::display_text( $item['title'], 250 ),
                'url' => self::safe_url( $item['url'] ), 'source' => $source,
                'summary' => self::display_text( $item['description'] ?? '', 900 ),
                'image_url' => empty( $item['image_blocked'] ) ? self::safe_url( $item['image_url'] ?? '' ) : '',
                'published_at' => self::published_at( $item ), 'status' => 'pending',
                'covered' => ! empty( $match ), 'coverage_url' => (string) ( $match['url'] ?? '' ),
                'coverage_status' => (string) ( $match['status'] ?? '' ),
            );
            $payload['counts']['total']++;
            if ( $story['covered'] ) { $payload['counts']['covered']++; }
            if ( '' === $story['published_at'] ) { $payload['counts']['undated']++; }
            if ( '' !== $query['source'] && $query['source'] !== $source && $query['source'] !== $source_id ) { continue; }
            if ( '' !== $query['search'] && false === stripos( $story['title'] . ' ' . $story['summary'] . ' ' . $source, $query['search'] ) ) { continue; }
            $stories[] = $story;
        }
        usort( $stories, static function ( $a, $b ) { return strcmp( $b['published_at'], $a['published_at'] ); } );
        $payload['stories'] = array_slice( $stories, 0, $query['limit'] );
        $payload['counts']['matched'] = count( $stories );
        $payload['counts']['returned'] = count( $payload['stories'] );
        $payload['sources'] = array_values( $sources );
        usort( $payload['sources'], static function ( $a, $b ) { return strcasecmp( $a['label'], $b['label'] ); } );
        return $payload;
    }

    /** Reject explicit TV stories without discarding film adaptations or an actor's TV history. */
    private static function eligible( array $item ) {
        if ( empty( $item['title'] ) || empty( $item['url'] ) || '' === self::safe_url( $item['url'] ) ) { return false; }
        $headline = self::display_text( $item['title'], 500 ) . ' ' . str_replace( array( '-', '_' ), ' ', (string) $item['url'] );
        $description = self::display_text( $item['description'] ?? '', 1800 );
        $text = $headline . ' ' . $description;
        $tv = '/\b(?:television|tv|showrunner|sitcom|miniseries|mini series|docuseries|limited series|animated series|streaming series|series finale|season finale|new series|tv series|episodes?|season\s+\d+|season\s+(?:one|two|three|four|five|six)|(?:first|second|third|fourth|fifth|final)\s+season|emmys?|reality show)\b/i';
        if ( preg_match( $tv, $headline ) || preg_match( '/\b(?:short films?|short documentary|short subject|music videos?|music album|concert tour)\b/i', $headline ) ) { return false; }
        $explicit_feature = '/\b(?:films?|movies?|cinema|theatrical|feature length|feature debut|feature directorial|motion picture)\b/i';
        $feature_context = preg_match( $explicit_feature, $headline ) || preg_match( '/\b(?:new film|feature film|theatrical film|new movie|movie adaptation|film adaptation|feature debut|feature directorial|motion picture)\b/i', $description );
        if ( ! $feature_context && preg_match( $tv, $description ) ) { return false; }
        if ( ! preg_match( '/\b(?:films?|movies?|cinema|cinematic|filmmaker|screenplay|cinematograph\w*|box office|oscars?|academy awards|feature length|documentar(?:y|ies)|film festival|theatrical|filmography)\b/i', $text ) ) { return false; }
        $date = self::published_at( $item );
        if ( '' !== $date ) {
            $timestamp = strtotime( $date );
            if ( $timestamp < time() - self::MAX_AGE_SECONDS || $timestamp > time() + 86400 ) { return false; }
        }
        return true;
    }

    private static function published_at( array $item ) {
        if ( empty( $item['published_at'] ) || ! is_scalar( $item['published_at'] ) ) { return ''; }
        $raw = trim( (string) $item['published_at'] );
        // Do not let strtotime's relative-date grammar invent a source timestamp.
        if ( ! preg_match( '/\b(?:19|20)\d{2}\b/', $raw ) ) { return ''; }
        $timestamp = strtotime( $raw );
        return false === $timestamp ? '' : gmdate( 'c', $timestamp );
    }

    /** Bound the coverage scan; compare URLs, never fuzzy headlines. */
    private static function coverage() {
        $ids = get_posts( array( 'post_type' => 'journal', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'posts_per_page' => self::COVERAGE_LIMIT, 'fields' => 'ids', 'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true ) );
        if ( function_exists( 'update_meta_cache' ) ) { update_meta_cache( 'post', $ids ); }
        $out = array();
        foreach ( $ids as $id ) {
            $status = get_post_status( $id );
            $urls = array();
            foreach ( (array) get_post_meta( $id ) as $key => $values ) {
                if ( 'journal_source_items' === $key || preg_match( '/^journal_source_items_\d+_source_url$/', $key ) || in_array( $key, array( '_lunara_dispatch_source_url', 'journal_source_url' ), true ) ) {
                    self::collect_urls( $values, $urls );
                }
            }
            foreach ( $urls as $url ) {
                $key = self::canonical_url( $url );
                if ( '' !== $key && ( ! isset( $out[$key] ) || 'publish' === $status ) ) {
                    $out[$key] = array( 'url' => get_permalink( $id ), 'status' => $status );
                }
            }
        }
        return $out;
    }

    private static function collect_urls( $value, array &$urls, $depth = 0 ) {
        if ( $depth > 5 ) { return; }
        if ( is_string( $value ) && function_exists( 'maybe_unserialize' ) ) { $value = maybe_unserialize( $value ); }
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $child ) {
                if ( is_int( $key ) || 'source_url' === $key || 'url' === $key ) { self::collect_urls( $child, $urls, $depth + 1 ); }
            }
        } elseif ( is_string( $value ) && '' !== self::safe_url( $value ) ) { $urls[] = $value; }
    }

    private static function safe_url( $url ) {
        if ( ! is_string( $url ) ) { return ''; }
        $url = esc_url_raw( $url, array( 'https' ) );
        $parts = wp_parse_url( $url );
        return is_array( $parts ) && 'https' === ( $parts['scheme'] ?? '' ) && ! empty( $parts['host'] ) && empty( $parts['user'] ) && empty( $parts['pass'] ) ? $url : '';
    }

    private static function canonical_url( $url ) {
        $url = self::safe_url( $url );
        if ( '' === $url ) { return ''; }
        $parts = wp_parse_url( $url );
        $query = array();
        parse_str( $parts['query'] ?? '', $query );
        foreach ( array_keys( $query ) as $key ) {
            if ( preg_match( '/^(?:utm_|fbclid$|gclid$|mc_cid$|mc_eid$)/i', $key ) ) { unset( $query[$key] ); }
        }
        ksort( $query );
        $port = ! empty( $parts['port'] ) && 443 !== (int) $parts['port'] ? ':' . (int) $parts['port'] : '';
        return preg_replace( '/^www\./', '', strtolower( $parts['host'] ) ) . $port . rtrim( $parts['path'] ?? '/', '/' ) . ( $query ? '?' . http_build_query( $query ) : '' );
    }

    /** Decode feed presentation only; the canonical pitch and its identifier stay untouched. */
    private static function display_text( $value, $limit ) {
        $text = html_entity_decode( is_scalar( $value ) ? (string) $value : '', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        // Strip after decoding so encoded markup cannot become presentation HTML.
        $text = sanitize_text_field( $text );
        $collapsed = preg_replace( '/[\s\p{Z}]+/u', ' ', $text );
        return self::text( null === $collapsed ? $text : trim( $collapsed ), $limit );
    }

    private static function text( $value, $limit ) {
        $text = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
        if ( function_exists( 'mb_substr' ) ) { return mb_substr( $text, 0, $limit, 'UTF-8' ); }
        // PHP installations without mbstring must not split a decoded Unicode character.
        return preg_match( '/^.{0,' . (int) $limit . '}/us', $text, $match ) ? $match[0] : substr( $text, 0, $limit );
    }

    private static function delete_lock( $owner ) {
        global $wpdb;
        if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->options ) ) {
            $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $owner ) );
            if ( $deleted ) { wp_cache_delete( self::LOCK_OPTION, 'options' ); wp_cache_delete( 'notoptions', 'options' ); }
            return (bool) $deleted;
        }
        return get_option( self::LOCK_OPTION, '' ) === $owner && delete_option( self::LOCK_OPTION );
    }

    private static function response( array $payload ) {
        $response = rest_ensure_response( $payload );
        $response->header( 'Cache-Control', 'private, no-store, max-age=0' );
        return $response;
    }

    private static function error( $code, $message, $status ) {
        return new WP_Error( $code, $message, array( 'status' => $status ) );
    }
}

<?php
/** Run: php tests/story-wire-runtime.php */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['wire_options'] = array();
$GLOBALS['wire_routes'] = array();
$GLOBALS['wire_logged_in'] = true;
$GLOBALS['wire_caps'] = array( 'manage_options' => true );
$GLOBALS['wire_fetches'] = 0;
$GLOBALS['wire_service_initializations'] = 0;
$GLOBALS['wire_worker_runs'] = 0;
$GLOBALS['wire_marked'] = array();
$GLOBALS['wire_fetch_result'] = array( 'items' => array(), 'errors' => array(), 'skipped_duplicates' => 0 );
$GLOBALS['wire_meta'] = array();
$GLOBALS['wire_posts'] = array();

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
class WP_REST_Request {
    private $params = array(); private $headers = array();
    public function get_param( $key ) { return $this->params[$key] ?? null; }
    public function set_param( $key, $value ) { $this->params[$key] = $value; }
    public function get_header( $key ) { return $this->headers[strtolower( $key )] ?? ''; }
    public function set_header( $key, $value ) { $this->headers[strtolower( $key )] = $value; }
}
class WP_REST_Response {
    private $data; public $headers = array();
    public function __construct( $data ) { $this->data = $data; }
    public function get_data() { return $this->data; }
    public function header( $key, $value ) { $this->headers[$key] = $value; }
}
function wire_check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function rest_ensure_response( $value ) { return new WP_REST_Response( $value ); }
function add_action( $hook, $callback ) {}
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['wire_routes'][$route] = $args; }
function is_user_logged_in() { return $GLOBALS['wire_logged_in']; }
function wp_get_session_token() { return 'session'; }
function wp_validate_auth_cookie( $cookie, $scheme ) { return 7; }
function get_current_user_id() { return 7; }
function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce && 'wp_rest' === $action; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['wire_caps'][$cap] ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' ); }
function esc_url_raw( $value, $protocols = null ) { return is_string( $value ) ? trim( $value ) : ''; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_generate_uuid4() { static $n = 0; return 'owner-' . ++$n; }
function get_option( $key, $default = false ) { return $GLOBALS['wire_options'][$key] ?? $default; }
function add_option( $key, $value, $unused = '', $autoload = false ) { if ( isset( $GLOBALS['wire_options'][$key] ) ) { return false; } $GLOBALS['wire_options'][$key] = $value; return true; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['wire_options'][$key] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['wire_options'][$key] ); return true; }
function get_posts( $args ) { $GLOBALS['wire_coverage_query'] = $args; return array_slice( $GLOBALS['wire_posts'], 0, $args['posts_per_page'] ); }
function get_post_meta( $id ) { return $GLOBALS['wire_meta'][$id] ?? array(); }
function get_post_status( $id ) { return 42 === $id ? 'publish' : 'draft'; }
function get_permalink( $id ) { return 'https://lunarafilm.com/journal/' . $id . '/'; }
function maybe_unserialize( $value ) { $result = @unserialize( $value ); return false === $result ? $value : $result; }

require dirname( __DIR__ ) . '/includes/class-lunara-journal-desk-api.php';
require dirname( __DIR__ ) . '/includes/class-lunara-journal-story-wire.php';

function wire_request( array $params = array() ) {
    $request = new WP_REST_Request();
    $request->set_header( 'x-wp-nonce', 'valid' );
    foreach ( $params as $key => $value ) { $request->set_param( $key, $value ); }
    return $request;
}
function wire_story( $title, $url, $description = 'A feature film arrives in cinemas.', $date = null ) {
    return array( 'title' => $title, 'url' => $url, 'description' => $description, 'source_label' => 'The Film Stage', 'published_at' => null === $date ? gmdate( 'c', time() - 3600 ) : $date, 'image_url' => 'https://images.example/still.jpg', 'fingerprint' => md5( $url ) );
}
function wire_pitch( $item, $status = 'pending' ) { return array( 'id' => Lunara_Dispatch_Pitches::pitch_id( $item ), 'status' => $status, 'item' => $item ); }

Lunara_Journal_Story_Wire::register_rest_routes();
wire_check( isset( $GLOBALS['wire_routes']['/journal/app/wire/refresh'] ), 'refresh route not registered' );
$missing = Lunara_Journal_Story_Wire::rest_list( wire_request() )->get_data();
wire_check( false === $missing['availability']['ready'] && '' !== $missing['availability']['message'], 'missing dependency was silent' );
wire_check( 'lunara_wire_dispatch_required' === Lunara_Journal_Story_Wire::rest_refresh( wire_request() )->get_error_code(), 'missing dependency allowed refresh' );

function wire_load_dispatch() {
    class Lunara_Dispatch_Pitches {
        public static $records = array();
        public static function all() { return self::$records; }
        public static function pitch_id( array $item ) { return 'p_' . substr( hash( 'sha256', $item['fingerprint'] ?? $item['url'] ), 0, 16 ); }
        public static function add_items( array $items ) {
            $known = array_column( self::$records, 'id' ); $added = 0;
            foreach ( array_slice( $items, 0, 2 ) as $item ) {
                if ( ! in_array( self::pitch_id( $item ), $known, true ) ) { self::$records[] = wire_pitch( $item ); $added++; }
            }
            return $added;
        }
    }
    class Lunara_Dispatch_Plugin {
        public static function instance() { return new self(); }
        public function ensure_services() {
            $GLOBALS['wire_service_initializations']++;
            if ( ! empty( $GLOBALS['wire_service_throw'] ) ) { throw new RuntimeException( 'secret service diagnostics' ); }
            wire_load_feed_fetcher();
        }
        public function run() { $GLOBALS['wire_worker_runs']++; throw new RuntimeException( 'Paid worker must never be called by discovery.' ); }
        public function queue_manual_run() { $GLOBALS['wire_worker_runs']++; throw new RuntimeException( 'Paid worker must never be queued by discovery.' ); }
    }
}
function wire_load_feed_fetcher() {
    class Lunara_Dispatch_Feed_Fetcher {
        public function fetch_all() {
            $GLOBALS['wire_fetches']++;
            if ( ! empty( $GLOBALS['wire_throw'] ) ) { throw new RuntimeException( 'secret upstream diagnostics' ); }
            if ( ! empty( $GLOBALS['wire_replace_lock'] ) ) { $GLOBALS['wire_options'][Lunara_Journal_Story_Wire::LOCK_OPTION] = 'another-owner'; }
            return $GLOBALS['wire_fetch_result'];
        }
        public function mark_seen( array $items ) { $GLOBALS['wire_marked'] = $items; }
    }
}
wire_load_dispatch();
wire_check( ! class_exists( 'Lunara_Dispatch_Feed_Fetcher' ), 'fixture did not preserve Dispatch lazy loading' );

$GLOBALS['wire_logged_in'] = false;
wire_check( is_wp_error( Lunara_Journal_Story_Wire::rest_list( wire_request() ) ), 'anonymous reader accepted' );
$GLOBALS['wire_logged_in'] = true;
$request = wire_request(); $request->set_header( 'authorization', 'Bearer token' );
wire_check( is_wp_error( Lunara_Journal_Story_Wire::rest_refresh( $request ) ), 'bridge credentials granted app authority' );
$request = new WP_REST_Request();
wire_check( is_wp_error( Lunara_Journal_Story_Wire::rest_list( $request ) ), 'missing nonce accepted' );
$GLOBALS['wire_caps']['manage_options'] = false;
wire_check( is_wp_error( Lunara_Journal_Story_Wire::rest_refresh( wire_request() ) ), 'nonadministrator refreshed sources' );
$GLOBALS['wire_caps']['manage_options'] = true;
wire_check( 0 === $GLOBALS['wire_fetches'], 'permission failure fetched feeds' );
wire_check( 0 === $GLOBALS['wire_service_initializations'] && ! class_exists( 'Lunara_Dispatch_Feed_Fetcher' ), 'permission failure initialized Dispatch services' );

$GLOBALS['wire_service_throw'] = true;
$failed_boot = Lunara_Journal_Story_Wire::rest_list( wire_request() )->get_data();
wire_check( false === $failed_boot['availability']['ready'] && 1 === $GLOBALS['wire_service_initializations'], 'service initialization failure escaped or reported ready' );
wire_check( ! class_exists( 'Lunara_Dispatch_Feed_Fetcher' ) && 0 === $GLOBALS['wire_fetches'] && 0 === $GLOBALS['wire_worker_runs'], 'failed initialization fetched feeds or ran the worker' );
$GLOBALS['wire_service_throw'] = false;
$ready = Lunara_Journal_Story_Wire::rest_list( wire_request() )->get_data();
wire_check( true === $ready['availability']['ready'] && true === $ready['availability']['can_refresh'] && 2 === $GLOBALS['wire_service_initializations'], 'active Dispatch lazy services were not initialized on authorized read' );
wire_check( 0 === $GLOBALS['wire_fetches'] && 0 === $GLOBALS['wire_worker_runs'], 'availability read fetched feeds or invoked the paid worker' );
Lunara_Journal_Story_Wire::rest_list( wire_request() );
wire_check( 2 === $GLOBALS['wire_service_initializations'], 'already loaded Dispatch services initialized again' );

$movie = wire_story( 'A director makes a new film', 'https://www.example.com/new-film/?utm_source=rss#top' );
$undated = wire_story( 'Another feature film', 'https://example.com/undated-film', 'A film without a source date.', '' );
$tv = wire_story( 'A film star joins season 2', 'https://example.com/show-season-2' );
$old = wire_story( 'Old film news', 'https://example.com/old-film', 'A theatrical film.', gmdate( 'c', time() - 8 * 86400 ) );
$music = wire_story( 'A musician announces a tour', 'https://example.com/concert', 'New music album and concert tour.' );
$bad_url = wire_story( 'Unsafe film link', 'javascript:alert(1)' );
$adaptation = wire_story( 'A video game becomes a film', 'https://example.com/game-adaptation', 'The movie adaptation begins production.' );
$history = wire_story( 'A filmmaker chooses the next lead', 'https://example.com/casting', 'The new film stars an actor known for a television series and season 2 of another show.' );
Lunara_Dispatch_Pitches::$records = array( wire_pitch( $movie ), wire_pitch( $undated ), wire_pitch( $tv ), wire_pitch( $old ), wire_pitch( $music ), wire_pitch( $bad_url ), wire_pitch( $movie, 'approved' ), wire_pitch( $adaptation ), wire_pitch( $history ) );
$GLOBALS['wire_posts'] = array( 41, 42 );
$GLOBALS['wire_meta'][41] = array( 'journal_source_items' => array( serialize( array( array( 'source_url' => 'https://example.com/new-film/' ) ) ) ) );
$GLOBALS['wire_meta'][42] = array( 'journal_source_items_0_source_url' => array( 'https://example.com/new-film?fbclid=track' ) );
$read_response = Lunara_Journal_Story_Wire::rest_list( wire_request() );
$read = $read_response->get_data();
wire_check( 4 === count( $read['stories'] ) && 4 === $read['counts']['filtered'], 'feature/freshness/status filters failed or feature context was overfiltered' );
$covered_stories = array_values( array_filter( $read['stories'], static function ( $story ) { return $story['covered']; } ) );
wire_check( 1 === count( $covered_stories ) && 'publish' === $covered_stories[0]['coverage_status'], 'canonical source matching or publish preference failed' );
wire_check( 1 === $read['counts']['undated'], 'unknown source date was invented' );
wire_check( 'private, no-store, max-age=0' === $read_response->headers['Cache-Control'], 'private results can be cached' );
wire_check( 250 === $GLOBALS['wire_coverage_query']['posts_per_page'], 'coverage lookup is unbounded' );
wire_check( 1 === count( Lunara_Journal_Story_Wire::rest_list( wire_request( array( 'search' => 'without a source date', 'source' => 'the-film-stage' ) ) )->get_data()['stories'] ), 'search/source filtering failed' );
wire_check( is_wp_error( Lunara_Journal_Story_Wire::rest_list( wire_request( array( 'search' => array( 'bad' ) ) ) ) ), 'malformed query accepted' );
wire_check( 1 === count( Lunara_Journal_Story_Wire::rest_list( wire_request( array( 'limit' => 1 ) ) )->get_data()['stories'] ), 'limit ignored' );

Lunara_Dispatch_Pitches::$records = array( wire_pitch( $movie, 'approved' ) );
$fresh1 = wire_story( 'Fresh film one', 'https://example.com/fresh-film-1' );
$fresh2 = wire_story( 'Fresh film two', 'https://example.com/fresh-film-2' );
$fresh3 = wire_story( 'Fresh film three', 'https://example.com/fresh-film-3' );
$GLOBALS['wire_fetch_result'] = array( 'items' => array( $tv, $fresh1, $fresh2, $fresh3 ), 'skipped_duplicates' => 3, 'errors' => array( 'One feed' => 'Unavailable.' ) );
$refresh = Lunara_Journal_Story_Wire::rest_refresh( wire_request() )->get_data();
wire_check( 1 === $GLOBALS['wire_fetches'] && 2 === $refresh['refresh']['added'], 'model-free refresh did not store eligible stories' );
wire_check( 2 === count( $GLOBALS['wire_marked'] ) && $fresh2['url'] === $GLOBALS['wire_marked'][1]['url'], 'unstored or excluded stories marked seen' );
wire_check( 'approved' === Lunara_Dispatch_Pitches::$records[0]['status'], 'refresh changed an existing approval' );
wire_check( 4 === $refresh['refresh']['skipped'] && 1 === count( $refresh['refresh']['errors'] ), 'refresh outcomes incomplete' );
wire_check( '' !== $refresh['refreshed_at'] && ! isset( $GLOBALS['wire_options'][Lunara_Journal_Story_Wire::LOCK_OPTION] ), 'refresh timestamp or lock release failed' );
$throttled = Lunara_Journal_Story_Wire::rest_refresh( wire_request() )->get_data();
wire_check( true === $throttled['refresh']['throttled'] && 1 === $GLOBALS['wire_fetches'], 'repeated refresh bypassed throttle' );

$GLOBALS['wire_options'][Lunara_Journal_Story_Wire::LOCK_OPTION] = json_encode( array( 'owner' => 'other', 'expires' => time() + 100 ) );
wire_check( 'lunara_wire_busy' === Lunara_Journal_Story_Wire::rest_refresh( wire_request() )->get_error_code(), 'active refresh lock ignored' );
$GLOBALS['wire_options'][Lunara_Journal_Story_Wire::LOCK_OPTION] = json_encode( array( 'owner' => 'expired', 'expires' => time() - 1 ) );
delete_option( Lunara_Journal_Story_Wire::REFRESH_OPTION );
$GLOBALS['wire_throw'] = true;
wire_check( 'lunara_wire_fetch_failed' === Lunara_Journal_Story_Wire::rest_refresh( wire_request() )->get_error_code(), 'fetch exception escaped' );
wire_check( ! isset( $GLOBALS['wire_options'][Lunara_Journal_Story_Wire::LOCK_OPTION] ), 'failed refresh retained owned lock' );
$GLOBALS['wire_throw'] = false;
delete_option( Lunara_Journal_Story_Wire::REFRESH_OPTION );
$GLOBALS['wire_replace_lock'] = true;
Lunara_Journal_Story_Wire::rest_refresh( wire_request() );
wire_check( 'another-owner' === get_option( Lunara_Journal_Story_Wire::LOCK_OPTION ), 'refresh deleted another worker lock' );
wire_check( 0 === $GLOBALS['wire_worker_runs'], 'discovery invoked or queued the paid worker' );

echo "Story Wire behavior checks passed.\n";

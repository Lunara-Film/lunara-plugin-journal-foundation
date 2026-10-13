<?php
/**
 * Minimal WordPress behavioral harness for critical Foundation transitions.
 * Run: php tests/wp-behavior-contract.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'LUNARA_NOTION_TOKEN', 'constant-token' );

$GLOBALS['wp_filters'] = array();
$GLOBALS['wp_options'] = array(
    'lunara_journal_notion_page_id' => 'page-id',
    'lunara_journal_control_plane_active_version' => 1,
    'lunara_journal_control_plane_versions' => array(
        array(
            'id' => 1,
            'config' => array(
                'config_version' => '1.0.0',
                'dispatch' => array(
                    'enabled' => true,
                    'schedule' => 'daily',
                    'target_post_type' => 'journal',
                    'post_status' => 'draft',
                    'provider' => 'openai',
                    'max_tokens' => 4096,
                    'models' => array( 'openai' => 'test-model', 'claude' => '', 'gemini' => '', 'grok' => '' ),
                ),
                'chatgpt' => array( 'may_publish' => true ),
            ),
        ),
    ),
);
$GLOBALS['wp_posts'] = array();
$GLOBALS['wp_meta'] = array();
$GLOBALS['wp_terms'] = array();
$GLOBALS['wp_object_terms'] = array();
$GLOBALS['wp_next_post_id'] = 100;
$GLOBALS['suppress_publish'] = false;
$GLOBALS['fail_term_assignment'] = false;

class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct( $code = '', $message = '', $data = null ) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

class WP_Post {
    public $ID = 0;
    public $post_type = 'post';
    public $post_status = 'draft';
    public $post_title = '';
    public $post_content = '';
    public $post_excerpt = '';
    public $post_name = '';
    public $post_date = '2026-07-12 00:00:00';
    public $post_modified = '2026-07-12 00:00:00';
    public $post_mime_type = '';
    public function __construct( array $data = array() ) {
        foreach ( $data as $key => $value ) { $this->$key = $value; }
    }
}

class WP_REST_Request implements ArrayAccess {
    private $params;
    private $json;
    private $headers = array();
    private $route = '';
    private $method = 'POST';
    public function __construct( array $params = array(), array $json = array(), $route = '', $method = 'POST' ) {
        $this->params = $params;
        $this->json = $json;
        $this->route = (string) $route;
        $this->method = strtoupper( (string) $method );
    }
    public function get_json_params() { return $this->json; }
    public function get_param( $key ) { return $this->params[ $key ] ?? null; }
    public function set_param( $key, $value ) { $this->params[ $key ] = $value; }
    public function set_header( $key, $value ) { $this->headers[ strtolower( (string) $key ) ] = $value; }
    public function get_header( $key ) { return $this->headers[ strtolower( (string) $key ) ] ?? ''; }
    public function get_route() { return $this->route; }
    public function get_method() { return $this->method; }
    public function offsetExists( $offset ): bool { return isset( $this->params[ $offset ] ); }
    #[\ReturnTypeWillChange]
    public function offsetGet( $offset ) { return $this->params[ $offset ] ?? null; }
    public function offsetSet( $offset, $value ): void { $this->params[ $offset ] = $value; }
    public function offsetUnset( $offset ): void { unset( $this->params[ $offset ] ); }
}

class WPDB_Stub {
    public $options = 'wp_options';
    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        $name = $where['option_name'];
        if ( ! array_key_exists( $name, $GLOBALS['wp_options'] ) || maybe_serialize( $GLOBALS['wp_options'][ $name ] ) !== $where['option_value'] ) {
            return 0;
        }
        $GLOBALS['wp_options'][ $name ] = maybe_unserialize( $data['option_value'] );
        return 1;
    }
    public function delete( $table, $where, $where_format = null ) {
        $name = $where['option_name'];
        if ( ! array_key_exists( $name, $GLOBALS['wp_options'] ) || maybe_serialize( $GLOBALS['wp_options'][ $name ] ) !== $where['option_value'] ) {
            return 0;
        }
        unset( $GLOBALS['wp_options'][ $name ] );
        return 1;
    }
}
$GLOBALS['wpdb'] = new WPDB_Stub();

function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) { return add_filter( $tag, $callback, $priority, $accepted_args ); }
function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['wp_filters'][ $tag ][ $priority ][] = array( $callback, $accepted_args );
    return true;
}
function apply_filters( $tag, $value ) {
    $args = func_get_args();
    array_shift( $args );
    if ( empty( $GLOBALS['wp_filters'][ $tag ] ) ) { return $value; }
    ksort( $GLOBALS['wp_filters'][ $tag ] );
    foreach ( $GLOBALS['wp_filters'][ $tag ] as $callbacks ) {
        foreach ( $callbacks as $entry ) {
            $args[0] = call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
        }
    }
    return $args[0];
}
function do_action() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
function plugin_dir_path( $file ) { return dirname( $file ) . DIRECTORY_SEPARATOR; }

function maybe_serialize( $value ) { return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value; }
function maybe_unserialize( $value ) { $decoded = @unserialize( $value ); return false === $decoded && 'b:0;' !== $value ? $value : $decoded; }
function wp_cache_delete() { return true; }

function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['wp_options'] ) ? $GLOBALS['wp_options'][ $key ] : $default; }
function update_option( $key, $value ) { $GLOBALS['wp_options'][ $key ] = $value; return true; }
function add_option( $key, $value ) { if ( array_key_exists( $key, $GLOBALS['wp_options'] ) ) { return false; } $GLOBALS['wp_options'][ $key ] = $value; return true; }
function delete_option( $key ) { if ( ! array_key_exists( $key, $GLOBALS['wp_options'] ) ) { return false; } unset( $GLOBALS['wp_options'][ $key ] ); return true; }
function get_transient() { return false; }
function set_transient() { return true; }
function delete_transient() { return true; }

function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $value ) ), '-' ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_kses_allowed_html() { return array(); }
function wp_kses( $value ) { return (string) $value; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $value ) { return filter_var( (string) $value, FILTER_SANITIZE_URL ); }
function wp_slash( $value ) { return $value; }
function wp_unslash( $value ) { return $value; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function absint( $value ) { return abs( (int) $value ); }
function current_time( $type, $gmt = false ) { return '2026-07-12 12:00:00'; }
function wp_timezone() { return new DateTimeZone( 'America/Chicago' ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_bloginfo() { return 'UTF-8'; }
function wp_hash( $value ) { return hash( 'sha256', (string) $value ); }
function wp_generate_password( $length = 12 ) { return str_repeat( 'x', $length ); }
function wp_hash_password( $value ) { return password_hash( $value, PASSWORD_DEFAULT ); }
function wp_check_password( $value, $hash ) { return password_verify( $value, $hash ); }
function is_user_logged_in() { return false; }
function current_user_can() { return true; }
function wp_get_current_user() { return (object) array( 'ID' => 1, 'display_name' => 'Tester', 'user_login' => 'tester' ); }
function get_current_user_id() { return 1; }

function wp_insert_post( $data, $return_error = false ) {
    $id = ++$GLOBALS['wp_next_post_id'];
    $data['ID'] = $id;
    $GLOBALS['wp_posts'][ $id ] = new WP_Post( $data );
    return $id;
}
function wp_update_post( $data, $return_error = false ) {
    $id = (int) $data['ID'];
    if ( empty( $GLOBALS['wp_posts'][ $id ] ) ) { return new WP_Error( 'missing_post', 'Missing post.' ); }
    if ( ! empty( $GLOBALS['suppress_publish'] ) && isset( $data['post_status'] ) && 'publish' === $data['post_status'] ) {
        unset( $data['post_status'] );
    }
    foreach ( $data as $key => $value ) {
        if ( 'ID' !== $key ) { $GLOBALS['wp_posts'][ $id ]->$key = $value; }
    }
    return $id;
}
function get_post( $post ) { $id = $post instanceof WP_Post ? $post->ID : (int) $post; return $GLOBALS['wp_posts'][ $id ] ?? null; }
function get_post_status( $post ) { $post = get_post( $post ); return $post ? $post->post_status : false; }
function clean_post_cache() {}
function get_posts( $args ) {
    $ids = array();
    foreach ( $GLOBALS['wp_posts'] as $id => $post ) {
        if ( isset( $args['post_type'] ) && $post->post_type !== $args['post_type'] ) { continue; }
        if ( isset( $args['post_status'] ) && 'any' !== $args['post_status'] && ! in_array( $post->post_status, (array) $args['post_status'], true ) ) { continue; }
        if ( isset( $args['meta_key'] ) && get_post_meta( $id, $args['meta_key'], true ) !== $args['meta_value'] ) { continue; }
        $ids[] = $id;
    }
    return array_slice( $ids, 0, $args['posts_per_page'] ?? count( $ids ) );
}
function update_post_meta( $post_id, $key, $value ) { $GLOBALS['wp_meta'][ $post_id ][ $key ] = $value; return true; }
function add_post_meta( $post_id, $key, $value ) { $GLOBALS['wp_meta'][ $post_id ][ $key . '[]' ][] = $value; return true; }
function get_post_meta( $post_id, $key = '', $single = false ) {
    if ( '' === $key ) { return $GLOBALS['wp_meta'][ $post_id ] ?? array(); }
    $value = $GLOBALS['wp_meta'][ $post_id ][ $key ] ?? ( $single ? '' : array() );
    return $single ? $value : ( is_array( $value ) ? $value : array( $value ) );
}
function delete_post_meta( $post_id, $key ) { unset( $GLOBALS['wp_meta'][ $post_id ][ $key ] ); return true; }
function update_field( $field, $value, $post_id ) { return update_post_meta( $post_id, $field, $value ); }
function get_field( $field, $post_id ) {
    $value = get_post_meta( $post_id, $field, true );
    return 'journal_ready_for_review' === $field && 0 === $value ? false : $value;
}

function taxonomy_exists( $taxonomy ) { return in_array( $taxonomy, array( 'journal_section', 'journal_topic', 'journal_type' ), true ); }
function term_exists( $name, $taxonomy ) {
    foreach ( $GLOBALS['wp_terms'][ $taxonomy ] ?? array() as $id => $term_name ) { if ( $term_name === $name ) { return $id; } }
    return 0;
}
function wp_insert_term( $name, $taxonomy ) {
    $id = count( $GLOBALS['wp_terms'][ $taxonomy ] ?? array() ) + 1;
    $GLOBALS['wp_terms'][ $taxonomy ][ $id ] = $name;
    return array( 'term_id' => $id );
}
function wp_set_object_terms( $post_id, $term_ids, $taxonomy, $append = false ) {
    if ( ! empty( $GLOBALS['fail_term_assignment'] ) ) { return new WP_Error( 'term_failure', 'Forced term assignment failure.' ); }
    $GLOBALS['wp_object_terms'][ $post_id ][ $taxonomy ] = array_map( 'intval', (array) $term_ids );
    return $term_ids;
}
function wp_get_object_terms( $post_id, $taxonomy, $args = array() ) { return $GLOBALS['wp_object_terms'][ $post_id ][ $taxonomy ] ?? array(); }
function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) { return wp_get_object_terms( $post_id, $taxonomy, $args ); }
function get_the_category() { return array(); }
function wp_list_pluck( $items, $field ) { return array_map( function ( $item ) use ( $field ) { return is_object( $item ) ? $item->$field : $item[ $field ]; }, $items ); }

function set_post_thumbnail( $post_id, $attachment_id ) { update_post_meta( $post_id, '_thumbnail_id', (int) $attachment_id ); return true; }
function get_post_thumbnail_id( $post_id ) { return (int) get_post_meta( $post_id, '_thumbnail_id', true ); }
function has_post_thumbnail( $post_id ) { return get_post_thumbnail_id( $post_id ) > 0; }
function get_post_mime_type( $post_id ) { $post = get_post( $post_id ); return $post ? $post->post_mime_type : ''; }
function wp_get_attachment_url( $post_id ) { return get_post( $post_id ) ? 'https://example.com/image.jpg' : false; }
function wp_get_attachment_metadata( $post_id ) { return array( 'width' => 1600, 'height' => 900 ); }
function wp_get_attachment_image_src( $post_id ) { return array( 'https://example.com/image.jpg', 1600, 900 ); }

function rest_ensure_response( $value ) { return $value; }
function admin_url( $path = '' ) { return 'https://example.com/wp-admin/' . ltrim( $path, '/' ); }
function rest_url( $path = '' ) { return 'https://example.com/wp-json/' . ltrim( $path, '/' ); }
function get_the_title( $post ) { $post = get_post( $post ); return $post ? $post->post_title : ''; }
function get_permalink( $post ) { $post = get_post( $post ); return $post ? 'https://example.com/journal/' . $post->ID : ''; }
function get_preview_post_link( $post ) { return get_permalink( $post ) . '?preview=1'; }

function behavior_assert( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}

$GLOBALS['wp_posts'][99] = new WP_Post( array( 'ID' => 99, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg' ) );
update_post_meta( 99, '_wp_attachment_image_alt', 'A film still' );

require dirname( __DIR__ ) . '/lunara-journal-foundation.php';
behavior_assert( Lunara_Journal_Foundation::VERSION === LUNARA_JOURNAL_FOUNDATION_VERSION, 'Runtime and global Foundation release identity must agree.' );

$scope_resolver = new ReflectionMethod( 'Lunara_Journal_Foundation', 'required_scope_for_request' );
$scope_resolver->setAccessible( true );
$automation_scopes = array(
    '/lunara/v1/journal/automation/status'       => 'automation_read',
    '/lunara/v1/journal/automation/inbox'        => 'automation_read',
    '/lunara/v1/journal/automation/capture'      => 'capture',
    '/lunara/v1/journal/automation/run-dispatch' => 'run_dispatch',
    '/lunara/v1/journal/automation/morning-desk' => 'notify',
);
foreach ( $automation_scopes as $route => $expected_scope ) {
    $automation_request = new WP_REST_Request( array(), array(), $route, 'POST' );
    behavior_assert( $expected_scope === $scope_resolver->invoke( null, $automation_request ), 'Automation route has the wrong required scope: ' . $route );
}

$profile_resolver = new ReflectionMethod( 'Lunara_Journal_Foundation', 'default_access_profiles' );
$profile_resolver->setAccessible( true );
$default_profiles = $profile_resolver->invoke( null );
behavior_assert( isset( $default_profiles['ifttt_operator'] ), 'Default IFTTT operator profile is missing.' );
$ifttt_scopes = $default_profiles['ifttt_operator']['scopes'];
behavior_assert( in_array( 'capture', $ifttt_scopes, true ) && in_array( 'run_dispatch', $ifttt_scopes, true ) && in_array( 'notify', $ifttt_scopes, true ) && in_array( 'ingest', $ifttt_scopes, true ), 'IFTTT operator lacks its required private automation and draft-ingest scopes.' );
behavior_assert( ! in_array( 'automation_read', $ifttt_scopes, true ) && ! in_array( 'audit', $ifttt_scopes, true ) && ! in_array( 'publish', $ifttt_scopes, true ) && ! in_array( 'convert', $ifttt_scopes, true ) && ! in_array( 'schema', $ifttt_scopes, true ) && ! in_array( '*', $ifttt_scopes, true ), 'IFTTT operator received read, audit, publish, conversion, schema, or wildcard authority beyond its four actions.' );
behavior_assert( true === Lunara_Journal_Automation::rest_validate_capture_type( 'idea', null, 'type' ), 'Idea capture type must validate with WordPress three-argument callbacks.' );
behavior_assert( false === Lunara_Journal_Automation::rest_validate_capture_type( 'publish', null, 'type' ), 'Unapproved capture type must fail validation.' );

$capture_lock_acquire = new ReflectionMethod( 'Lunara_Journal_Automation', 'acquire_capture_lock' );
$capture_lock_release = new ReflectionMethod( 'Lunara_Journal_Automation', 'release_capture_lock' );
$capture_lock_acquire->setAccessible( true );
$capture_lock_release->setAccessible( true );
$capture_lock = $capture_lock_acquire->invoke( null, 'ifttt-capture-1' );
behavior_assert( is_array( $capture_lock ) && ! empty( $capture_lock['owner'] ), 'First capture request must acquire an atomic lock.' );
$capture_contended = $capture_lock_acquire->invoke( null, 'ifttt-capture-1' );
behavior_assert( is_wp_error( $capture_contended ) && 'lunara_automation_capture_lock_busy' === $capture_contended->get_error_code(), 'Concurrent capture retry must fail retryably while another owner holds the event lock.' );
$capture_lock_release->invoke( null, $capture_lock );
behavior_assert( null === get_option( $capture_lock['option_name'], null ), 'Capture lock must be released by its owner.' );

$stale_capture_name = Lunara_Journal_Automation::CAPTURE_LOCK_PREFIX . hash( 'sha256', 'ifttt-capture-stale' );
add_option( $stale_capture_name, array( 'owner' => 'stale-owner', 'created_at' => time() - 300, 'expires_at' => time() - 180 ), '', false );
$stale_capture_lock = $capture_lock_acquire->invoke( null, 'ifttt-capture-stale' );
behavior_assert( is_array( $stale_capture_lock ) && 'stale-owner' !== $stale_capture_lock['owner'], 'Clearly expired capture lock must be reclaimed atomically.' );
$capture_lock_release->invoke( null, $stale_capture_lock );
behavior_assert( null === get_option( $stale_capture_name, null ), 'Reclaimed capture lock must be released after use.' );

// WordPress supplies value, request, and parameter name to validate_callback.
// This must remain safe on PHP 8+ and reject non-positive/non-integer IDs.
$validator_request = new WP_REST_Request( array( 'id' => '100020' ) );
behavior_assert( true === Lunara_Journal_Foundation::rest_validate_positive_id( '100020', $validator_request, 'id' ), 'Three-argument REST validation must accept a positive integer ID.' );
behavior_assert( false === Lunara_Journal_Foundation::rest_validate_positive_id( '0', $validator_request, 'id' ), 'REST validation must reject zero.' );
behavior_assert( false === Lunara_Journal_Foundation::rest_validate_positive_id( '-1', $validator_request, 'id' ), 'REST validation must reject negative IDs.' );
behavior_assert( false === Lunara_Journal_Foundation::rest_validate_positive_id( '12.5', $validator_request, 'id' ), 'REST validation must reject decimal IDs.' );
behavior_assert( false === Lunara_Journal_Foundation::rest_validate_positive_id( 'draft', $validator_request, 'id' ), 'REST validation must reject non-numeric IDs.' );

behavior_assert( Lunara_Journal_Notion_Client::has_credentials(), 'Notion credentials must accept the constant-first token path.' );

$ingest_match = new ReflectionMethod( 'Lunara_Journal_Ingest', 'matches' );
$ingest_match->setAccessible( true );
behavior_assert( true === $ingest_match->invoke( null, 0, false, 'journal_ready_for_review' ), 'Ingest readback must accept ACF false for an unchecked true_false field.' );
behavior_assert( true === $ingest_match->invoke( null, 1, true, 'journal_bridge_locked' ), 'Ingest readback must accept ACF true for a checked true_false field.' );
behavior_assert( false === $ingest_match->invoke( null, 0, false, 'journal_primary_year' ), 'Ingest readback must keep non-boolean fields strict.' );

$source_date_normalizer = new ReflectionMethod( 'Lunara_Journal_Ingest', 'normalize_source_published_at' );
$source_date_normalizer->setAccessible( true );
behavior_assert( '2026-08-12 15:54:00' === $source_date_normalizer->invoke( null, 'August 12, 2026 at 03:54PM' ), 'IFTTT human-readable dates must normalize to ACF local database format.' );
behavior_assert( '2026-08-12 15:54:00' === $source_date_normalizer->invoke( null, '2026-08-12 15:54:00' ), 'Already-normalized ACF dates must remain stable.' );
behavior_assert( '2026-08-12 15:54:00' === $source_date_normalizer->invoke( null, '2026-08-12T20:54:00+00:00' ), 'Offset publication dates must normalize to the WordPress site timezone.' );
behavior_assert( 'not-a-date' === $source_date_normalizer->invoke( null, 'not-a-date' ), 'Invalid publication dates must remain invalid so strict readback can fail closed.' );
behavior_assert( '' === $source_date_normalizer->invoke( null, '' ), 'Empty source publication dates must remain empty.' );

$paragraph_normalizer = new ReflectionMethod( 'Lunara_Journal_Ingest', 'normalize_content_paragraphs' );
$paragraph_normalizer->setAccessible( true );
$long_sentences = array();
for ( $sentence_index = 1; $sentence_index <= 12; $sentence_index++ ) {
    $long_sentences[] = 'Sentence ' . $sentence_index . ' preserves the <em>Lunara Film</em> editorial wording while adding useful structure for the classic editor.';
}
$long_unstructured = '<p>' . implode( ' ', $long_sentences ) . '</p>';
$structured = $paragraph_normalizer->invoke( null, $long_unstructured );
behavior_assert( preg_match_all( '/<p\b/i', $structured ) >= 3, 'Long single-paragraph drafts must gain editable paragraph structure.' );
behavior_assert( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $long_unstructured ) ) ) === trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $structured ) ) ), 'Paragraph normalization must preserve every editorial word.' );
behavior_assert( false !== strpos( $structured, '<em>Lunara Film</em>' ), 'Paragraph normalization must preserve allowed inline emphasis.' );
$already_structured = '<p>First editorial paragraph stays intact.</p><p>Second editorial paragraph stays intact.</p>';
behavior_assert( $already_structured === $paragraph_normalizer->invoke( null, $already_structured ), 'Existing paragraph structure must remain untouched.' );
$block_structured = '<div>' . implode( ' ', $long_sentences ) . '</div>';
behavior_assert( $block_structured === $paragraph_normalizer->invoke( null, $block_structured ), 'Ambiguous block markup must remain unchanged so validation can fail closed.' );

$preferred_image_url = new ReflectionMethod( 'Lunara_Journal_Image_Sideload', 'preferred_download_url' );
$preferred_image_url->setAccessible( true );
$deadline_1024 = 'https://deadline.com/wp-content/uploads/2026/08/Lilly-Wachowski-2.jpg?w=1024';
behavior_assert( 'https://deadline.com/wp-content/uploads/2026/08/Lilly-Wachowski-2.jpg?w=1920' === $preferred_image_url->invoke( null, $deadline_1024 ), 'WordPress source images capped below the preferred size must request a 1920px derivative.' );
behavior_assert( 'https://deadline.com/wp-content/uploads/2026/08/Lilly-Wachowski-2.jpg?w=2048' === $preferred_image_url->invoke( null, 'https://deadline.com/wp-content/uploads/2026/08/Lilly-Wachowski-2.jpg?w=2048' ), 'Already-large source images must not be reduced.' );
behavior_assert( 'https://images.example.com/Lilly-Wachowski-2.jpg?w=1024' === $preferred_image_url->invoke( null, 'https://images.example.com/Lilly-Wachowski-2.jpg?w=1024' ), 'Non-WordPress source URLs must not be rewritten.' );

$foundation_match = new ReflectionMethod( 'Lunara_Journal_Foundation', 'readback_values_match' );
$foundation_match->setAccessible( true );
behavior_assert( true === $foundation_match->invoke( null, 0, false, 'journal_ready_for_review' ), 'Conversion readback must accept ACF false for an unchecked true_false field.' );
behavior_assert( true === $foundation_match->invoke( null, 1, true, 'journal_bridge_locked' ), 'Conversion readback must accept ACF true for a checked true_false field.' );
behavior_assert( false === $foundation_match->invoke( null, 0, false, 'journal_primary_year' ), 'Conversion readback must keep non-boolean fields strict.' );

$public_config = Lunara_Journal_Control_Plane::public_config( array(
    'dispatch' => array( 'max_tokens' => 4096, 'credentials' => array( 'api_key' => 'remove-me', 'model' => 'keep-me' ) ),
    'client_secret' => 'remove-me-too',
) );
behavior_assert( 4096 === $public_config['dispatch']['max_tokens'], 'Recursive redaction must preserve non-secret token budgets.' );
behavior_assert( ! isset( $public_config['dispatch']['credentials']['api_key'] ) && ! isset( $public_config['client_secret'] ), 'Recursive redaction must remove nested and top-level secrets.' );
behavior_assert( 'keep-me' === $public_config['dispatch']['credentials']['model'], 'Recursive redaction must preserve safe neighboring values.' );

$words = implode( ' ', array_fill( 0, 90, 'cinema' ) );
$payload = array(
    'idempotency_key' => 'dispatch-run-42-item-7',
    'title' => 'A Verified Journal Draft',
    'content' => '<p class="builder" style="color:red">' . $words . '</p><p>Source context and editorial analysis.</p>',
    'deck' => 'A canonical deck that also supplies the excerpt.',
    'seo_description' => 'A concise search description for this Journal entry.',
    'featured_media' => 99,
    'source_items' => array( array( 'url' => 'https://example.com/source', 'headline' => 'Source headline', 'publication' => 'Example', 'published_at' => 'August 12, 2026 at 03:54PM' ) ),
    'classification' => array(
        'section' => 'News',
        'topics' => array( 'Production' ),
        'item_type' => 'news',
        'primary_title' => 'A Verified Film',
        'primary_year' => 2026,
    ),
    'provenance' => array( 'provider' => 'openai', 'model' => 'test-model', 'run_id' => 'run-42' ),
);

$first = apply_filters( 'lunara_journal_foundation_ingest', null, $payload );
behavior_assert( is_array( $first ) && true === $first['created'], 'First ingest must create a draft.' );
$first_lock = 'lunara_journal_ingest_lock_' . hash( 'sha256', $payload['idempotency_key'] );
behavior_assert( null === get_option( $first_lock, null ), 'Successful ingest must release its idempotency lock.' );
behavior_assert( 'draft' === get_post_status( $first['post_id'] ), 'Ingest must persist draft status.' );
behavior_assert( 'A canonical deck that also supplies the excerpt.' === get_post( $first['post_id'] )->post_excerpt, 'Deck must populate the canonical excerpt.' );
behavior_assert( 'A canonical deck that also supplies the excerpt.' === get_field( 'journal_deck', $first['post_id'] ), 'Deck field must persist.' );
behavior_assert( 'A Verified Film' === get_field( 'journal_primary_title', $first['post_id'] ), 'Primary title classification must persist.' );
behavior_assert( '2026-08-12 15:54:00' === get_field( 'journal_source_items', $first['post_id'] )[0]['source_published_at'], 'Full ingest must persist the normalized source publication date.' );
behavior_assert( false === get_field( 'journal_ready_for_review', $first['post_id'] ), 'Behavior harness must mirror ACF true_false readback for an unchecked field.' );
behavior_assert( 'run-42' === get_post_meta( $first['post_id'], '_lunara_dispatch_run_id', true ), 'Dispatch run provenance must persist.' );
behavior_assert( false === strpos( get_post( $first['post_id'] )->post_content, 'class=' ) && false === strpos( get_post( $first['post_id'] )->post_content, 'style=' ), 'Actual ingestion must strip presentational attributes before inserting.' );
$saved_validation = get_post_meta( $first['post_id'], '_lunara_journal_last_validation', true );
behavior_assert( is_array( $saved_validation ) && isset( $saved_validation['checked_at'] ) && ( ! empty( $saved_validation['valid'] ) ? 'passed' : 'failed' ) === get_field( 'journal_validation_status', $first['post_id'] ), 'Validation state must come from the existing validator report, not HTML normalization.' );
behavior_assert( 'test-model' === get_post_meta( $first['post_id'], '_lunara_journal_initial_model', true ), 'Sanitization must preserve the actual model.' );
$unsafe_payload = $payload;
$unsafe_payload['idempotency_key'] = 'unsafe-script-input';
$unsafe_payload['content'] = '<p>Editorial text.</p><script>alert(1)</script>';
$unsafe_result = Lunara_Journal_Ingest::ingest( $unsafe_payload, true );
behavior_assert( is_wp_error( $unsafe_result ) && 'lunara_ingest_executable_html' === $unsafe_result->get_error_code(), 'Executable input must fail before creating a draft.' );

$second = apply_filters( 'lunara_journal_foundation_ingest', null, $payload );
behavior_assert( is_array( $second ) && false === $second['created'], 'Second ingest must reuse the existing draft.' );
behavior_assert( $first['post_id'] === $second['post_id'], 'Idempotent ingest must return the original post ID.' );
behavior_assert( null === get_option( $first_lock, null ), 'Idempotent reuse must release its lock through finally.' );

$contended = $payload;
$contended['idempotency_key'] = 'dispatch-contended-key';
$contended_lock = 'lunara_journal_ingest_lock_' . hash( 'sha256', $contended['idempotency_key'] );
add_option( $contended_lock, array( 'owner' => 'other-owner', 'created_at' => time(), 'expires_at' => time() + 120 ), '', false );
$contended_result = apply_filters( 'lunara_journal_foundation_ingest', null, $contended );
behavior_assert( is_wp_error( $contended_result ) && 'lunara_ingest_lock_busy' === $contended_result->get_error_code(), 'A fresh lock owner must make concurrent ingest fail retryably.' );
behavior_assert( true === $contended_result->get_error_data()['retryable'], 'Lock contention must be explicitly retryable.' );
behavior_assert( 'other-owner' === get_option( $contended_lock, array() )['owner'], 'Contention must not release another owner lock.' );
delete_option( $contended_lock );

$malformed = $payload;
$malformed['idempotency_key'] = 'dispatch-malformed-lock';
$malformed_lock = 'lunara_journal_ingest_lock_' . hash( 'sha256', $malformed['idempotency_key'] );
add_option( $malformed_lock, array( 'owner' => 'unknown-owner' ), '', false );
$malformed_result = apply_filters( 'lunara_journal_foundation_ingest', null, $malformed );
behavior_assert( is_wp_error( $malformed_result ) && 'lunara_ingest_lock_busy' === $malformed_result->get_error_code(), 'A lock without a clear expiry must not be reclaimed.' );
behavior_assert( 'unknown-owner' === get_option( $malformed_lock, array() )['owner'], 'Malformed lock state must remain untouched for manual recovery.' );
delete_option( $malformed_lock );

$stale = $payload;
$stale['idempotency_key'] = 'dispatch-stale-key';
$stale_lock = 'lunara_journal_ingest_lock_' . hash( 'sha256', $stale['idempotency_key'] );
add_option( $stale_lock, array( 'owner' => 'stale-owner', 'created_at' => time() - 300, 'expires_at' => time() - 180 ), '', false );
$stale_result = apply_filters( 'lunara_journal_foundation_ingest', null, $stale );
behavior_assert( is_array( $stale_result ) && true === $stale_result['created'], 'A clearly expired lock must be reclaimed.' );
behavior_assert( null === get_option( $stale_lock, null ), 'Reclaimed lock must be released after success.' );

$quarantine = $payload;
$quarantine['idempotency_key'] = 'dispatch-error-key';
$quarantine_lock = 'lunara_journal_ingest_lock_' . hash( 'sha256', $quarantine['idempotency_key'] );
$GLOBALS['fail_term_assignment'] = true;
$quarantine_result = apply_filters( 'lunara_journal_foundation_ingest', null, $quarantine );
$GLOBALS['fail_term_assignment'] = false;
behavior_assert( is_wp_error( $quarantine_result ) && 'lunara_ingest_incomplete' === $quarantine_result->get_error_code(), 'Verification failure must quarantine the unpublished draft.' );
behavior_assert( null === get_option( $quarantine_lock, null ), 'Ingest error and quarantine paths must release their lock in finally.' );

$forbidden = $payload;
$forbidden['post_status'] = 'publish';
$forbidden_result = apply_filters( 'lunara_journal_foundation_ingest', null, $forbidden );
behavior_assert( is_wp_error( $forbidden_result ) && 'lunara_ingest_no_status' === $forbidden_result->get_error_code(), 'Ingest must reject requested publish status.' );

$GLOBALS['suppress_publish'] = true;
$request = new WP_REST_Request( array( 'id' => $first['post_id'] ), array( 'confirm_publish_now' => true ) );
$publish = Lunara_Journal_Fast_Desk::rest_publish_draft( $request );
behavior_assert( is_wp_error( $publish ) && 'lunara_publish_readback_failed' === $publish->get_error_code(), 'Publish must fail when WordPress does not persist publish status.' );
behavior_assert( '' === get_post_meta( $first['post_id'], '_lunara_journal_published_at_gmt', true ), 'Failed publish must not write published provenance.' );
behavior_assert( 'draft' === get_post_status( $first['post_id'] ), 'Failed publish must leave the post as a draft.' );

echo "Journal Foundation behavioral contracts passed: ingest locks, idempotency, and publish readback.\n";

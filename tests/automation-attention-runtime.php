<?php
/**
 * Hook-aware runtime contract for validation-origin Needs Attention alerts.
 *
 * Run: php tests/automation-attention-runtime.php
 */

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
define( 'LUNARA_IFTTT_WEBHOOK_KEY', 'runtime-test-webhook-key' );
// WordPress slashes before a meta save and unslashes inside it; with this stub's
// plain meta store the pair is the identity.
if ( ! function_exists( 'wp_slash' ) ) { function wp_slash( $value ) { return $value; } }

$GLOBALS['attention_posts'] = array(
    700 => array( 'post_type' => 'journal', 'title' => 'A Journal Draft' ),
);
$GLOBALS['attention_meta'] = array();
$GLOBALS['attention_options'] = array(
    'lunara_journal_automation_enabled' => '1',
);
$GLOBALS['attention_hooks'] = array();
$GLOBALS['attention_scheduled'] = array();
$GLOBALS['attention_http'] = array();
$GLOBALS['attention_schedule_error'] = false;
$GLOBALS['attention_http_result'] = 'success';
$GLOBALS['attention_validator_valid'] = array( 700 => false );
$GLOBALS['attention_finish_image_at_shutdown'] = false;
$GLOBALS['attention_write_log'] = array();
$GLOBALS['attention_validation_tick'] = 0;

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code = '', $message = '' ) {
        $this->code = $code;
        $this->message = $message;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function maybe_serialize( $value ) { return is_scalar( $value ) ? (string) $value : serialize( $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function current_time( $type, $gmt = false ) {
    if ( 'Y-m-d' === $type ) {
        return '2026-08-14';
    }
    return $gmt ? '2026-08-14 22:00:00' : '2026-08-14 17:00:00';
}
function is_user_logged_in() { return false; }
function get_current_user_id() { return 0; }
function esc_url_raw( $value, $protocols = null ) { unset( $protocols ); return filter_var( (string) $value, FILTER_SANITIZE_URL ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' ); }
function get_post_type( $post_id ) { return $GLOBALS['attention_posts'][ $post_id ]['post_type'] ?? false; }
function get_the_title( $post_id ) { return $GLOBALS['attention_posts'][ $post_id ]['title'] ?? ''; }
function get_edit_post_link( $post_id, $context = '' ) { unset( $context ); return 'https://example.test/wp-admin/post.php?post=' . absint( $post_id ); }

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['attention_hooks'][ $hook ][ (int) $priority ][] = array(
        'callback'      => $callback,
        'accepted_args' => (int) $accepted_args,
    );
    return true;
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    return add_action( $hook, $callback, $priority, $accepted_args );
}
function attention_do_action( $hook, ...$args ) {
    if ( empty( $GLOBALS['attention_hooks'][ $hook ] ) ) {
        return;
    }
    $priorities = $GLOBALS['attention_hooks'][ $hook ];
    ksort( $priorities, SORT_NUMERIC );
    foreach ( $priorities as $callbacks ) {
        foreach ( $callbacks as $registered ) {
            call_user_func_array( $registered['callback'], array_slice( $args, 0, $registered['accepted_args'] ) );
        }
    }
}
function do_action( $hook, ...$args ) {
    attention_do_action( $hook, ...$args );
}
function get_post_meta( $post_id, $key, $single = false ) {
    $value = $GLOBALS['attention_meta'][ $post_id ][ $key ] ?? '';
    return $single ? $value : array( $value );
}
function update_post_meta( $post_id, $key, $value ) {
    $exists = array_key_exists( $key, $GLOBALS['attention_meta'][ $post_id ] ?? array() );
    if ( $exists && $GLOBALS['attention_meta'][ $post_id ][ $key ] === $value ) {
        return false;
    }
    $GLOBALS['attention_meta'][ $post_id ][ $key ] = $value;
    $GLOBALS['attention_write_log'][] = $key;
    attention_do_action( $exists ? 'updated_post_meta' : 'added_post_meta', 1, $post_id, $key, $value );
    return true;
}
function add_post_meta( $post_id, $key, $value, $unique = false ) {
    unset( $unique );
    return update_post_meta( $post_id, $key, $value );
}
function delete_post_meta( $post_id, $key ) {
    $exists = array_key_exists( $key, $GLOBALS['attention_meta'][ $post_id ] ?? array() );
    unset( $GLOBALS['attention_meta'][ $post_id ][ $key ] );
    if ( $exists ) {
        attention_do_action( 'deleted_post_meta', array( 1 ), $post_id, $key, '' );
    }
    return true;
}
function get_option( $key, $default = false ) {
    return array_key_exists( $key, $GLOBALS['attention_options'] ) ? $GLOBALS['attention_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
    unset( $autoload );
    $GLOBALS['attention_options'][ $key ] = $value;
    return true;
}
function wp_next_scheduled( $hook, $args = array() ) {
    foreach ( $GLOBALS['attention_scheduled'] as $scheduled ) {
        if ( $hook === $scheduled['hook'] && $args === $scheduled['args'] ) {
            return $scheduled['timestamp'];
        }
    }
    return false;
}
function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
    unset( $wp_error );
    if ( ! empty( $GLOBALS['attention_schedule_error'] ) ) {
        return new WP_Error( 'schedule_failed', 'Cron queue is unavailable.' );
    }
    $GLOBALS['attention_scheduled'][] = array(
        'timestamp' => $timestamp,
        'hook'      => $hook,
        'args'      => $args,
    );
    return true;
}
function wp_safe_remote_post( $url, $args ) {
    $GLOBALS['attention_http'][] = array( 'url' => $url, 'args' => $args, 'result' => $GLOBALS['attention_http_result'] );
    if ( 'error' === $GLOBALS['attention_http_result'] ) {
        return new WP_Error( 'transport_failed', 'Transport failed.' );
    }
    if ( 'http_error' === $GLOBALS['attention_http_result'] ) {
        return array( 'response' => array( 'code' => 503 ) );
    }
    return array( 'response' => array( 'code' => 200 ) );
}
function wp_remote_retrieve_response_code( $response ) { return (int) ( $response['response']['code'] ?? 0 ); }

final class Lunara_Journal_Validator {
    public static function validate_post( $post_id ) {
        $valid = ! empty( $GLOBALS['attention_validator_valid'][ $post_id ] );
        $GLOBALS['attention_validation_tick']++;
        return array(
            'valid'      => $valid,
            'errors'     => $valid ? array() : array( 'Featured image is required.' ),
            'warnings'   => array(),
            'checked_at' => '2026-08-14 22:00:' . str_pad( (string) $GLOBALS['attention_validation_tick'], 2, '0', STR_PAD_LEFT ),
        );
    }
}

require dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-lunara-journal-provenance.php';
require dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-lunara-journal-automation.php';

Lunara_Journal_Automation::bootstrap();

function attention_finish_image() {
    if ( ! empty( $GLOBALS['attention_finish_image_at_shutdown'] ) ) {
        $GLOBALS['attention_validator_valid'][700] = true;
    }
}
add_action( 'shutdown', 'attention_finish_image', 20 );

$failures = array();
function attention_assert( $condition, $message ) {
    global $failures;
    if ( ! $condition ) {
        $failures[] = $message;
    }
}
function attention_reset() {
    $GLOBALS['attention_meta'] = array();
    $GLOBALS['attention_options'] = array( 'lunara_journal_automation_enabled' => '1' );
    $GLOBALS['attention_scheduled'] = array();
    $GLOBALS['attention_http'] = array();
    $GLOBALS['attention_schedule_error'] = false;
    $GLOBALS['attention_http_result'] = 'success';
    $GLOBALS['attention_validator_valid'] = array( 700 => false );
    $GLOBALS['attention_finish_image_at_shutdown'] = false;
    $GLOBALS['attention_write_log'] = array();
    $GLOBALS['attention_validation_tick'] = 0;
}
function attention_attach_validation( $valid ) {
    $GLOBALS['attention_validator_valid'][700] = (bool) $valid;
    Lunara_Journal_Provenance::attach_validation_result( 700, Lunara_Journal_Validator::validate_post( 700 ) );
}
function attention_run_next() {
    $scheduled = array_shift( $GLOBALS['attention_scheduled'] );
    if ( ! $scheduled ) {
        return false;
    }
    Lunara_Journal_Automation::send_scheduled_event( $scheduled['args'][0], $scheduled['args'][1] );
    return true;
}
function attention_signature() {
    return get_post_meta( 700, '_lunara_automation_last_attention_signature', true );
}

// The alert settlement hook must run after image sideload's shutdown priority 20.
$settlement_priority = 0;
foreach ( $GLOBALS['attention_hooks']['shutdown'] ?? array() as $priority => $callbacks ) {
    foreach ( $callbacks as $registered ) {
        if ( array( 'Lunara_Journal_Automation', 'settle_validation_attention' ) === $registered['callback'] ) {
            $settlement_priority = (int) $priority;
        }
    }
}
attention_assert( $settlement_priority > 20, 'Validation attention settlement is not registered after image sideload shutdown priority 20.' );

// Provenance must make the report and last-validation snapshot authoritative before status hooks fire.
attention_reset();
attention_attach_validation( false );
$report_position = array_search( 'journal_validation_report', $GLOBALS['attention_write_log'], true );
$last_position = array_search( '_lunara_journal_last_validation', $GLOBALS['attention_write_log'], true );
$status_position = array_search( 'journal_validation_status', $GLOBALS['attention_write_log'], true );
attention_assert(
    false !== $report_position && false !== $last_position && false !== $status_position && $report_position < $status_position && $last_position < $status_position,
    'Provenance exposed validation status before persisting its report and last-validation snapshot.'
);
attention_do_action( 'shutdown' );

// An early failed status must not create a +5 cron event before the image finishes at shutdown.
attention_reset();
attention_attach_validation( false );
attention_assert( 0 === count( $GLOBALS['attention_scheduled'] ), 'Early failed validation queued an alert before shutdown settlement.' );
attention_assert( false === attention_run_next(), 'A five-second cron alert existed before image completion.' );
$GLOBALS['attention_finish_image_at_shutdown'] = true;
attention_do_action( 'shutdown' );
attention_assert( 'passed' === get_post_meta( 700, 'journal_validation_status', true ), 'Shutdown settlement did not persist the post-image validation result.' );
attention_assert( 0 === count( $GLOBALS['attention_scheduled'] ), 'Recovered post-image validation still queued an alert.' );
attention_assert( 0 === count( $GLOBALS['attention_http'] ), 'Recovered post-image validation emitted a false webhook.' );

// A same-request recovery must settle quietly even without the sideload callback.
attention_reset();
attention_attach_validation( false );
attention_attach_validation( true );
attention_do_action( 'shutdown' );
attention_assert( 0 === count( $GLOBALS['attention_scheduled'] ), 'Same-request recovery queued a stale alert.' );
attention_assert( '' === attention_signature(), 'Same-request recovery retained the failed attention signature.' );

// A queued failure that recovers before cron delivery must be suppressed at send time.
attention_reset();
attention_attach_validation( false );
attention_do_action( 'shutdown' );
attention_assert( 1 === count( $GLOBALS['attention_scheduled'] ), 'Failed validation did not queue the send-time recovery fixture.' );
attention_attach_validation( true );
attention_run_next();
attention_assert( 0 === count( $GLOBALS['attention_http'] ), 'Queued validation alert sent after the draft recovered.' );
attention_assert( '' === attention_signature(), 'Send-time recovery did not clear the queued failure signature.' );

// A persistent failure queues once, sends once, and remains deduplicated.
attention_reset();
attention_attach_validation( false );
attention_attach_validation( false );
attention_do_action( 'shutdown' );
attention_assert( 1 === count( $GLOBALS['attention_scheduled'] ), 'Persistent validation failure did not queue exactly one alert.' );
attention_assert( '' !== attention_signature(), 'Successful alert queue did not store its dedupe signature.' );
attention_run_next();
attention_assert( 1 === count( $GLOBALS['attention_http'] ), 'Persistent validation failure did not emit exactly one webhook.' );
attention_attach_validation( false );
attention_do_action( 'shutdown' );
attention_assert( 0 === count( $GLOBALS['attention_scheduled'] ), 'Unchanged persistent failure queued a duplicate alert.' );
attention_assert( 1 === count( $GLOBALS['attention_http'] ), 'Unchanged persistent failure emitted a duplicate webhook.' );

// Recovery clears the signature and the same later failure can notify again.
attention_attach_validation( true );
attention_do_action( 'shutdown' );
attention_assert( '' === attention_signature(), 'Recovery did not clear the attention signature.' );
attention_attach_validation( false );
attention_do_action( 'shutdown' );
attention_assert( 1 === count( $GLOBALS['attention_scheduled'] ), 'The same failure did not re-arm after recovery.' );
attention_run_next();
attention_assert( 2 === count( $GLOBALS['attention_http'] ), 'A post-recovery failure did not emit a fresh webhook.' );

// A queue failure must not consume the failure signature and must be retryable.
attention_reset();
$GLOBALS['attention_schedule_error'] = true;
attention_attach_validation( false );
attention_do_action( 'shutdown' );
attention_assert( '' === attention_signature(), 'Queue failure consumed the validation failure signature.' );
$GLOBALS['attention_schedule_error'] = false;
attention_attach_validation( false );
attention_do_action( 'shutdown' );
attention_assert( 1 === count( $GLOBALS['attention_scheduled'] ), 'Validation alert did not retry after queue recovery.' );
attention_run_next();
attention_assert( 1 === count( $GLOBALS['attention_http'] ), 'Queue-recovered validation alert did not send.' );

// A delivery failure must clear the signature so the persistent failure can retry.
attention_reset();
attention_attach_validation( false );
attention_do_action( 'shutdown' );
$GLOBALS['attention_http_result'] = 'error';
attention_run_next();
attention_assert( '' === attention_signature(), 'Delivery failure retained and consumed the validation failure signature.' );
$GLOBALS['attention_http_result'] = 'success';
attention_attach_validation( false );
attention_do_action( 'shutdown' );
attention_assert( 1 === count( $GLOBALS['attention_scheduled'] ), 'Validation alert did not re-arm after delivery failure.' );
attention_run_next();
attention_assert( 2 === count( $GLOBALS['attention_http'] ), 'Delivery-recovered validation alert did not retry.' );

// Dispatch-run failures carry no post-validation context and must remain unaffected.
attention_reset();
Lunara_Journal_Automation::handle_dispatch_report_update(
    array(),
    array(
        'success'       => false,
        'message'       => 'Provider transport failed.',
        'created'       => 0,
        'imported'      => 0,
        'timestamp_gmt' => '2026-08-14 22:00:00',
    ),
    'lunara_dispatch_last_run_report'
);
attention_assert( 1 === count( $GLOBALS['attention_scheduled'] ), 'Dispatch failure did not queue its independent attention event.' );
attention_run_next();
attention_assert( 1 === count( $GLOBALS['attention_http'] ), 'Validation settlement suppressed an unrelated Dispatch failure.' );

// The Needs Attention connection test has no validation context and must still send.
attention_reset();
Lunara_Journal_Automation::send_scheduled_event(
    'lunara_needs_attention',
    array(
        'message' => 'Lunara Journal Automation is connected.',
        'link'    => admin_url( 'admin.php?page=lunara-control-desk&tab=automation' ),
        'context' => array( 'kind' => 'connection_test', 'checked_at' => current_time( 'mysql' ) ),
    )
);
attention_assert( 1 === count( $GLOBALS['attention_http'] ), 'Validation settlement suppressed the connection test.' );

if ( ! empty( $failures ) ) {
    fwrite( STDERR, "Automation attention runtime failed:\n- " . implode( "\n- ", $failures ) . "\n" );
    exit( 1 );
}

echo "Automation attention runtime passed.\n";

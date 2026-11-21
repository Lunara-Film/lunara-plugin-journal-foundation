<?php
/**
 * Daily cadence: two or three draft slots, held until the voice is signed off.
 *
 * Run: php tests/cadence-runtime.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code, $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() { return $this->message; }
}
class WP_REST_Request implements ArrayAccess {
	private $params = array();
	public function set_param( $key, $value ) { $this->params[ $key ] = $value; }
	public function set_header( $key, $value ) {}
	public function set_body( $value ) {}
	public function offsetExists( $key ) { return isset( $this->params[ $key ] ); }
	public function offsetGet( $key ) { return $this->params[ $key ] ?? null; }
	public function offsetSet( $key, $value ): void { $this->params[ $key ] = $value; }
	public function offsetUnset( $key ): void { unset( $this->params[ $key ] ); }
}
class WP_REST_Response {
	private $data;
	public function __construct( $data ) { $this->data = $data; }
	public function get_data() { return $this->data; }
	public function header( $key, $value ) {}
}

$GLOBALS['checks'] = 0;
function cadence_check( $condition, $message ) {
	global $checks;
	$GLOBALS['checks']++;
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function rest_ensure_response( $value ) { return $value instanceof WP_REST_Response ? $value : new WP_REST_Response( $value ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_timezone() { return new DateTimeZone( 'America/Chicago' ); }
function get_current_user_id() { return 7; }
function user_can() { return ! empty( $GLOBALS['can_publish'] ); }
function current_user_can() { return ! empty( $GLOBALS['can_publish'] ); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['options'] ) ? $GLOBALS['options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['options'][ $key ] = $value; return true; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function clean_post_cache( $id ) {}
function get_post_status( $id ) { $post = get_post( $id ); return $post ? $post->post_status : ''; }
function get_the_title( $post ) { return is_object( $post ) ? $post->post_title : ( get_post( $post )->post_title ?? '' ); }
function get_edit_post_link() { return 'https://example.test/edit'; }
function get_permalink() { return 'https://example.test/journal/draft'; }
function get_post_time() { return '2026-11-21T15:00:00-06:00'; }
function wp_next_scheduled( $hook ) { return $GLOBALS['events'][ $hook ] ?? false; }
function wp_schedule_event( $at, $interval, $hook ) { $GLOBALS['events'][ $hook ] = array( 'at' => $at, 'interval' => $interval ); return true; }
function wp_schedule_single_event() { return true; }
function add_action() { return true; }
function add_filter( $hook, $callback ) { $GLOBALS['filters'][ $hook ][] = $callback; return true; }
function apply_filters( $hook, $value ) {
	if ( 'lunara_journal_delivery_now' === $hook && isset( $GLOBALS['delivery_now'] ) ) {
		return $GLOBALS['delivery_now'];
	}
	return $value;
}
function get_posts( $args ) {
	if ( ! empty( $args['date_query'] ) ) {
		$published = array();
		foreach ( $GLOBALS['posts'] as $post ) {
			if ( 'publish' === $post->post_status ) {
				$published[] = $post->ID;
			}
		}
		return $published;
	}
	$found = array();
	foreach ( $GLOBALS['posts'] as $post ) {
		if ( in_array( $post->post_status, array( 'draft', 'pending', 'private' ), true ) && get_post_meta( $post->ID, Lunara_Journal_Publishing::META, true ) ) {
			$found[] = $post;
		}
	}
	return $found;
}
class WP_Query {
	public $found_posts = 0;
}
class Lunara_Journal_Control_Plane {
	public static function get_active_config() { return array( 'chatgpt' => array( 'may_publish' => true ) ); }
}
class Lunara_Journal_Validator {
	public static function validate_post() { return array( 'valid' => true ); }
}
class Lunara_Journal_Foundation {
	public static function record_bridge_log_entry() {}
}
class Lunara_Journal_Desk_API {
	public static function settings_permissions_check() { return true; }
	public static function publish_permissions_check() { return true; }
	public static function with_post_lock( $id, $callback ) { return $callback(); }
	public static function with_delivery_lock( $callback ) { return $callback(); }
	public static function revision_for_post( $id ) { return hash( 'sha256', get_post( $id )->post_title ); }
	public static function check_draft_revision( $request ) { return get_post( $request['id'] ); }
}
class Lunara_Journal_Fast_Desk {
	public static function rest_publish_draft( $request ) {
		$GLOBALS['publish_attempts']++;
		$post = get_post( $request['id'] );
		$post->post_status = 'publish';
		return new WP_REST_Response( array( 'published' => true, 'id' => $post->ID ) );
	}
}

require dirname( __DIR__ ) . '/includes/class-lunara-journal-cadence.php';
require dirname( __DIR__ ) . '/includes/class-lunara-journal-publishing.php';

$chicago = new DateTimeZone( 'America/Chicago' );
$morning = new DateTimeImmutable( '2026-11-21 08:30:00', $chicago );
$late_morning = new DateTimeImmutable( '2026-11-21 10:15:00', $chicago );
$afternoon = new DateTimeImmutable( '2026-11-21 15:00:00', $chicago );
$evening = new DateTimeImmutable( '2026-11-21 20:05:00', $chicago );

cadence_check( array() === Lunara_Journal_Cadence::plan( $morning, 0 ), 'before 9:00 the day has not opened a slot' );
$one = Lunara_Journal_Cadence::plan( $late_morning, 0 );
cadence_check( 1 === count( $one ) && 'news' === $one[0]['pillar'], 'after 9:00 the first slot is news' );
$two = Lunara_Journal_Cadence::plan( $afternoon, 0 );
cadence_check( Lunara_Journal_Cadence::DAILY_MIN === count( $two ) && array( 'news', 'reviews' ) === array( $two[0]['pillar'], $two[1]['pillar'] ), 'after 14:00 the plan yields the daily minimum, news then reviews' );
$three = Lunara_Journal_Cadence::plan( $evening, 0 );
cadence_check( Lunara_Journal_Cadence::DAILY_MAX === count( $three ) && 'oscars' === $three[2]['pillar'], 'after 19:00 the plan yields three slots and ends on the Oscars' );
$rest = Lunara_Journal_Cadence::plan( $evening, 2 );
cadence_check( 1 === count( $rest ) && 'oscars' === $rest[0]['pillar'] && 2 === $rest[0]['index'], 'two drafts already filed leave only the evening Oscars slot' );
cadence_check( array() === Lunara_Journal_Cadence::plan( $evening, 3 ), 'three drafts close the day' );

$GLOBALS['options'] = array();
cadence_check( true === Lunara_Journal_Cadence::hold_is_on(), 'the publication hold is on when the option has never been saved' );
cadence_check( false !== strpos( Lunara_Journal_Cadence::release_note(), 'set that option to 0' ) && false !== strpos( Lunara_Journal_Cadence::release_note(), 'never publishes' ), 'the release note says what flips drafts live and that cadence itself does not publish' );
update_option( Lunara_Journal_Cadence::OPTION_HOLD, '0' );
cadence_check( false === Lunara_Journal_Cadence::hold_is_on(), 'storing 0 lifts the hold' );
update_option( Lunara_Journal_Cadence::OPTION_HOLD, '1' );
cadence_check( true === Lunara_Journal_Cadence::hold_is_on(), 'storing 1 puts the hold back' );

$schedules = Lunara_Journal_Cadence::register_interval( array() );
cadence_check( isset( $schedules['lunara_every_4_hours']['interval'] ) && 14400 === $schedules['lunara_every_4_hours']['interval'], 'the cadence worker repeats every four hours so the three slots can be reached' );
$GLOBALS['events'] = array();
cadence_check( true === Lunara_Journal_Cadence::ensure_schedule() && 'lunara_every_4_hours' === $GLOBALS['events'][ Lunara_Journal_Cadence::HOOK ]['interval'], 'a missing cadence event is scheduled on the four-hour interval' );

function cadence_post( $id, $title ) {
	$post = (object) array( 'ID' => $id, 'post_type' => 'journal', 'post_status' => 'draft', 'post_title' => $title );
	$GLOBALS['posts'][ $id ] = $post;
	$revision = hash( 'sha256', $title );
	update_post_meta( $id, Lunara_Journal_Publishing::META, array(
		'revision' => $revision,
		'approved_by' => 7,
		'approved_at' => time(),
		'expires' => time() + DAY_IN_SECONDS,
		'publish_at' => 0,
		'evergreen' => false,
	) );
	update_post_meta( $id, Lunara_Journal_Cadence::META, array( 'pillar' => 'news', 'hold' => true ) );
}

$GLOBALS['posts'] = array();
$GLOBALS['meta'] = array();
$GLOBALS['can_publish'] = true;
$GLOBALS['publish_attempts'] = 0;
$GLOBALS['delivery_now'] = $afternoon;
cadence_post( 11, 'Held news draft' );
cadence_check( true === Lunara_Journal_Cadence::blocks_delivery( 11 ), 'a cadence draft is blocked while the hold is on' );
Lunara_Journal_Publishing::tick();
cadence_check( 0 === $GLOBALS['publish_attempts'] && 'draft' === get_post_status( 11 ), 'the delivery worker leaves a held cadence draft unpublished' );

update_option( Lunara_Journal_Cadence::OPTION_HOLD, '0' );
$GLOBALS['posts'] = array();
$GLOBALS['meta'] = array();
$GLOBALS['publish_attempts'] = 0;
cadence_post( 21, 'News draft one' );
cadence_post( 22, 'Review draft two' );
cadence_post( 23, 'Oscars draft three' );
cadence_post( 24, 'Fourth draft stays' );
Lunara_Journal_Publishing::tick();
$published = 0;
foreach ( array( 21, 22, 23, 24 ) as $id ) {
	if ( 'publish' === get_post_status( $id ) ) {
		$published++;
	}
}
cadence_check( 3 === $published && 'draft' === get_post_status( 24 ), 'with the hold off, approved cadence drafts publish up to three in a day and the fourth stays a draft' );
cadence_check( 3 === $GLOBALS['publish_attempts'], 'the worker asks the existing Journal Desk publisher, not a new write path' );

fwrite( STDOUT, "Journal cadence runtime passed: {$GLOBALS['checks']} checks.\n" );

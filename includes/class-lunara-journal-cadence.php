<?php
/**
 * Daily Journal cadence: plan 2–3 draft slots across news, reviews, and the Oscars.
 *
 * Dispatch composes the drafts. This class owns the clock, the daily cap, and
 * the publication hold. The hold is on unless `lunara_journal_cadence_hold` is
 * stored as "0". Turning the hold off does not publish anything. An editor
 * still approves each draft in the Journal Desk. While the hold is on, even
 * an approval on a cadence draft is ignored.
 *
 * Voice sign-off is still open. The local composer in Dispatch is a scaffold
 * next to Lunara_Dispatch_Voice, not Dalton's finished writing.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lunara_Journal_Cadence {
	const DAILY_MIN   = 2;
	const DAILY_MAX   = 3;
	const OPTION_HOLD = 'lunara_journal_cadence_hold';
	const META        = '_lunara_journal_cadence';
	const HOOK        = 'lunara_journal_cadence_tick';

	/**
	 * Site-local slots. News in the morning, a review after lunch, the Oscars
	 * in the evening. A run files every slot whose time has passed, and stops
	 * at three drafts for the day.
	 *
	 * @var array<int,array{pillar:string,hour:int,minute:int}>
	 */
	const SLOTS = array(
		array( 'pillar' => 'news', 'hour' => 9, 'minute' => 0 ),
		array( 'pillar' => 'reviews', 'hour' => 14, 'minute' => 0 ),
		array( 'pillar' => 'oscars', 'hour' => 19, 'minute' => 0 ),
	);

	public static function bootstrap() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_interval' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
	}

	public static function register_interval( $schedules ) {
		$schedules = is_array( $schedules ) ? $schedules : array();
		if ( ! isset( $schedules['lunara_every_4_hours'] ) ) {
			$interval = defined( 'HOUR_IN_SECONDS' ) ? 4 * HOUR_IN_SECONDS : 14400;
			$schedules['lunara_every_4_hours'] = array(
				'interval' => $interval,
				'display'  => 'Every 4 Hours (Lunara Film Journal cadence)',
			);
		}
		return $schedules;
	}

	/**
	 * Keep a worker on the clock. A missing event comes back on the next load.
	 * The worker only asks Dispatch to file drafts.
	 */
	public static function ensure_schedule() {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return false;
		}
		if ( wp_next_scheduled( self::HOOK ) ) {
			return true;
		}
		return wp_schedule_event( time() + 60, 'lunara_every_4_hours', self::HOOK );
	}

	public static function tick() {
		if ( class_exists( 'Lunara_Dispatch_Cadence' ) ) {
			Lunara_Dispatch_Cadence::fill_due_slots();
		}
	}

	/**
	 * Slots still open today. `$filed` is how many automation drafts already
	 * exist for this local day, including Dispatch's own model drafts.
	 *
	 * @param DateTimeImmutable $now   Current time in the site timezone.
	 * @param int               $filed Drafts already filed today.
	 * @return array<int,array{index:int,pillar:string,at:string}>
	 */
	public static function plan( DateTimeImmutable $now, $filed ) {
		$filed = max( 0, (int) $filed );
		$want  = 0;
		foreach ( self::SLOTS as $index => $slot ) {
			$due = $now->setTime( (int) $slot['hour'], (int) $slot['minute'], 0 );
			if ( $now >= $due ) {
				$want = $index + 1;
			}
		}
		$want = min( self::DAILY_MAX, $want );
		$open = max( 0, $want - min( $filed, self::DAILY_MAX ) );
		$out  = array();
		for ( $n = 0; $n < $open; $n++ ) {
			$index = $filed + $n;
			if ( ! isset( self::SLOTS[ $index ] ) ) {
				break;
			}
			$slot  = self::SLOTS[ $index ];
			$out[] = array(
				'index'  => $index,
				'pillar' => $slot['pillar'],
				'at'     => $now->setTime( (int) $slot['hour'], (int) $slot['minute'], 0 )->format( 'c' ),
			);
		}
		return $out;
	}

	/**
	 * Hold is the default. Only the stored value "0", "off", or "false" lifts it.
	 */
	public static function hold_is_on() {
		$value = function_exists( 'get_option' ) ? get_option( self::OPTION_HOLD, '1' ) : '1';
		return ! in_array( (string) $value, array( '0', 'off', 'false' ), true );
	}

	public static function is_cadence_post( $post_id ) {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return false;
		}
		$meta = get_post_meta( (int) $post_id, self::META, true );
		return is_array( $meta ) && ! empty( $meta['pillar'] );
	}

	/**
	 * While the hold is on, the delivery worker skips cadence drafts even if
	 * an editor has approved them. Other Journal reserve items are untouched.
	 */
	public static function blocks_delivery( $post_id ) {
		return self::hold_is_on() && self::is_cadence_post( $post_id );
	}

	/**
	 * What flips a cadence draft live, after the voice is signed off.
	 */
	public static function release_note() {
		return 'Cadence drafts stay unpublished while lunara_journal_cadence_hold is on, which is the default. After the voice is signed off, set that option to 0. An editor then approves each draft in the Journal Desk delivery controls. This cadence never publishes a post by itself.';
	}
}

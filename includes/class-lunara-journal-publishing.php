<?php
/** An explicitly approved reserve and timed delivery for the private Journal Desk. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Lunara_Journal_Publishing {
    const META = '_lunara_journal_delivery';
    const HOOK = 'lunara_journal_delivery_tick';
    const DUE_HOOK = 'lunara_journal_delivery_due';

    public static function bootstrap() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
        add_action( self::HOOK, array( __CLASS__, 'tick' ) );
        add_action( self::DUE_HOOK, array( __CLASS__, 'tick' ) );
    }

    public static function register_routes() {
        register_rest_route( 'lunara/v1', '/journal/app/publishing', array(
            'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_status' ),
            'permission_callback' => array( 'Lunara_Journal_Desk_API', 'settings_permissions_check' ),
        ) );
        foreach ( array( 'reserve', 'schedule' ) as $action ) {
            register_rest_route( 'lunara/v1', '/journal/app/drafts/(?P<id>\d+)/' . $action, array(
                'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_' . $action ),
                'permission_callback' => array( 'Lunara_Journal_Desk_API', 'publish_permissions_check' ),
                'args' => array( 'id' => array( 'sanitize_callback' => 'absint', 'validate_callback' => array( 'Lunara_Journal_Foundation', 'rest_validate_positive_id' ) ) ),
            ) );
        }
    }

    /** Install a worker only after an administrator has approved delivery content. */
    public static function ensure_schedule() {
        if ( get_option( 'lunara_journal_delivery_active', false ) && ! wp_next_scheduled( self::HOOK ) ) {
            return wp_schedule_event( time() + 60, 'hourly', self::HOOK );
        }
        return true;
    }

    public static function rest_status( WP_REST_Request $request ) {
        $allowed = Lunara_Journal_Desk_API::settings_permissions_check( $request );
        return is_wp_error( $allowed ) ? $allowed : self::response( self::status() );
    }

    public static function status() {
        $now = new DateTimeImmutable( 'now', wp_timezone() );
        $today = $now->format( 'Y-m-d' );
        $published = get_posts( array( 'post_type' => 'journal', 'post_status' => 'publish', 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
        $today_query = new WP_Query( array( 'post_type' => 'journal', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'date_query' => array( array( 'after' => $today . ' 00:00:00', 'before' => $today . ' 23:59:59', 'inclusive' => true ) ) ) );
        $scheduled = array(); $reserve = array(); $attention = array();
        foreach ( self::approved_posts() as $post ) {
            $approval = get_post_meta( $post->ID, self::META, true );
            $problem = self::approval_problem( $post, $approval );
            $item = array( 'id' => $post->ID, 'title' => get_the_title( $post ), 'status' => $post->post_status, 'url' => get_edit_post_link( $post->ID, 'raw' ), 'revision' => Lunara_Journal_Desk_API::revision_for_post( $post->ID ), 'expires_at' => gmdate( 'c', $approval['expires'] ?? time() ), 'evergreen' => ! empty( $approval['evergreen'] ) );
            if ( $problem ) { $item['message'] = $problem; $attention[] = $item; continue; }
            if ( ! empty( $approval['publish_at'] ) ) { $item['publish_at'] = gmdate( 'c', $approval['publish_at'] ); $scheduled[] = $item; }
            else { $reserve[] = $item; }
        }
        usort( $scheduled, static function ( $a, $b ) { return strcmp( $a['publish_at'], $b['publish_at'] ); } );
        $last = ! empty( $published ) ? $published[0] : null;
        $config = Lunara_Journal_Control_Plane::get_active_config();
        return array(
            'timezone' => wp_timezone()->getName(), 'today' => $today, 'published_today' => (int) $today_query->found_posts,
            'last_published' => $last ? array( 'id' => $last->ID, 'title' => get_the_title( $last ), 'date' => get_post_time( 'c', true, $last ), 'url' => get_permalink( $last ) ) : null,
            'scheduled' => $scheduled, 'reserve' => $reserve, 'attention' => $attention,
            'target_per_day' => 1, 'reserve_target' => 7, 'ready_count' => count( $reserve ), 'coverage_days' => self::coverage_days( $now, (int) $today_query->found_posts, $reserve, $scheduled ),
            'cron' => array( 'disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, 'next_event' => wp_next_scheduled( self::HOOK ), 'requires_server_trigger' => true ),
            'publication' => array( 'enabled' => ! empty( $config['chatgpt']['may_publish'] ), 'can_publish' => current_user_can( 'publish_posts' ) ),
            'last_delivery' => get_option( 'lunara_journal_delivery_result', null ),
        );
    }

    public static function rest_reserve( WP_REST_Request $request ) { return self::approve( $request, false ); }
    public static function rest_schedule( WP_REST_Request $request ) { return self::approve( $request, true ); }

    private static function approve( WP_REST_Request $request, $scheduled ) {
        $allowed = Lunara_Journal_Desk_API::publish_permissions_check( $request );
        if ( is_wp_error( $allowed ) ) { return $allowed; }
        $body = $request->get_json_params();
        $keys = $scheduled ? array( 'expected_revision', 'publish_at', 'evergreen' ) : array( 'expected_revision', 'approved', 'evergreen' );
        if ( ! is_array( $body ) || array_diff( array_keys( $body ), $keys ) || ( isset( $body['evergreen'] ) && ! is_bool( $body['evergreen'] ) ) ) {
            return self::error( 'lunara_delivery_body', 'Use the current draft revision and the delivery controls shown in Journal Desk.', 400 );
        }
        if ( ! $scheduled && ( ! isset( $body['approved'] ) || ! is_bool( $body['approved'] ) ) ) {
            return self::error( 'lunara_delivery_approval', 'Explicitly approve this exact article before adding it to the publication reserve.', 400 );
        }
        return Lunara_Journal_Desk_API::with_post_lock( absint( $request['id'] ), static function () use ( $request, $body, $scheduled ) {
            $post = Lunara_Journal_Desk_API::check_draft_revision( $request );
            if ( is_wp_error( $post ) ) { return $post; }
            if ( ! $scheduled && false === $body['approved'] ) {
                delete_post_meta( $post->ID, self::META );
                return self::response( array( 'id' => $post->ID, 'reserved' => false, 'revision' => Lunara_Journal_Desk_API::revision_for_post( $post->ID ) ) );
            }
            $config = Lunara_Journal_Control_Plane::get_active_config();
            if ( empty( $config['chatgpt']['may_publish'] ) ) { return self::error( 'lunara_publish_disabled', 'Enable publishing in the Journal Control Plane before approving automatic delivery.', 403 ); }
            $validation = Lunara_Journal_Validator::validate_post( $post->ID, $config );
            if ( empty( $validation['valid'] ) ) { return self::error( 'lunara_delivery_invalid', 'Resolve the article and image checks before approving delivery.', 409, array( 'validation' => $validation ) ); }
            $evergreen = ! empty( $body['evergreen'] );
            $expires = time() + ( $evergreen ? 30 * DAY_IN_SECONDS : DAY_IN_SECONDS );
            $publish_at = 0;
            if ( $scheduled ) {
                $publish_at = self::parse_publish_at( $body['publish_at'] ?? null );
                if ( is_wp_error( $publish_at ) ) { return $publish_at; }
                if ( $publish_at > $expires ) { return self::error( 'lunara_delivery_stale', 'Time-sensitive stories can be scheduled within 24 hours. Mark a reviewed evergreen article for a later date.', 400 ); }
            }
            $previous = get_post_meta( $post->ID, self::META, true );
            $revision = Lunara_Journal_Desk_API::revision_for_post( $post->ID );
            if ( $scheduled && is_array( $previous ) && ( $previous['revision'] ?? '' ) === $revision && (int) ( $previous['publish_at'] ?? 0 ) === $publish_at && (bool) ( $previous['evergreen'] ?? false ) === $evergreen && empty( self::approval_problem( $post, $previous ) ) ) {
                return self::response( array( 'id' => $post->ID, 'reserved' => false, 'scheduled' => true, 'publish_at' => gmdate( 'c', $publish_at ), 'expires_at' => gmdate( 'c', $previous['expires'] ), 'revision' => $revision ) );
            }
            $approval = array( 'revision' => $revision, 'approved_by' => get_current_user_id(), 'approved_at' => time(), 'expires' => $expires, 'publish_at' => $publish_at, 'evergreen' => $evergreen );
            update_post_meta( $post->ID, self::META, $approval );
            if ( get_post_meta( $post->ID, self::META, true ) !== $approval ) { return self::error( 'lunara_delivery_save', 'Delivery approval could not be confirmed. Reload the saved article before trying again.', 409 ); }
            update_option( 'lunara_journal_delivery_active', true, false );
            $worker = self::ensure_schedule();
            if ( is_wp_error( $worker ) || false === $worker ) {
                self::restore_approval( $post->ID, $previous );
                return self::error( 'lunara_delivery_worker_failed', 'WordPress could not start the delivery worker. The article remains an unpublished draft.', 503 );
            }
            if ( $scheduled ) {
                $queued = wp_schedule_single_event( $publish_at, self::DUE_HOOK, array( $post->ID ), true );
                if ( is_wp_error( $queued ) || false === $queued ) {
                    self::restore_approval( $post->ID, $previous );
                    return self::error( 'lunara_delivery_schedule_failed', 'WordPress could not queue the delivery. The article remains an unpublished draft.', 503 );
                }
            }
            Lunara_Journal_Foundation::record_bridge_log_entry( $post->ID, $scheduled ? 'schedule_approved' : 'reserve_approved', array( 'approved_by' => $approval['approved_by'], 'publish_at' => $publish_at, 'expires' => $expires ) );
            return self::response( array( 'id' => $post->ID, 'reserved' => ! $scheduled, 'scheduled' => $scheduled, 'publish_at' => $publish_at ? gmdate( 'c', $publish_at ) : null, 'expires_at' => gmdate( 'c', $expires ), 'revision' => $approval['revision'] ) );
        } );
    }

    public static function parse_publish_at( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/D', $value ) ) {
            return self::error( 'lunara_delivery_date', 'Choose a future date and time with its timezone.', 400 );
        }
        try { $parsed = new DateTimeImmutable( $value ); } catch ( Exception $error ) { return self::error( 'lunara_delivery_date', 'Choose a valid publication date.', 400 ); }
        $errors = DateTimeImmutable::getLastErrors();
        if ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) { return self::error( 'lunara_delivery_date', 'Choose a valid publication date.', 400 ); }
        $timestamp = $parsed->getTimestamp();
        return $timestamp > time() + 60 && $timestamp <= time() + 30 * DAY_IN_SECONDS ? $timestamp : self::error( 'lunara_delivery_date', 'Schedule between one minute and 30 days from now.', 400 );
    }

    private static function restore_approval( $post_id, $previous ) {
        if ( is_array( $previous ) && $previous ) { update_post_meta( $post_id, self::META, $previous ); }
        else { delete_post_meta( $post_id, self::META ); }
    }

    /** Count consecutive covered calendar days, respecting every approval's expiry. */
    public static function coverage_days( DateTimeImmutable $now, $published_today, array $reserve, array $scheduled ) {
        $count = 0;
        for ( $offset = 0; $offset < 30; $offset++ ) {
            $day = $now->setTime( 10, 0 )->modify( '+' . $offset . ' days' );
            if ( 0 === $offset && $published_today ) { $count++; continue; }
            $covered = false;
            foreach ( $scheduled as $item ) {
                $due = ( new DateTimeImmutable( $item['publish_at'] ) )->setTimezone( $now->getTimezone() );
                if ( $due->format( 'Y-m-d' ) === $day->format( 'Y-m-d' ) ) { $covered = true; break; }
            }
            if ( ! $covered ) {
                foreach ( $reserve as $index => $item ) {
                    if ( strtotime( $item['expires_at'] ) >= max( $now->getTimestamp(), $day->getTimestamp() ) ) { unset( $reserve[$index] ); $covered = true; break; }
                }
            }
            if ( ! $covered ) { break; }
            $count++;
        }
        return $count;
    }

    /** Never select an unapproved draft. Approval expires and is invalidated by any article edit. */
    public static function approval_problem( $post, $approval ) {
        if ( ! is_array( $approval ) || empty( $approval['approved_by'] ) || empty( $approval['revision'] ) ) { return 'Review and approve this article for delivery.'; }
        if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'private' ), true ) ) { return 'This article is no longer an unpublished draft.'; }
        if ( empty( $approval['expires'] ) || $approval['expires'] < time() ) { return 'Publication approval expired. Check the story and approve it again.'; }
        if ( ! hash_equals( $approval['revision'], Lunara_Journal_Desk_API::revision_for_post( $post->ID ) ) ) { return 'The article changed after approval. Review the saved version again.'; }
        if ( ! user_can( (int) $approval['approved_by'], 'publish_posts' ) || ! user_can( (int) $approval['approved_by'], 'edit_post', $post->ID ) ) { return 'The approving editor no longer has publication access.'; }
        if ( in_array( get_post_meta( $post->ID, 'journal_bridge_locked', true ), array( true, 1, '1', 'true', 'yes', 'on' ), true ) ) { return 'This article is locked against publication.'; }
        return '';
    }

    private static function approved_posts() {
        return get_posts( array( 'post_type' => 'journal', 'post_status' => array( 'draft', 'pending', 'private' ), 'posts_per_page' => 100, 'meta_key' => self::META, 'orderby' => 'modified', 'order' => 'ASC' ) );
    }

    public static function tick() {
        return Lunara_Journal_Desk_API::with_delivery_lock( static function () {
            $now = new DateTimeImmutable( 'now', wp_timezone() );
            $posts = self::approved_posts();
            usort( $posts, static function ( $a, $b ) {
                $left = get_post_meta( $a->ID, self::META, true ); $right = get_post_meta( $b->ID, self::META, true );
                return ( (int) ( $left['publish_at'] ?? PHP_INT_MAX ) ?: PHP_INT_MAX ) <=> ( (int) ( $right['publish_at'] ?? PHP_INT_MAX ) ?: PHP_INT_MAX );
            } );
            foreach ( $posts as $post ) {
                $approval = get_post_meta( $post->ID, self::META, true );
                if ( self::approval_problem( $post, $approval ) ) { continue; }
                if ( ! empty( $approval['publish_at'] ) ) {
                    if ( $approval['publish_at'] > time() ) { continue; }
                } elseif ( (int) $now->format( 'G' ) < 10 || self::has_published_today( $now ) ) { continue; }
                $result = self::deliver( $post->ID );
                update_option( 'lunara_journal_delivery_result', array( 'at' => gmdate( 'c' ), 'id' => $post->ID, 'published' => ! is_wp_error( $result ), 'message' => is_wp_error( $result ) ? $result->get_error_message() : 'Publication confirmed by WordPress.' ), false );
                if ( empty( $approval['publish_at'] ) && ! is_wp_error( $result ) ) { break; }
            }
        } );
    }

    private static function has_published_today( DateTimeImmutable $now ) {
        return (bool) get_posts( array( 'post_type' => 'journal', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'date_query' => array( array( 'after' => $now->format( 'Y-m-d' ) . ' 00:00:00', 'before' => $now->format( 'Y-m-d' ) . ' 23:59:59', 'inclusive' => true ) ) ) );
    }

    private static function deliver( $post_id ) {
        return Lunara_Journal_Desk_API::with_post_lock( $post_id, static function () use ( $post_id ) {
            clean_post_cache( $post_id ); $post = get_post( $post_id );
            if ( ! $post || 'journal' !== $post->post_type ) { return self::error( 'lunara_delivery_missing', 'Journal draft could not be found.', 404 ); }
            $approval = get_post_meta( $post_id, self::META, true );
            $problem = self::approval_problem( $post, $approval );
            if ( $problem ) { return self::error( 'lunara_delivery_changed', $problem, 409 ); }
            $request = new WP_REST_Request( 'POST', '/lunara/v1/journal/desk/drafts/' . $post_id . '/publish' );
            $request->set_param( 'id', $post_id ); $request->set_header( 'Content-Type', 'application/json' );
            $request->set_body( wp_json_encode( array( 'confirm_publish_now' => true ) ) );
            $result = Lunara_Journal_Fast_Desk::rest_publish_draft( $request );
            if ( is_wp_error( $result ) ) { return $result; }
            $data = rest_ensure_response( $result )->get_data();
            if ( empty( $data['published'] ) || 'publish' !== get_post_status( $post_id ) ) { return self::error( 'lunara_delivery_unconfirmed', 'Publication could not be confirmed. Inspect the article before retrying.', 409 ); }
            delete_post_meta( $post_id, self::META );
            update_post_meta( $post_id, '_lunara_journal_delivery_approved_by', $approval['approved_by'] );
            return $result;
        } );
    }

    private static function response( array $data ) { $result = rest_ensure_response( $data ); $result->header( 'Cache-Control', 'private, no-store, max-age=0' ); return $result; }
    private static function error( $code, $message, $status, array $extra = array() ) { return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) ); }
}

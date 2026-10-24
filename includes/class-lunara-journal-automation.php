<?php
/**
 * Restricted automation bridge for IFTTT Pro+ and other transport services.
 *
 * WordPress remains authoritative. This module accepts only private editorial
 * signals, queues the existing asynchronous Dispatch runner, and emits a small
 * allowlist of notification events. It never publishes or changes public
 * content.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Journal_Automation {
    const POST_TYPE               = 'lunara_signal';
    const REST_NAMESPACE          = 'lunara/v1';
    const OPTION_ENABLED          = 'lunara_journal_automation_enabled';
    const OPTION_HISTORY          = 'lunara_journal_automation_history';
    const META_EVENT_ID           = '_lunara_automation_event_id';
    const META_TYPE               = '_lunara_automation_type';
    const META_STATUS             = '_lunara_automation_status';
    const META_SOURCE_URL         = '_lunara_automation_source_url';
    const META_FILM_TITLE         = '_lunara_automation_film_title';
    const META_RECEIVED_AT        = '_lunara_automation_received_at';
    const META_DISPATCH_OUTCOME   = '_lunara_automation_dispatch_outcome';
    const META_DISPATCHED_AT      = '_lunara_automation_dispatched_at';
    const META_DISPATCH_RUN_ID    = '_lunara_automation_dispatch_run_id';
    const META_DISPATCH_POST_IDS  = '_lunara_automation_dispatch_post_ids';
    const META_ATTENTION_SIGNATURE = '_lunara_automation_last_attention_signature';
    const OUTBOUND_CRON_HOOK      = 'lunara_journal_automation_send_event';
    const CAPTURE_LOCK_PREFIX     = 'lunara_journal_automation_capture_lock_';
    const CAPTURE_LOCK_TTL        = 120;
    const HISTORY_LIMIT           = 100;
    const INBOX_LIMIT             = 50;
    const NOTE_LIMIT              = 5000;

    /** Journal posts whose validation-origin alert must settle after image work. */
    private static $pending_validation_attention = array();

    /** Prevent validation persisted during settlement from re-queuing itself. */
    private static $settling_validation_attention = false;

    public static function bootstrap() {
        add_action( 'init', array( __CLASS__, 'register_inbox_post_type' ), 7 );
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
        add_action( self::OUTBOUND_CRON_HOOK, array( __CLASS__, 'send_scheduled_event' ), 10, 2 );
        add_action( 'update_option_lunara_dispatch_last_run_report', array( __CLASS__, 'handle_dispatch_report_update' ), 30, 3 );
        add_action( 'added_post_meta', array( __CLASS__, 'handle_validation_meta_change' ), 30, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'handle_validation_meta_change' ), 30, 4 );
        add_action( 'lunara_journal_validation_persisted', array( __CLASS__, 'handle_validation_result' ), 30, 2 );
        add_action( 'shutdown', array( __CLASS__, 'settle_validation_attention' ), 30 );
        add_action( 'admin_post_lunara_journal_automation_morning_desk', array( __CLASS__, 'admin_send_morning_desk' ) );
        add_action( 'admin_post_lunara_journal_automation_test', array( __CLASS__, 'admin_send_test' ) );
        add_action( 'admin_post_lunara_journal_automation_update_signal', array( __CLASS__, 'admin_update_signal' ) );
    }

    public static function register_inbox_post_type() {
        register_post_type(
            self::POST_TYPE,
            array(
                'labels' => array(
                    'name'          => 'Automation Inbox',
                    'singular_name' => 'Automation Signal',
                    'edit_item'     => 'Edit Automation Signal',
                ),
                'public'              => false,
                'publicly_queryable'  => false,
                'exclude_from_search' => true,
                'show_ui'             => true,
                'show_in_menu'        => false,
                'show_in_admin_bar'   => false,
                'show_in_nav_menus'   => false,
                'show_in_rest'        => false,
                'rewrite'             => false,
                'query_var'           => false,
                'capability_type'     => 'post',
                'map_meta_cap'        => true,
                'supports'            => array( 'title', 'editor', 'custom-fields', 'revisions' ),
            )
        );
    }

    public static function register_rest_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            '/journal/automation/status',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'rest_status' ),
                'permission_callback' => array( 'Lunara_Journal_Foundation', 'rest_permissions_check' ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/journal/automation/inbox',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'rest_list_inbox' ),
                'permission_callback' => array( 'Lunara_Journal_Foundation', 'rest_permissions_check' ),
                'args'                => array(
                    'per_page' => array(
                        'type'    => 'integer',
                        'default' => 20,
                        'minimum' => 1,
                        'maximum' => self::INBOX_LIMIT,
                    ),
                    'type' => array(
                        'type'              => 'string',
                        'default'           => '',
                        'validate_callback' => array( __CLASS__, 'rest_validate_optional_capture_type' ),
                    ),
                    'status' => array(
                        'type'              => 'string',
                        'default'           => '',
                        'validate_callback' => array( __CLASS__, 'rest_validate_optional_inbox_status' ),
                    ),
                ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/journal/automation/capture',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'rest_capture' ),
                'permission_callback' => array( 'Lunara_Journal_Foundation', 'rest_permissions_check' ),
                'args'                => array(
                    'type' => array(
                        'type'              => 'string',
                        'required'          => true,
                        'validate_callback' => array( __CLASS__, 'rest_validate_capture_type' ),
                    ),
                    'title' => array( 'type' => 'string' ),
                    'note' => array( 'type' => 'string' ),
                    'source_url' => array( 'type' => 'string' ),
                    'film_title' => array( 'type' => 'string' ),
                    'event_id' => array( 'type' => 'string' ),
                    'submitted_at' => array( 'type' => 'string' ),
                ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/journal/automation/run-dispatch',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'rest_run_dispatch' ),
                'permission_callback' => array( 'Lunara_Journal_Foundation', 'rest_permissions_check' ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/journal/automation/morning-desk',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( __CLASS__, 'rest_morning_desk' ),
                'permission_callback' => array( 'Lunara_Journal_Foundation', 'rest_permissions_check' ),
            )
        );
    }

    public static function rest_validate_capture_type( $value, $request = null, $param = null ) {
        unset( $request, $param );
        return in_array( sanitize_key( (string) $value ), array( 'idea', 'source', 'screening' ), true );
    }

    public static function rest_validate_optional_capture_type( $value, $request = null, $param = null ) {
        unset( $request, $param );
        return '' === (string) $value || self::rest_validate_capture_type( $value );
    }

    public static function rest_validate_optional_inbox_status( $value, $request = null, $param = null ) {
        unset( $request, $param );
        return '' === (string) $value || in_array( sanitize_key( (string) $value ), array( 'new', 'triaged', 'archived' ), true );
    }

    public static function rest_status( WP_REST_Request $request ) {
        unset( $request );
        return rest_ensure_response( self::admin_snapshot() );
    }

    public static function rest_list_inbox( WP_REST_Request $request ) {
        $limit  = min( self::INBOX_LIMIT, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
        $type   = sanitize_key( (string) $request->get_param( 'type' ) );
        $status = sanitize_key( (string) $request->get_param( 'status' ) );

        return rest_ensure_response(
            array(
                'items'      => self::get_inbox_items( $limit, $type, $status ),
                'counts'     => self::inbox_counts(),
                'guardrails' => self::guardrails(),
            )
        );
    }

    public static function rest_capture( WP_REST_Request $request ) {
        $type       = sanitize_key( (string) $request->get_param( 'type' ) );
        $title      = self::limited_text( $request->get_param( 'title' ), 180 );
        $note       = self::limited_textarea( $request->get_param( 'note' ), self::NOTE_LIMIT );
        $source_url = self::safe_source_url( $request->get_param( 'source_url' ) );
        $film_title = self::limited_text( $request->get_param( 'film_title' ), 180 );

        if ( 'source' === $type && '' === $source_url ) {
            return new WP_Error( 'lunara_automation_source_required', 'Source Radar requires a valid public source URL.', array( 'status' => 400 ) );
        }
        if ( 'idea' === $type && '' === $title && '' === $note ) {
            return new WP_Error( 'lunara_automation_idea_required', 'Capture Idea requires a title or note.', array( 'status' => 400 ) );
        }
        if ( 'screening' === $type && '' === $film_title && '' === $title && '' === $note ) {
            return new WP_Error( 'lunara_automation_screening_required', 'Screening Follow-Up requires a film title or note.', array( 'status' => 400 ) );
        }

        $normalized = array(
            'type'         => $type,
            'title'        => $title,
            'note'         => $note,
            'source_url'   => $source_url,
            'film_title'   => $film_title,
            'submitted_at' => self::limited_text( $request->get_param( 'submitted_at' ), 80 ),
        );
        $event_id = self::request_event_id( $request, $normalized );
        $lock = self::acquire_capture_lock( $event_id );
        if ( is_wp_error( $lock ) ) {
            return $lock;
        }

        try {
            // Recheck only after the atomic lock is held. This closes the
            // concurrent-retry gap between the first lookup and insertion.
            $existing = self::find_inbox_item_by_event_id( $event_id );
            if ( $existing ) {
                self::append_history( 'capture.duplicate', 'duplicate', array( 'signal_id' => $existing, 'type' => $type ), $event_id );
                return rest_ensure_response(
                    array(
                        'created'    => false,
                        'duplicate'  => true,
                        'signal_id'  => $existing,
                        'event_id'   => $event_id,
                        'guardrails' => self::guardrails(),
                    )
                );
            }

            $post_id = wp_insert_post(
                array(
                    'post_type'    => self::POST_TYPE,
                    'post_status'  => 'draft',
                    'post_title'   => self::capture_title( $normalized ),
                    'post_content' => $note,
                    'post_excerpt' => $source_url,
                    'post_author'  => 0,
                    'meta_input'   => array(
                        self::META_EVENT_ID    => $event_id,
                        self::META_TYPE        => $type,
                        self::META_STATUS      => 'new',
                        self::META_SOURCE_URL  => $source_url,
                        self::META_FILM_TITLE  => $film_title,
                        self::META_RECEIVED_AT => current_time( 'mysql', true ),
                    ),
                ),
                true
            );

            if ( is_wp_error( $post_id ) ) {
                self::append_history( 'capture.failed', 'error', array( 'type' => $type, 'message' => $post_id->get_error_message() ), $event_id );
                return $post_id;
            }

            self::append_history( 'capture.' . $type, 'created', array( 'signal_id' => $post_id, 'title' => get_the_title( $post_id ) ), $event_id );
            do_action( 'lunara_journal_automation_capture_created', $post_id, $normalized );

            return new WP_REST_Response(
                array(
                    'created'    => true,
                    'duplicate'  => false,
                    'signal_id'  => $post_id,
                    'event_id'   => $event_id,
                    'status'     => 'new',
                    'guardrails' => self::guardrails(),
                ),
                201
            );
        } finally {
            self::release_capture_lock( $lock );
        }
    }

    public static function rest_run_dispatch( WP_REST_Request $request ) {
        $event_id = self::request_event_id(
            $request,
            array(
                'action' => 'run_dispatch',
                'window' => gmdate( 'Y-m-d-H-i' ),
            )
        );
        $previous = self::find_history_by_event_id( $event_id, 'dispatch.request' );
        if ( $previous ) {
            return rest_ensure_response(
                array(
                    'queued'     => false,
                    'duplicate'  => true,
                    'event_id'   => $event_id,
                    'message'    => 'This Run Lunara Film request was already accepted.',
                    'guardrails' => self::guardrails(),
                )
            );
        }

        if ( ! class_exists( 'Lunara_Journal_Fast_Desk' ) ) {
            return new WP_Error( 'lunara_automation_foundation_incomplete', 'Fast Journal Desk is unavailable.', array( 'status' => 503 ) );
        }

        $result = Lunara_Journal_Fast_Desk::rest_run_dispatch( $request );
        if ( is_wp_error( $result ) ) {
            self::append_history( 'dispatch.request', 'error', array( 'message' => $result->get_error_message() ), $event_id );
            return $result;
        }

        $data = $result instanceof WP_REST_Response ? $result->get_data() : $result;
        if ( ! is_array( $data ) ) {
            $data = array();
        }
        $data['event_id']   = $event_id;
        $data['duplicate']  = false;
        $data['guardrails'] = self::guardrails();
        self::append_history( 'dispatch.request', 'queued', array( 'message' => isset( $data['message'] ) ? $data['message'] : 'Dispatch queued.' ), $event_id );

        return rest_ensure_response( $data );
    }

    public static function rest_morning_desk( WP_REST_Request $request ) {
        $digest   = self::build_morning_desk();
        $event_id = self::request_event_id( $request, array( 'action' => 'morning_desk', 'date' => current_time( 'Y-m-d' ) ) );
        $previous = self::find_history_by_event_id( $event_id, 'morning_desk.request' );

        if ( $previous ) {
            return rest_ensure_response(
                array(
                    'queued'    => false,
                    'duplicate' => true,
                    'event_id'  => $event_id,
                    'digest'    => $digest,
                )
            );
        }

        $queued = self::queue_outbound_event(
            'lunara_morning_desk',
            array(
                'message' => $digest['message'],
                'link'    => admin_url( 'admin.php?page=lunara-control-desk&tab=automation' ),
                'context' => $digest,
            )
        );

        if ( is_wp_error( $queued ) ) {
            self::append_history( 'morning_desk.request', 'not_sent', array( 'message' => $queued->get_error_message() ), $event_id );
            return new WP_REST_Response(
                array(
                    'queued'    => false,
                    'duplicate' => false,
                    'event_id'  => $event_id,
                    'digest'    => $digest,
                    'notice'    => $queued->get_error_message(),
                ),
                200
            );
        }

        self::append_history( 'morning_desk.request', 'queued', array(), $event_id );
        return new WP_REST_Response(
            array(
                'queued'    => true,
                'duplicate' => false,
                'event_id'  => $event_id,
                'digest'    => $digest,
            ),
            202
        );
    }

    public static function admin_snapshot() {
        $profiles = get_option( Lunara_Journal_Foundation::OPTION_ACCESS_PROFILES, array() );
        $profile  = is_array( $profiles ) && isset( $profiles['ifttt_operator'] ) && is_array( $profiles['ifttt_operator'] )
            ? $profiles['ifttt_operator']
            : array();

        return array(
            'enabled'             => self::is_enabled(),
            'foundation_version'  => defined( 'LUNARA_JOURNAL_FOUNDATION_VERSION' ) ? LUNARA_JOURNAL_FOUNDATION_VERSION : '',
            'ifttt_profile'       => array(
                'configured'  => ! empty( $profile['token_hash'] ),
                'active'      => ! isset( $profile['active'] ) || ! empty( $profile['active'] ),
                'last4'       => isset( $profile['last4'] ) ? sanitize_text_field( (string) $profile['last4'] ) : '',
                'last_used_at'=> isset( $profile['last_used_at'] ) ? sanitize_text_field( (string) $profile['last_used_at'] ) : '',
            ),
            'outbound_configured' => '' !== self::outbound_key(),
            'dispatch'            => self::dispatch_state(),
            'inbox_counts'        => self::inbox_counts(),
            'inbox'               => self::get_inbox_items( 12 ),
            'history'             => array_slice( self::history(), 0, 20 ),
            'endpoints'           => array(
                'status'        => rest_url( self::REST_NAMESPACE . '/journal/automation/status' ),
                'inbox'         => rest_url( self::REST_NAMESPACE . '/journal/automation/inbox' ),
                'capture'       => rest_url( self::REST_NAMESPACE . '/journal/automation/capture' ),
                'run_dispatch'  => rest_url( self::REST_NAMESPACE . '/journal/automation/run-dispatch' ),
                'morning_desk'  => rest_url( self::REST_NAMESPACE . '/journal/automation/morning-desk' ),
            ),
            'workflows'           => array(
                'morning_desk'      => array( 'label' => 'Morning Desk', 'direction' => 'two-way', 'ready' => '' !== self::outbound_key() ),
                'run_lunara'        => array( 'label' => 'Run Lunara Film', 'direction' => 'inbound', 'ready' => ! empty( $profile['token_hash'] ) ),
                'capture_idea'      => array( 'label' => 'Capture Idea', 'direction' => 'inbound', 'ready' => ! empty( $profile['token_hash'] ) ),
                'source_radar'      => array( 'label' => 'Source Radar', 'direction' => 'inbound', 'ready' => ! empty( $profile['token_hash'] ) ),
                'screening_followup'=> array( 'label' => 'Screening Follow-Up', 'direction' => 'inbound', 'ready' => ! empty( $profile['token_hash'] ) ),
                'needs_attention'   => array( 'label' => 'Needs Attention', 'direction' => 'outbound', 'ready' => '' !== self::outbound_key() ),
            ),
            'guardrails'           => self::guardrails(),
        );
    }

    public static function admin_send_morning_desk() {
        self::require_admin_action( 'lunara_journal_automation_morning_desk' );
        $digest = self::build_morning_desk();
        $result = self::queue_outbound_event(
            'lunara_morning_desk',
            array(
                'message' => $digest['message'],
                'link'    => admin_url( 'admin.php?page=lunara-control-desk&tab=automation' ),
                'context' => $digest,
            )
        );
        self::redirect_to_control_desk( is_wp_error( $result ) ? 'morning_not_configured' : 'morning_queued' );
    }

    public static function admin_send_test() {
        self::require_admin_action( 'lunara_journal_automation_test' );
        $result = self::queue_outbound_event(
            'lunara_needs_attention',
            array(
                'message' => 'Lunara Film Journal Automation is connected.',
                'link'    => admin_url( 'admin.php?page=lunara-control-desk&tab=automation' ),
                'context' => array( 'kind' => 'connection_test', 'checked_at' => current_time( 'mysql' ) ),
            )
        );
        self::redirect_to_control_desk( is_wp_error( $result ) ? 'test_not_configured' : 'test_queued' );
    }

    public static function admin_update_signal() {
        self::require_admin_action( 'lunara_journal_automation_update_signal' );
        $post_id = isset( $_POST['signal_id'] ) ? absint( $_POST['signal_id'] ) : 0;
        $status  = isset( $_POST['signal_status'] ) ? sanitize_key( wp_unslash( $_POST['signal_status'] ) ) : '';
        if ( ! $post_id || self::POST_TYPE !== get_post_type( $post_id ) || ! in_array( $status, array( 'new', 'triaged', 'archived' ), true ) ) {
            self::redirect_to_control_desk( 'signal_invalid' );
        }
        update_post_meta( $post_id, self::META_STATUS, $status );
        self::append_history( 'inbox.status', 'updated', array( 'signal_id' => $post_id, 'status' => $status ) );
        self::redirect_to_control_desk( 'signal_updated' );
    }

    public static function handle_dispatch_report_update( $old_value, $value, $option ) {
        unset( $old_value, $option );
        if ( ! is_array( $value ) ) {
            return;
        }

        $success = isset( $value['success'] ) ? (bool) $value['success'] : false;
        $context = array(
            'success'   => $success,
            'message'   => isset( $value['message'] ) ? self::limited_text( $value['message'], 500 ) : '',
            'created'   => isset( $value['created'] ) ? absint( $value['created'] ) : 0,
            'imported'  => isset( $value['imported'] ) ? absint( $value['imported'] ) : 0,
            'timestamp' => isset( $value['timestamp_gmt'] ) ? self::limited_text( $value['timestamp_gmt'], 80 ) : current_time( 'mysql', true ),
        );
        $event_id = 'dispatch-report-' . hash( 'sha256', wp_json_encode( $context ) );
        if ( self::find_history_by_event_id( $event_id, 'dispatch.completed' ) ) {
            return;
        }
        self::append_history( 'dispatch.completed', $success ? 'success' : 'error', $context, $event_id );

        if ( ! $success ) {
            self::queue_outbound_event(
                'lunara_needs_attention',
                array(
                    'message' => 'Lunara Film Dispatch needs attention: ' . ( $context['message'] ? $context['message'] : 'the latest run did not complete successfully.' ),
                    'link'    => admin_url( 'admin.php?page=lunara-control-desk&tab=automation' ),
                    'context' => $context,
                )
            );
        }
    }

    public static function handle_validation_meta_change( $meta_id, $object_id, $meta_key, $meta_value ) {
        unset( $meta_id );
        if ( 'journal_validation_status' !== (string) $meta_key || 'journal' !== get_post_type( $object_id ) ) {
            return;
        }

        self::handle_validation_result( $object_id, $meta_value );
    }

    /**
     * Record every completed deterministic validation, including failed-to-
     * failed writes that WordPress correctly short-circuits as unchanged meta.
     */
    public static function handle_validation_result( $object_id, $status ) {
        $object_id = absint( $object_id );
        if ( ! $object_id || 'journal' !== get_post_type( $object_id ) ) {
            return;
        }

        $status = sanitize_key( (string) $status );
        if ( ! self::validation_status_requires_attention( $status ) ) {
            delete_post_meta( $object_id, self::META_ATTENTION_SIGNATURE );
            return;
        }

        if ( self::$settling_validation_attention ) {
            return;
        }

        self::$pending_validation_attention[ absint( $object_id ) ] = true;
    }

    /**
     * Re-run deterministic validation after shutdown image work and queue only
     * a failure that is still authoritative at the end of the request.
     */
    public static function settle_validation_attention() {
        $post_ids = array_keys( self::$pending_validation_attention );
        self::$pending_validation_attention = array();

        foreach ( $post_ids as $post_id ) {
            $post_id = absint( $post_id );
            if ( ! $post_id || 'journal' !== get_post_type( $post_id ) ) {
                continue;
            }
            if ( ! class_exists( 'Lunara_Journal_Validator' ) || ! class_exists( 'Lunara_Journal_Provenance' ) ) {
                continue;
            }

            $result = null;
            self::$settling_validation_attention = true;
            try {
                $result = Lunara_Journal_Validator::validate_post( $post_id );
                if ( is_array( $result ) ) {
                    Lunara_Journal_Provenance::attach_validation_result( $post_id, $result );
                }
            } finally {
                self::$settling_validation_attention = false;
            }

            if ( ! is_array( $result ) ) {
                continue;
            }

            $status = sanitize_key( (string) get_post_meta( $post_id, 'journal_validation_status', true ) );
            if ( ! self::validation_status_requires_attention( $status ) ) {
                delete_post_meta( $post_id, self::META_ATTENTION_SIGNATURE );
                continue;
            }

            $report    = get_post_meta( $post_id, 'journal_validation_report', true );
            $signature = self::validation_attention_signature( $post_id, $status, $report );
            if ( hash_equals( (string) get_post_meta( $post_id, self::META_ATTENTION_SIGNATURE, true ), $signature ) ) {
                continue;
            }

            $queued = self::queue_outbound_event(
                'lunara_needs_attention',
                array(
                    'message' => sprintf( 'Journal draft "%s" needs attention after validation.', get_the_title( $post_id ) ),
                    'link'    => get_edit_post_link( $post_id, 'raw' ),
                    'context' => array( 'post_id' => $post_id, 'validation_status' => $status ),
                )
            );
            if ( is_wp_error( $queued ) ) {
                delete_post_meta( $post_id, self::META_ATTENTION_SIGNATURE );
                continue;
            }

            update_post_meta( $post_id, self::META_ATTENTION_SIGNATURE, $signature );
        }
    }

    public static function send_scheduled_event( $event, $payload ) {
        $event = sanitize_key( (string) $event );
        if ( ! in_array( $event, self::allowed_outbound_events(), true ) ) {
            self::append_history( 'outbound.refused', 'error', array( 'event' => $event, 'message' => 'Event is not allowlisted.' ) );
            return;
        }

        $payload = is_array( $payload ) ? $payload : array();
        if ( self::validation_attention_is_stale( $event, $payload ) ) {
            self::clear_validation_attention_signature( $event, $payload );
            self::append_history(
                'outbound.skipped',
                'validation_settled',
                array(
                    'event'   => $event,
                    'post_id' => absint( $payload['context']['post_id'] ),
                )
            );
            return;
        }

        $key = self::outbound_key();
        if ( '' === $key || ! self::is_enabled() ) {
            self::clear_validation_attention_signature( $event, $payload );
            self::append_history( 'outbound.skipped', 'not_configured', array( 'event' => $event ) );
            return;
        }

        $body = array(
            'value1' => self::limited_textarea( isset( $payload['message'] ) ? $payload['message'] : '', 1000 ),
            'value2' => self::safe_internal_or_public_url( isset( $payload['link'] ) ? $payload['link'] : '' ),
            'value3' => self::limited_textarea( wp_json_encode( isset( $payload['context'] ) ? self::sanitize_context( $payload['context'] ) : array() ), 1000 ),
        );
        $url = 'https://maker.ifttt.com/trigger/' . rawurlencode( $event ) . '/with/key/' . rawurlencode( $key );
        $response = wp_safe_remote_post(
            $url,
            array(
                'timeout'     => 8,
                'redirection' => 0,
                'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
                'body'        => wp_json_encode( $body ),
            )
        );

        if ( is_wp_error( $response ) ) {
            self::clear_validation_attention_signature( $event, $payload );
            self::append_history( 'outbound.' . $event, 'error', array( 'message' => $response->get_error_message() ) );
            return;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            self::clear_validation_attention_signature( $event, $payload );
        }
        self::append_history( 'outbound.' . $event, $code >= 200 && $code < 300 ? 'sent' : 'error', array( 'http_code' => $code ) );
    }

    private static function queue_outbound_event( $event, array $payload ) {
        $event = sanitize_key( (string) $event );
        if ( ! self::is_enabled() ) {
            return new WP_Error( 'lunara_automation_disabled', 'Journal Automation is disabled.' );
        }
        if ( ! in_array( $event, self::allowed_outbound_events(), true ) ) {
            return new WP_Error( 'lunara_automation_event_forbidden', 'This outbound event is not allowed.' );
        }
        if ( '' === self::outbound_key() ) {
            return new WP_Error( 'lunara_automation_ifttt_key_missing', 'Add LUNARA_IFTTT_WEBHOOK_KEY to the deployment configuration before sending IFTTT notifications.' );
        }

        $args = array( $event, self::sanitize_context( $payload ) );
        if ( ! wp_next_scheduled( self::OUTBOUND_CRON_HOOK, $args ) ) {
            $scheduled = wp_schedule_single_event( time() + 5, self::OUTBOUND_CRON_HOOK, $args, true );
            if ( is_wp_error( $scheduled ) ) {
                return $scheduled;
            }
        }
        self::append_history( 'outbound.queued', 'queued', array( 'event' => $event ) );
        return array( 'queued' => true, 'event' => $event );
    }

    private static function allowed_outbound_events() {
        return array( 'lunara_morning_desk', 'lunara_needs_attention' );
    }

    private static function validation_status_requires_attention( $status ) {
        return in_array( sanitize_key( (string) $status ), array( 'failed', 'errors', 'invalid' ), true );
    }

    private static function validation_attention_signature( $post_id, $status, $report ) {
        if ( is_string( $report ) ) {
            $decoded = json_decode( $report, true );
            if ( is_array( $decoded ) ) {
                $report = $decoded;
            }
        }

        if ( is_array( $report ) ) {
            $stable_report = array(
                'errors'   => self::stable_validation_messages( $report['errors'] ?? array() ),
                'warnings' => self::stable_validation_messages( $report['warnings'] ?? array() ),
            );
        } else {
            $stable_report = self::limited_textarea( maybe_serialize( $report ), 4000 );
        }

        return hash( 'sha256', absint( $post_id ) . '|' . sanitize_key( (string) $status ) . '|' . wp_json_encode( $stable_report ) );
    }

    private static function stable_validation_messages( $messages ) {
        $stable = array();
        foreach ( is_array( $messages ) ? $messages : array( $messages ) as $message ) {
            if ( ! is_scalar( $message ) ) {
                continue;
            }
            $message = self::limited_text( $message, 500 );
            if ( '' !== $message ) {
                $stable[] = $message;
            }
        }
        $stable = array_values( array_unique( $stable ) );
        sort( $stable, SORT_STRING );
        return $stable;
    }

    private static function validation_attention_is_stale( $event, array $payload ) {
        if ( 'lunara_needs_attention' !== $event || empty( $payload['context'] ) || ! is_array( $payload['context'] ) ) {
            return false;
        }

        $context = $payload['context'];
        if ( empty( $context['post_id'] ) || ! isset( $context['validation_status'] ) || ! self::validation_status_requires_attention( $context['validation_status'] ) ) {
            return false;
        }

        $current_status = get_post_meta( absint( $context['post_id'] ), 'journal_validation_status', true );
        return ! self::validation_status_requires_attention( $current_status );
    }

    private static function clear_validation_attention_signature( $event, array $payload ) {
        if ( 'lunara_needs_attention' !== $event || empty( $payload['context'] ) || ! is_array( $payload['context'] ) ) {
            return;
        }

        $context = $payload['context'];
        if ( empty( $context['post_id'] ) || ! isset( $context['validation_status'] ) || ! self::validation_status_requires_attention( $context['validation_status'] ) ) {
            return;
        }

        delete_post_meta( absint( $context['post_id'] ), self::META_ATTENTION_SIGNATURE );
    }

    private static function build_morning_desk() {
        $counts = wp_count_posts( 'journal' );
        $working = 0;
        foreach ( array( 'draft', 'pending', 'private' ) as $status ) {
            $working += isset( $counts->$status ) ? absint( $counts->$status ) : 0;
        }

        $attention = 0;
        if ( class_exists( 'Lunara_Journal_Fast_Desk' ) && class_exists( 'WP_REST_Request' ) ) {
            $request = new WP_REST_Request( 'GET', '/lunara/v1/journal/desk' );
            $request->set_param( 'limit', 8 );
            $request->set_param( 'page', 1 );
            $request->set_param( 'refresh', true );
            $response = Lunara_Journal_Fast_Desk::rest_open_desk( $request );
            $data = $response instanceof WP_REST_Response ? $response->get_data() : array();
            if ( is_array( $data ) && isset( $data['attention'] ) && is_array( $data['attention'] ) ) {
                $attention = count( $data['attention'] );
            }
        }

        $inbox    = self::inbox_counts();
        $dispatch = self::dispatch_state();
        $last     = isset( $dispatch['last_run'] ) && is_array( $dispatch['last_run'] ) ? $dispatch['last_run'] : array();
        $last_label = empty( $last ) || null === $last['success']
            ? 'No completed Dispatch report yet'
            : ( $last['success'] ? 'healthy' : 'needs attention' );

        return array(
            'message' => sprintf(
                'Lunara Film Morning Desk: %1$d working Journal drafts, %2$d new inbox signals, and %3$d visible attention items. Dispatch is %4$s.',
                $working,
                isset( $inbox['new'] ) ? absint( $inbox['new'] ) : 0,
                $attention,
                $last_label
            ),
            'journal_working' => $working,
            'inbox_new'       => isset( $inbox['new'] ) ? absint( $inbox['new'] ) : 0,
            'attention'       => $attention,
            'dispatch'        => $dispatch,
            'generated_at'    => current_time( 'mysql' ),
        );
    }

    private static function dispatch_state() {
        $active = class_exists( 'Lunara_Dispatch_Plugin' );
        $report = get_option( 'lunara_dispatch_last_run_report', array() );
        if ( ! is_array( $report ) ) {
            $report = array();
        }
        $runtime = class_exists( 'Lunara_Journal_Control_Plane' ) ? Lunara_Journal_Control_Plane::get_dispatch_runtime_config() : array();
        $runtime = is_array( $runtime ) ? $runtime : array();
        $runtime_provider = isset( $runtime['provider'] ) ? sanitize_key( (string) $runtime['provider'] ) : '';
        $runtime_models = isset( $runtime['models'] ) && is_array( $runtime['models'] ) ? $runtime['models'] : array();
        $runtime_model = isset( $runtime_models[ $runtime_provider ] ) ? self::limited_text( $runtime_models[ $runtime_provider ], 100 ) : '';
        $ai_usage = isset( $report['ai_usage'] ) && is_array( $report['ai_usage'] ) ? $report['ai_usage'] : array();
        $created = isset( $report['created'] ) ? absint( $report['created'] ) : 0;
        $imported = isset( $report['imported'] ) ? absint( $report['imported'] ) : 0;
        $fallback_used = ! empty( $report['ai_fallback_used'] );
        $usage_reported = ! empty( array_intersect( array_keys( $ai_usage ), array( 'input_tokens', 'cached_input_tokens', 'output_tokens', 'estimated_cost_usd' ) ) );
        $estimated_cost = array_key_exists( 'estimated_cost_usd', $ai_usage ) && is_numeric( $ai_usage['estimated_cost_usd'] )
            ? max( 0.0, min( 999999.999999, round( (float) $ai_usage['estimated_cost_usd'], 6 ) ) )
            : null;
        $manual_hook = $active && defined( 'Lunara_Dispatch_Plugin::MANUAL_CRON_HOOK' )
            ? Lunara_Dispatch_Plugin::MANUAL_CRON_HOOK
            : 'lunara_dispatch_manual_requested';
        $source_budget = $active && defined( 'Lunara_Dispatch_Plugin::MAX_ITEMS_PER_RUN' )
            ? min( 100, absint( Lunara_Dispatch_Plugin::MAX_ITEMS_PER_RUN ) )
            : 0;

        return array(
            'active'            => $active,
            'version'           => defined( 'LUNARA_DISPATCH_VERSION' ) ? LUNARA_DISPATCH_VERSION : '',
            'running'           => $active ? self::dispatch_running() : false,
            'manual_run_queued' => $active ? (bool) wp_next_scheduled( $manual_hook ) : false,
            'runtime'           => array(
                'provider'          => $runtime_provider,
                'model'             => $runtime_model,
                'max_output_tokens' => isset( $runtime['max_tokens'] ) ? min( Lunara_Journal_Config_Schema::max_output_tokens_for( $runtime_provider ), absint( $runtime['max_tokens'] ) ) : 0,
                'source_budget'     => $source_budget,
            ),
            'last_run'          => array(
                'timestamp_gmt' => isset( $report['timestamp_gmt'] ) ? self::limited_text( $report['timestamp_gmt'], 80 ) : '',
                'success'       => isset( $report['success'] ) ? (bool) $report['success'] : null,
                'message'       => isset( $report['message'] ) ? self::limited_text( $report['message'], 500 ) : '',
                'created'       => $created,
                'imported'      => $imported,
                'provider'      => isset( $ai_usage['provider'] ) ? sanitize_key( (string) $ai_usage['provider'] ) : '',
                'requested_model' => isset( $ai_usage['requested_model'] ) ? self::limited_text( $ai_usage['requested_model'], 100 ) : '',
                'effective_model' => isset( $ai_usage['effective_model'] ) ? self::limited_text( $ai_usage['effective_model'], 100 ) : '',
                'usage_reported' => $usage_reported,
                'max_output_tokens' => array_key_exists( 'max_output_tokens', $ai_usage ) ? absint( $ai_usage['max_output_tokens'] ) : null,
                'input_tokens' => array_key_exists( 'input_tokens', $ai_usage ) ? absint( $ai_usage['input_tokens'] ) : null,
                'cached_input_tokens' => array_key_exists( 'cached_input_tokens', $ai_usage ) ? absint( $ai_usage['cached_input_tokens'] ) : null,
                'output_tokens' => array_key_exists( 'output_tokens', $ai_usage ) ? absint( $ai_usage['output_tokens'] ) : null,
                'estimated_cost_usd' => $estimated_cost,
                'fallback_used' => $fallback_used,
                'error_code' => isset( $report['ai_error_code'] ) ? sanitize_key( (string) $report['ai_error_code'] ) : '',
                'processed_source_items' => $imported,
                'deferred_source_items' => array_key_exists( 'deferred_source_items', $report ) ? absint( $report['deferred_source_items'] ) : null,
                'source_radar_items' => array_key_exists( 'source_radar_items', $report ) ? absint( $report['source_radar_items'] ) : null,
                'source_packet_drafts' => $fallback_used ? $created : 0,
            ),
        );
    }

    /**
     * Read the atomic Dispatch worker lock, with a legacy transient fallback.
     *
     * Dispatch 3.2.5 stores a JSON lock in wp_options so that acquisition and
     * release can use compare-and-swap semantics. A present option is
     * authoritative, including when it is expired or malformed.
     *
     * @return bool
     */
    private static function dispatch_running() {
        if ( ! class_exists( 'Lunara_Dispatch_Plugin' ) || ! defined( 'Lunara_Dispatch_Plugin::LOCK_KEY' ) ) {
            return false;
        }

        $raw = get_option( Lunara_Dispatch_Plugin::LOCK_KEY, '' );
        if ( is_string( $raw ) && '' !== $raw ) {
            $lock = json_decode( $raw, true );

            return is_array( $lock )
                && ! empty( $lock['owner'] )
                && ! empty( $lock['expires'] )
                && (int) $lock['expires'] >= time();
        }

        return (bool) get_transient( Lunara_Dispatch_Plugin::LOCK_KEY );
    }

    /**
     * Return bounded, private Source Radar inputs for same-process Dispatch use.
     *
     * This is deliberately not a REST route. Dispatch receives only new source
     * signals and never gains general Automation Inbox read authority.
     *
     * @param int $limit Maximum source signals to return.
     * @return array
     */
    public static function dispatch_source_items( $limit = 6 ) {
        $items   = self::get_inbox_items( min( 12, max( 1, absint( $limit ) ) ), 'source', 'new' );
        $sources = array();

        foreach ( $items as $item ) {
            if ( empty( $item['id'] ) || empty( $item['source_url'] ) ) {
                continue;
            }
            $sources[] = array(
                'signal_id'  => absint( $item['id'] ),
                'title'      => self::limited_text( $item['title'], 250 ),
                'note'       => self::limited_textarea( $item['note'], 1800 ),
                'source_url' => self::safe_source_url( $item['source_url'] ),
                'received_at'=> self::limited_text( $item['received_at'], 80 ),
            );
        }

        return $sources;
    }

    /**
     * Mark Source Radar inputs terminal only after Dispatch has safely finished.
     *
     * Retryable failures intentionally never call this method, leaving the
     * private signal in the new queue for a later run.
     *
     * @param array  $signal_ids Source Radar post IDs.
     * @param string $outcome    Allowlisted terminal outcome.
     * @param array  $post_ids   Journal draft IDs, when any were created.
     * @param string $run_id     Dispatch run UUID.
     * @return int Number of signals triaged.
     */
    public static function record_dispatch_source_outcome( array $signal_ids, $outcome, array $post_ids = array(), $run_id = '' ) {
        $outcome = sanitize_key( (string) $outcome );
        if ( ! in_array( $outcome, array( 'drafted', 'editorial_skip', 'topic_duplicate', 'quality_gate', 'duplicate' ), true ) ) {
            return 0;
        }

        $signal_ids = array_values( array_unique( array_filter( array_map( 'absint', $signal_ids ) ) ) );
        $post_ids   = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
        $run_id     = self::limited_text( $run_id, 128 );
        $updated    = 0;

        foreach ( $signal_ids as $signal_id ) {
            if ( self::POST_TYPE !== get_post_type( $signal_id ) ) {
                continue;
            }
            if ( 'source' !== sanitize_key( (string) get_post_meta( $signal_id, self::META_TYPE, true ) ) ) {
                continue;
            }
            if ( 'new' !== sanitize_key( (string) get_post_meta( $signal_id, self::META_STATUS, true ) ) ) {
                continue;
            }

            update_post_meta( $signal_id, self::META_STATUS, 'triaged' );
            update_post_meta( $signal_id, self::META_DISPATCH_OUTCOME, $outcome );
            update_post_meta( $signal_id, self::META_DISPATCHED_AT, current_time( 'mysql', true ) );
            update_post_meta( $signal_id, self::META_DISPATCH_RUN_ID, $run_id );
            update_post_meta( $signal_id, self::META_DISPATCH_POST_IDS, $post_ids );
            self::append_history(
                'source.dispatch',
                $outcome,
                array(
                    'signal_id' => $signal_id,
                    'post_ids'  => $post_ids,
                    'run_id'    => $run_id,
                )
            );
            $updated++;
        }

        return $updated;
    }

    private static function get_inbox_items( $limit = 20, $type = '', $status = '' ) {
        $meta_query = array();
        if ( '' !== $type ) {
            $meta_query[] = array( 'key' => self::META_TYPE, 'value' => $type );
        }
        if ( '' !== $status ) {
            $meta_query[] = array( 'key' => self::META_STATUS, 'value' => $status );
        }

        $args = array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => array( 'draft', 'private' ),
            'posts_per_page' => min( self::INBOX_LIMIT, max( 1, absint( $limit ) ) ),
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        );
        if ( ! empty( $meta_query ) ) {
            $args['meta_query'] = $meta_query;
        }
        $posts = get_posts( $args );
        $items = array();
        foreach ( $posts as $post ) {
            $items[] = array(
                'id'          => $post->ID,
                'type'        => sanitize_key( (string) get_post_meta( $post->ID, self::META_TYPE, true ) ),
                'status'      => sanitize_key( (string) get_post_meta( $post->ID, self::META_STATUS, true ) ),
                'title'       => get_the_title( $post ),
                'note'        => self::limited_textarea( $post->post_content, 1000 ),
                'source_url'  => self::safe_source_url( get_post_meta( $post->ID, self::META_SOURCE_URL, true ) ),
                'film_title'  => self::limited_text( get_post_meta( $post->ID, self::META_FILM_TITLE, true ), 180 ),
                'received_at' => self::limited_text( get_post_meta( $post->ID, self::META_RECEIVED_AT, true ), 80 ),
                'edit_link'   => get_edit_post_link( $post->ID, 'raw' ),
            );
        }
        return $items;
    }

    private static function inbox_counts() {
        $counts = array( 'new' => 0, 'triaged' => 0, 'archived' => 0, 'total' => 0 );
        foreach ( array( 'new', 'triaged', 'archived' ) as $status ) {
            $query = new WP_Query(
                array(
                    'post_type'      => self::POST_TYPE,
                    'post_status'    => array( 'draft', 'private' ),
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'no_found_rows'  => false,
                    'meta_key'       => self::META_STATUS,
                    'meta_value'     => $status,
                )
            );
            $counts[ $status ] = absint( $query->found_posts );
            $counts['total']  += $counts[ $status ];
        }
        return $counts;
    }

    private static function find_inbox_item_by_event_id( $event_id ) {
        $ids = get_posts(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => array( 'draft', 'private' ),
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_key'       => self::META_EVENT_ID,
                'meta_value'     => $event_id,
            )
        );
        return ! empty( $ids ) ? absint( $ids[0] ) : 0;
    }

    private static function acquire_capture_lock( $event_id ) {
        $option_name = self::CAPTURE_LOCK_PREFIX . hash( 'sha256', (string) $event_id );
        $owner       = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : wp_generate_password( 32, false, false );
        $now         = time();
        $value       = array(
            'owner'      => $owner,
            'created_at' => $now,
            'expires_at' => $now + self::CAPTURE_LOCK_TTL,
        );

        if ( add_option( $option_name, $value, '', false ) ) {
            return array( 'option_name' => $option_name, 'owner' => $owner );
        }

        $current = get_option( $option_name, null );
        $clearly_stale = is_array( $current )
            && ! empty( $current['expires_at'] )
            && is_numeric( $current['expires_at'] )
            && (int) $current['expires_at'] <= $now;
        if ( $clearly_stale && self::replace_capture_lock_if_unchanged( $option_name, $current, $value ) ) {
            return array( 'option_name' => $option_name, 'owner' => $owner );
        }

        return new WP_Error(
            'lunara_automation_capture_lock_busy',
            'This automation event is already being captured. Retry shortly.',
            array( 'retryable' => true, 'retry_after' => 5, 'status' => 409 )
        );
    }

    private static function release_capture_lock( array $lock ) {
        $current = get_option( $lock['option_name'], null );
        if ( is_array( $current ) && isset( $current['owner'] ) && hash_equals( (string) $current['owner'], (string) $lock['owner'] ) ) {
            self::delete_capture_lock_if_unchanged( $lock['option_name'], $current );
        }
    }

    private static function replace_capture_lock_if_unchanged( $option_name, array $current, array $replacement ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->options ) ) {
            return false;
        }
        $updated = $wpdb->update(
            $wpdb->options,
            array( 'option_value' => maybe_serialize( $replacement ), 'autoload' => 'no' ),
            array( 'option_name' => $option_name, 'option_value' => maybe_serialize( $current ) ),
            array( '%s', '%s' ),
            array( '%s', '%s' )
        );
        if ( 1 === $updated ) {
            wp_cache_delete( $option_name, 'options' );
            return true;
        }
        return false;
    }

    private static function delete_capture_lock_if_unchanged( $option_name, array $current ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->options ) ) {
            return false;
        }
        $deleted = $wpdb->delete(
            $wpdb->options,
            array( 'option_name' => $option_name, 'option_value' => maybe_serialize( $current ) ),
            array( '%s', '%s' )
        );
        if ( 1 === $deleted ) {
            wp_cache_delete( $option_name, 'options' );
            return true;
        }
        return false;
    }

    private static function request_event_id( WP_REST_Request $request, array $normalized ) {
        $event_id = $request->get_header( 'idempotency-key' );
        if ( ! $event_id ) {
            $event_id = $request->get_param( 'event_id' );
        }
        $event_id = preg_replace( '/[^A-Za-z0-9._:-]/', '-', (string) $event_id );
        $event_id = trim( self::limited_text( $event_id, 128 ), '-' );
        if ( '' !== $event_id ) {
            return $event_id;
        }
        return 'ifttt-' . hash( 'sha256', wp_json_encode( self::sanitize_context( $normalized ) ) );
    }

    private static function capture_title( array $normalized ) {
        if ( ! empty( $normalized['title'] ) ) {
            return $normalized['title'];
        }
        if ( ! empty( $normalized['film_title'] ) ) {
            return $normalized['film_title'] . ' — Screening Follow-Up';
        }
        if ( 'source' === $normalized['type'] ) {
            $host = wp_parse_url( $normalized['source_url'], PHP_URL_HOST );
            return 'Source Radar — ' . ( $host ? $host : 'New source' );
        }
        return 'Captured idea — ' . current_time( 'M j, Y g:i a' );
    }

    private static function history() {
        $history = get_option( self::OPTION_HISTORY, array() );
        return is_array( $history ) ? array_values( $history ) : array();
    }

    private static function append_history( $action, $outcome, array $context = array(), $event_id = '' ) {
        $profile = method_exists( 'Lunara_Journal_Foundation', 'current_access_profile' )
            ? Lunara_Journal_Foundation::current_access_profile()
            : null;
        $history = self::history();
        array_unshift(
            $history,
            array(
                'action'     => sanitize_key( (string) $action ),
                'outcome'    => sanitize_key( (string) $outcome ),
                'event_id'   => self::limited_text( $event_id, 128 ),
                'profile_id' => is_array( $profile ) && isset( $profile['id'] ) ? sanitize_key( (string) $profile['id'] ) : ( is_user_logged_in() ? 'wp_user_' . get_current_user_id() : 'wordpress' ),
                'context'    => self::sanitize_context( $context ),
                'created_at' => current_time( 'mysql' ),
            )
        );
        $history = array_slice( $history, 0, self::HISTORY_LIMIT );
        update_option( self::OPTION_HISTORY, $history, false );
    }

    private static function find_history_by_event_id( $event_id, $action = '' ) {
        foreach ( self::history() as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['event_id'] ) || ! hash_equals( (string) $entry['event_id'], (string) $event_id ) ) {
                continue;
            }
            if ( '' === $action || ( isset( $entry['action'] ) && $action === $entry['action'] ) ) {
                return $entry;
            }
        }
        return null;
    }

    private static function outbound_key() {
        if ( defined( 'LUNARA_IFTTT_WEBHOOK_KEY' ) ) {
            return trim( (string) constant( 'LUNARA_IFTTT_WEBHOOK_KEY' ) );
        }
        $environment = getenv( 'LUNARA_IFTTT_WEBHOOK_KEY' );
        return is_string( $environment ) ? trim( $environment ) : '';
    }

    private static function is_enabled() {
        return '1' === get_option( self::OPTION_ENABLED, '1' );
    }

    private static function guardrails() {
        return array(
            'publishing'       => false,
            'content_status'   => 'private_input_only',
            'dispatch_mode'    => 'asynchronous_queue',
            'credential_store' => 'deployment_configuration_only',
            'refused'          => array( 'publish', 'schedule', 'delete', 'theme_change', 'plugin_change', 'cache_change' ),
        );
    }

    private static function sanitize_context( $value, $key = '' ) {
        if ( preg_match( '/(?:token|secret|password|authorization|api[_-]?key|webhook[_-]?key)/i', (string) $key ) ) {
            return '[redacted]';
        }
        if ( is_array( $value ) ) {
            $clean = array();
            foreach ( array_slice( $value, 0, 50, true ) as $child_key => $child_value ) {
                $clean[ sanitize_key( (string) $child_key ) ] = self::sanitize_context( $child_value, $child_key );
            }
            return $clean;
        }
        if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
            return $value;
        }
        return self::limited_textarea( $value, 1000 );
    }

    private static function safe_source_url( $value ) {
        $url = esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );
        return $url && wp_http_validate_url( $url ) ? $url : '';
    }

    private static function safe_internal_or_public_url( $value ) {
        $url = esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );
        return $url ? $url : '';
    }

    private static function limited_text( $value, $limit ) {
        return self::limit_string( sanitize_text_field( (string) $value ), $limit );
    }

    private static function limited_textarea( $value, $limit ) {
        return self::limit_string( sanitize_textarea_field( (string) $value ), $limit );
    }

    private static function limit_string( $value, $limit ) {
        $limit = max( 1, absint( $limit ) );
        return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, 0, $limit ) : substr( (string) $value, 0, $limit );
    }

    private static function require_admin_action( $nonce_action ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lunara-journal-foundation' ) );
        }
        check_admin_referer( $nonce_action );
    }

    private static function redirect_to_control_desk( $notice ) {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'                      => 'lunara-control-desk',
                    'tab'                       => 'automation',
                    'lunara_automation_notice'  => sanitize_key( (string) $notice ),
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }
}

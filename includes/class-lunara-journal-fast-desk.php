<?php
/**
 * Fast, consolidated GPT-facing Journal operations.
 *
 * The Fast Desk keeps routine identity, health, configuration, draft listing,
 * workspace retrieval, save+validate, and manual Dispatch triggering behind a
 * small number of compact REST calls.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Journal_Fast_Desk {
    const CACHE_KEY = 'lunara_journal_fast_desk_snapshot_v1';
    const CACHE_TTL = 60;
    const DEFAULT_DRAFT_LIMIT = 8;

    public static function bootstrap() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
        add_action( 'save_post_journal', array( __CLASS__, 'invalidate_cache' ), 20 );
        add_action( 'lunara_journal_control_plane_activated', array( __CLASS__, 'invalidate_cache' ) );
        add_action( 'update_option_lunara_dispatch_last_run_report', array( __CLASS__, 'invalidate_cache' ), 20, 3 );
        add_action( 'updated_option', array( __CLASS__, 'maybe_invalidate_for_option' ), 20, 3 );
    }

    public static function register_rest_routes() {
        register_rest_route( 'lunara/v1', '/journal/desk', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'rest_open_desk' ),
            'permission_callback' => array( 'Lunara_Journal_Foundation', 'rest_permissions_check' ),
            'args'                => array(
                'limit' => array(
                    'type'              => 'integer',
                    'default'           => self::DEFAULT_DRAFT_LIMIT,
                    'minimum'           => 1,
                    'maximum'           => 20,
                    'sanitize_callback' => 'absint',
                ),
                'page' => array(
                    'type'              => 'integer',
                    'default'           => 1,
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ),
                'search' => array(
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'refresh' => array(
                    'type'    => 'boolean',
                    'default' => false,
                ),
            ),
        ) );

        register_rest_route( 'lunara/v1', '/journal/desk/drafts/(?P<id>\d+)', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'rest_open_workspace' ),
            'permission_callback' => array( 'Lunara_Journal_Foundation', 'rest_permissions_check' ),
            'args'                => array(
                'id' => array(
                    'validate_callback' => array( 'Lunara_Journal_Foundation', 'rest_validate_positive_id' ),
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );

        register_rest_route( 'lunara/v1', '/journal/desk/drafts/(?P<id>\d+)/save-validate', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'rest_save_validate' ),
            'permission_callback' => array( 'Lunara_Journal_Foundation', 'rest_permissions_check' ),
            'args'                => array(
                'id' => array(
                    'validate_callback' => array( 'Lunara_Journal_Foundation', 'rest_validate_positive_id' ),
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );

        register_rest_route( 'lunara/v1', '/journal/desk/drafts/(?P<id>\d+)/publish', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'rest_publish_draft' ),
            'permission_callback' => array( 'Lunara_Journal_Foundation', 'rest_permissions_check' ),
            'args'                => array(
                'id' => array(
                    'validate_callback' => array( 'Lunara_Journal_Foundation', 'rest_validate_positive_id' ),
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );

        register_rest_route( 'lunara/v1', '/journal/desk/run-dispatch', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'rest_run_dispatch' ),
            'permission_callback' => array( 'Lunara_Journal_Foundation', 'rest_permissions_check' ),
        ) );
    }

    public static function invalidate_cache() {
        delete_transient( self::CACHE_KEY );
        if ( class_exists( 'Lunara_Journal_Image_Guard' ) ) {
            Lunara_Journal_Image_Guard::clear_cache();
        }
    }

    public static function maybe_invalidate_for_option( $option, $old_value, $value ) {
        if ( in_array( (string) $option, array(
            Lunara_Journal_Config_Repository::OPTION_ACTIVE,
            Lunara_Journal_Config_Repository::OPTION_VERSIONS,
            'lunara_dispatch_last_run_report',
            'lunara_dispatch_manual_run_queued_at',
        ), true ) ) {
            self::invalidate_cache();
        }
    }

    public static function rest_open_desk( WP_REST_Request $request ) {
        $started = microtime( true );
        $refresh = self::truthy( $request->get_param( 'refresh' ) );
        $limit   = min( 20, max( 1, absint( $request->get_param( 'limit' ) ) ) );
        $page    = max( 1, absint( $request->get_param( 'page' ) ) );
        $search  = sanitize_text_field( (string) $request->get_param( 'search' ) );
        $cache_eligible = 1 === $page && '' === $search && self::DEFAULT_DRAFT_LIMIT === $limit;

        $base = false;
        if ( ! $refresh && $cache_eligible ) {
            $base = get_transient( self::CACHE_KEY );
        }
        if ( ! is_array( $base ) ) {
            $base = self::build_desk_snapshot( $limit, $page, $search );
            if ( $cache_eligible ) {
                set_transient( self::CACHE_KEY, $base, self::CACHE_TTL );
            }
        }

        $health_response = Lunara_Journal_Foundation::rest_health( $request );
        $health = self::response_data( $health_response );
        $base['identity'] = isset( $health['access_profile'] ) ? $health['access_profile'] : null;
        $base['performance_ms'] = self::elapsed_ms( $started );
        $base['cached_for_seconds'] = self::CACHE_TTL;

        return rest_ensure_response( $base );
    }

    public static function rest_open_workspace( WP_REST_Request $request ) {
        $started = microtime( true );
        $post = self::get_editable_journal_post( absint( $request['id'] ) );
        if ( is_wp_error( $post ) ) {
            return $post;
        }

        $config = Lunara_Journal_Control_Plane::get_active_config();
        $validation = Lunara_Journal_Validator::validate_post( $post->ID, $config );

        $payload = array(
            'workspace'          => self::workspace_payload( $post ),
            'editorial_config'   => self::compact_editorial_config( $config ),
            'validation'         => $validation,
            'guardrails'         => self::guardrails(),
            'recommended_action' => ! empty( $validation['valid'] ) ? 'Review the draft and propose changes; save only after Dalton approves the exact revision.' : 'Resolve validation errors while revising; save and validate only after Dalton approves the exact revision.',
            'performance_ms'     => self::elapsed_ms( $started ),
        );

        return rest_ensure_response( $payload );
    }

    public static function rest_save_validate( WP_REST_Request $request ) {
        $started = microtime( true );
        $post = self::get_editable_journal_post( absint( $request['id'] ) );
        if ( is_wp_error( $post ) ) {
            return $post;
        }

        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            $body = array();
        }
        if ( isset( $body['status'] ) || isset( $body['post_status'] ) || isset( $body['publish'] ) ) {
            return new WP_Error( 'lunara_fast_desk_no_status_change', 'Fast Desk refuses publish, schedule, delete, trash, and post-status changes.', array( 'status' => 400 ) );
        }

        $request->set_param( '_compact', 1 );
        $update_response = Lunara_Journal_Foundation::rest_update_draft( $request );
        if ( is_wp_error( $update_response ) ) {
            return $update_response;
        }

        clean_post_cache( $post->ID );
        $post = get_post( $post->ID );
        $validation_response = Lunara_Journal_Foundation::rest_validate_draft( $request );
        if ( is_wp_error( $validation_response ) ) {
            return $validation_response;
        }
        $validation = self::response_data( $validation_response );
        self::invalidate_cache();

        return rest_ensure_response( array(
            'saved'           => true,
            'post'            => self::draft_summary( $post ),
            'validation'      => $validation,
            'ready_to_mark'   => ! empty( $validation['valid'] ),
            'post_status'     => get_post_status( $post ),
            'guardrails'      => self::guardrails(),
            'performance_ms'  => self::elapsed_ms( $started ),
        ) );
    }

    public static function rest_publish_draft( WP_REST_Request $request ) {
        $started = microtime( true );
        $post = self::get_editable_journal_post( absint( $request['id'] ) );
        if ( is_wp_error( $post ) ) {
            return $post;
        }
        if ( is_user_logged_in() && ( ! current_user_can( 'edit_post', $post->ID ) || ! current_user_can( 'publish_posts' ) ) ) {
            return new WP_Error( 'lunara_publish_capability_forbidden', 'You do not have permission to publish this Journal entry.', array( 'status' => 403 ) );
        }

        $config = Lunara_Journal_Control_Plane::get_active_config();
        if ( empty( $config['chatgpt']['may_publish'] ) ) {
            return new WP_Error( 'lunara_publish_disabled', 'GPT publishing is currently disabled in the Journal Control Plane.', array( 'status' => 403 ) );
        }

        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            $body = array();
        }
        if ( ! self::truthy( isset( $body['confirm_publish_now'] ) ? $body['confirm_publish_now'] : false ) ) {
            return new WP_Error( 'lunara_publish_confirmation_required', 'Publishing requires explicit confirmation. Send confirm_publish_now=true only after Dalton instructs you to publish this exact entry now.', array( 'status' => 400 ) );
        }

        $validation = Lunara_Journal_Validator::validate_post( $post->ID, $config );
        if ( empty( $validation['valid'] ) ) {
            return new WP_Error( 'lunara_publish_validation_failed', 'This Journal entry cannot be published because validation failed.', array( 'status' => 409, 'validation' => $validation ) );
        }

        $before_status = get_post_status( $post );
        $result = wp_update_post( array(
            'ID'          => $post->ID,
            'post_status' => 'publish',
            'post_date'   => current_time( 'mysql' ),
            'post_date_gmt' => current_time( 'mysql', true ),
            'edit_date'   => true,
        ), true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        clean_post_cache( $post->ID );
        $published = get_post( $post->ID );
        if ( ! $published || 'publish' !== get_post_status( $published ) ) {
            return new WP_Error(
                'lunara_publish_readback_failed',
                'WordPress did not persist this Journal entry as published. No published provenance was recorded.',
                array(
                    'status'      => 409,
                    'post_id'     => $post->ID,
                    'post_status' => $published ? get_post_status( $published ) : '',
                )
            );
        }

        $actor = Lunara_Journal_Foundation::current_bridge_actor_context();
        update_post_meta( $post->ID, '_lunara_journal_published_at_gmt', current_time( 'mysql', true ) );
        update_post_meta( $post->ID, '_lunara_journal_published_by_actor', isset( $actor['actor'] ) ? $actor['actor'] : 'ChatGPT with Dalton approval' );
        update_post_meta( $post->ID, '_lunara_journal_published_by_client', isset( $actor['client'] ) ? $actor['client'] : 'ChatGPT Action' );
        update_post_meta( $post->ID, '_lunara_journal_published_config_version', isset( $config['config_version'] ) ? $config['config_version'] : '' );
        update_post_meta( $post->ID, 'journal_status', 'published' );
        if ( function_exists( 'update_field' ) ) {
            update_field( 'journal_status', 'published', $post->ID );
            update_field( 'journal_human_reviewer', isset( $actor['actor'] ) ? $actor['actor'] : 'ChatGPT with Dalton approval', $post->ID );
        }
        Lunara_Journal_Foundation::record_bridge_log_entry( $post->ID, 'publish', array(
            'before_status'   => $before_status,
            'after_status'    => 'publish',
            'config_version'  => isset( $config['config_version'] ) ? $config['config_version'] : '',
            'confirmed'       => true,
        ) );
        Lunara_Journal_Foundation::update_bridge_attribution( $post->ID, 'publish' );

        self::invalidate_cache();

        return rest_ensure_response( array(
            'published'               => true,
            'id'                      => $post->ID,
            'title'                   => get_the_title( $published ),
            'post_status'             => get_post_status( $published ),
            'permalink'               => get_permalink( $published ),
            'edit_link'               => admin_url( 'post.php?post=' . absint( $post->ID ) . '&action=edit' ),
            'published_at_gmt'        => get_post_meta( $post->ID, '_lunara_journal_published_at_gmt', true ),
            'published_by_actor'      => get_post_meta( $post->ID, '_lunara_journal_published_by_actor', true ),
            'published_by_client'     => get_post_meta( $post->ID, '_lunara_journal_published_by_client', true ),
            'published_config_version'=> get_post_meta( $post->ID, '_lunara_journal_published_config_version', true ),
            'validation'              => $validation,
            'guardrails'              => self::guardrails(),
            'performance_ms'          => self::elapsed_ms( $started ),
        ) );
    }

    public static function rest_run_dispatch( WP_REST_Request $request ) {
        $started = microtime( true );
        if ( ! class_exists( 'Lunara_Dispatch_Plugin' ) ) {
            return new WP_Error( 'lunara_dispatch_unavailable', 'Lunara Film Dispatch Automation is not active.', array( 'status' => 503 ) );
        }

        $plugin = Lunara_Dispatch_Plugin::instance();
        if ( ! method_exists( $plugin, 'queue_manual_run' ) ) {
            return new WP_Error( 'lunara_dispatch_upgrade_required', 'Dispatch 3.2.0 or later is required for fast asynchronous runs.', array( 'status' => 409 ) );
        }

        $result = $plugin->queue_manual_run();
        self::invalidate_cache();
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $result['performance_ms'] = self::elapsed_ms( $started );
        $result['instruction'] = 'The run is queued in WordPress. Reopen Journal Desk after about one minute to see the result and any new drafts.';

        return rest_ensure_response( $result );
    }

    private static function build_desk_snapshot( $limit, $page = 1, $search = '' ) {
        $config = Lunara_Journal_Control_Plane::get_active_config();
        $summary = Lunara_Journal_Prompt_Compiler::public_summary( $config );
        $query_args = array(
            'post_type'      => 'journal',
            'post_status'    => array( 'draft', 'pending', 'private', 'auto-draft' ),
            'posts_per_page' => $limit,
            'paged'          => $page,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => false,
        );
        if ( '' !== $search ) {
            $query_args['s'] = $search;
        }
        $query = new WP_Query( $query_args );

        $drafts = array();
        $attention = array();
        $recommended = null;
        $image_counts = array(
            'ready'           => 0,
            'needs_attention' => 0,
            'missing'         => 0,
            'unusable'        => 0,
        );
        foreach ( $query->posts as $post ) {
            $item = self::draft_summary( $post );
            $drafts[] = $item;
            $image_status = isset( $item['image']['status'] ) ? (string) $item['image']['status'] : 'missing';
            if ( isset( $image_counts[ $image_status ] ) ) {
                $image_counts[ $image_status ]++;
            }
            if ( ! empty( $item['needs_attention'] ) ) {
                $attention[] = array(
                    'id'      => $item['id'],
                    'title'   => $item['title'],
                    'reasons' => $item['attention_reasons'],
                );
            }
            if ( null === $recommended && empty( $item['ready_for_review'] ) ) {
                $recommended = array(
                    'id'     => $item['id'],
                    'title'  => $item['title'],
                    'reason' => ! empty( $item['needs_attention'] ) ? 'Newest draft needing editorial attention.' : 'Newest draft awaiting ChatGPT review.',
                );
            }
        }

        $counts = wp_count_posts( 'journal' );
        $draft_count = 0;
        foreach ( array( 'draft', 'pending', 'private', 'auto-draft' ) as $status ) {
            $draft_count += isset( $counts->$status ) ? (int) $counts->$status : 0;
        }
        $matched_drafts = isset( $query->found_posts ) ? (int) $query->found_posts : count( $drafts );
        $total_pages = isset( $query->max_num_pages ) ? (int) $query->max_num_pages : 0;

        return array(
            'ok'                  => true,
            'desk_ready'          => true,
            'generated_at_gmt'    => current_time( 'mysql', true ),
            'foundation_version'  => defined( 'LUNARA_JOURNAL_FOUNDATION_VERSION' ) ? LUNARA_JOURNAL_FOUNDATION_VERSION : '',
            'control_plane'       => array(
                'config_version'       => isset( $summary['config_version'] ) ? $summary['config_version'] : '1.0.0',
                'provider'             => isset( $summary['provider'] ) ? $summary['provider'] : '',
                'schedule'             => isset( $summary['schedule'] ) ? $summary['schedule'] : '',
                'target_post_type'     => 'journal',
                'post_status'          => 'draft',
                'sources_enabled'      => isset( $summary['sources_enabled'] ) ? (int) $summary['sources_enabled'] : 0,
                'configuration_valid'  => ! empty( Lunara_Journal_Config_Schema::validate_config( $config )['valid'] ),
                'gpt_publish_enabled'  => ! empty( $config['chatgpt']['may_publish'] ),
                'external_activation_refused' => true,
            ),
            'dispatch'            => self::dispatch_state(),
            'draft_count'         => $draft_count,
            'matched_drafts'      => $matched_drafts,
            'returned_drafts'     => count( $drafts ),
            'search'              => $search,
            'pagination'          => array(
                'page'        => $page,
                'per_page'    => $limit,
                'total'       => $matched_drafts,
                'total_pages' => $total_pages,
                'has_more'    => $page < $total_pages,
                'next_page'   => $page < $total_pages ? $page + 1 : null,
            ),
            'image_counts'        => $image_counts,
            'drafts'              => $drafts,
            'attention'           => $attention,
            'recommended'         => $recommended,
            'guardrails'          => self::guardrails(),
        );
    }

    private static function dispatch_state() {
        $active = class_exists( 'Lunara_Dispatch_Plugin' );
        $report_option = $active && defined( 'Lunara_Dispatch_Plugin::REPORT_OPTION' )
            ? Lunara_Dispatch_Plugin::REPORT_OPTION
            : 'lunara_dispatch_last_run_report';
        $last_report = get_option( $report_option, array() );
        if ( ! is_array( $last_report ) ) {
            $last_report = array();
        }
        $runtime = class_exists( 'Lunara_Journal_Control_Plane' ) ? Lunara_Journal_Control_Plane::get_dispatch_runtime_config() : array();
        $runtime = is_array( $runtime ) ? $runtime : array();
        $runtime_provider = isset( $runtime['provider'] ) ? sanitize_key( (string) $runtime['provider'] ) : '';
        $runtime_models = isset( $runtime['models'] ) && is_array( $runtime['models'] ) ? $runtime['models'] : array();
        $runtime_model = isset( $runtime_models[ $runtime_provider ] ) ? self::limited_text( $runtime_models[ $runtime_provider ], 100 ) : '';
        $ai_usage = isset( $last_report['ai_usage'] ) && is_array( $last_report['ai_usage'] ) ? $last_report['ai_usage'] : array();
        $created = isset( $last_report['created'] ) ? absint( $last_report['created'] ) : 0;
        $imported = isset( $last_report['imported'] ) ? absint( $last_report['imported'] ) : 0;
        $fallback_used = ! empty( $last_report['ai_fallback_used'] );
        $usage_reported = ! empty( array_intersect( array_keys( $ai_usage ), array( 'input_tokens', 'cached_input_tokens', 'output_tokens', 'estimated_cost_usd' ) ) );
        $estimated_cost = array_key_exists( 'estimated_cost_usd', $ai_usage ) && is_numeric( $ai_usage['estimated_cost_usd'] )
            ? max( 0.0, min( 999999.999999, round( (float) $ai_usage['estimated_cost_usd'], 6 ) ) )
            : null;
        $next_run = $active ? wp_next_scheduled( Lunara_Dispatch_Plugin::CRON_HOOK ) : false;
        $manual_hook = $active && defined( 'Lunara_Dispatch_Plugin::MANUAL_CRON_HOOK' ) ? Lunara_Dispatch_Plugin::MANUAL_CRON_HOOK : 'lunara_dispatch_manual_requested';
        $manual_queued = $active ? wp_next_scheduled( $manual_hook ) : false;
        $source_budget = $active && defined( 'Lunara_Dispatch_Plugin::MAX_ITEMS_PER_RUN' )
            ? min( 100, absint( Lunara_Dispatch_Plugin::MAX_ITEMS_PER_RUN ) )
            : 0;

        return array(
            'active'            => $active,
            'version'           => defined( 'LUNARA_DISPATCH_VERSION' ) ? LUNARA_DISPATCH_VERSION : '',
            'enabled'           => ! empty( $runtime['enabled'] ),
            'running'           => $active ? self::dispatch_running() : false,
            'manual_run_queued' => (bool) $manual_queued,
            'manual_queued_at'  => get_option( 'lunara_dispatch_manual_run_queued_at', '' ),
            'next_run_gmt'      => $next_run ? gmdate( 'c', $next_run ) : '',
            'runtime'           => array(
                'provider'          => $runtime_provider,
                'model'             => $runtime_model,
                'max_output_tokens' => isset( $runtime['max_tokens'] ) ? min( Lunara_Journal_Config_Schema::max_output_tokens_for( $runtime_provider ), absint( $runtime['max_tokens'] ) ) : 0,
                'source_budget'     => $source_budget,
            ),
            'last_run'          => array(
                'timestamp_gmt' => isset( $last_report['timestamp_gmt'] ) ? self::limited_text( $last_report['timestamp_gmt'], 80 ) : '',
                'success'       => isset( $last_report['success'] ) ? (bool) $last_report['success'] : null,
                'message'       => isset( $last_report['message'] ) ? self::limited_text( $last_report['message'], 500 ) : '',
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
                'error_code' => isset( $last_report['ai_error_code'] ) ? sanitize_key( (string) $last_report['ai_error_code'] ) : '',
                'processed_source_items' => $imported,
                'deferred_source_items' => array_key_exists( 'deferred_source_items', $last_report ) ? absint( $last_report['deferred_source_items'] ) : null,
                'source_radar_items' => array_key_exists( 'source_radar_items', $last_report ) ? absint( $last_report['source_radar_items'] ) : null,
                'source_packet_drafts' => $fallback_used ? $created : 0,
            ),
        );
    }

    /**
     * Read the atomic Dispatch worker lock, with a legacy transient fallback.
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

    private static function draft_summary( WP_Post $post ) {
        $validation_status = (string) self::acf_value( 'journal_validation_status', $post->ID );
        $journal_status = (string) self::acf_value( 'journal_status', $post->ID );
        $ready = self::truthy( self::acf_value( 'journal_ready_for_review', $post->ID ) );
        $sources = self::acf_value( 'journal_source_items', $post->ID );
        $image = Lunara_Journal_Image_Guard::inspect( $post->ID );
        $initial_provider = self::limited_text( get_post_meta( $post->ID, '_lunara_journal_initial_provider', true ), 100 );
        $prompt_version = self::limited_text( get_post_meta( $post->ID, '_lunara_journal_prompt_version', true ), 100 );
        $source_packet = self::truthy( get_post_meta( $post->ID, '_lunara_dispatch_source_packet', true ) )
            || 'source_packet' === sanitize_key( $initial_provider )
            || 0 === strpos( strtolower( $prompt_version ), 'source-packet' );
        $generation_mode = $source_packet ? 'source_packet' : ( '' !== $initial_provider ? 'ai_draft' : 'editorial' );
        $attention = array();

        foreach ( isset( $image['errors'] ) && is_array( $image['errors'] ) ? $image['errors'] : array() as $image_error ) {
            $attention[] = (string) $image_error;
        }
        foreach ( isset( $image['warnings'] ) && is_array( $image['warnings'] ) ? $image['warnings'] : array() as $image_warning ) {
            $attention[] = (string) $image_warning;
        }
        if ( '' === trim( (string) $post->post_excerpt ) && '' === trim( (string) self::acf_value( 'journal_deck', $post->ID ) ) ) {
            $attention[] = 'missing excerpt or deck';
        }
        if ( '' === trim( (string) self::acf_value( 'journal_seo_description', $post->ID ) ) ) {
            $attention[] = 'missing SEO description';
        }
        if ( ! self::has_source_url( $sources ) ) {
            $attention[] = 'missing source URL';
        }
        if ( in_array( $validation_status, array( 'errors', 'failed' ), true ) ) {
            $attention[] = 'validation errors';
        }

        return array(
            'id'                => $post->ID,
            'title'             => get_the_title( $post ),
            'modified_gmt'      => get_post_modified_time( 'c', true, $post ),
            'journal_status'    => $journal_status,
            'validation_status' => $validation_status,
            'ready_for_review'  => $ready,
            'featured_image'    => ! empty( $image['attached'] ),
            'image'             => array(
                'status'            => isset( $image['status'] ) ? $image['status'] : 'missing',
                'attached'          => ! empty( $image['attached'] ),
                'usable'            => ! empty( $image['usable'] ),
                'preferred_quality' => ! empty( $image['preferred_quality'] ),
                'dimensions'        => isset( $image['dimensions'] ) ? $image['dimensions'] : '',
                'aspect_ratio'      => isset( $image['aspect_ratio'] ) ? $image['aspect_ratio'] : 0,
                'warnings'          => isset( $image['warnings'] ) ? $image['warnings'] : array(),
                'errors'            => isset( $image['errors'] ) ? $image['errors'] : array(),
            ),
            'source_count'      => is_array( $sources ) ? count( $sources ) : 0,
            'section'           => self::first_term_name( $post->ID, 'journal_section' ),
            'generation'        => array(
                'mode'          => $generation_mode,
                'source_packet' => $source_packet,
            ),
            'needs_attention'   => ! empty( $attention ),
            'attention_reasons' => array_values( array_unique( $attention ) ),
            'edit_link'         => admin_url( 'post.php?post=' . absint( $post->ID ) . '&action=edit' ),
        );
    }

    private static function workspace_payload( WP_Post $post ) {
        $fields = array(
            'journal_kicker',
            'journal_deck',
            'journal_primary_section',
            'journal_status',
            'journal_item_type',
            'journal_priority',
            'journal_source_items',
            'journal_primary_title',
            'journal_primary_year',
            'journal_people',
            'journal_studios_platforms',
            'journal_editorial_angle',
            'journal_chatgpt_brief',
            'journal_chatgpt_revision_notes',
            'journal_seo_title',
            'journal_seo_description',
            'journal_image_source_url',
            'journal_image_credit',
            'journal_image_alt',
            'journal_validation_status',
            'journal_ready_for_review',
            'journal_writer_source',
            'journal_dispatch_actor',
            'journal_ai_editor',
            'journal_last_bridge_actor',
            'journal_last_bridge_client',
            'journal_last_bridge_action',
            'journal_last_bridge_updated_at',
        );
        $acf = array();
        foreach ( $fields as $field ) {
            $acf[ $field ] = self::acf_value( $field, $post->ID );
        }

        return array(
            'id'             => $post->ID,
            'post_type'      => $post->post_type,
            'post_status'    => $post->post_status,
            'title'          => get_the_title( $post ),
            'content'        => (string) $post->post_content,
            'excerpt'        => (string) $post->post_excerpt,
            'featured_media' => (int) get_post_thumbnail_id( $post->ID ),
            'image'          => Lunara_Journal_Image_Guard::inspect( $post->ID ),
            'terms'          => array(
                'sections' => wp_get_post_terms( $post->ID, 'journal_section', array( 'fields' => 'names' ) ),
                'topics'   => wp_get_post_terms( $post->ID, 'journal_topic', array( 'fields' => 'names' ) ),
            ),
            'acf'            => $acf,
            'provenance'     => array(
                'config_version' => get_post_meta( $post->ID, '_lunara_journal_config_version', true ),
                'prompt_version' => get_post_meta( $post->ID, '_lunara_journal_prompt_version', true ),
                'initial_provider'=> get_post_meta( $post->ID, '_lunara_journal_initial_provider', true ),
                'initial_model'   => get_post_meta( $post->ID, '_lunara_journal_initial_model', true ),
                'generated_at_gmt'=> get_post_meta( $post->ID, '_lunara_journal_generated_at_gmt', true ),
                'published_at_gmt'=> get_post_meta( $post->ID, '_lunara_journal_published_at_gmt', true ),
                'published_by_actor'=> get_post_meta( $post->ID, '_lunara_journal_published_by_actor', true ),
                'published_by_client'=> get_post_meta( $post->ID, '_lunara_journal_published_by_client', true ),
                'published_config_version'=> get_post_meta( $post->ID, '_lunara_journal_published_config_version', true ),
            ),
            'publication'    => array(
                'gpt_publish_enabled' => ! empty( Lunara_Journal_Control_Plane::get_active_config()['chatgpt']['may_publish'] ),
                'requires_explicit_confirmation' => true,
                'status' => $post->post_status,
            ),
            'edit_link'      => admin_url( 'post.php?post=' . absint( $post->ID ) . '&action=edit' ),
            'preview_link'   => get_preview_post_link( $post ),
        );
    }

    private static function compact_editorial_config( array $config ) {
        return array(
            'config_version' => isset( $config['config_version'] ) ? $config['config_version'] : '1.0.0',
            'purpose'        => isset( $config['editorial']['purpose'] ) ? $config['editorial']['purpose'] : '',
            'voice'          => array(
                'summary'            => isset( $config['editorial']['voice']['summary'] ) ? $config['editorial']['voice']['summary'] : '',
                'current_refinement' => isset( $config['editorial']['voice']['current_refinement'] ) ? $config['editorial']['voice']['current_refinement'] : '',
                'banned_phrases'     => isset( $config['editorial']['voice']['banned_phrases'] ) ? $config['editorial']['voice']['banned_phrases'] : array(),
                'reader_value_test'  => isset( $config['editorial']['voice']['reader_value_test'] ) ? $config['editorial']['voice']['reader_value_test'] : array(),
            ),
            'selection'      => isset( $config['editorial']['selection'] ) ? $config['editorial']['selection'] : array(),
            'formatting'     => isset( $config['editorial']['formatting'] ) ? $config['editorial']['formatting'] : array(),
            'requirements'   => isset( $config['editorial']['requirements'] ) ? $config['editorial']['requirements'] : array(),
        );
    }

    private static function get_editable_journal_post( $post_id ) {
        $post = get_post( (int) $post_id );
        if ( ! $post ) {
            return new WP_Error( 'lunara_not_found', 'Journal entry not found.', array( 'status' => 404 ) );
        }
        if ( 'journal' !== $post->post_type ) {
            return new WP_Error( 'lunara_wrong_post_type', 'Fast Desk only works with Journal entries.', array( 'status' => 400 ) );
        }
        if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'private', 'auto-draft' ), true ) ) {
            return new WP_Error( 'lunara_fast_desk_refused_status', 'Fast Desk refuses published, scheduled, and trashed content.', array( 'status' => 403 ) );
        }
        return $post;
    }

    private static function guardrails() {
        $config = Lunara_Journal_Control_Plane::get_active_config();
        return array(
            'draft_only_editing'                  => true,
            'explicit_publish_action_available'   => ! empty( $config['chatgpt']['may_publish'] ),
            'publish_requires_validation_pass'    => true,
            'publish_requires_explicit_confirmation' => true,
            'refused_operations'                  => array( 'future', 'schedule', 'bulk_publish', 'delete', 'trash', 'post_status_change', 'activate_configuration' ),
            'notion_required'                     => false,
        );
    }

    private static function acf_value( $field, $post_id ) {
        if ( function_exists( 'get_field' ) ) {
            return get_field( $field, $post_id );
        }
        return get_post_meta( $post_id, $field, true );
    }

    private static function has_source_url( $sources ) {
        if ( ! is_array( $sources ) ) {
            return false;
        }
        foreach ( $sources as $source ) {
            if ( is_array( $source ) && ! empty( $source['source_url'] ) ) {
                return true;
            }
        }
        return false;
    }

    private static function first_term_name( $post_id, $taxonomy ) {
        $terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
        return is_array( $terms ) && ! empty( $terms ) ? (string) $terms[0] : '';
    }

    private static function truthy( $value ) {
        return in_array( $value, array( true, 1, '1', 'true', 'yes', 'on' ), true );
    }

    private static function limited_text( $value, $limit ) {
        $value = sanitize_text_field( (string) $value );
        $limit = max( 1, absint( $limit ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit );
    }

    private static function response_data( $response ) {
        if ( is_object( $response ) && method_exists( $response, 'get_data' ) ) {
            $data = $response->get_data();
            return is_array( $data ) ? $data : array();
        }
        return is_array( $response ) ? $response : array();
    }

    private static function elapsed_ms( $started ) {
        return round( ( microtime( true ) - (float) $started ) * 1000, 1 );
    }
}

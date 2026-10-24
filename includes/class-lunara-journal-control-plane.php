<?php
/**
 * Authoritative Journal Control Plane API, REST routes, and admin UI glue.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Journal_Control_Plane {
    private static $active_config_cache = null;
    const MENU_SLUG = 'lunara-journal-control-plane';
    const CAPABILITY = 'manage_options';
    const SOURCE_STAGE_TTL = 600;

    public static function bootstrap() {
        add_action( 'init', array( __CLASS__, 'ensure_bootstrap_state' ), 20 );
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ), 20 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
        add_action( 'admin_post_lunara_journal_control_plane_save', array( __CLASS__, 'admin_save' ) );
        add_action( 'admin_post_lunara_journal_control_plane_migrate', array( __CLASS__, 'admin_migrate' ) );
        add_action( 'admin_post_lunara_journal_control_plane_rollback', array( __CLASS__, 'admin_rollback' ) );
        add_action( 'admin_post_lunara_journal_control_plane_sync_notion', array( __CLASS__, 'admin_sync_notion' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
        add_action( 'acf/init', array( __CLASS__, 'register_acf_fields' ) );
        add_filter( 'lunara_dispatch_control_plane_runtime', array( __CLASS__, 'filter_dispatch_runtime' ) );
        // Any activation (admin save, rollback, or the 1.4.0 voice move on a
        // cron request) must be what the rest of this request reads.
        add_action( 'lunara_journal_control_plane_activated', array( __CLASS__, 'flush_active_config_cache' ), 1 );
        Lunara_Journal_Notion_Sync::bootstrap();
    }

    public static function activate() {
        self::ensure_bootstrap_state();
    }

    public static function ensure_bootstrap_state() {
        Lunara_Journal_Config_Repository::ensure_default_version();
    }

    public static function is_active() {
        $config = self::get_active_config();
        return ! empty( $config );
    }

    public static function get_active_config() {
        if ( is_array( self::$active_config_cache ) ) {
            return self::$active_config_cache;
        }
        self::$active_config_cache = Lunara_Journal_Config_Repository::get_active_config();
        return self::$active_config_cache;
    }

    public static function get_active_version() {
        return Lunara_Journal_Config_Repository::get_active_version();
    }

    public static function flush_active_config_cache() {
        self::$active_config_cache = null;
    }

    public static function admin_path() {
        return 'edit.php?post_type=journal&page=' . self::MENU_SLUG;
    }

    public static function admin_url() {
        return admin_url( self::admin_path() );
    }

    public static function get_dispatch_runtime_config() {
        $config = self::get_active_config();
        return array(
            'protocol_version' => Lunara_Journal_Protocol::VERSION,
            'config_version'   => $config['config_version'] ?? '1.0.0',
            'enabled'          => ! empty( $config['dispatch']['enabled'] ),
            'schedule'         => $config['dispatch']['schedule'] ?? 'daily',
            'target_post_type' => 'journal',
            'post_status'      => 'draft',
            'provider'         => $config['dispatch']['provider'] ?? 'openai',
            'models'           => $config['dispatch']['models'] ?? array(),
            'max_tokens'       => (int) ( $config['dispatch']['max_tokens'] ?? Lunara_Journal_Config_Schema::MAX_OUTPUT_TOKENS ),
            'sources'          => $config['sources'] ?? array(),
            // Phrases a draft is sent back for once before it reaches the post builder.
            'house_tells'      => self::house_tells( $config ),
            'compiled_system_prompt' => Lunara_Journal_Prompt_Compiler::dispatch_system_prompt( $config ),
            'compiled_user_directive_prompt' => Lunara_Journal_Prompt_Compiler::dispatch_user_directive_prompt( $config ),
        );
    }

    private static function house_tells( array $config ) {
        $voice = isset( $config['editorial']['voice'] ) && is_array( $config['editorial']['voice'] ) ? $config['editorial']['voice'] : array();
        $tells = array();
        foreach ( array( 'banned_phrases', 'expertise_poison_phrases' ) as $list ) {
            foreach ( isset( $voice[ $list ] ) && is_array( $voice[ $list ] ) ? $voice[ $list ] : array() as $phrase ) {
                $phrase = is_scalar( $phrase ) ? strtolower( trim( (string) $phrase ) ) : '';
                if ( '' !== $phrase ) {
                    $tells[ $phrase ] = true;
                }
            }
        }
        return array_keys( $tells );
    }

    public static function filter_dispatch_runtime( $runtime ) {
        return self::get_dispatch_runtime_config();
    }

    public static function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=journal',
            'Journal Control Plane',
            'Control Plane',
            self::CAPABILITY,
            self::MENU_SLUG,
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function enqueue_admin_assets( $hook ) {
        if ( 'journal_page_' . self::MENU_SLUG !== (string) $hook || ! defined( 'LUNARA_JOURNAL_FOUNDATION_FILE' ) ) {
            return;
        }
        wp_enqueue_style(
            'lunara-journal-control-plane',
            plugins_url( 'assets/admin/control-plane.css', LUNARA_JOURNAL_FOUNDATION_FILE ),
            array(),
            Lunara_Journal_Foundation::VERSION
        );
        wp_enqueue_script(
            'lunara-journal-control-plane',
            plugins_url( 'assets/admin/control-plane.js', LUNARA_JOURNAL_FOUNDATION_FILE ),
            array(),
            Lunara_Journal_Foundation::VERSION,
            true
        );
    }

    public static function register_rest_routes() {
        register_rest_route( 'lunara/v1', '/journal/config/active', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'rest_active_config' ),
            'permission_callback' => array( 'Lunara_Journal_Foundation', 'rest_permissions_check' ),
        ) );
        register_rest_route( 'lunara/v1', '/journal/config/compiled', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'rest_compiled_config' ),
            'permission_callback' => array( 'Lunara_Journal_Foundation', 'rest_permissions_check' ),
        ) );
        register_rest_route( 'lunara/v1', '/journal/config/health', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'rest_health' ),
            'permission_callback' => array( 'Lunara_Journal_Foundation', 'rest_permissions_check' ),
        ) );
    }

    public static function rest_active_config( WP_REST_Request $request ) {
        $config = self::get_active_config();
        return rest_ensure_response( array(
            'active_version' => Lunara_Journal_Config_Repository::get_active_version_id(),
            'config'         => self::public_config( $config ),
            'summary'        => Lunara_Journal_Prompt_Compiler::public_summary( $config ),
        ) );
    }

    public static function rest_compiled_config( WP_REST_Request $request ) {
        $config = self::get_active_config();
        return rest_ensure_response( array(
            'config_version' => $config['config_version'] ?? '1.0.0',
            'dispatch_system_prompt' => Lunara_Journal_Prompt_Compiler::dispatch_system_prompt( $config ),
            'dispatch_user_directive_prompt' => Lunara_Journal_Prompt_Compiler::dispatch_user_directive_prompt( $config ),
            'chatgpt_editor_instructions' => Lunara_Journal_Prompt_Compiler::chatgpt_editor_instructions( $config ),
        ) );
    }

    public static function rest_health( WP_REST_Request $request ) {
        $config = self::get_active_config();
        return rest_ensure_response( array(
            'ok'                    => true,
            'control_plane'         => true,
            'protocol'              => Lunara_Journal_Protocol::health(),
            'active_version'        => Lunara_Journal_Config_Repository::get_active_version_id(),
            'summary'               => Lunara_Journal_Prompt_Compiler::public_summary( $config ),
            'configuration_valid'   => Lunara_Journal_Config_Schema::validate_config( $config ),
            'notion_has_credentials'=> Lunara_Journal_Notion_Client::has_credentials(),
            'notion_last_sync'      => get_option( Lunara_Journal_Notion_Client::OPTION_LAST, '' ),
            'notion_last_error'     => get_option( Lunara_Journal_Notion_Client::OPTION_ERROR, '' ),
            'refused_external_activation' => true,
        ) );
    }

    public static function public_config( array $config ) {
        return self::redact_secret_values( $config );
    }

    private static function redact_secret_values( array $values ) {
        $redacted = array();
        foreach ( $values as $key => $value ) {
            $normalized = strtolower( str_replace( '-', '_', (string) $key ) );
            $is_secret = in_array(
                $normalized,
                array( 'secret', 'secrets', 'token', 'tokens', 'api_key', 'api_keys', 'password', 'passwords', 'authorization', 'access_key', 'private_key', 'client_secret', 'auth_token', 'access_token' ),
                true
            ) || preg_match( '/(?:^|_)(?:api_key|access_token|auth_token|client_secret|private_key|password|secret)$/', $normalized );

            if ( $is_secret ) {
                continue;
            }
            $redacted[ $key ] = is_array( $value ) ? self::redact_secret_values( $value ) : $value;
        }
        return $redacted;
    }

    public static function register_acf_fields() {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) {
            return;
        }
        acf_add_local_field_group( array(
            'key' => 'group_lunara_journal_control_plane_provenance',
            'title' => 'LUNARA FILM Journal Control Plane Provenance',
            'fields' => array(
                array( 'key' => 'field_lunara_journal_config_version', 'label' => 'Configuration Version', 'name' => '_lunara_journal_config_version', 'type' => 'text', 'readonly' => 1 ),
                array( 'key' => 'field_lunara_journal_prompt_version', 'label' => 'Prompt Version', 'name' => '_lunara_journal_prompt_version', 'type' => 'text', 'readonly' => 1 ),
                array( 'key' => 'field_lunara_journal_initial_provider', 'label' => 'Initial Provider', 'name' => '_lunara_journal_initial_provider', 'type' => 'text', 'readonly' => 1 ),
                array( 'key' => 'field_lunara_journal_initial_model', 'label' => 'Initial Model', 'name' => '_lunara_journal_initial_model', 'type' => 'text', 'readonly' => 1 ),
                array( 'key' => 'field_lunara_journal_generated_at_gmt', 'label' => 'Generated At GMT', 'name' => '_lunara_journal_generated_at_gmt', 'type' => 'text', 'readonly' => 1 ),
            ),
            'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'journal' ) ) ),
            'position' => 'side',
            'style' => 'default',
            'active' => true,
            'show_in_rest' => 1,
        ) );
    }

    public static function admin_save() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lunara-journal-foundation' ) );
        }
        check_admin_referer( 'lunara_journal_control_plane_save' );
        $result = self::save_admin_submission( wp_unslash( $_POST ), get_current_user_id() );
        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( self::admin_url() . '&validation-error=1#lunara-journal-sources' );
            exit;
        }
        wp_safe_redirect( self::admin_url() . '&updated=1' );
        exit;
    }

    private static function save_admin_submission( array $post, $user_id = 0 ) {
        $current = self::get_active_config();
        $config = $current;
        $source_result = self::prepare_source_submission(
            isset( $post['sources'] ) ? $post['sources'] : array(),
            isset( $current['sources'] ) && is_array( $current['sources'] ) ? $current['sources'] : array(),
            isset( $post['removed_source_ids'] ) ? (array) $post['removed_source_ids'] : array()
        );
        if ( empty( $source_result['valid'] ) ) {
            self::retain_source_stage(
                array(
                    'rows'        => $source_result['rows'],
                    'errors'      => $source_result['errors'],
                    'removed_ids' => $source_result['removed_ids'],
                ),
                $user_id
            );
            return new WP_Error( 'lunara_invalid_sources', 'Correct the labeled source rows and try again.', $source_result['errors'] );
        }

        $config['sources'] = $source_result['sources'];
        $config['dispatch']['enabled'] = ! empty( $post['dispatch_enabled'] );
        $config['dispatch']['schedule'] = isset( $post['dispatch_schedule'] ) ? sanitize_key( $post['dispatch_schedule'] ) : 'daily';
        $config['dispatch']['provider'] = isset( $post['dispatch_provider'] ) ? sanitize_key( $post['dispatch_provider'] ) : 'openai';
        $config['dispatch']['max_tokens'] = isset( $post['dispatch_max_tokens'] ) ? (int) $post['dispatch_max_tokens'] : Lunara_Journal_Config_Schema::MAX_OUTPUT_TOKENS;
        foreach ( array( 'openai', 'claude', 'gemini', 'grok' ) as $provider ) {
            $field = 'dispatch_model_' . $provider;
            if ( isset( $post[ $field ] ) ) {
                $config['dispatch']['models'][ $provider ] = sanitize_text_field( $post[ $field ] );
            }
        }
        if ( isset( $post['editorial_purpose'] ) ) {
            $config['editorial']['purpose'] = sanitize_textarea_field( $post['editorial_purpose'] );
        }
        if ( isset( $post['voice_summary'] ) ) {
            $config['editorial']['voice']['summary'] = sanitize_textarea_field( $post['voice_summary'] );
        }
        if ( isset( $post['current_refinement'] ) ) {
            $config['editorial']['voice']['current_refinement'] = sanitize_textarea_field( $post['current_refinement'] );
        }
        if ( isset( $post['banned_phrases'] ) ) {
            $phrases = preg_split( '/\R+/', $post['banned_phrases'] );
            $config['editorial']['voice']['banned_phrases'] = array_values( array_filter( array_map( 'sanitize_text_field', $phrases ) ) );
        }
        $config['chatgpt']['may_publish'] = ! empty( $post['chatgpt_may_publish'] );
        $config['notion']['sync_enabled'] = ! empty( $post['notion_sync_enabled'] );
        $changelog = isset( $post['changelog'] ) ? sanitize_textarea_field( $post['changelog'] ) : 'Control Plane update.';
        $version = Lunara_Journal_Config_Repository::create_and_activate( $config, $changelog, 'wp_admin' );
        if ( is_wp_error( $version ) ) {
            self::retain_source_stage(
                array(
                    'rows'        => $source_result['rows'],
                    'errors'      => array( array( 'row' => 0, 'field' => 'config', 'message' => 'The configuration could not be activated.' ) ),
                    'removed_ids' => $source_result['removed_ids'],
                ),
                $user_id
            );
            return $version;
        }
        if ( isset( $post['notion_page_id'] ) ) {
            update_option( Lunara_Journal_Notion_Client::OPTION_PAGE_ID, sanitize_text_field( $post['notion_page_id'] ), false );
        }
        if ( isset( $post['notion_token'] ) && '' !== trim( (string) $post['notion_token'] ) ) {
            update_option( Lunara_Journal_Notion_Client::OPTION_TOKEN, sanitize_text_field( $post['notion_token'] ), false );
        }
        delete_transient( self::source_stage_key( $user_id ) );
        self::$active_config_cache = null;
        return $version;
    }

    private static function prepare_source_submission( $submitted, array $current_sources, array $confirmed_removed ) {
        $rows = array();
        $candidates = array();
        $errors = array();
        $current_ids = array();
        $submitted_existing_ids = array();
        $new_rows = array();
        $allowed_keys = array( 'enabled', 'id', 'label', 'max', 'priority', 'url' );
        sort( $allowed_keys );

        foreach ( $current_sources as $source ) {
            if ( is_array( $source ) && isset( $source['id'] ) && is_scalar( $source['id'] ) ) {
                $current_ids[] = (string) $source['id'];
            }
        }
        $current_ids = array_values( array_unique( $current_ids ) );

        if ( ! is_array( $submitted ) ) {
            $submitted = array();
            $errors[] = self::source_form_error( 0, 'row', 'Sources must be submitted as labeled rows.' );
        }

        foreach ( $submitted as $row_key => $raw ) {
            $index = count( $rows );
            if ( ! is_array( $raw ) ) {
                $raw = array();
                $errors[] = self::source_form_error( $index, 'row', 'Each source must be a labeled row.' );
            } else {
                $actual_keys = array_keys( $raw );
                sort( $actual_keys );
                if ( $actual_keys !== $allowed_keys ) {
                    $errors[] = self::source_form_error( $index, 'row', 'Each source row must contain only the labeled controls shown here.' );
                }
            }

            $id_is_scalar = isset( $raw['id'] ) && is_scalar( $raw['id'] );
            $id_raw = $id_is_scalar ? trim( (string) $raw['id'] ) : '';
            $id = sanitize_key( $id_raw );
            $is_new = '' === $id_raw;
            if ( array_key_exists( 'id', $raw ) && ! $id_is_scalar ) {
                $errors[] = self::source_form_error( $index, 'id', 'The immutable source ID is invalid.' );
            }
            if ( ! $is_new && $id_raw !== $id ) {
                $errors[] = self::source_form_error( $index, 'id', 'The immutable source ID is invalid.' );
            }
            if ( ! $is_new && ! in_array( $id, $current_ids, true ) ) {
                $errors[] = self::source_form_error( $index, 'id', 'The immutable source ID is not part of the active configuration.' );
            }
            if ( ! $is_new ) {
                $submitted_existing_ids[] = $id;
            }

            $enabled_raw = isset( $raw['enabled'] ) ? $raw['enabled'] : '0';
            if ( ! in_array( $enabled_raw, array( true, false, 1, 0, '1', '0' ), true ) ) {
                $errors[] = self::source_form_error( $index, 'enabled', 'Enabled must be on or off.' );
            }
            $enabled = in_array( $enabled_raw, array( true, 1, '1' ), true );
            $label = isset( $raw['label'] ) && is_scalar( $raw['label'] ) ? sanitize_text_field( $raw['label'] ) : '';
            $url = isset( $raw['url'] ) && is_scalar( $raw['url'] ) ? sanitize_text_field( $raw['url'] ) : '';
            $max = isset( $raw['max'] ) && is_scalar( $raw['max'] ) ? trim( (string) $raw['max'] ) : '';
            $priority = isset( $raw['priority'] ) && is_scalar( $raw['priority'] ) ? trim( (string) $raw['priority'] ) : '';
            $rows[] = array(
                'id'       => $is_new ? '' : $id,
                'enabled'  => $enabled,
                'label'    => $label,
                'url'      => $url,
                'max'      => $max,
                'priority' => $priority,
            );
            $candidate_id = $is_new ? 'source-new-' . ( $index + 1 ) : $id;
            if ( $is_new ) {
                $new_rows[ $index ] = true;
            }
            $candidates[] = array(
                'id'       => $candidate_id,
                'enabled'  => $enabled,
                'label'    => $label,
                'url'      => $url,
                'max'      => $max,
                'priority' => $priority,
            );
        }

        $confirmed = array();
        foreach ( $confirmed_removed as $confirmed_id ) {
            if ( ! is_scalar( $confirmed_id ) || (string) $confirmed_id !== sanitize_key( $confirmed_id ) ) {
                $errors[] = self::source_form_error( 0, 'removal', 'Removal confirmation is invalid.' );
                continue;
            }
            if ( '' !== (string) $confirmed_id ) {
                $confirmed[] = (string) $confirmed_id;
            }
        }
        $confirmed = array_values( array_unique( $confirmed ) );
        $omitted = array_values( array_diff( $current_ids, array_unique( $submitted_existing_ids ) ) );
        $unexpected_confirmations = array_diff( $confirmed, $omitted );
        $missing_confirmations = array_diff( $omitted, $confirmed );
        if ( ! empty( $unexpected_confirmations ) || ! empty( $missing_confirmations ) ) {
            $errors[] = self::source_form_error( 0, 'removal', 'Confirm each removed source before saving.' );
        }

        $schema_validation = Lunara_Journal_Config_Schema::validate_sources( $candidates );
        $errors = array_merge( $errors, $schema_validation['errors'] );
        if ( ! empty( $errors ) ) {
            return array(
                'valid'       => false,
                'sources'     => array(),
                'rows'        => $rows,
                'errors'      => $errors,
                'removed_ids' => $confirmed,
            );
        }

        $used_ids = $current_ids;
        foreach ( $candidates as $index => &$candidate ) {
            if ( isset( $new_rows[ $index ] ) ) {
                $candidate['id'] = self::new_source_id( $used_ids );
                $used_ids[] = $candidate['id'];
            }
            $candidate['url'] = Lunara_Journal_Config_Schema::normalize_source_url( $candidate['url'] );
            $candidate['max'] = (int) $candidate['max'];
            $candidate['priority'] = (int) $candidate['priority'];
            $rows[ $index ]['url'] = $candidate['url'];
            $rows[ $index ]['max'] = $candidate['max'];
            $rows[ $index ]['priority'] = $candidate['priority'];
        }
        unset( $candidate );

        $final_validation = Lunara_Journal_Config_Schema::validate_sources( $candidates );
        if ( empty( $final_validation['valid'] ) ) {
            return array(
                'valid'       => false,
                'sources'     => array(),
                'rows'        => $rows,
                'errors'      => $final_validation['errors'],
                'removed_ids' => $confirmed,
            );
        }

        return array(
            'valid'       => true,
            'sources'     => array_values( $candidates ),
            'rows'        => $rows,
            'errors'      => array(),
            'removed_ids' => $confirmed,
        );
    }

    private static function new_source_id( array $used_ids ) {
        for ( $attempt = 0; $attempt < 20; $attempt++ ) {
            $uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true );
            $id = sanitize_key( 'source-' . $uuid );
            if ( '' !== $id && ! in_array( $id, $used_ids, true ) ) {
                return $id;
            }
        }
        return 'source-' . sanitize_key( hash( 'sha256', microtime( true ) . wp_rand() ) );
    }

    private static function source_form_error( $row, $field, $message ) {
        return array(
            'row'     => (int) $row,
            'field'   => sanitize_key( $field ),
            'message' => sanitize_text_field( $message ),
        );
    }

    private static function source_stage_key( $user_id = 0 ) {
        $user_id = absint( $user_id ? $user_id : get_current_user_id() );
        return 'lunara_journal_control_plane_source_stage_' . $user_id;
    }

    private static function retain_source_stage( array $stage, $user_id = 0 ) {
        $user_id = absint( $user_id ? $user_id : get_current_user_id() );
        if ( ! $user_id ) {
            return false;
        }
        $safe_rows = array();
        foreach ( isset( $stage['rows'] ) && is_array( $stage['rows'] ) ? $stage['rows'] : array() as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $safe_rows[] = array(
                'id'       => isset( $row['id'] ) && is_scalar( $row['id'] ) ? sanitize_key( $row['id'] ) : '',
                'enabled'  => ! empty( $row['enabled'] ),
                'label'    => isset( $row['label'] ) && is_scalar( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '',
                'url'      => isset( $row['url'] ) && is_scalar( $row['url'] ) ? sanitize_text_field( $row['url'] ) : '',
                'max'      => isset( $row['max'] ) && is_scalar( $row['max'] ) ? sanitize_text_field( $row['max'] ) : '',
                'priority' => isset( $row['priority'] ) && is_scalar( $row['priority'] ) ? sanitize_text_field( $row['priority'] ) : '',
            );
        }
        $safe_errors = array();
        foreach ( isset( $stage['errors'] ) && is_array( $stage['errors'] ) ? $stage['errors'] : array() as $error ) {
            if ( ! is_array( $error ) ) {
                continue;
            }
            $safe_errors[] = self::source_form_error(
                isset( $error['row'] ) ? $error['row'] : 0,
                isset( $error['field'] ) ? $error['field'] : 'row',
                isset( $error['message'] ) ? $error['message'] : 'Correct this source row.'
            );
        }
        $safe_removed_ids = array();
        foreach ( isset( $stage['removed_ids'] ) && is_array( $stage['removed_ids'] ) ? $stage['removed_ids'] : array() as $removed_id ) {
            if ( is_scalar( $removed_id ) ) {
                $removed_id = sanitize_key( $removed_id );
                if ( '' !== $removed_id ) {
                    $safe_removed_ids[] = $removed_id;
                }
            }
        }
        $safe_stage = array(
            'rows'        => $safe_rows,
            'errors'      => $safe_errors,
            'removed_ids' => array_values( array_unique( $safe_removed_ids ) ),
        );
        return set_transient( self::source_stage_key( $user_id ), $safe_stage, self::SOURCE_STAGE_TTL );
    }

    private static function consume_source_stage( $user_id = 0 ) {
        $user_id = absint( $user_id ? $user_id : get_current_user_id() );
        if ( ! $user_id ) {
            return false;
        }
        $key = self::source_stage_key( $user_id );
        $stage = get_transient( $key );
        if ( false === $stage ) {
            return false;
        }
        delete_transient( $key );
        return is_array( $stage ) ? $stage : false;
    }

    public static function admin_migrate() {
        if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( esc_html__( 'Permission denied.', 'lunara-journal-foundation' ) ); }
        check_admin_referer( 'lunara_journal_control_plane_migrate' );
        $result = Lunara_Journal_Migration::migrate_current_settings_as_active();
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
        self::$active_config_cache = null;
        wp_safe_redirect( self::admin_url() . '&migrated=1' );
        exit;
    }

    public static function admin_rollback() {
        if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( esc_html__( 'Permission denied.', 'lunara-journal-foundation' ) ); }
        check_admin_referer( 'lunara_journal_control_plane_rollback' );
        if ( ! isset( $_POST['confirm_rollback'] ) || '1' !== (string) wp_unslash( $_POST['confirm_rollback'] ) ) {
            wp_die( esc_html__( 'Confirm the configuration restore before continuing.', 'lunara-journal-foundation' ) );
        }
        $id = isset( $_POST['version_id'] ) ? absint( $_POST['version_id'] ) : 0;
        $result = Lunara_Journal_Config_Repository::clone_prior_as_new_active( $id, 'Rollback cloned from version #' . $id . '.', 'wp_admin' );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
        self::$active_config_cache = null;
        wp_safe_redirect( self::admin_url() . '&rolled-back=1' );
        exit;
    }

    public static function admin_sync_notion() {
        if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( esc_html__( 'Permission denied.', 'lunara-journal-foundation' ) ); }
        check_admin_referer( 'lunara_journal_control_plane_sync_notion' );
        $result = Lunara_Journal_Notion_Client::sync_config( self::get_active_config() );
        $arg = is_wp_error( $result ) ? 'notion-error=1' : 'notion-synced=1';
        wp_safe_redirect( self::admin_url() . '&' . $arg );
        exit;
    }

    public static function render_admin_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) { return; }
        $version = self::get_active_version();
        $config = self::get_active_config();
        $source_stage = self::consume_source_stage();
        $source_rows = is_array( $source_stage ) && isset( $source_stage['rows'] ) ? $source_stage['rows'] : ( isset( $config['sources'] ) && is_array( $config['sources'] ) ? $config['sources'] : array() );
        $source_errors = is_array( $source_stage ) && isset( $source_stage['errors'] ) ? $source_stage['errors'] : array();
        $removed_source_ids = is_array( $source_stage ) && isset( $source_stage['removed_ids'] ) ? $source_stage['removed_ids'] : array();
        $versions = array_reverse( Lunara_Journal_Config_Repository::get_versions() );
        $summary = Lunara_Journal_Prompt_Compiler::public_summary( $config );
        $compiled = Lunara_Journal_Prompt_Compiler::dispatch_system_prompt( $config );
        $runtime = self::get_dispatch_runtime_config();
        $runtime_provider = isset( $runtime['provider'] ) ? (string) $runtime['provider'] : 'openai';
        $runtime_model = isset( $runtime['models'][ $runtime_provider ] ) ? (string) $runtime['models'][ $runtime_provider ] : '';
        ?>
        <div class="wrap lunara-control-plane">
            <h1>LUNARA FILM Journal Control Plane</h1>
            <p><strong>Authoritative runtime configuration.</strong> Dispatch, the Fast Journal Desk, validation, and provenance consume this active version. The optional Notion mirror is not part of the GPT operational path. Only WordPress administrators can activate or roll back configuration.</p>
            <?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p>New Control Plane version activated.</p></div><?php endif; ?>
            <?php if ( isset( $_GET['migrated'] ) ) : ?><div class="notice notice-success"><p>Current Dispatch settings migrated into a new active Control Plane version.</p></div><?php endif; ?>
            <?php if ( isset( $_GET['notion-error'] ) ) : ?><div class="notice notice-warning"><p>Notion sync did not complete. Check the Notion status below.</p></div><?php endif; ?>
            <?php if ( isset( $_GET['validation-error'] ) ) : ?><div class="notice notice-error"><p>Nothing was activated. Correct the labeled source rows below and save again.</p></div><?php endif; ?>
            <h2>Active Version</h2>
            <table class="widefat striped" style="max-width:1100px"><tbody>
                <tr><th>Configuration</th><td><?php echo esc_html( $summary['config_version'] ); ?> (version ID <?php echo esc_html( Lunara_Journal_Config_Repository::get_active_version_id() ); ?>)</td></tr>
                <tr><th>Dispatch Runtime</th><td><?php echo esc_html( $runtime_provider . ' / ' . $runtime_model . ' / ' . (int) $runtime['max_tokens'] . ' max output tokens / ' . $summary['schedule'] . ' / journal draft' ); ?></td></tr>
                <tr><th>Sources Enabled</th><td><?php echo esc_html( (string) $summary['sources_enabled'] ); ?></td></tr>
                <tr><th>Notion Mirror</th><td><?php echo ! empty( $config['notion']['sync_enabled'] ) ? 'Enabled' : 'Disabled'; ?>; last sync: <?php echo esc_html( get_option( Lunara_Journal_Notion_Client::OPTION_LAST, 'never' ) ); ?></td></tr>
                <tr><th>GPT Publishing</th><td><?php echo ! empty( $config['chatgpt']['may_publish'] ) ? 'Enabled with explicit publish action' : 'Disabled'; ?></td></tr>
            </tbody></table>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:1100px;margin-top:24px;">
                <?php wp_nonce_field( 'lunara_journal_control_plane_save' ); ?>
                <input type="hidden" name="action" value="lunara_journal_control_plane_save" />
                <h2>Workflow and AI Runtime</h2>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th scope="row">Automation</th><td><label><input type="checkbox" name="dispatch_enabled" value="1" <?php checked( ! empty( $config['dispatch']['enabled'] ) ); ?> /> Dispatch automation enabled</label><p class="description">Publication remains manual unless you explicitly use the dedicated GPT publish action.</p></td></tr>
                    <tr><th scope="row">GPT Publishing</th><td><label><input type="checkbox" name="chatgpt_may_publish" value="1" <?php checked( ! empty( $config['chatgpt']['may_publish'] ) ); ?> /> Allow the private LUNARA FILM GPT to publish a single validated Journal entry when you explicitly instruct it to publish.</label><p class="description">This does not enable bulk publishing or scheduling. The draft must pass validation, including the featured-image guard.</p></td></tr>
                    <tr><th scope="row">Schedule</th><td><?php self::select_field( 'dispatch_schedule', $config['dispatch']['schedule'], array( 'daily' => 'Daily', 'twice_daily' => 'Twice Daily', 'every_4_hours' => 'Every 4 Hours', 'every_2_hours' => 'Every 2 Hours' ) ); ?></td></tr>
                    <tr><th scope="row">Provider</th><td><?php self::select_field( 'dispatch_provider', $config['dispatch']['provider'], array( 'openai' => 'OpenAI', 'claude' => 'Claude', 'gemini' => 'Gemini', 'grok' => 'Grok' ) ); ?></td></tr>
                    <tr><th scope="row">Max Output Tokens</th><td><input type="number" min="1024" max="<?php echo esc_attr( (string) Lunara_Journal_Config_Schema::CLAUDE_MAX_OUTPUT_TOKENS ); ?>" name="dispatch_max_tokens" value="<?php echo esc_attr( (string) $config['dispatch']['max_tokens'] ); ?>" /><p class="description">OpenAI, Gemini, and Grok are capped at <?php echo esc_html( number_format( Lunara_Journal_Config_Schema::MAX_OUTPUT_TOKENS ) ); ?> output tokens per run. Claude may use up to <?php echo esc_html( number_format( Lunara_Journal_Config_Schema::CLAUDE_MAX_OUTPUT_TOKENS ) ); ?>, because its thinking counts against the same limit.</p></td></tr>
                    <tr><th scope="row">OpenAI Model</th><td><?php self::select_field( 'dispatch_model_openai', $config['dispatch']['models']['openai'] ?? Lunara_Journal_Config_Schema::DEFAULT_OPENAI_MODEL, array( 'gpt-5.4-mini' => 'GPT-5.4 mini', 'gpt-5.4-nano' => 'GPT-5.4 nano' ) ); ?><p class="description">Only the cost-safe Dispatch 3.2.5 model allowlist is available.</p></td></tr>
                    <?php foreach ( array( 'claude' => 'Claude Model', 'gemini' => 'Gemini Model', 'grok' => 'Grok Model' ) as $key => $label ) : ?>
                    <tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><input type="text" class="regular-text" name="dispatch_model_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $config['dispatch']['models'][ $key ] ?? '' ); ?>" /></td></tr>
                    <?php endforeach; ?>
                </tbody></table>

                <h2>Editorial Specification</h2>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th scope="row">Purpose</th><td><textarea name="editorial_purpose" rows="4" class="large-text"><?php echo esc_textarea( $config['editorial']['purpose'] ?? '' ); ?></textarea></td></tr>
                    <tr><th scope="row">Voice Summary</th><td><textarea name="voice_summary" rows="4" class="large-text"><?php echo esc_textarea( $config['editorial']['voice']['summary'] ?? '' ); ?></textarea></td></tr>
                    <tr><th scope="row">Current Refinement</th><td><textarea name="current_refinement" rows="5" class="large-text"><?php echo esc_textarea( $config['editorial']['voice']['current_refinement'] ?? '' ); ?></textarea><p class="description">This replaces the old Dispatch voice-refinement field as the authoritative steering note.</p></td></tr>
                    <tr><th scope="row">Banned Phrases</th><td><textarea name="banned_phrases" rows="8" class="large-text code"><?php echo esc_textarea( implode( "\n", $config['editorial']['voice']['banned_phrases'] ?? array() ) ); ?></textarea></td></tr>
                </tbody></table>

                <section id="lunara-journal-sources" class="lunara-journal-sources" aria-labelledby="lunara-journal-sources-heading">
                    <h2 id="lunara-journal-sources-heading">Sources</h2>
                    <p class="description">Each source has a permanent ID. Give it a recognizable name, a complete HTTP(S) URL, a maximum of 1–50 items, and a priority of 1–10.</p>
                    <?php if ( $source_errors ) : ?><ul class="lunara-source-errors" role="alert"><?php foreach ( array_unique( array_map( static function ( $error ) { return is_array( $error ) && isset( $error['message'] ) ? sanitize_text_field( $error['message'] ) : 'Correct the source rows.'; }, $source_errors ) ) as $message ) : ?><li><?php echo esc_html( $message ); ?></li><?php endforeach; ?></ul><?php endif; ?>
                    <div data-lunara-source-removals>
                        <?php foreach ( $removed_source_ids as $removed_source_id ) : ?>
                            <input type="hidden" name="removed_source_ids[]" value="<?php echo esc_attr( $removed_source_id ); ?>" />
                        <?php endforeach; ?>
                    </div>
                    <div class="lunara-source-rows" data-lunara-source-rows>
                        <?php foreach ( array_values( $source_rows ) as $source_index => $source_row ) : ?>
                            <?php self::render_source_row( $source_row, $source_index, $source_errors ); ?>
                        <?php endforeach; ?>
                    </div>
                    <template data-lunara-source-template><?php self::render_source_row( array(), '__ROW_KEY__', array() ); ?></template>
                    <p><button type="button" class="button" data-lunara-source-add>Add source</button></p>
                </section>

                <h2>Optional Notion Mirror</h2>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th scope="row">Enable Mirror</th><td><label><input type="checkbox" name="notion_sync_enabled" value="1" <?php checked( ! empty( $config['notion']['sync_enabled'] ) ); ?> /> Sync active configuration one-way to Notion (optional; not used by Fast Journal Desk)</label><p class="description">Notion is a mirror. It never activates production configuration.</p></td></tr>
                    <tr><th scope="row">Notion Page ID</th><td><input type="text" class="regular-text" name="notion_page_id" value="<?php echo esc_attr( get_option( Lunara_Journal_Notion_Client::OPTION_PAGE_ID, '' ) ); ?>" /></td></tr>
                    <tr><th scope="row">Notion Token</th><td><input type="password" class="regular-text" name="notion_token" value="" autocomplete="off" /><p class="description">Leave blank to keep existing token. Tokens are stored separately and are never exported in configuration JSON.</p></td></tr>
                </tbody></table>

                <h2>Activate New Version</h2>
                <p><textarea name="changelog" rows="3" class="large-text" placeholder="Describe why this configuration version is being activated."></textarea></p>
                <?php submit_button( 'Save and Activate New Configuration Version' ); ?>
            </form>

            <hr />
            <h2>Operations</h2>
            <p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:12px;">
                    <?php wp_nonce_field( 'lunara_journal_control_plane_migrate' ); ?>
                    <input type="hidden" name="action" value="lunara_journal_control_plane_migrate" />
                    <?php submit_button( 'Migrate Current Dispatch Settings', 'secondary', 'submit', false ); ?>
                </form>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                    <?php wp_nonce_field( 'lunara_journal_control_plane_sync_notion' ); ?>
                    <input type="hidden" name="action" value="lunara_journal_control_plane_sync_notion" />
                    <?php submit_button( 'Sync Notion Mirror Now', 'secondary', 'submit', false ); ?>
                </form>
            </p>

            <h2>Compiled Dispatch Prompt Preview</h2>
            <textarea readonly rows="16" class="large-text code"><?php echo esc_textarea( $compiled ); ?></textarea>

            <h2>Version History</h2>
            <table class="widefat striped" style="max-width:1100px"><thead><tr><th>ID</th><th>Version</th><th>Status</th><th>Created</th><th>Changelog</th><th>Rollback</th></tr></thead><tbody>
            <?php foreach ( $versions as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( (string) $row['id'] ); ?></td>
                    <td><?php echo esc_html( $row['config_version'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $row['status'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $row['created_at_gmt'] ?? '' ); ?></td>
                    <td><?php echo esc_html( $row['changelog'] ?? '' ); ?></td>
                    <td><?php if ( 'active' !== ( $row['status'] ?? '' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-lunara-confirm="Restore this configuration as a new active version?"><?php wp_nonce_field( 'lunara_journal_control_plane_rollback' ); ?><input type="hidden" name="action" value="lunara_journal_control_plane_rollback" /><input type="hidden" name="version_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" /><input type="hidden" name="confirm_rollback" value="0" /><?php submit_button( 'Clone as Rollback', 'small', 'submit', false ); ?></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }

    private static function render_source_row( $row, $row_key, array $errors ) {
        $row = is_array( $row ) ? $row : array();
        $key = (string) $row_key;
        $numeric_index = is_numeric( $row_key ) ? (int) $row_key : -1;
        $id = isset( $row['id'] ) ? sanitize_key( $row['id'] ) : '';
        $enabled = ! empty( $row['enabled'] );
        $label = isset( $row['label'] ) && is_scalar( $row['label'] ) ? (string) $row['label'] : '';
        $url = isset( $row['url'] ) && is_scalar( $row['url'] ) ? (string) $row['url'] : '';
        $max = isset( $row['max'] ) && is_scalar( $row['max'] ) ? (string) $row['max'] : '10';
        $priority = isset( $row['priority'] ) && is_scalar( $row['priority'] ) ? (string) $row['priority'] : '5';
        $prefix = 'lunara-source-' . $key;
        $row_errors = array();
        foreach ( $errors as $error ) {
            if ( is_array( $error ) && $numeric_index === (int) ( isset( $error['row'] ) ? $error['row'] : -2 ) ) {
                $row_errors[] = isset( $error['message'] ) ? sanitize_text_field( $error['message'] ) : 'Correct this source row.';
            }
        }
        ?>
        <fieldset class="lunara-source-row" data-lunara-source-row data-existing-id="<?php echo esc_attr( $id ); ?>">
            <legend><?php echo '__ROW_KEY__' === $key ? 'New source' : esc_html( $label ? $label : 'Source ' . ( $numeric_index + 1 ) ); ?></legend>
            <input type="hidden" name="sources[<?php echo esc_attr( $key ); ?>][id]" value="<?php echo esc_attr( $id ); ?>" />
            <p class="lunara-source-identity"><strong>Permanent ID:</strong> <code data-lunara-source-id-label><?php echo esc_html( $id ? $id : 'Assigned when saved' ); ?></code></p>
            <div class="lunara-source-grid">
                <div class="lunara-source-field lunara-source-field--enabled">
                    <input type="hidden" name="sources[<?php echo esc_attr( $key ); ?>][enabled]" value="0" />
                    <label for="<?php echo esc_attr( $prefix . '-enabled' ); ?>"><input id="<?php echo esc_attr( $prefix . '-enabled' ); ?>" type="checkbox" name="sources[<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( $enabled ); ?> /> Enabled</label>
                </div>
                <div class="lunara-source-field">
                    <label for="<?php echo esc_attr( $prefix . '-label' ); ?>">Source name</label>
                    <input id="<?php echo esc_attr( $prefix . '-label' ); ?>" type="text" name="sources[<?php echo esc_attr( $key ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" required />
                </div>
                <div class="lunara-source-field lunara-source-field--url">
                    <label for="<?php echo esc_attr( $prefix . '-url' ); ?>">HTTP(S) URL</label>
                    <input id="<?php echo esc_attr( $prefix . '-url' ); ?>" type="url" name="sources[<?php echo esc_attr( $key ); ?>][url]" value="<?php echo esc_attr( $url ); ?>" placeholder="https://example.com/feed" required />
                </div>
                <div class="lunara-source-field">
                    <label for="<?php echo esc_attr( $prefix . '-max' ); ?>">Maximum items</label>
                    <input id="<?php echo esc_attr( $prefix . '-max' ); ?>" type="number" min="1" max="50" step="1" name="sources[<?php echo esc_attr( $key ); ?>][max]" value="<?php echo esc_attr( $max ); ?>" required />
                </div>
                <div class="lunara-source-field">
                    <label for="<?php echo esc_attr( $prefix . '-priority' ); ?>">Priority</label>
                    <input id="<?php echo esc_attr( $prefix . '-priority' ); ?>" type="number" min="1" max="10" step="1" name="sources[<?php echo esc_attr( $key ); ?>][priority]" value="<?php echo esc_attr( $priority ); ?>" required />
                </div>
            </div>
            <?php if ( $row_errors ) : ?><ul class="lunara-source-errors" role="alert"><?php foreach ( array_unique( $row_errors ) as $message ) : ?><li><?php echo esc_html( $message ); ?></li><?php endforeach; ?></ul><?php endif; ?>
            <p><button type="button" class="button-link-delete" data-lunara-source-remove>Remove source</button></p>
        </fieldset>
        <?php
    }

    private static function select_field( $name, $current, array $choices ) {
        echo '<select name="' . esc_attr( $name ) . '">';
        foreach ( $choices as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }
}

<?php
/**
 * Plugin Name: LUNARA FILM Journal Foundation
 * Description: Registers the LUNARA FILM Journal content model, ACF fields, draft-first scope-gated bridge, authoritative Control Plane, and Fast Journal Desk for Dispatch and ChatGPT.
 * Version: 1.5.2
 * Author: LUNARA FILM
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: advanced-custom-fields-pro
 * Text Domain: lunara-journal-foundation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'LUNARA_JOURNAL_FOUNDATION_VERSION' ) ) {
    define( 'LUNARA_JOURNAL_FOUNDATION_VERSION', '1.5.2' );
}
if ( ! defined( 'LUNARA_JOURNAL_FOUNDATION_FILE' ) ) {
    define( 'LUNARA_JOURNAL_FOUNDATION_FILE', __FILE__ );
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-protocol.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-voice-exemplars.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-config-schema.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-migration.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-config-repository.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-prompt-compiler.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-image-guard.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-validator.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-provenance.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-ingest.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-notion-client.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-notion-sync.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-control-plane.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-voice-upgrade.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-site-studio.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-fast-desk.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-desk-api.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-story-wire.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-publishing.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-desk-rewriter.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-desk-app.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-automation.php';

final class Lunara_Journal_Foundation {
    const VERSION             = LUNARA_JOURNAL_FOUNDATION_VERSION;
    const POST_TYPE           = 'journal';
    const TAX_SECTION         = 'journal_section';
    const TAX_TOPIC           = 'journal_topic';
    const TAX_LEGACY_TYPE     = 'journal_type';
    const OPTION_ENABLED      = 'lunara_journal_bridge_enabled';
    const OPTION_ACTIVATED    = 'lunara_journal_foundation_activated';
    const OPTION_AUTO_CONVERT = 'lunara_journal_dispatch_auto_convert';
    const OPTION_CONVERT_MODE = 'lunara_journal_dispatch_convert_mode';
    const OPTION_LAST_SCAN    = 'lunara_journal_dispatch_last_scan';
    const OPTION_ACCESS_PROFILES = 'lunara_journal_bridge_access_profiles';
    const OPTION_SAFETY_VERSION = 'lunara_journal_foundation_safety_version';
    const META_SKIP_CONVERT   = '_lunara_journal_skip_auto_convert';
    const META_CONVERTED      = '_lunara_journal_converted_from_dispatch';
    const META_IDEMPOTENCY    = '_lunara_dispatch_idempotency_key';
    const CRON_HOOK           = 'lunara_journal_dispatch_scan';
    const REST_NAMESPACE      = 'lunara/v1';
    const REST_TOKEN_HEADER   = 'x-lunara-bridge-token';
    const MIGRATION_CONFIRM_PHRASE = 'CONVERT JOURNAL DRAFTS';

    /**
     * Access profile used for the current REST request.
     *
     * @var array|null
     */
    private static $current_access_profile = null;

    /**
     * Allowed ACF field names for REST bridge writes.
     * Anything not listed here is ignored.
     *
     * @return string[]
     */
    private static function allowed_acf_fields() {
        return array(
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
            'journal_trailer_url',
            'journal_original_dispatch_copy',
            'journal_editorial_angle',
            'journal_chatgpt_brief',
            'journal_chatgpt_revision_notes',
            'journal_last_ai_reviewed_at',
            'journal_writer_source',
            'journal_dispatch_actor',
            'journal_ai_editor',
            'journal_human_reviewer',
            'journal_last_bridge_actor',
            'journal_last_bridge_client',
            'journal_last_bridge_action',
            'journal_last_bridge_updated_at',
            'journal_bridge_audit_summary',
            'journal_seo_title',
            'journal_seo_description',
            'journal_social_x',
            'journal_social_typefully_notes',
            'journal_image_source_url',
            'journal_image_credit',
            'journal_image_alt',
            'journal_validation_status',
            'journal_validation_report',
            'journal_ready_for_review',
            'journal_bridge_locked',
            'journal_bridge_update_count',
            'journal_dispatch_ingested_at',
            'journal_dispatch_conversion_notes',
        );
    }

    public static function bootstrap() {
        add_action( 'init', array( __CLASS__, 'ensure_stabilized_defaults' ), 1 );
        add_action( 'init', array( __CLASS__, 'register_content_model' ), 5 );
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
        add_action( 'admin_notices', array( __CLASS__, 'render_dependency_notice' ) );
        add_action( 'admin_post_lunara_journal_toggle_bridge', array( __CLASS__, 'admin_toggle_bridge' ) );
        add_action( 'admin_post_lunara_journal_dispatch_scan', array( __CLASS__, 'admin_dispatch_scan' ) );
        add_action( 'admin_post_lunara_journal_dispatch_preview', array( __CLASS__, 'admin_dispatch_preview' ) );
        add_action( 'admin_post_lunara_journal_set_dispatch_automation', array( __CLASS__, 'admin_set_dispatch_automation' ) );
        add_action( 'admin_post_lunara_journal_generate_access_key', array( __CLASS__, 'admin_generate_access_key' ) );
        add_action( 'admin_post_lunara_journal_revoke_access_key', array( __CLASS__, 'admin_revoke_access_key' ) );
        add_action( 'save_post_post', array( __CLASS__, 'maybe_convert_dispatch_post_on_save' ), 50, 3 );
        add_action( self::CRON_HOOK, array( __CLASS__, 'cron_scan_dispatch_posts' ) );
        add_action( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
        add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'admin_column_content' ), 10, 2 );

        add_action( 'acf/init', array( __CLASS__, 'register_acf_fields' ) );
        add_filter( 'acf/settings/load_json', array( __CLASS__, 'acf_load_json_path' ) );
    }

    public static function activate() {
        self::ensure_stabilized_defaults();

        if ( false === get_option( self::OPTION_ENABLED, false ) ) {
            add_option( self::OPTION_ENABLED, '1', '', false );
        }

        if ( false === get_option( self::OPTION_AUTO_CONVERT, false ) ) {
            add_option( self::OPTION_AUTO_CONVERT, '0', '', false );
        }

        if ( false === get_option( self::OPTION_CONVERT_MODE, false ) ) {
            add_option( self::OPTION_CONVERT_MODE, 'off', '', false );
        }

        self::sync_conversion_cron( self::is_auto_convert_enabled(), self::get_convert_mode() );

        self::register_content_model();
        self::create_default_terms();
        flush_rewrite_rules();
        update_option( self::OPTION_ACTIVATED, gmdate( 'c' ), false );
    }

    public static function ensure_stabilized_defaults() {
        if ( self::VERSION === get_option( self::OPTION_SAFETY_VERSION, '' ) ) {
            return;
        }

        update_option( self::OPTION_AUTO_CONVERT, '0', false );
        update_option( self::OPTION_CONVERT_MODE, 'off', false );
        wp_clear_scheduled_hook( self::CRON_HOOK );
        self::ensure_access_profiles();
        update_option( self::OPTION_SAFETY_VERSION, self::VERSION, false );
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
        }
        flush_rewrite_rules();
    }

    public static function render_dependency_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $messages = array();
        if ( ! function_exists( 'acf_add_local_field_group' ) ) {
            $messages[] = 'ACF Pro must be active before the Journal editorial fields can be edited.';
        }

        if ( ! class_exists( 'Lunara_Dispatch_Plugin' ) ) {
            $messages[] = 'Lunara Film Dispatch 3.2.0 or newer is required for automated draft collection and Fast Desk runs.';
        } elseif ( ! defined( 'LUNARA_DISPATCH_VERSION' ) || version_compare( LUNARA_DISPATCH_VERSION, '3.2.0', '<' ) ) {
            $messages[] = 'The active Lunara Film Dispatch version is not compatible. Install version 3.2.0 or newer.';
        } elseif ( ! class_exists( 'Lunara_Dispatch_Control_Plane_Client' ) || ! method_exists( 'Lunara_Dispatch_Control_Plane_Client', 'supports_protocol' ) ) {
            $messages[] = 'Lunara Film Dispatch is missing the required Journal protocol compatibility contract.';
        } elseif ( ! Lunara_Dispatch_Control_Plane_Client::supports_protocol( Lunara_Journal_Protocol::VERSION ) ) {
            $messages[] = 'Lunara Film Dispatch does not support Journal protocol ' . Lunara_Journal_Protocol::VERSION . '.';
        }

        if ( empty( $messages ) ) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>LUNARA FILM Journal Foundation needs attention.</strong></p><ul>';
        foreach ( $messages as $message ) {
            echo '<li>' . esc_html( $message ) . '</li>';
        }
        echo '</ul></div>';
    }

    private static function sync_conversion_cron( $enabled, $mode ) {
        unset( $enabled, $mode );
        // Version 1.2.2 never bulk-converts from cron. Legacy conversion is preview-gated.
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public static function register_content_model() {
        $labels = array(
            'name'                  => 'Journal',
            'singular_name'         => 'Journal Entry',
            'menu_name'             => 'Journal',
            'name_admin_bar'        => 'Journal Entry',
            'add_new'               => 'Add New',
            'add_new_item'          => 'Add New Journal Entry',
            'new_item'              => 'New Journal Entry',
            'edit_item'             => 'Edit Journal Entry',
            'view_item'             => 'View Journal Entry',
            'all_items'             => 'All Journal Entries',
            'search_items'          => 'Search Journal',
            'not_found'             => 'No Journal entries found.',
            'not_found_in_trash'    => 'No Journal entries found in Trash.',
            'featured_image'        => 'Journal Featured Image',
            'set_featured_image'    => 'Set featured image',
            'remove_featured_image' => 'Remove featured image',
            'use_featured_image'    => 'Use as featured image',
        );

        register_taxonomy(
            self::TAX_SECTION,
            array( self::POST_TYPE ),
            array(
                'labels'            => array(
                    'name'          => 'Journal Sections',
                    'singular_name' => 'Journal Section',
                    'menu_name'     => 'Journal Sections',
                ),
                'public'            => true,
                'hierarchical'      => true,
                'show_ui'           => true,
                'show_admin_column' => true,
                'show_in_rest'      => true,
                'rewrite'           => array( 'slug' => 'journal-section', 'with_front' => false ),
            )
        );

        register_taxonomy(
            self::TAX_TOPIC,
            array( self::POST_TYPE ),
            array(
                'labels'            => array(
                    'name'          => 'Journal Topics',
                    'singular_name' => 'Journal Topic',
                    'menu_name'     => 'Journal Topics',
                ),
                'public'            => true,
                'hierarchical'      => false,
                'show_ui'           => true,
                'show_admin_column' => true,
                'show_in_rest'      => true,
                'rewrite'           => array( 'slug' => 'journal-topic', 'with_front' => false ),
            )
        );

        if ( taxonomy_exists( self::TAX_LEGACY_TYPE ) ) {
            register_taxonomy_for_object_type( self::TAX_LEGACY_TYPE, self::POST_TYPE );
        } else {
            register_taxonomy(
                self::TAX_LEGACY_TYPE,
                array( self::POST_TYPE ),
                array(
                    'labels'            => array(
                        'name'          => 'Journal Types',
                        'singular_name' => 'Journal Type',
                        'menu_name'     => 'Journal Types',
                    ),
                    'public'            => true,
                    'hierarchical'      => false,
                    'show_ui'           => true,
                    'show_admin_column' => false,
                    'show_in_rest'      => true,
                    'rewrite'           => array( 'slug' => 'journal-type', 'with_front' => false ),
                )
            );
        }

        register_post_type(
            self::POST_TYPE,
            array(
                'labels'              => $labels,
                'description'         => 'LUNARA FILM Journal entries: fast editorial dispatches, reactions, industry notes, and film-culture signals.',
                'public'              => true,
                'publicly_queryable'  => true,
                'exclude_from_search' => false,
                'show_ui'             => true,
                'show_in_menu'        => true,
                'show_in_admin_bar'   => true,
                'show_in_nav_menus'   => true,
                'show_in_rest'        => true,
                'rest_base'           => 'journal',
                'menu_position'       => 6,
                'menu_icon'           => 'dashicons-media-document',
                'capability_type'     => 'post',
                'map_meta_cap'        => true,
                'hierarchical'        => false,
                'has_archive'         => 'journal',
                'rewrite'             => array(
                    'slug'       => 'journal',
                    'with_front' => false,
                    'feeds'      => false,
                    'pages'      => true,
                ),
                'query_var'           => 'journal',
                'supports'            => array(
                    'title',
                    'editor',
                    'author',
                    'thumbnail',
                    'excerpt',
                    'revisions',
                    'custom-fields',
                ),
                'taxonomies'          => array( self::TAX_SECTION, self::TAX_TOPIC, self::TAX_LEGACY_TYPE ),
                'delete_with_user'    => false,
            )
        );
    }

    private static function create_default_terms() {
        $sections = array(
            'News',
            'Trailer Reactions',
            'Awards Season',
            'Box Office',
            'Casting & Production',
            'Physical Media',
            'Streaming',
            'TV & Streaming',
            'Signal',
            'Rumors & Scoops',
        );

        foreach ( $sections as $section ) {
            if ( ! term_exists( $section, self::TAX_SECTION ) ) {
                wp_insert_term( $section, self::TAX_SECTION );
            }
        }
    }

    public static function acf_load_json_path( $paths ) {
        $paths[] = plugin_dir_path( __FILE__ ) . 'acf-json';
        return $paths;
    }

    public static function register_acf_fields() {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) {
            return;
        }

        acf_add_local_field_group( self::get_acf_field_group() );
    }

    private static function get_acf_field_group() {
        return array(
            'key'                   => 'group_lunara_journal_foundation',
            'title'                 => 'LUNARA FILM Journal Editorial Control',
            'fields'                => array(
                array(
                    'key'       => 'field_lunara_journal_tab_editorial',
                    'label'     => 'Editorial',
                    'name'      => '',
                    'type'      => 'tab',
                    'placement' => 'top',
                ),
                array(
                    'key'           => 'field_lunara_journal_kicker',
                    'label'         => 'Kicker',
                    'name'          => 'journal_kicker',
                    'type'          => 'text',
                    'instructions'  => 'Small editorial label above the headline. Example: Trailer Watch, Awards Season, Industry Signal.',
                    'required'      => 0,
                    'wrapper'       => array( 'width' => '33' ),
                    'maxlength'     => 80,
                    'show_in_rest'  => 1,
                ),
                array(
                    'key'           => 'field_lunara_journal_deck',
                    'label'         => 'Deck',
                    'name'          => 'journal_deck',
                    'type'          => 'textarea',
                    'instructions'  => 'One to two sentence reader-facing summary. This should not repeat the headline.',
                    'required'      => 0,
                    'wrapper'       => array( 'width' => '67' ),
                    'rows'          => 3,
                    'new_lines'     => '',
                    'show_in_rest'  => 1,
                ),
                array(
                    'key'           => 'field_lunara_journal_primary_section',
                    'label'         => 'Primary Journal Section',
                    'name'          => 'journal_primary_section',
                    'type'          => 'taxonomy',
                    'taxonomy'      => self::TAX_SECTION,
                    'field_type'    => 'select',
                    'allow_null'    => 1,
                    'add_term'      => 1,
                    'save_terms'    => 1,
                    'load_terms'    => 1,
                    'return_format' => 'id',
                    'wrapper'       => array( 'width' => '33' ),
                    'show_in_rest'  => 1,
                ),
                array(
                    'key'           => 'field_lunara_journal_status',
                    'label'         => 'Journal Workflow Status',
                    'name'          => 'journal_status',
                    'type'          => 'select',
                    'instructions'  => 'Internal editorial workflow status. This never publishes the post by itself.',
                    'required'      => 0,
                    'choices'       => array(
                        'dispatch_generated'   => 'Dispatch Generated',
                        'needs_chatgpt_review' => 'Needs ChatGPT Review',
                        'ready_for_editor'     => 'Ready for Editor',
                        'editor_approved'      => 'Editor Approved',
                        'published'            => 'Published',
                        'held'                 => 'Held',
                        'rejected'             => 'Rejected',
                    ),
                    'default_value' => 'needs_chatgpt_review',
                    'allow_null'    => 0,
                    'ui'            => 1,
                    'return_format' => 'value',
                    'wrapper'       => array( 'width' => '33' ),
                    'show_in_rest'  => 1,
                ),
                array(
                    'key'           => 'field_lunara_journal_item_type',
                    'label'         => 'Journal Item Type',
                    'name'          => 'journal_item_type',
                    'type'          => 'select',
                    'choices'       => array(
                        'news'                => 'News',
                        'trailer'             => 'Trailer Reaction',
                        'casting_production'  => 'Casting & Production',
                        'awards'              => 'Awards Season',
                        'box_office'          => 'Box Office',
                        'streaming'           => 'Streaming',
                        'physical_media'      => 'Physical Media',
                        'tv_streaming'        => 'TV & Streaming',
                        'festival'            => 'Festival',
                        'industry'            => 'Industry',
                        'signal'              => 'Signal',
                    ),
                    'allow_null'    => 1,
                    'ui'            => 1,
                    'return_format' => 'value',
                    'wrapper'       => array( 'width' => '34' ),
                    'show_in_rest'  => 1,
                ),
                array(
                    'key'           => 'field_lunara_journal_priority',
                    'label'         => 'Priority',
                    'name'          => 'journal_priority',
                    'type'          => 'select',
                    'choices'       => array(
                        'low'      => 'Low',
                        'normal'   => 'Normal',
                        'high'     => 'High',
                        'breaking' => 'Breaking',
                    ),
                    'default_value' => 'normal',
                    'allow_null'    => 0,
                    'ui'            => 1,
                    'return_format' => 'value',
                    'wrapper'       => array( 'width' => '33' ),
                    'show_in_rest'  => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_tab_sources',
                    'label'        => 'Sources',
                    'name'         => '',
                    'type'         => 'tab',
                    'placement'    => 'top',
                ),
                array(
                    'key'           => 'field_lunara_journal_source_items',
                    'label'         => 'Source Items',
                    'name'          => 'journal_source_items',
                    'type'          => 'repeater',
                    'instructions'  => 'Source material used by Dispatch and/or ChatGPT. At least one source URL is required before marking ready.',
                    'required'      => 0,
                    'layout'        => 'block',
                    'button_label'  => 'Add Source',
                    'min'           => 0,
                    'max'           => 6,
                    'show_in_rest'  => 1,
                    'sub_fields'    => array(
                        array(
                            'key'          => 'field_lunara_journal_source_headline',
                            'label'        => 'Source Headline',
                            'name'         => 'source_headline',
                            'type'         => 'text',
                            'wrapper'      => array( 'width' => '50' ),
                            'show_in_rest' => 1,
                        ),
                        array(
                            'key'          => 'field_lunara_journal_source_publication',
                            'label'        => 'Publication',
                            'name'         => 'source_publication',
                            'type'         => 'text',
                            'wrapper'      => array( 'width' => '25' ),
                            'show_in_rest' => 1,
                        ),
                        array(
                            'key'          => 'field_lunara_journal_source_author',
                            'label'        => 'Author',
                            'name'         => 'source_author',
                            'type'         => 'text',
                            'wrapper'      => array( 'width' => '25' ),
                            'show_in_rest' => 1,
                        ),
                        array(
                            'key'          => 'field_lunara_journal_source_url',
                            'label'        => 'Source URL',
                            'name'         => 'source_url',
                            'type'         => 'url',
                            'wrapper'      => array( 'width' => '50' ),
                            'show_in_rest' => 1,
                        ),
                        array(
                            'key'            => 'field_lunara_journal_source_published_at',
                            'label'          => 'Published At',
                            'name'           => 'source_published_at',
                            'type'           => 'date_time_picker',
                            'display_format' => 'Y-m-d H:i',
                            'return_format'  => 'Y-m-d H:i:s',
                            'wrapper'        => array( 'width' => '25' ),
                            'show_in_rest'   => 1,
                        ),
                        array(
                            'key'           => 'field_lunara_journal_source_reliability',
                            'label'         => 'Source Reliability',
                            'name'          => 'source_reliability',
                            'type'          => 'select',
                            'choices'       => array(
                                'primary'   => 'Primary / Official',
                                'trade'     => 'Trade',
                                'secondary' => 'Secondary',
                                'social'    => 'Social',
                                'unknown'   => 'Unknown',
                            ),
                            'default_value' => 'unknown',
                            'ui'            => 1,
                            'wrapper'       => array( 'width' => '25' ),
                            'show_in_rest'  => 1,
                        ),
                        array(
                            'key'          => 'field_lunara_journal_source_excerpt',
                            'label'        => 'Source Excerpt / Notes',
                            'name'         => 'source_excerpt',
                            'type'         => 'textarea',
                            'rows'         => 3,
                            'new_lines'    => '',
                            'show_in_rest' => 1,
                        ),
                    ),
                ),
                array(
                    'key'       => 'field_lunara_journal_tab_film_context',
                    'label'     => 'Film Context',
                    'name'      => '',
                    'type'      => 'tab',
                    'placement' => 'top',
                ),
                array(
                    'key'          => 'field_lunara_journal_primary_title',
                    'label'        => 'Primary Film / Series Title',
                    'name'         => 'journal_primary_title',
                    'type'         => 'text',
                    'wrapper'      => array( 'width' => '45' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_primary_year',
                    'label'        => 'Primary Year',
                    'name'         => 'journal_primary_year',
                    'type'         => 'number',
                    'min'          => 1888,
                    'max'          => 2100,
                    'wrapper'      => array( 'width' => '15' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_people',
                    'label'        => 'People Mentioned',
                    'name'         => 'journal_people',
                    'type'         => 'textarea',
                    'instructions' => 'Directors, actors, executives, craftspeople, or critics involved. One per line is fine.',
                    'rows'         => 4,
                    'wrapper'      => array( 'width' => '40' ),
                    'new_lines'    => '',
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_studios_platforms',
                    'label'        => 'Studios / Platforms',
                    'name'         => 'journal_studios_platforms',
                    'type'         => 'text',
                    'wrapper'      => array( 'width' => '50' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_trailer_url',
                    'label'        => 'Trailer / Video URL',
                    'name'         => 'journal_trailer_url',
                    'type'         => 'url',
                    'wrapper'      => array( 'width' => '50' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'       => 'field_lunara_journal_tab_chatgpt',
                    'label'     => 'Dispatch / ChatGPT',
                    'name'      => '',
                    'type'      => 'tab',
                    'placement' => 'top',
                ),
                array(
                    'key'          => 'field_lunara_journal_original_dispatch_copy',
                    'label'        => 'Original Dispatch Copy',
                    'name'         => 'journal_original_dispatch_copy',
                    'type'         => 'textarea',
                    'instructions' => 'Unedited original copy generated by Dispatch before ChatGPT/editorial revision.',
                    'rows'         => 8,
                    'new_lines'    => '',
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_editorial_angle',
                    'label'        => 'Editorial Angle',
                    'name'         => 'journal_editorial_angle',
                    'type'         => 'textarea',
                    'instructions' => 'The concrete reason this is worth publishing on LUNARA FILM.',
                    'rows'         => 4,
                    'new_lines'    => '',
                    'wrapper'      => array( 'width' => '50' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_chatgpt_brief',
                    'label'        => 'ChatGPT Brief',
                    'name'         => 'journal_chatgpt_brief',
                    'type'         => 'textarea',
                    'instructions' => 'Instructions to ChatGPT for this item.',
                    'rows'         => 4,
                    'new_lines'    => '',
                    'wrapper'      => array( 'width' => '50' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_chatgpt_revision_notes',
                    'label'        => 'ChatGPT Revision Notes',
                    'name'         => 'journal_chatgpt_revision_notes',
                    'type'         => 'textarea',
                    'rows'         => 5,
                    'new_lines'    => '',
                    'wrapper'      => array( 'width' => '70' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'            => 'field_lunara_journal_last_ai_reviewed_at',
                    'label'          => 'Last AI Reviewed At',
                    'name'           => 'journal_last_ai_reviewed_at',
                    'type'           => 'date_time_picker',
                    'display_format' => 'Y-m-d H:i',
                    'return_format'  => 'Y-m-d H:i:s',
                    'wrapper'        => array( 'width' => '30' ),
                    'show_in_rest'   => 1,
                ),
                array(
                    'key'           => 'field_lunara_journal_writer_source',
                    'label'         => 'Writer Source',
                    'name'          => 'journal_writer_source',
                    'type'          => 'select',
                    'instructions'  => 'Who originated the draft. This is attribution metadata only; it never changes the WordPress author.',
                    'choices'       => array(
                        'dispatch' => 'Dispatch Automation',
                        'chatgpt'  => 'ChatGPT',
                        'dalton'   => 'Dalton / Human',
                        'mixed'    => 'Mixed',
                        'unknown'  => 'Unknown',
                    ),
                    'default_value' => 'unknown',
                    'allow_null'    => 0,
                    'ui'            => 1,
                    'return_format' => 'value',
                    'wrapper'       => array( 'width' => '30' ),
                    'show_in_rest'  => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_dispatch_actor',
                    'label'        => 'Dispatch Actor',
                    'name'         => 'journal_dispatch_actor',
                    'type'         => 'text',
                    'instructions' => 'Named integration profile that created or converted the draft.',
                    'readonly'     => 1,
                    'wrapper'      => array( 'width' => '35' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_ai_editor',
                    'label'        => 'AI Editor',
                    'name'         => 'journal_ai_editor',
                    'type'         => 'text',
                    'instructions' => 'Named integration profile that last revised the draft through the bridge.',
                    'readonly'     => 1,
                    'wrapper'      => array( 'width' => '35' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_human_reviewer',
                    'label'        => 'Human Reviewer',
                    'name'         => 'journal_human_reviewer',
                    'type'         => 'text',
                    'instructions' => 'Optional name of the human who reviewed the draft before publication.',
                    'wrapper'      => array( 'width' => '30' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_last_bridge_actor',
                    'label'        => 'Last Bridge Actor',
                    'name'         => 'journal_last_bridge_actor',
                    'type'         => 'text',
                    'readonly'     => 1,
                    'wrapper'      => array( 'width' => '35' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_last_bridge_client',
                    'label'        => 'Last Bridge Client',
                    'name'         => 'journal_last_bridge_client',
                    'type'         => 'text',
                    'readonly'     => 1,
                    'wrapper'      => array( 'width' => '35' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_last_bridge_action',
                    'label'        => 'Last Bridge Action',
                    'name'         => 'journal_last_bridge_action',
                    'type'         => 'text',
                    'readonly'     => 1,
                    'wrapper'      => array( 'width' => '30' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'            => 'field_lunara_journal_last_bridge_updated_at',
                    'label'          => 'Last Bridge Updated At',
                    'name'           => 'journal_last_bridge_updated_at',
                    'type'           => 'date_time_picker',
                    'display_format' => 'Y-m-d H:i',
                    'return_format'  => 'Y-m-d H:i:s',
                    'readonly'       => 1,
                    'wrapper'        => array( 'width' => '30' ),
                    'show_in_rest'   => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_bridge_audit_summary',
                    'label'        => 'Bridge Audit Summary',
                    'name'         => 'journal_bridge_audit_summary',
                    'type'         => 'textarea',
                    'rows'         => 3,
                    'new_lines'    => '',
                    'readonly'     => 1,
                    'show_in_rest' => 1,
                ),
                array(
                    'key'            => 'field_lunara_journal_dispatch_ingested_at',
                    'label'          => 'Dispatch Ingested At',
                    'name'           => 'journal_dispatch_ingested_at',
                    'type'           => 'date_time_picker',
                    'display_format' => 'Y-m-d H:i',
                    'return_format'  => 'Y-m-d H:i:s',
                    'readonly'       => 1,
                    'wrapper'        => array( 'width' => '30' ),
                    'show_in_rest'   => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_dispatch_conversion_notes',
                    'label'        => 'Dispatch Conversion Notes',
                    'name'         => 'journal_dispatch_conversion_notes',
                    'type'         => 'textarea',
                    'rows'         => 3,
                    'new_lines'    => '',
                    'readonly'     => 1,
                    'wrapper'      => array( 'width' => '70' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'       => 'field_lunara_journal_tab_seo_social',
                    'label'     => 'SEO / Social',
                    'name'      => '',
                    'type'      => 'tab',
                    'placement' => 'top',
                ),
                array(
                    'key'          => 'field_lunara_journal_seo_title',
                    'label'        => 'SEO Title',
                    'name'         => 'journal_seo_title',
                    'type'         => 'text',
                    'maxlength'    => 70,
                    'wrapper'      => array( 'width' => '50' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_seo_description',
                    'label'        => 'SEO Description',
                    'name'         => 'journal_seo_description',
                    'type'         => 'textarea',
                    'rows'         => 3,
                    'maxlength'    => 180,
                    'new_lines'    => '',
                    'wrapper'      => array( 'width' => '50' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_social_x',
                    'label'        => 'X / Typefully Draft',
                    'name'         => 'journal_social_x',
                    'type'         => 'textarea',
                    'rows'         => 4,
                    'maxlength'    => 280,
                    'new_lines'    => '',
                    'wrapper'      => array( 'width' => '50' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_social_typefully_notes',
                    'label'        => 'Typefully Notes',
                    'name'         => 'journal_social_typefully_notes',
                    'type'         => 'textarea',
                    'rows'         => 4,
                    'new_lines'    => '',
                    'wrapper'      => array( 'width' => '50' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'       => 'field_lunara_journal_tab_image',
                    'label'     => 'Image',
                    'name'      => '',
                    'type'      => 'tab',
                    'placement' => 'top',
                ),
                array(
                    'key'          => 'field_lunara_journal_image_source_url',
                    'label'        => 'Image Source URL',
                    'name'         => 'journal_image_source_url',
                    'type'         => 'url',
                    'wrapper'      => array( 'width' => '50' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_image_credit',
                    'label'        => 'Image Credit',
                    'name'         => 'journal_image_credit',
                    'type'         => 'text',
                    'wrapper'      => array( 'width' => '25' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_image_alt',
                    'label'        => 'Image Alt Text',
                    'name'         => 'journal_image_alt',
                    'type'         => 'text',
                    'wrapper'      => array( 'width' => '25' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'       => 'field_lunara_journal_tab_validation',
                    'label'     => 'Validation',
                    'name'      => '',
                    'type'      => 'tab',
                    'placement' => 'top',
                ),
                array(
                    'key'           => 'field_lunara_journal_validation_status',
                    'label'         => 'Validation Status',
                    'name'          => 'journal_validation_status',
                    'type'          => 'select',
                    'choices'       => array(
                        'unchecked' => 'Unchecked',
                        'passed'    => 'Passed',
                        'warnings'  => 'Warnings',
                        'errors'    => 'Errors',
                    ),
                    'default_value' => 'unchecked',
                    'ui'            => 1,
                    'wrapper'       => array( 'width' => '25' ),
                    'show_in_rest'  => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_ready_for_review',
                    'label'        => 'Ready for Review',
                    'name'         => 'journal_ready_for_review',
                    'type'         => 'true_false',
                    'ui'           => 1,
                    'default_value'=> 0,
                    'wrapper'      => array( 'width' => '25' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_bridge_locked',
                    'label'        => 'Bridge Locked',
                    'name'         => 'journal_bridge_locked',
                    'type'         => 'true_false',
                    'instructions' => 'When enabled, the ChatGPT bridge refuses updates to this entry.',
                    'ui'           => 1,
                    'default_value'=> 0,
                    'wrapper'      => array( 'width' => '25' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_bridge_update_count',
                    'label'        => 'Bridge Update Count',
                    'name'         => 'journal_bridge_update_count',
                    'type'         => 'number',
                    'default_value'=> 0,
                    'min'          => 0,
                    'readonly'     => 1,
                    'wrapper'      => array( 'width' => '25' ),
                    'show_in_rest' => 1,
                ),
                array(
                    'key'          => 'field_lunara_journal_validation_report',
                    'label'        => 'Validation Report',
                    'name'         => 'journal_validation_report',
                    'type'         => 'textarea',
                    'rows'         => 8,
                    'new_lines'    => '',
                    'readonly'     => 1,
                    'show_in_rest' => 1,
                ),
            ),
            'location'              => array(
                array(
                    array(
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => self::POST_TYPE,
                    ),
                ),
            ),
            'menu_order'            => 0,
            'position'              => 'normal',
            'style'                 => 'default',
            'label_placement'       => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen'        => '',
            'active'                => true,
            'description'           => 'Editorial metadata, Dispatch source tracking, ChatGPT revision controls, and validation state for LUNARA FILM Journal entries.',
            'show_in_rest'          => 1,
        );
    }

    /**
     * Validate a positive REST resource ID using WordPress' three-argument
     * validation callback contract.
     *
     * Native one-argument callbacks such as is_numeric() throw an
     * ArgumentCountError when WordPress supplies the request and parameter
     * name on modern PHP versions.
     *
     * @param mixed           $value   Submitted parameter value.
     * @param WP_REST_Request $request Current REST request.
     * @param string          $param   Parameter name.
     * @return bool
     */
    public static function rest_validate_positive_id( $value, $request = null, $param = '' ) {
        unset( $request, $param );

        return is_scalar( $value )
            && 1 === preg_match( '/^\d+$/', (string) $value )
            && absint( $value ) > 0;
    }

    public static function register_rest_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            '/dispatch/drafts',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( __CLASS__, 'rest_list_drafts' ),
                    'permission_callback' => array( __CLASS__, 'rest_permissions_check' ),
                    'args'                => array(
                        'per_page' => array(
                            'type'              => 'integer',
                            'default'           => 20,
                            'minimum'           => 1,
                            'maximum'           => 100,
                            'sanitize_callback' => 'absint',
                        ),
                        'status' => array(
                            'type'              => 'string',
                            'default'           => 'draft,pending,private,auto-draft',
                            'sanitize_callback' => 'sanitize_text_field',
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/dispatch/drafts/(?P<id>\d+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( __CLASS__, 'rest_get_draft' ),
                    'permission_callback' => array( __CLASS__, 'rest_permissions_check' ),
                    'args'                => array(
                        'id' => array(
                            'validate_callback' => array( __CLASS__, 'rest_validate_positive_id' ),
                            'sanitize_callback' => 'absint',
                        ),
                    ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( __CLASS__, 'rest_update_draft' ),
                    'permission_callback' => array( __CLASS__, 'rest_permissions_check' ),
                    'args'                => array(
                        'id' => array(
                            'validate_callback' => array( __CLASS__, 'rest_validate_positive_id' ),
                            'sanitize_callback' => 'absint',
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/dispatch/drafts/(?P<id>\d+)/validate',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( __CLASS__, 'rest_validate_draft' ),
                    'permission_callback' => array( __CLASS__, 'rest_permissions_check' ),
                    'args'                => array(
                        'id' => array(
                            'validate_callback' => array( __CLASS__, 'rest_validate_positive_id' ),
                            'sanitize_callback' => 'absint',
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/dispatch/drafts/(?P<id>\d+)/mark-ready',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( __CLASS__, 'rest_mark_ready' ),
                    'permission_callback' => array( __CLASS__, 'rest_permissions_check' ),
                    'args'                => array(
                        'id' => array(
                            'validate_callback' => array( __CLASS__, 'rest_validate_positive_id' ),
                            'sanitize_callback' => 'absint',
                        ),
                    ),
                ),
            )
        );


        register_rest_route(
            self::REST_NAMESPACE,
            '/dispatch/ingest',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( __CLASS__, 'rest_ingest_dispatch_item' ),
                    'permission_callback' => array( __CLASS__, 'rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/dispatch/convert',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( __CLASS__, 'rest_convert_candidates' ),
                    'permission_callback' => array( __CLASS__, 'rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/dispatch/health',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( __CLASS__, 'rest_health' ),
                    'permission_callback' => array( __CLASS__, 'rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/dispatch/whoami',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( __CLASS__, 'rest_whoami' ),
                    'permission_callback' => array( __CLASS__, 'rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/dispatch/drafts/(?P<id>\d+)/audit',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( __CLASS__, 'rest_get_audit' ),
                    'permission_callback' => array( __CLASS__, 'rest_permissions_check' ),
                    'args'                => array(
                        'id' => array(
                            'validate_callback' => array( __CLASS__, 'rest_validate_positive_id' ),
                            'sanitize_callback' => 'absint',
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/dispatch/schema',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( __CLASS__, 'rest_schema' ),
                    'permission_callback' => array( __CLASS__, 'rest_permissions_check' ),
                ),
            )
        );
    }

    public static function rest_permissions_check( WP_REST_Request $request ) {
        self::$current_access_profile = null;
        $required_scope = self::required_scope_for_request( $request );

        if ( is_user_logged_in() ) {
            if ( ! self::wordpress_user_can_access_request( $request, $required_scope ) ) {
                return new WP_Error( 'lunara_bridge_capability_forbidden', 'You do not have permission to perform this Journal operation.', array( 'status' => 403 ) );
            }
            $user = wp_get_current_user();
            self::$current_access_profile = array(
                'id'       => 'wp_user_' . absint( $user->ID ),
                'label'    => $user->display_name ? $user->display_name : $user->user_login,
                'actor'    => $user->display_name ? $user->display_name : $user->user_login,
                'client'   => 'WordPress authenticated user',
                'scopes'   => array( $required_scope ),
                'auth'     => 'wordpress_user',
                'last4'    => '',
            );
            return true;
        }

        if ( '1' !== get_option( self::OPTION_ENABLED, '1' ) ) {
            return new WP_Error( 'lunara_bridge_disabled', 'The LUNARA FILM Journal bridge is disabled.', array( 'status' => 403 ) );
        }

        $provided = self::get_request_token( $request );
        if ( '' === $provided ) {
            return new WP_Error( 'lunara_bridge_forbidden', 'Missing LUNARA FILM bridge token.', array( 'status' => 403 ) );
        }

        $profile = self::find_access_profile_for_token( $provided );

        if ( ! $profile ) {
            return new WP_Error( 'lunara_bridge_forbidden', 'Invalid LUNARA FILM bridge token.', array( 'status' => 403 ) );
        }

        if ( ! self::profile_has_scope( $profile, $required_scope ) ) {
            return new WP_Error(
                'lunara_bridge_scope_forbidden',
                'This access key does not have the required scope: ' . $required_scope,
                array( 'status' => 403, 'required_scope' => $required_scope )
            );
        }

        self::$current_access_profile = $profile;
        self::record_access_profile_use( $profile );
        return true;
    }

    private static function get_request_token( WP_REST_Request $request ) {
        $provided = $request->get_header( self::REST_TOKEN_HEADER );
        if ( ! $provided ) {
            $provided = $request->get_header( 'x_lunara_bridge_token' );
        }
        if ( ! $provided ) {
            $authorization = $request->get_header( 'authorization' );
            if ( is_string( $authorization ) && preg_match( '/^Bearer\s+(.+)$/i', trim( $authorization ), $matches ) ) {
                $provided = $matches[1];
            }
        }
        return is_string( $provided ) ? trim( $provided ) : '';
    }

    private static function wordpress_user_can_access_request( WP_REST_Request $request, $required_scope ) {
        $post_id = absint( $request->get_param( 'id' ) );

        if ( 'publish' === $required_scope ) {
            return $post_id > 0
                && current_user_can( 'edit_post', $post_id )
                && current_user_can( 'publish_posts' );
        }

        if ( in_array( $required_scope, array( 'update', 'validate', 'mark_ready', 'audit' ), true ) ) {
            return $post_id > 0 && current_user_can( 'edit_post', $post_id );
        }

        if ( 'read' === $required_scope && $post_id > 0 ) {
            return current_user_can( 'edit_post', $post_id );
        }

        if ( 'read' === $required_scope ) {
            return current_user_can( 'edit_others_posts' );
        }

        if ( in_array( $required_scope, array( 'run_dispatch', 'ingest', 'convert', 'schema', 'automation_read', 'capture', 'notify' ), true ) ) {
            return current_user_can( 'manage_options' );
        }

        return false;
    }

    private static function required_scope_for_request( WP_REST_Request $request ) {
        $route  = (string) $request->get_route();
        $method = strtoupper( (string) $request->get_method() );

        if ( false !== strpos( $route, '/journal/automation/run-dispatch' ) ) {
            return 'run_dispatch';
        }
        if ( false !== strpos( $route, '/journal/automation/capture' ) ) {
            return 'capture';
        }
        if ( false !== strpos( $route, '/journal/automation/morning-desk' ) ) {
            return 'notify';
        }
        if ( false !== strpos( $route, '/journal/automation/' ) ) {
            return 'automation_read';
        }
        if ( false !== strpos( $route, '/journal/desk/run-dispatch' ) ) {
            return 'run_dispatch';
        }
        if ( false !== strpos( $route, '/journal/desk/drafts/' ) && false !== strpos( $route, '/publish' ) ) {
            return 'publish';
        }
        if ( false !== strpos( $route, '/journal/desk/drafts/' ) && false !== strpos( $route, '/save-validate' ) ) {
            return 'update';
        }
        if ( false !== strpos( $route, '/journal/desk' ) ) {
            return 'read';
        }
        if ( false !== strpos( $route, '/journal/config/' ) ) {
            return 'schema';
        }

        if ( false !== strpos( $route, '/dispatch/ingest' ) ) {
            return 'ingest';
        }
        if ( false !== strpos( $route, '/dispatch/convert' ) ) {
            return 'convert';
        }
        if ( false !== strpos( $route, '/dispatch/drafts/' ) && false !== strpos( $route, '/validate' ) ) {
            return 'validate';
        }
        if ( false !== strpos( $route, '/dispatch/drafts/' ) && false !== strpos( $route, '/mark-ready' ) ) {
            return 'mark_ready';
        }
        if ( false !== strpos( $route, '/dispatch/drafts/' ) && false !== strpos( $route, '/audit' ) ) {
            return 'audit';
        }
        if ( false !== strpos( $route, '/dispatch/schema' ) || false !== strpos( $route, '/dispatch/health' ) || false !== strpos( $route, '/dispatch/whoami' ) ) {
            return 'schema';
        }
        if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
            return 'update';
        }
        return 'read';
    }

    private static function profile_has_scope( array $profile, $scope ) {
        $scopes = isset( $profile['scopes'] ) && is_array( $profile['scopes'] ) ? $profile['scopes'] : array();
        return in_array( '*', $scopes, true ) || in_array( $scope, $scopes, true );
    }

    private static function sanitize_scope_list( array $scopes ) {
        $out = array();
        foreach ( $scopes as $scope ) {
            $scope = (string) $scope;
            $out[] = '*' === $scope ? '*' : sanitize_key( $scope );
        }
        return array_values( array_unique( array_filter( $out, 'strlen' ) ) );
    }

    public static function rest_health( WP_REST_Request $request ) {
        return rest_ensure_response(
            array(
                'ok'                    => true,
                'plugin'                => 'LUNARA FILM Journal Foundation',
                'version'               => self::VERSION,
                'post_type_registered'  => post_type_exists( self::POST_TYPE ),
                'acf_available'         => function_exists( 'acf_add_local_field_group' ),
                'dispatch_version'      => defined( 'LUNARA_DISPATCH_VERSION' ) ? LUNARA_DISPATCH_VERSION : '',
                'dispatch_protocol_compatible' => class_exists( 'Lunara_Dispatch_Control_Plane_Client' )
                    && method_exists( 'Lunara_Dispatch_Control_Plane_Client', 'supports_protocol' )
                    && Lunara_Dispatch_Control_Plane_Client::supports_protocol( Lunara_Journal_Protocol::VERSION ),
                'bridge_enabled'        => '1' === get_option( self::OPTION_ENABLED, '1' ),
                'auto_convert'          => self::is_auto_convert_enabled(),
                'convert_mode'          => self::get_convert_mode(),
                'access_profile'        => self::public_access_profile( self::$current_access_profile ),
                'available_scopes'      => self::available_access_scopes(),
                'refused_operations'    => array( 'future', 'delete', 'trash', 'status_change_without_explicit_publish' ),
                'checked_at'            => current_time( 'mysql' ),
            )
        );
    }

    public static function rest_whoami( WP_REST_Request $request ) {
        return rest_ensure_response(
            array(
                'authenticated' => true,
                'required_scope'=> self::required_scope_for_request( $request ),
                'profile'       => self::public_access_profile( self::$current_access_profile ),
            )
        );
    }

    /**
     * Return the current request identity without token material.
     *
     * Automation history uses this public, redacted view so IFTTT activity is
     * attributable without exposing the profile registry or credential hash.
     */
    public static function current_access_profile() {
        return self::public_access_profile( self::$current_access_profile );
    }

    public static function rest_get_audit( WP_REST_Request $request ) {
        $post = self::get_journal_post_or_error( absint( $request['id'] ), false );
        if ( is_wp_error( $post ) ) {
            return $post;
        }

        return rest_ensure_response(
            array(
                'post_id'    => $post->ID,
                'audit_log'  => get_post_meta( $post->ID, '_lunara_journal_bridge_log', false ),
                'attribution'=> array(
                    'writer_source'          => self::get_acf_value( 'journal_writer_source', $post->ID ),
                    'dispatch_actor'         => self::get_acf_value( 'journal_dispatch_actor', $post->ID ),
                    'ai_editor'              => self::get_acf_value( 'journal_ai_editor', $post->ID ),
                    'human_reviewer'         => self::get_acf_value( 'journal_human_reviewer', $post->ID ),
                    'last_bridge_actor'      => self::get_acf_value( 'journal_last_bridge_actor', $post->ID ),
                    'last_bridge_client'     => self::get_acf_value( 'journal_last_bridge_client', $post->ID ),
                    'last_bridge_action'     => self::get_acf_value( 'journal_last_bridge_action', $post->ID ),
                    'last_bridge_updated_at' => self::get_acf_value( 'journal_last_bridge_updated_at', $post->ID ),
                ),
            )
        );
    }

    public static function rest_list_drafts( WP_REST_Request $request ) {
        $statuses = self::parse_allowed_statuses( $request->get_param( 'status' ) );

        $query = new WP_Query(
            array(
                'post_type'      => self::POST_TYPE,
                'post_status'    => $statuses,
                'posts_per_page' => min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ) ),
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            )
        );

        $items = array();
        foreach ( $query->posts as $post ) {
            $items[] = self::prepare_post_for_response( $post, false );
        }

        return rest_ensure_response(
            array(
                'items' => $items,
                'count' => count( $items ),
            )
        );
    }

    public static function rest_get_draft( WP_REST_Request $request ) {
        $post = self::get_journal_post_or_error( absint( $request['id'] ), false );
        if ( is_wp_error( $post ) ) {
            return $post;
        }

        return rest_ensure_response( self::prepare_post_for_response( $post, true ) );
    }

    public static function rest_validate_draft( WP_REST_Request $request ) {
        $post = self::get_journal_post_or_error( absint( $request['id'] ), false );
        if ( is_wp_error( $post ) ) {
            return $post;
        }

        $report = Lunara_Journal_Validator::validate_post( $post->ID, Lunara_Journal_Control_Plane::get_active_config() );
        Lunara_Journal_Provenance::attach_validation_result( $post->ID, $report );
        self::update_bridge_attribution_fields( $post->ID, 'validate' );
        self::append_bridge_log( $post->ID, 'validate', array( 'validation_status' => ! empty( $report['valid'] ) ? 'passed' : 'failed' ) );

        return rest_ensure_response( $report );
    }

    public static function rest_update_draft( WP_REST_Request $request ) {
        $post = self::get_journal_post_or_error( absint( $request['id'] ), true );
        if ( is_wp_error( $post ) ) {
            return $post;
        }

        if ( self::truthy( self::get_acf_value( 'journal_bridge_locked', $post->ID ) ) ) {
            return new WP_Error( 'lunara_bridge_locked', 'This Journal entry is bridge locked and cannot be updated through the API.', array( 'status' => 423 ) );
        }

        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            $body = array();
        }

        if ( isset( $body['status'] ) || isset( $body['post_status'] ) ) {
            return new WP_Error( 'lunara_bridge_no_status_change', 'The bridge does not accept post status changes. Publish, future, delete, and trash actions are refused.', array( 'status' => 400 ) );
        }

        $update = array( 'ID' => $post->ID );
        if ( array_key_exists( 'title', $body ) ) {
            $update['post_title'] = wp_strip_all_tags( (string) $body['title'] );
        }
        if ( array_key_exists( 'content', $body ) ) {
            $update['post_content'] = self::sanitize_allowed_post_html( (string) $body['content'] );
        }
        if ( array_key_exists( 'excerpt', $body ) ) {
            $update['post_excerpt'] = sanitize_textarea_field( (string) $body['excerpt'] );
        }

        if ( count( $update ) > 1 ) {
            $result = wp_update_post( wp_slash( $update ), true );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
        }

        if ( isset( $body['acf'] ) && is_array( $body['acf'] ) ) {
            foreach ( $body['acf'] as $field_name => $value ) {
                if ( in_array( $field_name, self::allowed_acf_fields(), true ) ) {
                    self::update_acf_value( $field_name, self::sanitize_acf_value( $field_name, $value ), $post->ID );
                }
            }
        }

        $count = absint( self::get_acf_value( 'journal_bridge_update_count', $post->ID ) );
        self::update_acf_value( 'journal_bridge_update_count', $count + 1, $post->ID );
        self::update_acf_value( 'journal_last_ai_reviewed_at', current_time( 'mysql' ), $post->ID );
        self::update_acf_value( 'journal_status', 'needs_chatgpt_review', $post->ID );
        self::update_bridge_attribution_fields( $post->ID, 'update' );
        self::append_bridge_log( $post->ID, 'update', array( 'fields' => array_keys( $body ) ) );

        clean_post_cache( $post->ID );
        $post = get_post( $post->ID );

        if ( self::truthy( $request->get_param( '_compact' ) ) ) {
            return rest_ensure_response(
                array(
                    'updated'    => true,
                    'post_id'    => $post->ID,
                    'post_status'=> $post->post_status,
                )
            );
        }

        return rest_ensure_response(
            array(
                'updated'    => true,
                'post'       => self::prepare_post_for_response( $post, true ),
                'validation' => self::validate_journal_post( $post ),
            )
        );
    }

    public static function rest_mark_ready( WP_REST_Request $request ) {
        $post = self::get_journal_post_or_error( absint( $request['id'] ), true );
        if ( is_wp_error( $post ) ) {
            return $post;
        }

        $report = Lunara_Journal_Validator::validate_post( $post->ID, Lunara_Journal_Control_Plane::get_active_config() );
        Lunara_Journal_Provenance::attach_validation_result( $post->ID, $report );

        if ( empty( $report['valid'] ) ) {
            return new WP_Error(
                'lunara_validation_errors',
                'This Journal entry cannot be marked ready until validation errors are fixed.',
                array( 'status' => 422, 'report' => $report )
            );
        }

        self::update_acf_value( 'journal_ready_for_review', 1, $post->ID );
        self::update_acf_value( 'journal_status', 'ready_for_editor', $post->ID );
        self::update_acf_value( 'journal_last_ai_reviewed_at', current_time( 'mysql' ), $post->ID );
        self::update_bridge_attribution_fields( $post->ID, 'mark-ready' );
        self::append_bridge_log( $post->ID, 'mark-ready', array( 'validation_status' => ! empty( $report['valid'] ) ? 'passed' : 'failed' ) );

        return rest_ensure_response(
            array(
                'ready'      => true,
                'post_id'    => $post->ID,
                'post_status'=> get_post_status( $post ),
                'validation' => $report,
                'message'    => 'Marked ready for editor review. Post status was not changed to publish.',
            )
        );
    }

    public static function rest_schema() {
        return rest_ensure_response(
            array(
                'post_type'          => self::POST_TYPE,
                'rest_namespace'     => self::REST_NAMESPACE,
                'token_header'       => 'X-Lunara-Bridge-Token',
                'writable_core'      => array( 'title', 'content', 'excerpt' ),
                'writable_acf'       => self::allowed_acf_fields(),
                'ingest_endpoint'    => rest_url( self::REST_NAMESPACE . '/dispatch/ingest' ),
                'convert_endpoint'   => rest_url( self::REST_NAMESPACE . '/dispatch/convert' ),
                'auto_convert'       => self::is_auto_convert_enabled(),
                'convert_mode'       => self::get_convert_mode(),
                'access_model'       => array(
                    'token_header'       => 'X-Lunara-Bridge-Token',
                    'current_profile'    => self::public_access_profile( self::$current_access_profile ),
                    'available_scopes'   => self::available_access_scopes(),
                    'configured_profiles'=> self::public_access_profiles(),
                ),
                'refused_operations' => array( 'future', 'delete', 'trash', 'status_change_outside_dedicated_publish_action' ),
                'publish_disabled_by_default' => true,
            )
        );
    }


    public static function rest_ingest_dispatch_item( WP_REST_Request $request ) {
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            $body = array();
        }

        $result = Lunara_Journal_Ingest::ingest( $body, false );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $post = get_post( $result['post_id'] );
        return rest_ensure_response( array(
            'created'      => ! empty( $result['created'] ),
            'deduplicated' => empty( $result['created'] ),
            'post'         => self::prepare_post_for_response( $post, true ),
            'validation'   => self::validate_journal_post( $post ),
            'message'      => ! empty( $result['created'] ) ? 'Created Journal draft. Post status was forced to draft.' : 'Reused existing Journal draft for this idempotency key.',
        ) );
    }

    public static function rest_convert_candidates( WP_REST_Request $request ) {
        $body  = $request->get_json_params();
        $limit = 25;
        $mode  = 'standard';

        if ( is_array( $body ) ) {
            if ( isset( $body['limit'] ) ) {
                $limit = min( 100, max( 1, absint( $body['limit'] ) ) );
            }
            if ( isset( $body['mode'] ) ) {
                $mode = sanitize_key( (string) $body['mode'] );
            }
        }

        $preview = self::preview_dispatch_candidates( $limit, $mode );
        $confirmation = is_array( $body ) && isset( $body['confirm_conversion'] ) ? sanitize_text_field( (string) $body['confirm_conversion'] ) : '';
        if ( self::MIGRATION_CONFIRM_PHRASE !== $confirmation ) {
            $preview['preview'] = true;
            $preview['confirmation_required'] = self::MIGRATION_CONFIRM_PHRASE;
            return rest_ensure_response( $preview );
        }

        $requested_ids = is_array( $body ) && isset( $body['candidate_ids'] ) && is_array( $body['candidate_ids'] )
            ? array_values( array_unique( array_filter( array_map( 'absint', $body['candidate_ids'] ) ) ) )
            : array();
        $preview_ids = wp_list_pluck( $preview['candidates'], 'id' );
        if ( empty( $requested_ids ) || array_diff( $requested_ids, $preview_ids ) ) {
            return new WP_Error( 'lunara_conversion_preview_mismatch', 'Conversion requires candidate_ids from the current read-only preview.', array( 'status' => 409, 'preview' => $preview ) );
        }

        $result = self::convert_dispatch_candidate_ids( $requested_ids, $mode, 'rest_confirmed' );
        return rest_ensure_response( $result );
    }

    private static function set_journal_terms_from_payload( $post_id, array $payload ) {
        $section_name = ! empty( $payload['section'] ) ? $payload['section'] : 'Signal';
        $section_id   = self::ensure_term_id( $section_name, self::TAX_SECTION );
        if ( is_wp_error( $section_id ) ) {
            return $section_id;
        }

        $section_set = wp_set_object_terms( $post_id, array( $section_id ), self::TAX_SECTION, false );
        if ( is_wp_error( $section_set ) ) {
            return $section_set;
        }
        self::update_acf_value( 'journal_primary_section', $section_id, $post_id );
        if ( ! self::readback_values_match( $section_id, self::get_acf_value( 'journal_primary_section', $post_id ) ) ) {
            return new WP_Error( 'lunara_section_readback_failed', 'Journal primary section field could not be verified.' );
        }
        if ( empty( self::get_acf_value( 'journal_item_type', $post_id ) ) ) {
            self::update_acf_value( 'journal_item_type', self::item_type_from_section_name( $section_name ), $post_id );
        }
        $assigned_sections = wp_get_object_terms( $post_id, self::TAX_SECTION, array( 'fields' => 'ids' ) );
        if ( is_wp_error( $assigned_sections ) || ! in_array( (int) $section_id, array_map( 'intval', (array) $assigned_sections ), true ) ) {
            return new WP_Error( 'lunara_section_term_readback_failed', 'Journal section taxonomy assignment could not be verified.' );
        }

        $legacy_type_id = self::ensure_term_id( $section_name, self::TAX_LEGACY_TYPE );
        if ( is_wp_error( $legacy_type_id ) ) {
            return $legacy_type_id;
        }
        $legacy_set = wp_set_object_terms( $post_id, array( $legacy_type_id ), self::TAX_LEGACY_TYPE, false );
        if ( is_wp_error( $legacy_set ) ) {
            return $legacy_set;
        }
        $assigned_legacy = wp_get_object_terms( $post_id, self::TAX_LEGACY_TYPE, array( 'fields' => 'ids' ) );
        if ( is_wp_error( $assigned_legacy ) || ! in_array( (int) $legacy_type_id, array_map( 'intval', (array) $assigned_legacy ), true ) ) {
            return new WP_Error( 'lunara_legacy_term_readback_failed', 'Legacy Journal taxonomy assignment could not be verified.' );
        }

        $topic_ids = array();
        if ( ! empty( $payload['topics'] ) ) {
            foreach ( $payload['topics'] as $topic ) {
                $topic_id = self::ensure_term_id( $topic, self::TAX_TOPIC );
                if ( is_wp_error( $topic_id ) ) {
                    return $topic_id;
                }
                $topic_ids[] = $topic_id;
            }
            if ( $topic_ids ) {
                $topics_set = wp_set_object_terms( $post_id, $topic_ids, self::TAX_TOPIC, false );
                if ( is_wp_error( $topics_set ) ) {
                    return $topics_set;
                }
                $assigned_topics = wp_get_object_terms( $post_id, self::TAX_TOPIC, array( 'fields' => 'ids' ) );
                if ( is_wp_error( $assigned_topics ) || array_diff( array_map( 'intval', $topic_ids ), array_map( 'intval', (array) $assigned_topics ) ) ) {
                    return new WP_Error( 'lunara_topic_term_readback_failed', 'Journal topic taxonomy assignments could not be verified.' );
                }
            }
        }
        return array(
            'section_id'     => (int) $section_id,
            'legacy_type_id' => (int) $legacy_type_id,
            'topic_ids'      => array_map( 'intval', $topic_ids ),
        );
    }

    private static function ensure_term_id( $name, $taxonomy ) {
        $name = sanitize_text_field( (string) $name );
        if ( '' === $name || ! taxonomy_exists( $taxonomy ) ) {
            return new WP_Error( 'lunara_taxonomy_unavailable', 'Required Journal taxonomy is unavailable: ' . $taxonomy );
        }

        $existing = term_exists( $name, $taxonomy );
        if ( $existing && ! is_wp_error( $existing ) ) {
            return absint( is_array( $existing ) ? $existing['term_id'] : $existing );
        }

        $created = wp_insert_term( $name, $taxonomy );
        if ( is_wp_error( $created ) ) {
            return $created;
        }

        return absint( $created['term_id'] );
    }

    private static function parse_allowed_statuses( $raw ) {
        $allowed = array( 'draft', 'pending', 'private', 'auto-draft' );
        $pieces  = array_map( 'trim', explode( ',', (string) $raw ) );
        $out     = array();

        foreach ( $pieces as $piece ) {
            if ( in_array( $piece, $allowed, true ) ) {
                $out[] = $piece;
            }
        }

        return $out ? $out : array( 'draft', 'pending', 'private', 'auto-draft' );
    }

    private static function get_journal_post_or_error( $post_id, $for_write ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'lunara_not_found', 'Journal entry not found.', array( 'status' => 404 ) );
        }

        if ( self::POST_TYPE !== $post->post_type ) {
            return new WP_Error( 'lunara_wrong_post_type', 'This endpoint only works with the Journal post type.', array( 'status' => 400 ) );
        }

        if ( in_array( $post->post_status, array( 'publish', 'future', 'trash' ), true ) ) {
            return new WP_Error( 'lunara_refuses_published', 'The bridge refuses published, scheduled, or trashed content.', array( 'status' => 403 ) );
        }

        if ( $for_write && ! in_array( $post->post_status, array( 'draft', 'pending', 'private', 'auto-draft' ), true ) ) {
            return new WP_Error( 'lunara_status_not_editable', 'This Journal entry status is not editable through the bridge.', array( 'status' => 403 ) );
        }

        return $post;
    }

    private static function prepare_post_for_response( WP_Post $post, $full ) {
        $acf = array();
        foreach ( self::allowed_acf_fields() as $field_name ) {
            $acf[ $field_name ] = self::get_acf_value( $field_name, $post->ID );
        }

        $data = array(
            'id'             => $post->ID,
            'post_type'      => $post->post_type,
            'status'         => $post->post_status,
            'title'          => get_the_title( $post ),
            'slug'           => $post->post_name,
            'date'           => $post->post_date,
            'modified'       => $post->post_modified,
            'excerpt'        => $post->post_excerpt,
            'featured_media' => get_post_thumbnail_id( $post->ID ),
            'edit_link'      => admin_url( 'post.php?post=' . absint( $post->ID ) . '&action=edit' ),
            'preview_link'   => get_preview_post_link( $post ),
            'terms'          => array(
                self::TAX_SECTION => wp_get_post_terms( $post->ID, self::TAX_SECTION, array( 'fields' => 'names' ) ),
                self::TAX_TOPIC   => wp_get_post_terms( $post->ID, self::TAX_TOPIC, array( 'fields' => 'names' ) ),
                self::TAX_LEGACY_TYPE => wp_get_post_terms( $post->ID, self::TAX_LEGACY_TYPE, array( 'fields' => 'names' ) ),
            ),
            'acf'            => $acf,
        );

        if ( $full ) {
            $data['content']    = $post->post_content;
            $data['bridge_log'] = get_post_meta( $post->ID, '_lunara_journal_bridge_log', false );
        }

        return $data;
    }

    private static function validate_journal_post( WP_Post $post ) {
        $errors   = array();
        $warnings = array();
        $content  = (string) $post->post_content;
        $title    = (string) $post->post_title;
        $excerpt  = (string) $post->post_excerpt;
        $acf      = array();

        foreach ( self::allowed_acf_fields() as $field_name ) {
            $acf[ $field_name ] = self::get_acf_value( $field_name, $post->ID );
        }

        if ( '' === trim( $title ) ) {
            $errors[] = 'Title is missing.';
        }

        if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
            $errors[] = 'Body content is missing.';
        }

        if ( '' === trim( $excerpt ) ) {
            $errors[] = 'Excerpt is missing.';
        }

        if ( ! has_post_thumbnail( $post->ID ) ) {
            $errors[] = 'Featured image is missing.';
        }

        if ( preg_match( '/<h2\b/i', $content ) ) {
            $errors[] = 'Content contains an <h2>; Journal output should not use <h2> headings.';
        }

        // Curly quotes, em dashes and accents are correct UTF-8 and stay. Only flag
        // text that is actually damaged: lost characters (U+FFFD), stray control
        // bytes, or double-encoded mojibake such as "â€™" or "Ã©".
        $text_subject = $title . "\n" . $excerpt . "\n" . $content . "\n" . (string) $acf['journal_seo_description'];
        if ( preg_match( '/\x{FFFD}|[\x{0080}-\x{009F}]|â€|Ã[\x{0080}-\x{00BF}]/u', $text_subject ) ) {
            $warnings[] = 'Damaged characters detected (for example "â€™", "Ã©" or "�"). Re-paste the text from the source.';
        }

        $source_urls = self::extract_source_urls( $acf['journal_source_items'] );
        if ( empty( $source_urls ) ) {
            $errors[] = 'At least one source URL is required.';
        }

        if ( empty( $acf['journal_seo_description'] ) ) {
            $errors[] = 'SEO description is missing.';
        }

        $primary_title = is_string( $acf['journal_primary_title'] ) ? trim( $acf['journal_primary_title'] ) : '';
        if ( $primary_title && false !== stripos( wp_strip_all_tags( $content ), $primary_title ) ) {
            $em_pattern = '/<em>\s*' . preg_quote( $primary_title, '/' ) . '\s*<\/em>/i';
            if ( ! preg_match( $em_pattern, $content ) ) {
                $warnings[] = 'Primary title appears in body copy but may not be wrapped in <em>.';
            }
        }

        $forbidden_phrases = array(
            'highly anticipated',
            'fans are excited',
            'set to captivate',
            'promises to',
            'must-watch',
            'game-changer',
            'cinematic universe',
            'delves into',
            'eagerly awaited',
            'buzzing with excitement',
        );

        foreach ( $forbidden_phrases as $phrase ) {
            if ( false !== stripos( $content, $phrase ) ) {
                $warnings[] = 'Possible PR/generic phrase detected: "' . $phrase . '".';
            }
        }

        $status = 'passed';
        if ( $errors ) {
            $status = 'errors';
        } elseif ( $warnings ) {
            $status = 'warnings';
        }

        return array(
            'post_id'     => $post->ID,
            'status'      => $status,
            'errors'      => $errors,
            'warnings'    => $warnings,
            'checked_at'  => current_time( 'mysql' ),
            'guardrails'  => array(
                'draft_only'       => true,
                'publish_refused'  => true,
                'post_type'        => self::POST_TYPE,
                'required_fields'  => array( 'title', 'content', 'excerpt', 'featured_image', 'source_url', 'seo_description' ),
            ),
        );
    }

    private static function extract_source_urls( $source_items ) {
        $urls = array();
        if ( is_array( $source_items ) ) {
            foreach ( $source_items as $item ) {
                if ( is_array( $item ) && ! empty( $item['source_url'] ) ) {
                    $url = esc_url_raw( $item['source_url'] );
                    if ( $url ) {
                        $urls[] = $url;
                    }
                }
            }
        }
        return array_values( array_unique( $urls ) );
    }

    private static function get_acf_value( $field_name, $post_id ) {
        if ( function_exists( 'get_field' ) ) {
            return get_field( $field_name, $post_id );
        }
        return get_post_meta( $post_id, $field_name, true );
    }

    private static function update_acf_value( $field_name, $value, $post_id ) {
        if ( function_exists( 'update_field' ) ) {
            return update_field( $field_name, $value, $post_id );
        }
        return update_post_meta( $post_id, $field_name, $value );
    }

    private static function verify_acf_fields( $post_id, array $expected, array $field_names ) {
        foreach ( array_values( array_unique( $field_names ) ) as $field_name ) {
            if ( ! array_key_exists( $field_name, $expected ) ) {
                return new WP_Error( 'lunara_missing_required_field', 'Required Journal field was not supplied: ' . $field_name );
            }
            $actual = self::get_acf_value( $field_name, $post_id );
            if ( ! self::readback_values_match( $expected[ $field_name ], $actual, $field_name ) ) {
                return new WP_Error( 'lunara_field_readback_failed', 'Journal field readback failed: ' . $field_name );
            }
        }
        return true;
    }

    private static function readback_values_match( $expected, $actual, $field_name = '' ) {
        if ( is_array( $expected ) ) {
            if ( ! is_array( $actual ) || count( $expected ) !== count( $actual ) ) {
                return false;
            }
            foreach ( $expected as $key => $value ) {
                if ( ! array_key_exists( $key, $actual ) || ! self::readback_values_match( $value, $actual[ $key ], $field_name ) ) {
                    return false;
                }
            }
            return true;
        }
        if ( self::is_boolean_acf_field( $field_name ) && in_array( $expected, array( false, 0, '0' ), true ) && in_array( $actual, array( false, 0, '0' ), true ) ) {
            return true;
        }
        if ( self::is_boolean_acf_field( $field_name ) && in_array( $expected, array( true, 1, '1' ), true ) && in_array( $actual, array( true, 1, '1' ), true ) ) {
            return true;
        }
        if ( ( null === $expected || false === $expected || '' === $expected ) && ( null === $actual || false === $actual || '' === $actual ) ) {
            return true;
        }
        return (string) $expected === (string) $actual;
    }

    private static function is_boolean_acf_field( $field_name ) {
        return in_array( $field_name, array( 'journal_ready_for_review', 'journal_bridge_locked' ), true );
    }

    private static function sanitize_acf_value( $field_name, $value ) {
        if ( 'journal_source_items' === $field_name && is_array( $value ) ) {
            $items = array();
            foreach ( $value as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $items[] = array(
                    'source_headline'    => isset( $item['source_headline'] ) ? sanitize_text_field( $item['source_headline'] ) : '',
                    'source_publication' => isset( $item['source_publication'] ) ? sanitize_text_field( $item['source_publication'] ) : '',
                    'source_author'      => isset( $item['source_author'] ) ? sanitize_text_field( $item['source_author'] ) : '',
                    'source_url'         => isset( $item['source_url'] ) ? esc_url_raw( $item['source_url'] ) : '',
                    'source_published_at'=> isset( $item['source_published_at'] ) ? sanitize_text_field( $item['source_published_at'] ) : '',
                    'source_reliability' => isset( $item['source_reliability'] ) ? sanitize_key( $item['source_reliability'] ) : 'unknown',
                    'source_excerpt'     => isset( $item['source_excerpt'] ) ? sanitize_textarea_field( $item['source_excerpt'] ) : '',
                );
            }
            return $items;
        }

        if ( is_bool( $value ) ) {
            return $value ? 1 : 0;
        }

        if ( is_numeric( $value ) && in_array( $field_name, array( 'journal_primary_year', 'journal_bridge_update_count', 'journal_ready_for_review', 'journal_bridge_locked' ), true ) ) {
            return absint( $value );
        }

        if ( in_array( $field_name, array( 'journal_trailer_url', 'journal_image_source_url' ), true ) ) {
            return esc_url_raw( (string) $value );
        }

        if ( is_array( $value ) ) {
            return array_map( 'sanitize_text_field', $value );
        }

        return sanitize_textarea_field( (string) $value );
    }

    private static function sanitize_allowed_post_html( $html ) {
        $allowed = wp_kses_allowed_html( 'post' );
        $allowed['em'] = array();
        return wp_kses( $html, $allowed );
    }

    private static function truthy( $value ) {
        return in_array( $value, array( true, 1, '1', 'true', 'yes', 'on' ), true );
    }

    public static function current_bridge_actor_context() {
        return self::current_actor_context();
    }

    public static function record_bridge_log_entry( $post_id, $action, $context = array() ) {
        self::append_bridge_log( $post_id, $action, $context );
    }

    public static function update_bridge_attribution( $post_id, $action ) {
        self::update_bridge_attribution_fields( $post_id, $action );
    }

    private static function append_bridge_log( $post_id, $action, $context = array() ) {
        $actor_context = self::current_actor_context();
        $entry = array(
            'action'     => sanitize_key( $action ),
            'context'    => $context,
            'actor'      => $actor_context['actor'],
            'client'     => $actor_context['client'],
            'profile_id' => $actor_context['profile_id'],
            'auth'       => $actor_context['auth'],
            'created_at' => current_time( 'mysql' ),
            'ip_hash'    => isset( $_SERVER['REMOTE_ADDR'] ) ? wp_hash( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '',
        );

        add_post_meta( $post_id, '_lunara_journal_bridge_log', $entry, false );
    }

    private static function update_bridge_attribution_fields( $post_id, $action ) {
        $actor_context = self::current_actor_context();
        $actor  = $actor_context['actor'];
        $client = $actor_context['client'];

        self::update_acf_value( 'journal_last_bridge_actor', $actor, $post_id );
        self::update_acf_value( 'journal_last_bridge_client', $client, $post_id );
        self::update_acf_value( 'journal_last_bridge_action', sanitize_key( $action ), $post_id );
        self::update_acf_value( 'journal_last_bridge_updated_at', current_time( 'mysql' ), $post_id );

        if ( in_array( sanitize_key( $action ), array( 'update', 'mark-ready', 'validate' ), true ) ) {
            self::update_acf_value( 'journal_ai_editor', $actor, $post_id );
            if ( ! self::get_acf_value( 'journal_writer_source', $post_id ) || 'unknown' === self::get_acf_value( 'journal_writer_source', $post_id ) ) {
                self::update_acf_value( 'journal_writer_source', 'mixed', $post_id );
            }
        }

        if ( in_array( sanitize_key( $action ), array( 'ingest', 'auto-convert' ), true ) ) {
            self::update_acf_value( 'journal_dispatch_actor', $actor, $post_id );
            self::update_acf_value( 'journal_writer_source', 'dispatch', $post_id );
        }

        self::update_acf_value(
            'journal_bridge_audit_summary',
            sprintf( 'Last bridge action: %s by %s via %s at %s', sanitize_key( $action ), $actor, $client, current_time( 'mysql' ) ),
            $post_id
        );
    }

    private static function current_actor_context() {
        $profile = is_array( self::$current_access_profile ) ? self::$current_access_profile : array();
        return array(
            'profile_id' => isset( $profile['id'] ) ? sanitize_key( (string) $profile['id'] ) : 'internal',
            'actor'      => ! empty( $profile['actor'] ) ? sanitize_text_field( (string) $profile['actor'] ) : 'LUNARA Journal Foundation',
            'client'     => ! empty( $profile['client'] ) ? sanitize_text_field( (string) $profile['client'] ) : 'Internal WordPress automation',
            'auth'       => ! empty( $profile['auth'] ) ? sanitize_key( (string) $profile['auth'] ) : 'internal',
        );
    }

    private static function available_access_scopes() {
        return array( 'read', 'update', 'validate', 'mark_ready', 'run_dispatch', 'publish', 'ingest', 'convert', 'audit', 'schema', 'automation_read', 'capture', 'notify' );
    }

    private static function default_access_profiles() {
        return array(
            'chatgpt_editor' => array(
                'id'          => 'chatgpt_editor',
                'label'       => 'ChatGPT Editorial Bridge',
                'actor'       => 'ChatGPT with Dalton approval',
                'client'      => 'ChatGPT Action',
                'scopes'      => array( 'read', 'update', 'validate', 'mark_ready', 'run_dispatch', 'audit', 'schema' ),
                'active'      => true,
                'token_hash'  => '',
                'last4'       => '',
                'created_at'  => current_time( 'mysql' ),
                'last_used_at'=> '',
            ),
            'dispatch_ingest' => array(
                'id'          => 'dispatch_ingest',
                'label'       => 'LUNARA FILM Dispatch Automation',
                'actor'       => 'LUNARA Dispatch Automation',
                'client'      => 'WordPress Dispatch plugin',
                'scopes'      => array( 'ingest', 'convert', 'schema' ),
                'active'      => true,
                'token_hash'  => '',
                'last4'       => '',
                'created_at'  => current_time( 'mysql' ),
                'last_used_at'=> '',
            ),
            'ifttt_operator' => array(
                'id'          => 'ifttt_operator',
                'label'       => 'IFTTT Pro+ Operator',
                'actor'       => 'IFTTT for Dalton Johnson',
                'client'      => 'IFTTT Webhooks',
                // Draft creation only. The ingest endpoint hard-codes post_status=draft
                // and rejects caller-supplied status, while publish remains absent.
                'scopes'      => array( 'capture', 'run_dispatch', 'notify', 'ingest' ),
                'active'      => true,
                'token_hash'  => '',
                'last4'       => '',
                'created_at'  => current_time( 'mysql' ),
                'last_used_at'=> '',
            ),
            'dalton_admin' => array(
                'id'          => 'dalton_admin',
                'label'       => 'Dalton Manual Admin Key',
                'actor'       => 'Dalton Johnson',
                'client'      => 'Manual API testing',
                'scopes'      => array( '*' ),
                'active'      => true,
                'token_hash'  => '',
                'last4'       => '',
                'created_at'  => current_time( 'mysql' ),
                'last_used_at'=> '',
            ),
        );
    }

    private static function ensure_access_profiles() {
        $profiles = get_option( self::OPTION_ACCESS_PROFILES, array() );
        if ( ! is_array( $profiles ) ) {
            $profiles = array();
        }

        $defaults = self::default_access_profiles();
        $changed  = false;
        foreach ( $defaults as $id => $profile ) {
            if ( empty( $profiles[ $id ] ) || ! is_array( $profiles[ $id ] ) ) {
                $profiles[ $id ] = $profile;
                $changed = true;
            } else {
                $existing = $profiles[ $id ];
                $merged = array_merge( $profile, $existing );
                $default_scopes = isset( $profile['scopes'] ) && is_array( $profile['scopes'] ) ? $profile['scopes'] : array();
                $existing_scopes = isset( $existing['scopes'] ) && is_array( $existing['scopes'] ) ? $existing['scopes'] : array();
                $merged_scopes = self::sanitize_scope_list( array_merge( $default_scopes, $existing_scopes ) );
                if ( $merged_scopes !== self::sanitize_scope_list( $existing_scopes ) ) {
                    $changed = true;
                }
                $merged['scopes'] = $merged_scopes;
                $merged['id'] = $id;
                $profiles[ $id ] = $merged;
            }
        }

        if ( $changed ) {
            update_option( self::OPTION_ACCESS_PROFILES, $profiles, false );
        }
        return $profiles;
    }

    private static function get_access_profiles() {
        return self::ensure_access_profiles();
    }

    private static function update_access_profiles( array $profiles ) {
        update_option( self::OPTION_ACCESS_PROFILES, $profiles, false );
    }

    private static function find_access_profile_for_token( $provided ) {
        $profiles = self::get_access_profiles();
        foreach ( $profiles as $id => $profile ) {
            if ( empty( $profile['active'] ) || empty( $profile['token_hash'] ) ) {
                continue;
            }
            if ( function_exists( 'wp_check_password' ) && wp_check_password( $provided, $profile['token_hash'] ) ) {
                $profile['id']   = $id;
                $profile['auth'] = 'access_profile_token';
                return $profile;
            }
        }
        return null;
    }

    private static function record_access_profile_use( array $profile ) {
        if ( empty( $profile['id'] ) || 'access_profile_token' !== ( isset( $profile['auth'] ) ? $profile['auth'] : '' ) ) {
            return;
        }
        $profiles = self::get_access_profiles();
        $id = sanitize_key( (string) $profile['id'] );
        if ( empty( $profiles[ $id ] ) ) {
            return;
        }
        $throttle_key = 'lunara_journal_access_seen_' . $id;
        if ( get_transient( $throttle_key ) ) {
            return;
        }
        set_transient( $throttle_key, 1, 5 * MINUTE_IN_SECONDS );
        $profiles[ $id ]['last_used_at'] = current_time( 'mysql' );
        $profiles[ $id ]['last_used_ip_hash'] = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_hash( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
        self::update_access_profiles( $profiles );
    }

    private static function public_access_profile( $profile ) {
        if ( ! is_array( $profile ) ) {
            return null;
        }
        return array(
            'id'           => isset( $profile['id'] ) ? sanitize_key( (string) $profile['id'] ) : '',
            'label'        => isset( $profile['label'] ) ? sanitize_text_field( (string) $profile['label'] ) : '',
            'actor'        => isset( $profile['actor'] ) ? sanitize_text_field( (string) $profile['actor'] ) : '',
            'client'       => isset( $profile['client'] ) ? sanitize_text_field( (string) $profile['client'] ) : '',
            'scopes'       => self::sanitize_scope_list( isset( $profile['scopes'] ) && is_array( $profile['scopes'] ) ? $profile['scopes'] : array() ),
            'active'       => ! empty( $profile['active'] ),
            'has_token'    => ! empty( $profile['token_hash'] ),
            'token_last4'  => isset( $profile['last4'] ) ? sanitize_text_field( (string) $profile['last4'] ) : '',
            'last_used_at' => isset( $profile['last_used_at'] ) ? sanitize_text_field( (string) $profile['last_used_at'] ) : '',
        );
    }

    private static function public_access_profiles() {
        $out = array();
        foreach ( self::get_access_profiles() as $id => $profile ) {
            $profile['id'] = $id;
            $out[] = self::public_access_profile( $profile );
        }
        return $out;
    }

    private static function generate_access_token_for_profile( $profile_id ) {
        $profiles = self::get_access_profiles();
        $profile_id = sanitize_key( (string) $profile_id );
        if ( empty( $profiles[ $profile_id ] ) ) {
            return new WP_Error( 'lunara_unknown_profile', 'Unknown access profile.', array( 'status' => 400 ) );
        }

        $token = 'ljb_' . wp_generate_password( 64, false, false );
        $profiles[ $profile_id ]['token_hash'] = wp_hash_password( $token );
        $profiles[ $profile_id ]['last4'] = substr( $token, -4 );
        $profiles[ $profile_id ]['active'] = true;
        $profiles[ $profile_id ]['generated_at'] = current_time( 'mysql' );
        self::update_access_profiles( $profiles );

        return $token;
    }

    private static function revoke_access_token_for_profile( $profile_id ) {
        $profiles = self::get_access_profiles();
        $profile_id = sanitize_key( (string) $profile_id );
        if ( empty( $profiles[ $profile_id ] ) ) {
            return new WP_Error( 'lunara_unknown_profile', 'Unknown access profile.', array( 'status' => 400 ) );
        }
        $profiles[ $profile_id ]['token_hash'] = '';
        $profiles[ $profile_id ]['last4'] = '';
        $profiles[ $profile_id ]['revoked_at'] = current_time( 'mysql' );
        self::update_access_profiles( $profiles );
        return true;
    }

    private static function is_auto_convert_enabled() {
        return '1' === get_option( self::OPTION_AUTO_CONVERT, '0' );
    }

    private static function get_convert_mode() {
        $mode = sanitize_key( (string) get_option( self::OPTION_CONVERT_MODE, 'off' ) );
        return in_array( $mode, array( 'off', 'standard', 'aggressive' ), true ) ? $mode : 'off';
    }

    public static function maybe_convert_dispatch_post_on_save( $post_id, $post, $update ) {
        static $converting = array();

        if ( isset( $converting[ $post_id ] ) ) {
            return;
        }
        if ( ! self::is_auto_convert_enabled() || 'off' === self::get_convert_mode() ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( ! $post instanceof WP_Post ) {
            $post = get_post( $post_id );
        }
        if ( ! $post || 'post' !== $post->post_type ) {
            return;
        }
        if ( ! self::is_dispatch_candidate( $post_id, $post, self::get_convert_mode() ) ) {
            return;
        }

        $converting[ $post_id ] = true;
        self::convert_dispatch_post_to_journal( $post_id, 'save_post' );
        unset( $converting[ $post_id ] );
    }

    public static function cron_scan_dispatch_posts() {
        if ( ! self::is_auto_convert_enabled() || 'off' === self::get_convert_mode() ) {
            return;
        }
        $preview = self::preview_dispatch_candidates( 25, self::get_convert_mode() );
        update_option( self::OPTION_LAST_SCAN, 'Read-only preview: ' . absint( $preview['candidate_count'] ) . ' candidate(s) at ' . current_time( 'mysql' ), false );
    }

    private static function scan_and_convert_dispatch_posts( $limit = 25, $mode = null ) {
        $mode = $mode ? sanitize_key( (string) $mode ) : self::get_convert_mode();
        if ( ! in_array( $mode, array( 'standard', 'aggressive' ), true ) ) {
            $mode = 'standard';
        }

        $preview = self::preview_dispatch_candidates( $limit, $mode );
        return self::convert_dispatch_candidate_ids( wp_list_pluck( $preview['candidates'], 'id' ), $mode, 'scan_' . $mode );
    }

    private static function preview_dispatch_candidates( $limit = 25, $mode = 'standard' ) {
        $mode = sanitize_key( (string) $mode );
        if ( ! in_array( $mode, array( 'standard', 'aggressive' ), true ) ) {
            $mode = 'standard';
        }

        $query = new WP_Query(
            array(
                'post_type'      => 'post',
                'post_status'    => array( 'draft', 'pending', 'private', 'auto-draft' ),
                'posts_per_page' => min( 100, max( 1, absint( $limit ) ) ),
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'no_found_rows'  => true,
                'meta_query'     => array(
                    array(
                        'key'     => self::META_CONVERTED,
                        'compare' => 'NOT EXISTS',
                    ),
                ),
            )
        );

        $candidates = array();

        foreach ( $query->posts as $post ) {
            if ( self::is_dispatch_candidate( $post->ID, $post, $mode ) ) {
                $candidates[] = array(
                    'id'        => (int) $post->ID,
                    'title'     => get_the_title( $post ),
                    'status'    => $post->post_status,
                    'edit_link' => admin_url( 'post.php?post=' . absint( $post->ID ) . '&action=edit' ),
                );
            }
        }

        return array(
            'mode'            => $mode,
            'candidate_count' => count( $candidates ),
            'candidates'      => $candidates,
            'scanned'         => count( $query->posts ),
            'generated_at'    => current_time( 'mysql' ),
        );
    }

    private static function convert_dispatch_candidate_ids( array $candidate_ids, $mode, $source ) {
        $converted = array();
        $skipped = array();
        foreach ( array_values( array_unique( array_filter( array_map( 'absint', $candidate_ids ) ) ) ) as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post || ! self::is_dispatch_candidate( $post_id, $post, $mode ) ) {
                $skipped[] = array( 'id' => $post_id, 'reason' => 'Candidate no longer qualifies for conversion.' );
                continue;
            }
            $result = self::convert_dispatch_post_to_journal( $post_id, $source );
            if ( is_wp_error( $result ) ) {
                $skipped[] = array( 'id' => $post_id, 'reason' => $result->get_error_message() );
            } else {
                $converted[] = self::prepare_post_for_response( get_post( $post_id ), false );
            }
        }

        update_option( self::OPTION_LAST_SCAN, current_time( 'mysql' ), false );
        return array(
            'mode'            => $mode,
            'converted_count' => count( $converted ),
            'converted'       => $converted,
            'skipped'         => $skipped,
            'last_scan'       => current_time( 'mysql' ),
        );
    }

    private static function is_dispatch_candidate( $post_id, WP_Post $post, $mode = 'standard' ) {
        if ( 'post' !== $post->post_type ) {
            return false;
        }
        if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'private', 'auto-draft' ), true ) ) {
            return false;
        }
        if ( get_post_meta( $post_id, self::META_SKIP_CONVERT, true ) ) {
            return false;
        }
        if ( get_post_meta( $post_id, self::META_CONVERTED, true ) ) {
            return false;
        }

        $cats       = get_the_category( $post_id );
        $cat_slugs  = array();
        $cat_names  = array();
        foreach ( $cats as $cat ) {
            $cat_slugs[] = $cat->slug;
            $cat_names[] = $cat->name;
        }

        if ( in_array( 'journal', $cat_slugs, true ) ) {
            return true;
        }

        $all_meta = get_post_meta( $post_id );
        foreach ( array_keys( $all_meta ) as $meta_key ) {
            $meta_key_l = strtolower( (string) $meta_key );
            if ( false !== strpos( $meta_key_l, 'dispatch' ) || false !== strpos( $meta_key_l, 'journal_' ) || false !== strpos( $meta_key_l, 'lunara_dispatch' ) ) {
                return true;
            }
        }

        $haystack = strtolower( $post->post_title . ' ' . $post->post_content );
        if ( false !== strpos( $haystack, 'lunara dispatch' ) || false !== strpos( $haystack, '<!-- lunara-dispatch' ) ) {
            return true;
        }

        if ( 'aggressive' === $mode ) {
            $watch = array(
                'news',
                'trailer-reactions',
                'awards-season',
                'box-office',
                'casting-production',
                'physical-media',
                'streaming',
                'tv-streaming',
                'signal',
                'rumors-scoops',
            );
            if ( array_intersect( $watch, $cat_slugs ) ) {
                return true;
            }
        }

        return false;
    }

    private static function convert_dispatch_post_to_journal( $post_id, $source ) {
        $post = get_post( $post_id );
        if ( ! $post || 'post' !== $post->post_type ) {
            return new WP_Error( 'lunara_convert_wrong_type', 'Only standard post drafts can be converted.', array( 'status' => 400 ) );
        }
        if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'private', 'auto-draft' ), true ) ) {
            return new WP_Error( 'lunara_convert_refuses_status', 'Only draft, pending, private, or auto-draft posts can be converted.', array( 'status' => 403 ) );
        }

        $legacy_categories = get_the_category( $post_id );
        $update = array(
            'ID'        => $post_id,
            'post_type' => self::POST_TYPE,
        );
        $result = wp_update_post( wp_slash( $update ), true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        clean_post_cache( $post_id );
        $journal = get_post( $post_id );
        if ( ! $journal || self::POST_TYPE !== $journal->post_type ) {
            return new WP_Error( 'lunara_conversion_readback_failed', 'Converted Journal entry could not be verified after the post type update.' );
        }

        update_post_meta( $post_id, '_lunara_legacy_post_type', 'post' );
        $legacy_category_slugs = wp_list_pluck( $legacy_categories, 'slug' );
        update_post_meta( $post_id, '_lunara_legacy_categories', $legacy_category_slugs );
        if ( 'post' !== get_post_meta( $post_id, '_lunara_legacy_post_type', true ) || ! self::readback_values_match( $legacy_category_slugs, get_post_meta( $post_id, '_lunara_legacy_categories', true ) ) ) {
            return self::fail_conversion( $post_id, 'Legacy post metadata could not be verified.' );
        }

        $payload = self::payload_from_legacy_post( $journal, $legacy_categories );
        foreach ( $payload['acf'] as $field_name => $value ) {
            self::update_acf_value( $field_name, $value, $post_id );
        }
        $required_fields = array( 'journal_deck', 'journal_status', 'journal_item_type', 'journal_original_dispatch_copy', 'journal_seo_description', 'journal_ready_for_review', 'journal_dispatch_conversion_notes' );
        if ( ! empty( $payload['acf']['journal_source_items'] ) ) {
            $required_fields[] = 'journal_source_items';
        }
        $acf_readback = self::verify_acf_fields( $post_id, $payload['acf'], $required_fields );
        if ( is_wp_error( $acf_readback ) ) {
            return self::fail_conversion( $post_id, $acf_readback->get_error_message() );
        }

        $term_result = self::set_journal_terms_from_payload( $post_id, $payload );
        if ( is_wp_error( $term_result ) ) {
            return self::fail_conversion( $post_id, $term_result->get_error_message() );
        }
        self::disable_jetpack_publicize_for_post( $post_id );

        $report = self::validate_journal_post( get_post( $post_id ) );
        self::update_acf_value( 'journal_validation_status', $report['status'], $post_id );
        // Meta saves strip backslashes, which broke this JSON (\" and \u2019) and
        // failed the readback check. Slash it, and keep Unicode literal.
        $validation_report = wp_slash( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        self::update_acf_value( 'journal_validation_report', $validation_report, $post_id );
        $validation_readback = self::verify_acf_fields( $post_id, array(
            'journal_validation_status' => $report['status'],
            'journal_validation_report' => $validation_report,
        ), array( 'journal_validation_status', 'journal_validation_report' ) );
        if ( is_wp_error( $validation_readback ) ) {
            return self::fail_conversion( $post_id, $validation_readback->get_error_message() );
        }
        clean_post_cache( $post_id );
        $journal = get_post( $post_id );
        if ( ! $journal || self::POST_TYPE !== $journal->post_type || ! in_array( $journal->post_status, array( 'draft', 'pending', 'private', 'auto-draft' ), true ) ) {
            return self::fail_conversion( $post_id, 'Final Journal draft readback failed.' );
        }

        $converted_at = current_time( 'mysql', true );
        update_post_meta( $post_id, self::META_CONVERTED, $converted_at );
        if ( $converted_at !== get_post_meta( $post_id, self::META_CONVERTED, true ) ) {
            return self::fail_conversion( $post_id, 'The conversion completion marker could not be verified.' );
        }
        delete_post_meta( $post_id, '_lunara_journal_conversion_quarantined' );
        self::update_bridge_attribution_fields( $post_id, 'auto-convert' );
        self::append_bridge_log( $post_id, 'auto-convert', array( 'source' => sanitize_key( $source ) ) );

        return $post_id;
    }

    private static function fail_conversion( $post_id, $message ) {
        delete_post_meta( $post_id, self::META_CONVERTED );
        $quarantine = array(
            'message'        => sanitize_text_field( (string) $message ),
            'quarantined_at' => current_time( 'mysql', true ),
        );
        update_post_meta( $post_id, '_lunara_journal_conversion_quarantined', $quarantine );

        $rollback = wp_update_post( array( 'ID' => $post_id, 'post_type' => 'post' ), true );
        clean_post_cache( $post_id );
        $readback = get_post( $post_id );
        $rolled_back = ! is_wp_error( $rollback ) && $readback && 'post' === $readback->post_type;

        return new WP_Error(
            'lunara_conversion_incomplete',
            $rolled_back
                ? 'Conversion verification failed and the post was restored for a safe retry: ' . $message
                : 'Conversion verification failed. The unpublished Journal entry was quarantined for manual repair: ' . $message,
            array(
                'post_id'     => (int) $post_id,
                'retryable'   => $rolled_back,
                'quarantined' => ! $rolled_back,
            )
        );
    }

    private static function payload_from_legacy_post( WP_Post $post, array $legacy_categories ) {
        $section = self::infer_section_from_legacy_categories( $legacy_categories );
        $topics  = self::infer_topics_from_legacy_categories( $legacy_categories );
        $content = (string) $post->post_content;
        $excerpt = (string) $post->post_excerpt;
        $urls    = self::extract_urls_from_html( $content );

        $source_items = array();
        foreach ( $urls as $url ) {
            $source_items[] = array(
                'source_headline'     => $post->post_title,
                'source_publication'  => '',
                'source_author'       => '',
                'source_url'          => $url,
                'source_published_at' => '',
                'source_reliability'  => 'unknown',
                'source_excerpt'      => '',
            );
        }

        $thumbnail_id = get_post_thumbnail_id( $post->ID );
        $image_url    = $thumbnail_id ? wp_get_attachment_url( $thumbnail_id ) : '';
        $image_alt    = $thumbnail_id ? get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) : '';

        return array(
            'title'          => $post->post_title,
            'content'        => $content,
            'excerpt'        => $excerpt,
            'section'        => $section,
            'topics'         => $topics,
            'featured_media' => $thumbnail_id,
            'acf'            => array(
                'journal_deck'                      => $excerpt ? $excerpt : self::make_plaintext_excerpt( $content, 260 ),
                'journal_status'                    => 'needs_chatgpt_review',
                'journal_item_type'                 => self::item_type_from_section_name( $section ),
                'journal_priority'                  => 'normal',
                'journal_source_items'              => $source_items,
                'journal_original_dispatch_copy'    => $content,
                'journal_chatgpt_brief'             => 'Revise this Dispatch-generated draft into LUNARA FILM Journal voice, preserve sourced claims, remove generic PR phrasing, keep film titles in <em>, and return draft-only copy.',
                'journal_seo_description'           => self::make_plaintext_excerpt( $excerpt ? $excerpt : $content, 155 ),
                'journal_image_source_url'          => $image_url,
                'journal_image_alt'                 => is_string( $image_alt ) ? $image_alt : '',
                'journal_ready_for_review'          => 0,
                'journal_dispatch_ingested_at'      => current_time( 'mysql' ),
                'journal_dispatch_conversion_notes' => 'Converted from standard post by LUNARA FILM Journal Foundation adapter.',
            ),
        );
    }

    private static function infer_section_from_legacy_categories( array $categories ) {
        $map = array(
            'news'               => 'News',
            'trailer-reactions'  => 'Trailer Reactions',
            'awards-season'      => 'Awards Season',
            'box-office'         => 'Box Office',
            'casting-production' => 'Casting & Production',
            'physical-media'     => 'Physical Media',
            'streaming'          => 'Streaming',
            'tv-streaming'       => 'TV & Streaming',
            'signal'             => 'Signal',
            'rumors-scoops'      => 'Rumors & Scoops',
        );

        foreach ( $categories as $cat ) {
            if ( isset( $map[ $cat->slug ] ) ) {
                return $map[ $cat->slug ];
            }
        }

        return 'Signal';
    }

    private static function infer_topics_from_legacy_categories( array $categories ) {
        $ignore = array(
            'journal',
            'uncategorized',
            'movie-review',
            'review',
            'essays',
            'spoilers',
        );
        $topics = array();
        foreach ( $categories as $cat ) {
            if ( in_array( $cat->slug, $ignore, true ) ) {
                continue;
            }
            if ( preg_match( '/^\d{4}$/', $cat->slug ) ) {
                continue;
            }
            $topics[] = $cat->name;
        }
        return array_values( array_unique( $topics ) );
    }

    private static function item_type_from_section_name( $section ) {
        $section = strtolower( html_entity_decode( (string) $section, ENT_QUOTES, get_bloginfo( 'charset' ) ) );
        $section = str_replace( '&', 'and', $section );
        if ( false !== strpos( $section, 'trailer' ) ) { return 'trailer'; }
        if ( false !== strpos( $section, 'award' ) ) { return 'awards'; }
        if ( false !== strpos( $section, 'box office' ) ) { return 'box_office'; }
        if ( false !== strpos( $section, 'casting' ) || false !== strpos( $section, 'production' ) ) { return 'casting_production'; }
        if ( false !== strpos( $section, 'physical' ) ) { return 'physical_media'; }
        if ( false !== strpos( $section, 'tv' ) ) { return 'tv_streaming'; }
        if ( false !== strpos( $section, 'streaming' ) ) { return 'streaming'; }
        if ( false !== strpos( $section, 'rumor' ) || false !== strpos( $section, 'scoop' ) ) { return 'signal'; }
        if ( false !== strpos( $section, 'news' ) ) { return 'news'; }
        return 'signal';
    }

    private static function section_name_from_item_type( $item_type ) {
        $map = array(
            'news'               => 'News',
            'trailer'            => 'Trailer Reactions',
            'casting_production' => 'Casting & Production',
            'awards'             => 'Awards Season',
            'box_office'         => 'Box Office',
            'streaming'          => 'Streaming',
            'physical_media'     => 'Physical Media',
            'tv_streaming'       => 'TV & Streaming',
            'festival'           => 'Signal',
            'industry'           => 'Signal',
            'signal'             => 'Signal',
        );
        $item_type = sanitize_key( (string) $item_type );
        return isset( $map[ $item_type ] ) ? $map[ $item_type ] : 'Signal';
    }

    private static function extract_urls_from_html( $html ) {
        $urls = array();
        if ( preg_match_all( '/https?:\/\/[^\s"\'<>]+/i', (string) $html, $matches ) ) {
            foreach ( $matches[0] as $url ) {
                $url = esc_url_raw( html_entity_decode( $url ) );
                if ( $url ) {
                    $urls[] = $url;
                }
            }
        }
        return array_values( array_unique( $urls ) );
    }

    private static function make_plaintext_excerpt( $text, $limit = 155 ) {
        $plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $plain, 0, $limit );
        }
        return substr( $plain, 0, $limit );
    }

    private static function disable_jetpack_publicize_for_post( $post_id ) {
        update_post_meta( $post_id, '_jetpack_dont_email_post_to_subs', 1 );
        update_post_meta( $post_id, 'jetpack_publicize_feature_enabled', 0 );
        update_post_meta( $post_id, 'jetpack_publicize_message', '' );
    }

    public static function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            'Journal Bridge',
            'Journal Bridge',
            'manage_options',
            'lunara-journal-bridge',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $enabled      = '1' === get_option( self::OPTION_ENABLED, '1' );
        $base         = esc_url( rest_url( self::REST_NAMESPACE . '/dispatch' ) );
        $auto_convert = self::is_auto_convert_enabled();
        $convert_mode = self::get_convert_mode();
        $last_scan    = get_option( self::OPTION_LAST_SCAN, 'Never' );
        $profiles     = self::public_access_profiles();
        $conversion_preview = get_transient( self::migration_preview_transient_key() );
        $new_key      = get_transient( 'lunara_journal_generated_key_' . get_current_user_id() );
        if ( $new_key ) {
            delete_transient( 'lunara_journal_generated_key_' . get_current_user_id() );
        }
        ?>
        <div class="wrap">
            <h1>LUNARA FILM Journal Bridge</h1>
            <p>This plugin registers the <strong>Journal</strong> custom post type, ACF editorial fields, and draft-first scope-gated REST endpoints for Dispatch/ChatGPT review.</p>

            <?php if ( is_array( $new_key ) && ! empty( $new_key['token'] ) ) : ?>
                <div class="notice notice-success" style="padding:12px 16px;max-width:900px;">
                    <p><strong>New access key generated for <?php echo esc_html( $new_key['label'] ); ?>.</strong> Copy it now. It will not be shown again.</p>
                    <p><input type="text" readonly value="<?php echo esc_attr( $new_key['token'] ); ?>" style="width:100%;font-family:monospace;" onclick="this.select();" /></p>
                </div>
            <?php endif; ?>

            <h2>Status</h2>
            <table class="widefat striped" style="max-width: 900px;">
                <tbody>
                    <tr><th scope="row">Bridge enabled</th><td><?php echo $enabled ? 'Yes' : 'No'; ?></td></tr>
                    <tr><th scope="row">REST base</th><td><code><?php echo esc_html( $base ); ?></code></td></tr>
                    <tr><th scope="row">Token header</th><td><code>X-Lunara-Bridge-Token</code></td></tr>
                    <tr><th scope="row">Dispatch auto-convert</th><td><?php echo $auto_convert ? 'Enabled' : 'Disabled'; ?> / <?php echo esc_html( $convert_mode ); ?></td></tr>
                    <tr><th scope="row">Last Dispatch scan</th><td><?php echo esc_html( (string) $last_scan ); ?></td></tr>
                </tbody>
            </table>

            <p><strong>Guardrail:</strong> publishing is disabled by default and unavailable to the standard ChatGPT editor key. A separately authorized publish action still requires an editable draft, publish capability, deterministic validation, and explicit per-entry confirmation. Scheduling, trash, and delete operations remain unavailable.</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                <?php wp_nonce_field( 'lunara_journal_toggle_bridge' ); ?>
                <input type="hidden" name="action" value="lunara_journal_toggle_bridge" />
                <input type="hidden" name="enabled" value="<?php echo $enabled ? '0' : '1'; ?>" />
                <?php submit_button( $enabled ? 'Disable Bridge' : 'Enable Bridge', $enabled ? 'delete' : 'primary', 'submit', false ); ?>
            </form>

            <h2>Integrated access profiles</h2>
            <p>Use separate keys so the audit log can say exactly whether a draft came from Dispatch, ChatGPT, Dalton, or a legacy integration. Tokens are stored hashed; newly generated keys are shown once.</p>
            <table class="widefat striped" style="max-width: 1100px;">
                <thead>
                    <tr>
                        <th>Profile</th>
                        <th>Actor</th>
                        <th>Client</th>
                        <th>Scopes</th>
                        <th>Token</th>
                        <th>Last used</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $profiles as $profile ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $profile['label'] ); ?></strong><br><code><?php echo esc_html( $profile['id'] ); ?></code></td>
                            <td><?php echo esc_html( $profile['actor'] ); ?></td>
                            <td><?php echo esc_html( $profile['client'] ); ?></td>
                            <td><code><?php echo esc_html( implode( ', ', $profile['scopes'] ) ); ?></code></td>
                            <td><?php echo $profile['has_token'] ? 'Generated, ending ' . esc_html( $profile['token_last4'] ) : '<strong>Not generated</strong>'; ?></td>
                            <td><?php echo esc_html( $profile['last_used_at'] ? $profile['last_used_at'] : 'Never' ); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:6px;">
                                    <?php wp_nonce_field( 'lunara_journal_generate_access_key' ); ?>
                                    <input type="hidden" name="action" value="lunara_journal_generate_access_key" />
                                    <input type="hidden" name="profile_id" value="<?php echo esc_attr( $profile['id'] ); ?>" />
                                    <?php submit_button( $profile['has_token'] ? 'Rotate Key' : 'Generate Key', 'secondary', 'submit', false ); ?>
                                </form>
                                <?php if ( $profile['has_token'] ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                                        <?php wp_nonce_field( 'lunara_journal_revoke_access_key' ); ?>
                                        <input type="hidden" name="action" value="lunara_journal_revoke_access_key" />
                                        <input type="hidden" name="profile_id" value="<?php echo esc_attr( $profile['id'] ); ?>" />
                                        <?php submit_button( 'Revoke', 'delete', 'submit', false ); ?>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Dispatch automation</h2>
            <p><strong>Standard mode</strong> converts draft standard posts that are clearly Dispatch/Journal items: Journal category, Dispatch meta, or LUNARA Dispatch markers. <strong>Aggressive mode</strong> also converts draft posts in Journal-adjacent categories such as News, Trailer Reactions, Awards Season, Box Office, Streaming, and Signal.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;">
                <?php wp_nonce_field( 'lunara_journal_set_dispatch_automation' ); ?>
                <input type="hidden" name="action" value="lunara_journal_set_dispatch_automation" />
                <label><input type="checkbox" name="auto_convert" value="1" <?php checked( $auto_convert ); ?> /> Auto-convert qualifying Dispatch-created drafts when they are saved</label>
                <select name="convert_mode">
                    <option value="standard" <?php selected( $convert_mode, 'standard' ); ?>>Standard</option>
                    <option value="aggressive" <?php selected( $convert_mode, 'aggressive' ); ?>>Aggressive</option>
                    <option value="off" <?php selected( $convert_mode, 'off' ); ?>>Off</option>
                </select>
                <?php submit_button( 'Save Automation Settings', 'secondary', 'submit', false ); ?>
            </form>
            <h3>Legacy draft migration</h3>
            <p>Previewing is read-only. Conversion remains off until a preview is generated and the exact confirmation phrase is entered.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;">
                <?php wp_nonce_field( 'lunara_journal_dispatch_preview' ); ?>
                <input type="hidden" name="action" value="lunara_journal_dispatch_preview" />
                <input type="hidden" name="limit" value="100" />
                <label for="lunara-preview-mode">Preview mode</label>
                <select id="lunara-preview-mode" name="preview_mode">
                    <option value="standard">Standard</option>
                    <option value="aggressive">Aggressive</option>
                </select>
                <?php submit_button( 'Preview Legacy Draft Candidates', 'secondary', 'submit', false ); ?>
            </form>
            <?php if ( is_array( $conversion_preview ) ) : ?>
                <p><strong><?php echo esc_html( (string) count( $conversion_preview['candidate_ids'] ) ); ?> candidate(s)</strong> were found in the read-only <?php echo esc_html( $conversion_preview['mode'] ); ?> preview generated <?php echo esc_html( $conversion_preview['generated_at'] ); ?>.</p>
                <?php if ( ! empty( $conversion_preview['candidates'] ) ) : ?>
                    <table class="widefat striped" style="max-width:900px"><thead><tr><th>ID</th><th>Title</th><th>Status</th></tr></thead><tbody>
                    <?php foreach ( $conversion_preview['candidates'] as $candidate ) : ?>
                        <tr><td><?php echo esc_html( (string) $candidate['id'] ); ?></td><td><a href="<?php echo esc_url( $candidate['edit_link'] ); ?>"><?php echo esc_html( $candidate['title'] ); ?></a></td><td><?php echo esc_html( $candidate['status'] ); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:900px;margin:16px 0;">
                        <?php wp_nonce_field( 'lunara_journal_dispatch_scan' ); ?>
                        <input type="hidden" name="action" value="lunara_journal_dispatch_scan" />
                        <p><label>Type <code><?php echo esc_html( self::MIGRATION_CONFIRM_PHRASE ); ?></code> to convert only these previewed IDs.<br /><input type="text" class="regular-text" name="confirm_conversion" autocomplete="off" /></label></p>
                        <?php submit_button( 'Convert Previewed Drafts', 'primary', 'submit', false ); ?>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <h2>Endpoints</h2>
            <ul>
                <li><code>GET <?php echo esc_html( rest_url( self::REST_NAMESPACE . '/dispatch/drafts' ) ); ?></code></li>
                <li><code>GET <?php echo esc_html( rest_url( self::REST_NAMESPACE . '/dispatch/drafts/{id}' ) ); ?></code></li>
                <li><code>PATCH <?php echo esc_html( rest_url( self::REST_NAMESPACE . '/dispatch/drafts/{id}' ) ); ?></code></li>
                <li><code>POST <?php echo esc_html( rest_url( self::REST_NAMESPACE . '/dispatch/drafts/{id}/validate' ) ); ?></code></li>
                <li><code>POST <?php echo esc_html( rest_url( self::REST_NAMESPACE . '/dispatch/drafts/{id}/mark-ready' ) ); ?></code></li>
                <li><code>POST <?php echo esc_html( rest_url( self::REST_NAMESPACE . '/dispatch/ingest' ) ); ?></code></li>
                <li><code>POST <?php echo esc_html( rest_url( self::REST_NAMESPACE . '/dispatch/convert' ) ); ?></code></li>
                <li><code>GET <?php echo esc_html( rest_url( self::REST_NAMESPACE . '/dispatch/schema' ) ); ?></code></li>
                <li><code>GET <?php echo esc_html( rest_url( self::REST_NAMESPACE . '/dispatch/health' ) ); ?></code></li>
                <li><code>GET <?php echo esc_html( rest_url( self::REST_NAMESPACE . '/dispatch/whoami' ) ); ?></code></li>
                <li><code>GET <?php echo esc_html( rest_url( self::REST_NAMESPACE . '/dispatch/drafts/{id}/audit' ) ); ?></code></li>
            </ul>
        </div>
        <?php
    }

    public static function admin_toggle_bridge() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lunara-journal-foundation' ) );
        }
        check_admin_referer( 'lunara_journal_toggle_bridge' );
        $enabled = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) ? '1' : '0';
        update_option( self::OPTION_ENABLED, $enabled, false );
        wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=lunara-journal-bridge&updated=1' ) );
        exit;
    }


    private static function migration_preview_transient_key() {
        return 'lunara_journal_conversion_preview_' . get_current_user_id();
    }

    public static function admin_dispatch_preview() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lunara-journal-foundation' ) );
        }
        check_admin_referer( 'lunara_journal_dispatch_preview' );
        $limit = isset( $_POST['limit'] ) ? min( 100, max( 1, absint( $_POST['limit'] ) ) ) : 100;
        $mode = isset( $_POST['preview_mode'] ) ? sanitize_key( wp_unslash( $_POST['preview_mode'] ) ) : 'standard';
        $preview = self::preview_dispatch_candidates( $limit, $mode );
        $preview['candidate_ids'] = wp_list_pluck( $preview['candidates'], 'id' );
        set_transient( self::migration_preview_transient_key(), $preview, 15 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=lunara-journal-bridge&preview=1' ) );
        exit;
    }

    public static function admin_dispatch_scan() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lunara-journal-foundation' ) );
        }
        check_admin_referer( 'lunara_journal_dispatch_scan' );
        $confirmation = isset( $_POST['confirm_conversion'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm_conversion'] ) ) : '';
        $preview = get_transient( self::migration_preview_transient_key() );
        if ( self::MIGRATION_CONFIRM_PHRASE !== $confirmation || ! is_array( $preview ) || empty( $preview['candidate_ids'] ) ) {
            wp_die( esc_html__( 'A current migration preview and the exact confirmation phrase are required.', 'lunara-journal-foundation' ) );
        }
        $result = self::convert_dispatch_candidate_ids( $preview['candidate_ids'], $preview['mode'], 'admin_confirmed_preview' );
        delete_transient( self::migration_preview_transient_key() );
        set_transient( 'lunara_journal_last_scan_result', $result, 10 * MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=lunara-journal-bridge&scan=1&converted=' . absint( $result['converted_count'] ) ) );
        exit;
    }

    public static function admin_generate_access_key() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lunara-journal-foundation' ) );
        }
        check_admin_referer( 'lunara_journal_generate_access_key' );
        $profile_id = isset( $_POST['profile_id'] ) ? sanitize_key( wp_unslash( $_POST['profile_id'] ) ) : '';
        $profiles = self::get_access_profiles();
        $label = isset( $profiles[ $profile_id ]['label'] ) ? $profiles[ $profile_id ]['label'] : $profile_id;
        $token = self::generate_access_token_for_profile( $profile_id );
        if ( is_wp_error( $token ) ) {
            wp_die( esc_html( $token->get_error_message() ) );
        }
        set_transient(
            'lunara_journal_generated_key_' . get_current_user_id(),
            array( 'profile_id' => $profile_id, 'label' => $label, 'token' => $token ),
            10 * MINUTE_IN_SECONDS
        );
        wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=lunara-journal-bridge&key-generated=1' ) );
        exit;
    }

    public static function admin_revoke_access_key() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lunara-journal-foundation' ) );
        }
        check_admin_referer( 'lunara_journal_revoke_access_key' );
        $profile_id = isset( $_POST['profile_id'] ) ? sanitize_key( wp_unslash( $_POST['profile_id'] ) ) : '';
        $result = self::revoke_access_token_for_profile( $profile_id );
        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ) );
        }
        wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=lunara-journal-bridge&key-revoked=1' ) );
        exit;
    }

    public static function admin_set_dispatch_automation() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'lunara-journal-foundation' ) );
        }
        check_admin_referer( 'lunara_journal_set_dispatch_automation' );
        $enabled = isset( $_POST['auto_convert'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['auto_convert'] ) ) ? '1' : '0';
        $mode    = isset( $_POST['convert_mode'] ) ? sanitize_key( wp_unslash( $_POST['convert_mode'] ) ) : 'standard';
        if ( ! in_array( $mode, array( 'off', 'standard', 'aggressive' ), true ) ) {
            $mode = 'standard';
        }
        if ( 'off' === $mode ) {
            $enabled = '0';
        }
        update_option( self::OPTION_AUTO_CONVERT, $enabled, false );
        update_option( self::OPTION_CONVERT_MODE, $mode, false );
        self::sync_conversion_cron( '1' === $enabled, $mode );
        wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=lunara-journal-bridge&updated=1' ) );
        exit;
    }

    public static function admin_columns( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'title' === $key ) {
                $new['journal_status'] = 'Journal Status';
                $new['journal_ready']  = 'Ready';
            }
        }
        return $new;
    }

    public static function admin_column_content( $column, $post_id ) {
        if ( 'journal_status' === $column ) {
            echo esc_html( (string) self::get_acf_value( 'journal_status', $post_id ) );
        }

        if ( 'journal_ready' === $column ) {
            echo self::truthy( self::get_acf_value( 'journal_ready_for_review', $post_id ) ) ? 'Yes' : 'No';
        }
    }
}

Lunara_Journal_Foundation::bootstrap();
Lunara_Journal_Ingest::bootstrap();
Lunara_Journal_Control_Plane::bootstrap();
Lunara_Journal_Voice_Upgrade::bootstrap();
Lunara_Journal_Site_Studio::bootstrap();
Lunara_Journal_Fast_Desk::bootstrap();
Lunara_Journal_Desk_API::bootstrap();
Lunara_Journal_Story_Wire::bootstrap();
Lunara_Journal_Publishing::bootstrap();
Lunara_Journal_Desk_Rewriter::bootstrap();
Lunara_Journal_Desk_App::bootstrap();
Lunara_Journal_Automation::bootstrap();
register_activation_hook( __FILE__, array( 'Lunara_Journal_Foundation', 'activate' ) );
register_activation_hook( __FILE__, array( 'Lunara_Journal_Control_Plane', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Lunara_Journal_Foundation', 'deactivate' ) );

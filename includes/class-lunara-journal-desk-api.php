<?php
/** Private, same-origin Journal Desk operations for WordPress cookie sessions. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Journal_Desk_API {
    const MAX_BODY_BYTES = 300000;
    const LOCK_SECONDS = 60;

    public static function bootstrap() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    public static function register_rest_routes() {
        register_rest_route( 'lunara/v1', '/journal/app/settings', array(
            array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'rest_settings' ), 'permission_callback' => array( __CLASS__, 'settings_permissions_check' ) ),
            array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'rest_save_settings' ), 'permission_callback' => array( __CLASS__, 'settings_permissions_check' ) ),
        ) );
        register_rest_route( 'lunara/v1', '/journal/app/media', array(
            'methods' => 'GET, POST', 'callback' => array( __CLASS__, 'rest_media' ),
            'permission_callback' => array( __CLASS__, 'media_permissions_check' ),
        ) );
        $id_args = array( 'id' => array( 'validate_callback' => array( 'Lunara_Journal_Foundation', 'rest_validate_positive_id' ), 'sanitize_callback' => 'absint' ) );
        register_rest_route( 'lunara/v1', '/journal/app/drafts/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'rest_workspace' ),
            'permission_callback' => array( __CLASS__, 'draft_permissions_check' ), 'args' => $id_args,
        ) );
        foreach ( array( 'save', 'reject', 'publish' ) as $action ) {
            register_rest_route( 'lunara/v1', '/journal/app/drafts/(?P<id>\d+)/' . $action, array(
                'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'rest_' . $action ),
                'permission_callback' => array( __CLASS__, 'publish' === $action ? 'publish_permissions_check' : 'draft_permissions_check' ),
                'args' => $id_args,
            ) );
        }
    }

    /** Bridge and application-password credentials cannot grant this app authority. */
    public static function session_permissions_check( WP_REST_Request $request, $capability = 'manage_options' ) {
        if ( ! is_user_logged_in() || ! function_exists( 'wp_get_session_token' ) || '' === (string) wp_get_session_token() || ! function_exists( 'wp_validate_auth_cookie' ) || (int) wp_validate_auth_cookie( '', 'logged_in' ) !== get_current_user_id() ) {
            return self::error( 'lunara_desk_session_required', 'Sign in to WordPress to open Journal Desk.', 403 );
        }
        foreach ( array( 'authorization', 'x-lunara-bridge-token', 'x_lunara_bridge_token' ) as $header ) {
            if ( '' !== trim( (string) $request->get_header( $header ) ) ) {
                return self::error( 'lunara_desk_session_only', 'Journal Desk accepts your WordPress session only.', 403 );
            }
        }
        $nonce = $request->get_header( 'x-wp-nonce' );
        if ( ! is_string( $nonce ) || '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return self::error( 'lunara_desk_nonce_required', 'Your session needs refreshing. Reload Journal Desk and try again.', 403 );
        }
        if ( $capability && ! current_user_can( $capability ) ) {
            return self::error( 'lunara_desk_forbidden', 'You do not have permission for this Journal operation.', 403 );
        }
        return true;
    }

    public static function settings_permissions_check( WP_REST_Request $request ) {
        return self::session_permissions_check( $request, 'manage_options' );
    }

    public static function draft_permissions_check( WP_REST_Request $request ) {
        $permission = self::session_permissions_check( $request, 'manage_options' );
        if ( is_wp_error( $permission ) ) {
            return $permission;
        }
        $id = absint( $request->get_param( 'id' ) );
        if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
            return self::error( 'lunara_desk_forbidden', 'You cannot edit this Journal draft.', 403 );
        }
        return true;
    }

    public static function publish_permissions_check( WP_REST_Request $request ) {
        $permission = self::draft_permissions_check( $request );
        if ( is_wp_error( $permission ) ) {
            return $permission;
        }
        return current_user_can( 'publish_posts' ) ? true : self::error( 'lunara_desk_publish_forbidden', 'You cannot publish Journal entries.', 403 );
    }

    public static function media_permissions_check( WP_REST_Request $request ) {
        $permission = self::session_permissions_check( $request );
        if ( is_wp_error( $permission ) ) { return $permission; }
        return current_user_can( 'upload_files' ) ? true : self::error( 'lunara_desk_media_forbidden', 'You cannot manage images.', 403 );
    }

    /** Keep core upload validation and permissions; expose only image-picker fields. */
    public static function rest_media( WP_REST_Request $request ) {
        $permission = self::media_permissions_check( $request );
        if ( is_wp_error( $permission ) ) { return $permission; }
        $upload = 'POST' === $request->get_method();
        $proxy = new WP_REST_Request( $upload ? 'POST' : 'GET', '/wp/v2/media' );
        if ( $upload ) {
            $files = $request->get_file_params();
            $file = isset( $files['file'] ) ? $files['file'] : null;
            if ( ! is_array( $file ) || ! isset( $file['error'], $file['size'], $file['tmp_name'] ) || UPLOAD_ERR_OK !== $file['error'] || ! is_string( $file['tmp_name'] ) ) {
                return self::error( 'lunara_desk_upload_failed', 'Choose an image file. The upload may exceed your server limit.', 400 );
            }
            if ( $file['size'] > min( 20 * 1024 * 1024, wp_max_upload_size() ) ) {
                return self::error( 'lunara_desk_upload_large', 'Choose an image smaller than the upload limit shown in the desk.', 413 );
            }
            $mime = wp_get_image_mime( $file['tmp_name'] );
            if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ), true ) ) {
                return self::error( 'lunara_desk_upload_type', 'Choose a JPEG, PNG, WebP, GIF, or AVIF image.', 400 );
            }
            $proxy->set_file_params( array( 'file' => $file ) );
        } else {
            $page = $request->get_param( 'page' );
            $search = $request->get_param( 'search' );
            if ( ( null !== $page && ( ! is_scalar( $page ) || ! ctype_digit( (string) $page ) || (int) $page < 1 ) ) || ( null !== $search && ( ! is_string( $search ) || strlen( $search ) > 200 ) ) ) {
                return self::error( 'lunara_desk_media_query', 'Use a valid image search and page.', 400 );
            }
            $proxy->set_param( 'context', 'edit' );
            $proxy->set_param( 'media_type', 'image' );
            $proxy->set_param( 'per_page', 24 );
            $proxy->set_param( 'page', $page ? (int) $page : 1 );
            $proxy->set_param( 'search', sanitize_text_field( $search ?: '' ) );
        }
        $result = rest_do_request( $proxy );
        if ( $result->is_error() ) { return $result->as_error(); }
        $data = $result->get_data();
        $items = $upload ? array( $data ) : $data;
        $images = array();
        foreach ( $items as $item ) {
            if ( ! in_array( $item['mime_type'] ?? '', array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ), true ) || ! current_user_can( 'edit_post', $item['id'] ) ) { continue; }
            $images[] = array( 'id' => (int) $item['id'], 'url' => esc_url_raw( $item['source_url'] ), 'thumbnail' => esc_url_raw( $item['media_details']['sizes']['medium']['source_url'] ?? $item['source_url'] ), 'title' => sanitize_text_field( $item['title']['raw'] ?? wp_strip_all_tags( $item['title']['rendered'] ?? '' ) ), 'alt' => (string) ( $item['alt_text'] ?? '' ), 'width' => (int) ( $item['media_details']['width'] ?? 0 ), 'height' => (int) ( $item['media_details']['height'] ?? 0 ) );
        }
        $headers = $result->get_headers();
        return self::response( array( 'images' => $images, 'total_pages' => (int) ( $headers['X-WP-TotalPages'] ?? 1 ) ) );
    }

    public static function rest_settings( WP_REST_Request $request ) {
        $permission = self::settings_permissions_check( $request );
        if ( is_wp_error( $permission ) ) {
            return $permission;
        }
        $version = self::active_version();
        return is_wp_error( $version ) ? $version : self::response( self::settings_payload( $version ) );
    }

    public static function rest_save_settings( WP_REST_Request $request ) {
        $permission = self::settings_permissions_check( $request );
        if ( is_wp_error( $permission ) ) {
            return $permission;
        }
        $body = self::request_body( $request, array( 'expected_version_id', 'voice', 'sources', 'removed_source_ids', 'selection' ) );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        if ( ! isset( $body['expected_version_id'] ) || ! is_int( $body['expected_version_id'] ) ) {
            return self::error( 'lunara_desk_version_required', 'Send the settings version you reviewed.', 400 );
        }
        return self::with_lock( 'settings', static function () use ( $body ) {
            $version = self::active_version();
            if ( is_wp_error( $version ) ) {
                return $version;
            }
            if ( (int) $version['id'] !== $body['expected_version_id'] ) {
                return self::error( 'lunara_desk_settings_conflict', 'The workflow settings changed elsewhere. Reload them before saving.', 409 );
            }
            $config = Lunara_Journal_Config_Schema::sanitize_config( $version['config'] );
            $changed = false;
            if ( array_key_exists( 'voice', $body ) ) {
                $voice = self::sanitize_voice( $body['voice'] );
                if ( is_wp_error( $voice ) ) {
                    return $voice;
                }
                foreach ( $voice as $key => $value ) {
                    $config['editorial']['voice'][$key] = $value;
                }
                $changed = true;
            }
            if ( array_key_exists( 'sources', $body ) ) {
                $sources = self::prepare_sources( $body['sources'], $config['sources'], $body['removed_source_ids'] ?? array() );
                if ( is_wp_error( $sources ) ) {
                    return $sources;
                }
                $config['sources'] = $sources;
                $changed = true;
            } elseif ( array_key_exists( 'removed_source_ids', $body ) ) {
                return self::error( 'lunara_desk_invalid_sources', 'Send the complete source list with removals.', 400 );
            }
            if ( array_key_exists( 'selection', $body ) ) {
                $selection = self::sanitize_selection( $body['selection'], $config['editorial']['selection'] );
                if ( is_wp_error( $selection ) ) {
                    return $selection;
                }
                $config['editorial']['selection'] = $selection;
                $changed = true;
            }
            if ( ! $changed ) {
                return self::error( 'lunara_desk_empty_update', 'Choose a voice, source, or story-selection change to save.', 400 );
            }
            // Reuse the sole validated, immutable activation path. Never write raw config options.
            $stored = Lunara_Journal_Config_Repository::create_and_activate( $config, 'Journal Desk voice, sources, or story selection updated.', 'wp_user_' . get_current_user_id() );
            if ( is_wp_error( $stored ) ) {
                return $stored;
            }
            Lunara_Journal_Fast_Desk::invalidate_cache();
            $payload = self::settings_payload( $stored );
            $payload['saved'] = true;
            return self::response( $payload );
        } );
    }

    public static function rest_workspace( WP_REST_Request $request ) {
        $permission = self::draft_permissions_check( $request );
        if ( is_wp_error( $permission ) ) {
            return $permission;
        }
        $post = self::editable_post( absint( $request['id'] ), false );
        if ( is_wp_error( $post ) ) {
            return $post;
        }
        $revision = self::revision_for_post( $post->ID );
        $proxy = self::foundation_request( 'GET', '/journal/desk/drafts/' . $post->ID, $post->ID );
        $permission = Lunara_Journal_Foundation::rest_permissions_check( $proxy );
        if ( is_wp_error( $permission ) || true !== $permission ) {
            return $permission;
        }
        $result = Lunara_Journal_Fast_Desk::rest_open_workspace( $proxy );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( ! hash_equals( $revision, self::revision_for_post( $post->ID ) ) ) {
            return self::error( 'lunara_desk_revision_conflict', 'This draft changed while it was opening. Open it again to load the latest version.', 409 );
        }
        $payload = rest_ensure_response( $result )->get_data();
        $payload['workspace']['title'] = (string) $post->post_title;
        $payload['revision'] = $revision;
        return self::response( $payload );
    }

    public static function rest_save( WP_REST_Request $request ) {
        $permission = self::draft_permissions_check( $request );
        if ( is_wp_error( $permission ) ) {
            return $permission;
        }
        $body = self::request_body( $request, array( 'expected_revision', 'title', 'content', 'excerpt', 'acf', 'featured_media' ) );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        foreach ( array( 'title', 'content', 'excerpt' ) as $field ) {
            if ( array_key_exists( $field, $body ) && ! is_string( $body[$field] ) ) {
                return self::error( 'lunara_desk_invalid_draft', 'Draft text fields must contain text.', 400 );
            }
        }
        if ( isset( $body['acf'] ) && ! is_array( $body['acf'] ) ) {
            return self::error( 'lunara_desk_invalid_draft', 'Draft fields must be an object.', 400 );
        }
        // The app edits prose and descriptive metadata only; provenance and workflow remain server-owned.
        $acf_allowed = array( 'journal_kicker', 'journal_deck', 'journal_primary_section', 'journal_item_type', 'journal_priority', 'journal_source_items', 'journal_primary_title', 'journal_primary_year', 'journal_people', 'journal_studios_platforms', 'journal_trailer_url', 'journal_editorial_angle', 'journal_chatgpt_brief', 'journal_chatgpt_revision_notes', 'journal_seo_title', 'journal_seo_description', 'journal_social_x', 'journal_social_typefully_notes', 'journal_image_source_url', 'journal_image_credit', 'journal_image_alt' );
        if ( isset( $body['acf'] ) && array_diff( array_keys( $body['acf'] ), $acf_allowed ) ) {
            return self::error( 'lunara_desk_invalid_draft', 'Only editable Journal fields may be saved.', 400 );
        }
        return self::with_lock( 'draft-' . absint( $request['id'] ), static function () use ( $request, $body ) {
            $post = self::check_draft_revision( $request );
            if ( is_wp_error( $post ) ) {
                return $post;
            }
            unset( $body['expected_revision'] );
            if ( ! $body ) {
                return self::error( 'lunara_desk_empty_update', 'There are no draft changes to save.', 400 );
            }
            $proxy = self::foundation_request( 'POST', '/journal/desk/drafts/' . $post->ID . '/save-validate', $post->ID, $body );
            $permission = Lunara_Journal_Foundation::rest_permissions_check( $proxy );
            if ( is_wp_error( $permission ) || true !== $permission ) {
                return $permission;
            }
            $image_id = get_post_thumbnail_id( $post->ID );
            if ( array_key_exists( 'featured_media', $body ) ) {
                $image_id = $body['featured_media'];
                if ( ! is_int( $image_id ) || $image_id < 1 || ! wp_attachment_is_image( $image_id ) || ! in_array( get_post_mime_type( $image_id ), array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ), true ) ) {
                    return self::error( 'lunara_desk_invalid_image', 'Choose an existing image from your media library.', 400 );
                }
            }
            $has_alt = isset( $body['acf'] ) && array_key_exists( 'journal_image_alt', $body['acf'] );
            if ( $has_alt && ( ! is_string( $body['acf']['journal_image_alt'] ) || strlen( $body['acf']['journal_image_alt'] ) > 2000 ) ) {
                return self::error( 'lunara_desk_invalid_alt', 'Use plain text for the image description (up to 2,000 bytes).', 400 );
            }
            $image_write = array_key_exists( 'featured_media', $body ) || ( $image_id && $has_alt );
            if ( $image_write && ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $image_id ) ) ) {
                return self::error( 'lunara_desk_media_forbidden', 'You cannot edit the selected image.', 403 );
            }
            if ( $image_write ) {
                if ( (int) get_post_thumbnail_id( $post->ID ) !== $image_id ) { set_post_thumbnail( $post->ID, $image_id ); }
                if ( (int) get_post_thumbnail_id( $post->ID ) !== (int) $image_id ) {
                    return new WP_Error( 'lunara_desk_image_failed', 'The featured image could not be verified. Reload the draft before retrying.', array( 'status' => 500, 'partial_save' => true ) );
                }
                if ( $has_alt ) {
                    $alt = sanitize_text_field( $body['acf']['journal_image_alt'] );
                    update_post_meta( $image_id, '_wp_attachment_image_alt', wp_slash( $alt ) );
                    if ( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) !== $alt ) {
                        return new WP_Error( 'lunara_desk_partial_save', 'The image changed but its description could not be verified. Reload the draft before retrying.', array( 'status' => 500, 'partial_save' => true ) );
                    }
                }
                Lunara_Journal_Image_Guard::clear_cache( $post->ID );
            }
            $result = Lunara_Journal_Fast_Desk::rest_save_validate( $proxy );
            if ( $image_write && is_wp_error( $result ) ) {
                return new WP_Error( 'lunara_desk_partial_save', 'The image was saved, but the article save could not be confirmed. Reload the saved draft before retrying.', array( 'status' => 500, 'partial_save' => true ) );
            }
            return self::with_revision( $result, $post->ID );
        } );
    }

    public static function rest_reject( WP_REST_Request $request ) {
        $permission = self::draft_permissions_check( $request );
        if ( is_wp_error( $permission ) ) {
            return $permission;
        }
        $body = self::request_body( $request, array( 'expected_revision' ) );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        return self::with_lock( 'draft-' . absint( $request['id'] ), static function () use ( $request ) {
            $post = self::check_draft_revision( $request );
            if ( is_wp_error( $post ) ) {
                return $post;
            }
            $proxy = self::foundation_request( 'POST', '/journal/desk/drafts/' . $post->ID . '/save-validate', $post->ID );
            $permission = Lunara_Journal_Foundation::rest_permissions_check( $proxy );
            if ( is_wp_error( $permission ) || true !== $permission ) {
                return $permission;
            }
            if ( function_exists( 'update_field' ) ) {
                update_field( 'journal_status', 'rejected', $post->ID );
                update_field( 'journal_ready_for_review', false, $post->ID );
            } else {
                update_post_meta( $post->ID, 'journal_status', 'rejected' );
                update_post_meta( $post->ID, 'journal_ready_for_review', false );
            }
            clean_post_cache( $post->ID );
            if ( 'rejected' !== (string) get_post_meta( $post->ID, 'journal_status', true ) ) {
                return self::error( 'lunara_desk_reject_failed', 'WordPress could not save the rejected state.', 409 );
            }
            Lunara_Journal_Foundation::record_bridge_log_entry( $post->ID, 'reject', array( 'post_status' => $post->post_status, 'actor' => 'wp_user_' . get_current_user_id() ) );
            Lunara_Journal_Foundation::update_bridge_attribution( $post->ID, 'reject' );
            Lunara_Journal_Fast_Desk::invalidate_cache();
            return self::with_revision( array( 'rejected' => true, 'id' => $post->ID, 'journal_status' => 'rejected', 'post_status' => get_post_status( $post->ID ) ), $post->ID );
        } );
    }

    public static function rest_publish( WP_REST_Request $request ) {
        $permission = self::publish_permissions_check( $request );
        if ( is_wp_error( $permission ) ) {
            return $permission;
        }
        $body = self::request_body( $request, array( 'expected_revision', 'confirm_publish_now' ) );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        if ( ! isset( $body['confirm_publish_now'] ) || true !== $body['confirm_publish_now'] ) {
            return self::error( 'lunara_publish_confirmation_required', 'Review the entry and choose Approve & Publish to publish it now.', 400 );
        }
        return self::with_lock( 'draft-' . absint( $request['id'] ), static function () use ( $request ) {
            $post = self::check_draft_revision( $request );
            if ( is_wp_error( $post ) ) {
                return $post;
            }
            $proxy = self::foundation_request( 'POST', '/journal/desk/drafts/' . $post->ID . '/publish', $post->ID, array( 'confirm_publish_now' => true ) );
            $permission = Lunara_Journal_Foundation::rest_permissions_check( $proxy );
            if ( is_wp_error( $permission ) || true !== $permission ) {
                return $permission;
            }
            // The Foundation action retains capability, configuration, image, validation, and readback gates.
            return self::with_revision( Lunara_Journal_Fast_Desk::rest_publish_draft( $proxy ), $post->ID );
        } );
    }

    public static function check_draft_revision( WP_REST_Request $request ) {
        $post = self::editable_post( absint( $request['id'] ), true );
        if ( is_wp_error( $post ) ) {
            return $post;
        }
        $body = $request->get_json_params();
        $expected = is_array( $body ) && isset( $body['expected_revision'] ) ? $body['expected_revision'] : null;
        if ( ! is_string( $expected ) || ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) ) {
            return self::error( 'lunara_desk_revision_required', 'Open this draft before saving changes.', 400 );
        }
        if ( ! hash_equals( self::revision_for_post( $post->ID ), $expected ) ) {
            return self::error( 'lunara_desk_revision_conflict', 'This draft changed elsewhere. Reload it before saving or publishing.', 409 );
        }
        return $post;
    }

    /** Content and editorial metadata, excluding read-time validation/audit bookkeeping. */
    public static function revision_for_post( $post_id ) {
        clean_post_cache( $post_id );
        $post = get_post( $post_id );
        if ( ! $post ) {
            return '';
        }
        $all_meta = get_post_meta( $post_id );
        $meta = array();
        foreach ( is_array( $all_meta ) ? $all_meta : array() as $key => $value ) {
            if ( ( 0 === strpos( $key, 'journal_' ) && ! preg_match( '/^journal_(validation_|last_|bridge_update_count|bridge_audit_summary)/', $key ) ) || '_thumbnail_id' === $key ) {
                $meta[$key] = $value;
            }
        }
        ksort( $meta );
        return hash( 'sha256', wp_json_encode( array( 'id' => $post->ID, 'type' => $post->post_type, 'status' => $post->post_status, 'title' => $post->post_title, 'content' => $post->post_content, 'excerpt' => $post->post_excerpt, 'modified' => $post->post_modified_gmt, 'featured_media' => get_post_thumbnail_id( $post->ID ), 'image_alt' => get_post_meta( get_post_thumbnail_id( $post->ID ), '_wp_attachment_image_alt', true ), 'meta' => $meta ) ) );
    }

    private static function editable_post( $id, $writing ) {
        clean_post_cache( $id );
        $post = get_post( $id );
        if ( ! $post || 'journal' !== $post->post_type ) {
            return self::error( 'lunara_desk_not_found', 'Journal draft not found.', 404 );
        }
        if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'private', 'auto-draft' ), true ) ) {
            return self::error( 'lunara_desk_not_editable', 'Journal Desk only edits unpublished drafts.', 403 );
        }
        if ( $writing && in_array( get_post_meta( $id, 'journal_bridge_locked', true ), array( true, 1, '1', 'true', 'yes', 'on' ), true ) ) {
            return self::error( 'lunara_bridge_locked', 'This Journal draft is locked against changes.', 423 );
        }
        return $post;
    }

    private static function active_version() {
        $version = Lunara_Journal_Config_Repository::get_version( Lunara_Journal_Config_Repository::get_active_version_id() );
        return is_array( $version ) && ! empty( $version['config'] ) && is_array( $version['config'] ) ? $version : self::error( 'lunara_desk_config_unavailable', 'Activate a Journal workflow in WordPress before using these controls.', 503 );
    }

    private static function settings_payload( array $version ) {
        $config = Lunara_Journal_Config_Schema::sanitize_config( $version['config'] );
        $voice = $config['editorial']['voice'];
        $selection = $config['editorial']['selection'];
        return array(
            'version_id' => (int) $version['id'], 'config_version' => (string) $config['config_version'],
            'voice' => array( 'summary' => (string) $voice['summary'], 'current_refinement' => (string) ( $voice['current_refinement'] ?? '' ), 'banned_phrases' => array_values( $voice['banned_phrases'] ) ),
            'sources' => array_values( $config['sources'] ),
            'selection' => array( 'prefer_entries' => (int) $selection['prefer_entries'], 'max_entries' => (int) $selection['max_entries'], 'minimum_words' => (int) $selection['minimum_words'], 'minimum_paragraphs' => (int) $selection['minimum_paragraphs'], 'skip_rules' => array_values( $selection['skip_rules'] ) ),
            'publication' => array( 'enabled' => ! empty( $config['chatgpt']['may_publish'] ), 'can_publish' => current_user_can( 'publish_posts' ) ),
            'settings_admin_url' => admin_url( 'edit.php?post_type=journal&page=lunara-journal-control-plane' ),
        );
    }

    private static function sanitize_voice( $input ) {
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'summary', 'current_refinement', 'banned_phrases' ) ) ) {
            return self::error( 'lunara_desk_invalid_voice', 'Only voice summary, current refinement, and banned phrases may change.', 400 );
        }
        $out = array();
        foreach ( array( 'summary' => 4000, 'current_refinement' => 8000 ) as $key => $limit ) {
            if ( array_key_exists( $key, $input ) ) {
                if ( ! is_string( $input[$key] ) || strlen( $input[$key] ) > $limit ) {
                    return self::error( 'lunara_desk_invalid_voice', 'The voice note is invalid or too long.', 400 );
                }
                $out[$key] = sanitize_textarea_field( $input[$key] );
            }
        }
        if ( array_key_exists( 'banned_phrases', $input ) ) {
            $phrases = self::text_list( $input['banned_phrases'], 100, 160 );
            if ( is_wp_error( $phrases ) ) {
                return $phrases;
            }
            $out['banned_phrases'] = $phrases;
        }
        return $out;
    }

    private static function sanitize_selection( $input, array $current ) {
        $limits = array( 'prefer_entries' => array( 1, 3 ), 'max_entries' => array( 1, 3 ), 'minimum_words' => array( 50, 500 ), 'minimum_paragraphs' => array( 1, 8 ) );
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), array_merge( array_keys( $limits ), array( 'skip_rules' ) ) ) ) {
            return self::error( 'lunara_desk_invalid_selection', 'Only the displayed story-selection controls may change.', 400 );
        }
        foreach ( $limits as $key => $range ) {
            if ( array_key_exists( $key, $input ) ) {
                if ( ! is_int( $input[$key] ) || $input[$key] < $range[0] || $input[$key] > $range[1] ) {
                    return self::error( 'lunara_desk_invalid_selection', 'A story-selection number is outside its supported range.', 400 );
                }
                $current[$key] = $input[$key];
            }
        }
        if ( $current['prefer_entries'] > $current['max_entries'] ) {
            return self::error( 'lunara_desk_invalid_selection', 'Preferred entries cannot exceed the maximum entries.', 400 );
        }
        if ( array_key_exists( 'skip_rules', $input ) ) {
            $rules = self::text_list( $input['skip_rules'], 20, 1000 );
            if ( is_wp_error( $rules ) ) {
                return $rules;
            }
            $current['skip_rules'] = $rules;
        }
        return $current;
    }

    private static function prepare_sources( $input, array $current, $removed ) {
        if ( ! self::is_list( $input ) || count( $input ) > 100 || ! self::is_list( $removed ) || count( $removed ) > 100 ) {
            return self::error( 'lunara_desk_invalid_sources', 'Sources and removed IDs must be bounded lists.', 400 );
        }
        $current_ids = array_column( $current, 'id' );
        $seen_existing = array();
        $candidates = array();
        foreach ( $input as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_string( $row['id'] ) || ! isset( $row['label'], $row['url'] ) || ! is_string( $row['label'] ) || ! is_string( $row['url'] ) || strlen( $row['label'] ) > 200 || strlen( $row['url'] ) > 2048 ) {
                return self::error( 'lunara_desk_invalid_sources', 'Each source needs its ID, name, and URL.', 400 );
            }
            if ( '' !== $row['id'] ) {
                if ( ! in_array( $row['id'], $current_ids, true ) ) {
                    return self::error( 'lunara_desk_invalid_sources', 'An existing source ID cannot be changed. Use an empty ID for a new source.', 400 );
                }
                $seen_existing[] = $row['id'];
            } else {
                $row['id'] = 'source-' . wp_generate_uuid4();
            }
            $candidates[] = $row;
        }
        foreach ( $removed as $id ) {
            if ( ! is_string( $id ) || ! in_array( $id, $current_ids, true ) ) {
                return self::error( 'lunara_desk_invalid_sources', 'A removed source ID is invalid.', 400 );
            }
        }
        $omitted = array_values( array_diff( $current_ids, $seen_existing ) );
        if ( array_diff( $omitted, $removed ) || array_diff( $removed, $omitted ) || count( array_unique( $removed ) ) !== count( $removed ) ) {
            return self::error( 'lunara_desk_source_removal_required', 'Confirm every removed source before saving.', 400 );
        }
        $validation = Lunara_Journal_Config_Schema::validate_sources( $candidates );
        if ( empty( $validation['valid'] ) ) {
            return self::error( 'lunara_desk_invalid_sources', 'Correct the source names, URLs, limits, or priorities before saving.', 400, array( 'errors' => $validation['errors'] ) );
        }
        return Lunara_Journal_Config_Schema::normalize_sources( $candidates );
    }

    private static function text_list( $input, $max_items, $max_length ) {
        if ( ! self::is_list( $input ) || count( $input ) > $max_items ) {
            return self::error( 'lunara_desk_invalid_list', 'Send a bounded list of text values.', 400 );
        }
        $out = array();
        foreach ( $input as $text ) {
            if ( ! is_string( $text ) || strlen( $text ) > $max_length ) {
                return self::error( 'lunara_desk_invalid_list', 'A text value is invalid or too long.', 400 );
            }
            $text = sanitize_text_field( $text );
            if ( '' !== $text ) {
                $out[] = $text;
            }
        }
        return array_values( array_unique( $out ) );
    }

    private static function is_list( $value ) {
        return is_array( $value ) && ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) );
    }

    private static function request_body( WP_REST_Request $request, array $allowed ) {
        $body = $request->get_json_params();
        if ( ! is_array( $body ) || array_diff( array_keys( $body ), $allowed ) || strlen( wp_json_encode( $body ) ) > self::MAX_BODY_BYTES ) {
            return self::error( 'lunara_desk_invalid_request', 'The request contains unsupported fields or exceeds the editor limit.', 400 );
        }
        return $body;
    }

    private static function foundation_request( $method, $route, $id, array $body = array() ) {
        $request = new WP_REST_Request( $method, '/lunara/v1' . $route );
        $request->set_param( 'id', $id );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( $body ) );
        return $request;
    }

    private static function with_revision( $result, $id ) {
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $data = rest_ensure_response( $result )->get_data();
        $data['revision'] = self::revision_for_post( $id );
        return self::response( $data );
    }

    /** Share the Desk's post lock with delivery approvals and background publication. */
    public static function with_post_lock( $post_id, $callback ) {
        return self::with_lock( 'draft-' . absint( $post_id ), $callback );
    }

    /** Serialize delivery workers without allowing a second worker to release their lock. */
    public static function with_delivery_lock( $callback ) {
        return self::with_lock( 'delivery', $callback );
    }

    /** Serialize writes from app tabs; other WordPress editors are detected by content revision. */
    private static function with_lock( $resource, $callback ) {
        $key = 'lunara_journal_desk_lock_' . sanitize_key( $resource );
        $owner = wp_json_encode( array( 'owner' => wp_generate_uuid4(), 'expires' => time() + self::LOCK_SECONDS ) );
        if ( ! add_option( $key, $owner, '', false ) ) {
            $existing = get_option( $key, '' );
            $decoded = is_string( $existing ) ? json_decode( $existing, true ) : null;
            if ( ! is_array( $decoded ) || empty( $decoded['expires'] ) || (int) $decoded['expires'] >= time() || ! self::delete_owned_lock( $key, $existing ) || ! add_option( $key, $owner, '', false ) ) {
                return self::error( 'lunara_desk_busy', 'Another Journal Desk save is finishing. Try again in a moment.', 409 );
            }
        }
        try {
            return call_user_func( $callback );
        } finally {
            self::delete_owned_lock( $key, $owner );
        }
    }

    private static function delete_owned_lock( $key, $owner ) {
        global $wpdb;
        if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->options ) ) {
            $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $owner ) );
            if ( $deleted ) {
                wp_cache_delete( $key, 'options' );
                wp_cache_delete( 'notoptions', 'options' );
            }
            return (bool) $deleted;
        }
        // Standalone behavior harness; WordPress always provides $wpdb in production.
        return get_option( $key, '' ) === $owner && delete_option( $key );
    }

    private static function response( array $data ) {
        $response = rest_ensure_response( $data );
        $response->header( 'Cache-Control', 'private, no-store, max-age=0' );
        return $response;
    }

    private static function error( $code, $message, $status, array $extra = array() ) {
        return new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) );
    }
}

<?php
/**
 * Draft-only Journal ingestion shared by REST and same-process Dispatch runs.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-lunara-journal-ingest-html.php';

final class Lunara_Journal_Ingest {
    const FILTER = 'lunara_journal_foundation_ingest';
    const META_IDEMPOTENCY = '_lunara_dispatch_idempotency_key';
    const LOCK_PREFIX = 'lunara_journal_ingest_lock_';
    const LOCK_TTL = 120;

    public static function bootstrap() {
        add_filter( self::FILTER, array( __CLASS__, 'filter_ingest' ), 10, 2 );
    }

    public static function filter_ingest( $result, $payload ) {
        if ( null !== $result ) {
            return $result;
        }
        if ( ! is_array( $payload ) ) {
            return new WP_Error( 'lunara_ingest_invalid_payload', 'Foundation ingest requires an array payload.' );
        }
        return self::ingest( $payload, true );
    }

    /**
     * @return array|WP_Error {post_id, created, post_status, idempotency_key}
     */
    public static function ingest( array $body, $same_process = false ) {
        $payload = self::normalize_payload( $body );
        if ( is_wp_error( $payload ) ) {
            return $payload;
        }
        if ( $same_process && '' === $payload['idempotency_key'] ) {
            return new WP_Error( 'lunara_ingest_idempotency_required', 'Foundation ingest requires a stable idempotency_key.' );
        }

        if ( '' !== $payload['idempotency_key'] ) {
            $lock = self::acquire_lock( $payload['idempotency_key'] );
            if ( is_wp_error( $lock ) ) {
                return $lock;
            }

            try {
                $existing = self::find_by_idempotency_key( $payload['idempotency_key'], $same_process );
                if ( $existing ) {
                    return self::existing_result( $existing, $payload['idempotency_key'], $same_process );
                }

                $post_id = self::create_draft( $payload, $same_process ? 'same_process_filter' : 'rest_ingest' );
                if ( is_wp_error( $post_id ) ) {
                    return $post_id;
                }
                return self::result( $post_id, true, $payload['idempotency_key'] );
            } finally {
                self::release_lock( $lock );
            }
        }

        $post_id = self::create_draft( $payload, $same_process ? 'same_process_filter' : 'rest_ingest' );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }
        return self::result( $post_id, true, $payload['idempotency_key'] );
    }

    private static function existing_result( WP_Post $existing, $idempotency_key, $same_process ) {
        if ( 'journal' !== $existing->post_type || ( $same_process && 'draft' !== $existing->post_status ) ) {
            return new WP_Error(
                'lunara_ingest_idempotency_conflict',
                'The idempotency key already belongs to a Journal entry that is not an editable draft.',
                array( 'post_id' => $existing->ID, 'post_status' => $existing->post_status )
            );
        }
        return self::result( $existing->ID, false, $idempotency_key );
    }

    private static function acquire_lock( $idempotency_key ) {
        $option_name = self::LOCK_PREFIX . hash( 'sha256', (string) $idempotency_key );
        $owner = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : wp_generate_password( 32, false, false );
        $now = time();
        $value = array(
            'owner'      => $owner,
            'created_at' => $now,
            'expires_at' => $now + self::LOCK_TTL,
        );

        if ( add_option( $option_name, $value, '', false ) ) {
            return array( 'option_name' => $option_name, 'owner' => $owner );
        }

        $current = get_option( $option_name, null );
        $clearly_stale = is_array( $current )
            && ! empty( $current['expires_at'] )
            && is_numeric( $current['expires_at'] )
            && (int) $current['expires_at'] <= $now;
        if ( $clearly_stale && self::replace_lock_if_unchanged( $option_name, $current, $value ) ) {
            return array( 'option_name' => $option_name, 'owner' => $owner );
        }

        return new WP_Error(
            'lunara_ingest_lock_busy',
            'Another Foundation ingest owns this idempotency key. Retry shortly.',
            array( 'retryable' => true, 'retry_after' => 5, 'status' => 409 )
        );
    }

    private static function release_lock( array $lock ) {
        $current = get_option( $lock['option_name'], null );
        if ( is_array( $current ) && isset( $current['owner'] ) && hash_equals( (string) $current['owner'], (string) $lock['owner'] ) ) {
            self::delete_lock_if_unchanged( $lock['option_name'], $current );
        }
    }

    private static function replace_lock_if_unchanged( $option_name, array $current, array $replacement ) {
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

    private static function delete_lock_if_unchanged( $option_name, array $current ) {
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

    private static function result( $post_id, $created, $idempotency_key ) {
        clean_post_cache( $post_id );
        $post = get_post( $post_id );
        if ( ! $post || 'journal' !== $post->post_type || 'draft' !== $post->post_status ) {
            return new WP_Error(
                'lunara_ingest_draft_readback_failed',
                'Foundation ingest could not verify the Journal draft.',
                array( 'post_id' => (int) $post_id, 'post_status' => $post ? $post->post_status : '' )
            );
        }
        return array(
            'post_id'         => (int) $post_id,
            'created'         => (bool) $created,
            'post_status'     => 'draft',
            'idempotency_key' => (string) $idempotency_key,
        );
    }

    private static function normalize_payload( array $body ) {
        if ( isset( $body['status'] ) || isset( $body['post_status'] ) ) {
            return new WP_Error( 'lunara_ingest_no_status', 'Dispatch ingest always creates drafts and does not accept post status.' );
        }

        $title = isset( $body['title'] ) ? wp_strip_all_tags( (string) $body['title'] ) : '';
        $content = isset( $body['content'] ) ? self::sanitize_post_html( (string) $body['content'] ) : '';
        if ( is_wp_error( $content ) ) {
            return $content;
        }
        $content = self::normalize_content_paragraphs( $content );
        if ( '' === trim( $title ) && '' === trim( wp_strip_all_tags( $content ) ) ) {
            return new WP_Error( 'lunara_ingest_empty', 'Dispatch ingest requires title or content.' );
        }

        $deck = isset( $body['deck'] ) ? sanitize_textarea_field( (string) $body['deck'] ) : '';
        $excerpt = isset( $body['excerpt'] ) ? sanitize_textarea_field( (string) $body['excerpt'] ) : $deck;
        if ( '' === trim( $excerpt ) ) {
            $excerpt = self::excerpt( $content, 260 );
        }

        $acf = array();
        if ( isset( $body['acf'] ) && is_array( $body['acf'] ) ) {
            foreach ( $body['acf'] as $field_name => $value ) {
                if ( in_array( $field_name, self::allowed_fields(), true ) ) {
                    $acf[ $field_name ] = self::sanitize_field( $field_name, $value );
                }
            }
        }

        $classification = isset( $body['classification'] ) && is_array( $body['classification'] ) ? $body['classification'] : array();
        foreach ( array(
            'item_type'         => 'journal_item_type',
            'primary_title'     => 'journal_primary_title',
            'primary_year'      => 'journal_primary_year',
            'people'            => 'journal_people',
            'studios_platforms' => 'journal_studios_platforms',
        ) as $input_key => $field_name ) {
            if ( ! array_key_exists( $field_name, $acf ) && ( array_key_exists( $input_key, $classification ) || array_key_exists( $input_key, $body ) ) ) {
                $value = array_key_exists( $input_key, $classification ) ? $classification[ $input_key ] : $body[ $input_key ];
                $acf[ $field_name ] = self::sanitize_field( $field_name, $value );
            }
        }

        $source_items = self::normalize_source_items( $body, $title, $content );
        if ( ! isset( $acf['journal_source_items'] ) ) {
            $acf['journal_source_items'] = $source_items;
        }
        if ( empty( $acf['journal_original_dispatch_copy'] ) ) {
            $acf['journal_original_dispatch_copy'] = $content;
        }
        if ( empty( $acf['journal_deck'] ) ) {
            $acf['journal_deck'] = $deck ? $deck : $excerpt;
        }
        if ( empty( $acf['journal_seo_description'] ) ) {
            $seo = isset( $body['seo_description'] ) ? sanitize_textarea_field( (string) $body['seo_description'] ) : '';
            $acf['journal_seo_description'] = $seo ? $seo : self::excerpt( $excerpt ? $excerpt : $content, 155 );
        }

        $section = isset( $body['section'] ) ? sanitize_text_field( (string) $body['section'] ) : '';
        if ( '' === $section && isset( $classification['section'] ) ) {
            $section = sanitize_text_field( (string) $classification['section'] );
        }
        if ( '' === $section && isset( $acf['journal_item_type'] ) ) {
            $section = self::section_from_item_type( $acf['journal_item_type'] );
        }

        $raw_topics = isset( $body['topics'] ) && is_array( $body['topics'] ) ? $body['topics'] : ( isset( $classification['topics'] ) && is_array( $classification['topics'] ) ? $classification['topics'] : array() );
        $topics = array();
        foreach ( $raw_topics as $topic ) {
            $topic = sanitize_text_field( (string) $topic );
            if ( '' !== $topic ) {
                $topics[] = $topic;
            }
        }

        $provenance = array();
        if ( isset( $body['provenance'] ) && is_array( $body['provenance'] ) ) {
            foreach ( array( 'provider', 'model', 'config_version', 'prompt_version', 'generated_at_gmt', 'dispatch_version', 'run_id' ) as $key ) {
                if ( isset( $body['provenance'][ $key ] ) && is_scalar( $body['provenance'][ $key ] ) ) {
                    $provenance[ $key ] = sanitize_text_field( (string) $body['provenance'][ $key ] );
                }
            }
        }

        return array(
            'title'           => $title,
            'content'         => $content,
            'excerpt'         => $excerpt,
            'slug'            => isset( $body['slug'] ) ? sanitize_title( (string) $body['slug'] ) : '',
            'featured_media'  => isset( $body['featured_media'] ) ? absint( $body['featured_media'] ) : 0,
            'idempotency_key' => isset( $body['idempotency_key'] ) ? sanitize_text_field( (string) $body['idempotency_key'] ) : '',
            'section'         => $section,
            'topics'          => array_values( array_unique( $topics ) ),
            'acf'             => $acf,
            'provenance'      => $provenance,
        );
    }

    private static function create_draft( array $payload, $source ) {
        $insert = array(
            'post_type'    => 'journal',
            'post_status'  => 'draft',
            'post_title'   => $payload['title'],
            'post_content' => $payload['content'],
            'post_excerpt' => $payload['excerpt'],
        );
        if ( '' !== $payload['slug'] ) {
            $insert['post_name'] = $payload['slug'];
        }

        $post_id = wp_insert_post( wp_slash( $insert ), true );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }
        if ( $payload['featured_media'] && get_post( $payload['featured_media'] ) ) {
            set_post_thumbnail( $post_id, $payload['featured_media'] );
        }
        if ( '' !== $payload['idempotency_key'] ) {
            update_post_meta( $post_id, self::META_IDEMPOTENCY, $payload['idempotency_key'] );
            if ( $payload['idempotency_key'] !== (string) get_post_meta( $post_id, self::META_IDEMPOTENCY, true ) ) {
                return self::quarantine( $post_id, 'The stable idempotency key could not be persisted.' );
            }
        }

        foreach ( $payload['acf'] as $field_name => $value ) {
            self::set_field( $post_id, $field_name, $value );
        }

        // External draft transports such as IFTTT can provide the public
        // source image URL but cannot know a WordPress attachment id. Queue
        // the existing guarded sideload primitive so the private draft gains
        // its featured image, provenance, alt text, and refreshed validation
        // without granting the transport media-library or publish authority.
        $source_image_url = isset( $payload['acf']['journal_image_source_url'] )
            ? esc_url_raw( (string) $payload['acf']['journal_image_source_url'] )
            : '';
        if ( ! $payload['featured_media'] && '' !== $source_image_url && class_exists( 'Lunara_Journal_Image_Sideload' ) ) {
            $source_image_alt = isset( $payload['acf']['journal_image_alt'] )
                ? sanitize_text_field( (string) $payload['acf']['journal_image_alt'] )
                : $payload['title'];
            $source_image_credit = isset( $payload['acf']['journal_image_credit'] )
                ? sanitize_text_field( (string) $payload['acf']['journal_image_credit'] )
                : '';

            // Write optional context first; writing the URL queues the attach.
            update_post_meta( $post_id, Lunara_Journal_Image_Sideload::TRIGGER_ALT, $source_image_alt );
            update_post_meta( $post_id, Lunara_Journal_Image_Sideload::TRIGGER_CREDIT, $source_image_credit );
            update_post_meta( $post_id, Lunara_Journal_Image_Sideload::TRIGGER_URL, $source_image_url );
        }
        self::set_field( $post_id, 'journal_writer_source', 'dispatch' );
        self::set_field( $post_id, 'journal_dispatch_actor', 'Lunara Dispatch Automation' );
        self::set_field( $post_id, 'journal_status', 'needs_chatgpt_review' );
        self::set_field( $post_id, 'journal_validation_status', 'unchecked' );
        self::set_field( $post_id, 'journal_ready_for_review', 0 );
        self::set_field( $post_id, 'journal_dispatch_ingested_at', current_time( 'mysql', true ) );
        self::set_field( $post_id, 'journal_dispatch_conversion_notes', 'Created through Foundation ingest: ' . sanitize_key( $source ) );

        $required = array( 'journal_deck', 'journal_seo_description', 'journal_status', 'journal_validation_status', 'journal_ready_for_review' );
        if ( ! empty( $payload['acf']['journal_source_items'] ) ) {
            $required[] = 'journal_source_items';
        }
        foreach ( array( 'journal_item_type', 'journal_primary_title', 'journal_primary_year', 'journal_people', 'journal_studios_platforms' ) as $field_name ) {
            if ( array_key_exists( $field_name, $payload['acf'] ) ) {
                $required[] = $field_name;
            }
        }
        $expected = array_merge( $payload['acf'], array(
            'journal_status'            => 'needs_chatgpt_review',
            'journal_validation_status' => 'unchecked',
            'journal_ready_for_review'  => 0,
        ) );
        $fields_ok = self::verify_fields( $post_id, $expected, $required );
        if ( is_wp_error( $fields_ok ) ) {
            return self::quarantine( $post_id, $fields_ok->get_error_message() );
        }

        $terms = self::assign_terms( $post_id, $payload );
        if ( is_wp_error( $terms ) ) {
            return self::quarantine( $post_id, $terms->get_error_message() );
        }
        self::disable_publicize( $post_id );

        clean_post_cache( $post_id );
        $post = get_post( $post_id );
        if ( ! $post || 'journal' !== $post->post_type || 'draft' !== $post->post_status ) {
            return self::quarantine( $post_id, 'The Journal draft readback did not match the enforced post type and status.' );
        }

        Lunara_Journal_Provenance::attach_dispatch_provenance( $post_id, $payload['provenance'] );
        $validation = Lunara_Journal_Validator::validate_post( $post_id );
        Lunara_Journal_Provenance::attach_validation_result( $post_id, $validation );
        delete_post_meta( $post_id, '_lunara_journal_ingest_quarantined' );
        return $post_id;
    }

    private static function normalize_source_items( array $body, $title, $content ) {
        $items = array();
        if ( isset( $body['source_items'] ) && is_array( $body['source_items'] ) ) {
            foreach ( $body['source_items'] as $item ) {
                if ( is_array( $item ) ) {
                    $items[] = self::normalize_source_item( $item, $title );
                }
            }
        } elseif ( isset( $body['source_url'] ) ) {
            $items[] = self::normalize_source_item( $body, $title );
        } else {
            foreach ( self::extract_urls( $content ) as $url ) {
                $items[] = self::normalize_source_item( array( 'source_url' => $url ), $title );
            }
        }
        return $items;
    }

    private static function normalize_source_item( array $item, $fallback_title ) {
        return array(
            'source_headline'     => sanitize_text_field( (string) ( $item['source_headline'] ?? $item['headline'] ?? $fallback_title ) ),
            'source_publication'  => sanitize_text_field( (string) ( $item['source_publication'] ?? $item['publication'] ?? '' ) ),
            'source_author'       => sanitize_text_field( (string) ( $item['source_author'] ?? $item['author'] ?? '' ) ),
            'source_url'          => esc_url_raw( (string) ( $item['source_url'] ?? $item['url'] ?? '' ) ),
            'source_published_at' => self::normalize_source_published_at( $item['source_published_at'] ?? $item['published_at'] ?? '' ),
            'source_reliability'  => sanitize_key( (string) ( $item['source_reliability'] ?? $item['reliability'] ?? 'unknown' ) ),
            'source_excerpt'      => sanitize_textarea_field( (string) ( $item['source_excerpt'] ?? $item['excerpt'] ?? '' ) ),
        );
    }

    /**
     * Convert transport-friendly dates to the local database format ACF reads back.
     *
     * Feedly and IFTTT commonly send values such as
     * "August 12, 2026 at 03:54PM". ACF's Date Time Picker normalizes that value
     * when it saves it, so Foundation must normalize the expected value before
     * performing its strict post-write verification.
     *
     * @param mixed $value Source publication date supplied by the transport.
     * @return string
     */
    private static function normalize_source_published_at( $value ) {
        $value = sanitize_text_field( (string) $value );
        if ( '' === $value ) {
            return '';
        }

        $candidate = preg_replace( '/\s+at\s+/i', ' ', $value );
        $timezone  = function_exists( 'wp_timezone' ) ? wp_timezone() : null;
        $date      = $timezone instanceof DateTimeZone
            ? date_create_immutable( $candidate, $timezone )
            : date_create_immutable( $candidate );

        if ( false === $date ) {
            return $value;
        }

        if ( $timezone instanceof DateTimeZone ) {
            $date = $date->setTimezone( $timezone );
        }

        return $date->format( 'Y-m-d H:i:s' );
    }

    private static function find_by_idempotency_key( $key, $any_status ) {
        $ids = get_posts( array(
            'post_type'      => 'journal',
            'post_status'    => $any_status ? 'any' : array( 'draft', 'pending', 'private', 'auto-draft' ),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => self::META_IDEMPOTENCY,
            'meta_value'     => sanitize_text_field( (string) $key ),
            'no_found_rows'  => true,
        ) );
        return $ids ? get_post( absint( $ids[0] ) ) : null;
    }

    private static function assign_terms( $post_id, array $payload ) {
        $section = '' !== $payload['section'] ? $payload['section'] : 'Signal';
        $section_id = self::ensure_term( $section, 'journal_section' );
        if ( is_wp_error( $section_id ) ) {
            return $section_id;
        }
        $set = wp_set_object_terms( $post_id, array( $section_id ), 'journal_section', false );
        if ( is_wp_error( $set ) ) {
            return $set;
        }
        self::set_field( $post_id, 'journal_primary_section', $section_id );
        if ( ! self::matches( $section_id, self::get_field( $post_id, 'journal_primary_section' ) ) || ! self::has_term( $post_id, 'journal_section', $section_id ) ) {
            return new WP_Error( 'lunara_section_readback_failed', 'Journal section assignment could not be verified.' );
        }
        if ( ! self::get_field( $post_id, 'journal_item_type' ) ) {
            self::set_field( $post_id, 'journal_item_type', self::item_type_from_section( $section ) );
        }

        $legacy_id = self::ensure_term( $section, 'journal_type' );
        if ( is_wp_error( $legacy_id ) ) {
            return $legacy_id;
        }
        $legacy_set = wp_set_object_terms( $post_id, array( $legacy_id ), 'journal_type', false );
        if ( is_wp_error( $legacy_set ) || ! self::has_term( $post_id, 'journal_type', $legacy_id ) ) {
            return new WP_Error( 'lunara_legacy_term_readback_failed', 'Legacy Journal taxonomy assignment could not be verified.' );
        }

        $topic_ids = array();
        foreach ( $payload['topics'] as $topic ) {
            $topic_id = self::ensure_term( $topic, 'journal_topic' );
            if ( is_wp_error( $topic_id ) ) {
                return $topic_id;
            }
            $topic_ids[] = $topic_id;
        }
        if ( $topic_ids ) {
            $topic_set = wp_set_object_terms( $post_id, $topic_ids, 'journal_topic', false );
            if ( is_wp_error( $topic_set ) ) {
                return $topic_set;
            }
            foreach ( $topic_ids as $topic_id ) {
                if ( ! self::has_term( $post_id, 'journal_topic', $topic_id ) ) {
                    return new WP_Error( 'lunara_topic_term_readback_failed', 'Journal topic assignments could not be verified.' );
                }
            }
        }
        return true;
    }

    private static function ensure_term( $name, $taxonomy ) {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return new WP_Error( 'lunara_taxonomy_unavailable', 'Required Journal taxonomy is unavailable: ' . $taxonomy );
        }
        $existing = term_exists( sanitize_text_field( (string) $name ), $taxonomy );
        if ( $existing && ! is_wp_error( $existing ) ) {
            return absint( is_array( $existing ) ? $existing['term_id'] : $existing );
        }
        $created = wp_insert_term( sanitize_text_field( (string) $name ), $taxonomy );
        return is_wp_error( $created ) ? $created : absint( $created['term_id'] );
    }

    private static function has_term( $post_id, $taxonomy, $term_id ) {
        $assigned = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
        return ! is_wp_error( $assigned ) && in_array( (int) $term_id, array_map( 'intval', (array) $assigned ), true );
    }

    private static function verify_fields( $post_id, array $expected, array $field_names ) {
        foreach ( array_unique( $field_names ) as $field_name ) {
            if ( ! array_key_exists( $field_name, $expected ) || ! self::matches( $expected[ $field_name ], self::get_field( $post_id, $field_name ), $field_name ) ) {
                return new WP_Error( 'lunara_field_readback_failed', 'Journal field readback failed: ' . $field_name );
            }
        }
        return true;
    }

    private static function matches( $expected, $actual, $field_name = '' ) {
        if ( is_array( $expected ) ) {
            if ( ! is_array( $actual ) || count( $expected ) !== count( $actual ) ) {
                return false;
            }
            foreach ( $expected as $key => $value ) {
                if ( ! array_key_exists( $key, $actual ) || ! self::matches( $value, $actual[ $key ], $field_name ) ) {
                    return false;
                }
            }
            return true;
        }
        if ( self::is_boolean_field( $field_name ) && in_array( $expected, array( false, 0, '0' ), true ) && in_array( $actual, array( false, 0, '0' ), true ) ) {
            return true;
        }
        if ( self::is_boolean_field( $field_name ) && in_array( $expected, array( true, 1, '1' ), true ) && in_array( $actual, array( true, 1, '1' ), true ) ) {
            return true;
        }
        if ( ( null === $expected || false === $expected || '' === $expected ) && ( null === $actual || false === $actual || '' === $actual ) ) {
            return true;
        }
        return (string) $expected === (string) $actual;
    }

    private static function is_boolean_field( $field_name ) {
        return in_array( $field_name, array( 'journal_ready_for_review', 'journal_bridge_locked' ), true );
    }

    private static function quarantine( $post_id, $message ) {
        delete_post_meta( $post_id, self::META_IDEMPOTENCY );
        update_post_meta( $post_id, '_lunara_journal_ingest_quarantined', array(
            'message'        => sanitize_text_field( (string) $message ),
            'quarantined_at' => current_time( 'mysql', true ),
        ) );
        return new WP_Error(
            'lunara_ingest_incomplete',
            'Foundation retained an unpublished quarantine draft because ingest verification failed: ' . $message,
            array( 'post_id' => (int) $post_id, 'post_status' => get_post_status( $post_id ) )
        );
    }

    private static function allowed_fields() {
        return array(
            'journal_kicker', 'journal_deck', 'journal_status', 'journal_item_type', 'journal_priority',
            'journal_source_items', 'journal_primary_title', 'journal_primary_year', 'journal_people',
            'journal_studios_platforms', 'journal_trailer_url', 'journal_original_dispatch_copy',
            'journal_editorial_angle', 'journal_chatgpt_brief', 'journal_seo_title',
            'journal_seo_description', 'journal_image_source_url', 'journal_image_credit', 'journal_image_alt',
        );
    }

    private static function sanitize_field( $field_name, $value ) {
        if ( 'journal_source_items' === $field_name && is_array( $value ) ) {
            $items = array();
            foreach ( $value as $item ) {
                if ( is_array( $item ) ) {
                    $items[] = self::normalize_source_item( $item, '' );
                }
            }
            return $items;
        }
        if ( is_numeric( $value ) && in_array( $field_name, array( 'journal_primary_year' ), true ) ) {
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

    private static function set_field( $post_id, $field_name, $value ) {
        return function_exists( 'update_field' ) ? update_field( $field_name, $value, $post_id ) : update_post_meta( $post_id, $field_name, $value );
    }

    private static function get_field( $post_id, $field_name ) {
        return function_exists( 'get_field' ) ? get_field( $field_name, $post_id ) : get_post_meta( $post_id, $field_name, true );
    }

    private static function sanitize_post_html( $html ) {
        return Lunara_Journal_Ingest_HTML::sanitize( $html );
    }

    /**
     * Add editable paragraph structure to long, otherwise unstructured drafts.
     *
     * This is intentionally conservative: existing multi-paragraph or block
     * markup is never reshaped, and short/ambiguous content is returned intact
     * so the normal validator can continue to fail closed.
     */
    private static function normalize_content_paragraphs( $content ) {
        $content = trim( (string) $content );
        if ( '' === $content || preg_match_all( '/<p\b[^>]*>/i', $content ) >= 2 ) {
            return $content;
        }

        $working = $content;
        if ( preg_match( '/^\s*<p\b[^>]*>(.*)<\/p>\s*$/is', $content, $single_paragraph ) ) {
            $working = trim( $single_paragraph[1] );
        }

        if ( preg_match( '/<(?:address|article|aside|blockquote|div|figure|figcaption|footer|form|h[1-6]|header|hr|li|main|nav|ol|pre|section|table|ul)\b/i', $working ) ) {
            return $content;
        }

        $plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $working ) ) );
        $word_count = str_word_count( html_entity_decode( $plain, ENT_QUOTES, 'UTF-8' ) );
        if ( $word_count < 80 ) {
            return $content;
        }

        $sentences = preg_split( '/(?<=[.!?])\s+(?=(?:<[^>]+>)*[\p{Lu}\p{N}"\x{201C}\x{2018}])/u', $working, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $sentences ) || count( $sentences ) < 4 ) {
            return $content;
        }

        $target_count = min( 6, max( 3, (int) ceil( $word_count / 100 ) ), count( $sentences ) );
        $paragraphs = array();
        $offset = 0;
        for ( $index = 0; $index < $target_count; $index++ ) {
            $remaining_sentences = count( $sentences ) - $offset;
            $remaining_groups = $target_count - $index;
            $take = (int) ceil( $remaining_sentences / $remaining_groups );
            $paragraphs[] = trim( implode( ' ', array_slice( $sentences, $offset, $take ) ) );
            $offset += $take;
        }

        $normalized_before = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $working ) ) );
        $normalized_after = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( implode( ' ', $paragraphs ) ) ) );
        if ( $normalized_before !== $normalized_after ) {
            return $content;
        }

        return implode( "\n\n", array_map( static function ( $paragraph ) {
            return '<p>' . $paragraph . '</p>';
        }, $paragraphs ) );
    }

    private static function excerpt( $text, $limit ) {
        $plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $plain, 0, $limit ) : substr( $plain, 0, $limit );
    }

    private static function extract_urls( $html ) {
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

    private static function item_type_from_section( $section ) {
        $section = strtolower( str_replace( '&', 'and', (string) $section ) );
        if ( false !== strpos( $section, 'trailer' ) ) { return 'trailer'; }
        if ( false !== strpos( $section, 'award' ) ) { return 'awards'; }
        if ( false !== strpos( $section, 'box office' ) ) { return 'box_office'; }
        if ( false !== strpos( $section, 'casting' ) || false !== strpos( $section, 'production' ) ) { return 'casting_production'; }
        if ( false !== strpos( $section, 'physical' ) ) { return 'physical_media'; }
        if ( false !== strpos( $section, 'tv' ) ) { return 'tv_streaming'; }
        if ( false !== strpos( $section, 'streaming' ) ) { return 'streaming'; }
        if ( false !== strpos( $section, 'news' ) ) { return 'news'; }
        return 'signal';
    }

    private static function section_from_item_type( $item_type ) {
        $map = array(
            'news' => 'News', 'trailer' => 'Trailer Reactions', 'casting_production' => 'Casting & Production',
            'awards' => 'Awards Season', 'box_office' => 'Box Office', 'streaming' => 'Streaming',
            'physical_media' => 'Physical Media', 'tv_streaming' => 'TV & Streaming', 'signal' => 'Signal',
        );
        $item_type = sanitize_key( (string) $item_type );
        return isset( $map[ $item_type ] ) ? $map[ $item_type ] : 'Signal';
    }

    private static function disable_publicize( $post_id ) {
        update_post_meta( $post_id, '_jetpack_dont_email_post_to_subs', 1 );
        update_post_meta( $post_id, 'jetpack_publicize_feature_enabled', 0 );
        update_post_meta( $post_id, 'jetpack_publicize_message', '' );
    }
}

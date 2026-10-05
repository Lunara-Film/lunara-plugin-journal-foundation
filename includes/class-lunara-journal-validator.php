<?php
/**
 * Deterministic Journal draft validation.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Journal_Validator {
    public static function validate_post( $post_id, array $config = array() ) {
        $post = get_post( (int) $post_id );
        if ( ! $post ) {
            return array( 'valid' => false, 'errors' => array( 'Post not found.' ), 'warnings' => array() );
        }
        $config = ! empty( $config ) ? $config : Lunara_Journal_Control_Plane::get_active_config();
        $errors = array();
        $warnings = array();

        if ( 'journal' !== $post->post_type ) {
            $errors[] = 'Post type must be journal.';
        }
        if ( 'draft' !== $post->post_status ) {
            $errors[] = 'Journal Bridge readiness requires WordPress post_status=draft.';
        }
        if ( false !== stripos( (string) $post->post_content, '<h2' ) ) {
            $errors[] = 'Content contains disallowed <h2> markup.';
        }
        if ( trim( wp_strip_all_tags( (string) $post->post_title ) ) === '' ) {
            $errors[] = 'Title is required.';
        }
        if ( str_word_count( wp_strip_all_tags( (string) $post->post_content ) ) < (int) ( $config['editorial']['selection']['minimum_words'] ?? 75 ) ) {
            $errors[] = 'Content is below the configured minimum word count.';
        }
        if ( substr_count( strtolower( (string) $post->post_content ), '<p' ) < (int) ( $config['editorial']['selection']['minimum_paragraphs'] ?? 2 ) ) {
            $errors[] = 'Content has fewer than the configured minimum paragraphs.';
        }
        $image = Lunara_Journal_Image_Guard::inspect( $post->ID );
        foreach ( isset( $image['errors'] ) && is_array( $image['errors'] ) ? $image['errors'] : array() as $image_error ) {
            $errors[] = (string) $image_error;
        }
        foreach ( isset( $image['warnings'] ) && is_array( $image['warnings'] ) ? $image['warnings'] : array() as $image_warning ) {
            $warnings[] = (string) $image_warning;
        }

        $deck = self::acf_value( 'journal_deck', $post->ID );
        if ( '' === trim( (string) $post->post_excerpt ) && '' === trim( (string) $deck ) ) {
            $errors[] = 'Excerpt or Journal deck is required.';
        }

        $seo = self::acf_value( 'journal_seo_description', $post->ID );
        if ( '' === trim( (string) $seo ) ) {
            $errors[] = 'SEO description is required.';
        }

        $sources = self::acf_value( 'journal_source_items', $post->ID );
        if ( ! self::has_source_url( $sources ) ) {
            $errors[] = 'At least one source URL is required.';
        }

        $banned = $config['editorial']['voice']['banned_phrases'] ?? array();
        $lower = strtolower( wp_strip_all_tags( (string) $post->post_title . ' ' . (string) $post->post_content ) );
        foreach ( is_array( $banned ) ? $banned : array() as $phrase ) {
            $phrase = strtolower( trim( (string) $phrase ) );
            if ( '' !== $phrase && false !== strpos( $lower, $phrase ) ) {
                $errors[] = 'Banned phrase found: ' . $phrase;
            }
        }

        $poison = $config['editorial']['voice']['expertise_poison_phrases'] ?? array();
        foreach ( is_array( $poison ) ? $poison : array() as $phrase ) {
            $phrase = strtolower( trim( (string) $phrase ) );
            if ( '' !== $phrase && false !== strpos( $lower, $phrase ) ) {
                $warnings[] = 'House tell found, cut on sight: ' . $phrase;
            }
        }

        if ( preg_match( '/\x{FFFD}|[\x{0080}-\x{009F}]|â€|Ã[\x{0080}-\x{00BF}]/u', (string) $post->post_content ) ) {
            $warnings[] = 'Content contains damaged characters (mojibake). Re-paste it from the source.';
        }

        return array(
            'valid'       => empty( $errors ),
            'errors'      => array_values( array_unique( $errors ) ),
            'warnings'    => array_values( array_unique( $warnings ) ),
            'image'       => $image,
            'checked_at'  => current_time( 'mysql', true ),
            'config_version' => $config['config_version'] ?? '1.0.0',
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
}

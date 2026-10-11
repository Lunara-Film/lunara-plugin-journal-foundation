<?php
/**
 * Presentation-free HTML for new automation drafts only.
 *
 * This is deliberately not a post-save filter. Human edits, published entries,
 * and the Review importer keep their own contracts.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Journal_Ingest_HTML {
    /** @return string|WP_Error */
    public static function sanitize( $html ) {
        $html = (string) $html;
        if ( '' === trim( $html ) ) {
            return $html;
        }

        if ( class_exists( 'WP_HTML_Tag_Processor' ) && method_exists( 'WP_HTML_Tag_Processor', 'get_attribute_names_with_prefix' ) ) {
            $clean = self::wordpress_html( $html );
        } elseif ( class_exists( 'DOMDocument' ) ) {
            $clean = self::dom_html( $html );
        } else {
            return new WP_Error( 'lunara_ingest_html_parser_unavailable', 'Journal ingestion requires the WordPress HTML API or the PHP DOM extension.' );
        }
        if ( is_wp_error( $clean ) ) {
            return $clean;
        }

        // Keep WordPress's semantic post schema (images, lists, tables, code,
        // captions, accessibility and data attributes), without presentation.
        $allowed = wp_kses_allowed_html( 'post' );
        foreach ( $allowed as &$attributes ) {
            if ( is_array( $attributes ) ) {
                unset( $attributes['class'], $attributes['style'] );
            }
        }
        unset( $attributes );
        return wp_kses( $clean, $allowed );
    }

    private static function forbidden_tag( $tag ) {
        return in_array( strtolower( $tag ), array( 'script', 'style', 'iframe', 'object', 'embed', 'applet', 'base', 'link', 'meta', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'template' ), true );
    }

    private static function unsafe_attribute( $name, $value ) {
        $name = strtolower( $name );
        if ( 0 === strpos( $name, 'on' ) || 'srcdoc' === $name ) {
            return true;
        }
        if ( in_array( $name, array( 'href', 'src', 'cite', 'action', 'formaction', 'poster', 'xlink:href' ), true ) ) {
            // This expression checks a URL scheme, never HTML structure.
            $url = preg_replace( '/[\x00-\x20\x7f]+/', '', html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            return preg_match( '/^([a-z][a-z0-9+.-]*):/i', $url, $scheme )
                && ! in_array( strtolower( $scheme[1] ), array( 'http', 'https', 'mailto', 'tel' ), true );
        }
        return false;
    }

    private static function rejection() {
        return new WP_Error( 'lunara_ingest_executable_html', 'Journal automation contains executable or embedded markup. Supply editorial HTML; keep trailers and media provenance in their dedicated fields.' );
    }

    private static function wordpress_html( $html ) {
        $processor = new WP_HTML_Tag_Processor( $html );
        while ( $processor->next_tag() ) {
            if ( self::forbidden_tag( $processor->get_tag() ) ) {
                return self::rejection();
            }
            foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $name ) {
                if ( self::unsafe_attribute( $name, $processor->get_attribute( $name ) ) ) {
                    return self::rejection();
                }
            }
            $processor->remove_attribute( 'class' );
            $processor->remove_attribute( 'style' );
        }
        if ( method_exists( $processor, 'paused_at_incomplete_token' ) && $processor->paused_at_incomplete_token() ) {
            return new WP_Error( 'lunara_ingest_incomplete_html', 'Journal automation ended inside an HTML token. Complete the source markup before ingesting it.' );
        }
        return $processor->get_updated_html();
    }

    private static function dom_html( $html ) {
        $document = new DOMDocument( '1.0', 'UTF-8' );
        $previous = libxml_use_internal_errors( true );
        try {
            $loaded = $document->loadHTML( '<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NONET );
            if ( ! $loaded ) {
                return new WP_Error( 'lunara_ingest_invalid_html', 'Journal automation HTML could not be parsed.' );
            }
            $body = $document->getElementsByTagName( 'body' )->item( 0 );
            if ( ! $body ) {
                return new WP_Error( 'lunara_ingest_invalid_html', 'Journal automation HTML has no readable body.' );
            }
            // Inspect the whole parsed document, including elements a parser
            // moved out of the body. Do not enable entity or network loading.
            foreach ( $document->getElementsByTagName( '*' ) as $element ) {
                if ( self::forbidden_tag( $element->tagName ) ) {
                    return self::rejection();
                }
                foreach ( $element->attributes as $attribute ) {
                    if ( self::unsafe_attribute( $attribute->name, $attribute->value ) ) {
                        return self::rejection();
                    }
                }
                $element->removeAttribute( 'class' );
                $element->removeAttribute( 'style' );
            }
            $output = '';
            foreach ( $body->childNodes as $node ) {
                $output .= $document->saveHTML( $node );
            }
            return $output;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors( $previous );
        }
    }
}

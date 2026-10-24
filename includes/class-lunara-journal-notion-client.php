<?php
/**
 * Minimal Notion client for one-way Journal HQ mirror sync.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Journal_Notion_Client {
    const OPTION_TOKEN   = 'lunara_journal_notion_token';
    const OPTION_PAGE_ID = 'lunara_journal_notion_page_id';
    const OPTION_LAST    = 'lunara_journal_notion_last_sync';
    const OPTION_ERROR   = 'lunara_journal_notion_last_error';

    public static function has_credentials() {
        return '' !== self::token() && '' !== trim( (string) get_option( self::OPTION_PAGE_ID, '' ) );
    }

    public static function sync_config( array $config ) {
        if ( ! self::has_credentials() ) {
            update_option( self::OPTION_ERROR, 'Notion credentials are not configured.', false );
            return new WP_Error( 'lunara_notion_missing_credentials', 'Notion credentials are not configured.' );
        }

        $token = self::token();
        $page_id = trim( (string) get_option( self::OPTION_PAGE_ID, '' ) );
        $payload = array(
            'children' => self::build_blocks( $config ),
        );

        $response = wp_remote_request( 'https://api.notion.com/v1/blocks/' . rawurlencode( $page_id ) . '/children', array(
            'method'  => 'PATCH',
            'timeout' => 20,
            'headers' => array(
                'Authorization'  => 'Bearer ' . $token,
                'Content-Type'   => 'application/json',
                'Notion-Version' => '2022-06-28',
            ),
            'body' => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            update_option( self::OPTION_ERROR, $response->get_error_message(), false );
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            $error = 'Notion sync failed with HTTP ' . $code . '.';
            update_option( self::OPTION_ERROR, $error, false );
            return new WP_Error( 'lunara_notion_http_error', $error );
        }
        update_option( self::OPTION_LAST, current_time( 'mysql', true ), false );
        update_option( self::OPTION_ERROR, '', false );
        return true;
    }

    private static function token() {
        if ( defined( 'LUNARA_NOTION_TOKEN' ) ) {
            $constant = trim( (string) constant( 'LUNARA_NOTION_TOKEN' ) );
            if ( '' !== $constant ) {
                return $constant;
            }
        }

        $environment = getenv( 'LUNARA_NOTION_TOKEN' );
        if ( is_string( $environment ) && '' !== trim( $environment ) ) {
            return trim( $environment );
        }

        return trim( (string) get_option( self::OPTION_TOKEN, '' ) );
    }

    public static function build_blocks( array $config ) {
        $summary = Lunara_Journal_Prompt_Compiler::public_summary( $config );
        $lines = array(
            'Active configuration: ' . ( $summary['config_version'] ?? '1.0.0' ),
            'Provider: ' . ( $summary['provider'] ?? '' ),
            'Schedule: ' . ( $summary['schedule'] ?? '' ),
            'Target: journal / draft',
            'Enabled sources: ' . (int) ( $summary['sources_enabled'] ?? 0 ),
            'Synced from WordPress. Notion is a mirror only and cannot activate production configuration.',
        );
        return array(
            array(
                'object' => 'block',
                'type' => 'heading_2',
                'heading_2' => array( 'rich_text' => array( self::text_object( 'LUNARA FILM Journal Control Plane' ) ) ),
            ),
            array(
                'object' => 'block',
                'type' => 'paragraph',
                'paragraph' => array( 'rich_text' => array( self::text_object( implode( "\n", $lines ) ) ) ),
            ),
            array(
                'object' => 'block',
                'type' => 'code',
                'code' => array(
                    'language' => 'json',
                    'rich_text' => array( self::text_object( wp_json_encode( Lunara_Journal_Prompt_Compiler::public_summary( $config ), JSON_PRETTY_PRINT ) ) ),
                ),
            ),
        );
    }

    private static function text_object( $text ) {
        $text = (string) $text;
        $text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 1900 ) : substr( $text, 0, 1900 );
        return array( 'type' => 'text', 'text' => array( 'content' => $text ) );
    }
}

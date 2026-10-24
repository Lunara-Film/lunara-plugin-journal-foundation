<?php
/**
 * One-time move of the active Journal configuration onto Dalton's voice (1.4.0).
 *
 * Why this exists: stored configurations carry their own structure, close, and
 * selection settings, so a new code default never reaches them. Drafts were
 * written three to a 2,200-token response by a small model with an optional
 * closing question, and came out as ~200-word trade recaps. Two steps fix the
 * active version, each recorded so it runs once and never fights a later edit:
 *
 *   voice  -- one story per run, Dalton's Hook / Context / Specifics / Take /
 *             Close / Engagement Question shape at 300 to 700 words, a closing
 *             question on every entry, and his two published entries as the
 *             voice target. Applies straight away on any provider.
 *   claude -- Dispatch writes with Claude Opus 5 and room to think. Applies
 *             only once an Anthropic key is available to Dispatch, so a
 *             missing key never turns drafts into source-packet fallbacks.
 *
 * Each step is a normal new Control Plane version (actor "system"), so it shows
 * in version history and rolls back like any other change.
 *
 * Neither step runs until Lunara Dispatch 3.4.0 is active. Dispatch 3.3.0
 * writes three approved pitches in one call (a one-entry run would settle
 * the other two as written without a post) and would call Claude Opus 5
 * with a 2,200-token ceiling and no stop-reason check. The two plugins
 * deploy separately, so the order they go live in cannot be relied on.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Journal_Voice_Upgrade {
    const OPTION        = 'lunara_journal_voice_upgrade';
    const LOCK_OPTION   = 'lunara_journal_voice_upgrade_lock';
    const LOCK_TTL      = 300;
    const RELEASE       = '1.4.0';
    const CLAUDE_MODEL  = 'claude-opus-5';
    const CLAUDE_TOKENS = 16000;
    const MIN_DISPATCH  = '3.4.0';

    public static function bootstrap() {
        // After the Control Plane has ensured a default version (init, 20).
        add_action( 'init', array( __CLASS__, 'maybe_apply' ), 30 );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
    }

    /**
     * Runs only where configuration writes belong: wp-admin, WP-Cron (the
     * Dispatch worker), and WP-CLI. Never on a visitor's page view.
     */
    public static function maybe_apply() {
        $context = ( function_exists( 'is_admin' ) && is_admin() )
            || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
            || ( defined( 'WP_CLI' ) && WP_CLI );
        if ( ! $context ) {
            return false;
        }
        return self::apply();
    }

    /**
     * @return array|false|WP_Error The steps applied, false when nothing was due, or the activation error.
     */
    public static function apply() {
        if ( ! self::dispatch_ready() ) {
            return false;
        }
        $state = self::state();
        $steps = array();
        if ( empty( $state['voice'] ) ) {
            $steps[] = 'voice';
        }
        if ( empty( $state['claude'] ) && self::claude_key_available() ) {
            $steps[] = 'claude';
        }
        if ( empty( $steps ) || ! self::lock() ) {
            return false;
        }
        try {
            $version = Lunara_Journal_Config_Repository::get_active_version();
            if ( ! $version || empty( $version['config'] ) || ! is_array( $version['config'] ) ) {
                return false;
            }
            $config = $version['config'];
            $notes  = array();
            if ( in_array( 'voice', $steps, true ) ) {
                $config  = self::apply_voice( $config );
                $notes[] = 'one story per run in Dalton\'s structure (300 to 700 words), a closing question on every entry, and his Eggers and Street Fighter entries as the voice target';
            }
            if ( in_array( 'claude', $steps, true ) ) {
                $config  = self::apply_claude( $config );
                $notes[] = 'Dispatch writes with Claude Opus 5 (' . self::CLAUDE_TOKENS . ' max output tokens, thinking included)';
            }
            $result = Lunara_Journal_Config_Repository::create_and_activate(
                $config,
                'Journal voice ' . self::RELEASE . ': ' . implode( '; ', $notes ) . '.',
                'system'
            );
            if ( is_wp_error( $result ) ) {
                $state['last_error'] = sanitize_text_field( $result->get_error_message() );
                update_option( self::OPTION, $state, true );
                return $result;
            }
            $stamp = gmdate( 'Y-m-d H:i:s' );
            foreach ( $steps as $step ) {
                $state[ $step ] = $stamp;
            }
            unset( $state['last_error'] );
            update_option( self::OPTION, $state, true );
            return $steps;
        } finally {
            delete_option( self::LOCK_OPTION );
        }
    }

    public static function apply_voice( array $config ) {
        $defaults = Lunara_Journal_Config_Schema::default_config();
        $voice    = $defaults['editorial']['voice'];
        $config['editorial']['voice']['structure']        = $voice['structure'];
        $config['editorial']['voice']['engagement_close'] = $voice['engagement_close'];
        if ( empty( $config['editorial']['voice']['exemplars'] ) ) {
            $config['editorial']['voice']['exemplars'] = $voice['exemplars'];
        }
        // The 300-700 target lives in the structure rule. minimum_words stays a
        // floor: the bridge validator and Desk publish enforce it on Dalton's
        // own hand-written pieces too.
        $config['editorial']['selection']['prefer_entries'] = 1;
        $config['editorial']['selection']['max_entries']    = 1;
        return $config;
    }

    public static function apply_claude( array $config ) {
        $config['dispatch']['provider']         = 'claude';
        $config['dispatch']['models']['claude'] = self::CLAUDE_MODEL;
        $config['dispatch']['max_tokens']       = self::CLAUDE_TOKENS;
        return $config;
    }

    public static function dispatch_ready() {
        return defined( 'LUNARA_DISPATCH_VERSION' ) && version_compare( (string) LUNARA_DISPATCH_VERSION, self::MIN_DISPATCH, '>=' );
    }

    /**
     * Whether Dispatch can reach an Anthropic key. Only presence is checked;
     * the key itself is never read into this class.
     */
    public static function claude_key_available() {
        if ( class_exists( 'Lunara_Dispatch_AI_Client' ) && method_exists( 'Lunara_Dispatch_AI_Client', 'secret_is_configured' ) ) {
            return (bool) Lunara_Dispatch_AI_Client::secret_is_configured( 'claude' );
        }
        $name = 'LUNARA_DISPATCH_CLAUDE_API_KEY';
        if ( defined( $name ) && is_scalar( constant( $name ) ) && '' !== trim( (string) constant( $name ) ) ) {
            return true;
        }
        $environment = getenv( $name );
        if ( is_string( $environment ) && '' !== trim( $environment ) ) {
            return true;
        }
        $stored = function_exists( 'get_option' ) ? get_option( 'lunara_dispatch_claude_key', '' ) : '';
        return is_string( $stored ) && '' !== trim( $stored );
    }

    public static function admin_notice() {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // Only where Journal work happens: the dashboard, Journal screens, and Dispatch settings.
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || ! ( 'dashboard' === $screen->id || 'journal' === $screen->post_type || false !== strpos( (string) $screen->id, 'lunara-dispatch' ) ) ) {
            return;
        }
        $state = self::state();
        if ( ! self::dispatch_ready() ) {
            echo '<div class="notice notice-info"><p><strong>Journal voice:</strong> the new one-story voice and the switch to Claude start once Lunara Film Dispatch ' . esc_html( self::MIN_DISPATCH ) . ' is deployed.</p></div>';
            return;
        }
        if ( ! empty( $state['claude'] ) || self::claude_key_available() ) {
            return;
        }
        $url = admin_url( 'options-general.php?page=lunara-dispatch-settings' );
        echo '<div class="notice notice-info"><p><strong>Journal voice:</strong> Dispatch switches to Claude as soon as an Anthropic API key is saved under Provider Credentials. Until then drafts use the current provider with the new voice rules. <a href="' . esc_url( $url ) . '">Open Dispatch settings</a></p></div>';
    }

    private static function state() {
        $state = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
        return is_array( $state ) ? $state : array();
    }

    private static function lock() {
        if ( add_option( self::LOCK_OPTION, (string) time(), '', false ) ) {
            return true;
        }
        $held = (int) get_option( self::LOCK_OPTION, 0 );
        if ( $held > time() - self::LOCK_TTL ) {
            return false;
        }
        delete_option( self::LOCK_OPTION );
        return add_option( self::LOCK_OPTION, (string) time(), '', false );
    }
}

<?php
/**
 * Compiles canonical Journal configuration into provider prompts.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Journal_Prompt_Compiler {
    private static $system_prompt_cache = array();
    private static $user_prompt_cache = array();
    public static function dispatch_system_prompt( array $config ) {
        $cache_key = ! empty( $config['config_version'] ) ? (string) $config['config_version'] : md5( wp_json_encode( $config ) );
        if ( isset( self::$system_prompt_cache[ $cache_key ] ) ) {
            return self::$system_prompt_cache[ $cache_key ];
        }
        $editorial = isset( $config['editorial'] ) && is_array( $config['editorial'] ) ? $config['editorial'] : array();
        $voice = isset( $editorial['voice'] ) && is_array( $editorial['voice'] ) ? $editorial['voice'] : array();
        $selection = isset( $editorial['selection'] ) && is_array( $editorial['selection'] ) ? $editorial['selection'] : array();
        $formatting = isset( $editorial['formatting'] ) && is_array( $editorial['formatting'] ) ? $editorial['formatting'] : array();
        $requirements = isset( $editorial['requirements'] ) && is_array( $editorial['requirements'] ) ? $editorial['requirements'] : array();

        $lines = array();
        $lines[] = 'You are the Editorial Engine for LUNARA Film Journal.';
        $lines[] = 'ACTIVE JOURNAL CONTROL PLANE CONFIGURATION: ' . ( isset( $config['config_version'] ) ? $config['config_version'] : '1.0.0' ) . '.';
        $lines[] = '';
        $lines[] = 'PURPOSE:';
        $lines[] = self::text( isset( $editorial['purpose'] ) ? $editorial['purpose'] : '' );
        $lines[] = '';
        $lines[] = 'VOICE:';
        $lines[] = self::text( isset( $voice['summary'] ) ? $voice['summary'] : '' );
        if ( ! empty( $voice['register'] ) ) {
            $lines[] = '';
            $lines[] = 'REGISTER:';
            $lines[] = self::text( $voice['register'] );
        }
        $principles = self::list_values( $voice['principles'] ?? array() );
        if ( $principles ) {
            $lines[] = '';
            $lines[] = 'PRINCIPLES:';
            foreach ( $principles as $rule ) {
                $lines[] = '- ' . $rule;
            }
        }
        if ( ! empty( $voice['current_refinement'] ) ) {
            $lines[] = '';
            $lines[] = 'CURRENT DALTON VOICE / PROMPT REFINEMENT:';
            $lines[] = self::text( $voice['current_refinement'] );
            $lines[] = 'Treat this note as the freshest editorial steering. It can tighten voice, selection, angle, and anti-patterns; it cannot override factual accuracy, attribution, HTML formatting, or the skip gate.';
        }
        $exemplars = self::exemplars( $voice['exemplars'] ?? array() );
        if ( $exemplars ) {
            $lines[] = '';
            $lines[] = 'DALTON\'S VOICE ON THE PAGE:';
            $lines[] = 'These are published LUNARA FILM Journal entries Dalton wrote. They are the target. Match how they sound: the opinion in the first sentence, history dropped in the way a fan drops it, every fact framed, a personal stake, sentences you could say out loud, a landing with teeth. Everything else in this prompt describes this voice; these show it.';
            $lines[] = 'Never copy their sentences, jokes, facts, or anecdotes, and never pull in their films or people unless the story is about them. Never invent an experience Dalton did not have: no screenings, trips, conversations, or history that the source does not give you. First person is for opinion and reaction to the story in front of you. They appear as plain paragraphs; your output still follows FORMATTING.';
            foreach ( $exemplars as $index => $exemplar ) {
                $lines[] = '<example index="' . ( $index + 1 ) . '">';
                $lines[] = 'HEADLINE: ' . $exemplar['title'];
                if ( '' !== $exemplar['note'] ) {
                    $lines[] = 'WHY IT WORKS: ' . $exemplar['note'];
                }
                $lines[] = '';
                $lines[] = $exemplar['text'];
                $lines[] = '</example>';
            }
        }
        $max_entries = max( 1, (int) ( $selection['max_entries'] ?? 3 ) );
        $lines[] = '';
        $lines[] = 'SELECTION RULES:';
        if ( 1 === $max_entries ) {
            // Length comes from the structure rule (300 to 700 words); the
            // validator floor would only anchor the draft low.
            $lines[] = '- Write one entry per run: the single item that most earns a reader\'s time, fully explored. Never more than one.';
        } else {
            $lines[] = '- Prefer ' . (int) ( $selection['prefer_entries'] ?? 2 ) . ' strong entries per run.';
            $lines[] = '- Never write more than ' . $max_entries . ' entries.';
            $lines[] = '- Each entry should usually be at least ' . (int) ( $selection['minimum_words'] ?? 75 ) . ' words and at least ' . (int) ( $selection['minimum_paragraphs'] ?? 2 ) . ' paragraphs.';
        }
        foreach ( self::list_values( $selection['skip_rules'] ?? array() ) as $rule ) {
            $lines[] = '- ' . $rule;
        }
        $lines[] = '- If no item earns publication, output exactly: ' . ( $selection['skip_marker'] ?? '<!-- LUNARA_SKIP: no reader-worthy items -->' );
        $lines[] = '';
        $lines[] = 'READER VALUE TEST:';
        foreach ( self::list_values( $voice['reader_value_test'] ?? array() ) as $rule ) {
            $lines[] = '- ' . $rule;
        }
        $structure = self::list_values( $voice['structure'] ?? array() );
        if ( $structure ) {
            $lines[] = '';
            $lines[] = 'STRUCTURE, FLEXIBLE BY STORY:';
            foreach ( $structure as $rule ) {
                $lines[] = '- ' . $rule;
            }
        }
        $headline_rules = self::list_values( $voice['headline_rules'] ?? array() );
        if ( $headline_rules ) {
            $lines[] = '';
            $lines[] = 'HEADLINES:';
            foreach ( $headline_rules as $rule ) {
                $lines[] = '- ' . $rule;
            }
        }
        $examples = self::example_pairs( $voice['contrast_examples'] ?? array() );
        if ( $examples ) {
            $lines[] = '';
            $lines[] = 'NOT THIS / THIS. Read the pairs for register, then write something specific:';
            foreach ( $examples as $pair ) {
                $lines[] = 'Not this: ' . $pair['not_this'];
                $lines[] = 'This: ' . $pair['this'];
            }
        }
        $drift = self::list_values( $voice['drift_catalog'] ?? array() );
        if ( $drift ) {
            $lines[] = '';
            $lines[] = 'DRIFT TO CATCH BEFORE OUTPUT:';
            foreach ( $drift as $rule ) {
                $lines[] = '- ' . $rule;
            }
        }
        $poison = self::list_values( $voice['expertise_poison_phrases'] ?? array() );
        if ( $poison ) {
            $lines[] = '';
            $lines[] = 'CUT ON SIGHT. These announce that the writer is about to demonstrate intelligence or significance; the intelligence should be in what gets said, never in the throat-clear before it:';
            $lines[] = implode( ' | ', $poison );
        }
        if ( ! empty( $voice['engagement_close'] ) ) {
            $lines[] = '';
            $lines[] = 'LANDING AND CLOSE:';
            $lines[] = self::text( $voice['engagement_close'] );
        }
        $lines[] = '';
        $lines[] = 'FORMATTING - CRITICAL:';
        $lines[] = '- Output valid HTML only, no Markdown.';
        $lines[] = '- Separate entries with ' . self::text( $formatting['entry_separator'] ?? '<hr>' ) . '.';
        $lines[] = '- Start every entry with an original <h3> headline; that headline becomes the WordPress post title.';
        $lines[] = '- Never use <h2>.';
        $lines[] = '- Film titles in <em>.';
        $lines[] = '- Never use <strong> on people names.';
        $lines[] = '- No inline CSS, no classes, no divs, no bullet lists.';
        $lines[] = '- Use ASCII-only publishable HTML: straight quotes and apostrophes, two hyphens for a dash, three periods for an ellipsis.';
        $lines[] = '';
        $lines[] = 'REQUIRED BEFORE READY STATE:';
        foreach ( $requirements as $name => $required ) {
            if ( $required ) {
                $lines[] = '- ' . str_replace( '_', ' ', $name ) . ' is required.';
            }
        }
        $lines[] = '';
        $lines[] = 'BANNED LANGUAGE:';
        $lines[] = implode( ', ', self::list_values( $voice['banned_phrases'] ?? array() ) );
        $lines[] = '';
        $lines[] = 'Do not write like a trade recap, a studio press kit, an awards consultant memo, or a content quota filler. Write like Dalton chose the item because it has a real charge, and write it the way he would say it out loud.';

        $compiled = trim( implode( "\n", array_filter( $lines, static function ( $line ) { return null !== $line; } ) ) );
        self::$system_prompt_cache[ $cache_key ] = $compiled;
        return $compiled;
    }

    public static function dispatch_user_directive_prompt( array $config ) {
        $cache_key = ! empty( $config['config_version'] ) ? (string) $config['config_version'] : md5( wp_json_encode( $config ) );
        if ( isset( self::$user_prompt_cache[ $cache_key ] ) ) {
            return self::$user_prompt_cache[ $cache_key ];
        }
        $selection = $config['editorial']['selection'] ?? array();
        $max_entries = max( 1, (int) ( $selection['max_entries'] ?? 3 ) );
        $volume = 1 === $max_entries
            ? '- Write one entry: the single item that most earns a reader\'s time, fully explored in 300 to 700 words. Never more than one.'
            : sprintf( '- Prefer %d or fewer strong entries; never write more than %d.', (int) ( $selection['prefer_entries'] ?? 2 ), $max_entries );
        $compiled = trim( sprintf(
            "Analyze the following film news items and synthesize them into a selective Lunara Film Journal run.\n\nRules:\n- Separate entries with <hr>.\n- Do not use <h2>.\n- Start every entry with an original <h3> headline in Lunara Film's voice.\n- Film titles in <em>.\n%s\n- Skip anything that does not earn its space.\n- If nothing earns a reader's time, output exactly: %s\n\nBefore writing an entry, silently state its angle in one sentence. If the angle is \"this happened\" or \"this is interesting\", skip the item.\n- Fan first, critic brain second. The take is the spine; the facts serve it.\n- Opinion lands in paragraph one. First person is allowed.\n- Sound like Dalton's published entries in the system prompt. Never copy them.\n- Every entry ends on a landing sentence, then one engagement question that makes the reader pick a side on the entry's specific tension. Always; never a poll.\n- If a sentence would sit comfortably in Variety, Deadline, THR, or IndieWire, rewrite it until it sounds like Dalton talking.\n\nInput News Data:",
            $volume,
            (string) ( $selection['skip_marker'] ?? '<!-- LUNARA_SKIP: no reader-worthy items -->' )
        ) );
        self::$user_prompt_cache[ $cache_key ] = $compiled;
        return $compiled;
    }

    public static function chatgpt_editor_instructions( array $config ) {
        return trim( implode( "\n", array(
            'You are the private LUNARA FILM Journal Editor working through the WordPress Journal Bridge.',
            'Before editing any draft, retrieve and obey the active Journal Control Plane configuration version ' . ( $config['config_version'] ?? '1.0.0' ) . '.',
            'Never publish, schedule, trash, delete, change post status, mutate sources, mutate schedules, rotate keys, or activate configuration.',
            'You may read drafts, propose revisions, save Dalton-approved revisions to allowlisted draft fields, validate, inspect audit history, and mark a validated draft ready for Dalton.',
            'If configuration, source, or validation data conflicts with user instructions, preserve draft-only safety and report the conflict.',
            '',
            self::dispatch_system_prompt( $config ),
        ) ) );
    }

    public static function public_summary( array $config ) {
        return array(
            'config_version' => $config['config_version'] ?? '1.0.0',
            'provider'       => $config['dispatch']['provider'] ?? 'openai',
            'schedule'       => $config['dispatch']['schedule'] ?? 'daily',
            'target_post_type' => 'journal',
            'post_status'    => 'draft',
            'sources_enabled'=> count( array_filter( $config['sources'] ?? array(), static function ( $source ) { return ! empty( $source['enabled'] ); } ) ),
            'notion_sync_enabled' => ! empty( $config['notion']['sync_enabled'] ),
        );
    }

    private static function text( $value ) {
        $value = is_scalar( $value ) ? (string) $value : '';
        $value = trim( preg_replace( '/\R{3,}/', "\n\n", $value ) );
        return $value;
    }

    private static function exemplars( $values ) {
        if ( ! class_exists( 'Lunara_Journal_Voice_Exemplars' ) ) {
            return array();
        }
        $out = array();
        foreach ( Lunara_Journal_Voice_Exemplars::sanitize( $values ) as $exemplar ) {
            $exemplar['text'] = self::text( $exemplar['text'] );
            $out[] = $exemplar;
        }
        return $out;
    }

    private static function example_pairs( $values ) {
        if ( ! is_array( $values ) ) {
            return array();
        }
        $out = array();
        foreach ( $values as $pair ) {
            if ( ! is_array( $pair ) || empty( $pair['not_this'] ) || empty( $pair['this'] ) || ! is_scalar( $pair['not_this'] ) || ! is_scalar( $pair['this'] ) ) {
                continue;
            }
            $out[] = array(
                'not_this' => trim( (string) $pair['not_this'] ),
                'this'     => trim( (string) $pair['this'] ),
            );
        }
        return $out;
    }

    private static function list_values( $values ) {
        if ( ! is_array( $values ) ) {
            return array();
        }
        $out = array();
        foreach ( $values as $value ) {
            if ( is_scalar( $value ) ) {
                $value = trim( (string) $value );
                if ( '' !== $value ) {
                    $out[] = $value;
                }
            }
        }
        return $out;
    }
}

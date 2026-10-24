<?php
/**
 * Canonical Journal configuration schema and defaults.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// The default voice embeds Dalton's exemplars; loaded here so every consumer of the schema has them.
require_once __DIR__ . '/class-lunara-journal-voice-exemplars.php';

final class Lunara_Journal_Config_Schema {
    const DEFAULT_OPENAI_MODEL = 'gpt-5.4-mini';
    const MAX_OUTPUT_TOKENS    = 2200;
    const DEFAULT_CLAUDE_MODEL = 'claude-opus-5';
    // Claude's max_tokens covers its thinking as well as the entry, so the
    // OpenAI cost cap would cut a Claude draft off mid-thought.
    const CLAUDE_MAX_OUTPUT_TOKENS = 16000;

    /**
     * Voice lists an editor can shorten. A stored list replaces the default
     * outright; recursive numeric merging would silently resurrect removed
     * entries (or splice an old list onto the tail of a longer new one).
     */
    const VOICE_LISTS = array( 'banned_phrases', 'reader_value_test', 'principles', 'expertise_poison_phrases', 'structure', 'headline_rules', 'contrast_examples', 'drift_catalog', 'exemplars' );

    public static function allowed_openai_models() {
        return array( 'gpt-5.4-mini', 'gpt-5.4-nano' );
    }

    /**
     * Output-token ceiling for a provider: Claude gets room to think and
     * write one full entry; every other provider keeps the 2,200 cost cap.
     */
    public static function max_output_tokens_for( $provider ) {
        return 'claude' === (string) $provider ? self::CLAUDE_MAX_OUTPUT_TOKENS : self::MAX_OUTPUT_TOKENS;
    }

    public static function default_config( array $legacy = array() ) {
        $now = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

        return array(
            'protocol_version' => Lunara_Journal_Protocol::VERSION,
            'schema_version'   => Lunara_Journal_Protocol::SCHEMA_VERSION,
            'config_version'   => '1.0.0',
            'status'           => 'active',
            'created_at_gmt'   => $now,
            'activated_at_gmt' => $now,
            'changelog'        => 'Initial Journal Control Plane configuration generated from existing Dispatch/Foundation settings.',
            'editorial'        => array(
                'purpose' => 'LUNARA FILM Journal is the living news-desk and magazine side of LUNARA Film: selective, critic-led, film-fan-aware dispatches that exist only when Dalton has a real reason to surface the item.',
                'voice'   => array(
                    'summary' => 'Conversational, exact, sharp, fan-aware, critic-led, alive without sounding like trade-copy filler.',
                    'reader_value_test' => array(
                        'Would a film reader text this to a friend with a reaction?',
                        'Does it reveal a meaningful pressure point around a movie, filmmaker, studio, actor, audience behavior, box office signal, festival strategy, awards race, or the business of taste?',
                        'Can LUNARA FILM add judgment, excitement, skepticism, context, taste, stakes, contradiction, or reader-facing implication beyond the source?'
                    ),
                    'banned_phrases' => array(
                        'autopsy',
                        'ever-evolving',
                        'poised to',
                        'made waves',
                        'must-see',
                        'garnering attention',
                        'cinematic discourse',
                        'in the current landscape',
                        'raises significant questions',
                        'unprecedented',
                        'game-changer',
                        'a love letter to',
                        'at the forefront of',
                        'highly anticipated',
                        'this matters because',
                        'this is significant as',
                        'could potentially',
                        'part of the conversation',
                        'worth keeping an eye on',
                        'only time will tell',
                        'fans are eagerly awaiting',
                        'delves into',
                        'underscores',
                        'a testament to'
                    ),
                    'register' => 'Write it the way you would say it. The Journal is talk, not essay: the first thing Dalton would say to a friend who trusts his taste, except the first thing already lands sharper than most people\'s third draft because the knowledge is internalized and the take is already formed. Fan first, critic second: the excitement or the disappointment is the engine of the entry, not a flavor on top. First person is allowed and often right: "I watched this trailer twice," "I don\'t trust this casting," "I\'m scared but I\'m in." The reader came here for a voice, not a report.',
                    'principles' => array(
                        'Always a take. Every entry stakes a position by the end of paragraph one. Not what happened; what Dalton thinks about what happened. If there is no opinion, there is no entry.',
                        'Worn-lightly expertise. The IP history, the director\'s track record, the last time the studio got this right: all of it can be in the entry, none of it presented. Knowledge drops in as asides, never as evidence. The voice never sets up its credentials.',
                        'The angle arrives fast. The point lands in the first paragraph. Everything after it is evidence, texture, and escalation, not a march toward a conclusion the reader is waiting for.',
                        'Compression without stiffness. Cut until every paragraph does double duty, but the test is not "is this elegant?" It is "would I actually say this?" A stray joke or an aside is fine when it advances the take.',
                        'Contempt and admiration both need specifics. "This guy sucks" is a blog comment. "This guy hasn\'t made a watchable movie since 2009" is a Journal entry. Same rule for praise.',
                        'Make a call. No hedging unless real uncertainty exists, and then name the specific thing that is uncertain instead of spraying qualifiers across the whole sentence.',
                        'Standalone. No references to LUNARA FILM review scores, prior verdicts, or review-side critical architecture.',
                    ),
                    'expertise_poison_phrases' => array(
                        'as we know',
                        'as you know',
                        'worth noting',
                        'notably,',
                        'recall that',
                        'it bears mentioning',
                        'for context,',
                        'just announced',
                        'fans will be',
                        'this trend signals',
                        'indicates a broader trend',
                        'reshaping the landscape',
                        'the real story is',
                        'the takeaway is simple',
                    ),
                    'structure' => array(
                        'One story per entry, fully explored: 300 to 700 words. The Eggers example runs past 700 because every paragraph brings a new specific; go past 700 only when yours does too.',
                        'Hook, one or two sentences: what happened, stated with the angle already built in. A claim, not a neutral headline. Never open with "according to" unless attribution is the only honest way in.',
                        'Context, one or two paragraphs: the history, the trajectory, the pattern this fits, said the way a fan who knows the history would say it, not the way a briefing would.',
                        'Specifics, one to three paragraphs: cast, filmmaker, dates, the concrete decisions, with commentary woven through every fact. Never a data dump.',
                        'The take, one paragraph: the read on the signal itself. What this tells you about the kind of movie they are trying to make and whether that is the right call.',
                        'The close, one or two sentences: a landing the reader remembers. A claim, a warning, a joke with teeth, or the specific thing to watch. Never a summary, never a hedged prediction.',
                        'The engagement question, always, as the final beat. See LANDING AND CLOSE.',
                    ),
                    'headline_rules' => array(
                        '4 to 14 words. It frames the read; it does not repeat the lede.',
                        'It should sound like a person with an opinion wrote it, not a feed parser and not a trade desk.',
                        'Never reuse a template across entries. "X Turns Y Into Z", "X Is Y, and That Means Z", and "Why X Matters" are house cliches. If the headline would still work with the names swapped, write a different one.',
                        'No colon-stacked subtitle unless the second half is the joke.',
                    ),
                    'contrast_examples' => array(
                        array( 'not_this' => 'The trailer suggests a tonal recalibration for the franchise.', 'this' => 'They finally figured out this thing is supposed to be fun.' ),
                        array( 'not_this' => 'Notably, this is Reeves\'s first project since The Batman, a film that grossed over $770 million worldwide and re-established the character\'s cinematic viability.', 'this' => 'Reeves coming off The Batman is the only reason any of this is interesting.' ),
                        array( 'not_this' => 'This could potentially redefine how ambitious sci-fi narratives are produced, signaling a shift in the industry towards technology-driven innovation in storytelling.', 'this' => 'Whether the screenplay earns that ambition is the only question left.' ),
                        array( 'not_this' => 'The phenomenon illustrates how personal nostalgia can trump critical sentiment, especially concerning cultural icons.', 'this' => 'Lionsgate made a jukebox movie and sold it as a biopic. The audience bought the jukebox. The critics reviewed the biopic.' ),
                        array( 'not_this' => 'It is worth noting that Megalopolis lost roughly $100 million of Coppola\'s own money, which makes this announcement particularly striking.', 'this' => 'Coppola lit $100 million of his own money on fire two years ago and is back asking us to bet on him again. I respect it.' ),
                        array( 'not_this' => 'Furthermore, the casting decision raises questions about Paramount\'s broader strategy. On the other hand, similar choices have paid off in recent years.', 'this' => 'It\'s a weird casting and it\'s also exactly the kind of weird casting Paramount keeps making work. We\'ll see.' ),
                        array( 'not_this' => 'How the festival balances these elements could define its impact within the film community for years to come.', 'this' => 'The saw is not the product. The filmmaker is.' ),
                        array( 'not_this' => 'That is not just another franchise rumor; it is a sign of who may be holding the wheel while the studio tries to bring the series back under control.', 'this' => 'Vin Diesel posting "Director... Hmmm..." the same week Universal asked him to find $150 million in cuts is not a tease. It\'s a ransom note.' ),
                        array( 'not_this' => 'The takeaway is simple: the talent is there, the opportunity is not.', 'this' => 'He did the hard part in a Nolan movie and the phone still isn\'t ringing. That\'s on the people who own the phones.' ),
                    ),
                    'drift_catalog' => array(
                        'Announcing significance ("this matters because", "the real story is", "the takeaway is simple") instead of building it into the framing. If the reader has to be told it matters, the angle was never framed.',
                        'The "not just X, it is Y" pivot as the reflex second sentence. It is a tell that the entry summarized first and decorated after.',
                        'Analyst distance: "signals", "control point", "leverage", "authorship" as the subject of the sentence with no human on the other side. Find the person who is thrilled, embarrassed, or cornered and write from there.',
                        'Hedging as tone: "could potentially", "may indicate", "might redefine". Pick one and make the call.',
                        'Essay register: "Furthermore", "Moreover", "On the other hand", sentences that announce what they are about to do, a closing paragraph that summarizes the previous two.',
                        'Paragraph one that restates the source and saves the opinion for paragraph three. Flip it: opinion first, facts in service of it.',
                        'A last sentence that is just another sentence. Every entry lands.',
                    ),
                    'engagement_close' => 'Every entry ends with one engagement question, after the landing sentence. It is always there, and it is the last thing the reader sees. It makes the reader pick a side on the specific tension the entry just laid out: tied to the angle, implying a right answer while leaving a genuinely interesting wrong one, and making the reader want to type a reply, not just nod. Not "What do you think about Avary\'s AI pivot?" but "Honest question: if the Paradise Lost footage actually looks incredible, does it matter how he got there?" Never a poll, never "what do you think?", never "let us know in the comments". If no real fork exists, the angle is not sharp enough yet: sharpen the angle, then ask.',
                    'exemplars' => Lunara_Journal_Voice_Exemplars::defaults(),
                ),
                'selection' => array(
                    'prefer_entries' => 1,
                    'max_entries'    => 1,
                    'minimum_words'  => 75,
                    'minimum_paragraphs' => 2,
                    'skip_marker'    => '<!-- LUNARA_SKIP: no reader-worthy items -->',
                    'skip_rules'     => array(
                        'Skip thin, generic, lightly sourced, purely promotional, routine quote, or headline-only items.',
                        'Skip anything that mainly says this happened.',
                        'Skip anything that mostly preserves the source angle, order, phrasing, or headline logic.',
                        'Prefer zero posts over polite, competent, forgettable posts.'
                    ),
                ),
                'formatting' => array(
                    'output_format'       => 'valid_html_only',
                    'entry_separator'     => '<hr>',
                    'entry_heading'       => 'h3',
                    'disallow_h2'         => true,
                    'film_title_element'  => 'em',
                    'disallow_inline_css' => true,
                    'disallow_markdown'   => true,
                    'ascii_only'          => true,
                ),
                'requirements' => array(
                    'featured_image'  => true,
                    'excerpt_or_deck'  => true,
                    'seo_description' => true,
                    'source_url'       => true,
                    'draft_only'       => true,
                    'human_publish'    => true,
                ),
            ),
            'dispatch' => array(
                'enabled'          => self::bool_from_legacy( $legacy, 'lunara_dispatch_enabled', false ),
                'schedule'         => self::string_from_legacy( $legacy, 'lunara_dispatch_schedule', 'daily' ),
                'target_post_type' => 'journal',
                'post_status'      => 'draft',
                'provider'         => self::string_from_legacy( $legacy, 'lunara_dispatch_provider', 'openai' ),
                'max_tokens'       => self::int_from_legacy( $legacy, 'lunara_dispatch_max_tokens', self::MAX_OUTPUT_TOKENS, 1024, self::MAX_OUTPUT_TOKENS ),
                'models'           => array(
                    'openai' => self::sanitize_openai_model( self::string_from_legacy( $legacy, 'lunara_dispatch_openai_model', self::DEFAULT_OPENAI_MODEL ) ),
                    'claude' => self::string_from_legacy( $legacy, 'lunara_dispatch_claude_model', self::DEFAULT_CLAUDE_MODEL ),
                    'gemini' => self::string_from_legacy( $legacy, 'lunara_dispatch_gemini_model', 'gemini-2.5-pro' ),
                    'grok'   => self::string_from_legacy( $legacy, 'lunara_dispatch_grok_model', 'grok-4' ),
                ),
            ),
            'sources' => self::normalize_sources( isset( $legacy['lunara_dispatch_sources'] ) && is_array( $legacy['lunara_dispatch_sources'] ) ? $legacy['lunara_dispatch_sources'] : array() ),
            'chatgpt' => array(
                'live_configuration_required' => true,
                'may_activate_configuration'  => false,
                'may_publish'                 => false,
                'may_delete'                  => false,
                'allowed_actions'             => array( 'read', 'update', 'validate', 'mark_ready', 'run_dispatch', 'publish', 'audit', 'schema' ),
            ),
            'notion' => array(
                'sync_enabled'     => false,
                'one_way_only'     => true,
                'blocks_activation'=> false,
            ),
            'workflow' => array(
                'states' => array(
                    'collected',
                    'selected',
                    'dispatch_generated',
                    'needs_chatgpt_review',
                    'ai_reviewed',
                    'validation_failed',
                    'ready_for_editor',
                    'published',
                    'held',
                    'rejected',
                ),
                'ready_state' => 'ready_for_editor',
            ),
        );
    }

    public static function sanitize_config( array $config ) {
        // Editable lists replace prior/default lists, including an intentionally empty list.
        // Recursive numeric merging would silently resurrect phrases the editor removed.
        $voice_lists = array();
        foreach ( self::VOICE_LISTS as $list ) {
            if ( isset( $config['editorial']['voice'][ $list ] ) && is_array( $config['editorial']['voice'][ $list ] ) ) {
                $voice_lists[ $list ] = array_values( $config['editorial']['voice'][ $list ] );
            }
        }
        $skip_rules = isset( $config['editorial']['selection']['skip_rules'] ) && is_array( $config['editorial']['selection']['skip_rules'] )
            ? array_values( $config['editorial']['selection']['skip_rules'] ) : null;
        $default = self::default_config();
        $config = self::deep_merge( $default, $config );
        foreach ( $voice_lists as $list => $values ) {
            $config['editorial']['voice'][ $list ] = $values;
        }
        $config['editorial']['voice']['exemplars'] = Lunara_Journal_Voice_Exemplars::sanitize( $config['editorial']['voice']['exemplars'] ?? array() );
        if ( null !== $skip_rules ) { $config['editorial']['selection']['skip_rules'] = $skip_rules; }

        $config['protocol_version'] = Lunara_Journal_Protocol::VERSION;
        $config['schema_version']   = Lunara_Journal_Protocol::SCHEMA_VERSION;
        $config['dispatch']['target_post_type'] = 'journal';
        $config['dispatch']['post_status']      = 'draft';
        $config['dispatch']['provider'] = self::sanitize_choice( $config['dispatch']['provider'], array( 'openai', 'claude', 'gemini', 'grok' ), 'openai' );
        $config['dispatch']['schedule'] = self::sanitize_choice( $config['dispatch']['schedule'], array( 'daily', 'twice_daily', 'every_4_hours', 'every_2_hours' ), 'daily' );
        $config['dispatch']['max_tokens'] = max( 1024, min( self::max_output_tokens_for( $config['dispatch']['provider'] ), (int) $config['dispatch']['max_tokens'] ) );
        if ( ! isset( $config['dispatch']['models'] ) || ! is_array( $config['dispatch']['models'] ) ) {
            $config['dispatch']['models'] = $default['dispatch']['models'];
        }
        $config['dispatch']['models']['openai'] = self::sanitize_openai_model( isset( $config['dispatch']['models']['openai'] ) ? $config['dispatch']['models']['openai'] : self::DEFAULT_OPENAI_MODEL );
        foreach ( array( 'claude', 'gemini', 'grok' ) as $provider ) {
            $config['dispatch']['models'][ $provider ] = self::sanitize_text( isset( $config['dispatch']['models'][ $provider ] ) ? $config['dispatch']['models'][ $provider ] : $default['dispatch']['models'][ $provider ] );
        }
        $config['dispatch']['enabled'] = ! empty( $config['dispatch']['enabled'] );
        $config['sources'] = self::normalize_sources( isset( $config['sources'] ) && is_array( $config['sources'] ) ? $config['sources'] : array() );
        $config['chatgpt']['live_configuration_required'] = true;
        $config['chatgpt']['may_activate_configuration'] = false;
        $config['chatgpt']['may_publish'] = ! empty( $config['chatgpt']['may_publish'] );
        $config['chatgpt']['may_delete'] = false;
        $config['chatgpt']['allowed_actions'] = array_values( array_unique( array_map( 'sanitize_key', isset( $config['chatgpt']['allowed_actions'] ) && is_array( $config['chatgpt']['allowed_actions'] ) ? $config['chatgpt']['allowed_actions'] : array() ) ) );
        $config['notion']['sync_enabled'] = ! empty( $config['notion']['sync_enabled'] );
        $config['notion']['one_way_only'] = true;
        $config['notion']['blocks_activation'] = false;
        return $config;
    }

    public static function validate_config( array $config ) {
        $errors = array();
        if ( empty( $config['editorial']['purpose'] ) ) {
            $errors[] = 'Editorial purpose is required.';
        }
        if ( 'journal' !== (string) $config['dispatch']['target_post_type'] ) {
            $errors[] = 'Dispatch target post type must be journal.';
        }
        if ( 'draft' !== (string) $config['dispatch']['post_status'] ) {
            $errors[] = 'Dispatch post status must be draft.';
        }
        if ( empty( $config['editorial']['formatting']['disallow_h2'] ) ) {
            $errors[] = 'Journal entries must disallow h2 headings.';
        }
        if ( ! isset( $config['sources'] ) || ! is_array( $config['sources'] ) ) {
            $errors[] = 'Sources must be submitted as rows.';
        } else {
            $source_validation = self::validate_sources( $config['sources'] );
            foreach ( $source_validation['errors'] as $source_error ) {
                $errors[] = 'Source row ' . ( (int) $source_error['row'] + 1 ) . ': ' . $source_error['message'];
            }
        }
        return array(
            'valid'  => empty( $errors ),
            'errors' => $errors,
        );
    }

    /**
     * Validate canonical source rows without silently repairing input.
     *
     * @param array $sources Candidate canonical rows.
     * @return array{valid:bool,errors:array<int,array{row:int,field:string,message:string}>}
     */
    public static function validate_sources( array $sources ) {
        $errors = array();
        $seen_ids = array();
        $seen_urls = array();
        $expected_keys = array( 'enabled', 'id', 'label', 'max', 'priority', 'url' );

        foreach ( $sources as $index => $row ) {
            $row_number = is_int( $index ) ? $index : count( $errors );
            if ( ! is_array( $row ) ) {
                $errors[] = self::source_error( $row_number, 'row', 'Each source must be a labeled row.' );
                continue;
            }

            $actual_keys = array_keys( $row );
            sort( $actual_keys );
            if ( $expected_keys !== $actual_keys ) {
                $errors[] = self::source_error( $row_number, 'row', 'Each source row must contain only ID, enabled, label, URL, max, and priority.' );
            }

            $id = isset( $row['id'] ) && is_scalar( $row['id'] ) ? trim( (string) $row['id'] ) : '';
            if ( '' === $id || $id !== self::sanitize_key( $id ) || ! preg_match( '/^[a-z0-9][a-z0-9_-]*$/D', $id ) ) {
                $errors[] = self::source_error( $row_number, 'id', 'Source ID is missing or invalid.' );
            } elseif ( isset( $seen_ids[ $id ] ) ) {
                $errors[] = self::source_error( $row_number, 'id', 'Source IDs must be unique.' );
            } else {
                $seen_ids[ $id ] = true;
            }

            if ( ! array_key_exists( 'enabled', $row ) || ! in_array( $row['enabled'], array( true, false, 1, 0, '1', '0' ), true ) ) {
                $errors[] = self::source_error( $row_number, 'enabled', 'Enabled must be on or off.' );
            }

            $label = isset( $row['label'] ) && is_scalar( $row['label'] ) ? self::sanitize_text( $row['label'] ) : '';
            if ( '' === $label ) {
                $errors[] = self::source_error( $row_number, 'label', 'Source name is required.' );
            }

            $url = isset( $row['url'] ) && is_scalar( $row['url'] ) ? self::normalize_source_url( $row['url'] ) : '';
            if ( '' === $url ) {
                $errors[] = self::source_error( $row_number, 'url', 'Use a complete HTTP or HTTPS URL without credentials or fragments.' );
            } elseif ( isset( $seen_urls[ $url ] ) ) {
                $errors[] = self::source_error( $row_number, 'url', 'Source URLs must be unique.' );
            } else {
                $seen_urls[ $url ] = true;
            }

            if ( ! isset( $row['max'] ) || ! self::strict_integer_in_range( $row['max'], 1, 50 ) ) {
                $errors[] = self::source_error( $row_number, 'max', 'Maximum items must be a whole number from 1 to 50.' );
            }
            if ( ! isset( $row['priority'] ) || ! self::strict_integer_in_range( $row['priority'], 1, 10 ) ) {
                $errors[] = self::source_error( $row_number, 'priority', 'Priority must be a whole number from 1 to 10.' );
            }
        }

        return array(
            'valid'  => empty( $errors ),
            'errors' => $errors,
        );
    }

    /**
     * Produce the stable URL used for storage and duplicate detection.
     *
     * @param mixed $value Candidate URL.
     * @return string
     */
    public static function normalize_source_url( $value ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        $value = trim( (string) $value );
        if ( '' === $value || 0 === strpos( $value, '//' ) || preg_match( '/[\x00-\x20\x7f<>"\'{}|^`]/', $value ) || false !== strpos( $value, '\\' ) ) {
            return '';
        }

        $parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $value ) : parse_url( $value );
        if ( ! is_array( $parts ) ) {
            return '';
        }
        $scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
        $raw_host = isset( $parts['host'] ) ? (string) $parts['host'] : '';
        $bracketed_ipv6 = strlen( $raw_host ) > 2 && '[' === substr( $raw_host, 0, 1 ) && ']' === substr( $raw_host, -1 );
        $host = strtolower( $bracketed_ipv6 ? substr( $raw_host, 1, -1 ) : rtrim( $raw_host, '.' ) );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
            return '';
        }
        if ( false !== strpos( $host, ':' ) ) {
            if ( ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
                return '';
            }
        } elseif ( preg_match( '/[^a-z0-9.\-]/i', $host ) ) {
            return '';
        }

        $has_port = isset( $parts['port'] );
        $port = $has_port ? (int) $parts['port'] : 0;
        if ( $has_port && ( $port < 1 || $port > 65535 ) ) {
            return '';
        }
        if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
            $port = 0;
        }

        $path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
        if ( '' === $path ) {
            $path = '/';
        }
        if ( '/' !== substr( $path, 0, 1 ) || false !== strpos( $path, '\\' ) ) {
            return '';
        }
        $query = isset( $parts['query'] ) && '' !== (string) $parts['query'] ? '?' . (string) $parts['query'] : '';
        $host_for_url = false !== strpos( $host, ':' ) ? '[' . $host . ']' : $host;
        $normalized = $scheme . '://' . $host_for_url . ( $port ? ':' . $port : '' ) . $path . $query;

        return function_exists( 'esc_url_raw' ) ? esc_url_raw( $normalized, array( 'http', 'https' ) ) : $normalized;
    }

    public static function normalize_sources( array $sources ) {
        $out = array();
        $seen = array();
        foreach ( $sources as $row ) {
            if ( ! is_array( $row ) || empty( $row['url'] ) ) {
                continue;
            }
            $url = self::normalize_source_url( $row['url'] );
            if ( '' === $url ) {
                continue;
            }
            $label = isset( $row['label'] ) ? (string) $row['label'] : (string) $row['url'];
            $id = isset( $row['id'] ) && '' !== (string) $row['id'] ? self::sanitize_key( $row['id'] ) : self::sanitize_key( $label );
            if ( '' === $id ) {
                $id = 'source';
            }
            $base = $id;
            $i = 2;
            while ( isset( $seen[ $id ] ) ) {
                $id = $base . '-' . $i;
                $i++;
            }
            $seen[ $id ] = true;
            $out[] = array(
                'id'       => $id,
                'label'    => self::sanitize_text( $label ),
                'url'      => $url,
                'enabled'  => ! empty( $row['enabled'] ),
                'max'      => isset( $row['max'] ) ? max( 1, min( 50, (int) $row['max'] ) ) : 10,
                'priority' => isset( $row['priority'] ) ? max( 1, min( 10, (int) $row['priority'] ) ) : 5,
            );
        }
        return $out;
    }

    private static function source_error( $row, $field, $message ) {
        return array(
            'row'     => (int) $row,
            'field'   => (string) $field,
            'message' => (string) $message,
        );
    }

    private static function strict_integer_in_range( $value, $minimum, $maximum ) {
        if ( is_int( $value ) ) {
            $integer = $value;
        } elseif ( is_string( $value ) && preg_match( '/^\d+$/D', $value ) ) {
            $integer = (int) $value;
        } else {
            return false;
        }
        return $integer >= $minimum && $integer <= $maximum;
    }

    public static function deep_merge( array $base, array $override ) {
        foreach ( $override as $key => $value ) {
            if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
                $base[ $key ] = self::deep_merge( $base[ $key ], $value );
            } else {
                $base[ $key ] = $value;
            }
        }
        return $base;
    }

    private static function string_from_legacy( array $legacy, $key, $default ) {
        if ( isset( $legacy[ $key ] ) && is_scalar( $legacy[ $key ] ) && '' !== trim( (string) $legacy[ $key ] ) ) {
            return self::sanitize_text( $legacy[ $key ] );
        }
        if ( function_exists( 'get_option' ) ) {
            $value = get_option( $key, $default );
            return is_scalar( $value ) && '' !== trim( (string) $value ) ? self::sanitize_text( $value ) : $default;
        }
        return $default;
    }

    private static function bool_from_legacy( array $legacy, $key, $default ) {
        if ( isset( $legacy[ $key ] ) ) {
            return ! empty( $legacy[ $key ] );
        }
        return function_exists( 'get_option' ) ? ! empty( get_option( $key, $default ? 1 : 0 ) ) : (bool) $default;
    }

    private static function int_from_legacy( array $legacy, $key, $default, $min, $max ) {
        $value = isset( $legacy[ $key ] ) ? $legacy[ $key ] : ( function_exists( 'get_option' ) ? get_option( $key, $default ) : $default );
        return max( $min, min( $max, (int) $value ) );
    }

    private static function sanitize_choice( $value, array $allowed, $default ) {
        $value = self::sanitize_key( $value );
        return in_array( $value, $allowed, true ) ? $value : $default;
    }

    private static function sanitize_openai_model( $value ) {
        $value = self::sanitize_text( $value );
        return in_array( $value, self::allowed_openai_models(), true ) ? $value : self::DEFAULT_OPENAI_MODEL;
    }

    private static function sanitize_key( $value ) {
        return function_exists( 'sanitize_key' ) ? sanitize_key( (string) $value ) : strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
    }

    private static function sanitize_text( $value ) {
        return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( strip_tags( (string) $value ) );
    }

    private static function sanitize_url( $value ) {
        return function_exists( 'esc_url_raw' ) ? esc_url_raw( (string) $value ) : trim( (string) $value );
    }
}

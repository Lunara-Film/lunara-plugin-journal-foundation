<?php
/**
 * Journal Control Tower — a standalone, always-live dashboard.
 *
 * Renders the journal pipeline state (draft/publish counts, publish cadence,
 * per-draft image/copy/validation signals) straight from the WordPress
 * database on every load. No artifact, no session, no external service.
 *
 * Two surfaces share one renderer:
 *   - wp-admin: a "Control Tower" menu page (cap edit_posts), shown in an
 *     isolated iframe so its CSS cannot collide with admin styles.
 *   - front end: a login-gated /control-tower page (rewrite + template_redirect).
 *
 * Reuses Lunara_Journal_Image_Guard for image verdicts; adds no dependencies.
 *
 * @package Lunara_Journal_Foundation
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Journal_Control_Tower {
    const PAGE           = 'lunara-control-tower';
    const QVAR           = 'lunara_control_tower';
    const ROUTE          = 'control-tower';
    const CAP            = 'edit_posts';
    const REWRITE_OPTION = 'lunara_ct_rewrites_v1';
    const SAMPLE         = 18;

    private static $booted = false;

    public static function bootstrap() {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;
        add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
        add_action( 'init', array( __CLASS__, 'register_route' ) );
        add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
        add_action( 'template_redirect', array( __CLASS__, 'handle_frontend' ) );
    }

    public static function register_route() {
        add_rewrite_rule( '^' . self::ROUTE . '/?$', 'index.php?' . self::QVAR . '=1', 'top' );
        if ( '1' !== get_option( self::REWRITE_OPTION ) ) {
            flush_rewrite_rules( false );
            update_option( self::REWRITE_OPTION, '1' );
        }
    }

    public static function add_query_var( $vars ) {
        $vars[] = self::QVAR;
        return $vars;
    }

    public static function register_admin_page() {
        add_menu_page(
            'Journal Control Tower',
            'Control Tower',
            self::CAP,
            self::PAGE,
            array( __CLASS__, 'render_admin' ),
            'dashicons-visibility',
            3
        );
    }

    public static function render_admin() {
        if ( ! current_user_can( self::CAP ) ) {
            return;
        }
        $doc = self::render_document( self::gather() );
        $url = home_url( '/' . self::ROUTE . '/' );
        echo '<div class="wrap" style="margin-right:20px">';
        echo '<h1 class="screen-reader-text">Journal Control Tower</h1>';
        echo '<p style="margin:8px 0 12px;color:#646970">Live from the database on load. Also available at <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a></p>';
        echo '<iframe title="Journal Control Tower" style="width:100%;min-height:calc(100vh - 170px);border:1px solid #dcdcde;border-radius:10px;background:#0b0d12" srcdoc="' . esc_attr( $doc ) . '"></iframe>';
        echo '</div>';
    }

    public static function handle_frontend() {
        if ( 1 !== (int) get_query_var( self::QVAR ) ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url( home_url( '/' . self::ROUTE . '/' ) ) );
            exit;
        }
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'You do not have access to the Journal Control Tower.', 'Forbidden', array( 'response' => 403 ) );
        }
        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );
        echo self::render_document( self::gather() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped within render_document().
        exit;
    }

    private static function field( $name, $post_id ) {
        if ( function_exists( 'get_field' ) ) {
            $v = get_field( $name, $post_id );
            if ( null !== $v && '' !== $v ) {
                return $v;
            }
        }
        return get_post_meta( $post_id, $name, true );
    }

    private static function copy_needs_cleanup( $content ) {
        if ( false !== strpos( (string) $content, '```' ) ) {
            return true;
        }
        $plain = ltrim( wp_strip_all_tags( (string) $content ) );
        if ( '' === $plain ) {
            return false;
        }
        $tells = array( 'Looking at these', "I'm evaluating", 'I will evaluate', "I'll evaluate", "I'll work through", 'Let me work through', 'Evaluating each', 'I need to', 'Here are the' );
        foreach ( $tells as $t ) {
            if ( 0 === stripos( $plain, $t ) ) {
                return true;
            }
        }
        return false;
    }

    private static function gather() {
        $counts  = wp_count_posts( 'journal' );
        $drafts  = isset( $counts->draft ) ? (int) $counts->draft : 0;
        $publish = isset( $counts->publish ) ? (int) $counts->publish : 0;
        $pending = isset( $counts->pending ) ? (int) $counts->pending : 0;

        $days = null; $last_title = ''; $last_date = '';
        $last = get_posts( array(
            'post_type'        => 'journal',
            'post_status'      => 'publish',
            'numberposts'      => 1,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'suppress_filters' => false,
        ) );
        if ( $last ) {
            $t          = (int) get_post_time( 'U', true, $last[0] );
            $days       = max( 0, (int) floor( ( time() - $t ) / DAY_IN_SECONDS ) );
            $last_title = get_the_title( $last[0] );
            $last_date  = get_post_time( 'Y-m-d', false, $last[0] );
        }

        $rows      = array();
        $img       = array( 'ok' => 0, 'warn' => 0, 'missing' => 0 );
        $cleanup   = 0;
        $validated = 0;
        $q = new WP_Query( array(
            'post_type'      => 'journal',
            'post_status'    => array( 'draft', 'pending' ),
            'posts_per_page' => self::SAMPLE,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ) );
        foreach ( $q->posts as $p ) {
            $guard   = class_exists( 'Lunara_Journal_Image_Guard' ) ? Lunara_Journal_Image_Guard::inspect( $p->ID ) : array();
            $istatus = isset( $guard['status'] ) ? (string) $guard['status'] : 'missing';
            $cat     = ( 'ready' === $istatus ) ? 'ok' : ( ( 'needs_attention' === $istatus ) ? 'warn' : 'missing' );
            $img[ $cat ]++;
            $needs = self::copy_needs_cleanup( $p->post_content );
            if ( $needs ) {
                $cleanup++;
            }
            $vstatus = (string) self::field( 'journal_validation_status', $p->ID );
            if ( 'passed' === $vstatus ) {
                $validated++;
            }
            $rows[] = array(
                'id'         => (int) $p->ID,
                'title'      => get_the_title( $p ),
                'img'        => $cat,
                'copy'       => $needs ? 'cleanup' : 'clean',
                'validation' => $vstatus ? $vstatus : 'unchecked',
                'modified'   => get_post_modified_time( 'Y-m-d H:i', true, $p ),
                'edit'       => admin_url( 'post.php?post=' . (int) $p->ID . '&action=edit' ),
                'view'       => get_permalink( $p->ID ),
            );
        }
        wp_reset_postdata();

        return array(
            'drafts'     => $drafts,
            'publish'    => $publish,
            'pending'    => $pending,
            'days'       => $days,
            'last_title' => $last_title,
            'last_date'  => $last_date,
            'rows'       => $rows,
            'img'        => $img,
            'cleanup'    => $cleanup,
            'validated'  => $validated,
            'generated'  => current_time( 'Y-m-d H:i' ) . ' ' . wp_timezone_string(),
        );
    }

    private static function render_document( array $d ) {
        $n        = count( $d['rows'] );
        $days_txt = ( null === $d['days'] ) ? '&mdash;' : ( 0 === $d['days'] ? 'today' : (int) $d['days'] . ( 1 === (int) $d['days'] ? ' day' : ' days' ) );
        $cleared  = (int) $d['validated'];

        // Verdict tiles.
        $tiles  = self::tile( 'crit', 'Drafts in queue', number_format_i18n( $d['drafts'] ), 'waiting on review' );
        $tiles .= self::tile( 'info', 'Published all-time', number_format_i18n( $d['publish'] ), 'live on /journal/' );
        $tiles .= self::tile( 'warn', 'Since last publish', $days_txt, $d['last_date'] ? 'last out: ' . esc_html( $d['last_date'] ) : 'no published entry found' );
        $tiles .= self::tile( $cleared > 0 ? 'good' : 'crit', 'Cleared to post', number_format_i18n( $cleared ), 'validated in the sample' );

        // Pipeline.
        $stages = array(
            array( 'Pulled', number_format_i18n( $d['drafts'] ), '' ),
            array( 'Image', 'gate', 'cur' ),
            array( 'Copy', 'gate', 'cur' ),
            array( 'Review', number_format_i18n( $d['drafts'] ), 'cur' ),
            array( 'Validate', number_format_i18n( $cleared ), '' ),
            array( 'Posted', number_format_i18n( $d['publish'] ), 'done' ),
        );
        $pipe = '';
        foreach ( $stages as $s ) {
            $pipe .= '<div class="stg ' . esc_attr( $s[2] ) . '"><div class="nm">' . esc_html( $s[0] ) . '</div><div class="ct">' . $s[1] . '</div></div>';
        }

        // Board.
        $cards = '';
        foreach ( $d['rows'] as $r ) {
            if ( 'ok' === $r['img'] ) {
                $ib = '<span class="b good">Image OK</span>';
            } elseif ( 'warn' === $r['img'] ) {
                $ib = '<span class="b warn">Image needs work</span>';
            } else {
                $ib = '<span class="b crit">No image</span>';
            }
            $cb = ( 'cleanup' === $r['copy'] ) ? '<span class="b warn">Copy cleanup</span>' : '<span class="b good">Copy clean</span>';
            $vb = ( 'passed' === $r['validation'] ) ? '<span class="b good">Validated</span>' : '<span class="b mut">not validated</span>';
            $cards .= '<div class="card"><div><h3 class="tl">' . esc_html( $r['title'] ) . '</h3>'
                . '<div class="meta">#' . (int) $r['id'] . ' &middot; modified ' . esc_html( $r['modified'] ) . ' UTC</div>'
                . '<div class="badges">' . $ib . $cb . $vb . '</div></div>'
                . '<div class="acts"><a class="act p" href="' . esc_url( $r['edit'] ) . '">Open in editor</a>'
                . '<a class="act" href="' . esc_url( $r['view'] ) . '" target="_blank" rel="noopener">Preview</a></div></div>';
        }
        if ( '' === $cards ) {
            $cards = '<p style="color:var(--muted)">No drafts in the queue right now.</p>';
        }

        // Diagnostics.
        $diag  = self::dcard( 'crit', $d['img']['missing'], 'No usable image (of ' . (int) $n . ' shown)' );
        $diag .= self::dcard( 'warn', $d['img']['warn'], 'Image needs attention' );
        $diag .= self::dcard( 'warn', $d['cleanup'], 'Copy needs cleanup' );
        $diag .= self::dcard( $cleared > 0 ? 'good' : 'crit', $cleared, 'Cleared to post' );

        $css = self::css();

        $html  = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>LUNARA FILM &middot; Journal Control Tower</title><style>' . $css . '</style></head><body>';
        $html .= '<header class="mast"><div class="wrap in"><div><div class="lu">LU<b>N</b>ARA</div><div class="sub">Journal Control Tower</div></div>'
            . '<div class="live"><div><span class="dot"></span>LIVE &middot; read from the database</div><div>' . esc_html( $d['generated'] ) . '</div><div>lunarafilm.com</div></div></div></header>';
        $html .= '<main class="wrap">';
        $html .= '<section><p class="eyebrow">The state of the queue</p><h2>Everything is pulling. What has posted?</h2><div class="tiles">' . $tiles . '</div></section>';
        $html .= '<section><p class="eyebrow">The line, end to end</p><h2>Pulled &rarr; Image &rarr; Copy &rarr; Review &rarr; Validate &rarr; Posted</h2><div class="pipe">' . $pipe . '</div></section>';
        $html .= '<section><p class="eyebrow">Triage &middot; freshest drafts</p><h2>What is on the desk right now</h2><div class="board">' . $cards . '</div></section>';
        $html .= '<section><p class="eyebrow">Why the queue won\'t move</p><h2>Blockers in the current sample</h2><div class="diag">' . $diag . '</div></section>';
        $html .= '</main>';
        $html .= '<footer><div class="wrap">This page reads your WordPress database live every time it loads &mdash; no snapshot, no refresh, no external service. Generated ' . esc_html( $d['generated'] ) . '. &middot; Lunara Film Journal Foundation.</div></footer>';
        $html .= '</body></html>';
        return $html;
    }

    private static function tile( $cls, $cap, $big, $foot ) {
        return '<div class="tile ' . esc_attr( $cls ) . '"><span class="st"></span><div class="cap">' . esc_html( $cap ) . '</div><div class="big num">' . $big . '</div><div class="foot">' . $foot . '</div></div>';
    }

    private static function dcard( $cls, $num, $label ) {
        return '<div class="dc ' . esc_attr( $cls ) . '"><div class="n num">' . number_format_i18n( (int) $num ) . '</div><div class="l">' . esc_html( $label ) . '</div></div>';
    }

    private static function css() {
        return ':root{--bg:#eef0f4;--panel:#fff;--panel2:#f6f7fa;--ink:#171a22;--muted:#565c6c;--faint:#868c9c;--line:#dde1e9;--accent:#2f6f9e;--accent-ink:#215272;--good:#2f8f5b;--warn:#b5761f;--crit:#c14f3a;--gold:#9c6f1c;--serif:"Iowan Old Style",Palatino,Georgia,serif;--sans:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;--mono:"SF Mono",ui-monospace,Menlo,Consolas,monospace}'
            . '@media(prefers-color-scheme:dark){:root{--bg:#0b0d12;--panel:#13161e;--panel2:#181c26;--ink:#e8eaf1;--muted:#9aa0b1;--faint:#6a7083;--line:#262b38;--accent:#8fb8d8;--accent-ink:#b8d4ea;--good:#5cbf86;--warn:#e0a24a;--crit:#e5745e;--gold:#e6b968}}'
            . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:var(--sans);font-size:15px;line-height:1.5;-webkit-font-smoothing:antialiased}'
            . '.wrap{max-width:1080px;margin:0 auto;padding:0 20px}a{color:var(--accent-ink);text-decoration:none}a:hover{text-decoration:underline}.num{font-variant-numeric:tabular-nums}'
            . '.mast{border-bottom:1px solid var(--line);background:var(--panel2)}.mast .in{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-end;gap:14px;padding:22px 20px}'
            . '.lu{font-family:var(--serif);font-size:24px;letter-spacing:.16em;text-transform:uppercase;font-weight:600}.lu b{color:var(--gold)}.sub{font-size:11px;letter-spacing:.3em;text-transform:uppercase;color:var(--faint);margin-top:4px}'
            . '.live{font-family:var(--mono);font-size:11px;color:var(--muted);text-align:right;line-height:1.7}.live .dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--good);margin-right:5px}'
            . 'section{padding:26px 0;border-top:1px solid var(--line)}section:first-of-type{border-top:0}'
            . '.eyebrow{font-size:11px;letter-spacing:.22em;text-transform:uppercase;color:var(--accent-ink);font-weight:700;margin:0 0 4px}h2{font-family:var(--serif);font-size:21px;margin:0 0 15px;font-weight:600}'
            . '.tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.tile{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:15px;position:relative;overflow:hidden}'
            . '.tile .st{position:absolute;left:0;top:0;bottom:0;width:4px}.tile .cap{font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--faint);font-weight:700}'
            . '.tile .big{font-family:var(--serif);font-size:38px;line-height:1;margin:10px 0 7px;font-variant-numeric:tabular-nums}.tile .foot{font-size:11.5px;color:var(--muted)}'
            . '.tile.crit .big{color:var(--crit)}.tile.crit .st{background:var(--crit)}.tile.warn .big{color:var(--warn)}.tile.warn .st{background:var(--warn)}.tile.good .big{color:var(--good)}.tile.good .st{background:var(--good)}.tile.info .st{background:var(--accent)}'
            . '.pipe{display:flex;overflow-x:auto;background:var(--panel);border:1px solid var(--line);border-radius:12px}.stg{flex:1 1 0;min-width:108px;padding:14px 10px;text-align:center}.stg+.stg{border-left:1px dashed var(--line)}'
            . '.stg .nm{font-size:12px;font-weight:700}.stg .ct{font-family:var(--mono);font-size:12px;font-weight:600;margin-top:5px}.stg.cur{background:color-mix(in srgb,var(--warn) 10%,transparent)}.stg.cur .nm{color:var(--warn)}.stg.done .nm{color:var(--good)}'
            . '.board{display:flex;flex-direction:column;gap:9px}.card{background:var(--panel);border:1px solid var(--line);border-radius:11px;padding:12px 14px;display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap}'
            . '.card .tl{font-family:var(--serif);font-size:15.5px;margin:0 0 5px;line-height:1.3}.card .meta{font-family:var(--mono);font-size:11px;color:var(--faint)}'
            . '.badges{display:flex;flex-wrap:wrap;gap:5px;margin-top:8px}.b{font-size:10.5px;font-weight:700;padding:3px 8px;border-radius:6px;border:1px solid transparent}'
            . '.b.good{color:var(--good);background:color-mix(in srgb,var(--good) 13%,transparent);border-color:color-mix(in srgb,var(--good) 30%,transparent)}'
            . '.b.warn{color:var(--warn);background:color-mix(in srgb,var(--warn) 14%,transparent);border-color:color-mix(in srgb,var(--warn) 30%,transparent)}'
            . '.b.crit{color:var(--crit);background:color-mix(in srgb,var(--crit) 13%,transparent);border-color:color-mix(in srgb,var(--crit) 32%,transparent)}'
            . '.b.mut{color:var(--muted);background:var(--panel2);border-color:var(--line)}'
            . '.acts{display:flex;gap:7px;flex-shrink:0}.act{font-size:11px;font-weight:600;padding:5px 10px;border-radius:7px;border:1px solid var(--line);background:var(--panel2);color:var(--accent-ink)}.act.p{background:var(--accent);color:#fff;border-color:var(--accent)}'
            . '.diag{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.dc{background:var(--panel);border:1px solid var(--line);border-radius:11px;padding:14px}.dc .n{font-family:var(--serif);font-size:30px;font-variant-numeric:tabular-nums}.dc.crit .n{color:var(--crit)}.dc.warn .n{color:var(--warn)}.dc.good .n{color:var(--good)}.dc .l{font-size:12px;color:var(--muted);margin-top:3px}'
            . 'footer{border-top:1px solid var(--line);background:var(--panel2);padding:20px 0 28px;font-size:12px;color:var(--muted)}'
            . '@media(max-width:820px){.tiles,.diag{grid-template-columns:repeat(2,1fr)}}@media(max-width:560px){.tiles,.diag{grid-template-columns:1fr}}';
    }
}

Lunara_Journal_Control_Tower::bootstrap();

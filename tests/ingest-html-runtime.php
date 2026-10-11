<?php
/**
 * Exercise real DOM parsing, semantic preservation and the KSES policy boundary.
 * WordPress KSES itself is not reimplemented by this dependency-free fixture.
 */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code;
    public function __construct( $code, $message = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_kses_allowed_html( $context ) {
    if ( 'post' !== $context ) { throw new RuntimeException( 'Wrong KSES context.' ); }
    return array(
        'p' => array( 'class' => true, 'style' => true, 'id' => true, 'data-*' => true ),
        'img' => array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'class' => true, 'style' => true ),
        'code' => array( 'class' => true, 'style' => true, 'data-*' => true ),
        'a' => array( 'href' => true, 'id' => true, 'rel' => true, 'class' => true, 'style' => true ),
        'span' => array( 'lang' => true, 'dir' => true, 'class' => true, 'style' => true ),
    );
}
function wp_kses( $html, $allowed ) {
    foreach ( $allowed as $attributes ) {
        if ( isset( $attributes['style'] ) || isset( $attributes['class'] ) ) {
            throw new RuntimeException( 'Presentation attributes reached the KSES allowlist.' );
        }
    }
    if ( empty( $allowed['img']['alt'] ) || empty( $allowed['p']['data-*'] ) || empty( $allowed['a']['id'] ) ) {
        throw new RuntimeException( 'Semantic KSES attributes were removed.' );
    }
    return $html;
}
require dirname( __DIR__ ) . '/includes/class-lunara-journal-ingest-html.php';
if ( ! class_exists( 'DOMDocument' ) ) { throw new RuntimeException( 'DOM is required for this behavioral fixture.' ); }
function html_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function html_document( $html ) {
    $document = new DOMDocument( '1.0', 'UTF-8' );
    $previous = libxml_use_internal_errors( true );
    $document->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET );
    libxml_clear_errors();
    libxml_use_internal_errors( $previous );
    return $document;
}
$input = <<<'HTML'
<div class=builder style='color:red'><p ID="opening" data-source-id="news-7" style="color:gold" class='wp-block-paragraph'>Écho &amp; “criticism” <span class="nested"><span style='font-size:8px'>remains</span></span> <em lang="fr">cinéma</em>.</p>
<p><span lang="ar" dir="rtl" class="builder">فيلم</span> <a href="https://example.com/a?q=1&amp;x=2" id="source" rel="noopener" class='button'>The source</a><br>continues.</p>
<figure class="wp-block-image"><img src="https://example.com/still.jpg" width="1600" height="900" alt="A film still"><figcaption>Photo credit.</figcaption></figure>
<ol start="3"><li>First point</li><li>Second point</li></ol><blockquote cite="https://example.com/quote"><p>A quotation.</p></blockquote>
<table><caption>Release dates</caption><thead><tr><th scope="col">Film</th><th scope="col">Year</th></tr></thead><tbody><tr><td>Title</td><td>2026</td></tr></tbody></table>
<pre><code data-language="html">&lt;script&gt;Literal code, not executable&lt;/script&gt;</code></pre></div>
HTML;
$clean = Lunara_Journal_Ingest_HTML::sanitize( $input );
html_assert( is_string( $clean ), 'Semantic article was rejected.' );
$before = html_document( $input );
$after = html_document( $clean );
html_assert( $before->getElementsByTagName( 'body' )->item( 0 )->textContent === $after->getElementsByTagName( 'body' )->item( 0 )->textContent, 'Article words, punctuation or code changed.' );
foreach ( $after->getElementsByTagName( '*' ) as $element ) {
    html_assert( ! $element->hasAttribute( 'class' ) && ! $element->hasAttribute( 'style' ), 'A quoted/unquoted presentation attribute survived.' );
}
foreach ( array( 'img', 'figure', 'figcaption', 'ol', 'li', 'blockquote', 'table', 'caption', 'th', 'td', 'pre', 'code', 'em', 'br' ) as $tag ) {
    html_assert( $before->getElementsByTagName( $tag )->length === $after->getElementsByTagName( $tag )->length, 'Semantic element lost: ' . $tag );
}
html_assert( 'news-7' === $after->getElementById( 'opening' )->getAttribute( 'data-source-id' ), 'Source data attribute lost.' );
html_assert( 'https://example.com/a?q=1&x=2' === $after->getElementById( 'source' )->getAttribute( 'href' ), 'Source destination changed.' );
html_assert( 'A film still' === $after->getElementsByTagName( 'img' )->item( 0 )->getAttribute( 'alt' ), 'Image alternative lost.' );
html_assert( 'html' === $after->getElementsByTagName( 'code' )->item( 0 )->getAttribute( 'data-language' ), 'Code language metadata lost.' );
html_assert( $clean === Lunara_Journal_Ingest_HTML::sanitize( $clean ), 'Repeated sanitization changes content.' );

$blocked = array(
    '<p>Before</p><script>alert(1)</script>',
    '<SCRIPT SRC="https://example.com/a.js"></SCRIPT>',
    '<script>unfinished',
    '<img src="https://example.com/a.jpg" onerror="alert(1)">',
    '<a href="java&#x73;cript:alert(1)">source</a>',
    "<a href='java\nscript:alert(1)'>source</a>",
    '<img src="data:image/svg+xml,evil">',
    '<iframe src="https://example.com" srcdoc="bad"></iframe>',
    '<object data="https://example.com"></object>',
    '<svg><script>alert(1)</script></svg>',
    '<style>body{display:none}</style>',
    '<form action="https://example.com"><input name="x"></form>',
    '<meta http-equiv="refresh" content="0;url=https://example.com">',
);
foreach ( $blocked as $unsafe ) {
    html_assert( is_wp_error( Lunara_Journal_Ingest_HTML::sanitize( $unsafe ) ), 'Executable content was silently accepted: ' . $unsafe );
}
html_assert( '' === Lunara_Journal_Ingest_HTML::sanitize( '' ), 'Empty input should remain empty.' );
echo "Journal ingestion HTML: semantic article retained, presentation removed, unsafe input rejected, repeat stable.\n";

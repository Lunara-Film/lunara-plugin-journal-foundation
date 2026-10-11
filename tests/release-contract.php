<?php
/**
 * Executable stabilization contracts for Journal Foundation 1.4.0.
 *
 * Run: php tests/release-contract.php
 */

$root = dirname( __DIR__ );
$failures = array();
$passes = 0;

function contract_file( $root, $path ) {
    $contents = file_get_contents( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $path ) );
    if ( false === $contents ) {
        throw new RuntimeException( 'Unable to read ' . $path );
    }
    return $contents;
}

function contract_assert( $condition, $message ) {
    global $failures, $passes;
    if ( $condition ) {
        $passes++;
        return;
    }
    $failures[] = $message;
}

function contract_contains( $haystack, $needle, $message ) {
    contract_assert( false !== strpos( $haystack, $needle ), $message );
}

function contract_not_contains( $haystack, $needle, $message ) {
    contract_assert( false === strpos( $haystack, $needle ), $message );
}

function contract_deployignore_matches( $rules, $path ) {
    $path = ltrim( str_replace( '\\', '/', (string) $path ), '/' );
    $ignored = false;
    foreach ( preg_split( '/\R/', (string) $rules ) as $line ) {
        $line = trim( $line );
        if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
            continue;
        }
        $negated = '!' === substr( $line, 0, 1 );
        if ( $negated ) {
            $line = substr( $line, 1 );
        }
        $line = ltrim( $line, '/' );
        $directory = '/' === substr( $line, -1 );
        $line = rtrim( $line, '/' );
        if ( '' === $line ) {
            continue;
        }
        $has_slash = false !== strpos( $line, '/' );
        $pattern = preg_quote( $line, '#' );
        $pattern = str_replace( '\*\*/', '(?:.*/)?', $pattern );
        $pattern = str_replace( '\*\*', '.*', $pattern );
        $pattern = str_replace( '\*', '[^/]*', $pattern );
        $pattern = str_replace( '\?', '[^/]', $pattern );
        if ( $has_slash ) {
            $pattern = '#^' . $pattern . ( $directory ? '(?:/.*)?' : '' ) . '$#';
        } else {
            $pattern = '#(?:^|/)' . $pattern . '(?:$|/)#';
        }
        if ( preg_match( $pattern, $path ) ) {
            $ignored = ! $negated;
        }
    }
    return $ignored;
}

$main = contract_file( $root, 'lunara-journal-foundation.php' );
$schema = contract_file( $root, 'includes/class-lunara-journal-config-schema.php' );
$protocol = contract_file( $root, 'includes/class-lunara-journal-protocol.php' );
$repository = contract_file( $root, 'includes/class-lunara-journal-config-repository.php' );
$fast_desk = contract_file( $root, 'includes/class-lunara-journal-fast-desk.php' );
$automation = contract_file( $root, 'includes/class-lunara-journal-automation.php' );
$control_plane = contract_file( $root, 'includes/class-lunara-journal-control-plane.php' );
$site_studio = contract_file( $root, 'includes/class-lunara-journal-site-studio.php' );
$control_plane_js = contract_file( $root, 'assets/admin/control-plane.js' );
$control_plane_css = contract_file( $root, 'assets/admin/control-plane.css' );
$deployignore = contract_file( $root, '.deployignore' );
$ci_workflow = contract_file( $root, '.github/workflows/lint.yml' );
$wp_behavior_contract = contract_file( $root, 'tests/wp-behavior-contract.php' );
$notion_client = contract_file( $root, 'includes/class-lunara-journal-notion-client.php' );
$provenance = contract_file( $root, 'includes/class-lunara-journal-provenance.php' );
$ingest = contract_file( $root, 'includes/class-lunara-journal-ingest.php' );
$image_sideload = contract_file( $root, 'includes/class-lunara-journal-image-sideload.php' );
$readme = contract_file( $root, 'README.md' );
$production_openapi = contract_file( $root, 'openapi/lunara-journal-fast-desk.openapi.json' );
$bridge_openapi = contract_file( $root, 'openapi/lunara-journal-bridge.openapi.json' );
$staging_openapi = contract_file( $root, 'openapi/lunara-journal-fast-desk.staging.openapi.json' );

// Release identity is exercised through the real bootstrap in wp-behavior-contract.php.
// Documentation and API schema versions are not package release pins.
contract_contains( $readme, 'Authorization: Bearer', 'README must document Bearer authentication for ChatGPT Actions.' );

// Source rows are labeled, strict, retained per user on rejection, and save
// only through the immutable repository after validation.
contract_not_contains( $control_plane, 'sources_json', 'The raw source JSON editor must not remain.' );
foreach ( array( 'Source name', 'HTTP(S) URL', 'Maximum items', 'Priority', 'Add source', 'Remove source', 'Permanent ID' ) as $label ) {
    contract_contains( $control_plane, $label, 'Missing recognizable source-row control: ' . $label . '.' );
}
contract_contains( $control_plane, 'prepare_source_submission', 'Source submissions must use the strict row parser.' );
contract_contains( $control_plane, 'retain_source_stage', 'Rejected source rows must retain a user-bound stage.' );
contract_contains( $control_plane, 'consume_source_stage', 'Retained source rows must be one-read.' );
contract_contains( $control_plane, "const SOURCE_STAGE_TTL = 600;", 'Rejected source stages must expire after ten minutes.' );
contract_contains( $control_plane, "'lunara_journal_control_plane_source_stage_' . \$user_id", 'Source stages must be bound to the submitting WordPress user.' );
contract_contains( $control_plane, "check_admin_referer( 'lunara_journal_control_plane_save' )", 'Control Plane saves must retain nonce enforcement.' );
$admin_save_start = strpos( $control_plane, 'public static function admin_save()' );
$admin_save_end = strpos( $control_plane, 'private static function save_admin_submission', $admin_save_start );
$admin_save = substr( $control_plane, $admin_save_start, $admin_save_end - $admin_save_start );
$save_capability = strpos( $admin_save, 'current_user_can( self::CAPABILITY )' );
$save_nonce = strpos( $admin_save, "check_admin_referer( 'lunara_journal_control_plane_save' )" );
$save_delegate = strpos( $admin_save, 'self::save_admin_submission' );
contract_assert( false !== $save_capability && false !== $save_nonce && false !== $save_delegate && $save_capability < $save_nonce && $save_nonce < $save_delegate, 'Capability then nonce must guard the Control Plane save before any candidate processing.' );
$submission_start = strpos( $control_plane, 'private static function save_admin_submission' );
$submission_end = strpos( $control_plane, 'private static function prepare_source_submission', $submission_start );
$submission = substr( $control_plane, $submission_start, $submission_end - $submission_start );
$repository_save = strpos( $submission, 'Lunara_Journal_Config_Repository::create_and_activate' );
$notion_page_save = strpos( $submission, 'Lunara_Journal_Notion_Client::OPTION_PAGE_ID' );
$notion_token_save = strpos( $submission, 'Lunara_Journal_Notion_Client::OPTION_TOKEN' );
contract_assert( false !== $repository_save && false !== $notion_page_save && false !== $notion_token_save && $repository_save < $notion_page_save && $repository_save < $notion_token_save, 'Notion settings must remain untouched until immutable configuration activation succeeds.' );
contract_contains( $repository, 'private static function create_version', 'Low-level version storage must not remain a public bypass.' );
contract_contains( $repository, 'private static function activate_version', 'Low-level activation must not remain a public bypass.' );
contract_contains( $repository, "\$validation = Lunara_Journal_Config_Schema::validate_config( \$config );", 'The immutable path must validate before storage.' );
contract_contains( $repository, "self::create_and_activate( \$config, 'Initial active Control Plane version.', 'system' )", 'Default bootstrap must use the immutable activation path.' );
contract_contains( $control_plane_js, 'window.confirm', 'Source removal and rollback must use recognizable confirmation.' );
contract_contains( $control_plane, "'confirm_rollback'", 'Rollback must require a server-verified confirmation field.' );
contract_contains( $control_plane, "'1' !== (string) wp_unslash( \$_POST['confirm_rollback'] )", 'Rollback must reject a missing or forged confirmation value.' );
contract_contains( $control_plane, 'name="confirm_rollback" value="0"', 'Rollback confirmation must default to the safe non-confirmed value.' );
contract_not_contains( $control_plane, 'name="confirm_rollback" value="1"', 'Initial rollback HTML must never arrive pre-confirmed.' );
contract_contains( $control_plane_js, "confirmation.value = '1'", 'Only a positive client confirmation may arm the server-verified rollback field.' );
contract_contains( $control_plane_css, '@media (max-width: 782px)', 'Labeled rows must stack at the WordPress mobile breakpoint.' );

foreach ( array( 'control-plane-sources-runtime.php', 'site-studio-workflow-runtime.php', 'hub-telemetry-runtime.php' ) as $ci_runtime ) {
    contract_contains( $ci_workflow, $ci_runtime, 'CI must execute ' . $ci_runtime . '.' );
}
contract_contains( $ci_workflow, "php: ['7.4', '8.2', '8.3']", 'CI must execute the advertised PHP 7.4 minimum alongside current PHP versions.' );
contract_not_contains( $wp_behavior_contract, ': mixed', 'Runtime contracts must remain parseable on the advertised PHP 7.4 minimum.' );

// Foundation contributes a safe handoff without depending on or writing into
// the theme. The generic registry remains the only integration boundary.
contract_contains( $main, "require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-site-studio.php'", 'Foundation must always load its inert Site Studio contribution.' );
contract_contains( $main, 'Lunara_Journal_Site_Studio::bootstrap();', 'Foundation must register the optional registry filter.' );
contract_contains( $site_studio, "add_filter( 'lunara_site_studio_surfaces'", 'Foundation must use the public Site Studio registry filter.' );
contract_contains( $site_studio, "const SURFACE_ID = 'journal-workflow';", 'The canonical workflow surface ID must remain stable.' );
contract_contains( $site_studio, "'owner'                 => 'plugin:lunara-journal-foundation'", 'Foundation must retain canonical workflow ownership.' );
contract_contains( $site_studio, "'capability'            => Lunara_Journal_Control_Plane::CAPABILITY", 'The workflow handoff must retain manage-options capability ownership.' );
contract_contains( $site_studio, 'lunara_journal_foundation_workflow_status', 'Foundation must expose a stable redacted status helper.' );
foreach ( array( 'get_dispatch_runtime_config', 'rest_active_config', 'rest_compiled_config', 'rest_health', 'public_config', 'Lunara_Journal_Notion_Client', 'OPTION_ACCESS_PROFILES' ) as $forbidden_status_source ) {
    contract_not_contains( $site_studio, $forbidden_status_source, 'Site Studio status must not read the private/full configuration source ' . $forbidden_status_source . '.' );
}
contract_not_contains( $site_studio, '::get_active_config(', 'Site Studio status must not call the bootstrapping active-config read.' );
contract_not_contains( $site_studio, '::get_active_version(', 'Site Studio status must not call the bootstrapping active-version read.' );

// Deployment excludes repository-only material but keeps every production
// module and the new scoped UI assets.
foreach ( array( '.deployignore', '.github', '.github/**', 'README.md', 'docs', 'docs/**', 'tests', 'tests/**', 'vendor', 'vendor/**', '.env', '.env.*' ) as $excluded ) {
    contract_contains( $deployignore, $excluded, '.deployignore must exclude ' . $excluded . '.' );
}
foreach ( array( 'lunara-journal-foundation.php', 'includes', 'assets', 'openapi' ) as $production_path ) {
    contract_assert( ! preg_match( '/^' . preg_quote( $production_path, '/' ) . '(?:\/\*\*)?$/m', $deployignore ), '.deployignore must not exclude production path ' . $production_path . '.' );
}
$production_files = array( 'lunara-journal-foundation.php' );
foreach ( array( 'includes', 'assets', 'openapi' ) as $production_directory ) {
    $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . DIRECTORY_SEPARATOR . $production_directory, FilesystemIterator::SKIP_DOTS ) );
    foreach ( $iterator as $file ) {
        if ( $file->isFile() ) {
            $production_files[] = str_replace( DIRECTORY_SEPARATOR, '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
        }
    }
}
sort( $production_files );
foreach ( $production_files as $production_file ) {
    contract_assert( ! contract_deployignore_matches( $deployignore, $production_file ), '.deployignore semantics must retain production artifact ' . $production_file . '.' );
}
foreach ( array( '.deployignore', '.github/workflows/lint.yml', 'README.md', 'docs/placeholder.md', 'tests/release-contract.php', 'vendor/autoload.php', '.env' ) as $repository_only_file ) {
    contract_assert( contract_deployignore_matches( $deployignore, $repository_only_file ), '.deployignore semantics must exclude repository-only artifact ' . $repository_only_file . '.' );
}
contract_contains( $schema, "const DEFAULT_OPENAI_MODEL = 'gpt-5.4-mini';", 'The Control Plane must default to the cost-safe OpenAI model.' );
contract_contains( $schema, "array( 'gpt-5.4-mini', 'gpt-5.4-nano' )", 'The Control Plane must use the Dispatch 3.2.5 OpenAI allowlist.' );
contract_contains( $schema, 'const MAX_OUTPUT_TOKENS    = 2200;', 'The Control Plane must share the 2,200-token output cap.' );
contract_not_contains( $schema, "'gpt-4o'", 'Stale OpenAI defaults must not remain in the active schema.' );
contract_contains( $control_plane, 'Lunara_Journal_Config_Schema::MAX_OUTPUT_TOKENS', 'Control Plane runtime and form defaults must use the safe token cap.' );
contract_contains( $control_plane, "'gpt-5.4-mini' => 'GPT-5.4 mini'", 'Control Plane UI must expose the safe OpenAI allowlist.' );

foreach ( array( 'Fast Desk' => $fast_desk, 'Automation' => $automation ) as $surface => $source ) {
    foreach ( array( 'runtime', 'source_budget', 'requested_model', 'effective_model', 'usage_reported', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'estimated_cost_usd', 'fallback_used', 'error_code', 'processed_source_items', 'deferred_source_items', 'source_radar_items', 'source_packet_drafts' ) as $field ) {
        contract_contains( $source, "'" . $field . "'", $surface . ' must expose safe Dispatch telemetry field ' . $field . '.' );
    }
    contract_not_contains( $source, "['response_id']", $surface . ' must not expose provider response identifiers.' );
    contract_not_contains( $source, "['api_key']", $surface . ' must not expose provider credentials.' );
}
contract_contains( $fast_desk, "'generation'        => array(", 'Fast Desk draft summaries must expose bounded generation state.' );
contract_contains( $fast_desk, "'source_packet' => \$source_packet", 'Fast Desk draft summaries must identify source-packet fallbacks.' );
contract_contains( $ingest, 'normalize_source_published_at', 'Draft ingest must normalize transport publication dates before ACF readback.' );
contract_contains( $ingest, 'normalize_content_paragraphs', 'Draft ingest must add editable paragraph structure to long unstructured content.' );
contract_contains( $image_sideload, 'PREFERRED_REMOTE_WIDTH = 1920', 'Journal image ingest must request the preferred wide editorial source width.' );
contract_contains( $image_sideload, 'attachment_meets_preferred_quality', 'Low-resolution existing source attachments must not block a quality upgrade.' );
contract_contains( $image_sideload, "'download_url'", 'Image sideload results must expose the actual download URL for auditability.' );
// Protocol/schema stay pinned until the wire contract changes.
contract_contains( $protocol, "const VERSION        = '1.2.2';", 'Protocol version must be 1.2.2.' );
contract_contains( $protocol, "const SCHEMA_VERSION = '1.2.2';", 'Schema version must be 1.2.2.' );

// The theme relies on a real Journal archive and the legacy taxonomy remains queryable.
contract_contains( $main, "'has_archive'         => 'journal'", 'Journal CPT must own the /journal archive.' );
contract_contains( $main, "const TAX_LEGACY_TYPE     = 'journal_type';", 'Legacy journal_type taxonomy compatibility must remain.' );
contract_contains( $main, 'register_taxonomy_for_object_type( self::TAX_LEGACY_TYPE, self::POST_TYPE )', 'Existing journal_type taxonomy must attach safely.' );
contract_contains( $main, 'wp_set_object_terms( $post_id, array( $legacy_type_id ), self::TAX_LEGACY_TYPE, false )', 'New Journal drafts must mirror their section into journal_type compatibility terms.' );

// WordPress sessions receive exact operation scope and post/capability checks.
contract_contains( $main, "'scopes'   => array( \$required_scope )", 'WordPress sessions must receive only the required scope.' );
contract_contains( $main, "current_user_can( 'edit_post', \$post_id )", 'Post-specific edit capability check is required.' );
contract_contains( $main, "current_user_can( 'publish_posts' )", 'Publish operations require publish capability.' );
contract_contains( $main, "current_user_can( 'manage_options' )", 'Operational routes require administrator capability.' );
contract_not_contains( $main, "get_param( 'bridge_token' )", 'Query-string bridge tokens must be rejected.' );
contract_not_contains( $main, 'OPTION_TOKEN', 'Retired plaintext legacy token storage must not remain.' );
contract_contains( $fast_desk, "current_user_can( 'edit_post', \$post->ID )", 'Publish callback must enforce edit capability in depth.' );
contract_contains( $fast_desk, "current_user_can( 'publish_posts' )", 'Publish callback must enforce publish capability in depth.' );
$publish_start = strpos( $fast_desk, 'public static function rest_publish_draft' );
$publish_end = strpos( $fast_desk, 'public static function rest_run_dispatch', $publish_start );
$publish = substr( $fast_desk, $publish_start, $publish_end - $publish_start );
$publish_update = strpos( $publish, 'wp_update_post' );
$publish_readback = strpos( $publish, "'publish' !== get_post_status( \$published )" );
$publish_provenance = strpos( $publish, "'_lunara_journal_published_at_gmt'" );
contract_assert( false !== $publish_update && false !== $publish_readback && false !== $publish_provenance, 'Publish update, status readback, and provenance statements must exist.' );
contract_assert( $publish_readback > $publish_update && $publish_provenance > $publish_readback, 'Published provenance must follow an exact publish-status readback.' );
contract_contains( $publish, 'lunara_publish_readback_failed', 'Publish must fail closed when WordPress does not persist publish status.' );

// Automation and GPT publishing are fail-closed until an administrator opts in.
contract_contains( $main, "add_option( self::OPTION_AUTO_CONVERT, '0'", 'Auto-conversion must default off.' );
contract_contains( $main, "add_option( self::OPTION_CONVERT_MODE, 'off'", 'Conversion mode must default off.' );
contract_contains( $main, 'ensure_stabilized_defaults', 'Upgrade path must apply fail-closed stabilization once per release.' );
contract_contains( $main, "update_option( self::OPTION_AUTO_CONVERT, '0'", 'Upgrade path must disable inherited auto-conversion.' );
contract_contains( $schema, "'may_publish'                 => false", 'GPT publishing must default off.' );
$activation_start = strpos( $main, 'public static function activate()' );
$activation_end = strpos( $main, 'public static function deactivate()', $activation_start );
$activation = substr( $main, $activation_start, $activation_end - $activation_start );
contract_not_contains( $activation, 'wp_schedule_event', 'Activation must not schedule a content-conversion cron.' );
contract_not_contains( $activation, 'convert_dispatch_post_to_journal', 'Activation must not mutate content.' );
if ( preg_match( "/'chatgpt_editor'\s*=>\s*array\((.*?)\R\s*\),\R\s*'dispatch_ingest'/s", $main, $chatgpt_profile ) ) {
    contract_not_contains( $chatgpt_profile[1], "'publish'", 'Default ChatGPT editor profile must not include publish scope.' );
} else {
    contract_assert( false, 'Unable to locate default ChatGPT editor profile.' );
}
contract_not_contains( $main, "array_diff( \$merged_scopes, array( 'publish' ) )", 'An explicitly granted ChatGPT publish scope must survive profile reconciliation.' );

// WordPress 7/PHP 8 passes three arguments to REST validators; native
// one-argument callbacks would fatal before any draft handler can run.
contract_not_contains( $main, "'validate_callback' => 'is_numeric'", 'Foundation REST IDs must not use the one-argument native is_numeric callback.' );
contract_not_contains( $fast_desk, "'validate_callback' => 'is_numeric'", 'Fast Desk REST IDs must not use the one-argument native is_numeric callback.' );
contract_contains( $main, 'public static function rest_validate_positive_id', 'Foundation must expose the WordPress-compatible positive ID validator.' );
contract_contains( $fast_desk, "array( 'Lunara_Journal_Foundation', 'rest_validate_positive_id' )", 'Fast Desk must use the shared WordPress-compatible ID validator.' );

// Complete inventory access stays bounded through search and pagination.
contract_contains( $fast_desk, "'paged'          => \$page", 'Fast Desk query must support one-based pagination.' );
contract_contains( $fast_desk, "\$query_args['s'] = \$search", 'Fast Desk query must support targeted Journal search.' );
contract_contains( $fast_desk, "'has_more'    => \$page < \$total_pages", 'Fast Desk must tell the GPT whether another page exists.' );
foreach ( array( $production_openapi, $bridge_openapi, $staging_openapi ) as $openapi_release ) {
    contract_contains( $openapi_release, '"name": "page"', 'OpenAPI must expose Journal Desk pagination.' );
    contract_contains( $openapi_release, '"name": "search"', 'OpenAPI must expose targeted Journal search.' );
}

// Bulk migration is preview-gated and marker ordering proves successful readback first.
contract_contains( $main, 'admin_post_lunara_journal_dispatch_preview', 'Admin migration preview action must be registered.' );
contract_contains( $main, "const MIGRATION_CONFIRM_PHRASE = 'CONVERT JOURNAL DRAFTS';", 'Explicit migration confirmation phrase is required.' );
contract_contains( $main, 'Conversion requires candidate_ids from the current read-only preview.', 'REST migration must require IDs from a current preview.' );
$conversion_start = strpos( $main, 'private static function convert_dispatch_post_to_journal' );
$conversion_end = strpos( $main, 'private static function payload_from_legacy_post', $conversion_start );
$conversion = substr( $main, $conversion_start, $conversion_end - $conversion_start );
$update_position = strpos( $conversion, 'wp_update_post' );
$readback_position = strpos( $conversion, '$journal = get_post' );
$terms_position = strpos( $conversion, 'self::set_journal_terms_from_payload' );
$validation_position = strpos( $conversion, '$validation_readback' );
$marker_position = strpos( $conversion, 'update_post_meta( $post_id, self::META_CONVERTED' );
contract_assert( false !== $update_position && false !== $readback_position && false !== $terms_position && false !== $validation_position && false !== $marker_position, 'Conversion verification statements must all exist.' );
contract_assert( $marker_position > $update_position && $marker_position > $readback_position && $marker_position > $terms_position && $marker_position > $validation_position, 'Conversion marker must follow core, taxonomy, and validation readback.' );
contract_contains( $conversion, 'self::fail_conversion', 'Incomplete conversion must enter rollback-or-quarantine handling.' );

// Dispatch uses a stable, draft-only same-process ingestion contract.
contract_contains( $main, "require_once plugin_dir_path( __FILE__ ) . 'includes/class-lunara-journal-ingest.php'", 'Foundation must load the dedicated ingest module.' );
contract_contains( $main, 'Lunara_Journal_Ingest::ingest( $body, false )', 'REST ingest must delegate to the dedicated module.' );
contract_not_contains( $main, 'private static function normalize_dispatch_payload', 'Ingest normalization must not remain in the Foundation monolith.' );
contract_contains( $ingest, "const FILTER = 'lunara_journal_foundation_ingest'", 'Foundation ingest module must own the same-process filter name.' );
contract_contains( $ingest, 'public static function filter_ingest', 'Foundation ingest callback must be public to WordPress filters.' );
contract_contains( $ingest, 'lunara_ingest_idempotency_required', 'Same-process ingest must require a stable idempotency key.' );
contract_contains( $ingest, "'post_status'  => 'draft'", 'Foundation ingest must force WordPress draft status.' );
contract_contains( $ingest, "'post_status'     => 'draft'", 'Foundation ingest result must report draft status.' );
contract_contains( $ingest, 'return self::result( $existing->ID, false', 'Foundation ingest must report idempotent reuse.' );
contract_not_contains( $ingest, "'post_status' => 'publish'", 'Dedicated ingest module must never publish.' );
contract_contains( $ingest, "isset( \$payload['acf']['journal_image_source_url'] )", 'External ingest must recognize a source-story image URL.' );
contract_contains( $ingest, 'Lunara_Journal_Image_Sideload::TRIGGER_URL', 'External ingest must queue the guarded featured-image sideload primitive.' );
$image_alt_position = strpos( $ingest, 'Lunara_Journal_Image_Sideload::TRIGGER_ALT' );
$image_credit_position = strpos( $ingest, 'Lunara_Journal_Image_Sideload::TRIGGER_CREDIT' );
$image_url_position = strpos( $ingest, 'Lunara_Journal_Image_Sideload::TRIGGER_URL' );
contract_assert( false !== $image_alt_position && false !== $image_credit_position && false !== $image_url_position && $image_alt_position < $image_url_position && $image_credit_position < $image_url_position, 'Image context must be stored before the URL queues the shutdown attach.' );
contract_contains( $ingest, "! \$payload['featured_media']", 'External source images must not replace an explicit WordPress featured attachment.' );
$lock_position = strpos( $ingest, '$lock = self::acquire_lock' );
$locked_recheck = strpos( $ingest, 'self::find_by_idempotency_key', $lock_position );
contract_assert( false !== $lock_position && false !== $locked_recheck && $locked_recheck > $lock_position, 'Ingest must re-check idempotency after acquiring its atomic lock.' );
contract_contains( $ingest, "hash( 'sha256', (string) \$idempotency_key )", 'Idempotency locks must use a bounded hash key.' );
contract_contains( $ingest, "add_option( \$option_name, \$value, '', false )", 'Idempotency lock option must be atomic and non-autoloading.' );
contract_contains( $ingest, "'lunara_ingest_lock_busy'", 'Fresh lock contention must return a retryable error.' );
contract_contains( $ingest, '$clearly_stale', 'Only clearly expired locks may be reclaimed.' );
contract_contains( $ingest, '$wpdb->update', 'Stale lock takeover must use a database compare-and-swap.' );
contract_contains( $ingest, "maybe_serialize( \$current )", 'Stale takeover and release must compare the exact prior owner value.' );
contract_contains( $ingest, '$wpdb->delete', 'Lock release must use an owner-value conditional delete.' );
contract_not_contains( $ingest, 'delete_option( $option_name )', 'Stale takeover must not use a delete-then-add race.' );
contract_contains( $ingest, '} finally {', 'Every acquired ingest lock must release through finally.' );
contract_contains( $ingest, 'hash_equals', 'Lock release must verify owner identity.' );
contract_contains( $ingest, 'self::is_boolean_field( $field_name )', 'Ingest boolean normalization must be scoped by field name.' );
contract_contains( $ingest, "array( 'journal_ready_for_review', 'journal_bridge_locked' )", 'Ingest must identify the exact ACF true_false fields.' );
contract_contains( $main, 'self::is_boolean_acf_field( $field_name )', 'Conversion boolean normalization must be scoped by field name.' );
contract_contains( $main, "array( 'journal_ready_for_review', 'journal_bridge_locked' )", 'Conversion must identify the exact ACF true_false fields.' );

// Secrets prefer deployment configuration and are recursively removed from exposed config.
contract_contains( $notion_client, "defined( 'LUNARA_NOTION_TOKEN' )", 'Notion token must support wp-config constant precedence.' );
contract_contains( $notion_client, "getenv( 'LUNARA_NOTION_TOKEN' )", 'Notion token must support environment precedence.' );
contract_contains( $control_plane, 'redact_secret_values', 'Exposed Control Plane config must use recursive secret redaction.' );
contract_contains( $control_plane, "'client_secret'", 'Recursive redaction must recognize nested client secrets.' );
contract_contains( $provenance, "'journal_validation_status', 'unchecked'", 'Initial provenance validation status must use the canonical unchecked value.' );
contract_not_contains( $provenance, "'journal_validation_status', 'not_checked'", 'Legacy not_checked validation state must not remain.' );

// One canonical activation hook carries the authoritative schedule to Dispatch.
contract_contains( $repository, "do_action( 'lunara_journal_control_plane_activated', \$id, self::get_active_config() )", 'Repository must emit the canonical Control Plane activation hook with the active configuration.' );
contract_not_contains( $main . $repository, 'lunara_journal_configuration_activated', 'The obsolete activation hook name must not remain in runtime code.' );

// Dependencies and environment separation are visible before an operator can proceed.
contract_contains( $main, 'Requires Plugins: advanced-custom-fields-pro', 'ACF Pro dependency header is required.' );
contract_contains( $main, "version_compare( LUNARA_DISPATCH_VERSION, '3.2.0', '<' )", 'Dispatch minimum version check is required.' );
contract_contains( $main, 'supports_protocol( Lunara_Journal_Protocol::VERSION )', 'Dispatch protocol compatibility check is required.' );
contract_contains( $production_openapi, 'https://lunarafilm.com/wp-json/lunara/v1', 'Production schema must target production explicitly.' );
contract_not_contains( $staging_openapi, 'lunarafilm.com', 'Staging schema must never hardcode production.' );
contract_contains( $staging_openapi, '{stagingHost}', 'Staging schema must expose an explicit host variable.' );

foreach ( array( 'production' => $production_openapi, 'bridge' => $bridge_openapi, 'staging' => $staging_openapi ) as $label => $json ) {
    $openapi_document = json_decode( $json, true );
    contract_assert( JSON_ERROR_NONE === json_last_error(), ucfirst( $label ) . ' OpenAPI JSON must parse: ' . json_last_error_msg() );

    foreach ( $openapi_document['paths'] ?? array() as $path => $path_item ) {
        foreach ( $path_item as $method => $operation ) {
            if ( ! is_array( $operation ) || ! isset( $operation['description'] ) ) {
                continue;
            }
            contract_assert(
                strlen( $operation['description'] ) <= 300,
                ucfirst( $label ) . ' OpenAPI ' . strtoupper( $method ) . ' ' . $path . ' description exceeds the ChatGPT 300-character limit.'
            );
        }
    }
}

if ( $failures ) {
    fwrite( STDERR, "Journal Foundation release contracts failed:\n- " . implode( "\n- ", $failures ) . "\n" );
    exit( 1 );
}

echo 'Journal Foundation release contracts passed: ' . $passes . " assertions.\n";

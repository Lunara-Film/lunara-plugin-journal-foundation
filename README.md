# LUNARA FILM Journal Foundation

Version: 1.4.0

The Foundation owns the `journal` content model, ACF fields, versioned WordPress Control Plane, draft-first scope-gated security, provenance, validation, and the Fast Journal Desk used by the private LUNARA GPT.

## Dalton's voice, one story at a time (1.4.0)

Journal drafts did not sound like Dalton. The writer was a small model (GPT-5.4 mini, reasoning off) producing up to three entries in one 2,200-token response, so each landed near 200 words; the prompt described the voice mostly through prohibitions and never showed it; the closing question was asked for in about one entry in three; and a banned phrase only raised a warning. 1.4.0 changes what the model is shown and asked for:

- **Exemplars.** `editorial.voice.exemplars` holds Dalton's own published entries, compiled in full under *DALTON'S VOICE ON THE PAGE* as the target: *Robert Eggers Made a Werewolf Movie in Middle English and I Have Never Been More In* and *Paramount Let Street Fighter Be Unhinged. Thank God.* The prompt forbids copying their sentences, facts, or anecdotes and forbids inventing experiences Dalton did not have. Up to three exemplars; `<em>` only; folded to ASCII (`Lunara_Journal_Voice_Exemplars`).
- **One story per run**, in his guide's Hook / Context / Specifics / Take / Close / Engagement Question shape at 300 to 700 words, with the engagement question on **every** entry. `minimum_words` stays the validator and Desk publish floor (75), so Dalton's own short pieces still publish.
- **Claude.** `Lunara_Journal_Voice_Upgrade` moves the active Control Plane version once, as normal attributed versions (actor `system`): the voice step immediately on any provider, and, as soon as Dispatch has an Anthropic key, provider `claude` with `claude-opus-5` and 16,000 output tokens (Claude's thinking counts against the same limit; every other provider keeps the 2,200 cap). Each step runs once, under a lock, only in wp-admin, WP-Cron, or WP-CLI, and never undoes a later edit. Neither step runs until Lunara Dispatch 3.4.0 is active: 3.3.0 writes three approved pitches in one call and has no Claude stop-reason handling, and the two plugins deploy separately, so either can go live first. Until then, and later until the key exists, Journal screens show a notice saying what the move is waiting for.
- The Dispatch runtime now carries `house_tells` (banned plus cut-on-sight phrases) so Dispatch 3.4.0 can send a draft back once for revision, and every activation flushes the request's cached config.
- Journal Desk revisions on a Claude 5-family model use adaptive thinking at low effort, default server-side fallbacks, 8,000 output tokens, and a 90-second timeout, and report a refusal as a refusal.

Voice lists an editor can shorten (`structure`, `principles`, `exemplars`, and the rest in `Lunara_Journal_Config_Schema::VOICE_LISTS`) now replace the default whole instead of merging by index. Contracts: `tests/prompt-compiler-voice-runtime.php`, `tests/voice-upgrade-runtime.php`, `tests/desk-rewriter-runtime.php`.

## Journal voice lives in the compiler (1.2.14)

Foundation 1.2.14 moves the full LUNARA Journal register into the Control Plane prompt compiler as code-owned defaults: the talk-not-essay register, the standing principles, the flexible structure, headline rules, Not this / This contrast pairs, a drift catalog, a cut-on-sight list of performed-expertise phrases, and the per-entry landing plus engagement question. Before 1.2.14 the compiled prompt carried a one-sentence voice summary and the rest of the voice lived in a Dispatch fallback prompt that never executes while Foundation is active. The new keys sit under `editorial.voice` and are filled from code on every read, so every stored configuration version, including ones created before 1.2.14, compiles with the full voice while keeping its admin-edited summary, refinement note, and banned phrases. The validator reports the cut-on-sight phrases as warnings, never as errors. Contracts: `tests/prompt-compiler-voice-runtime.php` and `tests/validator-house-tells-runtime.php`.

## Journal Workflow handoff and labeled sources

Foundation 1.2.13 contributes a capability-gated **Journal Workflow** destination to Lunara Site Studio when the theme registry is available. The contribution is inert without that registry and always hands administrators to the canonical WordPress tool at **Journal → Control Plane**. Its status is deliberately minimal: availability, validity, last activation time, enabled-source count, and the protected administrative URL. It never returns source URLs or labels, prompts, banned phrases, Notion identifiers, credentials, bridge tokens, raw provider errors, or full configuration.

The Control Plane now edits sources as recognizable labeled rows rather than raw JSON. Each row has an immutable server-owned ID, enabled state, source name, HTTP(S) URL, maximum item count from 1–50, and priority from 1–10. Unsafe schemes, malformed rows, duplicate IDs, duplicate normalized URLs, and out-of-range values are rejected before any immutable version or Notion setting is written. Invalid rows are retained briefly for the submitting WordPress user only. Successful changes continue through the single versioned `create_and_activate()` repository path, and removing a source or restoring a prior version requires confirmation.

## Fast Journal Desk

Use the included OpenAPI schema:

`openapi/lunara-journal-fast-desk.openapi.json`

Authentication remains a separate GPT Action setting. Use the standard Bearer scheme for ChatGPT Actions:

`Authorization: Bearer {ChatGPT Editorial Bridge key}`

The legacy `X-Lunara-Bridge-Token` header remains supported. The key is never stored in the JSON schema, and query-string tokens are rejected.

Consolidated operations:

- `GET /journal/desk` — identity, health, Control Plane summary, Dispatch state, searchable and paginated drafts, attention items, and the recommended next draft. Use `search`, `page`, and `limit` to traverse the complete inventory without oversized responses.
- `GET /journal/desk/drafts/{id}` — complete editing workspace with active editorial rules and current validation, without the full audit log.
- `POST /journal/desk/drafts/{id}/save-validate` — save Dalton-approved fields and run deterministic validation in one call.
- `POST /journal/desk/run-dispatch` — queue a manual Dispatch run asynchronously and return immediately.
- `POST /dispatch/drafts/{id}/mark-ready` — mark a validated draft ready while preserving WordPress draft status.
- `GET /dispatch/drafts/{id}/audit` — retrieve full provenance only when explicitly needed.
- `POST /journal/desk/drafts/{id}/publish` — optional single-entry publish action. It is disabled by default, absent from the standard ChatGPT editor scope, and still requires validation plus explicit confirmation.

### Dispatch 3.2.5 Hub telemetry

The private Desk and Automation snapshots now expose the same bounded, non-secret Dispatch status contract. The active runtime reports its provider, selected model, 2,200-token output cap, and three-source run budget. The last-run summary reports requested and effective model names, token counts, estimated cost, fallback/error state, processed and deferred source counts, Source Radar count, and source-packet draft count. Usage values that an older run did not report remain `null` and are explicitly marked unreported; a genuine reported zero remains zero. Draft summaries also identify source-packet generation without returning prompts, source bodies, response identifiers, credentials, or API keys.

OpenAI runtime configuration is constrained to `gpt-5.4-mini` or `gpt-5.4-nano`. Existing immutable Control Plane versions are preserved; reading or cloning an older version safely normalizes unsupported OpenAI model names to `gpt-5.4-mini` and caps output at 2,200 tokens.

### Settled validation alert guard

Foundation 1.2.12 prevents a transient validation failure from producing a false IFTTT Needs Attention alert after the draft has already recovered. Validation-origin alerts settle at request shutdown after image sideload and revalidation: Foundation runs the deterministic validator again and queues an alert only if the final stored status is still `failed`, `errors`, or `invalid`. Returning to `passed`, `unchecked`, or any other non-invalid status clears the draft's failure signature. The signature is stored only after cron queueing succeeds and is cleared after queue or delivery failure so a persistent failure can be retried. When a queued post-specific alert reaches WordPress cron, Foundation reads the current stored validation status again and silently skips delivery unless it remains invalid. Dispatch-run failure and connection-test alerts remain independent and are never suppressed by this post-specific check.

## Featured Image Guard

Every Fast Desk draft now carries deterministic featured-image diagnostics.

Hard validation failures:

- no featured image attached
- missing or unresolved media-library attachment
- featured media is not an image
- image URL or dimensions cannot be resolved
- image is smaller than 800x450

Quality warnings:

- image is below the preferred 1200x630 resolution
- aspect ratio falls outside the landscape-friendly 1.50-2.10 range
- attachment alt text is missing

`openJournalDesk` reports compact image status and aggregate image counts. `openJournalWorkspace` returns full dimensions, MIME type, URL, aspect ratio, alt text, hard errors, and warnings. `saveAndValidateJournalDraft` and `markJournalDraftReady` fail when the image is missing or unusable.

## Featured image sideload

Foundation can attach a featured image to a Journal draft directly from a remote image URL, closing the gap where only an already-uploaded attachment id was accepted. This lets the editor choose the image for a story and attach it in the same pass as the writing, so the words and the picture are reviewed together.

Trigger it by writing the post-meta key `_lunara_journal_set_featured_image_url` on a Journal draft, for example through the standard update-post bridge path. Optional companion keys `_lunara_journal_set_featured_image_alt` and `_lunara_journal_set_featured_image_credit` supply alt text and credit; alt text defaults to the entry title when omitted.

On the request that writes the trigger key, Foundation:

- validates the URL (http/https only) and downloads it with `media_sideload_image`
- reuses an existing attachment when the same source URL was sideloaded before
- sets the media-library image as the draft's featured image and reads the assignment back before trusting it
- mirrors the source URL, alt text, and credit onto the `journal_image_*` fields
- clears the Featured Image Guard cache and re-runs validation so the image diagnostics reflect the new image immediately

The outcome (attachment id, dimensions, and any error) is recorded on the draft as `_lunara_journal_image_sideload_result` for inspection. Sideloading only applies to draft, pending, and private Journal entries; published, scheduled, and trashed content is refused. A public `Lunara_Journal_Image_Sideload::sideload_from_url()` is also available for same-process callers.

## Speed behavior

- The default first Journal Desk page is cached for 60 seconds. Search results and later pages are always queried directly.
- Active configuration is cached within each WordPress request.
- Compiled prompts are memoized within each WordPress request.
- Normal workspace responses omit full audit history.
- Manual Dispatch runs are queued in WordPress rather than holding the GPT Action request open during feed, AI, and image processing.

## Access profiles

The `chatgpt_editor` profile has these scopes:

`read`, `update`, `validate`, `mark_ready`, `run_dispatch`, `audit`, `schema`

Existing ChatGPT keys automatically receive the `run_dispatch` scope when the profile registry is loaded. Publishing remains absent by default, but an administrator may explicitly add the `publish` scope to this dedicated profile without granting wildcard WordPress access. Scoped keys do not need to be regenerated.

## IFTTT Pro+ Journal Automation

Foundation 1.2.12 keeps the dedicated `ifttt_operator` profile and private Automation Inbox. IFTTT is transport only: Foundation authenticates each request, deduplicates event IDs, stores a bounded audit history, and keeps WordPress authoritative. The profile may call only the server-enforced draft ingest in addition to its existing capture, run, and notification actions; it still has no read, update, validation, audit, conversion, schema, publication, deletion, or wildcard authority. Draft ingest queues the existing guarded image sideload when an external transport supplies `journal_image_source_url`, so a private draft can receive its source image without media-library or publication authority. Human-readable Feedly publication dates are normalized to the site's ACF database format before strict readback verification. Long unstructured draft copy gains editable paragraph markup without changing its words, and capped WordPress source images request a 1920px derivative while retaining the original provenance URL.

Supported first-release workflows:

- Morning Desk — asks WordPress to compile operational Journal/Dispatch status and queues an allowlisted IFTTT notification.
- Run Lunara — reuses Fast Journal Desk's existing asynchronous Dispatch queue.
- Capture Idea — stores a private Automation Inbox draft.
- Source Radar — stores a private source candidate with its exact source URL. Dispatch can consume only new Source Radar entries through a bounded same-process bridge; successful or editorially terminal outcomes are triaged, while retryable failures stay queued.
- Screening Follow-Up — stores private post-screening notes without creating a public article.
- Needs Attention — emits only for failed Dispatch reports or failed Journal validation.

Generate the dedicated IFTTT profile token from **Journal → Journal Bridge**. Send it only in an `Authorization: Bearer` or `X-Lunara-Bridge-Token` header. Configure outbound IFTTT delivery only through `LUNARA_IFTTT_WEBHOOK_KEY` in `wp-config.php` or the deployment environment; the key is never stored in a WordPress option or rendered in Control Desk.

The IFTTT profile has only three scopes: capture private signals, queue Dispatch, and trigger the allowlisted notification workflow. It cannot read the private inbox or broader Journal audit API, and it can never publish, schedule, delete, change plugins/themes, or change cache/CDN settings. Exact Applet recipes and the activation/rollback gate are in [`docs/IFTTT-PRO-PLUS-SETUP.md`](docs/IFTTT-PRO-PLUS-SETUP.md).

## Safety

The default ChatGPT Editorial Bridge key refuses:

- publish
- schedule/future
- delete/trash
- WordPress post-status changes
- published, scheduled, or trashed content
- external Control Plane activation or rollback

The dedicated publish route remains available only to an explicitly publish-scoped key or a WordPress user who can both edit the selected entry and publish posts. Control Plane publishing is off by default.

Authentication is header-only. Send access keys with `Authorization: Bearer <token>` (recommended for ChatGPT Actions) or the legacy `X-Lunara-Bridge-Token`; query-string tokens are rejected.

## Legacy migration safety

- Auto-conversion defaults to off and no conversion cron is created during activation.
- Saved-post conversion runs only after an administrator explicitly enables it.
- Bulk legacy conversion requires a read-only preview followed by the exact confirmation phrase shown in WordPress.
- Only IDs preserved in the current server-side preview are eligible for the confirmed conversion.
- The conversion completion marker is written only after the post type change is successfully read back as `journal`.

## Dependencies

- WordPress 6.4 or newer.
- ACF Pro for the fully editable Journal field interface.
- Lunara Dispatch 3.2.5 or newer for Foundation 1.2.13 automated OpenAI collection, cost-safe Responses API requests, source-packet fallback, and complete Hub telemetry.
- Journal protocol 1.x compatibility between Foundation and Dispatch.

WordPress remains the authoritative runtime. The private GPT is the daily editorial interface. Notion is optional and is not required by Fast Journal Desk.

## Install order

1. Confirm Lunara Dispatch Automation 3.2.5 or newer is active. When upgrading an older paired stack, disable automated runs until Dispatch has been upgraded first.
2. Replace the existing LUNARA Journal Foundation with version 1.4.0.
3. For production, update the private GPT Action schema using `openapi/lunara-journal-fast-desk.openapi.json`.
4. For staging, use `openapi/lunara-journal-fast-desk.staging.openapi.json` and replace its staging host variable before importing it.
5. Keep the current GPT instructions; the 1.2.13 automation routes are separate from the Journal Editor Action schema.
6. Configure the GPT Action to use Bearer authentication. Existing scoped keys remain valid; reissue a key only if the installation still uses the retired legacy wildcard token.

## Private Journal Desk app (1.4.0)

Open `/journal-desk/` with your existing WordPress administrator login to review drafts, propose revisions, adjust the Journal voice, manage sources, run Dispatch, and approve publication. Add it to your iPhone Home Screen for a standalone view. The same canonical voice powers Dispatch and rewrite proposals. Existing publication gates remain in force. See [Journal Desk installation and verification](docs/journal-desk-app.md).

**Pitches (1.3.2).** With Lunara Dispatch 3.3.0 or newer, the Desk's **Pitches** tab shows the stories Dispatch found while pitch mode is on. Choose Write it (with an optional angle) or Pass, and only approved pitches become drafts in the queue. The tab turns pitch mode on and off, and stores nothing of its own: it reads and decides through Dispatch's pitch routes with your WordPress session.

# LUNARA Journal Desk

The private app is served at `/journal-desk/` on the existing WordPress site. It reuses Journal Foundation, Dispatch, the active versioned editorial configuration, and the site's logged-in WordPress session. There is no second content database, token pasted into a browser, or additional hosting service.

## What is included

### Story sourcing and daily delivery

The Desk now opens on **Stories**: a shared feature-film news wire using the existing Dispatch pitch store. Search, filter by source, read the original report, add an angle and choose **Write it** or **Pass**. Refresh imports feeds directly without invoking the AI writer; choosing Write it uses Dispatch's existing paid writer. Explicit TV and short-film stories and source reports older than seven days are filtered. Undated reports remain clearly undated. Filters are deterministic editorial assistance, not a perfect classifier.

Coverage badges match canonical source URLs against the latest 250 active Journal records. They distinguish an existing draft from published coverage; an absent badge is not proof the story has never been covered. Discovery and the older Pitches tab use the same store. No WordPress credentials or article queue are kept in browser storage.

Version 1.5.1 initializes Dispatch's lazy-loaded feed services before checking discovery availability. An active Dispatch is therefore recognized on ordinary Journal Desk requests, without running its drafting worker or fetching feeds during a read.

**Publishing** reports today's live count, the last published entry, timed articles, approved reserve and delivery problems. The floor is one Journal article per site calendar day; the reserve target is seven. An administrator explicitly approves each saved, validated article for reserve or a chosen time. Time-sensitive approvals expire after 24 hours; the explicit Evergreen choice extends approval to 30 days. Changed articles, expired approvals, revoked publication access, locks and validation errors require renewed attention. Approvals do not grant broader bridge access or turn on the Control Plane's publication setting.

Timed articles stay as drafts until the guarded delivery worker revalidates and publishes them. The worker checks hourly for daily coverage after 10 a.m. in the WordPress site timezone and uses a single queued event for a chosen delivery time. It never picks an unapproved draft. A failed run leaves the article unpublished and records its result. Published status is read back before the reserve approval is consumed.

**Release prerequisite:** configure and verify a host/system trigger for WordPress cron before relying on unattended delivery. Traffic-triggered WP-Cron alone cannot guarantee timing. The UI reports when the traffic trigger is disabled; it cannot prove the host's scheduler exists. This release creates no hosting credentials and changes no production scheduler settings. Live provider, feed, publishing permissions and one real end-to-end publication still need verification on the installed release.

New private routes are GET `journal/app/wire`, POST `journal/app/wire/refresh`, GET `journal/app/publishing`, and POST `journal/app/drafts/{id}/reserve` or `/schedule`. Every route requires the existing administrator session and REST nonce; delivery writes also require edit and publish capabilities and the current saved revision.

- Live paginated draft queue and search, sourced from the actual Journal content type.
- Headline, article, summary, deck, and search-description editing.
- Sources, featured image, publishing checks, and separate voice-review prompts.
- Revision proposals using the same active Journal voice and configured Dispatch provider. Proposals are compared and applied in the editor before any save.
- Explicit standing-instruction saves, versioned voice settings, source controls, and story-selection rules.
- Manual Dispatch runs with queued/running/completed status from the existing worker.
- Confirmed publishing through the existing permission and validation gates. Rejection retains the WordPress draft.
- Home Screen web-app manifest, responsive interface, and no caching of drafts in browser storage.

## Access and installation

Install this release of the existing `lunara-plugin-journal-foundation` plugin, preserving its current folder and database. The site was observed running Foundation 1.2.12 and Dispatch 3.2.7 during implementation; the implementation baseline was Foundation 1.2.14. Dispatch's existing scheduled worker is reused.

Open `/journal-desk/` while logged in as a WordPress administrator, or use **Journal → Open Journal Desk**. No permalink flush is required. On iPhone, open in Safari, use **Share → Add to Home Screen**, and enable **Open as Web App** when offered. An internet connection is required.

Publishing continues to honor `chatgpt.may_publish`, WordPress post capabilities, locked-draft restrictions, an explicit confirmation, and the Journal validator. The app does not change these settings or grant new bridge scopes. If publishing is disabled, its button links to the existing protected Journal Control Plane. Schedule and provider credentials remain in that existing settings page.

## Verification and limits

The standalone tests cover cookie/nonce permissions, configuration field allowlists, exact list replacement, stale revision conflicts, publish confirmation, provider boundaries, source-link restrictions, no rewrite persistence, and frontend action guards. Run the PHP files in `tests/` and `node --test tests/desk-state.test.cjs`; CI runs the PHP matrix on 7.4, 8.2, and 8.3.

The feature must still be exercised on the installed WordPress site: authenticated page load, opening a draft, saving, one provider rewrite, Dispatch completion, and an explicitly approved real publication. Local mocked HTTP tests do not establish live provider access. A passed formatting/metadata validator does not establish factual accuracy or editorial approval.

Per-draft tokens reject stale app requests; short locks serialize app-tab writes. Existing WordPress editor/legacy bridge writes do not share those locks, so these are not global database transactions. Failed or timed-out writes should be reloaded and checked before retrying. Session expiry can require a page reload; keep unsaved text before leaving.

## Image editing (1.3.1)

The featured-image card includes **Change image**, device upload, and a searchable, paginated media-library picker. Selection preserves unsaved text and takes effect on the article only with Save draft. Credit is cleared for a different image; verify attribution before saving. Alt text is editable and also updates the selected attachment in WordPress, including other uses of that attachment. Shared alt changes participate in the draft revision check.

`GET /lunara/v1/journal/app/media` supports `search` and `page`; `POST` accepts one multipart `file`. Both require the administrator cookie session, REST nonce, and `upload_files`. Uploads use the core WordPress media endpoint after file-signature and size checks (20 MB or the lower site limit), and enter the library immediately with public file URLs. The picker accepts JPEG, PNG, WebP, GIF, and AVIF; core may reject formats unsupported by the server. No article is published by an upload.

Save accepts a positive integer `featured_media`, validates it and attachment edit capability before writing, and verifies thumbnail/alt readback. A subsequent failed article save is reported as partial and the UI requires reloading before another save or publication. WordPress does not provide an atomic transaction across attachment metadata and article writes.

Verification: image state tests, editor DOM interactions (upload, library search/paging, preservation of unsaved prose, staged image metadata, partial-save blocking), and PHP permission/revision/upload-boundary tests. These do not replace installed-site upload and thumbnail-rendering verification.

## Pitches (1.3.2)

The **Pitches** tab is the approval inbox for the Lunara Dispatch 3.3.0 pitch gate. With pitch mode on, a Dispatch run writes nothing. It files what it found as pitches (headline, outlet, summary, link, and the outlet image unless the source blocks reuse). Choose **Write it**, optionally with **Add angle** (a note for the writer, up to 600 characters), or **Pass**, then **Send calls**. Approved pitches go through the normal draft-only Dispatch pipeline and land in the draft queue. Passed pitches are never written. **Recent calls** shows each decided pitch as with the writer, drafted (with an Open draft link), passed, or skipped by Dispatch's editorial gate (with its reason). The queue's status strip links to waiting pitches. The pitch-mode switch is on the Pitches tab.

The Desk stores no pitch state. It calls Dispatch's own routes, `GET lunara/v1/dispatch/pitches`, `POST lunara/v1/dispatch/pitches/decide` and `POST lunara/v1/dispatch/pitches/mode`, with the administrator cookie session and REST nonce. Dispatch checks `edit_others_posts`. Deciding pitches never saves, rejects, or publishes a draft. On a Dispatch older than 3.3.0 the tab shows an update message and the queue is unaffected. The LUNARA Hub's Pitches panel reads the same Dispatch store, so a call made in either place shows up in both.

Verification: `tests/desk-pitches-ui.test.cjs` covers the decision helpers (only open pitches are sent, angles are trimmed and attached only to Write it), the tab flow against mocked Dispatch routes (nonce, same-origin credentials, request body, list and history refresh, mode switch, no save or publish calls, unsafe image URLs dropped), and the older-Dispatch message. These tests do not replace a live check on the installed site with Dispatch 3.3.0.

## Revision requests on OpenAI (1.3.3)

**Propose a revision** uses OpenAI's JSON response mode. OpenAI rejects that mode with HTTP 400 unless the request *input* contains the word "JSON"; mentioning it only in the instructions is not enough. Before 1.3.3 every OpenAI revision failed with "HTTP 400". The input now opens with a one-line JSON reply instruction. Dispatch's own drafting was unaffected because it does not use JSON mode.

Any other provider rejection now includes the provider's own reason, for example `(HTTP 400). Provider said: "…"`. The reason is capped at 240 characters, stripped of tags, and scrubbed of the stored key and anything shaped like an API key. Contract: `tests/desk-rewriter-runtime.php`.

## Revision requests on Claude (1.4.0)

When the active Dispatch provider is Claude on a 5-family model (the 1.4.0 voice move sets `claude-opus-5` once Dispatch has an Anthropic key), **Propose a revision** sends adaptive thinking at low effort, so the preview comes back quickly. It also sends `fallbacks: "default"` with the `server-side-fallback-2026-07-01` beta header, so a policy decline re-runs on Anthropic's recommended model. The request allows 8,000 output tokens, because Claude's thinking counts against that limit, and times out after 90 seconds. A refusal reads as "Claude declined to revise this draft" rather than as an unfinished rewrite. Only the reply's text is used; thinking never reaches the draft. An older Claude model ID keeps the plain request. Contract: `tests/desk-rewriter-runtime.php`.

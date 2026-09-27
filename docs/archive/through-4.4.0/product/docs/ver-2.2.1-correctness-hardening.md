# ver 2.2.1 correctness, idempotency, privacy, and low-risk efficiency hardening

## Player-facing summary

Farm production is now available to feed the population before only the residual amount above the
food capacity is sold or discarded. The food capacity continues to come from
`NationCapacityResolver`; the 1,000-ton sale batch, money capacity, normal sale phase, and sale
policies are unchanged. A typhoon that removes a farm now reports that the farm was lost and the
cell became plain, rather than saying that plain changed to plain.

Retrying the exact same single-command request key returns the existing item with `duplicate=true`
and no new registration message or audit. Reusing the key for a different normalized command,
coordinate, quantity, parameters, requested insertion position, or ruleset returns the stable
`command_request_conflict` 409 response without changing the queue. Rows created before the
fingerprint column remain nullable and fail closed; the release does not guess or rewrite history.

Nation registration now keeps player validation errors on `name`, `owner_name`, or `comment`, uses
stable 409 codes for request/world conflicts, preserves `reset_required`, reports placement
unavailability without internal detail, and hides unexpected invariant/corruption details behind a
generic 500 response while retaining the server exception report.

## Ruleset v10 and retry boundary

`hakoniwa-2s-plus-v10` inherits v9 byte-for-byte except for key/version and
`turn_processing.food.production_overflow_resolution_stage =
after_population_nutrition_consumption`. v1-v9 files, rows, checksums, and applied migrations are
not edited. A historical v9 TurnRun therefore keeps the old production-overflow-before-nutrition
behavior; only v10 runs use production, nutrition consumption, then residual overflow resolution.
The canonical v10 payload checksum is
`6a0f5354f8894081bacdb8eaaba328d3e4ee80a2c4136819b16cfa924f485dc1`.

The v10 forward migration takes the common World advisory lock and World row lock, rejects the
next non-dry `pending`, `running`, `failed`, or `blocked` TurnRun, requires the exact v9 source,
compares command and monster stable-key sets, and rebinds only the World, queued commands, live
monsters, and live kill totals. Completed/failed/cancelled queue history stays on its original
definition. The consistency constraints/triggers are restored before commit, the operation is
idempotent, and any failure rolls back the whole conversion. No queued-command confirmation is
required because command definitions and semantics are unchanged.

## Inquiry image and delivery boundary

The existing 10 MiB and server-observed PNG/JPEG/WebP/GIF allowlist remains. SVG is still rejected.
The server now also requires readable dimensions, at most 12,000 pixels on either side, no more
than 40,000,000 total pixels, and agreement between the dimension parser and `finfo` MIME. A
rejection writes neither a database row nor a file and does not consume the submission UUID.
Animated-frame counting is not added: robust frame/decompression-bomb inspection requires a
specialized decoder/sandbox and is a separate security decision.

Attachment URLs remain unguessable public locators, not authenticated/private storage. Apache and
the checked-in Nginx location return `Cache-Control: private, no-store, max-age=0` and
`X-Content-Type-Options: nosniff`; directory listing remains disabled. Cloudflare or another CDN can
ignore origin cache headers when a cache rule overrides them, so the operator must verify the
effective response and bypass/cache-rule behavior without changing production settings in this PR.

The repository default remains the `hakoniwa_inquiry_attachments` named volume for local/default
Compose. Production adopts an operator-owned host bind mount through a Git-external override. This
improves container-recreation persistence but is not a backup. The PostgreSQL backup wrapper still
does not include attachments, so recovery is unavailable until a separate off-host file backup and
restore test is approved and recorded.

## OAuth and query-efficiency boundary

OAuth first-login and link operations take a transaction advisory lock keyed by an unambiguous,
length-prefixed provider/provider-user-id string before reading the identity row. Concurrent first
logins converge to one User, one identity, and one registration audit; same-User concurrent links
converge to one identity and one link audit. Existing uniqueness remains the final integrity guard.
No generic lock framework is introduced, and raw provider identifiers remain hidden from APIs.

The command-definition endpoint now projects the selected cell and 30-item queue once per request
and batch-loads result facilities by stable key. It preserves catalog order, payload shape,
territory warnings, and dangerous overbuild previews. The representative full-catalog profile falls
from 52 SQL statements before the change to 44 after it. `/api/v1/me` evaluates the shared admin
capability once and reuses the eager-loaded identity collection for both flags; its identity lookup
falls from three equivalent queries to one and it exposes no admin identifier.

The application version comes from backend config, is rendered into a Blade meta tag, and is read
by Vue without an API request or a frontend version literal. Inquiry metadata, header UI, and this
document use 2.2.1.

## CI contract

The PHPUnit matrix retains the existing planner and identifier-equivalence checks, runs every
matrix entry with `fail-fast: false`, and keeps `backend`, `frontend`, and `documentation` as the
stable required-check surface. PHPStan covers all of `app`. Composer installs once with the locked
PHP/extensions, packages `vendor` as a mode/symlink-preserving tar archive, and publishes the lock
and archive checksums. Every consumer verifies both checksums and rejects archive entries outside
`vendor` before extraction. Dependency preparation, validation, upload, download, integrity, or
extraction failures fail closed and prevent backend success.

The official JavaScript Actions use their current Node 24-capable majors: `checkout@v6`,
`setup-node@v7`, `cache@v5`, `upload-artifact@v7`, and `download-artifact@v8`. The workflow runs on
GitHub-hosted runners, so no self-hosted runner compatibility exception is required.

## Pre-deployment operator checklist

This checklist does not authorize deploy, migration execution, production access, Cloudflare
changes, cron resume, or merge.

1. Verify the reviewed release SHA, clean tree, application 2.2.1, v10 checksum recorded by CI, and
   unchanged v1-v9 checksums.
2. Pause player writes and the turn cron; resolve the next non-dry `pending`, `running`, `failed`, or
   `blocked` TurnRun. Never retry it automatically across the release.
3. Take and verify the approved encrypted off-host PostgreSQL backup. Separately record the adopted
   attachment host bind mount and either a tested off-host file restore or explicit no-recovery
   acceptance.
4. Rehearse the forward migration against a current production-shaped copy: exact v9 source,
   queued/live rebinds, history unchanged, constraints enabled, idempotent second run, and rollback
   on a forced failure.
5. Verify a 10 MiB valid image succeeds; SVG, wrong MIME, corrupt dimensions, 12,001-pixel sides,
   and over-40MP images fail without row/file/key consumption.
6. Verify the effective attachment response at Apache/Nginx and every proxy/CDN layer includes
   `private, no-store, max-age=0` and `nosniff`; confirm no cache rule overrides it.
7. Run two-process PostgreSQL OAuth first-login and link smoke tests without exposing provider IDs,
   then verify one User/identity/audit per logical operation.
8. Verify command exact-retry and mismatch 409 behavior, Nation registration classifications,
   typhoon text, 30-item catalog query profile, `/me` identity query count, and all CI gates.
9. Keep production Compose, OCI, database, Cloudflare, and release-gate actions separate and require
   explicit owner authorization.

## Candidate next goals requiring an owner decision

| Candidate | Decision needed before work | Not implemented in 2.2.1 |
|---|---|---|
| AWS SDK for JavaScript v3 migration | owning surface, compatibility window, and rollout validation | yes |
| Generic serializable/retry framework | strictly DB-only transaction scope and replay-safe side effects | yes |
| Animated-image/decompression inspection | decoder/sandbox choice, resource limits, and false-positive policy | yes |
| Automatic dormancy/abandonment | existing Deferred design gate and production data conversion policy | yes |
| Monster achievement fixed-eight removal | achievement contract and compatibility for existing totals | yes |
| Monster kind/display-order redesign | ordering contract before any new monster definitions | yes |
| Event retention inventory | retention, deletion, audit, and production conversion policy | yes |
| Low-risk `App.vue` component split | stable component seams and regression scope without a redesign | yes |
| `CompleteTurnEngine` phase split | transaction, event-order, random-stream, and retry boundaries | yes |
| Historical migration/service dependency rebaseline | acceptable historical dependency boundary and evidence | yes |
| Main branch protection | manually require backend/frontend/documentation, prohibit force push/deletion, and define emergency bypass | yes |

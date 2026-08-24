# ver 2.5.0 Secretary public profile

## Scope and authority

This is the first ver 2.5.0 profile slice approved by the Owner. The two external PBW pages
inform only the public status-sheet composition: portrait, basic facts, biography, and
equipment on one screen. No external design, text, image, or code is copied.

## Existing-code audit

| Requested audit | Existing canonical boundary | v2.5 local delta |
|---|---|---|
| Secretary page/tabs | `App.vue` owner page with 熟練度, 装備, 倉庫 secondary tabs | Add メイン first; public viewers receive only that tab. |
| Secretary schema | one `secretaries` row per User, four `secretary_skills` | Add biography and one complete-or-null image metadata group to the same row. |
| Equipment slots | `SecretaryItemPresenter` and equipment service own slots 1–5 | Reuse its five-slot projection read-only; owner link switches to the existing equipment tab. |
| Passive level total | the four canonical `SecretarySkill` rows | Validate the exact key set and sum `level`; do not add XP or a second level table. |
| Capacity | `NationCapacityResolver` and `CapacityBoundedAssetService` | Add the exact v14 Secretary multiplier before all bounded credits. |
| Inquiry upload | request size/type rule, server `finfo` plus `getimagesize`, random path, disk write verification, orphan cleanup | Share `WebImageMime` and `WebImageUploadService`; keep purpose-specific requests/services. |
| Static image delivery | bot-assets writable mount and hardened static aliases | Use a separate public Secretary directory and immutable-by-URL cache policy. |

No related `Open` gate in `docs/open-questions.md` has been reached. E-04 remains Deferred:
v14 contains one explicit Secretary capacity delta, not a generic modifier framework.

## Data and API contract

`secretaries.profile_biography` is UTF-8 plain text, at most 1000 characters, with CRLF/CR
normalized to LF. HTML-like tags and unsafe control characters are rejected. Markdown syntax
has no special meaning. `main_image_path`, MIME, creation method, optional credit, and updated
time must be all null or a valid complete group. Credit is plain text up to 160 characters.

`users.show_ai_generated_secretary_images` and `users.secretary_image_fallback` are both null
for an unset preference, or both set to a boolean and `silhouette|peridot`. Preferences apply
to every Secretary, including the viewer's own.

| Endpoint | Audience | Contract |
|---|---|---|
| `GET /api/v1/secretaries/{id}` | public; optional session viewer | Viewer-safe public profile, current level, biography, image decision, and five slots. |
| `GET /api/v1/me/secretary` | authenticated owner | Existing owner payload plus nested profile. |
| `PATCH /api/v1/me/secretary/profile` | owner | Replace biography only. |
| `POST /api/v1/me/secretary/main-image` | owner | Validate and replace image plus required metadata. |
| `PATCH /api/v1/me/secretary/main-image` | owner | Edit metadata for the existing image only. |
| `PATCH /api/v1/me/secretary/image-preferences` | authenticated User | Atomically set AI visibility and missing-image fallback. |

The public profile response is `private, no-store`, because the same Secretary can resolve to
uploaded image, fallback, or No image depending on the session viewer. Owner mutations use
row locks and private audit events. Public Nation detail exposes only the named owner
`secretary_id` needed to navigate to the profile.

## Image boundary and display decision

Uploads accept PNG, JPEG, WebP, or GIF up to 10MiB. Extension and browser-declared type are not
trusted: `finfo`, decoded dimensions, decoded MIME agreement, 12000-pixel side, and 40-million
pixel limits are enforced. A 64-hex-character random basename plus MIME-derived extension is
stored on the `secretary_images` disk. A failed DB transaction deletes the new file; a
successful replacement deletes the previous file. There is no gallery or history row.

The default local path is `/srv/bot-assets/hakoniwa-secretaries`, published at
`/hakoniwa-secretaries/`. Static delivery disables indexing, `.htaccess`, CGI, and includes,
adds `nosniff`, and may cache each random immutable URL for one year. It is intentionally
separate from private/no-store inquiry attachments.

| Secretary image state | Viewer preference | Display |
|---|---|---|
| no image | configured | chosen silhouette or Peridot fallback |
| no image | unset | No image and the one-line setup notice |
| AI-generated image | unset or AI hidden | No image; stored file remains intact |
| any allowed image | viewer permits it | uploaded image and the public method/credit info |

## Secretary level and capacity

Secretary level is exactly the sum of the four passive-skill levels. v14 adds this authored
contract:

```text
money capacity = floor(base money capacity * (100 + Secretary level) / 100)
food capacity  = floor(base food capacity  * (100 + Secretary level) / 100)
```

There is no level or bonus cap. Non-food resource capacities do not change. The resolver loads
the Nation owner's User-persistent Secretary through the current membership and rejects an
incomplete nonempty skill set. A fixture with no Secretary receives zero bonus. Credits,
overflow reporting, production, sales, aid, and rewards continue to use the canonical resolved
capacity. Any future capacity increase must be explicitly authored as a multiplicative
contract; this slice does not decide E-04 ordering for a general modifier system.

## Migration and production boundary

`hakoniwa-2s-plus-v14` has checksum
`af9afe5bf055f4d2ecc4349de058f6dfc6281194dd3d52238167ced07c9d8274`.
The forward-only migration accepts only exact published v13, refuses any globally unresolved
non-dry TurnRun, publishes v14, preserves protected User/Secretary and historical digests,
remaps queued commands and current monster references by stable keys, and changes only the
current World Ruleset reference. Fresh installation publishes only v14. Failure recovery is
restore-exact-v13-and-re-upgrade; migration `down()` is intentionally unavailable.

The PostgreSQL backup does not contain `/srv/bot-assets/hakoniwa-secretaries`. Production
backup and restore must preserve DB rows and the matching file directory at one recovery point.
This document does not authorize deployment, migration execution, production file mutation,
or an official Turn.

## Test impact

Forecast recorded before implementation:

| Metric | Forecast | Rationale |
|---|---:|---|
| New test files | 0 | Extend existing Secretary, capacity, upload-stack, migration, and UI owners. |
| New identifiers | about 10 | Representative public/profile, image/replacement/privacy, capacity, migration, and UI contracts. |
| Production World constructions | about 4 lightweight Worlds | Reuse existing lightweight factories; never construct a production-size World. |
| Official Turn executions | 1 | Prove that the exact upgraded World remains runnable. |
| Migration/fresh-install executions | 2 contract paths | Exact v13 upgrade plus current fresh-install baseline. |
| World expansions / concurrency / performance profiles | 0 | These contracts do not change. |
| Estimated runtime delta | small to medium | Database migration and one Turn dominate; UI/unit checks are small. |

Actual representative growth:

| Metric | Forecast | Actual | Reason for difference |
|---|---:|---:|---|
| New test files | 0 | 0 | Existing files own all contracts. |
| New identifiers | about 10 | 3 PHP tests plus existing UI test extensions | Related states are asserted together without a format × preference matrix. |
| Production World constructions | about 4 | 4 lightweight contract Worlds | Public, upload, capacity, and migration paths each use one representative World. |
| Official Turn executions | 1 | 1 | Exact v13-to-v14 upgrade is followed by one runnable Turn. |
| Migration/fresh-install executions | 2 | 3 contract paths | Current install, the supported historical chain, and the exact v13 forward upgrade remain separately provable; the extra chain check prevents a pre-v14 Turn. |
| World expansions / concurrency / performance profiles | 0 | 0 | No new expensive fixture. |
| Estimated runtime delta | small to medium | small to medium | The implementation stayed within the forecast. |

Excluded tests include a Cartesian image-format/preference matrix, duplicate slot suites,
unsupported historical runtime execution, and speculative capacity modifier ordering.

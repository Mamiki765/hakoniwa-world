# ver 2.2.0 Secretary inventory/equipment foundation and in-game inquiries

## Player-facing summary

Secretary now has secondary navigation for 熟練度, 装備, and 倉庫. The existing four skills, values, effects, and progression are unchanged. Every existing and future Secretary receives one 古びた弓 Lv1 in equipment slot 1. The warehouse capacity is 50 item instances and equipment has five slots.

The TOP page also provides an authenticated お問い合わせ form for バグ報告, 要望, アイデア, 秘書のファンアート, and その他. One PNG, JPEG, WebP, or GIF image up to 10MB may be attached.

## Item ownership and lifecycle

`secretary_item_instances` is User/Secretary state, not Nation state. One item instance is one row with its own primary key, `secretary_id`, stable `item_key`, `level`, nullable `equipped_slot`, nullable idempotency `grant_key`, and `obtained_at`. Nation abandonment does not delete it and re-registration does not transfer Nation resources; the same User-owned Secretary keeps the row.

The application locks the Secretary row and grants inside a transaction. It counts existing rows under that lock, refuses the 51st row without deleting anything, and records `secretary.inventory_full` as a private audit event. A partial unique index prevents two rows for one Secretary from occupying the same non-null slot. Slots are the integers 1 through 5 and have no dummy rows. `starter:old_bow` and a partial old-bow uniqueness index make the existing-Secretary migration and future Secretary creation idempotent.

The small code-side item catalog defines only identity and presentation required now: `old_bow`, category `bow`, max level 1, name, category label, and flavor text. It does not add attack probability, damage, target rules, power shot, `effect1`, a modifier engine, or balance parameters to item rows. Presentation text is outside immutable gameplay rulesets.

## Equipment boundary

The UI renders all five slots and inserts rows by `equipped_slot`. In ver 2.2.0 the bow category limit is one equipped item. 古びた弓 starts in slot 1; slots 2 through 5 are empty. There is no equip/unequip mutation UI.

An equipped item has no gameplay effect in ver 2.2.0. The implementation does not reference item rows from TurnEngine, monster, missile, damage, random-stream, retry, or turn-order code. `hakoniwa-2s-plus-v9` remains current and no published v1-v9 file or migration is changed.

## Inquiry persistence and authorization

`inquiries` stores an idempotent per-User submission UUID, server-derived User, active Nation when one exists, World, submitted World turn, player-facing application version, category, subject, plain-text body, nullable attachment token/path, and timestamps. The management identifier is the stable formatted database primary key (`INQ-000123`); it is not used in the attachment URL.

Only authenticated Users can submit and the POST route uses the existing Laravel throttle at three requests per minute per authenticated User. This caps accepted 10MB attachment traffic from one account at 30MB per minute without adding a separate image-only limiter in 2.2.0. Client-provided User, Nation, turn, or version fields are ignored. Admin reads reuse the existing configured Discord admin authorization. Non-admin APIs expose neither other Users' inquiry subjects/counts/bodies nor attachment URLs. Reply, thread, comment, mail, Discord notification, and workflow state are not implemented.

## Attachment storage and security boundary

The server accepts exactly one optional file and validates both size and server-observed MIME. Allowed MIME types are PNG, JPEG, WebP, and GIF; SVG and files that merely have an image extension are rejected. The original filename is not persisted or used in a path. A 32-byte CSPRNG value is hex encoded as the 256-bit token and combined only with a server-selected extension.

The default write path is `/srv/bot-assets/hakoniwa-inquiries`. The default Compose stack mounts a persistent named volume there and its Apache vhost serves `/hakoniwa-inquiries/` as a static, non-indexed route. `product/docker/nginx/hakoniwa-inquiries.conf` remains the checked-in location for production environments that use the external assets-nginx stack, with `autoindex off`. The admin detail response constructs the URL from `HAKONIWA_INQUIRY_ATTACHMENT_BASE_URL`; ordinary responses never contain it.

This is not private or authenticated file storage. Anyone who obtains the sufficiently long random URL can view the image. The UI therefore warns not to attach images containing personal information. Directory listing is disabled, but the URL itself is a bearer-like public locator.

File writing occurs inside the inquiry DB transaction. If writing fails, no row is committed. If the DB operation or commit fails after writing, the service deletes the just-written path before propagating the failure. The transaction closure is never automatically replayed because filesystem writes cannot roll back; a client may retry safely with the same submission UUID. A repeated submission UUID returns the existing row before writing another file.

## Upload infrastructure audit

Laravel validation alone was insufficient: the base PHP image defaults were below 10MB. The checked-in image now installs `docker/php-upload.ini` with `upload_max_filesize=10M`, `post_max_size=12M`, and one upload file. Apache applies `LimitRequestBody 12582912`, leaving multipart overhead while Laravel keeps the attachment itself at 10MB.

The repository does not contain the production Nginx Proxy Manager configuration or the active assets-nginx server block. Before deployment, the operator must verify the external proxy permits at least a 12MiB request body and install/compare the checked-in assets location with `autoindex off`. Do not infer those external settings from application validation.

The base Compose stack provides `hakoniwa_inquiry_attachments` for local/default persistence and a non-indexed Apache static route. Production may continue to map the existing bot-assets host storage to `/srv/bot-assets` (or set `HAKONIWA_INQUIRY_ATTACHMENT_PATH` to another mounted directory) and use the external assets origin through `HAKONIWA_INQUIRY_ATTACHMENT_BASE_URL`. Verify the runtime Web UID can create and remove a probe file, and that the selected asset server can read but not list the directory. The base Compose configuration does not infer or replace the separately operated production mount or assets-nginx service.

## Backup boundary

The checked-in production backup wrapper contains only PostgreSQL dump/encryption/upload. It does not include `/srv/bot-assets/hakoniwa-inquiries`. Therefore the inquiry DB rows can be restored while their attachments cannot be restored unless the operator adds and verifies a separate off-host backup for this directory. This release does not broaden the existing database-backup framework.

Before accepting production image uploads, record either a tested independent backup/restore procedure for the attachment directory or an explicit acceptance that attachment recovery is unavailable. Do not describe the current DB backup as covering attachments.

## Pre-deployment operator checklist

1. Keep player writes and the turn cron paused, verify the reviewed release SHA, a clean tree, current ruleset v9, and no next-turn non-dry `pending`, `running`, `failed`, or `blocked` TurnRun.
2. Run and verify the existing encrypted off-host PostgreSQL backup. Separately resolve the attachment backup decision above.
3. Configure the persistent writable bot-assets mount and both inquiry environment variables. Do not expose `_references` or credentials.
4. Verify PHP reports 10M/12M, Apache is using the checked-in request limit, and the external proxy accepts a real 10MB multipart request while rejecting an attachment above 10MB at the application boundary.
5. Install/verify the assets-nginx location, `autoindex off`, read access, and an unlistable 256-bit-token URL.
6. Run the forward migration once while writes remain paused. Verify every Secretary has exactly one `starter:old_bow`, inventory counts do not exceed 50, slot uniqueness holds, and inquiry tables are empty unless deliberately smoke-tested.
7. Rebuild the Web image, refresh config cache, and smoke-test Secretary tabs, one no-image inquiry, one disposable image inquiry, non-admin denial, admin latest five/index/detail, and cleanup of the disposable row/file under an approved non-production or explicitly approved production procedure.
8. Confirm v1-v9 checksums, current v9, turn/random/order regression tests, and normal preflight before separately authorizing resume. This document does not authorize deploy, migration execution, production connection, or resume.

## Explicitly future only

Item gameplay effects, 古びた弓 attack behavior, mechanical bows, power shot, item leveling, gifts, auctions, item/resource trading, multiple-bow builds, and generic modifiers are not designed or implemented here.

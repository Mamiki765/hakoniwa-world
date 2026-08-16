# ver 1.7.0 production release record

This document is a historical production release record. It does not define gameplay or replace ADRs, rulesets, open questions, or runbooks.

**Status: Postflight partially recorded**

## Release identity

- Release: ver 1.7.0
- Pull request: [#55](https://github.com/Mamiki765/hakoniwa-world/pull/55)
- Final PR head: `647bd790660cb826c7d86bfcfd54d8cd9b1066cb`
- Merge commit: `02d71b03e9e59e8bc750744a05321e5dfa6a5043`

## Verified from the repository and owner-provided production results

- Production ruleset: `hakoniwa-2s-plus-v6`, `ruleset_version_id=21`
- The v5-to-v6 forward migration completed.
- The reviewed queue override was supplied for one migration invocation only: `HAKONIWA_V6_REBIND_REVIEWED_QUEUE_ITEMS=CONFIRM_REVIEWED_V5_QUEUE_ITEMS_TO_V6`.
- Published v1-v5 rulesets remained unchanged.
- The first official post-migration turn completed at 2026-08-16 14:00 JST: TurnRun 128, target turn 128, source `cron`, non-dry, attempt 1, status `completed`, ruleset `hakoniwa-2s-plus-v6`, `ruleset_version_id=21`. The World advanced to turn 128 with no failure.
- The immediately preceding production run 127 used `ruleset_version_id=19`; run 128 used `ruleset_version_id=21`. This confirms that run 128 was the first official cron turn after the v6 migration and that it completed successfully.

## Not recorded / not independently verified here

- Completion details for the authenticated production UI/API smoke checks are not present in the evidence used for this record.
- The persistent production web process's config-cache state and postflight environment check are not independently recorded here.

No production connection or production write was made to prepare this record. The operative migration procedure remains [ver-1.7.0-v6-ruleset-migration.md](ver-1.7.0-v6-ruleset-migration.md).

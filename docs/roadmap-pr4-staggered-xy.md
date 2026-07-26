# Roadmap PR #4: staggered x/y coordinate migration

## Goal

axial 正本から、旧式箱庭諸島に近い staggered square-tile x/y 正本へ breaking migration する。世界を論理的な60×60長方形にし、偶数行だけ16px右へずらす。

## Included

- safe forward / rollback migration と既存 data backfill
- map space、cell、chunk、capital、nation creation、command queue、audit の x/y 化
- 6近傍、距離、radius、chunk 算術
- version 3 world generator と version 2 initial island generator
- API resource、validation、route parameter、Vue type/state/projection/labels
- safe world reset command
- backend、frontend、migration、reset、visual test
- architecture、operations、open questions、test plan 更新

## Breaking transition

古い API field との長期互換は提供しない。migration 直後の既存 world は data 保持用の暫定外形である。production では backup、migration、dry run、exact confirmation 付き reset、新 Backend と新 Frontend の同時 rollout の順に進める。

## Explicitly excluded

turn runner、command execution、production、workforce、food、population change、auto sale、disaster、monster、missile、combat、scheduler、OCI deploy。

## Completion gates

- migration up/down/up が users、identities、world、nation、capital、cell IDs を保持
- 3,600 unique x/y cells、60 rows×60
- even/odd neighbors、bounds、distance、chunk/local arithmetic
- initial island と capital separation
- command target x/y と viewer secrecy
- reset dry-run、isolated reset、retention、failure rollback
- typecheck、lint、unit tests、production build
- Browser QA で square tiles、no parallelogram、no drift、no console errors

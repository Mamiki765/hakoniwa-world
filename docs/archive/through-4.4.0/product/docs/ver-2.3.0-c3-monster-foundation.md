# ver 2.3.0 C3 monster extension foundation

## Scope

C3 adds the schema and runtime contracts needed for a ruleset to contain more than the eight historical monster species. It does not publish a formal v11 ruleset, rebind a World, create either custom GIF, or implement the C4 spawn, movement, dispatch, or self-destruction behavior.

The shared test-only `V11SecretaryItemRulesetFixture` is inactive and contains ten definitions so C2 Item behavior and C3 monster authoring are exercised together. The repository's existing stable key `whale` remains unchanged; its player-facing name and asset identity remain クジラ and `hakoniwa_original.monster.kujira`.

## Display order and schema

`monster_definitions.display_order` is nullable for historical compatibility, constrained to non-negative integers, and unique per ruleset when non-null. The migration does not update existing rows and is forward-only.

The effective order resolver uses an explicit non-negative PostgreSQL integer value when present and rejects values above `2,147,483,647` before publication. A historical null falls back to the audited `source_metadata.kind * 100`, accepting only integer kinds 0 through 7. Duplicate effective orders fail closed. The resolver performs no database query and is shared by public Nation detail and ranking achievements.

`RulesetPublisher` checks the column capability once per publish. Historical migrations that execute the mutable publisher before the C3 migration omit the unavailable field. Once the column exists, historical snapshots persist and compare null, while explicitly ordered definitions persist their authored value. Published v1-v10 settings and rows are not rewritten.

## Authoring and natural spawn

The extended monster catalog is available only when validated ruleset identity and version both name v11. A v1-v10/roadmap identity remains on the exact historical eight contract even if a payload attempts to add `display_order`; a v11 identity requires every monster definition to provide a unique order and an explicit valid reward policy. The one inactive C2/C3 test fixture explicitly inherits already-decided v10 non-monster contracts while exercising the v11 monster shape; this does not publish a formal v11 or decide B-12. Historical definitions retain their audited source metadata; new definitions must not invent legacy kind, skill-code, or filename provenance. The shared C2 `secretary_item_target_safety` metadata is validated through its runtime policy and remains independent of JSON object key order.

Natural-spawn pools are validated against the loaded catalog before any gameplay draw. Unknown and duplicate pool references fail closed. Merely adding a definition does not add it to a pool: メカいのら零式 and あおいのら remain outside every Nation natural-spawn pool in the C3 fixture. There is no maximum definition count.

## Public projections

Public Nation detail returns every positive species aggregate, sorted by effective display order, and totals every returned count. Ranking achievements use the same ordering and choose the killed species with the greatest effective display order as the representative asset. Awards are unchanged. Both projections eager-load definitions in one bounded relation query, avoiding per-species queries and cross-ruleset data.

Measured fixtures with ten and twenty killed species keep both public Nation detail and ranking at one kill-stat query plus one eager definition query. The ranking bound is unchanged with two Nations sharing ten species each. Zero-stat reads perform one bounded aggregate lookup and do not create a definition N+1. Natural spawn likewise consumes one trigger and one type draw for a successful Nation whether the loaded catalog has eight, ten, or twenty definitions, provided the ruleset-authored pool is unchanged.

The frontend renders dynamic species lists. Ranking tooltips are viewport-bounded and scroll when a long list needs it; the public Nation preview wraps every returned mark. Missing assets retain the existing accessible text/CSS fallback.

## Assets and rewards

The manifest adds only these identities; no binary is stored in the repository:

- `hakoniwa_custom.monster.aoi_inora` -> `monster-aoi-inora.gif`
- `hakoniwa_custom.monster.mecha_inora_zero` -> `monster-mecha-inora-zero.gif`

The reward resolver reads the exact TurnRun ruleset definition. An absent policy preserves the v1-v10 standard split. `standard_split` makes that behavior explicit. `hostless_full_killer_money` gives an attributed killer the whole wreckage money only when no host Nation exists; an existing host still uses the standard split. A missing killer receives no reward. Unknown definitions and malformed policies fail closed.

`MonsterDamageService` remains the only ordinary damage/reward integration point. Money and food capacities, kill statistics, firing-base experience, atomic rollback/retry, and rewardless removal paths keep their existing services and semantics. Policy-specific event fields appear only when the ruleset authored a policy, preserving the legacy event shape otherwise.

## Player manual and next boundaries

The advanced manual contains ten rows in display order. A contract test derives the expected rows from the shared fixture, including HP, appearance, wreckage value, missile-base experience, and player-facing ability text.

C4 may implement the approved Aoi/Zero gameplay using these identities and contracts. C5 alone owns the formal v11 config, publication migration, World rebind, and any live data conversion. C3 performs none of those operations.

## Verification snapshot

The C3 implementation was verified on the isolated local `hakoniwa_test` database. `migrate:fresh` completed the entire historical migration chain through v10 and then added the nullable C3 column. Public Nation detail and ranking each used one monster-stat query plus one eager definition query for both ten- and twenty-species fixtures. The 16-shard plan covered 108 test files with no duplicates or omissions, and every shard passed; the final self-audit additions then passed as a focused 38-test, 483-assertion run. Frontend tests, lint, typecheck, production build, full-app PHPStan, Pint, and the open-question contract validator also passed.

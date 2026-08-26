# Ruleset Core, Balance, and Flavor boundaries

> Historical 2.3.1 responsibility map. Current v16 authoring is governed by
> [`ruleset-authoring.md`](ruleset-authoring.md): Core maps to **behavior**, Versioned Balance
> maps to **data**, and Flavor / Presentation maps to **flavor**. These historical labels do
> not create a fourth current classification, and the deferred physical-split decision below
> was superseded by the checksum-preserving v16 domain-first composition.

この文書は2.3.1当時のcurrent v11 payloadのfield responsibility mapである。現在の
contractではなく、上記のv16文書を参照すること。2.3.1では物理splitを実装せず、
historical authored payloadとchecksumを一切変更しない。分類は当時のreview境界であり、
別fileや別tableがすでに存在するという意味ではない。

## Layer definitions

### Ruleset Core

Replay、stable identity、処理順、入力shapeを決める構造である。例:

- Ruleset key/version and migration identity
- phase/stage ordering such as `turn_resolution.normal_monster_stage`
- stable resource/facility/command/monster keys and cross references
- command `target_type`, execution phase, parameter/selector structure and policy type
- Item action/effect type, timing, target scope and stacking policy
- monster behavior type, reward policy type and movement contract shape
- RNG stream identity/version and snapshot semantics

Coreを変えると、数値が同じでも同じTurnRun replayを保証できない可能性がある。新しいimmutable Ruleset version、migration review、exact retry policyが必要である。

### Versioned Balance

Core shapeの中でgameplay resultの量を決める値である。例:

- disaster and spawn probabilities
- monster HP, variation, movement limits, wreckage value and XP
- command costs, sale prices and transfer rates
- production rates, workforce, initial balances and capacities
- population thresholds/growth/damage and resource nutrition
- Secretary Item chance, damage and per-level finance bonus

Balance変更はplayer-visible gameplay変更であり、既存versionを上書きせず新しいimmutable versionとしてpublishする。

### Flavor / Presentation

gameplayが分岐してはならない人間向け情報である。例:

- names, descriptions, labels and safe alternative text
- Item flavor and effect prose derived for display
- manual prose, credits and source acknowledgements
- presentation-only sort labels when gameplay identity is a separate stable key

v11ではこれらの一部も同じpayload checksumに含まれる。`Flavor`分類だけを理由にv11から移動または書換えしてはならない。

## Current field map

| Field group | Layer | Current source | Checksum impact now | Runtime reader | Future target | Move safety | TurnRun must reference version? |
|---|---|---|---|---|---|---|---|
| `key`, `version` | Core | top-level authored payload | yes | publisher, current guard, migrations, API/audit | same immutable Ruleset identity | never move from an existing version | yes |
| chunk/bounds/initial-island coordinate structure | Core plus Balance radii | top-level | yes | World generation, Nation creation, expansion | core profile with versioned numeric bounds | unsafe without generation/live-World migration proof | yes for any operation that can replay generation decisions |
| stable definition keys and cross references | Core | `resource_definitions`, `facility_definitions`, `command_definitions`, `production_definitions`, `monster_definitions` | yes | publisher, command/map/turn services, historical presentation | one reviewed keyed catalog per Ruleset version | unsafe to split if it creates a second source or lookup | yes |
| command target/execution/parameter/selector/policy shape | Core | `command_definitions[*]` | yes | queue registration, fingerprint/provenance, execution | typed publication result or generated immutable snapshot | move only if canonical request bytes and historical fallback remain exact | yes |
| phase and stage order | Core | `turn_processing`, `turn_resolution` | yes | `CompleteTurnEngine`, disaster/missile/monster services | explicit typed turn plan built once from exact Ruleset | may resolve at prepare; never change order in place | yes |
| RNG stream version and population contracts | Core | disaster, monster, Item effect settings plus `TurnRandomStreamFactory` labels | yes for authored versions | turn services and random stream factory | one typed per-turn random plan, labels remain code-reviewed | unsafe if a draw appears/disappears or label/version changes | yes |
| snapshot semantics | Core | Secretary/Item/definition settings plus TurnRun/TurnState code | yes where authored | prepare phase, execution consumers, retry | typed attempt snapshot with narrow integrity guards | safe only when same snapshot bytes/meaning and retry behavior are proved | yes |
| probabilities | Balance | `turn_processing.disasters`, monster/world spawn, Item effect chance | yes | disaster, monster spawn, Old Bow | versioned balance profile inside exact Ruleset | requires new Ruleset; never external unversioned config | yes |
| monster HP/value/XP/movement numbers | Balance | `monster_definitions` and `monster_system` | yes | spawn, damage, reward, movement | versioned monster balance keyed by stable identity | new Ruleset and live-reference conversion review | yes |
| prices/costs/sale rates | Balance | commands, resource prices, inventory sale rates | yes | queue validation, execution, sales | versioned economy balance | new Ruleset; queued request provenance must be preserved | yes |
| production/workforce/capacities | Balance | production definitions, capacities, initial resources, overflow | yes | economy, sales, capacity enforcement, World generation | versioned economy balance | new Ruleset and existing data compatibility review | yes |
| Secretary Item numerical effects | Balance | `secretary.items[*].effects` | yes | prepare snapshot, Old Bow/Ring | versioned Item balance under stable effect types | new Ruleset; retry stream and finance ordering proof required | yes |
| definition `name`, `description`, parameter labels | Flavor / Presentation | authored definition maps | yes in v11 | APIs, command UI, event/manual projection | future presentation catalog keyed by immutable stable key, only after evidence | currently unsafe to move: payload/checksum and historical rendering depend on it | historical presentation must reference the appropriate version or frozen text |
| Item names/flavor/effect text | Flavor / Presentation with derived balance sentence | global Item catalog plus Ruleset effect reader | catalog text may be outside Ruleset; effect values are inside | Secretary API/UI/manual | stable presentation catalog plus text derived from versioned values | keep derived prose separate from gameplay branching; no v11 rewrite | effect sentence requires version context |
| credits/manual/alt text | Flavor / Presentation | docs and frontend/catalog metadata | usually no Ruleset impact unless authored in payload | docs/UI/assets | presentation-owned source | move only with asset/license and historical display review | normally no, unless rendering a historical definition |
| display order | presentation ordering contract | nullable definition column / authored v11 field | yes when authored | public monster projections | versioned presentation ordering keyed by definition | keep historical null fallback and current uniqueness | historical definition context is required |

## Publication and runtime trust boundary

The intended flow is:

```text
explicit authoring source
  -> complete publication validation and immutable checksum
  -> TurnRun preparation / locked typed snapshot
  -> hot path consumes resolved values
```

Runtime still validates external selectors, authorization, mutable DB amounts/state, ownership, lock/version state, occupancy, target safety, request provenance, retry identity and database integrity. A snapshot boundary may replace repeated parsing of the same immutable payload; it does not make corrupt DB state acceptable.

## Physical split decision for 2.3.1

A physical `core.php` / `balance.php` / `flavor.php` split is deferred. Current v1-v11 files are complete immutable payloads, v11 checksum must not change, and composing new partial files would either create a duplicate source of truth or add lookup/composition depth without simplifying runtime. No unused `balance.php` or `flavor.php` is created.

A future split is admissible only for a newly approved version when one authoring path composes a single deterministic payload, validation occurs before publication, TurnRun references the exact resulting version, and code/tests become measurably simpler. It cannot rewrite historical sources or published rows.

## Review questions for future changes

1. Does the field change replay structure, random stream population, selector/request bytes, or phase order? If yes, treat it as Core.
2. Does it change a numerical gameplay outcome? If yes, treat it as Versioned Balance.
3. Can runtime behavior remain identical if the text changes? If no, it is not pure Flavor.
4. Which historical TurnRun, queue, definition, event or presentation row retains the old value?
5. Is there one canonical authoring source and one publication checksum, with no runtime lookup added?

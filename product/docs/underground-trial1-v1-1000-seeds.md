# Trial 1 balance simulation v1

## Status and source

This is a simulation-only foundation for Owner review. It does not release Trial 1 to players, change persistence, grant SP, add awakening, change Item Lv progression, or modify the Surface Ruleset.

- Source commit: `ba98e7fd4ebca3e2ded77a617e1309732718101e`
- Manifest: `config/underground/balance/trial1-v1.json`
- Manifest SHA-256: `75e32b55fb70720ac9267f5f0ca5bbfde754dca870d58be60d145596cda8f35c`
- Seeds: `0..999` for every scenario
- Machine-readable report: [underground-trial1-v1-1000-seeds.json](underground-trial1-v1-1000-seeds.json)
- Contract result: passed; abnormal/invalid results 0; battle count, defeat-stop, and HP-cap violations 0

The current canonical combat contract starts every battle at 10,000 MP and recovers 300 MP per round. MP does not persist between battles. HP does persist, followed by the configured between-battle recovery capped at max HP. A 100-round stalemate remains the current withdrawal result and therefore fails the Trial.

## 1. Provisional ten-battle sequence

| # | Enemy | Main pressure | HP | Physical / magical defense | Weapon power |
|---:|---|---|---:|---:|---:|
| 1 | 試練の地底鼠 | Fast ordinary physical opener | 700 | 70 / 65 | 82 |
| 2 | 洞窟狩人 | Stronger physical attack | 850 | 80 / 75 | 92 |
| 3 | 腐食鎧兵 | Armor break | 1,000 | 120 / 85 | 95 |
| 4 | 再生する巨躯 | Sustained pressure and 0.25% max-HP regeneration | 1,150 | 95 / 85 | 102 |
| 5 | 晶術士 | Miracle pressure | 1,000 | 75 / 120 | 95 |
| 6 | 狂信隊長 | Telegraph followed by a heavy strike | 1,200 | 100 / 85 | 95 |
| 7 | 刃翼蝙蝠 | Three-hit physical attack | 1,050 | 80 / 75 | 100 |
| 8 | 灰晶騎士 | Physical attack mixed with miracle bursts | 1,400 | 120 / 110 | 100 |
| 9 | 門衛石像 | Defense/DPS check and 0.10% max-HP regeneration | 1,200 | 125 / 115 | 100 |
| 10 | ワイバーン | Boss; telegraph, breath, tail bleed, multi-hit pressure, regeneration | 1,600 | 60 / 300 | 180 |

Battles 1–9 are authored only for simulation and are not a proposal to register all of these as final production enemies.

## 2. Provisional Wyvern

The Wyvern has 1,600 HP, 60 physical defense, 300 magical defense, 180 weapon power, 1% damage reduction, and 1.10% max-HP regeneration per round. Its normal physical pressure is supplemented by:

- a telegraph every five rounds followed by a defendable breath;
- a tail attack every three rounds that can apply the canonical stacking bleed;
- a three-hit wing/claw pattern between larger attacks; and
- regeneration that makes low-DPS combat drift toward the canonical 100-round withdrawal.

This creates a recognizable first boss without a separate boss engine. The high number of Blessing withdrawals described below is the main reason these exact boss values should remain provisional.

## 3. Clear rate by checkpoint and build

All rows use Rank 3 Shop equipment, a legal current STP entitlement, the current initial 20 SP limit, no awakening, no enchant, and the formal weapon/armor/accessory slots. The primary between-battle recovery is 20% max HP.

| Growth build | Lv25 | Lv30 | Lv35 | Lv30 interpretation |
|---|---:|---:|---:|---|
| 戦技（赤） | 0.0% | 32.7% | 100.0% | Lower edge of the target band |
| 護身（青） | 0.0% | 43.6% | 100.0% | In the target band |
| 祝福（緑） | 0.0% | 40.0% | 100.0% | In the target band |
| 自由（黒） | 0.0% | 33.5% | 98.4% | Lower edge of the target band |

The intended progression curve is present for every build: Lv25 is clearly too early, Lv30 is a close attempt, and Lv35 is clearly easier. The 30–70% range is an observation target, not an acceptance gate.

## 4. Where builds fail

At Lv30 and 20% recovery, all four builds reached battle 10 in all 1,000 seeds. Every failure occurred against the Wyvern:

| Build | Clears | Battle 10 defeats | Battle 10 withdrawals |
|---|---:|---:|---:|
| 戦技 | 327 | 673 | 0 |
| 護身 | 436 | 564 | 0 |
| 祝福 | 400 | 1 | 599 |
| 自由 | 335 | 665 | 0 |

At Lv25, 戦技 also failed at battle 8 in 4 seeds and battle 9 in 154 seeds; the other three builds reached the boss in all seeds. At Lv35, only 自由 still failed, with 16 battle-10 defeats.

The early sequence therefore preserves meaningful HP attrition, but the final candidate is primarily boss-gated at the requested Lv30 checkpoint.

## 5. Blessing advantage

Blessing did not become the dominant build: its Lv30 clear rate was 40.0%, compared with 43.6% for Guardianship, 33.5% for Free, and 32.7% for Martial. It produced 3,089.991 average in-combat healing per Trial and 1,000 battle-level MP exhaustion observations across 1,000 Trials, while the other builds recorded no MP exhaustion.

Its stability appears as withdrawal rather than clear rate: 599 of 1,000 Lv30 seeds sustained combat to round 100. This is canonical behavior, but it is a strong play-feel warning. Before these exact enemy values become production content, the Owner should decide whether that many long Blessing attempts are acceptable or whether the final boss should convert more of those seeds into earlier wins or defeats without adding anti-heal or hidden build handicaps.

## 6. Recovery comparison at Lv30

| Build | 10% | 20% | 30% |
|---|---:|---:|---:|
| 戦技 | 0.0% | 32.7% | 99.8% |
| 護身 | 41.6% | 43.6% | 43.6% |
| 祝福 | 20.6% | 40.0% | 44.5% |
| 自由 | 9.2% | 33.5% | 82.9% |

The Guardianship result barely changes because its defense often caps HP before the next battle. Blessing gains less from 30% because in-combat healing already provides attrition control. Martial and Free are very sensitive to common recovery because they carry more unrecovered damage into the late sequence.

## 7. Recommended recovery

Keep 20% max HP as the next simulation and implementation candidate.

- 10% makes Martial and Free effectively non-viable at Lv30.
- 30% removes most of the intended attrition for Martial and pushes Free well above the target band.
- 20% keeps all four builds in the 30–70% observation band without a build-specific recovery rule.

## 8. Owner intent assessment

The final candidate meets the numerical intent of “barely clearable around Lv30 with Rank 3” for all four representative builds and shows a strong level curve. It also reuses the same content and canonical combat path for every build.

The caveat is qualitative: Blessing failures are predominantly 100-round withdrawals. The simulation foundation is ready for Owner comparison, but these exact Wyvern regeneration/defense values should not be treated as approved final content until that play-feel tradeoff is reviewed.

## 9. Parameters adjusted during tuning

Only simulation inputs were tuned:

- enemy HP, physical/magical defense, weapon power, and attack potency;
- the order and mix of physical, miracle, armor-break, telegraph, multi-hit, and regeneration pressure;
- Wyvern HP, split defenses, weapon power, damage reduction, regeneration, and action cadence;
- representative legal STP allocations and initial-20-SP loadouts for the four simulation builds; and
- the compared between-battle recovery rates of 10%, 20%, and 30%.

The player growth formula, STP entitlement, Skill Tree costs/effects, Rank 3 equipment definitions, equipment slots, canonical RNG, combat transaction path, MP contract, persistence, application version, and Surface Ruleset were not changed.

## 10. Minimum next scope for a formal Trial 1

After Owner review, the smallest follow-up should be split into reviewable steps:

1. approve or retune the final ten-enemy content and specifically resolve the Blessing withdrawal profile;
2. author a versioned Trial 1 runtime definition that reuses the existing canonical combat and Trial state machine;
3. implement server-authoritative ten-battle progression with HP carry, 20% capped recovery, per-battle canonical MP reset, defeat/withdrawal stop, deterministic retry identity, and focused production-reachable tests; and
4. add only the minimal attempt/clear persistence and player-facing entry/result flow explicitly approved for that slice.

SP +40, awakening, the post-clear story, second hunting ground, enchanted drops, Item Lv progression, underground map/facilities, pipes, and Trial 2 remain separate later scopes.

## Test impact forecast versus actual

| Metric | Forecast | Actual | Reason for difference |
|---|---:|---:|---|
| New test files | 0 | 0 | Extended the existing representative simulator test |
| New test identifiers | about 2 | 2 | Deterministic ten-battle/cap coverage and defeat-stop coverage |
| Fresh DB / migration executions | 0 | 0 | Pure simulation; no persistence path |
| Production World constructions | 0 | 0 | Underground-only pure simulation |
| Official Turn executions | 0 | 0 | No Surface/Turn dependency |
| Heavy simulation in CI | 0 | 0 | CI smoke uses two seeds; the 1,000-seed matrix is manual only |
| Focused-test runtime delta | small | small | The full representative test file completed in about 7 seconds locally |

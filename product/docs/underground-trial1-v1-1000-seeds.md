# Trial 1 balance simulation v1

## Status and source

This is a simulation-only foundation for Owner review. It does not release Trial 1 to players, change persistence, grant SP, add awakening, change Item Lv progression, or modify the Surface Ruleset.

- Source commit: `5ac91899f19655685b64107f4a62deb11358c9c2`
- Manifest: `config/underground/balance/trial1-v1.json`
- Manifest SHA-256: `6c65f49c9eb8008c1b2ce9fc36ba5ed9501a4b3c423679ba643209c025d598ff`
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
| 10 | ワイバーン | Boss; telegraph, breath, tail bleed, multi-hit pressure, regeneration, round-40 flight | 1,600 | 60 / 229 | 180 |

Battles 1–9 are authored only for simulation and are not a proposal to register all of these as final production enemies.

## 2. Provisional Wyvern

The Wyvern has 1,600 HP, 60 physical defense, 229 magical defense, 180 weapon power, 1% damage reduction, and 1.10% max-HP regeneration per round. Its normal physical pressure is supplemented by:

- a telegraph every five rounds followed by a defendable breath;
- a tail attack every three rounds that can apply the canonical stacking bleed;
- a three-hit wing/claw pattern between larger attacks; and
- regeneration that makes low-DPS combat drift toward the canonical 100-round withdrawal; and
- at the start of round 40, `天井が崩落し、ワイバーンは宙に舞い上がる……！` is emitted and the Wyvern gains `飛翔`, doubling all outgoing damage for the remainder of the battle.

The transition reuses the canonical status and damage-modifier path. It does not consume the Wyvern's action or an RNG draw, so the normal round-40 action still occurs. This keeps the mechanic deterministic and localized without a separate boss engine or build-specific anti-heal rule.

## 3. Clear rate by checkpoint and build

All rows use Rank 3 Shop equipment, a legal current STP entitlement, the current initial 20 SP limit, no awakening, no enchant, and the formal weapon/armor/accessory slots. The primary between-battle recovery is 20% max HP.

| Growth build | Lv25 | Lv30 | Lv35 | Lv30 interpretation |
|---|---:|---:|---:|---|
| 戦技（赤） | 0.0% | 32.7% | 100.0% | Lower edge of the target band |
| 護身（青） | 0.0% | 43.6% | 100.0% | In the target band |
| 祝福（緑） | 0.0% | 47.6% | 99.9% | Highest, but only 4.0 points above Guardianship |
| 自由（黒） | 0.0% | 33.5% | 98.4% | Lower edge of the target band |

The intended progression curve is present for every build: Lv25 is clearly too early, Lv30 is a close attempt, and Lv35 is clearly easier. The 30–70% range is an observation target, not an acceptance gate.

## 4. Where builds fail

At Lv30 and 20% recovery, all four builds reached battle 10 in all 1,000 seeds. Every failure occurred against the Wyvern:

| Build | Clears | Battle 10 defeats | Battle 10 withdrawals |
|---|---:|---:|---:|
| 戦技 | 327 | 673 | 0 |
| 護身 | 436 | 564 | 0 |
| 祝福 | 476 | 524 | 0 |
| 自由 | 335 | 665 | 0 |

At Lv25, 戦技 also failed at battle 8 in 4 seeds and battle 9 in 154 seeds; the other three builds reached the boss in all seeds. At Lv35, 祝福 had 1 battle-10 defeat and 自由 had 16.

The early sequence therefore preserves meaningful HP attrition, but the final candidate is primarily boss-gated at the requested Lv30 checkpoint.

## 5. Tuning evidence

The magical-defense search changed no other combat input. In the 300-seed Lv30 / Rank 3 / 20% recovery comparison without the flight phase, Blessing cleared all 300 seeds at each candidate and averaged 43.003 boss rounds at magical defense 225, 43.620 at 227, and 44.270 at 229. Magical defense 229 was selected because it sits near the center of the requested 43–45-round range while removing the conspicuous 300-point dedicated wall.

The round-40 damage bonus was then compared over 200 common seeds. A +25% bonus left Blessing at 94.0% clear with 9 withdrawals; +50% produced 76.0%; +75% produced 57.0% with 1 withdrawal; and +100% produced 47.5% with no withdrawal. The +100% candidate was selected because it was the only tested value that made Blessing only slightly stronger than the next build rather than dominant.

The selected candidate remained stable in the 300-seed confirmation: Blessing cleared 146, was defeated in 154, withdrew in 0, averaged 43.893 boss rounds, and triggered the transition 265 times. Guardianship was next at 124 clears (41.33%).

## 6. Blessing advantage and healer pressure

In the final 1,000-seed run, Blessing cleared 47.6%, compared with 43.6% for Guardianship, 33.5% for Free, and 32.7% for Martial. The requested advantage is therefore 4.0 percentage points over the next build. Blessing produced 1,138.221 average in-combat healing per Trial and 997 battle-level MP exhaustion observations.

The old 599/1,000 round-100 withdrawals became 0/1,000. The flight transition occurred in 892 Blessing trials and converted the low-damage long-fight tail into 524 defeats while preserving 476 clears. Martial and Guardianship never reached round 40; Free reached it in 224 trials but retained its prior 33.5% clear rate. The pressure is therefore tied to battle duration, not to a hidden healer identity check.

## 7. Recovery comparison at Lv30

| Build | 10% | 20% | 30% |
|---|---:|---:|---:|
| 戦技 | 0.0% | 32.7% | 99.8% |
| 護身 | 41.6% | 43.6% | 43.6% |
| 祝福 | 15.0% | 47.6% | 53.2% |
| 自由 | 9.2% | 33.5% | 82.9% |

The Guardianship result barely changes because its defense often caps HP before the next battle. Blessing gains less from 30% because in-combat healing already provides attrition control. Martial and Free are very sensitive to common recovery because they carry more unrecovered damage into the late sequence.

## 8. Recommended recovery

Keep 20% max HP as the next simulation and implementation candidate.

- 10% makes Martial and Free effectively non-viable at Lv30.
- 30% removes most of the intended attrition for Martial and pushes Free well above the target band.
- 20% keeps all four builds in the 30–70% observation band without a build-specific recovery rule.

## 9. Owner intent assessment

The final candidate meets the numerical intent of “barely clearable around Lv30 with Rank 3” for all four representative builds and shows a strong level curve. Blessing is the highest-clear build by a narrow margin, and its old withdrawal tail has been replaced with deterministic long-fight pressure. The same content, canonical combat path, and SP20 contract remain in force for every build.

This approves a simulation candidate only. It does not authorize the larger Trial 1 runtime, persistence, rewards, story, or player-facing release.

## 10. Parameters adjusted during tuning

Only simulation inputs were tuned:

- enemy HP, physical/magical defense, weapon power, and attack potency;
- the order and mix of physical, miracle, armor-break, telegraph, multi-hit, and regeneration pressure;
- Wyvern HP, split defenses, weapon power, damage reduction, regeneration, action cadence, and the round-40 flight damage bonus;
- representative legal STP allocations and initial-20-SP loadouts for the four simulation builds; and
- the compared between-battle recovery rates of 10%, 20%, and 30%.

The player growth formula, STP entitlement, Skill Tree costs/effects, Rank 3 equipment definitions, equipment slots, canonical RNG, combat transaction path, MP contract, persistence, application version, and Surface Ruleset were not changed.

## 11. Minimum next scope for a formal Trial 1

After Owner review, the smallest follow-up should be split into reviewable steps:

1. approve or retune the remaining ten-enemy content using this resolved Wyvern/Blessing candidate as evidence;
2. author a versioned Trial 1 runtime definition that reuses the existing canonical combat and Trial state machine;
3. implement server-authoritative ten-battle progression with HP carry, 20% capped recovery, per-battle canonical MP reset, defeat/withdrawal stop, deterministic retry identity, and focused production-reachable tests; and
4. add only the minimal attempt/clear persistence and player-facing entry/result flow explicitly approved for that slice.

SP +40, awakening, the post-clear story, second hunting ground, enchanted drops, Item Lv progression, underground map/facilities, pipes, and Trial 2 remain separate later scopes.

## Test impact forecast versus actual

| Metric | Forecast | Actual | Reason for difference |
|---|---:|---:|---|
| New test files | 0 | 0 | Extended the existing representative simulator test |
| New test identifiers | about 2 | 1 | One representative test covers timing, exact presentation text, status application, and the preserved round-40 enemy action |
| Fresh DB / migration executions | 0 | 0 | Pure simulation; no persistence path |
| Production World constructions | 0 | 0 | Underground-only pure simulation |
| Official Turn executions | 0 | 0 | No Surface/Turn dependency |
| Heavy simulation in CI | 0 | 0 | CI smoke uses two seeds; the 1,000-seed matrix is manual only |
| Focused-test runtime delta | small | small | The 12-test representative file completed in 7.63 seconds locally |

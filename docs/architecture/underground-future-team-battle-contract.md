# Underground future team battle compatibility contract

Status: CURRENT AUTHORITY (task-specific compatibility boundary)

This document does not approve or specify a team battle implementation. The supported
Underground runtime remains one player actor and one enemy actor. It records only the target
meaning that the current Awakening effects must preserve if a later Owner-approved change
adds multiple actors. UG-04 and the other design gates remain in force.

## Semantic targets

- `self`: the effect source. In the current runtime this is the Secretary.
- `primary_enemy`: the enemy selected as the attack's main target. In the current runtime
  this is the sole enemy.
- `other_enemies`: enemy actors other than `primary_enemy`. This is an empty set in the
  current runtime; this PR does not construct it.
- `all_allies`: every actor on the source's side, including the source. In the current solo
  runtime this is `[Secretary]`.
- `defeated_allies`: allied actors that are battle-defeated and eligible for revival. This is
  an empty set in the current runtime; this PR adds no party death lifecycle or persistence.

Direct references to the Secretary and current enemy in today's 1v1 implementation are an
implementation result, not a narrower semantic contract.

## Awakening Techniques

| Growth path | Technique | Current 1v1 result | Future target meaning |
|---|---|---|---|
| Martial | 天断一閃 | Deals 100% authored burst to the current enemy. | `primary_enemy` receives 100%. `other_enemies` receive a secondary echo in the Owner-directed 50–75% range; its exact coefficient remains for the future multi-enemy balance change. The purpose remains maximum single-target boss burst, not full-area clearing. |
| Guardianship | 絶対護界 | The Secretary receives 90% direct-damage reduction for two rounds after activation. Duration advances once at round end, never once per enemy action. | Apply the same protection to `all_allies` for those two rounds; enemy count and actions per round must not shorten it. Do not redefine it as self-only. |
| Blessing | 生命讃歌 | The living Secretary returns to maximum HP. There is no revivable party actor. | Fully heal `all_allies`; revive every `defeated_allies` actor into battle at maximum HP. |
| Free | 無窮再演 | Restore the Secretary's MP and clear the Secretary's ordinary active-skill cooldowns. | Remains `self` only. It never restores an ally's MP or cooldowns. |

The current code intentionally does not add a Party class, team aggregate, party table,
actor collection conversion, target-selector DSL, revival persistence, ally identifiers,
formation UI, or generic multi-target skill framework.

## Checklist for a future multi-actor PR

1. Audit existing effects against `self`, `primary_enemy`, `other_enemies`, `all_allies`, and
   `defeated_allies`.
2. Search for effects directly bound to the Secretary or current enemy by the solo runtime.
3. Extend Guardianship Awakening protection to `all_allies` for the same two-round duration,
   advancing duration once per round regardless of enemy count or action count.
4. Extend Blessing Awakening to heal `all_allies` and revive `defeated_allies`.
5. Add Martial secondary damage for `other_enemies` while retaining primary burst priority.
6. Keep Free Awakening strictly `self`-targeted.
7. Preserve all solo regressions.
8. Add representative target-semantics tests with at least two allies and two enemies; do not
   claim those semantics before the multi-actor runtime exists.

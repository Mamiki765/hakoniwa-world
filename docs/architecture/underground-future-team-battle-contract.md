# Underground party target compatibility contract

Status: CURRENT AUTHORITY (task-specific compatibility boundary)

Application 3.8.0 adds the Owner-approved asynchronous party path while preserving the
historical one-player/one-enemy path. This document records the target meaning shared by both
paths. Display names are presentation only; actions use `team`, `actor_id`, `target_id`, and
`target_ids`, while battle snapshots use stable `combatant_id` values.

## Semantic targets

- `self`: the effect source. In the current runtime this is the Secretary.
- `primary_enemy`: the living enemy selected as the attack's main target.
- `other_enemies`: living enemy actors other than `primary_enemy`.
- `all_allies`: every actor on the source's side, including the source.
- `defeated_allies`: allied actors whose battle HP is zero and which are eligible for revival.

Application 3.9.3では、enemyの単体敵対actionが`primary_enemy`を必要とする時だけ実targetを決定する。有効かつ生存中の挑発sourceを優先し、それがなければ生存playerからexisting battle RNGで決定的に1人を選ぶ。同じactionのdamage、effect、logはその一度の選択を共有し、self、全体、明示target、round-endは通常target抽選を消費しない。全体攻撃は挑発source一人へ縮退しない。

Party-capable content effects may declare the narrow `target_scope` values `single_enemy`,
`all_enemies`, `single_ally`, `all_allies`, or `self`. Existing effects omit the field and keep
their historical single-enemy/self result. `single_ally` uses the living ally with the lowest HP
ratio, with stable party order as the tie-breaker. This is the content extension point for a
future PT Boss's area actions; no concrete Boss or scaling value is authored in 3.8.0.

Party healing belongs to the authored skill/effect, not to a growth path or class. A Secretary
using `mending_prayer` may heal the lowest-HP living party member regardless of whether their
growth path is Martial, Guardianship, Blessing, or Free. `renewing_guard`, `crystal_aegis`, and
other self-only skills remain `self`; acquiring a Blessing growth path does not redirect them.
The Blessing awakening remains the separate one-shot `all_allies` recovery and 100% revival
contract below.

Direct references to the Secretary and current enemy in today's 1v1 implementation are an
implementation result, not a narrower semantic contract.

## Awakening Techniques

| Growth path | Technique | Historical 1v1 result | Party target meaning |
|---|---|---|---|
| Martial | 天断一閃 | Deals 100% authored burst to the current enemy. | `primary_enemy` receives 100%. Each `other_enemies` target receives the Owner-approved 50% potency. The purpose remains maximum single-target boss burst, not full-area clearing. |
| Guardianship | 絶対護界 | The Secretary receives 90% direct-damage reduction for two rounds after activation. Duration advances once at round end, never once per enemy action. | Apply the same protection to `all_allies` for those two rounds; enemy count and actions per round must not shorten it. Do not redefine it as self-only. |
| Blessing | 生命讃歌 | The living Secretary returns to maximum HP. There is no revivable party actor. | Fully heal `all_allies`; revive every `defeated_allies` actor into battle at maximum HP. |
| Free | 無窮再演 | Restore the Secretary's MP and clear the Secretary's ordinary active-skill cooldowns. | Remains `self` only. It never restores an ally's MP or cooldowns. |

The 3.8.0 implementation adds only the persisted party/member snapshot, actual combatant
collection, target identities, and the techniques required by this table. It does not add a
generic MMO party framework, real-time participation, companion progression, a second damage
formula, or an independent target-selector DSL.

## 3.8.0 compatibility checklist

1. Guardianship applies to `all_allies` for the same two-round duration, advancing once per
   round regardless of enemy count or action count.
2. Blessing heals `all_allies` and revives `defeated_allies`.
3. Martial retains full primary damage and applies 50% potency to `other_enemies`.
4. Free remains strictly `self`-targeted.
5. Party action logs retain actor and target identity; team survival decides the result.
6. Historical solo regressions remain unchanged and are not recalculated as party logs.

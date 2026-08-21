# Post-2.3.0 simplification handoff

この文書はver 2.3.0後のdocumentation-only auditと小さなcleanup PRへ引き継ぐOwner intentである。C5では以下のschema変更、runtime削除、field移動、turn engine refactorを実装しない。安全性は未確認であり、削除可とは結論しない。

## Current-only runtime support investigation

旧rulesetはsource、checksum、migration、audit、historical definitionとしてimmutableに保持しつつ、ordinary runtimeがcurrent active rulesetだけを実行できる構成を調査する。

historical runtime pathを削除する前に、少なくとも次のevidenceが必要である。

- unresolved historical TurnRunがzeroで、failed same-seed retryを含む保存義務とoperator policyが明示されている。
- queued command dataがcurrent definitionへ安全に移行済みで、request provenanceとfingerprintが保存されている。
- alive MonsterInstanceとcurrent aggregate kill statがcurrent definitionへ移行済みである。
- killed/removed monster、terminal queue、events、TurnRun、published payloadのread-only presentationが旧definitionを使っても壊れない。
- production snapshot query、runtime call graph、test coverageで旧ruleset execution入口がないことを確認した。
- backup/restore rehearsalとrollback policyがcurrent-only runtime導入後もhistorical recordsを復元・表示できる。

これらのevidenceが揃うまでは、historical runtime supportの削除が安全だと主張しない。

## Proposed three-layer configuration

### Ruleset core

checksum、replay、structural contractとして次を保持する。

- phase ordering
- stable keys
- effect/action/policy types
- selector structure
- random stream identity/version
- snapshot meaning
- migration identity

### Versioned balance profile

既存versionをin-place更新せず、新version publicationだけで変更する。

- disaster probability
- Item effect values
- monster HP/value/XP
- command costs
- prices
- production rates
- capacities

TurnRunはretry reproducibilityのため、使用したexact balance profileを参照する。C5ではこのschema、foreign key、publisher splitを実装しない。

### Flavor and presentation

gameplay checksumから分離できる候補はdescription、Item flavor、manual prose、Secretary text、credits、alt text、安全なpresentation-only labelである。gameplayはflavor textへ分岐してはならない。C5では既存fieldを移動しない。

## Over-defense audit candidates

| Candidate | Initial classification | Evidence required |
|---|---|---|
| extreme theoretical numeric validation | requires measurement | 実data bounds、DB constraints、failure frequency、hot-path cost |
| publicationで保証済みのrepeated runtime validation | move to publication | publisher validation coverage、corrupt-row detection boundary、query profile |
| duplicate resolver/service/executor checks | requires measurement | call graph、shared input trust boundary、mutation/retry timing |
| non-runnable historical ruleset compatibility branches | remove after migration | unresolved runs zero、live data migration、read-only history tests |
| UI-impossible states defended in hot paths | keep | API forgery、concurrency、DB corruption時のfail-closed value |
| hypothetical future feature向けabstraction | unknown | real callers、planned contract、deletion diff and regression proof |

見た目やclass数だけで削除しない。`keep`、`move to publication`、`remove after migration`、`requires measurement`、`unknown`の分類はprofileとevidenceを付けたdocumentation-only auditで更新する。

## Turn engine readability target

- `CompleteTurnEngine` top-levelはone-screen table of contentsを維持する。
- phase method名だけでexecution orderが読める。
- 各serviceは一つのchapterとして責務を持つ。
- policy/resolver classは本当に複数箇所で共有するruleだけに使う。
- one-line behaviorのためのresolver to policy to contract to normalizer chainを避ける。

C5でこのrefactorを行わない。

## Cleanup sequence

```text
C5 release completion
→ documentation-only simplification audit
→ runtime/query profile
→ classify compatibility paths
→ small deletion/refactor PRs
→ no mixed gameplay + cleanup PR
```

# 残る地底候補の再比較: 装備snapshotとAI初手選択

## 結論

**今回の優先順位はTurn保存の支配コスト改善を維持する。地底へ次に時間を割くなら、BU-02を一つの状態応答に限定してSQL計数/時間を測る。BU-03は現時点では見送る。** 新PRを増やすことを目的にせず、BU-02の2回程度のDB往復と重複装備投影が実応答で小さければ、両案とも後回しでよい。

BU-02はDB依存の寄与が未測定だが、同じ応答内の明示的なsnapshot渡しならmutation cacheを新設せずに3→1 SELECTを狙える。BU-03はselect回数削減の証拠がある一方、既存probeの時間差はlab約0.36ms/戦、PT約0.052ms/戦、短いsolo約0.00047ms/戦に留まる。各1回・計数instrument込みなので、これらを実環境の期待改善量として保証しない。ボス/覚醒/free actionの検証を追加してまで今着手する強い時間根拠はまだない。

## 対象版とPR照合

GitHubのread-only取得で再確認した。製品編集・commit/push・CI起動・runtime新規実行は行っていない。新runtimeは親の環境準備/実行通知待ちで、この文書はsourceと既存測定に基づく判断資料である。

| 対象 | 固定SHA | 今回の候補との関係 |
|---|---|---|
| main | `2e93ec918774ad64d787aba99fc59a7bc3e54d16` | 現在も5.8.3。BU-02/03は残る |
| [Draft #347](https://github.com/Mamiki765/hakoniwa-world/pull/347) | `a28f7169aa3cada312c36546f672ffde35779cb0` | 覚醒result projectionの共有のみ。headのexecuteTurnにも二重selectが残る。BU-03を解決したPRと誤認しない |
| [Draft #356](https://github.com/Mamiki765/hakoniwa-world/pull/356) | `7aa89e1058416da5b7cc3b65b65a7cb3816deb83` | immutable foundation/exploration/trial catalog再利用。mutable equipment取得とAlphaV1CombatModelは無変更 |
| 前回の6patch統合確認 | tree `99585a57f9d48007a5581ddf6ae1b42554841c9e` | catalog/HP/Lv/item/worker scopeの相互作用確認済みだが、BU-02/03の追加prototypeを検証したものではない |

以下の行番号はmain固定SHAを基準とする。旧監査・probeは上書きせず保全した。

## BU-02: 最小境界なら装備の永続cacheは要らない

### 実コードで確認した重複

| 到達経路 | 現在の処理 | 削減可能な部分 |
|---|---|---|
| `UndergroundIntroService::projectState():1728-1729` | combatLoadout→summary。summary内部はratingRequirementBps→combatLoadoutと、自身のequipped rows取得 | 同じ集合のSELECT **3回→1回**。inventory countは別で、削減数に含めない |
| `SecretaryUndergroundStatusPresenter::present():25-34` → `SecretaryProfilePresenter::present():60` | combatLoadout後、同じequipped集合を取り直して公開用3fieldを作る | **2回→1回**。公開返却のlabel/IL/quality制限は維持 |
| `UndergroundEquipmentLoadoutResolver::summary():25-54` / `equippedDefinitions():196-223` | profile/equipped条件のDB読取を常に実行。definitionForRowでgenerated identity検証/研磨を適用 | rowsとaggregateを共有すればSELECTと反復CPUを分けて減らせる |

Introの一通過例ではequipped数D（最大6slot）に対し、definitionForRowが概ね3D回、combat aggregateが2回、UI projectionが1回である。単にrowsだけ渡せばqueryは減るが、同じdefinition検証/aggregateをそのまま再実行し得る。CPU削減も測るなら、検証済みdefinitionとaggregateの呼出回数を別に計数する。

### rootが実装判断する場合の小さな案

一つの状態応答の冒頭で、既存resolverがequipped rowsを一度だけ取得し、canonicalなdefinitionForRowとcombatLoadoutを使って局所snapshotを作る。その同じsnapshotからcombat statsとsummaryを投影する。別requestへ保存せず、serviceのscoped/static mapにも入れない。

- 新しい定義authorityを作らず、既存のidentity/quality/研磨/category/slot検証を再利用する。
- summaryのinventory count/capacityと公開presenterの返却内容は別の必要処理として維持する。公開statusのために私有inventory summaryを作ってserializeしない。
- snapshotを受け取るhelperへ適当な未検証definitionを渡せる設計にせず、同じprofile/row/slotから作ったvalidated contextであることを型・private境界・呼出順で守る。
- equip/unequip/polish/sell/market購入の前後には引き継がず、**mutation完了後に新規取得して応答する**。これなら無効化registryやrevision migrationは不要。

最初はIntroの単一応答だけで測るのが小さい。公開statusまで同時に広げる必要はない。query削減の手段が一つできても、別の呼出元への適用は個別に整合性を確認する。

### mutable/借用/画像の境界

`UndergroundIntroService::state():67-92` はstarter補完を短いtransactionで行った後、profileを再取得してprojectStateへ進む。mutation側の `mutate():1261-1327` はprofile lock下で操作後にrefreshしてprojectStateへ進む。snapshotをこの**最後の応答投影内**に閉じれば、操作前の装備を返すcacheにはならない。GETには応答投影中のprofile長期lockがなく、現在の3 SELECTも並行equipで異なる時点を読み得る。一集合の共有は観測時点を一つに揃えるが、変更後の再取得が必要な別操作へ拡張してはならない。

借用sourceは別の契約である。`BorrowedSecretarySnapshotFactory::sourceSnapshot():242-268` は、lock済みprepared relationがあればそれを使ってdetached sourceを作り、fingerprintに実gear/quality/polishを含める。`UndergroundRuntimeService::prepareBorrowedPartySnapshots():3351` は画像予約を含む短いtransactionを終え、source lockを解放してから本戦闘へ進む。BU-02の最小案ではこの経路へ触れず、borrower/lender間でsnapshotを共有しない。

画像はequipmentとは別の保持対象である。Runtimeの`battleImageReferences():1818`/`partyCombatInputs():2130`のsource lock、`:3382`予約、`:2113/2707`等のretainは変更しない。readonlyな公開statusから作る装備表示snapshotを、画像lease付き戦闘snapshotの代替にしてはならない。

### 追加で見つかった隣接経路（最初の案には含めない）

`SecretaryLendingService::publicCandidatePage():63-115` はequipped rowsをeager取得済みなのに、各候補のratingRequirementBpsがresolver経由で再SELECTする。20候補pageで最大20回の追加equipped SELECTに到達する。公開status presenterを候補ごとに呼んでいるわけではないので、「候補pageに2回ずつ」と誤算しない。

eager rowsを明示的な入力として渡す候補はあるが、取得時点・id順・row/profile対応を確認し、ratingと表示の混在時点をどう揃えるかを評価する。ownerのbattle mutation、prepared snapshot、画像leaseとは分離する。件数だけならIntroより大きく見えるが、page利用頻度とDB時間は未測定。これを理由にscopeを全装備経路へ拡張しない。

### 新規runtimeが許可された場合の測定窓

まず既存代表fixtureを使い、fixture作成/認証/後続assertを窓外へ出し、`projectState`のequipped取得又はpublic presenterだけを測る。SQL listenerで `underground_owned_equipment`、対象profile、`equipped_slot IS NOT NULL` の照会を分類する。期待する減少は3→1（又は公開2→1）で、総SQL数の固定一致を契約にしない。definitionForRow/aggregate回数、SQL所要時間、PHP経過、result hashを併記する。

代表は通常gearとgenerated+研磨/qualityを含む状態、既存equip/unequip後のHP/rating、公開statusの秘匿を選ぶ。borrowed sourceと画像leaseは最小案で不変更を確認し、触る場合のみ既存のowner/画像並行代表へ拡張する。A→mutation→BでA snapshotがBへ再利用されないことを確認する。

期待寄与は少なくとも2回程度のquery/hydrate＋重複projectionで、**何msになるかは未測定**。Turn保存の大量writeとは規模が異なる。microbenchで回数だけ減っても通常応答で差が小さければ見送る。

## BU-03: 回数の約1/3減と時間改善を分ける

main `AlphaV1CombatModel::executeTurn():1233` の覚醒検出用selectと、`:1303` while初回selectが未覚醒・non-awakening候補で重複する。`actionImpaired():2978` はtrue時だけstatusを消してreturnし、false時はstateを変えない。未覚醒の`useAwakeningTechnique():3427`は即false。間のemergency-heal診断はmetricsのみ。`PriorityCombatAi::select()`はactor stateを変えない。この限定区間だけ最初の候補を再利用する案は静的には成立する。

ただし、覚醒後/無料skill後/次行動では選択条件が変わり得る。最初のnon-awakening候補を一度だけ使って捨てる以外のmemoへ拡張しない。MP blocked診断、jump cursor、明示target、action/ログ/乱数の順を維持する。#347はprojectionだけの変更だが同じmodel fileを変更するため、後の実装時には統合順も確認する。

既存の `/workspace/perf-audit-20261031/battle-probe-comparison.json`:

| 既存fixture | AI select before→after | full-result hash/RNG | 単発時間差 | 差÷戦闘数（観測算術のみ） |
|---|---:|---|---:|---:|
| lab32戦、合計3,195round | 9,427→6,232 | 32/32一致、RNG同数 | 373.349→361.848ms | 約0.359ms/戦 |
| Lv10短solo8戦、合計50round | 150→100 | 8/8一致、RNG同数 | 4.229→4.225ms | 約0.00047ms/戦 |
| 短PT8戦、合計56round | 567→356 | 8/8一致、RNG同数 | 24.045→23.629ms | 約0.0521ms/戦 |

各一回、instrumentあり、PHP8.5.8、pure domain、DBなし。これはmain由来の隔離prototypeであり、#347と#356を積んだHTTP/ボスfixtureのbefore/afterではない。「AIを34〜37%減らしたので戦闘を同率高速化」とは言えない。labは100round近くまで走った例を含むが、ボスの固有行動/覚醒/free-actionを網羅したことにはならない。

次に比較するなら既存の `test_guide_duel_forces_every_member_to_full_awakening_before_the_opening_ultimate`、`test_ai_can_delay_an_awakening_technique_and_preserves_its_action_cost`、`test_additional_strike_uses_a_slot_and_cooldown_but_preserves_the_regular_action_with_any_weapon`、soloのfree awakening/jump/MP代表から最小corpusを作り、既存のsource-overlay prototypeでfull result/hash/RNGを前後照合できる。新しい長期balance試験は要らない。ただし今回のTurn保存優先作業でこの追加実行を始めるだけの時間寄与根拠はまだ弱いので、先にAI関数のCPU比率が高い代表を見つけた時だけ進む。

## 順位・採否の条件

1. Turn保存の支配区間を改善する本筋を先に進める。地底の小さな局所最適化を新PR数のために割り込ませない。
2. 次候補としてBU-02の応答内snapshotを一つの入口で測定する。2 SELECTと重複CPUの寄与が具体的に見え、mutation後の結果/公開秘匿が保てる場合だけrootが実装判断する。現時点で「実測効果が確立した採用案」ではない。
3. BU-03は見送り。select回数削減・48結果一致は根拠として残すが、現行代表のCPU比率と固有分岐の確認が追加されるまで実装しない。

Rulesetを新しくする必要があるとは現段階で判断していない。実装候補は処理意味・抽選確率・資産・同版/状態/seed再現を維持する。Ownerが許容した将来乱数結果の変更を無意味に制約することも、retryで有利なseedを選び直すことも提案しない。

## English summary

Keep turn persistence as the priority. BU-02 is the better next measurement candidate: an explicit snapshot limited to one response could reduce three equipped-item reads to one without introducing a mutable cross-request cache. Preserve post-mutation freshness, public-field restrictions, detached lender snapshots and image leases. Its latency benefit is not measured yet. BU-03 reduced AI calls and matched 48 complete results, but observed single-run timing differences were small and boss/awakening/free-action coverage remains incomplete; defer it. Main and PR heads #347/#356 were rechecked, and no product code or new runtime execution was changed.

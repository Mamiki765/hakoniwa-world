# 地底戦闘・装備・首都地下施設の性能調査

調査基準は `Mamiki765/hakoniwa-world@2e93ec918774ad64d787aba99fc59a7bc3e54d16`（main、5.8.3）。この分冊の対象は、地底のsolo/PT探索、案内人決闘、狂月賛歌、異世界ボス、試練、力試し、導入戦闘、skip、装備/貸出snapshot、戦闘結果保存/表示と首都地下施設である。API一般・market・retentionの詳細はAPI担当の分冊と統合し、ここでは重複提案しない。

製品コード・設定・Ruleset・DBには一切書き込んでいない。commit/push/PR/CI/本番/OCI操作、認証情報の閲覧も行っていない。`AGENTS.md`、現行handoff入口、docs入口と関連contract/lock資料を確認した。tracked treeと実環境の `.agents` から本作業に該当するSKILL.mdは検出されなかった。archive配下は調査対象にしていない。

## 結論と推奨順序

| 順位 | ID | 候補 | 根拠 | 期待効果と範囲 |
|---|---|---|---|---|
| 1 | BU-01 | 同一requestの戦闘catalog/manifestを一度だけ構築する | 59,720Bのfoundationを毎回read/decodeし、52skill/10statusを検証。通常soloの一つの通過例で6回のfoundation読取、bulk skipの通常経路では4回/実行 | file IO/JSON/全定義検証の反復を削減。catalog service内のrequest限定memoまたは明示的context渡し |
| 2 | BU-02 | 同一操作内で装備rows・検証済みdefinition・combat aggregateを共有する | Introの状態応答に同じequipped集合のSELECTが3回、公開statusに2回 | Introは3→1、公開statusは2→1 equipped SELECT。各definition検証・研磨適用の重複も削減 |
| 3 | BU-04 | 貸出報酬の二段SELECTをunique条件の直接lock SELECTへ | ticket balanceとdaily rewardの各々で非lock読取→idでlock再読取 | 4→2 SELECT/借用人、最大3人のPTで6 SELECT削減。lock順/残高決算は維持 |
| 4 | BU-03 | 未覚醒playerの同一行動内AI選択結果を再利用する | 48戦の短い隔離probeで、結果全体hashとRNG呼出数がすべて一致し、AI走査回数が減少 | AI select総数が約34〜37%減少したfixtureあり。時間短縮は小さく、単発測定なので保証しない |
| 保留 | BU-05 | 状態modifierの局所集約・RNG入口の重複検証削減 | 32戦でstat()72,349回、RNG20,147回/3,939 streamsを計数 | CPU profile未取得。状態変化の無効化漏れを避ける設計が必要。優先4件より後 |

これら4件は旧版と同一の乱数列・出力を保つ実装も可能であり、Ruleset更新を必須とする理由は見つかっていない。Ownerが許容した将来乱数結果の変更を不要な制約で封じない。ただし、乱数engine変更そのものは本調査では推奨実装にしていない。確率・domain分離・private seed・同じコード/状態/seedの再現性を含む設計確認が必要であり、観測なしのengine交換を高速化の実績とは扱わない。

## BU-01 catalogの再構築

固定SHAの根拠:

- `product/app/Application/Underground/UndergroundAlphaV1PlayerCatalog.php:1156` laboratoryCatalogは毎回 `foundation-v4.json` を `file_get_contents → json_decode → new AlphaV1BuildCatalog` とする。
- 同`:668` explorationCatalogはfoundationを読み、すべての狩場のenemyをmergeして再びcatalogを構築する。同`:850` trialCatalogはtrial manifestをdecodeした後にfoundationを読み、合成catalogをもう一度検証する。
- `product/app/Domain/Underground/Combat/AlphaV1BuildCatalog.php:34` はconstructorごとにstatus/skill全定義検証を実施する。
- PlayerCatalog`:558/605/625/652` のencounter取得もexplorationCatalogを構築する。`UndergroundRuntimeService.php:3681` の通常ground（separate rareなし）ではweighted取得・最初のencounter解決・normal一覧の3回、その後`:1892` の選択encounter解決で1回、`:1914` combat definition中のcatalogとskill buildで各1回、計6回のfoundation readに到達する。rareの早期returnやotherworldは別分岐であり、すべての探索を6回とは断定しない。
- 同Runtime`:751` bulk skip loopは同じ通常通過例でdraw中3回+encounter取得1回、計4回/実行。`:41` で1,000実行まで受付けるため、この通過例は4,000回のfoundation read/decode（239MB相当の繰り返し入力）になり得る。これは静的計数で、実requestのSQLを含めた経過時間の実測ではない。
- `BorrowedSecretarySnapshotFactory.php:1025` のrulesIdentityなど、既存numeric projection cacheのhit経路でもcatalogのidentity確認へ戻る。既存cacheそのものは再提案しない。

計算量はcatalog構築をK回行うと、JSON size B・skill/status数D・合成enemy数Eに対し O(K(B+D+E))。同じrequestの同じ内容を共有すると O(B+D+E) に抑えられる。読み取るファイルをimmutableなrelease/configに限定する。worker全体の無期限static cacheはconfig差し替え/テスト/Octane等の生存期間を誤るので採用しない。trial key、foundation/combat/content identity、config内容を区別し、異なるcatalogを同一扱いしない。

既にcurrent catalogの型/identity/status/skill検証があるため、共有時も初回の検証は残す。検証を削除して速くする案ではない。検証法は、request内のfile読取・constructor回数を計数し、既存definition/不正manifestの失敗と、solo/PT/trial/bulk skipの報酬・確率・seed再現性をfocusedで確認する。完全な旧乱数順一致は将来engine変更の必須条件ではないが、本候補の意味保存確認には出力一致が使える。

## BU-02 装備snapshotの重複

`UndergroundIntroService.php:1728` が combatLoadout、`:1729` がsummaryを呼ぶ。`UndergroundEquipmentLoadoutResolver.php:19` は`:196` equippedDefinitionsのSELECTへ進む。summaryの`:27` ratingRequirementBpsもcombatLoadoutを再呼出し、`:28-32` は別途同じequipped rowsを取得する。したがって同じ集合を3 SELECTし、definitionForRow・generated payload検証・研磨・combat aggregateも繰り返す。inventory countは別SQLであり、3→1の数に含めない。

`SecretaryUndergroundStatusPresenter.php:25` のcombatLoadoutと`:31-34` のequipped rowsも同様に2回SELECTする。公開statusのlabel/IL/qualityだけを返す秘匿境界（同`:35`）を維持する。profile/装備versionを跨ぐ永続cacheにせず、一つの状態応答で取得したrowsとdefinitionを渡す。equip/unequip/polish/sell等のmutationの前後では必ずsnapshotを捨てる。Intro `mutate():1261-1327` からの応答はprofile lock内なので特に局所化しやすい。

確認は静的SQL経路に基づく。SQL listenerをfixture準備後から応答までに限定し、対象profile/equipped条件のSELECTが3→1/2→1になること、装備変更直後の能力・rating・HP・公開秘匿が保たれることを確認する。DB往復の時間と本番頻度は未測定。

## BU-04 貸出報酬SELECT

`UndergroundLendingRewardService.php:148-150` はinsertOrIgnore後、user_idでbalanceを読み、得たidでFOR UPDATEを再取得する。同`:167-170` はowner/dayでdaily rewardを読み、idで再lockする。schemaの `pgsql-schema.sql:5909` はbalance user_id UNIQUE、`:5357` はdaily `(owner_user_id,canonical_day)` UNIQUEを持つ。各unique条件のFOR UPDATEへ直接進めば、同じ最新rowを一度で取得できる。

borrowed人数b（最大3）に対して4b→2b SELECT。insertOrIgnore、owner順、ticket balance→daily rewardのlock順、profile/party/battle/participationの冪等性、日跨ぎ累計余りと日次上限、ledger/auditは維持する。DB実測・lock競合試験は今回は行っていない。既存 `UndergroundLendingRewardServiceTest` の成功/再送/日次境界と、既存PG concurrency代表へのSQL計数を使うのが検証候補。

## BU-03 AI走査の再利用

`AlphaV1CombatModel.php:1233` 付近の未覚醒player分岐は、覚醒actionを検出するため `ai->select(...,0)` を呼ぶ。候補が覚醒でなかった場合も、`:1303` のwhile冒頭で同じstateとcursorで再選択する。間の `actionImpaired():2978` がtrueならその行動は終了する。falseなら状態変更をせず、未覚醒のuseAwakeningTechniqueはfalse、ほかは計測値の加算だけである。候補を一行動内で保持し、whileの初回に使えば二重走査を避けられる。

隔離probeはautoload時にメモリ上のsourceへ小さな変換を適用した。製品treeにpatchを適用していない。non-awakening候補だけを保持し、初回使用後は破棄し、覚醒後/無料skill後/次行動では再評価する。PriorityCombatAiの選択はstateを変更しないことを確認した。jump、MP blocked診断、明示target、action順と各log/metricを保存する必要がある。

## 実測結果と限界

実行はDockerのローカルdaemonで `--network none`、app/config/resourcesをreadonly mountし、アプリboot・DB・外部APIを使っていない。既設dev imageのPHP **8.5.8**、本体Dockerfileの8.5.12とは異なる。vendorは利用せず、実体のPHPファイルをPSR形式の小さなautoloadで読む。config()はreadonly configファイルを返す補助関数。計数instrument自体のoverheadを含む。全suite/CI/長期balance試験は実行していない。

| 観測 | baseline | 局所prototype | 意味保存の根拠 |
|---|---:|---:|---|
| foundation構築16回と16参照 | 9.026 ms / 16 read | 0.544 ms / 1 read | 同じimmutable catalog参照を共有するだけ。別processの観測は6.521/0.448 ms |
| lab32戦（4build×8seed、max100round） | 373.349 ms、AI9,427回 | 361.848 ms、AI6,232回 | serialized result全体hashが32/32一致、RNG呼出20,147回で一致 |
| runtime solo8戦（Lv10、starter、最初の通常敵） | 4.229 ms、AI150回 | 4.225 ms、AI100回 | 全体hash8/8一致、RNG300回で一致 |
| runtime PT8戦（上と同じsnapshot4人/同敵4体） | 24.045 ms、AI567回 | 23.629 ms、AI356回 | 全体hash8/8一致、RNG1,661回で一致 |

各ケース一回の比較であり、timeの差は統計的な速度改善保証にならない。AI走査の回数削減と結果不変を支持する証拠である。catalog共有は局所操作の比較で、最適化後の本物のHTTP requestを計測した結果ではない。PHP OPcache・DB latency・同時接続・本番Worldの大きさは反映しない。

再実行用 `battle-probe.php`、raw `battle-probe-baseline.json` / `battle-probe-ai_reuse.json`、48結果の比較 `battle-probe-comparison.json` を同directoryへ保存した。最初の探索snapshot補助定義が不完全だったprobeは失敗し、ゲーム側を書かずに補助fixtureを修正して上記を取得した。失敗を性能測定の成功回数へ含めていない。

## 低順位・既改善・反証

- stat():168はstatus effectsを毎回走査する。上のlab32戦で72,349回を計数した。currentStats():3247は5statを要求し、damage/critical/heal/periodicで重複するが、status付与/解除/stack/round-end/覚醒で値が変わる。全戦闘のmemoでは意味が壊れる。状態versionつき局所集約の性能寄与は未測定なので、BU-05の観測候補へ留める。
- UndergroundRandom::integer():33は各drawでUTF-8 label検証、width/acceptBelow算出を行う。lab32戦は20,147draw/3,939streams。既登録labelの検証を初回へ寄せる局所案はあり得るが、HMACやstatがどれだけ支配するかは未profile。private seed由来・rejection sampling・label別domain・seedの秘匿を省かない。
- BorrowedSecretarySnapshotFactory:64-160はsource/effective equipment/numeric projectionを既に永続cacheし、numericとbehavior fingerprintを分離し、表示/HP変更では数値を再生成しない。装備IL同期cache順序修正も既存（17caa945）。このcacheの新規追加を改善として再提案しない。
- bulk skipは既に`:813`でvault残数を1回計数してloop内で減らす。profileも`:785`でまとめてsaveする。1dropごとのcount/updateへ戻す案はない。
- `RulesetVersion::settings` のJSON再解読は5.8.2でモデル単位に改善済み。このBU-01は別の地底manifest経路である。
- `b671717f138c11349256de0061d1e28e7fbe1b21` はAIのlowest-HP選択を共有済み。これとBU-03の同一行動二度選択は異なる。
- 首都地下施設の公式Turnは`UndergroundFacilityBenefits::loadTurnSnapshots():98`から一括取得し、ForTurn群はTurnStateを使う。施設ごとのDB読取を公式Turnの未修正N+1と誤認しない。liveSnapshots():190はnested eagerの固定段数であり、施設数比例のrelation SELECTではない。
- simple形式の導入戦闘表示は `UndergroundBattleLog.vue:325` でroundごとに全actionsをfilterしO(RA)となるが、長い現行Alpha戦闘のstructured形式とは別。template向けround groupingは低順位で、ブラウザ時間は未計測。

## カバレッジと残る限界

`battle-coverage.csv` は全入口をgroup化し、確認状況、候補、未実測を別列にした。`battle-file-inventory.csv` は地底application/domainと関連controller/模型のpublic入口・loop/SQL/file IOの機械的inventoryであり、全行を人が読んだ/全分岐を実行した証拠ではない。カバレッジの「確認済み」は実コードの呼出・loop・保存/lock境界を静的に照合した意味で、「問題なし」は具体的な追加改善を裏付けなかった範囲を表す。本番で速いことの保証ではない。

公式Turnの戦闘はTurn担当、market/receipt運用/全APIのquery/index/履歴はAPI担当、コマンド送信はcommand担当の台帳を正本に統合する。未確認: HTTP latency、DB SQL実測/PG EXPLAIN/並行lock時間、実際のプレイヤー装備分布・round数、boss/awake/free-action全分岐でのprototype同値、モバイル描画時間。意味保存の候補として着手できる順序はBU-01→BU-02→BU-04→BU-03。性能の根拠を追加してからBU-05を検討する。

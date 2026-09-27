# main差分によるテスト再設計の更新

調査日: 2026-09-15。対象は`671752244d7a2d28e87a42fdd80592c9c51398cd` → `00182bd0eaee52f34194c7b40bc5e98712108718`（fetchで確認した`origin/main`）。

[設計図](test-suite-rebuild-plan.md)への差分資料。ここでの「残す」「削る」「移す」は今後の実装方針であり、テストの変更や実行は行っていない。

本書は実装前の調査記録。続くPhase 0a・Phase 1の変更と確認結果は[実装記録](test-suite-phase01-implementation.md)を参照する。

## 1. 件数と調査範囲

| 対象 | 前回 | main | 差分 |
|---|---:|---:|---:|
| PHPテストファイル | 116 | 116 | 変更17、不変99。追加/削除0 |
| 宣言されたtest method | 902 | 921 | +19 |
| PHPUnit展開後identifier | 1,039 | 1,058 | 20追加、1削除 |
| 地上側の既存配置のcase | 832 | 836 | +4 |
| 地底のcase | 207 | 222 | +15 |
| frontend testファイル | 20 | 20 | 変更5。追加/削除0 |
| `lightweightWorld()`呼出し箇所 | 268 | 271 | +3、全て問合せ競合file |
| `NationCreationService::create()`呼出し箇所 | 161 | 165 | +4、全て問合せ競合file |

変更された17ファイルの本文と、対応するruntime・config・migration・current architecture・Owner決定の差分を確認した。不変99ファイルのゼロベース精査は前回を引き継ぐ。source不変でもruntime変更の影響は受けるため、PASSや回帰なしを意味しない。

前回の[1,039 identifier](baseline-6717522/test-suite-identifiers.txt)と[採取結果](baseline-6717522/test-suite-audit.json)は保存した。現在の[1,058 identifier](test-suite-identifiers.txt)、[inventory/hash](test-suite-inventory.csv)、[機械差分](test-suite-main-delta.json)から全追加/削除method名を追える。今回の追加はprovider展開数の変化ではなくtest methodの増減である。

## 2. PHPの変更17ファイルの処置

パスは`product/tests/`からの相対。件数はprovider展開後。

| ファイル | 前→後 | 変わった意味と処置 |
|---|---:|---|
| `Feature/AnnouncementApiTest.php` | 6→7 | Markdown preview/publicの安全なrenderと編集source保持を追加。**1つの代表を残す**。既存plaintextの互換性とpreview権限は同じAPI境界へ集約。HTML全文・全タグmatrixにはしない |
| `Feature/FreshInstallRebaselineTest.php` | 2→2 | application 4.1.2、SP/rental migration ledger、`announcements.body_format`へ追従。fresh installの担当を維持し、件数/列一覧snapshotの削減方針は継続。version変更だけで新caseを作らない |
| `Feature/InquiryApiTest.php` | 8→8 | 期待application versionだけ3.9.3→4.1.2。前回の分類を維持し、新規の保証に数えない |
| `Feature/InquiryConcurrencyFailureTest.php` | 1→4 | 実際のcross-domain deadlock 2経路と同一送信の添付重複防止を追加。**残してSharedへ移す**。3追加の内容と実接続要件は§3 |
| `Underground/Feature/BorrowedSecretarySnapshotFactoryTest.php` | 3→3 | skill tree identity v2にfixture追従。borrowed snapshotの担当を維持。v1/v2両runtimeのmatrixを新設しない |
| `Underground/Feature/PostgresUndergroundRuntimeConcurrencyTest.php` | 10→10 | 借用partyを事前にserver確定、SP消費15→14、identity v2。**既存の実競合代表を維持**。受付条件変更はfixture更新で扱い、party人数ごとのコピーを増やさない |
| `Underground/Feature/SecretaryLendingCandidatesTest.php` | 3→3 | identity v2へのfixture追従。既存候補/ownership境界を維持。全skill世代の一覧テストを増やさない |
| `Underground/Feature/UndergroundLendingRewardServiceTest.php` | 8→8 | identity v2へのfixture追従。貸出報酬の一度限り決算を担当。v2専用の同一報酬suiteを作らない |
| `Underground/Feature/UndergroundPersistenceTest.php` | 4→4 | 全column配列へrental party/skill rebuild flagを追加。**全列snapshotは縮小**し、必要なownership・default・制約へ寄せる。新columnごとの独立testを増やさない |
| `Underground/Feature/UndergroundPlayerAccessTest.php` | 27→28 | vault sort追加、武器filter旧case削除→武器非依存case追加、skill v2のprerequisite/予算へ追従。**sortを小fixtureへ縮小、武器loopを代表へ縮小**。詳細は§4 |
| `Underground/Feature/UndergroundRuntimeTest.php` | 26→30 | 案内人決闘2件、rental資源永続化1件、SP還元/旧retry保持1件。**データと実upgradeの代表を残す**。台詞等を縮小、migration caseを個別lifecycleのShared upgradeへ移す。詳細は§3 |
| `Underground/Feature/UndergroundSkipSettlementTest.php` | 11→11 | identity v2へのfixture追従。既存のskip/決算担当を維持。同じv2決算を別caseへ複製しない |
| `Underground/Unit/AlphaV1PartyCombatTest.php` | 14→23 | 決闘2、counter1、critical2、追加攻撃1、蘇生1、AoE覚醒1、回復覚醒1。**異なる戦闘契約をpureで担当**し、同じ計算をDBへ増やさない。反復の縮小は§4 |
| `Underground/Unit/PriorityCombatAiConfigurationTest.php` | 17→17 | rule固定indexのHP55/always assertionを削除。**既に縮小された偶然の構造を戻さない** |
| `Underground/Unit/UndergroundBalanceSimulatorTest.php` | 17→17 | SP支出を固定60ではなく総予算内へ変更。**意図に沿う緩和を維持**。未到達の整数限界を削る前回方針も維持 |
| `Underground/Unit/UndergroundCombatBuildTest.php` | 35→35 | skill v2、固定予算からmanifest由来予算へ、旧investment gateからprerequisiteへ。固定威力等の一部を意味の確認へ変更。**古い正確値を復活させない**。前回指定した整数限界の削減は継続 |
| `Underground/Unit/UndergroundPartyBattleProjectorTest.php` | 1→2 | barrier増加の内部負値を表示用正値へ変換しraw log不変を確認。**projectorの代表を残す**。旧蘇生caseも実targetへ追従しておりleader固定へ戻さない。日本語ラベル完全一致は縮小候補 |

## 3. 必要コストを認めて残す追加

### 実際のdeadlockと添付の重複

`InquiryConcurrencyFailureTest`へ追加された3件はそれぞれ異なる失敗を検出する。

1. `test_inquiry_and_party_foreign_keys_complete_while_turn_waits_for_secretary`: Turnのworld/nation lock、partyのSecretaryとUser FK、問合せのUserとNation FKが循環する経路。Userの非key更新を`FOR NO KEY UPDATE`へ変えた実修正に対応する。
2. `test_turn_secretary_flush_and_party_snapshot_complete_without_lost_updates`: Turnがborrowed Secretary→leader、partyがleader→borrowed FKを扱う経路。Secretary経験値加算の`FOR NO KEY UPDATE`と、別writerの加算が失われないことを確認する。
3. `test_same_submission_serializes_and_stores_only_one_attachment`: 同一submissionの同時送信を直列化し、添付fileを一度だけ保存する。

追加support `tests/Support/inquiry_concurrency_worker.php`と親processが実接続を使い、barrierと`pg_blocking_pids()`で順序を確認する。単なるsleep頼みの人工競合でも、存在しないDB破損状態でもない。重要な実regressionとして個別lifecycleを維持する。class名によるsurface分類のままでは地底だけの実行から外れるため、Sharedへ変更する。

### SP還元とrental party

`2026_09_13_000000_rebuild_underground_skills_and_store_rental_party.php`を実行する既存追加caseは、旧SPのearned totalを保持した還元、loadout再設定までの新規戦闘制限、過去requestのsnapshot retry、trial進行、既存announcementのplaintextを確認する。これは現行supported upgradeに含まれる履歴であり、旧identityを使うという理由だけで廃止しない。通常の`RefreshDatabase` caseから担当を分け、**既存caseを移動**する。別の全経路upgrade suiteは新設しない。

`test_rental_resources_persist_only_for_borrower_and_only_explicit_update_resets_awakening`は、借用元資源の不変、借用側snapshotのHP/覚醒持越し、再送ではresetしないこと、宿泊でHPだけ回復、明示party更新で覚醒resetを検出する。fixtureはSecretary/profile中心を維持し、地上mapを追加しない。

### 案内人決闘

`docs/open-questions.md` UG-07のOwner決定に基づく。新規migration `2026_09_14_000000_add_guide_duel.php`も確認した。

- 勝利側caseは両ownerのHP/覚醒/XP/資産/次回時刻/rental snapshot保持、貸出報酬なし、同一request再送、初勝利のGram一度限り、装備/売却不可を残す。戦闘計算はstubで決算だけを確認する現在の分離を使う。
- 敗北側caseは解放前拒否と、実coreへ接続した敗北後の資源保持を残す。既存の少数統合確認であり、人数×武器×勝敗へ広げない。
- 両caseの台詞全文や記念itemの表示用IL固定は、資産保護の検出に不要なので縮小対象。必要な状態・報酬・操作可否は残す。

## 4. 増分の中で縮めるもの

### 武器非依存skillと宝物庫

- 削除された`test_player_runtime_filters_active_skills_by_actual_weapon_without_clearing_the_saved_slot`は、新しい`test_player_runtime_keeps_acquired_skills_available_with_every_weapon_type`へ**仕様変更**された。単なるrenameではない。全武器で取得skillを使える方が現在の契約なので、旧filter保証を戻さない。
- 新caseは`dagger → rapier → longsword → crystal_staff → dagger`のloop、常にtrueの`supportsFlurry`による分岐、入力allocationの不変assertionを持つ。新規DB操作はせずcatalog生成だけを呼ぶため、既存のbuild担当へ移す候補。代表的な異種武器でもskillが残る確認へ縮小し、重複daggerや常に同じ分岐を捨てる。
- vault sort caseはpage size+1個を実生成し、各item用battleも作る。sort全体がpaginationより前に行われること、装備slot優先、item欠落/重複なしは残す。**test内のpage sizeを小さくして境界を跨ぐ最少fixture**にし、gear generator自体の再検証を減らす。sort×全rarity×全categoryのmatrixにはしない。
- sort case末尾の`sort[]=newest`拒否は、固有の資産・security故障を示さない限り独立保証にしない。一般のrequest型拒否を同じ画面ごとに繰り返す対象にはしない。

### 追加pure戦闘9件

| 新しい意味 | 残す担当と削る反復 |
|---|---|
| 決闘開始時の全員覚醒・敵先制 / lethal multi-hit後の一度だけ割込と最終死亡 | 通常戦闘と異なる決闘契約を短いpureシナリオで残す。DB側で全action logを再検証しない |
| counter stance | 現行のplayer側`counter_per_attacker`について、同じattackerのmulti-hitでは増えず別attackerでは発火する差を残す。4敵を2敵へ縮小する候補とし、次roundで再発火する確認のため2roundは残す。通常counterの既存pathと区別する |
| critical scaling 2件 | damage categoryの主statとGuardianの複合statという異なる式を担当。20roundを複数回走らせるsampleは、同じ差を検出する既存計算確認/短い代表combatへ縮小できるかfocusedで確認 |
| 追加攻撃 | slotとcooldownを消費しても通常actionを奪わないことを残す。全武器について同じtimelineを複製しない |
| 単体蘇生 | fallen allyを選び、生存者へ繰り返さない代表を残す。全growth path×party人数へ展開しない |
| party AoE覚醒 | UG-06はDecided。HP被害とbarrier被害を受けた各人がmulti-hitでも敵actionごと一度だけ得る既存の短い代表を残す |
| 有効回復と継続回復tickの覚醒差 | actionによる実回復とperiodic tickという別pathをpureで残す。全回復skillへコピーしない |

combat fixtureの巨大HP等は特定のactionを観察するための制御値でもある。DB改竄の21億を検証するcaseと同一視して数字だけで削除しない。必要なactionを短く観察できる値へ調整する。

### Markdown

script/危険URLを含む文章は実際に管理者が貼り付け得る外部入力であり、架空の巨大DB値とは違う。previewと公開記事が安全に描画され、元文章を編集できる代表は残す。新たなparser frameworkや全HTMLタグmatrixは作らず、既存rendererの境界を担当させる。

## 5. frontendの変更5ファイル

以下の数はソース上のliteralな`it/test`宣言数。Vitest実行件数ではない。5ファイル合計は65→72（+7）。

| `product/resources/js/`からの相対path | 宣言数 前→後 | 処置 |
|---|---:|---|
| `App.test.ts` | 42→42 | Markdown編集/preview、skill v2、rental確認へ追従。新methodなしでも既存methodが肥大化し得る。app接続だけ残し、下位componentの表示細部を重ねない |
| `components/UndergroundCombatantCard.test.ts` | 1→2 | HP横のbarrier表示。表示有無を担当し、meter class/DOM位置やraw符号計算まで保証しない |
| `components/UndergroundEquipmentNavigation.test.ts` | 4→7 | sort変更でpage1へ戻る/再表示で選択保持、storageが使えない時のfallback、Gramの装備/売却操作不可。storage例外は実browserで起き得るので残せるが、保存失敗種類ごとの増殖はしない |
| `components/UndergroundPartyBattleCards.test.ts` | 5→6 | cards/portraitのbarrier表示を追加。上記共通cardが実表示を担当する部分は親の接続確認へ縮小し、各variantへ同じ表示suiteを重ねない |
| `components/UndergroundPartyPresentation.test.ts` | 13→15 | 決闘の入口/解放状態/同じUUID再送を追加。選択partyのlocal保存をserver確定party復帰へ置換。台詞testはdamage targetとbarrier表示へ変更。送信identity・party資源非resetを残し、台詞全文/全画面巡回は縮小 |

PHP projectorは「raw logを壊さず表示値へ変換」、Vue語りは「誰のactionで誰が対象か」、cardは「受け取ったbarrierを表示」を担当する。この違いがある限り複数layerの確認は必要だが、同じ符号や数値を全layerで再計算して照合しない。

testファイル自体が不変の`undergroundExplorationPending.test.ts`に対し、sourceでは確定409へ`underground_rental_party_changed`と`underground_skill_rebuild_required`が加わった。既存の確定/不明エラー分類pathを使っており、code追加だけで新testを自動追加しない。固有の未検出分岐があれば既存代表を最小限拡張する。

## 6. 前回設計から変更しない部分と限界

- Composer/PHPUnit設定、parallel runner、共通fixture、依存lockはこのmain差分で不変。map再利用と4 shard設計は継続する。Sharedへのcross-domain競合追加、SP migrationの個別lifecycle化を反映する。
- 前回の極端値・偶然の個数・全catalog再実行の削減候補は、今回変更されていない箇所をそのまま引き継ぐ。
- 1,058件を永久維持する要件は設けない。20追加も自動的には温存せず、上記の故障検出と重複削減で扱う。削減後の件数や秒数は実装・実測前に決め打ちしない。
- Gitのmain確認はproduction適用確認ではない。migration実行、DB作成、テスト本体、速度測定、production操作は行っていない。
- AGENTSの新規テスト抑制に関する原文監査・置換文案は[設計図](test-suite-rebuild-plan.md) §10を参照。AGENTS自体は未編集。

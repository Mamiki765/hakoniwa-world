# E向け：テストとDB固定値の棚卸し

2026-09-22。調査基点 `34fa906be86eedc92a769aff3e26e23d804aefba`、保存時HEAD `9b01033f894c44df3593221d7f3f59c494b00d28`。間の変更は受付発行失敗のP2修正2ファイルのみ。

これはOwner依頼による静的調査メモ。Eでの削減・変更候補であり、採用済み仕様や実装指示へ読み替えない。ここに挙げたテスト・DB制約は今回変更していない。handoffも未編集。本番DBの適用状態は調べていない。全テストの網羅評価や実行時間の計測ではなく、候補と関連するruntime・schema・後続migrationを追った結果。

## 判断基準

- 正規UIでは到達せず、手動で入力を偽造して自分だけが不利益を受け、利益も他人への影響もないケースは保護対象として広げない。Ownerが挙げた「秘書作成前の公開POSTで仮profile」はこの例。
- 他人の資産操作、不正取得・不正な強化、二重決算、共有処理の停止、通常操作での資産・進行破壊は残す。単に「UIから送らないPOST」だけでは削除理由にしない。
- 設定の現在値をテストへ写しただけの保証と、設定が実処理へ反映される保証を区別する。後者も全variantを増やす必要はない。
- テストを削ることとruntimeの入力拒否を消すことは別。削減した件数を埋め合わせるテストは作らない。
- **「設定値・挙動を維持する」は、その数値をDBのCHECKやvalidatorへ重複固定する実装まで維持する意味ではない。** Ownerの追補により、レンタル日次100枚もこの区別を適用する。実際の上限は設定の正本を使うruntimeがlock下で守り、DBには残高・非負・identity等の整合制約を残す方向を調べる。

## テストの削減候補

以下のパスはこのworktreeのrepository rootからの相対パス。行番号は調査時点。

| 対象 | 削減候補・理由 | 残す確認 |
|---|---|---|
| `product/tests/Underground/Unit/UndergroundCombatBuildTest.php:1141` | shop catalogを直接読み、rank `[1,2,3]`、IL `[1,10,20]`、価格・能力の単調増加、アクセ全stat、黒水晶短剣の価格3000等を固定する一連のassertion。現在のバランス表を第二の正本にしている。 | 実購入・装備・売却・owner・idempotencyは `UndergroundEquipmentAndRuntimeTest.php:30`、Trial解禁は同`:568`に代表あり。catalog全件の別matrixへ置き換えない。 |
| `product/tests/Unit/SecretaryItemGameplayContractTest.php:18,81,103` | rarityの文言・現行価格100/500/1500、drop pool全件・weight40/40/20、表示全文の固定を整理する候補。 | 同じmethod内にも人口効果の計算等が混在するので丸ごと削除しない。不正なdrop対象や取引可否による利益がある境界は実際の付与・取引側の代表を確認して判断。 |
| `product/tests/Feature/InquiryApiTest.php:23` | category5件と日本語ラベルの配列コピー。文言変更に合わせた二重保守になる。 | 未知categoryの入口拒否は別判断。`InquiryCategoryCatalog.php:29`は未知keyで例外、`InquiryPresenter.php:18`はそのlabelを呼び、`AdminInquiryController.php:15,23`の一覧でも使う。保存を許すと管理者一覧へ影響し得るので、未知key拒否まで自業自得扱いしない。 |
| `product/tests/Unit/TurnStateTest.php:33` | 内部LaunchIntentへの文字列座標、float座標、文字列弾数等の6組provider。runtimeの呼出箇所は `DomesticCommandExecutor.php:947` で、persisted/cast済みモデルを渡す。対応する正規操作・supported upgradeの不具合経路を確認できない。 | 実際の発射数消費、費用・命中・他島影響の代表。入力境界と同じ型異常を内部層でも全組再検証する必要はない。 |
| `product/tests/Feature/NationAbandonmentTest.php:65` | 確認名の誤りを4種類POSTするloopは削減候補。UIを意図的に迂回して自島を破棄する自己損失の文字列variant。 | 他ownerによる破棄拒否、通常UIの誤操作防止、破棄の原子性と恒久進行保持。frontend `AppSurfaceOperations.surface.test.ts:197`に実際の確認入力・キャンセル・成功経路がある。 |
| `product/tests/Feature/SecretaryEquipmentTest.php:179` | 30桁の9をslotにして、null装備PUTとoptions GETが422になることの再検証。正規操作で使わず、この入力で利益は得られない。 | 他owner装備、重複装備、slot上限突破による余分な能力獲得は残す。method全体を削除しない。 |
| `product/tests/Feature/RuntimeMutationQueryCountTest.php:29` | Nation作成→queue→sale policy→Turnを実行して最後は各query数が「0より大きい」の確認。性能上限を検出していないため、通常CIでの重複smokeを整理する候補。 | 冒頭の読み込み済みRuleset guardが追加SQL不要という責務と、4操作全部を通す計測は分けて必要性を判断。固定query数の全廃を提案するものではない。 |

補助候補：`AppSurfaceOperations.surface.test.ts:151–157` の見出し順・CSS class固定。正規操作の確認UIは残し、見た目のOwner要求を実際に守る必要がある部分だけに絞る。

保留：`SecretaryLendingCandidatesTest.php:89` は21候補の途中にDB直書きで未知catalog装備を作る。正規生成・supported upgradeからの到達根拠がなければ人工的な破損fixtureの候補。ただし実際に発生すると借り手にも影響するため「自分だけの損失」とは分類しない。

## DB制約を先に変える必要がある箇所

制約を丸ごと落とすのではなく、追加migrationで該当CHECKを置き換える。現行の報酬額やbalance設定自体を変更する提案ではない。既存履歴を新しい数値で再計算・再付与せず、保存済み値を保持する。

### 既に解除済み：STP 5/6

`product/database/migrations/2026_09_18_000000_allow_configured_trial_reward_lengths.php:57` が、levelとgrowth pathから5/6倍を強制する `underground_profiles_stp_entitlement_check` を置換済み。現在は「growth path未選択ならSTP合計0」という形。非負制約は別に保持される。同migrationでgenerated reward indexの上限10も解除済み。

schema dumpの旧値だけを見て新しい解除migrationを重ねない。この記述はrepositoryのmigration適用後の契約であり、本番に適用済みと確認したものではない。

### 候補1：初期SP最低20

- DB：`2026_08_30_050000_rebaseline_3_0_0_underground_release.php:202–222` の `underground_profiles_skill_points_check` がgrowth選択後の `skill_points_total >= 20` を強制。
- runtime：`UndergroundAlphaV1PlayerCatalog.php:62` はconfig `initial_skill_points` を読むが、20未満を「persistence minimums」として拒否。`product/config/underground-alpha-v1.php:85` の現在値も20。
- 判断：設定可能な初期値をDB都合で20に固定している例。追加migrationで20の固定を外し、非負・unspent <= total・選択前の未初期化状態・skill tree identityとの整合は残す候補。readerの固定20拒否も一緒に見直す必要がある。
- 注意：`UndergroundIntroService.php:1245`、`UndergroundRuntimeService.php:3474` は既存profileを現在の初期値と比較する。将来初期値を上げる場合、旧profileを不正扱いしない契約も必要。DBだけ緩めればすべての初期値変更が安全になるとは言わない。既存SPを勝手に配り直さない。

### 候補2：日課5Pd・ログイン10Pd/券50

- DB：`2026_09_09_030000_add_surface_paradox_and_daily_rewards.php:78–79`。`user_daily_login_award_check` は10/50の完全一致、`user_daily_quest_progress_value_check` は未完0・完了5を強制。
- runtime：`DailyQuestService.php:18` は `PARADOX_REWARD = 5`、`DailyLoginRewardService.php:13` は10と券50の定数。現時点ではconfigではなくservice定数。
- 判断：報酬の数値と永続整合を分離する候補。追加migrationで固定額を解除し、付与値の範囲、未完了なら未付与、完了ならtarget達成、日単位のunique、ledgerの残高計算を保持。0報酬を許すかは実際の仕様変更時に決める。
- 既存5/10/50の行はそのまま保持。日を跨がない再読込・retryで重複受取しない代表テストは重要。実決算後に5/10/15とassertするテストを「数値だから」という理由だけで消さない。
- Eの補填配布額とは別table・別経路。補填まで5や50に制限されているわけではない。

### 候補3：怪獣HPの乱数幅18

- DB：`product/database/schema/pgsql-schema.sql:993` の `monster_definitions_hp_check` に `hp_variation <= 18`。同名制約の後続置換は見つからなかった。
- authoring：`RulesetAuthoringValidator.php:1438–1444` も18を固定。`product/tests/Unit/MonsterFoundationContractTest.php:51` は「DBが拒否するから19を拒否」と直接テストしている。
- runtime：`MonsterSpawnService.php:204–206`、`MonsterWorldSpawnService.php:205–207` は共に `base_hp + integer(0, hp_variation)`。調査した生成経路に18専用の実行意味は見つからなかった。
- 判断：上限18だけの解除候補。追加migration・authoring validator・19拒否のassertionを一緒に整理し、非負・正のHP・DB列が保存可能な範囲・既存の怪獣HP整合は残す。smallint等の型上限を18と一緒に無条件撤去しない。
- 同じtestの `natural_spawn_tier=5` はtier解決と実行側の対応範囲に関係するため、HP幅の話に便乗して削除しない。既存published Rulesetを書き換えない。

### 候補4：競売期間3〜84

- DB：`product/database/schema/pgsql-schema.sql:466` の `auction_listings_turn_check` は期間3〜84と `ends_turn = started_turn + duration_turns` を同じCHECKにしている。後続置換は見つからなかった。
- runtime：`TradingPostService.php:429` は `TradingPostRules` の `minimumDurationTurns` / `maximumDurationTurns` を参照。current fragment `product/config/hakoniwa/rulesets/current/trading-post.php:39–40` も3/84。ただし追加調査で、`TradingPostRules.php:50–58,124–126` 自体が3/84との完全一致を要求し、返す値もliteralだった。初版メモの「runtimeはRulesetを参照」という説明だけでは不十分。
- 判断：現在の期間3/84の挙動を変えず、数値を正本へ集約する候補。追加migrationでCHECKの固定3/84だけを解除し、正の期間と開始/終了の算術整合は残す。`TradingPostRules` 側も有効な整数・範囲順序を検証して正本値を返すよう揃える必要がある。DBだけ変更しても設定変更可能にはならない。
- Eの島整理時の即時決算は、このCHECKを落としたりduration=0へ変更したりする理由にはならない。現行決算はstatusと `completed_turn` を記録する（`TradingPostTurnService.php:117,449`）。通常満期の候補選択は同`:50`なので、Eではその抽出条件と既存決算処理の再利用を検討する。ここでは専用決算系や期間改竄を新設しない。

### 候補5：レンタル報酬の日次上限100

- **Owner訂正を反映：維持するのは「レンタルでもらえる券は1日最大100枚」という設定・挙動。DBへ100を固定するCHECKの維持指定ではない。** 旧メモの維持対象への分類は撤回する。
- DB：`2026_09_08_100000_add_underground_party_lending_persistence.php:108` の `secretary_lending_daily_ticket_cap_check` が `tickets_awarded BETWEEN 0 AND 100`。後続置換なし。
- runtime：`UndergroundLendingRewardService.php:19` の `DAILY_TICKET_CAP = 100` が現在の値。`:133–161` はownerのbalance rowとdaily rowをlockし、恒久participationから券境界を計算、`max(0, cap - tickets_awarded)` の範囲に今回付与を収める。DB上限だけに依存して100を守っているわけではない。
- 判断：追加migrationで固定上限100を外し、`tickets_awarded >= 0` を残す候補。現在のruntime上限100、10参加ごとの付与、lifetime count、balance/daily lock、ledgerの残高計算、owner×day uniqueは維持する。新しい設定体系は作らない。
- 既存daily行・券残高・累計の書換えは不要。将来上限を下げても過去に付与済みの値を新しい上限へ切り詰めない。現在の計算は付与済みが上限以上なら新規付与0となる。
- テストは「現在値が100であること」のコピーより、runtimeの付与が実際に上限で止まる代表を維持する。`UndergroundLendingRewardServiceTest.php:149` は100をDBへ直接入れた後に付与0を確認しているが、累計9参加との組合せは正規状態ではない。Eで整理するなら到達可能なcap付近の整合した既存stateをseedし、1000戦をループするテストには増やさない。DB直書きという手段自体ではなく、用意した状態の到達可能性で判断する。

## 外さない方がよいもの

- ledgerの `balance_after = balance_before + delta`、残高非負、同じrequest/entryのunique、ownerのFK・装備の排他、purgeの `deleted_through_id <= verified_through_id`。
- lendingの日次上限を守るruntime、10参加境界、lifetime count、balance lockは維持。DB固定100は上の撤去候補へ移した。
- 装備slot上限、party枠、戦闘round上限等を守る挙動は、余分な強化や処理量に関わる。これもDBへ同じ数値を重複固定する必要性とは別であり、設定を読む全経路と構造上の制約を確認して判断する。
- `TurnRandomStreamTest.php` の乱数幅制限は、32bitの乱数源と棄却samplingの停止条件に由来する。任意のバランス固定値と同一視しない。
- 問い合わせの未知category拒否は管理者への表示障害につながる。ラベル配列コピーのテスト削除と分ける。
- Trialへ借用memberを無理に送る拒否は、不正なクリアや報酬に関わる。怪獣派遣costを安く偽造する拒否は他島攻撃と経済に関わる。これらは正規UIから送られない入力でも残す。

## Eの実装へ接続する際

`2026_09_09_040000_add_compensation_warehouse.php:50` の補填itemは、asset enumと `amount > 0`、`0 <= claimed_amount <= amount`。固定配布額の制限はない。既存CompensationWarehouseの配布・一部受取・再試行・ownerチェックを流用する前提で、これらの整合制約は保持する。新assetが必要な場合だけ既存のasset解決とenumを同時に拡張する。

テスト整理は上記の具体的なassertion/providerから始める。DBの候補は既存migrationの編集ではなくforward migrationで扱い、その変更に必要なfresh install / supported upgradeと代表の決算確認へ絞る。不要なテストを消す代わりに新たな全設定値matrixを作らない。

本メモはPR #161へ含めない。Eの実装・migration適用はまだ行っていない。

## 継続調査：E周辺の具体的な削減候補

Ownerの「棚卸し継続」依頼を受け、同じHEADでsourceを追加確認した。以下も実装修正ではなく候補。優先度は障害のP1/P2評価ではなく、後日テストを整理する際の順序。

| 対象 | 追加で分かったこと | 整理の方向 |
|---|---|---|
| `product/tests/Feature/TradingPostApiTest.php:780,827–878` | 再出品・返却テストの後、5〜80の76ターン分を実行し、生成されたNPC出品全行の数値を確認。最後に特定8種のitemが全部出現したことまで要求する。全種類を揃えることは出品処理の代表確認より広い。 | 全種類出現のassertionと全生成行の反復を削減する強い候補。NPCの資源/item各経路とactive上限、売れ残り返却、auto-relistは異なる責務として残す。既存RNGの代表seed/fixtureへ絞り、全商品×全状態を置換テストにしない。実行時間は未計測なので削減秒数は主張しない。 |
| `product/tests/Feature/TradingPostApiTest.php:668–778` | 実競売後の公開/非公開表示に対し、名称・単位・句読点を含む日本語全文を複数copyして比較。価格・手数料計算、private情報の漏洩確認も同居する。 | 文言全文の固定を減らす候補。実額、受取人、公開範囲、privateな売り手情報の非公開は残す。`NationAbandonmentTest.php:311` の破棄文章全文も同様。Eの公開理由を単に旧全文へ詰め込む設計にしない。 |
| `product/tests/Feature/AnnouncementApiTest.php:156–180` | admin可否とidentity非公開の確認に `auth_identities` queryが厳密に1回であることを混在させている。固定query数1は権限の意味を確認していない。 | query数のassertionと計測listenerを削減する候補。管理者判定、provider違い/別user拒否、設定未設定時の拒否、identity情報の非公開は維持。性能上の具体的な退行がある場合だけ別途必要性を判断。 |
| `product/resources/js/components/GuideConversationTopicAdmin.underground.test.ts:54–55` | 登録処理の前にtextareaが必ず7個というDOM数を固定している。API mockに与えたunlockの表示も同じmethodにある。 | textarea数の固定は削減候補。実入力からPOSTされる値、保存・再編集・削除は維持。mockで渡したunlockがUIに反映される確認は、global catalogの全件固定とは意味が違うため一律削除しない。index指定に依存する操作も、整理時には実際のfieldを識別する形へ揃える。 |
| `product/tests/Unit/SecretaryItemGameplayContractTest.php:192` | `unsupported finance bonus` はeffectの `bonus_money_per_level` を1から2へ変えただけで例外を期待する。`SecretaryItemGameplayContract.php:405–412` に実際に `!== 1` がある。 | 「既存の財政計算へ渡す量」をvalidatorで1に固定する必要性を見直す候補。現在の効果量1は変更せず、型・有効範囲と、効果の種類/stacking/timingは分けて扱う。実処理の `SecretaryRingFinanceBonus.php:25–30` は正の整数を受けてlevel×設定値を計算するので、少なくとも計算本体は1専用ではない。単にこのtestだけ消して設定を可変にできた扱いにしない。 |

### TradingPostRulesの数値固定はDB以上に広い

`product/app/Domain/TradingPost/TradingPostRules.php:50–96,122–144` は、player出品数3、期間3/84、入札増分1、手取り9/10、NPC期間6、試行3、確率40/100、資源/item上限3/2、価格域100〜1000、価格率100〜130、item level1〜5、Lv単価100等を完全一致で拒否し、戻り値もliteralにしている。

`TradingPostTurnService.php:471,516,566,593–599` はそれらの値を確率判定・価格計算・手取り計算へ渡す既存アルゴリズム。数値を変えずに正本を一つにする候補となる。一方、同じifに入っているRNG version、丸め方式、fee処理、対象itemのeligibilityはBehaviorなので、数値固定の整理に便乗して任意の文字列を許す変更にはしない。

これはcurrent RulesetのDataを変更しなくても検討できる実装整理。ただしpublished snapshotを上書きしたり、Ruleset外へ第二の設定表を新設したりしない。前提は `product/docs/architecture/ruleset-authoring.md:34–55` のBehavior/Data/Flavor分類。

同系統の追加例：怪獣dropの受取割合。`SecretaryMonsterDropContract.php:59–60` がkiller/host=75/25を強制し、`SecretaryMonsterDropService.php:41–46` は1〜100のdrawをliteral75で判定する。テストの75/25コピーだけ消してもsourceの重複は残る。現在の75/25を変えず正本から値を渡す整理候補だが、同じコードの受取人選択・所有権・RNG identity・満杯時の扱いまで変更する話にはしない。

### 似て見えるが削除に直結しない確認

- **補填8種のテスト**：`CompensationWarehouseTest.php:26` はcatalogを眺めるだけでなく、実際に受け取ってmoney、food、oil、Pd、券、地下Gの別保存先とledgerを確認する。`meat`→`monster_meat` のmappingもある。8を減らすためだけにmethod全体を捨てない。容量超過で残りが倉庫に残る`:116`、未解決Turn拒否後の同request retry`:164`も実資産の確認。
- **補填のUI retry**：`AppShell.shared.test.ts:876` は応答喪失後に同じrequestで受取確認し、受取後の画面再読込失敗で再決算しない。正規操作の通信断であり、自業自得の異常POSTとは異なる。
- **完了競売3状態**：`TradingPostApiTest.php:882` はcancelled/expiredによる返却とsoldによる所有者移転のあと、実物装備を売却できることを確認している。FKを残した履歴が現在資産の売却を阻止する問題と二重売却を扱う。状態名の列挙だけを理由に不要扱いしない。
- **負座標の島整理**：`NationAbandonmentTest.php:382` は負方向へWorldを拡張した領域の島を整理する。現在のマップとして到達可能で、負数のfloorと六角座標変換があるため、DBでfixtureを配置したというだけで不要とはしない。
- **N+1確認と固定query数は別**：`SecretaryEquipmentTest.php:449` はitem0/1/50でquery数の増加を比較する。一律に削る対象ではない。ただし5枠に同じringを装備したfixtureがある。helperは実catalogを使い、`SecretaryItemCatalog.php:55,404–407` の同一item上限は1、通常装備APIは `SecretaryEquipmentService.php:370` でそれを拒否する。N+1の代表を残す場合でも、この装備組合せを現在の通常状態として扱わない。supportedな旧保存状態の根拠は今回確認していない。
- **添付ファイルの構成テスト**：`DefaultInquiryAttachmentStackTest.php` は静的なconfig文字列比較だが、volume永続化・公開ディレクトリでのscript実行禁止・test DB隔離を扱う。runtimeのバランス設定値コピーと同一視しない。文字列完全一致の脆さは別途整理できても、保存喪失や公開画像の実行を防ぐ確認は意味がある。
- **catalog間の一致と数値の再定義は別**：`SecretaryItemGameplayContract.php:118–125,143–170` は既存global catalogとRulesetの投影整合を確認している部分がある。確認を外して両方に別々の価格/identityを許すのは目的と逆。テスト側の価格literalコピーを減らすことと、authority間の整合確認を外すことは分ける。
- **設定が変更可能であることの確認**：`UndergroundExplorationDropTest.php:11` はtest内でIL上限を90へ変更し、旧60上限が残って設定を拒否しないことを確認する。現行configをそのまま複写するtestとは異なる。値90を恒久balanceと扱う必要はないが、同様の固定制約を撤去するときの小さな代表になり得る。全ground/全数値の組合せには増やさない。

## Eで既存処理を使う際の接続点

既存処理を使うために必要な差分を先に特定するメモ。ここで管理UIや新しい仕様を実装・確定したわけではない。

1. **島整理の本体**：`NationAbandonmentOperation::execute` は所有/周辺中立cell、船、怪獣、queue、地上残高、membershipを整理する共通処理。manualとautomaticの両方から利用済み。User/Secretary/地下profileの物理削除に置き換えない。
2. **管理者と対象ownerの分離**：同operation`:45,59,185` はsourceがmanual/automaticだけ、非null actorは対象owner一致が必要、audit上のactorもowner/system二択。Eで管理者の整理を加える場合、ここを明示的に拡張する必要がある。管理者IDを対象ownerへ偽装する、actor=nullでsystem扱いする、整理処理をコピーする、のいずれも避ける。
3. **競売の終端を再利用**：`TradingPostTurnService::settleSale` と `expireWithoutBid` が現在の決算/返却本体。`execute` 全体は「全満期出品の抽出→auto-relist→NPC新規出品」まで含むため、対象島整理のためにそのまま呼ぶと範囲外の副作用がある。必要な終端を既存service内で小さく共用し、対象選択・再出品の扱いだけをE契約に沿わせる方向が候補。決算を完了してから地上残高を整理する順序が重要。
4. **補填倉庫は現状Nation必須**：`CompensationWarehouseService.php:47,108,122` の入口はNationを取り、`:64,402–425` はowner membershipを要求。migration `2026_09_09_040000...:16–18` もworld/nation/userを非null FKで保持する。島なし対象に地下G/券を渡すため、仮島を作るか別倉庫を新設するのではなく、既存grantのrecipientとasset処理を適切に拡張する必要がある。nullable化だけで完了せず、一覧・claim・idempotency・lock・surface資産の受取先を横断する。
5. **配布の決算は流用可能**：同service`:268` の `creditAsset` は既存CapacityBoundedAssetService/Pd ledger/券ledger/profile加算へ分岐する。`:244` のlock順、`:213` の保存結果再生、未受取残の保持を維持する。対象選択の新UIのために二重の経済処理を作らない。
6. **権限判定も流用**：`AnnouncementAdminAuthorizer::allows` はDiscord identityのprovider＋IDで判定する既存入口。管理ページへ移動することは新しいrole制度やuser名判定を作る理由にならない。

今回の継続調査もread-only。候補のテスト実行、CI、DB操作、runtime/schema/test編集、PR追加更新はしていない。

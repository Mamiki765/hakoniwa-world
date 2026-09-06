# hakoniwa-world 開発経緯・現行引継ぎ

> 更新日: **2026-09-07 JST**。Owner提供の2026-09-06版と、その後の本チャットのOwner決定・Codex実機報告・GitHub Support返信に基づく更新案。
> 対象: `Mamiki765/hakoniwa-world`
> 最新production報告: **3.7.2 / `daa31ca01d929ffa231241021c2bef9ad7bdb14d`**。Ownerがdeployを承認し、Codexが完了を報告した。Web版ChatGPTによる本番への独立照会ではない。
> Surface Ruleset: **v21**（3.7.0移行報告）。Underground combat **v3**ほかの継承identityは1.3参照。今回、実機の全identity・migration ledgerを再取得していない。
> GitHubアカウント停止中。最新のGitHub `main`・PR #153最終merge SHAは今回再確認できていない。**本番SHA、bareのref、PCのHEAD、GitHubのmainを同一と決めつけない。**
>
> 本書はOwnerとWeb版ChatGPTが管理する。Codex / implementation agentは原則read-only。Ownerがhandoff編集そのものを明示的に依頼した場合だけ編集する。MCPはread-onlyのままとし、更新MarkdownはOwnerがCodexへ手渡す。

# 0. 読み方と、今回の重要な更新

作業開始時は利用可能なremote、対象branch、exact HEAD、未commit差分を確認する。ここに書かれたSHAへ未確認でresetしない。実装値は対象SHAのcode / schema / effective Ruleset、release scopeと未決事項はOwnerの最新明示指示を確認する。報告ベースの項目を、次のChatGPTが自分で実機確認した結果として語り直さない。

**3.6.0では技巧・会心・敏捷・精神魔法・自然成長を再設計していない。** 防具IL由来の技巧抵抗R、魔力昇華、five-stat mean会心率、自然敏捷成長などは不採用のまま。旧handoffの6.6や研究candidateを次の実装指示として復活させない。

3.6.0のTrial 2・追加覚醒奥義・戦闘UIに続き、**3.7.0でランク2施設、ニョワミヤ、零式の自然出現条件、回想、地底ヘッダー導線**を追加した経緯がある。3.7.1を経て、3.7.2は秘書の出会い／回想に関するストーリーデータ置き場の分離を主とする更新として、本番deploy完了報告を受けた。

直近の優先課題は**GitHub停止中の開発・独立レビュー経路の引継ぎ**。OCI bare経由の転送と本番deployは成立済み。MCPのGit readerとTunnelは実機疎通済みとの報告があり、別ChatGPTチャットで`get_status`成功が報告された。**ChatGPTから実際のGit SHA・本文・diffを取得できた証拠は、まだ本チャットへ共有されていない。** 次のMCP対応チャットは7.4～7.7と8章から始める。

3.6.0以前の詳細は[2026-09-05版](archive/development-history-and-current-handoff-2026-09-05.md)を必要時だけ参照する。このarchiveは提供版に保存済みと記載されたものを継承し、今回新たに作成したものではない。#149未merge、Trial 2未実装など当時の状態を現在へ持ち越さない。

地上経営・産業連鎖の相談は継続候補だが、電力・第三次産業・PD等を実装済みにしない。**ターン数圧縮は作らない**という後発Owner決定を優先する。参考作品の[箱庭RA Final Edition設計レビュー](../../../docs/reference-analysis/hako-ra-final-edition-design-review.md)は分析・提案であり、全採用の承認ではない。

# 1. 現在地

## 1.1 GitHub・CI・production

| 項目 | 現在の扱い・確認根拠 |
| --- | --- |
| production | **3.7.2**。Owner承認後のCodex deploy完了報告 |
| production SHA | **`daa31ca01d929ffa231241021c2bef9ad7bdb14d`** |
| 直前の3.7.1基点 | `f718f548b0f9958f6488a1fbfd8d206ce76b14de`。bare初期化・転送検証にも使用 |
| 3.7.2差分 | 10ファイル、追加160行／削除248行との報告 |
| 3.7.2検証 | frontend 160 tests、ローカルPHP回帰5件成功。公開ページ3.7.2表示・HTTP 200・`/up` 200 |
| 3.7.2 migration | 変更なしとの報告。暗号化バックアップ検証済み、旧image保持 |
| 保持したもの | 既存`origin`・upstream、DBコンテナ、MCP。3.7.2 deployで変更していないとの報告 |
| GitHub | `Mamiki765`停止中、Supportへ用途説明を返信済み。復旧完了の報告はまだない |
| PR #153 | 3.7.0の実装PR、branch `codex/3.7.0`。後続の本番更新は報告済みだが、最終PR HEAD・merge SHA・Quality runは今回未再取得 |
| OCI bare | `/home/ubuntu/git/hakoniwa-world.git`、第二remote `oci-offline` |
| bareの`offline/main` | 2026-09-07 JST、CodexがSSHで`rev-parse refs/heads/offline/main`を実読出しし、文書反映前は`daa31ca01d929ffa231241021c2bef9ad7bdb14d`と確認。本番checkoutも同SHA。今回のdocs-only push後は別SHAへ進むため、次のMCPで再resolveする |

**ローカル回帰成功を「3.7.2のGitHub Quality全件成功」と書き換えない。** また、3.7.2の本番deployは既にOwnerが許可して完了報告を受けている。古い「3.7.2は未承認・未deploy」という会話を復活させない。

PR #150について提供版が記録していた履歴は、merge日時2026-09-06 14:16:31 JST、最終HEAD `5a32331ca9da2083c1ada2d353ac6990ee6f9ae0`、merge `382c838edc5c92c33302b2895eeb394e45cc58ec`、Quality `34012796576`成功。両treeは`de720796a623cb62e49f41c138163487c2a4c933`。これは3.6.0の証拠であり、現在の3.7.2の検証を代替しない。

## 1.2 直近releaseの履歴

| PR   | 内容                                   | merge / anchor                             |
| ---- | ------------------------------------ | ------------------------------------------ |
| #140 | Surface water ownership限定修復、v20開始    | `cea7f39992c5885317b6102aa42469ea32fcaaba` |
| #141 | Ship persistence / projection、港      | `763a0993e5533eb9318048af925abb9f48206ade` |
| #142 | 船舶建造・任意廃船                            | `c2ed1e66ca50d93119ea4766fc718402dcdc3446` |
| #143 | 航行・燃料・報酬・秘書XP・forced displacement    | `dd76f7fbe0eb0bbcb07420a20c4b62f379d8899f` |
| #144 | Ship-first missile、visibility        | `22203ae9f7b06607bfa6a5a6821bb948e011f634` |
| #146 | 3.5.0 main昇格                         | `c79a5dac056a20609137477e6ac0c112fab4990d` |
| #147 | Owner確認済み未適用3.5.0 migrationの統一       | `146687a5d5f3f2703f405be6532fae562da3bd58` |
| #148 | Hotfix 3.5.1、command定義JSONのarray復旧   | `4e4d9b85524df5b31a874ec5083c9f74be5cedcd` |
| #149 | 3.5.2 UI / UX、漁獲overflow、レビュー修正      | `70871f9e3345ac183e3c2b97b3f499ad672ba6bb` |
| #150 | 3.6.0 Trial 2、選択式覚醒奥義、戦闘UI、release修正 | `382c838edc5c92c33302b2895eeb394e45cc58ec` |
| #152 | 3.6.1 古いIL／Lv固定上限のhotfix | `78e688c1e79a78ae938750629a64352e34f398e0`。当時のmerge確認報告 |
| #153 | 3.7.0 ランク2・ニョワミヤ・回想・UI | `codex/3.7.0`。最終merge SHAは今回未確認 |
| PR番号未確認 | 3.7.1 本番／bare初期化の基点 | `f718f548b0f9958f6488a1fbfd8d206ce76b14de`。実機報告 |
| GitHub外の本番反映 | 3.7.2 秘書ストーリーデータの分離等 | `daa31ca01d929ffa231241021c2bef9ad7bdb14d`。実機deploy報告 |

\#149は`5bad2cd`再レビュー後にhandoff commit `b30fee8`を追加し、同HEADのQuality成功を確認してmergeした経緯がある。当時の未merge記述を現在へ持ち越さない。

## 1.3 現在のidentity

```text
application_version: 3.7.2
Surface Ruleset: hakoniwa-2s-plus-v21
Underground combat: secretary-underground-alpha-v3
awakening: secretary-underground-awakening-v2
presentation_log_version: 2
Trial 1: secretary-underground-trial-01-v2
Trial 2: secretary-underground-trial-02-v1
exploration: secretary-underground-exploration-alpha-v2
shallow_caves: secretary-underground-exploration-alpha-v1
black_crystal_cave: secretary-underground-black-crystal-cave-alpha-v1
exploration drop: secretary-underground-exploration-drop-alpha-v1
shop equipment: secretary-underground-shop-equipment-alpha-v2
generated equipment: secretary-underground-drop-equipment-alpha-v1
```

applicationとSurfaceは後続報告で更新した。Underground各identityは提供された3.6.0版からの継承値で、今回3.7.2のcodeから再抽出していない。基礎戦闘・成長の再設計を採用したとの報告はない。identity自体が必要な作業では、同一SHAのconfigとoverrideを読み直す。

3.6.0ではSurface v20を維持し、**3.7.0でv21へ移行した**。IL90までの装備拡張は既存入力の出力を保つdomain extensionとしてgenerator identityを維持した経緯がある。将来変更時もidentityが不要という一般規則にはしない。

## 1.4 Migrationと本番境界

3.6.0のforward-only migrationは、`product/database/migrations/2026_09_06_000000_add_underground_awakening_technique_selection.php`。nullableな`awakening_technique_key`を追加し、NULLは既存技へfallbackする。既存playerの能力再計算や旧battle／JSONBの書換えは行わない契約を継承する。

3.7.0の追加migrationとservice（Codex checkpoint報告）：

- `product/database/migrations/2026_09_06_010000_add_underground_recollections.php`
- `product/database/migrations/2026_09_06_020000_publish_v21_3_7_0_release.php`
- `product/app/Application/Ver370RulesetUpgrade.php`

**通常のappend-only forward migrationであり、rebaselineではない。** 自然出現`natural_spawn_tier`のDB制約を1～4へ拡張し、exact v20 checksumを検証してv21をpublishする。v20 payloadは不変。queued command、生存怪獣、討伐集計をstable keyで移行し、船のv20 provenance、履歴、request identityは変更しない。実行definitionの移行と、依頼時点のrequest provenanceの保持を区別する。

未解決の次TurnRunがあれば公開前にfail-closed。`down()`はforward-onlyとしてbackup restoreを要求する。fresh DBにはv19・v20・v21を保存し、currentをv21にするとの報告。

migration checkpoint時の成功報告は、fresh baseline 2 tests / 152 assertions、supported-upgrade 2 / 21、3.7.0 focused 18 / 440、および`migrate:fresh`成功。**途中の作業ツリーに対するcheckpointであり、これだけを最終release HEADのCI証明にしない。** 3.7.1→3.7.2はmigration・Ruleset変更なしとの報告。

3.5.0で#147の統一を認めたのは、Ownerがproduction 3.4.0 / v19と旧4本未適用を確認した時点の限定承認。以後の適用済みmigrationを統合・削除する許可ではない。migrationが27秒かかったという会話だけから、原因やrebaselineの必要性を断定しない。

本番操作前にはactual checkout / application / Ruleset / migration ledger / 未解決Turnを別途確認する。cronは未解決Turnを自動retryしない。新コードのmigrationは、実際に新コードを使う実行環境で行う。旧稼働コンテナへ`exec`しただけで新migrationが見えていると仮定しない。backup・manual retry・restoreはcurrent runbookとOwner gateに従う。

## 1.5 既存Ship契約の要点

- ShipはNation-ownedのWorld actor。Monster、秘書itemとは別。1cellに最大1隻。
- 港建設は条件を満たす中立shallowをlandへ変更し、自国portを置く。港数で船の保有上限は増えない。
- 漁船・観光船・探索船は各最大3隻。spawnできなければ建造費・turnを消費しない。任意廃船は通常commandで返金なし。
- 通常航行はsea。canonical sea擬態facilityとの同居条件を維持し、見た目で判断しない。
- 漁船は航行ごと石油1万バレル→魚7,000t。観光船は石油2万バレル→20億円。探索船は石油1万バレル、直接報酬なし、visibility 3hex。
- randomized process\_cells内、Ship IDごとに一turn最大一回。自国port有無はphase開始時snapshot。最後の港を同turnに失った影響は次turnの通常航行から。
- heading NULLはrandom。指定進路不可時のbounded fallbackを維持する。進路変更自体はturn不要。
- 成功航行は秘書ship\_operationsへ基礎XP+1。既存modifierを適用。準備中の技能へ固有効果を勝手に追加しない。
- forced displacementは通常航行ではなく、燃料・通常報酬・秘書XPを発生させない。
- NPC海賊、船修理、destination pathfinding、船Lv／XP、汎用Ship AIは未実装の別scope。

細かな休眠・復帰、missile、visibility、port候補順はcurrent Ship codeと旧版1.5を必要なときだけ照合する。

## 1.6 漁獲overflow修正

\#149で航行報酬のfood overflowを既存FoodOverflowResolverへ接続した。食料庫満杯時は魚在庫ではなく標準売却収入へ反映され得る。航行距離・燃料・基礎報酬・秘書XP自体を変更したものではない。

## 1.7 既知残件・現在の扱い

| 項目 | 状態 / 次の扱い |
| --- | --- |
| 廃船の表示対象と登録対象の同一性 | 提供版の残件を継承。後続修正済みかは未確認。調べる際は同一Ship ID / intent境界を確認 |
| 強制退避の別自国港探索 | **Owner採用決定済み**。最寄り不可なら別の自国港も探す。後続の実装完了は本更新では確認できていない |
| 漁獲overflow損失の実測・補填 | 本番audit調査・支払い完了の新報告なし。修正deployと補填を混同しない |
| 「地底」ヘッダー | 3.7.0の実装報告とOwnerスクリーンショットあり。将来要望から除外。未命名時は名付け側へ誘導 |
| ランク2・ニョワミヤ・零式自然出現 | 3.7.0の実装範囲。1.11～1.12参照 |
| 回想の全文表示 | 2.5のOwner契約を維持。3.7.2でstoryデータ分離のdeploy報告あり。最終本文の全文一致はこの更新で未独立確認 |
| マニュアル全面改稿 | 13章のZIP・プレビュー・反映指示を作成済み。最終配信への全章反映は未独立確認。6.8参照 |
| OCI Git代替経路 | 構築・転送検証済み。3.7.2本番deployにも到達。7.3参照 |
| MCP / Tunnel | Phase 1・Tunnel起動・別チャットの`get_status`成功報告あり。次はChat側でGit本文・diffの実読出し |
| 地上HQアイテム | 3.7.0では見送り。案の存在を入手・装備可能の根拠にしない |
| 産業・電力・第三次産業・PD | 設計候補。基礎三施設のランク2実装と分離する |
| 32×32素材制作 | Skill作成／アップロードの話は進展したが、実素材を使うpixel-perfectな一連の成功は未確認。6.4参照 |

別港探索は隣接valid sea優先を維持し、その後の自国港候補を既存の距離・座標・ID順で順次調べる小さな変更案がある。採用決定を再び未決に戻さず、未確認のコードを実装済みとも書かない。

## 1.8 PR #150のレビューと測定（3.6.0の履歴）

初期HEAD `64ddb238`での独立レビューは次を指摘した。

1. 夜宴の血侯のlifestealがplayer条件で無効。
2. 新攻撃奥義4技へ既存敏捷comboが未接続。
3. Git情報不明を明示SHAだけでdirty=falseにしていた。

最終PR body、後続code、更新された報告では修正されている。通常吸収は敵／player共通、修羅の追加だけplayer固有。新技は既存の一行動一combo抽選を使う。Gitを検証できない環境をclean認定しない。追加レビューのapplication 3.6.0表示、通常／敵吸収ログ、served manualのTrial 2 drop説明も後続release修正対象となった。最終HEADのCI成功が現在の検証状態であり、初期失敗を残件扱いしない。

測定の正本は`product/docs/underground-trial2-v1-200-seeds.md`。source `a644447f62bfb3a3749bd57916471c6a58d977b7`、manifest hash `069c03893ac7389e3c71917c2a5dbc332c17c14e06f7d4de174124bc3b66e487`。

- 16scenario各200seed。Lv150/180・SP60・**generated IL50 uncommon（ハイクオリティ）武器／防具／アクセサリ各1部位**。ノービス一式の結果ではない。
- 主比較の戦技clear率は**Lv150 16.0%、Lv180 71.5%**。修正前17.0% / 72.5%と区別する。
- 護身・祝福・自由の代表例は両Lvで200/200clear。ただし有限seed・特定buildの結果であり、全配分の安全性証明ではない。
- default AIでは護身と祝福は新奥義を使わなかった。覚醒優先AIの20seed比較で両技を実使用できることを別途確認した。
- 同条件の天断／修羅200seed比較ではclear率同じ、天断が平均1.01～2.99round短い。修羅を常時上位と証明したものではない。
- 途中のレベルアップと装備変更はsimulationでは固定。実playerの浅層育成・装備更新も攻略手段にする。

ここまでの測定値とレビュー経緯は提供された3.6.0版から保持した履歴。今回のhandoff更新で200seed、全テスト、当時のGitHub CIを再実行・再取得したとの主張ではない。

## 1.9 漁船incidentと補填境界

ナム孤島turn368～371の「船が中立」は、後のtooltipで船主は自国、下の海が中立と確認した経緯がある。船籍DB破損と断定しない。

実際のoverflow損失、修正deploy境界、資金上限、払い済みの有無は本番auditで確認する。正常完了・非dry-run turnのship.moved resource\_requested / applied / overflowを元event IDごとに調べる。建造数×経過turn、現在残る船だけ、過去Turn再実行で補填額を作らない。

当時の売却lotと資金上限まで復元するか、売却相当額の救済にするかは別Owner判断。以前の63億円は例で確定額ではない。燃料・船代・XPを自動的に重ねて返さない。dry-run→承認→既存lock / transaction / capacity / auditで支払い、満額入らない分を無言で破棄しない。

## 1.10 3.6.1：古い固定上限の除去

PR #152は3.6.0後のhotfix。宝物庫まとめ売り`item_level_max`の1～60固定を「1以上」へ変更し、将来のILを検索条件として拒否しない。戦闘シミュレーターのLv／IL1000、story benchmark Lv10000固定も除去し、正整数と計算可能な整数範囲を検証する。Lv1254 scenarioの回帰テストを追加したとの報告。

探索場所・敵drop metadataは、生成器の現在の対応IL上限へ連動させる。検索条件に高いILを指定できることと、生成器がそのILの装備を生成できることは別。確率・列挙値・DB/PHPの表現限界・処理件数による負荷制限・明示されたgameplay上限は維持する。Ruleset／migration変更なしのhotfixとしてmergeした経緯を保持する。

## 1.11 3.7.0：ランク2施設

次はOwner確定値と実装checkpoint報告に基づく。rank専用列や別の施設HPは増やさず、Rulesetと現在の`facility_scale`から導出する。保存単位と画面の人数を混同しない。関連経路は`FacilityRankPolicy`、`FacilityScaleDamageService`、建設executor、災害／missile／表示／出現条件。

| 施設 | ランク1上限 | 上限到達後の整備量 | ランク2最大 | 表示する耐性の要点 |
| --- | ---: | ---: | ---: | --- |
| 大農場 | 50,000人 | ＋1,000人 | 100,000人 | 森3個分の台風耐性 |
| 大工場 | 100,000人 | ＋5,000人 | 200,000人 | ある程度の火事・地震耐性 |
| 大採掘場 | 200,000人 | ＋2,000人 | 400,000人 | 陸地破壊等へ規模損失で耐える |

旧上限ちょうどはランク1。さらに同じ整備を行い、**上限を超えた規模がランク2**。被害で旧上限以下になれば即降格し、耐性・表示・自然出現資格へ同じ判定を使う。例：大農場51,000人へ3,000人の損害なら、48,000人の農場として残り、次の一発からランク1として扱う。

| 破壊の種類 | 大農場 | 大工場 | 大採掘場 |
| --- | ---: | ---: | ---: |
| 荒地・焦土にする通常破壊 | −1,000人 | −5,000人 | 元来対象外の破壊を新たに追加しない |
| 陸地をえぐる破壊 | −3,000人 | −15,000人 | −2,000人 |
| 火災・地震の工場被害 | 既存の対象条件 | −20,000人 | 既存の対象条件 |

広域災害は名前だけで分類せず、実際の地形破壊処理に対応させる。巨大隕石周囲1は陸地破壊、周囲2は通常破壊の扱いが基本だが、山など元来対象外の地形まで壊す仕様にしない。**巨大隕石の中心直撃は耐性無効。怪獣の踏み荒らしは従来どおり**とし、「足を止めてミサイル10発分の施設攻撃」は未採用。

大農場自身の台風判定は`max(0, 6−N)/12`から`max(0, 3−N)/12`へ変わる。抽選成立時の消滅は防がない。近隣の別農場へ本物の森3個として作用させず、伐採・火災防護などへも流用しない。

部分破壊のKARMAは加点対象なら基本＋1、三施設とも陸地破壊は＋3。大採掘場の規模損失を、それに合わせて6,000人へ増やさない。マス詳細はランク1・昇格条件、ランク2名・短い耐性説明を出す。

GIFは後半のPR確認報告で`Land702.gif`、`KLand47.gif`、`land19.gif`への接続とOwnerの外部配置済み報告があった。施設別対応・大文字小文字は現行asset resolverが正本。「3枚未提供」の古いcheckpointへ戻さない。ただし本更新では配信を再確認していない。

## 1.12 3.7.0：ニョワミヤ・零式・怪獣経験値

人口50万人以上の候補は**既存7種＋珍獣ニョワミヤ＋メカいのら零式の均等9枠**。後二種が当たり、ランク2施設がない場合だけ既存7種から一度再抽選する。キング固定へ戻さず、9枠の引き直しや無制限loopにしない。零式は既存の怪獣定義を自然出現候補へ追加するもので、新規MonsterDefinitionが必ず二種増えると決めつけない。

珍獣ニョワミヤは`monsnyowa.gif`、HP1、経験値20、キング相当の残骸。特性「先行移動」「ニョワミヤ」。通常怪獣の先頭へ並べるだけでなく、ミサイル基地等のマップ処理より前に移動する。通常処理で二度動かさず、移動後のoccupancyをmissile・弓・KARMAの適切な境界で観測する。

踏み荒らした場所・討伐後は平地。HPダメージと討伐を、不機嫌な顔／大量のチーズを置いて帰る専用ログで表現する。**チーズ資源は追加しない。** 残骸・怪獣肉の既存保管／分配経路を使う。その後の着弾で平地が焦土になることはある。

経験値は次の区別を維持する。これは前会話のcode調査結果を引き継ぐもので、今回のMCP実読出しによる再検証ではない。

- 発生単位は撃破一回ではなく、damage処理ごとの**実HP減少量×`experience_per_damage`**。無効damage・overkillの余剰は対象にしない。
- 地上／海底基地由来は当該基地EXPへ、地底ミサイル基地／秘書弓由来は秘書の地上「怪獣討伐」EXPへ。二重付与と解釈しない。
- 残骸資金、怪獣肉、kill stats、秘書Item dropは撃破時の別処理。**地底Combat EXPは別系統**。

## 1.13 3.7.1～3.7.2と、過去レビューの扱い

3.7.1の詳細全差分は本チャットに揃っていないため推測で補わない。3.7.2はOwner説明で「秘書ストーリーデータの置き場を分離する簡単なコミット」。検証／deploy報告は1.1のとおり。2.5の回想契約に対し、本文が実際に全文で再利用されているかは、必要時に該当SHAの初回表示・回想表示・storyデータだけを照合する。

PR #153の途中HEADでは、部分被害イベント`facility.partially_damaged`のOwner／公開島ログ接続漏れ、回想のための全試練戦闘履歴取得、現在のgrowth pathで導入回想の過去分岐が変わる点が指摘された。その後も複数の修正・レビューが進んだため、**古いHEADへの指摘を無条件に現在の未解決P2へ再登録しない**。最終的な修正確認を求められたときだけ現行経路を確認し、解消済みとも未解消とも根拠なく断定しない。

# 2. 地下RPGの継承契約と3.6.0

## 2.1 Trial 1

「地下に眠る古代遺跡」は10連戦。HP carry、1～9戦勝利後max HPの20%回復、MPは毎戦10,000。active run中に宿を使わない。勝利後は次戦へ、敗北／撤退でrun終了。画面更新でも進行を保持する。

初回clearのみSP40・物語・覚醒・地底layer1（4設備枠）。再clearで初回報酬は重複しない。合計800EXP / 205G。**Trial 1は通常装備dropなし、Trial 2はあり**と区別する。

## 2.2 覚醒共通仕様と選択技

gaugeは永続、内部最大1000。default AIは満タンかつHP20%以下で発動を試みる。custom AIでは明示した覚醒ruleを使う。一戦最大一回であり、試練全体で一回ではない。activationそのものは通常actionを消費せず、HP／MP全回復・装備込み五能力+30%を戦闘終了まで適用する。

3.6.0では各growth pathの既存技／追加技を戦闘間に一つ選ぶ。選択NULLの既存playerは既存技。再振り時はNULLへ戻す。取得に追加SP・新skill node・Trial 2clearは要求しない。覚醒本体は通常のdispellable buffではない。

| 系統 | 既存技                      | 追加技の採用内容                                                                               |
| -- | ------------------------ | -------------------------------------------------------------------------------------- |
| 戦技 | 天断一閃                     | 修羅の血脈：potency160%の物理初撃、武力80%／技巧20%／weapon100%。初撃含む3round、実HP damage15%吸収、既存吸収との合計上限25% |
| 護身 | 絶対護界：2round直接damage90%軽減 | 城塞撃：potency180%、生命75%／武力25%／weapon80%、攻撃後に次direct hitを既存guardで受ける                      |
| 祝福 | 生命讃歌：HP全回復、MP回復なし        | 裁きの天光：potency220%、精神85%／技巧15%／weapon100%、会心可、damage後に解除可能buffをkey順で一つ除去                |
| 自由 | 無窮再演：MP全回復・通常技CT解除、行動継続  | 無相の一撃：potency240%、技巧100%／weapon100%、会心可、実効物理／魔法防御の低い側、同値physical                       |

追加四技はactionを消費する。修羅の吸収はoverkill・障壁で吸収した分・反撃・継続damageを含めない。無相は一撃であり防御無視や二重攻撃ではない。新しい魔力昇華はない。無窮再演で覚醒奥義を再使用可能にしない。

## 2.3 Trial 2「黒曜石の魔窟」

Trial 1初回clearで解禁、level hard gateなし。比較／攻略の想定はLv150～180帯・SP60・IL50級。低Lv突破、育成での押し切り、浅層での装備更新を許容し、正解buildを当てるだけの試験にしない。

|  戦 | 敵名        | 種族      |    EXP / 欠片 | 装備IL  |
| -: | --------- | ------- | ----------: | ----- |
|  1 | 煤牙の斥候     | ゴブリン    |    250 / 65 | 55–66 |
|  2 | 嘲炎の道化     | インプ     |    260 / 68 | 56–67 |
|  3 | 鉄鎖の獄犬     | ヘルハウンド  |    270 / 70 | 58–69 |
|  4 | 不寝番の石翼    | ガーゴイル   |    280 / 72 | 60–72 |
|  5 | 赤角の破城兵    | ミノタウロス  |    290 / 75 | 62–74 |
|  6 | 弔鐘の司祭     | レイス     |    300 / 78 | 64–76 |
|  7 | 蠱惑の蛇姫     | ラミア     |    320 / 82 | 66–78 |
|  8 | 夜宴の血侯     | ヴァンパイア  |    350 / 88 | 68–82 |
|  9 | 誓約喰らいの黒騎士 | デーモンナイト |    400 / 95 | 72–86 |
| 10 | 首なき断罪卿    | デュラハン   | 2,000 / 540 | 76–90 |

10戦、HP carry、1～9勝利後20%回復、各戦MP reset。各勝利のEXP・欠片・dropを同一settlementで即時確定し、戦闘間帰還でも持ち帰る。新runの再勝利は通常報酬を再取得できるが、同一request再送／表示更新では重複付与しない。未勝利battleのwithdrawalと、勝利後のrun帰還は別。

1～9平均は約1.25倍、10戦完走平均はXP・欠片とも黒晶洞名目平均の約2倍。合計4,720EXP / 1,233G。平均倍率は勝利・抽選前提付きで実時間効率そのものではない。魔窟装備は既存のIL売値式を使い、専用の売値倍率は付けない。護符は主能力別に5名称。

初回clearは**SP+40（Trial 1後の60から合計100）、地底layer2／8設備枠、Owner提供の物語**。追加覚醒・特別item・称号なし。初回挑戦／初回clear物語を再挑戦へ自動再表示しない。`(秘書名)`を実際の秘書名へ置換する。

舞台は黒晶洞最奥の封印の地。案内人はサキュバスの元魔王であり、敵の誘惑枠はサキュバスではなくラミア。物語の詳細はOwnerの文章とcurrent実装を正本とし、以前の自由案で書き換えない。

## 2.4 地底施設と公開境界

Trial進行とlayer権利はSecretary-owned、施設はNation-owned。同じ秘書で島を作り直しても権利は残るが旧島の施設を持ち越さない。

1layer=4設備枠。地底都市は首都effective最大人口+10,000、地底農場は農業労働容量+10,000、地底工場は工業労働容量+30,000、地底ミサイル基地はcapacity+1。建設・撤去はofficial Turnを一つ使う。入口・梯子は設備枠ではない。Surface MapCellや3D Worldを新設しない。

現行の地底マップは自島画面、見出し「首都地下」、正方形tileの5列連続配置。選択時だけ赤枠と詳細を出す。他島からも地底mapと秘書戦闘Lvを閲覧できるが、操作は自国権限へ限定する。AI画像の閲覧consentを維持する。

## 2.5 3.7.0回想と秘書の出会い：Ownerの確定契約

案内人の部屋の「少しお話が～」と再振りの間に回想を追加する。既に見た導入・試練開始・初回クリア等を再閲覧する。Trial 2の**初回クリア後**に「過去について問う・1」を解禁し、1～5を順に読むと次が開く。5の読了で「案内人に真剣な話をする」へ進む。会話の再選択を認める。

**回想はイベント本文の全文再表示であり、あらすじの新規作文ではない。** 初回表示と回想は同じstory正本を再利用し、本文を別々に短縮管理しない。表示だけを行い、名前入力・名付けmutation・戦闘・報酬・初回解禁を再実行しない。

秘書との出会いは、未命名の秘書画面を初めて開いたときに見せる本文。回想も、その本文を**「私の名前は――」まで省略せず**表示する。ここで終わるのは正しい。初回はその直後に名前入力へ進むため、勝手に名付け後の会話を創作して付け足さない。「秘書画面を初めて開いた時～」という操作説明で物語本文を要約するものでもない。

初回名の履歴がない場合、必要な名前置換だけ現在名を使う既定fallbackは許容する。それを理由に回想全体を「現在の名前は○○です」だけへ置換しない。現在名のfallbackと、当時の選択分岐を捏造することは別問題。storyを直す場合も明白な誤字は通常校正し、意味・設定・ニュアンスの変更だけOwnerへ確認する。

「地底」ヘッダーは追加済みの扱い。未命名なら地下APIへ進まず、`？？？`の名付け導線へ誘導する。これは秘書の出会い本文を省く許可ではない。

案内人の本名分岐・抱き締める等はOwner原稿を正本とする。**夢の女王戦・PT・魔剣グラムはruntimeへ入れない**。将来戦闘の構想と台詞は`docs/future-systems/guide-dream-queen-battle.md`へ保存する作業報告がある。没話は没話として分離する。

# 3. 装備・敏捷・資源

## 3.1 敏捷は現行のまま

高い実効敏捷が先手、同値のみ既存tie-break。相対敏捷差による回避・comboと行動阻害抵抗を維持する。新しい自然敏捷成長は採用していない。

damage action一回につきcomboを一回抽選し、native多段でも同じ結果を使う。追加action、追加damage event、追加会心、追加status抽選、追加覚醒gainを生まない。軽減後damageへの倍率である。ワイバーン敏捷12を別contentの都合で変更しない。

## 3.2 装備

既存5枠はweapon1・armor1・accessory3。generated itemは保存したpayload / identityを使用し、旧装備を現在catalogで作り直さない。3.6.0で生成IL上限を60から90へ拡張し、60以下のanchor・既存入力を維持した。

shopのノービスとgenerated rarityのレギュラー／ハイクオリティ等を混同しない。Trial 2報告はuncommon3部位の限定比較である。Unique、強化、汎用enchant、marketは別の将来scope。

## 3.3 地上資源との区別

current Surface resource definitionsには小麦、魚、怪獣肉、工業品、鉱物、石油がある。石油内部単位は万バレル。地下EXP／欠片と地上資源を無断で統合しない。

今後の産業案は、この既存の農作物・工業品・鉱物・石油を活用する方針。基礎三施設のランク2は3.7.0範囲だが、電力や加工品のruntimeとは別。基礎資源を細分化するか、加工結果を新在庫にするかは未決。

# 4. 既存育成・倉庫操作

直接STP入力、有限SP、skill取得、active slot、複合再振り、倉庫bulk saleは既存仕様を維持する。再振りは自然成長・手動割当・skill・奥義選択の関連を既存経路で扱い、無料回復や初回報酬の再取得へ転用しない。

削除・売却などのpreviewとmutationは同じ対象identityを保つ。現在の選択や画面表示だけで過去requestを解釈し直さない。具体的なcost / cooldown / constraintはcurrent serviceとtestsを読む。詳細経緯は旧版4章を参照する。

# 5. AIと戦闘ログの境界

custom AIはplayer保存済み設定を使い、既定AIを新ダンジョン専用に無言で差し替えない。覚醒HP条件の変更だけでも攻略時間や発動率は変わるため、比較報告へ明記する。

一つのexecuteTurnに覚醒activation・奥義・通常行動が含まれる場合がある。技の宣言、MP cost、効果、反撃、継続効果、自然回復を混同しない。PT、manual combat、万能action frameworkは未実装。

# 6. 表示の現在地と、次に相談するもの

## 6.1 3.6.0戦闘UI

冒頭で最終HPを見せず、遭遇→第1round開始状態→行動→次round開始状態→最後に決着状態・勝敗・報酬。次round開始カードは前roundの全終了処理後であり、次round開始処理後と偽らない。

途中の状態カードを折り畳まず、末尾の分析統計を「戦闘詳細」にまとめて初期closed。「末尾へ」を維持。覚醒gauge満タンと覚醒中を区別する。内部0～1000を生の数値として見せず、barを基本にし、アクセシビリティ上の値はpercentageで伝える。fill以外のcard背景／外枠を勝手に発光させない。

専用覚醒portraitの登録・歴史的画像snapshot機構まで実装済みとは扱わない。stateの覚醒中表示と登録画像の表示を区別する。

## 6.2 旧ログを変えない

presentation v2は新battleのinitial\_stateとround boundary等の投影を追加した。旧v1／flat logは保存済み情報でfallback。旧battleを再戦・再計算して補完しない。不足状態を現在profileや0で埋めない。表示やdetails開閉で抽選・報酬を再実行しない。

## 6.3 次の主題：地上の産業連鎖

Ownerの意図は「箱庭がメインなので、地上の島経営を豊かにする」。限られた土地、とくに100マスを意識した島づくりの中で、一マスの生産能力・役割・防災を育て、空間を有効利用する。100マスをWorldの絶対面積上限へ読み替えない。

基礎の農場・工場・採掘場は人口と土地を基盤にし、追加の燃料等を必須にしない方向。将来の別系統の上位施設は、追加の労働人口ではなく基礎資源を利用する案。**今回の「大農場」等は基礎施設の規模拡張であり、人口不要の自動工場ではない。**

**ターン数圧縮は作らない。** 成熟した施設が災害で全損せず規模を失って残る方向は採用し、ランク2として具体化した。PD（輝石／パラドックス）、電力、加工、第三次産業、複数マス合体などの案は将来候補。以前の「20PD＋3倍資金で整備を時短」や整備量増幅案を、そのまま次の確定仕様へ復活させない。

RAの調査対象としてOwnerが指定したのはGoogle Driveの「箱庭リファレンス / hako-r-a_FE」。
`https://drive.google.com/drive/folders/1hjeYuiMFwmXf1Qz7_WtvA7zcXKD4VJgI`

Owner提供のRA最新版として扱うが、世界中の公開版の最新版を確認した意味ではない。調査scopeを無断で他の兄弟folderへ広げず、原理の参照と第三者code／素材の利用条件を区別する。

## 6.4 素材制作・ドットSkill

箱庭用の地形下地（平地・荒地・海・浅瀬）へ、人とAIが描き足す制作環境を目指す。**地上チップは32×32を最小単位とし、一行おきに横16pxずれる配置を扱う**。行parityは対象の地上rendererを正本として確認する。地底の正方格子や、アイソメトリックな立体台座と混同しない。

単体チップ、選んだ複数の箱庭マスへまたがる施設、地形を残す透明overlayの三用途を想定する。占有マスと描画範囲は別に持つ。複数マスを単純な32×行列だけで切り出さず、stagger込みの隣接previewを考える。下地ロック、palette、layer、nearest-neighbor拡大、原寸検査、編集元データ、GIF出力が候補。

`pixel-art-authoring`／`hakoniwa-pixel-art-authoring`というSkill名で作成・利用を試した経緯があるが、**このチャットでSkill本文・同梱assetsを読んだうえで制作した成功例は未確認**。画像生成へ流れてペリドット／ペコリットの大判イラストが出た結果を、32×32ゲーム用GIF完成やSkill動作確認済みと扱わない。

Ownerは荒地・平地・海等の実画像をSkillの`assets/`へ同梱したい。更新ZIPの準備・再登録と、実際の画像参照確認が次のTODO。汎用Skillでサイズを固定しない方針と、箱庭専用の既知の32×32規格を省略することは別。Skillの名前だけでなく実体を確認する。素材の出所・利用条件を保持し、無条件の再配布可とは判断しない。

「地底」ヘッダーは実装済みの扱いへ移したため、今後の素材ツールの未実装項目には含めない。

## 6.5 ほかの将来候補

NPC海賊、船修理、第三狩場以降、Unique、enchant／装備強化、案内人再戦、専用覚醒画像、PT、manual combat、marketは必要になった時にOwner gateで検討する。Trial 2はこの一覧から除く（3.6.0で実装済み）。

## 6.6 技巧・成長研究の結論：今回不採用

旧版6.6の相対技巧、会心倍率、防具IL共通R、魔力昇華、魔法係数変更、自然敏捷成長、祝福武力+0を含む自然成長候補は、検討を経て**今回すべて採用しない**とOwnerが決定した。

「成長曲線」はこの会話ではLvごとの能力上昇を指しており、必要EXP曲線を変える依頼ではなかった。その能力成長も現状維持。既存の精神型、敏捷、ワイバーン等へ広範な影響を出さないことを優先する。

将来、自由へ**技量型のskill tree**を追加し、安定型とは違う上振れ／下振れの遊びを局所的に作る案はある。現行の技巧を全員向けに改造する計画ではなく、現在の実装scopeでもない。

研究結果は参考として残してよいが、candidate codeを新releaseへ自動的に混ぜない。旧版の「Owner確定」「昇華は撤回ではない」は当時の相談記録で、今回の最終判断に優先しない。

## 6.7 行動ログ形式：実装済みと未実装

presentation\_log\_version=2、初期状態、round開始／終了、覚醒gauge表示は#150で実装済み。旧6.7にあった汎用action\_id／parent\_action\_id／source等の全提案が実装されたわけではない。

MP costと効果の所属をより明確にする将来改善は、必要な保存情報だけで行う。v2という番号だけを根拠に、全行動を再生できる完全traceが存在すると判断しない。

## 6.8 プレイヤーマニュアル全面改稿

OwnerはMVP時代の未実装宣言・古い経験値説明・特定アイテムの過剰な特例説明を問題視し、3.7.0向けの全面改稿を依頼した。旧マニュアルは仕様の根拠にせず、説明順の参考や矛盾確認だけに使う。

前会話で`hakoniwa-2s-plus-manual-3.7.0.zip`、全章HTML、Codex反映指示を作成・共有済み。入口／はじめの一歩／土地と施設／人口と資源／災害／ミサイルと怪獣／港と船／交易場／地上秘書／地底探索／育成と戦闘／地底装備／困ったときの13章。最終的なserved manualへの反映状況は今回独立確認していない。ZIPが必要なら既存成果物を取得し、ゼロから作り直さない。

**プレイヤーが遊び方を調べられる説明書にする。内部仕様と全例外の百科事典にしない。** 主要施設や2S＋固有機能は個別に説明し、個別アイテムの細部はゲーム内効果欄へ委ねる。大枠と重要な単位・受取先・費用・解禁条件が現行と整合することを重視し、依頼のない25項目全監査や延々としたP2レビューを再開しない。明白な相違はその箇所だけ実装で確認する。

特に怪獣の実HP damage EXP／基地EXP／秘書討伐EXP／撃破報酬／地底Combat EXP、地上と地底の通貨・装備、STPとSP、試練途中帰還を分離する。futureの地上HQ・夢の女王・グラムを混ぜない。既存URL互換は保ちながら用途別の目次へ接続する方針。

# 7. Test / review / GitHub停止中の運用

## 7.1 Test・reviewの共通原則

- 開発中はfocused tests。migrationはfreshとsupported upgradeを区別して必要な検証を行う。
- GitHub利用可能時はexact-head Qualityを確認する。**停止中は同じSHAのlocal CIと結果記録を代替の根拠とする**。GitHub CIがないことを理由に検証不能とも、検証不要とも言わない。
- 実調査報告ではPHPUnitは**16分割**。frontend・静的解析等も合わせ「約20CI」と呼んでいた。PCで16並列を必須にしない。既存`product/tests/scripts/run_parallel_tests.sh`は既定4並列・DB分離・全件割当検証に対応との報告。
- 同一source／dependency／設定で成功した全suiteを理由なく繰り返さず、変更箇所と最終candidateに応じて実行する。ローカル検証を本番DBへ向けない。
- worktree、source、vendor、Composer設定、test設定の世代を揃える。stale containerをruntimeの不具合と取り違えない。
- simulationはsource SHA・manifest hash・seed・再現command・集計を記録。未知のGit状態をcleanとしない。
- P0/P1/P2は実際に到達する条件・影響・対象行を示す。好み、過剰設計、非現実的な異常値だけの指摘は増やさない。必要な証拠が読めない場合は検証範囲不足を明記し、無条件PASSにしない。
- subagentは限定調査・機械作業・focused test。Owner意図、release境界、migration、統合、production判断は主担当が保持する。
- 一releaseのSurface追加は原則一世代。merge、deploy、OCI変更、本番DB、補填、handoff編集は許可を混同しない。過去の承認を将来作業へ自動拡張しない。

## 7.2 GitHub停止とSupport対応

`Mamiki765`の認証APIが`403 / Sorry. Your account was suspended`を返し、ブラウザではログイン後500が出た経緯がある。停止原因を20CI分割やCodex使用と断定する証拠はない。別のbotアカウントを停止対象の正規アカウントと混同しない。

Ownerが貼付したGitHub Support返信（2026-09-06 20:15 UTC＝2026-09-07 05:15 JST）は、「一部の活動が不正利用検知でflagされ、規約と抵触する可能性のためmanual review対象。今後の利用目的を説明してほしい」という内容。**規約違反確定や復旧承認ではない。**

Ownerは同じスレッドへ返信済み。個人のプログラミング学習・趣味のWebゲーム／Discord bot開発、Codexによる開発支援、Git履歴・PR・テスト管理という用途を説明した。重複CIを減らし、事前にlocal focused checksを行い、終了条件のない自動review／fix loopを避ける方針も伝えた。現時点は返答待ち。追加の重複申立てや新規アカウントによる迂回を行わない。復旧日数の過去の推測を確約として継承しない。

## 7.3 OCI bare Git：転送経路は構築済み

```text
PC / Codex repository
  origin      → 既存GitHub（保持）
  oci-offline → SSH → /home/ubuntu/git/hakoniwa-world.git
                              ↓ 明示fetch
                /home/ubuntu/apps/hakoniwa-world
                    本番checkout / 既存origin保持
```

PCと本番に第二remoteを追加し、3.7.1の同一SHAで転送・fetch・一致を実機確認したとの報告。その後Ownerの許可で3.7.2を本番反映した。SSH障害はPCの設定ファイル権限修正で解消済み。鍵の内容や認証ファイルを再表示しない。

bareは受取場所で、本番working treeへの直接push・push即deployは採用しない。`offline/main`の最新値は実読出しで確認する。bareのbranch進行と稼働中のproduction SHAは別であり、candidateやdocs-only commitを受け取ってもdeploy済みにはならない。

2026-09-07 JST、Codexが実機の`hooks/pre-receive`を読取確認した。許可範囲は`refs/heads/offline/*`と新規`refs/tags/offline/*`。ref削除と既存tagの更新を拒否する。今回の文書反映先は既存`offline/main`であり、新branchやtagを作らない。将来も実際の許可refを確認し、force／mirror pushや保護設定の無断変更はしない。

通常deployは現在の実Compose・image・mount・backup手順を確認し、GitHub不通を理由に全面変更しない。ローカルbuild＋image転送は比較案であって必須決定ではない。「本番をCI runnerにしない」は開発中の全テスト反復を移さないという意味で、既存deploy build中の検査を無条件に禁止したものではない。DB rollback可とは仮定せず、forward migration後の旧コード互換性を分ける。

OwnerはOCI週次backup・1か月保管を用意している。新規bare・MCP・秘密設定までその対象に含まれるか、off-host性・復元可能性は別途確認する。PCにもGit履歴がある。bundleは履歴保全に使えるが、未commitファイル・外部画像・秘密設定・hooksを含むバックアップとは区別する。

Mariachang等も`/home/ubuntu/git/<repo>.git`へ拡張したい要望があるが、**箱庭以外のbare作成完了は未報告**。GitHub復旧後は双方をfetchして履歴を比較し、同じcommit SHAのまま復旧用branchへ送り、通常の保護ルールで統合する。mainをforce更新せず、稼働時の過去SHAも書き換えない。

## 7.4 Hakoniwa MCP Phase 1：実機完了報告

配置`/home/ubuntu/apps/hakoniwa-mcp`、Compose `compose.yml`、project／serviceとも`hakoniwa-mcp`。Streamable HTTP・stateless JSON、endpoint `http://127.0.0.1:8000/mcp`。MCP本体は認証なしでloopbackへbindする構成。**このことを一般公開URLで無認証運用してよいという許可にしない。**

bareを`/repos/hakoniwa-world.git`へread-only bind mountし、実機`RW=false`確認。非root、root filesystemもread-only、CPU／メモリ制限。server-side allowlistは`hakoniwa-world`のみ。

**ツールはGit読取5個＋既存`get_status`の計6個。**

| tool | 引数 | 主な返却内容 |
| --- | --- | --- |
| `get_status` | なし | 既存の接続確認情報 |
| `repo_resolve_ref` | `repo, ref` | requested ref、解決済み40桁commit SHA |
| `repo_commit` | `repo, ref` | SHA、parents、author／committer、subject、tree |
| `repo_changed_files` | `repo, base, head` | 両SHA、変更ファイル名／status、省略有無 |
| `repo_diff` | `repo, base, head, path?` | 両SHA、unified diff、省略理由 |
| `repo_read_file` | `repo, ref, path` | SHA、path、本文、サイズ |

引数は文字列、`path?`だけ省略／null可との報告。`repo_diff`は**指定baseとheadの直接比較**。merge-baseを使う三点diffだと仮定しない。refを最初にcommit SHAへ固定し、以降は同じSHAで読む。専用review-session APIは提案のみで必須でも実装済みでもない。

安全境界：通常のtracked fileのみ。symlink・submodule・binary・パス脱出・秘密パス・既知の秘密値形式を拒否。任意shell、Git write、checkout／fetch、Docker／DB／本番操作のtoolはない。

上限：ファイル本文64 KiB、返却JSON64 KiB、変更一覧200件、差分20ファイル。`truncated`／`omitted`を明示する。巨大ファイルの行範囲読取やページング引数はこのschemaにはない。上限で読めない関連箇所を、読んだものとして扱わず、限定抜粋・添付等が必要なことを報告する。

成功報告：安全性test 12件、実HTTP MCP Clientでinitialize・tools/list・全6ツール、README読取、394byte diff、非commit・任意repo・`.env`・パス脱出の拒否。bare全refs／HEAD不変、本番checkout・origin・Web・DB不変。変更はMCPコンテナ側。退避先は`/home/ubuntu/apps/hakoniwa-mcp-phase1.3yivKZ/original/`。

## 7.5 TunnelとChatGPT：接続確認の到達点

Codexの実機報告ではTunnelは**起動済み**。`healthz`／`readyz`とも200、サービス稼働・再起動回数0・起動時自動起動設定済み。本番Web・DB・Gitは変更なし。秘密値表示・モデルAPI呼出し・自動reviewなし。古い「clientダウンロードだけ許可、まだ起動禁止」の段階へ戻さない。

Tunnel ID・API keyはOwnerがTermiusの`configure-tunnel.sh`経由で非表示入力し、root限定ファイルへ保存した経緯がある。鍵・token・設定値をhandoffやチャットへ転記しない。APIキーの存在やTunnel疎通を、モデルAPIの課金実行・特定Proモデル対応・無料利用保証の証拠にしない。

ChatGPT側ではDeveloper modeで`Hakoniwa MCP`を作成し、接続「Tunnel」、MCP側認証「認証なし」を選んだ。旧チャットではDeveloper MCP非対応のエラーが出たと報告され、別チャットで次の結果が出たとOwnerが共有した。

```text
project: hakoniwa-world
status: MCP connection test successful
mode: read-only
```

**これは別チャットの`get_status`成功報告であり、bareの最新SHA・ソース本文・diffを取得した証明ではない。** このhandoffを作成しているチャットからはGitを実読出ししていない。新しいMCP対応チャットで`repo_resolve_ref`→固定SHAの`repo_read_file`→小さな`repo_diff`まで実行すれば、レビュー経路の確認ができる。

アプリがinstalled／enabled、`@`の名前が入力できる、Skillをアップロードできることと、その会話・モデルで実行できることは区別する。旧チャットの失敗を全Project／全モバイルの一律非対応と一般化せず、実際の呼出結果で判断する。不要な再接続設定やサーバー再構築を始めない。

## 7.6 独立レビューSkillと手動の復路

Ownerはレビュー結果をMarkdownで受け取り、手動でCodexへ渡す。**ChatGPTを外部から自動起動、MCPへreview.md保存、モデルAPI利用、無限review／fix loopは今回不要。** 復路が普遍的に不可能と断定するのではなく、必要ないため採用しない。

`hakoniwa-independent-review`はPCの`C:\Users\Hobby\.codex\skills\hakoniwa-independent-review\`に`SKILL.md`と`agents/openai.yaml`を作成し、quick validatorとYAML検証成功・実repo無変更との報告。OwnerはPCからアップロード成功とも報告したが、このチャットでSkill本文を読めた・Skillのend-to-end試験を完了したとは扱わない。

期待する手順は、base/headをSHA固定→変更一覧→diff→必要な関連runtime／tests→P0/P1/P2の実害評価→Markdown。実装・handoff変更・deployはレビューに含めない。全テストを読んだだけで実行済みと書かない。Skill不使用でも同じ手順を指示で適用できる。

出力には`Base`、`Reviewed commit`、`Verdict: PASS / HOLD`、findingごとの根拠と最小修正方向、実際に読んだ範囲と検証限界を入れる。未読の重要箇所がある場合はHOLDの理由を明記する。修正後は新SHAへの追加レビューとし、既存findingの修正確認と新規回帰を分け、好みだけの新指摘で往復を継続しない。

## 7.7 handoffを次のChatへ渡す経路

```text
MCP対応Chatが固定SHAのcodeを読む
  → レビュー／Owner判断をMarkdownで出す
  → OwnerがCodexへ渡し、handoff編集を個別許可
  → Codexが既存変更を保全してhandoffを反映
  → 許可されたrefへcommit／oci-offline反映
  → 次のMCP対応Chatが新SHAのhandoffを読む
```

MCPへwrite権限を追加しなくてもよい。反映前でも、更新MDを新チャットへ添付すれば文脈を引き継げる。読めないチャットの中だけに最新情報を閉じ込めない。

この更新案の作成はrepo編集・commit・push・deployではない。Codexへ反映を依頼するときは、手元のhandoffとの差分を先に確認し、既存の新しい記述を上書きしない。**docs-only commitでbareのHEADが進んでも、本番3.7.2のdeploy SHAをその新SHAへ改ざんしない。** 現在のデータと、デプロイの履歴を別々に記録する。

旧Projectメモの「GitHubをコード本体の優先元とする」は通常時の方針。停止中はOwnerが構築したOCI bare／MCPをソース経路とし、復旧後に再同期する。ただしMCPは現在箱庭用だけ。Mariachang対応を実装済みとしない。

# 8. 次のagentが最初に行うこと

1. **最新の報告済み本番は3.7.2 / `daa31ca01d929ffa231241021c2bef9ad7bdb14d`**。3.6.0／3.7.1へ戻す・3.7.2のdeploy許可を取り直す作業から始めない。GitHub復旧は未報告として扱う。
2. `Hakoniwa MCP`の実行可能なチャットでtool定義を取得し、`get_status`を実行する。成功したら`repo_resolve_ref(repo="hakoniwa-world", ref="offline/main")`で現在SHAを取得する。名前だけで利用済みとしない。
3. そのSHAの`repo_commit`と`repo_read_file`でcanonical handoffを読む。まだ古いhandoffなら、本添付版を更新案として併用し、最新報告を捨てない。任意のbranch名や存在未確認のrefを推測しない。
4. 接続確認には3.7.1の`f718f548b0f9958f6488a1fbfd8d206ce76b14de`と3.7.2の`daa31ca01d929ffa231241021c2bef9ad7bdb14d`の存在を確認し、変更一覧から実在する小さなファイルを選んでdiff／本文を取得する。これは疎通試験であり、依頼なしにrelease全体の再レビューを始めない。
5. 読めたSHA・ファイル・diffと、上限等で読めなかった範囲を報告する。`get_status`だけの成功から「コードレビュー可能確認済み」と飛躍しない。重要な対象が読めなければ添付等の最小代替を使う。
6. 実作業は`AGENTS.md`、`docs/README.md`、`docs/open-questions.md`からtask固有のcurrent codeへ進む。1.7～1.9の船・補填残件、2.5の回想契約、6.3～6.8の将来案／素材／マニュアルを混同しない。全歴史資料を最初から読まない。
7. 技巧・自然成長の現状維持、ターン圧縮なし、地上HQ・夢の女王・グラム未採用を守る。Secret／tokenを出力せず、reviewはread-only。production・Git書込み・handoff反映はそれぞれOwner許可を確認し、完了時には実際の証拠と引継ぎ用Markdownを返す。

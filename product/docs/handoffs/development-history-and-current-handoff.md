# hakoniwa-world 開発経緯・現行引継ぎ

> 更新日: 2026-09-06 JST。Ownerによる3.6.0 merge報告と、handoff更新の明示依頼に基づく。
> 対象: `Mamiki765/hakoniwa-world`
> 確認したmain: `382c838edc5c92c33302b2895eeb394e45cc58ec`（PR #150 merge）
> repository上のapplication: **3.6.0** / Surface Ruleset **v20** / Underground combat **v3**。
> productionの最新checkout・migration ledger・deploy完了は今回独立未確認。GitHub mergeとproduction deployを区別する。
>
> 本書はOwnerとWeb版ChatGPTが管理する。Codex / implementation agentは原則read-only。Ownerがhandoff編集そのものを明示的に依頼した場合だけ編集する。

# 0. 読み方と、今回の重要な更新

作業開始時はremote、対象branch、exact HEAD、未commit差分を確認する。ここに書かれたSHAへ未確認でresetしない。実装値はcurrent code / schema / accepted decision、release scopeと未決事項はOwnerの最新明示指示を確認する。

**3.6.0では技巧・会心・敏捷・精神魔法・自然成長を再設計していない。** 防具IL由来の技巧抵抗R、魔力昇華、five-stat mean会心率、自然敏捷成長などの研究案は今回不採用。旧handoffの6.6を次の実装指示として復活させない。

3.6.0は戦闘の時系列UI、四つの追加覚醒奥義、試練2「黒曜石の魔窟」、途中撤退でも育成できる通常報酬を実装した。次の相談は**地上の島経営・産業連鎖・電力・第三次産業・素材制作**へ重心を戻す。

更新前の詳細全文は[2026-09-05版](archive/development-history-and-current-handoff-2026-09-05.md)へ元blobのまま保存した。旧版は経緯確認用であり、#149未merge、Trial 2未実装、旧6.6の成長案などは現在の状態ではない。必要な歴史だけ参照し、毎回全文を読み直さない。

今回の参考作品調査は[箱庭RA Final Edition設計レビュー](../../../docs/reference-analysis/hako-ra-final-edition-design-review.md)を参照する。分析・提案であって実装承認ではない。

# 1. 現在地

## 1.1 GitHub・CI・production

| 項目 | 確認結果 |
|---|---|
| PR #150 | closed / merged。`codex/3.6.0-trial2-gameplay` → `main` |
| merge日時 | 2026-09-06 14:16:31 JST |
| 最終PR HEAD | `5a32331ca9da2083c1ada2d353ac6990ee6f9ae0` |
| merge commit | `382c838edc5c92c33302b2895eeb394e45cc58ec` |
| 最終HEADのQuality | run `34012796576`、completed / success |
| application | `product/config/hakoniwa.php`は3.6.0 |
| production | 今回OCI・本番DBへ照会／変更していない。最新deploy状況は未確認 |

最終PR HEADとmerge commitのtreeはともに`de720796a623cb62e49f41c138163487c2a4c933`。初期HEAD `64ddb238`のCI failureを最終HEADの状態と混同しない。

証拠入口:
- PR: https://github.com/Mamiki765/hakoniwa-world/pull/150
- Quality: https://github.com/Mamiki765/hakoniwa-world/actions/runs/34012796576
- balance report: `product/docs/underground-trial2-v1-200-seeds.md`

balance reportの末尾に残る「main未反映」は報告作成時点の記録。本書更新時点では#150はmerge済みである。ただし、それをproduction反映済みと読み替えない。

## 1.2 直近releaseの履歴

| PR | 内容 | merge / anchor |
|---|---|---|
| #140 | Surface water ownership限定修復、v20開始 | `cea7f39992c5885317b6102aa42469ea32fcaaba` |
| #141 | Ship persistence / projection、港 | `763a0993e5533eb9318048af925abb9f48206ade` |
| #142 | 船舶建造・任意廃船 | `c2ed1e66ca50d93119ea4766fc718402dcdc3446` |
| #143 | 航行・燃料・報酬・秘書XP・forced displacement | `dd76f7fbe0eb0bbcb07420a20c4b62f379d8899f` |
| #144 | Ship-first missile、visibility | `22203ae9f7b06607bfa6a5a6821bb948e011f634` |
| #146 | 3.5.0 main昇格 | `c79a5dac056a20609137477e6ac0c112fab4990d` |
| #147 | Owner確認済み未適用3.5.0 migrationの統一 | `146687a5d5f3f2703f405be6532fae562da3bd58` |
| #148 | Hotfix 3.5.1、command定義JSONのarray復旧 | `4e4d9b85524df5b31a874ec5083c9f74be5cedcd` |
| #149 | 3.5.2 UI / UX、漁獲overflow、レビュー修正 | `70871f9e3345ac183e3c2b97b3f499ad672ba6bb` |
| #150 | 3.6.0 Trial 2、選択式覚醒奥義、戦闘UI、release修正 | `382c838edc5c92c33302b2895eeb394e45cc58ec` |

#149は`5bad2cd`再レビュー後にhandoff commit `b30fee8`を追加し、同HEADのQuality成功を確認してmergeした経緯がある。当時の未merge記述を現在へ持ち越さない。

## 1.3 現在のidentity

```text
application_version: 3.6.0
Surface Ruleset: hakoniwa-2s-plus-v20
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

3.6.0はSurface v21や新しい基礎combat identityを作っていない。IL90までの装備拡張も既存入力の出力を保つdomain extensionとしてgenerator identityを維持する。将来変更時もidentityが不要という一般規則にはしない。

## 1.4 Migrationと本番境界

3.6.0のforward-only migration:
`product/database/migrations/2026_09_06_000000_add_underground_awakening_technique_selection.php`

`underground_profiles.awakening_technique_key`をnullableで追加し、growth pathと許可された技の組合せ、intro request operationを検証する。NULLは既存技へfallbackし、既存playerのbackfillや能力再計算は不要。旧battle / JSONBを書き換えるmigrationはない。

初期CIで発生したPHPStanの配列型不整合と、fresh / upgradeテストのmigration件数不一致は後続修正を経て最終HEADのQualityが成功している。最新migrationを増やしたことを理由に、過去baseline fixtureや件数を無差別に変更しない。

3.5.0ではOwnerがproduction 3.4.0 / v19、旧4本未適用と確認したため#147の統一を認めた。これは**その時点の限定承認**であり、以後のproduction migrationを再統合する許可ではない。

本番操作前にはactual checkout / application / Ruleset / migration ledger / 未解決Turnを別途確認する。cronは未解決Turnの自動retryをしない。manual retry、backup、restoreはcurrent runbookとOwner gateに従う。

## 1.5 既存Ship契約の要点

- ShipはNation-ownedのWorld actor。Monster、秘書itemとは別。1cellに最大1隻。
- 港建設は条件を満たす中立shallowをlandへ変更し、自国portを置く。港数で船の保有上限は増えない。
- 漁船・観光船・探索船は各最大3隻。spawnできなければ建造費・turnを消費しない。任意廃船は通常commandで返金なし。
- 通常航行はsea。canonical sea擬態facilityとの同居条件を維持し、見た目で判断しない。
- 漁船は航行ごと石油1万バレル→魚7,000t。観光船は石油2万バレル→20億円。探索船は石油1万バレル、直接報酬なし、visibility 3hex。
- randomized process_cells内、Ship IDごとに一turn最大一回。自国port有無はphase開始時snapshot。最後の港を同turnに失った影響は次turnの通常航行から。
- heading NULLはrandom。指定進路不可時のbounded fallbackを維持する。進路変更自体はturn不要。
- 成功航行は秘書ship_operationsへ基礎XP+1。既存modifierを適用。準備中の技能へ固有効果を勝手に追加しない。
- forced displacementは通常航行ではなく、燃料・通常報酬・秘書XPを発生させない。
- NPC海賊、船修理、destination pathfinding、船Lv／XP、汎用Ship AIは未実装の別scope。

細かな休眠・復帰、missile、visibility、port候補順はcurrent Ship codeと旧版1.5を必要なときだけ照合する。

## 1.6 漁獲overflow修正

#149で航行報酬のfood overflowを既存FoodOverflowResolverへ接続した。食料庫満杯時は魚在庫ではなく標準売却収入へ反映され得る。航行距離・燃料・基礎報酬・秘書XP自体を変更したものではない。

## 1.7 既知残件・現在の扱い

| 項目 | 状態 / 次の扱い |
|---|---|
| 廃船の表示対象と登録対象の同一性 | 未解消残件として維持。別のShipへすり替わらないID / intent境界を確認する |
| 強制退避の別自国港探索 | **Owner採用決定済み**。最寄り不可なら別の自国港も探す。今回の#150は地下作業であり、この船変更の実装完了は確認していない |
| 漁獲overflow損失の実測・補填 | 本番audit調査と支払いは今回未実施。修正mergeを補填完了と混同しない |
| 「地底」ヘッダー導線 | Owner将来要望。今回のhandoff作業で実装しない |
| 地上の産業・電力・第三次産業 | 次期設計相談。RAから原理を抽出する段階。数式・release番号・migration未決 |
| 32×32素材編集ツール | 開発支援案。実装していない |

別港探索は隣接valid sea優先を維持し、その後の自国港候補を既存の距離・座標・ID順で順次調べる小さな変更案がある。仕様採用の可否を再び未決に戻さない一方、未確認のコードを実装済みと書かない。

## 1.8 PR #150のレビューと測定

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

本handoff更新は報告・code・CIの照合であり、200seedや全テストをWeb側で再実行したとの主張ではない。

## 1.9 漁船incidentと補填境界

ナム孤島turn368～371の「船が中立」は、後のtooltipで船主は自国、下の海が中立と確認した経緯がある。船籍DB破損と断定しない。

実際のoverflow損失、修正deploy境界、資金上限、払い済みの有無は本番auditで確認する。正常完了・非dry-run turnのship.moved resource_requested / applied / overflowを元event IDごとに調べる。建造数×経過turn、現在残る船だけ、過去Turn再実行で補填額を作らない。

当時の売却lotと資金上限まで復元するか、売却相当額の救済にするかは別Owner判断。以前の63億円は例で確定額ではない。燃料・船代・XPを自動的に重ねて返さない。dry-run→承認→既存lock / transaction / capacity / auditで支払い、満額入らない分を無言で破棄しない。

# 2. 地下RPGの継承契約と3.6.0

## 2.1 Trial 1

「地下に眠る古代遺跡」は10連戦。HP carry、1～9戦勝利後max HPの20%回復、MPは毎戦10,000。active run中に宿を使わない。勝利後は次戦へ、敗北／撤退でrun終了。画面更新でも進行を保持する。

初回clearのみSP40・物語・覚醒・地底layer1（4設備枠）。再clearで初回報酬は重複しない。合計800EXP / 205G。**Trial 1は通常装備dropなし、Trial 2はあり**と区別する。

## 2.2 覚醒共通仕様と選択技

gaugeは永続、内部最大1000。default AIは満タンかつHP20%以下で発動を試みる。custom AIでは明示した覚醒ruleを使う。一戦最大一回であり、試練全体で一回ではない。activationそのものは通常actionを消費せず、HP／MP全回復・装備込み五能力+30%を戦闘終了まで適用する。

3.6.0では各growth pathの既存技／追加技を戦闘間に一つ選ぶ。選択NULLの既存playerは既存技。再振り時はNULLへ戻す。取得に追加SP・新skill node・Trial 2clearは要求しない。覚醒本体は通常のdispellable buffではない。

| 系統 | 既存技 | 追加技の採用内容 |
|---|---|---|
| 戦技 | 天断一閃 | 修羅の血脈：potency160%の物理初撃、武力80%／技巧20%／weapon100%。初撃含む3round、実HP damage15%吸収、既存吸収との合計上限25% |
| 護身 | 絶対護界：2round直接damage90%軽減 | 城塞撃：potency180%、生命75%／武力25%／weapon80%、攻撃後に次direct hitを既存guardで受ける |
| 祝福 | 生命讃歌：HP全回復、MP回復なし | 裁きの天光：potency220%、精神85%／技巧15%／weapon100%、会心可、damage後に解除可能buffをkey順で一つ除去 |
| 自由 | 無窮再演：MP全回復・通常技CT解除、行動継続 | 無相の一撃：potency240%、技巧100%／weapon100%、会心可、実効物理／魔法防御の低い側、同値physical |

追加四技はactionを消費する。修羅の吸収はoverkill・障壁で吸収した分・反撃・継続damageを含めない。無相は一撃であり防御無視や二重攻撃ではない。新しい魔力昇華はない。無窮再演で覚醒奥義を再使用可能にしない。

## 2.3 Trial 2「黒曜石の魔窟」

Trial 1初回clearで解禁、level hard gateなし。比較／攻略の想定はLv150～180帯・SP60・IL50級。低Lv突破、育成での押し切り、浅層での装備更新を許容し、正解buildを当てるだけの試験にしない。

| 戦 | 敵名 | 種族 | EXP / 欠片 | 装備IL |
|---:|---|---|---:|---|
| 1 | 煤牙の斥候 | ゴブリン | 250 / 65 | 55–66 |
| 2 | 嘲炎の道化 | インプ | 260 / 68 | 56–67 |
| 3 | 鉄鎖の獄犬 | ヘルハウンド | 270 / 70 | 58–69 |
| 4 | 不寝番の石翼 | ガーゴイル | 280 / 72 | 60–72 |
| 5 | 赤角の破城兵 | ミノタウロス | 290 / 75 | 62–74 |
| 6 | 弔鐘の司祭 | レイス | 300 / 78 | 64–76 |
| 7 | 蠱惑の蛇姫 | ラミア | 320 / 82 | 66–78 |
| 8 | 夜宴の血侯 | ヴァンパイア | 350 / 88 | 68–82 |
| 9 | 誓約喰らいの黒騎士 | デーモンナイト | 400 / 95 | 72–86 |
| 10 | 首なき断罪卿 | デュラハン | 2,000 / 540 | 76–90 |

10戦、HP carry、1～9勝利後20%回復、各戦MP reset。各勝利のEXP・欠片・dropを同一settlementで即時確定し、戦闘間帰還でも持ち帰る。新runの再勝利は通常報酬を再取得できるが、同一request再送／表示更新では重複付与しない。未勝利battleのwithdrawalと、勝利後のrun帰還は別。

1～9平均は約1.25倍、10戦完走平均はXP・欠片とも黒晶洞名目平均の約2倍。合計4,720EXP / 1,233G。平均倍率は勝利・抽選前提付きで実時間効率そのものではない。魔窟装備は既存のIL売値式を使い、専用の売値倍率は付けない。護符は主能力別に5名称。

初回clearは**SP+40（Trial 1後の60から合計100）、地底layer2／8設備枠、Owner提供の物語**。追加覚醒・特別item・称号なし。初回挑戦／初回clear物語を再挑戦へ自動再表示しない。`(秘書名)`を実際の秘書名へ置換する。

舞台は黒晶洞最奥の封印の地。案内人はサキュバスの元魔王であり、敵の誘惑枠はサキュバスではなくラミア。物語の詳細はOwnerの文章とcurrent実装を正本とし、以前の自由案で書き換えない。

## 2.4 地底施設と公開境界

Trial進行とlayer権利はSecretary-owned、施設はNation-owned。同じ秘書で島を作り直しても権利は残るが旧島の施設を持ち越さない。

1layer=4設備枠。地底都市は首都effective最大人口+10,000、地底農場は農業労働容量+10,000、地底工場は工業労働容量+30,000、地底ミサイル基地はcapacity+1。建設・撤去はofficial Turnを一つ使う。入口・梯子は設備枠ではない。Surface MapCellや3D Worldを新設しない。

現行の地底マップは自島画面、見出し「首都地下」、正方形tileの5列連続配置。選択時だけ赤枠と詳細を出す。他島からも地底mapと秘書戦闘Lvを閲覧できるが、操作は自国権限へ限定する。AI画像の閲覧consentを維持する。

# 3. 装備・敏捷・資源

## 3.1 敏捷は現行のまま

高い実効敏捷が先手、同値のみ既存tie-break。相対敏捷差による回避・comboと行動阻害抵抗を維持する。新しい自然敏捷成長は採用していない。

damage action一回につきcomboを一回抽選し、native多段でも同じ結果を使う。追加action、追加damage event、追加会心、追加status抽選、追加覚醒gainを生まない。軽減後damageへの倍率である。ワイバーン敏捷12を別contentの都合で変更しない。

## 3.2 装備

既存5枠はweapon1・armor1・accessory3。generated itemは保存したpayload / identityを使用し、旧装備を現在catalogで作り直さない。3.6.0で生成IL上限を60から90へ拡張し、60以下のanchor・既存入力を維持した。

shopのノービスとgenerated rarityのレギュラー／ハイクオリティ等を混同しない。Trial 2報告はuncommon3部位の限定比較である。Unique、強化、汎用enchant、marketは別の将来scope。

## 3.3 地上資源との区別

current Surface resource definitionsには小麦、魚、怪獣肉、工業品、鉱物、石油がある。石油内部単位は万バレル。地下EXP／欠片と地上資源を無断で統合しない。

今後の産業案は、この既存の農作物・工業品・鉱物・石油を活用する方針。まだ電力や加工品のruntimeを追加していない。基礎資源を細分化するか、加工結果を新在庫にするかは未決。

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

presentation v2は新battleのinitial_stateとround boundary等の投影を追加した。旧v1／flat logは保存済み情報でfallback。旧battleを再戦・再計算して補完しない。不足状態を現在profileや0で埋めない。表示やdetails開閉で抽選・報酬を再実行しない。

## 6.3 次の主題：地上の産業連鎖

Ownerの最新意図は「箱庭がメインなので、地上の島経営を豊かにする」。第一次・第二次産業から生まれる農作物・工業品・鉱物と石油を基礎に、電力、加工、第三次産業へつなげたい。

完成品のUI・施設・仕組みを観察してアイデアを出す段階であり、RA移植や次release全機能の承認ではない。地下の技巧再設計へ話を戻さない。

参考資料はGoogle Driveの「箱庭リファレンス / hako-r-a_FE」のみ。
`https://drive.google.com/drive/folders/1hjeYuiMFwmXf1Qz7_WtvA7zcXKD4VJgI`

Owner提供のRA最新版として扱うが、世界中の公開版の最新版を確認したとの意味ではない。readmeはFinal Editionを名乗り、モジュールには旧version表記もある。今回、他のreference作品の兄弟folderは調べない。

## 6.4 素材制作と「地底」導線

Ownerは将来ヘッダーへ「地底」を追加してアクセスを容易にしたい。現在位置やURL、閲覧／操作権限を確認して別のUI変更として考える。今回の文書更新で追加済みとは書かない。

32×32の地形下地（平地・荒地・海・浅瀬）に、人とAIが描き足せるGIF制作ツールも候補。提案は下地ロック、palette、layer、nearest-neighbor拡大、原寸／隣接preview、undo、JSON等の編集元、GIF／PNG出力。AI生成の見栄えと正確なpixel編集は別の課題。RA画像そのものの複製や無条件の再配布許可を前提にしない。

## 6.5 ほかの将来候補

NPC海賊、船修理、第三狩場以降、Unique、enchant／装備強化、案内人再戦、専用覚醒画像、PT、manual combat、marketは必要になった時にOwner gateで検討する。Trial 2はこの一覧から除く（3.6.0で実装済み）。

## 6.6 技巧・成長研究の結論：今回不採用

旧版6.6の相対技巧、会心倍率、防具IL共通R、魔力昇華、魔法係数変更、自然敏捷成長、祝福武力+0を含む自然成長候補は、検討を経て**今回すべて採用しない**とOwnerが決定した。

「成長曲線」はこの会話ではLvごとの能力上昇を指しており、必要EXP曲線を変える依頼ではなかった。その能力成長も現状維持。既存の精神型、敏捷、ワイバーン等へ広範な影響を出さないことを優先する。

将来、自由へ**技量型のskill tree**を追加し、安定型とは違う上振れ／下振れの遊びを局所的に作る案はある。現行の技巧を全員向けに改造する計画ではなく、現在の実装scopeでもない。

研究結果は参考として残してよいが、candidate codeを新releaseへ自動的に混ぜない。旧版の「Owner確定」「昇華は撤回ではない」は当時の相談記録で、今回の最終判断に優先しない。

## 6.7 行動ログ形式：実装済みと未実装

presentation_log_version=2、初期状態、round開始／終了、覚醒gauge表示は#150で実装済み。旧6.7にあった汎用action_id／parent_action_id／source等の全提案が実装されたわけではない。

MP costと効果の所属をより明確にする将来改善は、必要な保存情報だけで行う。v2という番号だけを根拠に、全行動を再生できる完全traceが存在すると判断しない。

# 7. Test / review / Agent運用

- localはfocused tests。migrationではfresh install／supported upgrade等の直接関係する検証を追加する。
- repository全体のPHPUnitはexact-head Quality CIをauthorityとし、同じsource／dependency／設定で全suiteを何度も重複実行しない。
- worktree、source、vendor、Composer設定、test設定を同世代に揃える。stale containerをruntime不具合と取り違えない。
- simulationはsource commit・manifest hash・seed・compact aggregate・再現commandを記録する。未知のGit状態をcleanとしない。raw巨大JSONを常設成果物へ増やさない。
- P1/P2は実際に到達可能なproduction経路、データ、権限、retry／lock、利用者への回帰を具体的に示す。好みやあり得ない異常値だけで重大指摘にしない。
- subagentへは限定した調査・機械的作業・focused test。Owner意図、release境界、移行、統合、production判断は主担当が保持する。
- 一releaseのSurface Ruleset追加は原則一世代。過去の未適用統合を現在のmigrationへ一般化しない。
- main merge、release merge、deploy、OCI、本番DB、補填はそれぞれOwnerの明示許可を確認する。handoff編集依頼はこれらの許可を兼ねない。

# 8. 次のagentが最初に行うこと

1. remote mainと対象branchを確認し、#150 merge後から開始する。#149／#150の初期失敗を現HEADの未修正事項として扱わない。
2. 本書1.7～1.9の船・補填残件と、6.3～6.4の新しい地上設計相談を分離する。
3. 基礎戦闘／自然成長を維持するOwner判断を守る。技巧研究を再開しない。
4. `AGENTS.md`、`docs/README.md`、`docs/open-questions.md`からtask固有のcurrent codeへ進む。全歴史資料を最初から読み直さない。
5. RA分析は指定folderと本書から参照する分析文書を入口にする。原理の独立実装と、第三者code／素材の複製を混同しない。
6. UI／産業／素材ツールの具体的な実装は別のOwner scopeを確認する。今回の自由なアイデア出しを全部実装する指示にしない。
7. production操作は別gate。完了時は必要な検証根拠とhandoff更新材料をOwnerへ返す。

# バハムル中級2：採用値と測定結果

2026-10-07。Owner指定で測定・実装し、HP840,000を採用。独立checkoutの基点はGitHub main `2fbe0b5ba5cd709644addc2c41462ca83a494b25`（PR206 merge / application 4.15.0）。ローカル調整の完了後、Ownerの追加依頼により開発branchとmain向けDraft PRで共有する。merge、deploy、本番DB変更は対象外。

## 最終値と共通処理

| 項目 | 中級1（基点main） | 中級2 |
|---|---:|---:|
| 推奨Lv | 666 | 1,000 |
| HP | 390,000 | **840,000** |
| 武力 / 精神 | 12,075 / 6,000 | 22,000 / 10,000 |
| 技巧 / 敏捷 | 1,200 / 220 | 1,800 / 330 |
| 物防 / 魔防 | 16,000 / 9,000 | 24,000 / 14,000 |
| 武器威力 | 2,600 | 4,500 |
| 勝利EXP / G | 200,000 / 120,000 | **400,000 / 240,000** |
| 武器・結晶報酬IL | 210 | **223** |

中級1クリア後に既存の異世界挑戦経路で解放する。勝利EXPとGは中級1の基礎値の2倍をstage定義に一度だけ設定する。既存決算は敵1体の基礎報酬を使い、異世界は宝物庫倍率の対象ではない。初回と反復勝利で基礎EXP・Gを分けない。

黒竜晶1個確定、ユニーク武器10%、既存4武器種・4結晶種、勝利時だけ輝石1個消費を維持。新しい報酬名・演出・報酬率は追加しない。帰港地・宝物庫の装備帯は200〜220のまま。IL223対応としてgeneratorと倍率計算の上限だけを223へ拡張した。

敵は中級1と同じ`otherworldCatalog()`で生成する。構え→黒爪、6round周期の息吹・竜翼、HP50%の咆哮・覚醒ゲージ補充、5→4→3→2→1、大予告の次roundのメガフレアを維持する。黒爪35,000、息吹12,000、竜翼6,500、メガフレア50,000bpsと咆哮+70,000bps、竜翼の与damage20%低下・3roundも共通。combat式・AI条件・行動順・予告間隔を変更していない。

## 結晶の固定補正

基礎補正はキャラクター能力への割合ではなく固定ポイント。IL210の基礎60に対しIL223の基礎90をanchorへ追加し、種別配分後の＋0能力を正確に1.5倍とした。

| 結晶 | IL210＋0：生命/筋力/技巧/精神/敏捷 | IL223＋0 |
|---|---|---|
| 熾牙 | 60 / 60 / 60 / 0 / 60 | 90 / 90 / 90 / 0 / 90 |
| 堅鱗 | 90 / 60 / 0 / 30 / 60 | 135 / 90 / 0 / 45 / 90 |
| 命脈 | 60 / 0 / 60 / 60 / 60 | 90 / 0 / 90 / 90 / 90 |
| 慧眼 | 60 / 0 / 120 / 0 / 60 | 90 / 0 / 180 / 0 / 90 |

研磨は＋0固定能力の30%/段階、＋5で2.5倍（整数丸めあり）のまま。ランダムAffixは2枠・重複可・IL200で基準割合の成長を止め、通常の`S(IL)`でrating化する。Affix値・固有効果・研磨増分まで1.5倍にしていない。Qualityは未研磨原本から算出し、研磨・貸出同期でも保持する。IL210以下の基礎anchorと保存済みpayloadを変更せず、既存品の再抽選・migrationは行わない。

## 味方と測定条件

Lv1000、通常武器・防具・アクセ3枠IL220、結晶IL210＋5。通常装備は**アーティファクト**（レリックへ引き上げない）、結晶はユニーク。武器は短剣・細剣・長剣・輝石杖。全快、MP100、覚醒ゲージ0、覚醒解放済み、上限80round。自然回復は各成長方針の本体設定を使う。

中級1採用測定と同じ配分比を、そのLvのSTP権利量へ正規化する。全STPを配分し、端数は最大配分先へ加える。性格・種族等の追加補正は旧測定にも今回にも載せず、本体の成長方針の初期能力＋自然成長＋STPを使う。

| 役 | STP比：生命/筋力/技巧/精神/敏捷 | Lv1000の成長能力（装備加算前） |
|---|---|---|
| 戦技1 | 463:478:110:1:3 | 3,209 / 4,297 / 1,549 / 1,011 / 24 |
| 戦技2 | 2:7:1:0:0 | 2,016 / 5,529 / 1,528 / 1,007 / 10 |
| 護身 | 411:411:0:0:88 | 4,295 / 3,276 / 1,009 / 1,015 / 495 |
| 祝福 | 110:0:210:610:120 | 1,544 / 1,007 / 2,014 / 4,943 / 582 |

旧と同じ100SP予算・前提nodeを取得したrank1の5技能/役。戦技は`precision_cut, dagger_flurry, armor_break_strike, severing_bleed, executioner_cut`、護身は`shield_bash, counter_stance, renewing_guard, bulwark_strike, unbroken_retort`、祝福は`holy_bolt, mending_prayer, crystal_cycle, crystal_aegis, holy_lance`。未指定の上位技能や140SP構成へ強化していない。

対処AIも旧と同じ。護身は大予告で覚醒→奥義、祝福は大予告で覚醒し、味方HP35%以下で奥義、80%以下で最低HPの味方へ祈り。戦技は覚醒・奥義を使い、既存技能のready優先順で攻撃する。専用「着弾済み」条件を追加しない。祝福奥義はHP条件によりフレア前に発動する場合もあり、蘇生も実戦ログに含まれる。

装備seedは武器444000、防具444001、アクセ444002〜444004、石445010〜445013で旧と同じ。Affixの種類・Quality係数8000〜10000bpsの抽選はそのまま使い、狙い直しや最大roll固定をしない。入手時Qualityは武器42、防具40、アクセ56/51/33%、各石44/55/38/82%。詳細な全Affix・技能・AI・装備payloadはlocal入力JSONへ保存。

旧は武器IL180ユニーク（衝撃波あり）、防具・アクセIL120、石180＋5だった。今回は指定ILに合わせて通常IL220武器（衝撃波なし）と石210を使う。4.15のアクセ全枠付与は維持するので旧の空きAffix枠と完全同一品ではない。初期測定の通常装備tierはgeneric `hero`。実入手tier `yunagi_harbor`とのbase・全能力・Affix・modifier・固有効果が同値で、名称/identityだけが異なることを代表確認した。

過去の一次資料は[4.4.0採用測定](https://github.com/Mamiki765/hakoniwa-world/blob/2fbe0b5ba5cd709644addc2c41462ca83a494b25/docs/archive/through-4.4.0/product/docs/plans/4.4.0-bahamul-polishing.md#L15-L67)と[PR164](https://github.com/Mamiki765/hakoniwa-world/pull/164)。黒爪・竜翼欠落の旧測定は使わない。現在はcombat identity v9、IL200超の武器/HP/rating倍率、Quality・貸出Affix保持・アクセ全枠仕様がある。過去の勝率を現行baselineとして流用せず、旧装備条件を現行式へ通した451000〜451015の16戦では11勝5全滅・平均46.06round（旧同seedは11勝5全滅・48.75round）だった。

## baselineと候補比較

32seedは451000〜451015、452000〜452015。64seedはこれに453000〜453031を加える。すべて本体`AlphaV1CombatModel::fightPartySnapshots()`を呼び、別戦闘engineを作らない。同じPCの専用Docker、network none、2CPU、既存DBへの接続なしで実行した。

| 条件 | 件数 | 勝/全滅/時間切れ | 平均round | 勝利平均round |
|---|---:|---:|---:|---:|
| 現中級1・指定Lv1000装備・対処＋5 | 32 | 32 / 0 / 0 | 26.78 | 26.78 |
| A：HP800,000・最終値と同じ他能力 | 64 | 52 / 10 / 2 | 58.70 | 61.58 |
| B：HP720,000・武力26,000・精神12,000、他A同一 | 8 | 0 / 8 / 0 | 32.13 | — |
| C：HP750,000・武力22,500、他A同一 | 32 | 23 / 8 / 1 | 53.28 | 58.04 |
| **採用：HP840,000・対処＋5** | **64** | **49 / 13 / 2** | **60.66** | **64.92** |
| 採用値・無対策寄り＋5 | 32 | 0 / 32 / 0 | 42.56 | — |
| 採用値・対処＋0（451000〜451007） | 8 | 3 / 5 / 0 | 48.38 | 72.00 |
| 採用値・対処＋5の同8seed | 8（上記64内） | 8 / 0 / 0 | — | — |

採用値の対処勝率は76.56%。過去の中級1対処約75%を暫定比較点にした。無対策寄りは通常攻撃・回復・護身優先を保ち、覚醒を早めに使うAI。**奥義が完全に無いAIではない**。実際はタンク奥義27/32戦だが大予告一致0戦で、29戦がフレア着弾round全滅。対処側はタンク奥義51/64戦、大予告一致50戦、祝福奥義33戦、フレア後18戦。技構成の欠落や奥義未実装を敵強度として補正していない。

対処側13全滅中10戦はフレアround全滅。残る3戦はフレアに達する前の全滅。時間切れ2戦はseed453014（敵残HP148,287）と453030（51,696）、ともに味方1人生存だった。勝利49戦は最終的に4人生存だが、途中の戦闘不能・祝福奥義の蘇生を含む。

採用値の対処側の敵damage log `amount`の1対象平均は黒爪18,973.6、息吹3,217.0、竜翼3,538.7、咆哮後メガフレア26,552.7。無対策寄りのフレアは143,025.9。HP上限で切り捨てた実HP減少量や障壁吸収量と混同せず、全summaryの別項目で確認できる。

＋0は少数比較であり一般的勝率を推定しない。80万では同8seedで0勝8全滅だったが、84万では3勝となった。HP変更が咆哮時期と覚醒・蘇生の経路を変えるため、難度が単調に動くとは限らない。

## HP上積みの停止点

Owner追加指定により、Aの他能力・装備・AI・同64seedを固定し、HPだけ20,000ずつ増加。基準80万からの累積増量と直前stepの増量を別々に敵残HPと比較した。全滅と時間切れの両方を対象にした。

| HP | 累積増量 / 直前増量 | 勝/全滅/時間切れ | 平均round / 勝利平均 | 敗北時の最小敵残HP | 増量以内の例 |
|---:|---:|---:|---:|---:|---|
| 800,000 | 0 / — | 52 / 10 / 2 | 58.70 / 61.58 | — | 基準 |
| 820,000 | 20,000 / 20,000 | 52 / 11 / 1 | 59.47 / 63.08 | 31,696 | なし |
| **840,000** | **40,000 / 20,000** | **49 / 13 / 2** | **60.66 / 64.92** | **51,696** | **なし** |
| 860,000 | 60,000 / 20,000 | 46 / 16 / 2 | 60.92 / 66.61 | 24,977 | 累積増量基準で1件 |

該当は**seed451015、HP860,000、80round時間切れ、敵残HP24,977**。80万からの累積60,000未満、84万からの直前20,000以内には非該当。同seedの実測は80万で70round勝利、82万で71round勝利、84万で73round勝利。84万では42round咆哮、46round大予告と両奥義、47roundフレア、73round勝利だった。HP50%trigger等で経路も変わるため、残HPだけから増量前の必勝を断言しない。累積増量の最初の該当を検出した86万で上積み測定を止め、その手前の84万をOwnerが採用した。

## 検証・再現・証拠

- 既存代表Unit：14 tests / 162 assertions PASS。最終上限修正後の境界代表：1 test / 9 assertions PASS。最終HP84万での中級ローテーション代表：1 test / 18 assertions PASS。
- ローカル代表確認：既存catalogから中級2・報酬EXP/G、IL223の4武器・4結晶、固定能力1.5倍、研磨、Affix/rating/Quality、保存rollによるIL同期を確認しPASS。
- 変更5PHPファイルの構文確認、Pintの書式確認、`git diff --check`もPASS。
- 新しい恒久test caseは増やさず、既存の中級戦闘代表を中級2へ、既存の上限境界代表を現在上限へ更新した。全体suite、DB/HTTP経由の報酬決算、画面目視は実行していない。
- 測定実行は合計468戦。うち32戦は全ログ一括JSON化が128MB上限で失敗したため採用根拠へ使わず、1戦ずつ保存へ変更して同seedを再実行。保存完了の436戦を記録した。初期probeと本比較の重複は独立sampleとして合算しない。

測定器・入力・全戦闘ログ・各summary・検証logは独立checkoutのlocal `.codex-tmp/`。Gitのlocal excludeへ追加し、公開repoへ入れない。主資料は`hp840000.json`、`hp-comparison.json`、`final-casual.json`、`final-polish0.json`、`representative.json`、`focused-phpunit.log`。各summaryの`battles[].log_file`が個別の全ログを指す。

測定器はローカル証拠とともに保持し、公開repoには入れない。以下のコマンドは測定したcheckoutでの再実行用で、公開repo単独には`measure.php`を含まない。checkoutの`product`を`/var/www/html`、local `.codex-tmp`を`/work-evidence`へマウントし、空env・network none・2CPUの同じPHP8.5.8依存環境で実行する。最終configのHPは840,000なので敵overrideは不要。

```sh
php /work-evidence/measure.php --stage=bahamul_intermediate_2 --level=1000 --timing=timed --build=distributed --polish=5 --seed-set=all --output=reproduce-final
```

別checkoutで条件を再構成するときも、戦闘は本体の次の経路を使う。

1. `UndergroundAlphaV1PlayerCatalog`の`stpEntitlement()`、`currentStats()`、`growthPath()`で上記のLv・配分比・自然回復を解決する。武器・防具は`hero`、アクセの主能力は戦技`might`、護身`vitality`、祝福`spirit`。石は戦技`might`、護身`guard`、祝福`healing`を上記seedで`UndergroundRuntimeEquipmentGenerator::generate()`し、`UndergroundEquipmentPolishing::apply(..., 5)`で研磨する。
2. `UndergroundEquipmentCatalog::combatLoadout()`で装備を解決し、前提nodeを含む上記100SP/rank1技能を`UndergroundAlphaV1PlayerCatalog::playerSkillBuild()`で解決する。active技能とpassive modifier、全快HP・MP100・覚醒0をsnapshotへ設定する。対処AIは下表の上から順に`PriorityCombatAiConfiguration::normalizeRules()`へ通す。戦技2名は同じAIとする。
3. `otherworldCatalog('bahamul_intermediate_2')`のcatalog、4人snapshot、敵key配列`['bahamul_intermediate_2']`を`AlphaV1CombatModel::fightPartySnapshots($catalog, $party, $enemyKeys, $seed, 80, 0)`へ渡す。64seedを一度ずつ使い、勝利・全滅・時間切れとroundを集計する。

| 役 | 条件 → 行動（上から優先） |
|---|---|
| 戦技 | 常時→覚醒、常時→奥義、ready→`executioner_cut`、ready→`severing_bleed`、ready→`armor_break_strike`、ready→`dagger_flurry`、ready→`precision_cut` |
| 護身 | 大予告→覚醒、大予告→奥義、自分に`aegis`なし→`counter_stance`、自分HP60%以下かつready→`renewing_guard`、ready→`bulwark_strike`、ready→`unbroken_retort`、常時→`shield_bash` |
| 祝福 | 大予告→覚醒、味方HP35%以下→奥義、味方HP80%以下かつready→`mending_prayer`（最低HPの味方）、自分MP50%以下→`crystal_cycle`、常時→`holy_bolt` |

無対策寄りの比較は、上表から明示的な奥義ruleを外し、覚醒ruleの条件を常時にする。本体の自動奥義判断は残す。＋0比較は研磨段階だけを0にする。敵HP候補の比較はstageの`max_hp`だけをローカルでoverrideし、戦闘処理や他の条件は同じにする。

変更はstage数値、装備生成/倍率の上限、結晶の基礎anchor、既存代表2ファイル、関連手引きと本記録。新release branch・World Ruleset世代・migration・新戦闘engineは作成していない。

変更path：

- `product/config/underground-alpha-v1.php`
- `product/config/underground-equipment.php`
- `product/app/Domain/Underground/Combat/UndergroundEquipmentScaling.php`
- `product/tests/Underground/Unit/BahamulCombatTest.php`
- `product/tests/Underground/Unit/UndergroundRuntimeEquipmentGeneratorTest.php`
- `product/docs/architecture/underground-equipment-scaling.md`
- `product/docs/manual/equipment.md`
- `product/docs/plans/bahamul-intermediate-2-balance.md`

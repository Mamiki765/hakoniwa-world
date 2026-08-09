# ADR-0009 ver 1.3.0 のNation賞と原作準拠TOP

- 状態: 採用
- 日付: 2026-08-09
- 対象: ver 1.3.0、AWARD-01、公開TOP、怪獣討伐周期

## 文脈

ver 1.3.0では、公開TOPを箱庭諸島2＋に近い情報密度へ戻し、Nationの賞と怪獣討伐実績を永続表示する。原作の表示と判定は参考にするが、現行の共有World、同率順位、ruleset不変性、production data境界へ独立実装する。

原作の名称、系列順、段階受賞、難民受入の監査根拠は`_references/hakoniwa-2plus/source/info.c:306-354`、`info.h:82,97-98`、`map.c`である。exact thresholdは2026-08-09にownerが提示したver 1.3.0条件表を正本とし、原作sourceから導いた別値より優先する。賞画像は外部read-only assetの`prize0.gif`〜`prize10.gif`だけを論理keyから解決し、画像バイナリはGit、container image、`product/public`へ収録しない。

## 決定

### TOP表示

`GET /api/v1/public/worlds/{world}/rankings`だけに`achievements`を追加する。World summary、public Nation detail、public previewへ賞を混入しない。賞と種類別討伐統計はそれぞれWorld単位の一括queryで投影し、NationごとのN+1 queryを行わない。

各Nationは2行で表示する。1行目は順位、島名と賞／討伐mark、人口、面積、推定資金、正確な全food合計、農場、工場、採掘場、生存ターンの順とする。2行目は島主名だけを表示し、profile commentは置かない。施設規模0は`保有せず`とする。ランキング対象と順位は現行契約を維持し、stateで除外せず、最終人口降順、領土降順、Nation ID昇順とする。

### 永続賞

`nation_awards`はWorld、Nation、award key、受賞turn、occurrence keyを保持する。条件賞は`once`、反復賞は`turn:<target_turn>`をoccurrence keyとし、DB unique制約とapplicationの`insertOrIgnore`で同じturnの再実行を冪等にする。一度確定した賞は取消さない。賞による資金、資源、能力、判定補正はない。

条件賞は最終集計後、次の系列順で判定する。各系列は下位から順に、未受賞の最初のeligible tierを1 turnにつき最大1個だけ付与する。複数系列は同じturnに同時受賞できる。

| 判定順 | 賞 | 条件 |
|---:|---|---:|
| 1 | 災難賞 / 超災難賞 / 究極災難賞 | turn開始人口から最終人口への純減が50,000 / 100,000 / 200,000人以上 |
| 2 | 繁栄賞 / 超繁栄賞 / 究極繁栄賞 | 最終人口が300,000 / 500,000 / 1,000,000人以上 |
| 3 | 平和賞 / 超平和賞 / 究極平和賞 | 当該turnに実際に受け入れた難民が20,000 / 50,000 / 80,000人以上 |

`award.turn`はtarget turnが100の倍数のとき、最終人口最大の全Nationへ付与する。presentation用の領土・ID tie-breakより前に最大人口集合を求めるため、人口同率は全員受賞する。候補集合は現行ランキング契約と同じ全Nationとする。

### 怪獣討伐周期

`nation_monster_kill_stats`は引き続き種類別の永久累積統計とする。別tableの`nation_monster_cycle_stats`は1〜100、101〜200のような100 turn区間ごとのNation attributed final blow総数を履歴として保持する。final blow成立時に永久統計と同じtransactionでatomic incrementし、非帰属死亡、terrain removal、既に解決済みinstanceは加算しない。

各100 turn境界で当該区間の最大値が1以上なら、最大の全Nationへ`award.monster_turn`を付与する。最大0なら付与しない。判定後は次区間の0行を作成するが、完了区間の行は削除・上書きせずaudit historyとして保持する。原作の`prize10.gif`は300 turn賞だったが、ver 1.3.0ではowner decisionにより討伐ターン賞へ明示的に再対応付けする。`prize11.gif`は使用しない。

pre-1.3.0期間の区間値はeventや永久累積値から推測しない。migrationは100 turn境界途中の既存World/Nationをseed要求行として固定し、全要求の明示完了までnon-dry turn開始と周期順位確定をfail closedにする。要求完了は同じWorld/Nation/区間の`seeded_at`付き周期statとDB上で不可分にし、applicationも完了時刻だけの不整合を未完了として拒否する。operatorはWorld key、Nation DB ID、現在区間の確認済みcount、確認tokenを明示するcommandだけを使用し、0件も明示する。既存seed/runtime行、要求のないNation、別World Nation、負数、turn実行中、次target turnの未解決non-dry TurnRunがあれば変更しない。award自体のhistorical backfillは行わない。

### 公開markと操作性

賞は`prize0.gif`〜`prize10.gif`を16×16 CSSで表示する。反復賞は1〜9回を`×n`、10回以上を数字だけで表示し、tooltipに全受賞turnを昇順で列挙する。画像不足時は短いtext fallbackを使う。

怪獣討伐markは永久種類別countが1以上のときだけ表示する。表示画像は討伐済みdefinitionの最大`source_metadata.kind`から選び、DB primary keyや名称順へ依存しない。tooltipはkind昇順で種類名と正確なcountを示す。raw kindとsource metadataはAPIへ返さない。

tooltipは専用buttonをtriggerとし、hover、keyboard focus、tap/click、Escapeで操作できる。`title`属性だけには依存しない。

## Transactionとruleset境界

条件賞、反復賞、周期集計、次周期初期化は既存TurnRunnerの単一transaction内で行う。失敗時はturnの他の変更とまとめてrollbackし、same-target manual retryはunique occurrenceにより二重受賞しない。新しいcanonical phaseは作らず`finalize_turn`で最終集計後に確定する。

公開済みruleset v1/v2 payload、checksum、Worldのruleset参照は変更しない。ver 1.3.0の賞はruleset v3を要求しないapplication-levelの永続・表示機能である。

## 結果

AWARD-01のthreshold、反復性、取消、公開表示、historical backfill gateは本ADRで解決する。将来、新しい賞、取消、受賞効果、別周期、過去award backfillを追加する場合は、新しいowner decisionとproduction data conversionを必要とする。

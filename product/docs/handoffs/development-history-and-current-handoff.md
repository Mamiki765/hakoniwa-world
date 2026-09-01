# hakoniwa-world 開発経緯・現行引継ぎ

> 更新日: 2026-09-02 JST  
> 対象リポジトリ: `Mamiki765/hakoniwa-world`  
> 配置先: `product/docs/handoffs/development-history-and-current-handoff.md`  
> 用途: 現在有効なrelease boundary、Owner決定、次の作業開始点の引継ぎ
>
> Codex / implementation agentはread-onlyとして利用してください。Ownerがhandoff更新そのものを明示的に依頼しない限り編集しません。

---

# 1. 現在地

```text
main:
  application: 3.1.0
  Ruleset: hakoniwa-2s-plus-v19 / version 19
  checksum: b65752b88e9daf3c9b64e6d28b72847315d521dfe65b704f4cd8fd622e1368c9

3.1.0 release:
  PR #118
  merge commit: 96b36706ebee211126ab24ca817ef3a5633e3613

AGENTS.md日本語整理:
  e5718fbb70201f523ec689a4e3c00e9d4ae16cea
```

このhandoff更新前のmainではapplication 3.1.0 / Ruleset v19です。次作業開始時はremote mainのexact HEADを再取得してください。

productionについては、GitHub上でmainへmerge済みであることをproduction適用済みの証拠にしません。deploy / OCI / production migrationは別途Owner指示と実環境確認が必要です。

3.0.0 -> 3.1.0のsupported production upgradeは次のcanonical migration 1本です。

```text
product/database/migrations/2026_09_01_000000_rebaseline_3_1_0_release.php
```

release stabilizationではPR途中の3.1.0 migrationをretire済みです。

---

# 2. 3.1.0の主要contract

## 2.1 封印の地 / Trial 1

player-facing名称:

```text
封印の地
└ 地下に眠る古代遺跡
```

- 全10戦
- HPは戦闘間でcarry
- 勝利後、次戦前に最大HPの20%を回復しmax HPでcap
- MPは各戦闘開始時にcanonical maximumへreset
- defeat / withdrawalでrun終了
- 初回clear時SP +40
- first-clear story / unlockはexactly once
- repeat clear可能
- Trial 1 clearでAwakeningと地底layer 1 / 4 slotsを解禁

## 2.2 Awakening

- persistent Awakening gauge
- internal gauge maximum: 1000
- 一戦につき最大1回
- gauge fullかつHP 20%以下でdefault AI発動
- 発動時HP / MP全回復
- 戦闘終了まで5主能力値+30%
- growth pathごとの固定Awakening techniqueあり

## 2.3 地底facility

- Trial progression / layer unlockはSecretary-owned
- facilityはNation-owned
- 1 layer = 4 slots
- build / removalはofficial Turnを1消費
- Surface MapCellや3D coordinate persistenceは使用しない

```text
地底都市: 首都effective population maximum +10,000
地底農場: aggregate farm workforce capacity +10,000
地底工場: aggregate factory workforce capacity +30,000
地底ミサイル基地: missile capacity +1
```

---

# 3. 3.1.1 release方針

3.1.1は3.1.0で見つかったUnderground player-flowと表示の修正releaseです。

新しいgameplay system、balance拡張、Ruleset変更、facility追加は行いません。

## 3.1 封印の地の連戦導線

現状、勝利後にいったん地下メインへ戻ってから続きを開始する導線になっており、10連戦としての見え方が弱い。

3.1.1ではbattle 1〜9の勝利結果画面の最下部に、次の文言を追加します。

```text
次の階層へ
```

flow:

```text
戦闘1勝利
↓
次の階層へ
↓
戦闘2
↓
...
↓
戦闘10 / clear
```

要件:

- battle 1〜9勝利後に`次の階層へ`を表示
- そこから直接次battleへ進む
- battle 10 clearでは表示しない
- defeat / withdrawalは従来どおりrun終了
- server-authoritativeなTrial progressionは維持

### 補足: 地下メイン / 宿

再確認したところ、連戦中は宿が既に使用不可になっているため、3.1.1で「地下メインへ戻ったら強制的にbattle 1へresetする」処理は追加しません。

- 既存の途中progress persistenceを今回わざわざ変更しない
- 宿の使用可否contractも変更しない
- 3.1.1の目的は連戦導線を`次の階層へ`へ直すこと

## 3.2 20% interbattle healの確認と表示

現行contractの20%回復がruntimeで実際に適用されていることを確認します。

- 対象: 次battleがある勝利時
- 回復量: current canonical max HPの20%
- max HPでcap
- heal後HPを次battleへcarry
- MP reset contractは変更しない

実際に回復が発生した勝利結果 / 勝利ログへ、次の文言を追加します。

```text
体力が少し回復した
```

表示だけ追加してhealが欠けたままにしないこと。healしていない場面で文言だけ出さないこと。

## 3.3 Awakening gauge表示

現状のplayer-facing表示:

```text
1000 / 1000
```

3.1.1では数値を出しません。

```text
覚醒ゲージ
[ progress barのみ ]
```

要件:

- `current / max`数値を削除
- progress barだけで進捗を示す
- internal gauge maximum 1000、gain formula、persistence、AI発動条件は変更しない
- accessibility上必要なprogress semanticsは維持してよい

### 色

スクリーンショット確認上、満タン時のゲージ本体の金色が明るすぎるため、3.1.1で少し落ち着いた色へ変更します。

- 変更するのは**ゲージのfill色だけ**
- 外枠 / card背景 / 枠線 / layoutは変更しない
- 未満時と満タン時の状態差が分かることは維持
- 数値非表示と合わせ、情報量を減らして見やすくする

---

# 4. 3.1.1 non-goals

- Trial 2以降
- 第二狩場
- enchant / random drop / Item Lv progression
- Awakening gauge balance変更
- Awakening technique balance変更
- Underground facility追加・数値変更
- Ruleset semantic change / v20追加
- party / team battle
- production deploy / OCI操作
- unrelated cleanup / broad refactor
- 「念のため」のTrial run reset追加

必要な変更がこの境界を超える場合は実装前にOwnerへ報告してください。

---

# 5. 3.1.1 verification重点

1. battle 1〜9勝利後に`次の階層へ`が表示される
2. `次の階層へ`で次battleへ進む
3. battle 10 clearでは表示しない
4. 既存の宿使用不可contractを壊さない
5. 20% healが実際に適用される
6. heal後HPが次battleへcarryされる
7. 実際にhealした勝利結果へ`体力が少し回復した`が出る
8. defeat / withdrawalで不正なheal表示を出さない
9. Awakening gaugeの`0 / 1000`等の数値がplayer-facing UIから消える
10. progress bar自体は維持
11. ゲージfillだけを落ち着いた色へ変更し、外枠 / card / layoutを変更しない
12. gaugeの内部値・蓄積・発動条件は変えない

次の自然な開始点は、**3.1.1: 封印の地の連戦導線、20%回復確認＋表示、Awakening gauge数値非表示＋fill色調整**です。

# Black表示テーマ

既存のDarkとは別の、黒・灰色を基調とした表示テーマ。配置・操作・ゲーム処理は変更せず、既存のCSS変数と`hakoniwa_theme` cookieへ`black`を追加する。application versionはOwnerの決定まで変更しない。

## 参考にした実物

`_references/yamanity/repository/`にある`mjtakenon/hakoniwa`の次のファイルを読んだ。

- `app/resources/css/themes.scss`：Darkの色と前景・背景の組。
- `app/resources/css/app.scss`：通常・主色・警告・エラーボタンのhover、入力・select。
- `app/resources/js/ThemeList.json`、`components/ThemeSwitcher.vue`、`components/ThemeSettings.vue`：テーマ区分とsurface variant上の表示。
- `components/CommentForm.vue`：focus時も前景・背景を同時に切り替える関係。
- `components/IslandNameSettings.vue`、`PlanController.vue`、`IslandEditor.vue`、`IslandPopup.vue`、`StatusTable.vue`、`LogViewer.vue`、`CountdownWidget.vue`：disabled、選択・popup、数値増減、警告表示の用途。

参考にしたのは配色と状態の関係だけで、CSS本文・Vue実装・画像・文言は収録していない。BlackのCSSはhakoniwa-worldの既存selector・変数に対する独立実装であり、復刻テーマではない。

## 配色の対応

| やまにてぃDarkの用途 | 色 | Blackでの対応 |
| --- | --- | --- |
| background / on-background | `#24272a` / `#e2e2e5` | canvas・入力背景 / ink |
| surface / on-surface | `#34373b` / `#e2e2e5` | paper・panel・modal / ink |
| surface variant / on-surface-variant | `#42474e` / `#c2c7cf` | paper-deep・表見出し・hover / muted |
| primary / on-primary | `#96cbff` / `#003353` | navy・navy-solid / on-solid、通常の主ボタン |
| primary container / on-primary-container | `#004a76` / `#cee5ff` | selected-surface / navy-light、選択状態・増加系の通知 |
| secondary / container | `#5fdbb9` / `#005140` | coral・選択のアクセント / success-surface |
| on-secondary-container | `#7ef8d5` | success・coral-dark |
| outline | `#8c9198` | line・input-border・heading-line |
| on-link | `#acb7e7` | link、visitedも同じ読める色 |
| alert container / on-alert-container | `#594400` / `#ffdf93` | warning-surface / warning |
| error / on-error | `#ffb4ab` / `#690005` | danger-solid / on-danger-solid、危険操作のボタン |
| on-error-container | `#ffdad6` | danger-strong、暗いエラー背景上の強調 |
| on-plus / on-minus | `#5fb3ff` / `#ff6c72` | info・資源予測の増加 / minus・資源予測の減少 |

背景よりpanel、panelよりvariantが明るい関係を維持する。既存HUDの白文字を明るい主色の上へ載せないよう、HUD・map toolbarはvariantとinkの組にする。主ボタンはprimaryとon-primary、dangerボタンはerrorとon-errorをそれぞれ組にする。

## hakoniwa-worldで補完した用途

- 秘書・地底・施設詳細・交易場・資源方針・manualは既存の共通変数を使う。
- disabledはvariant / mutedの組。opacityで一律に薄くせず、操作できない状態でも文字を読めるようにする。
- 覚醒ゲージ用の`#c2b6ee`は独自補完。灰色の背景上で見える淡い色とする。
- 半透明panelと影は既存配置に合わせた灰色・黒の合成色。scene画像や地形・施設画像は加工しない。

保存は既存cookieのPath・有効期間・SameSite・Secure条件を維持する。app / manual / community guidelinesの初期HTMLも同じ許可値を持ち、不正値は従来通りsystemへ戻す。

## 実画面確認

ローカルViteで実際のVue/CSSを表示し、既存テスト由来のAPI fixtureで確認した。manual / 利用ルールは現行本文・CSSとBladeに対応するHTMLを表示した。Laravelそのものの初期HTMLは既存`FirstProductionReleaseTest`の許可値確認へBlackを追加して検証する。

| 画面・操作 | 確認した幅 |
| --- | --- |
| TOP、自島のセル・コマンド・地図・開発計画、HUD詳細、資源方針、デイリー | 1440 / 768 / 390px |
| 地上からの地下施設選択、階層・座標・建設対象 | 1440 / 768 / 390px |
| 秘書、秘書装備と変更modal、交易場と効果tooltip | 1440 / 768 / 390px（tooltipは768 / 390px） |
| 地底ホーム、ショップ、装備・保管庫、売却確認dialog、探索結果・戦闘ログ | 1440 / 768 / 390px |
| マニュアルの表・リンク、利用ルールの本文と連絡ボタン | 1440 / 768 / 390px |

スマホのworkspace-jumpは横スクロール位置を移動する既存操作のまま。rangeと数値入力、select / option、keyboard focus、hover、選択状態、disabled、visited linkの宣言も確認した。画像やgradientを除く本文・入力値の前景と背景の合成色を確認し、スクリーンショットでも代表画面を確認した。

白文字を前提にしたHUD、薄い灰色背景の地下施設fallback、強いselectorで旧固定色が残るdanger buttonのdisabledを、Blackだけで前景・背景の組に揃えた。既存Darkの定義は変更していない。

実機スマホのOS別option popupとproductionの外部scene / キャラクター画像は未確認。画像の加工・resolver・配信設定には変更を加えていない。

検証：既存AppShell focused test（21件）、typecheck、build、lint（既存の`v-html`警告5件のみ）、文書validator、`git diff --check`。CSS値やテーマ件数を固定するsnapshot testは追加していない。

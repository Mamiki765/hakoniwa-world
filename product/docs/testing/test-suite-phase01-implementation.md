# テスト再設計 Phase 0a・Phase 1 実装記録

日付: 2026-09-15。branch: `release/4.2.0`。Status: Phase 0a・Phase 1実装済み、focused確認PASS。

## 開始点と範囲

Forgejo APIのmain先端を読み直し、手元の`origin/main`と同じ`00182bd0eaee52f34194c7b40bc5e98712108718`であることを確認した。旧作業branchからは16 commits進んでおり、このmainから新規branchを作成した。`release/4.2.0`がremoteに存在しないことも作成前に確認した。

対象は[設計図](test-suite-rebuild-plan.md)のPhase 0aとAランク10項目。マップ再利用、scope/4 shard配線、B/Cランク削減は後続工程。application version、runtime、Ruleset、schema/migration、handoffは変更しない。

## Phase 0a：不要テストの増殖防止

AGENTS §8の重なった追加・matrix・確認範囲の規則を統合した。

- 新設/拡張には「到達可能な操作またはsupported upgrade」「具体的な故障」「既存確認で検出できない理由」を必要条件とした。不足がなければ追加しない。
- 不安や「念のため」で必要性を広げることを禁止した。provider/loop/assertion/別layerも同じ基準に含め、method数だけを減らして内部を肥大化させない。
- 重要なデータ・資産・進行保護に必要なコストを認め、外部入力の認可・安全性やsupported upgradeを到達不能な異常と混同しない。
- 必要な確認が通れば終了する。新たな変更・失敗・具体的な懸念なしの拡大/反復、一時調査の無条件な恒久test化を防ぐ。
- test追加を求めるreviewにも同じ根拠を要求する。Owner intent、CIの重複実行回避、push権限とCIコストの分離は維持した。

## Phase 1：Aランク全項目の処置

| 設計図の対象 | 実施した変更 | 残す確認・根拠 |
|---|---|---|
| 島登録の21億番号 | `ApiAndAssetTest`のDB直書き＋内部error誘発ブロックを削除、method名を残した意味に変更 | 改行拒否、重複名、request conflict、二重所属の代表を維持 |
| 銀行の最大整数残高 | `UndergroundPlayerAccessTest`の`PHP_INT_MAX-500`を保存するoverflowブロックを削除 | 通常入出金、全額移動、残高不足、他人の資産保持を維持 |
| requestの巨大能力/残高 | 銀行全actionへの偽field反復を削除し、最初の入金1件だけ通常範囲の偽残高/他人IDを使用。宿泊HP、購入stats、装備戦闘weapon powerも通常範囲へ変更。探索の重複weapon powerを削除 | server側資産/能力がrequestで上書きされない代表を各々の異なる受付境界に保持。新しいcase/helperは作らない |
| combat乗算の整数限界 | `test_combat_value_scaling_rejects_an_actual_operand_product_that_cannot_fit_an_integer`を削除 | 合法なprogression、通常の戦闘計算とdamage確認を維持 |
| level scalingの巨大値 | 巨大level3入力を削除し、正のlevelの計算とzero拒否へ縮小。method名も変更 | `progressionScaleBps`と`storyBenchmarkScaleBps`の既存代表を維持 |
| simulatorのinteger headroom | headroom拒否・canonical enemy巨大levelの2ケースを削除 | 合法checkpoint replay、決定性、通常summaryを維持 |
| 架空のspecial parameter | `test_future_special_parameter_api_distinguishes_omitted_defaults_from_explicit_null`を削除 | `design_id`/`optional_variant`は現行app/config/frontendに利用者なし。v25で実在するparameterは4 commandの`target_nation_id`で、default付きschemaはない。nullableな記念碑対象や援助対象の意味まで架空扱いせず、既存の記念碑/対象島/selector/quantity代表を維持 |
| Appの偶然の構造/個数 | ranking badge子要素の順序・class由来配列と、monster markのspan全件数assertionを削除。ranking case名も変更 | badgeの意味、zero/負値、対象島への遷移、monster数の表示を維持。地下入口の総ボタン数assertionは現行/旧baselineとも存在せず、元の設計候補を訂正。既存の探索/試練操作確認を維持 |
| 指輪なしfinanceの全metadata形 | `test_no_equipped_ring_preserves_the_exact_legacy_finance_metadata_shape`を削除 | `PlayerIslandEventService::financeMessage`は必要field `applied`だけを読み、全キー一致に依存しない。通常資金繰りはDomesticCommandExecution、指輪の決算/overflow/rollbackは既存代表が担当 |
| 全8怪獣の報酬再実行 | `test_all_eight_definitions_use_their_exact_wreckage_reward_values`を削除 | 通常分配/上限/一度限り、奇数端数、同一owner、hostless、無帰属、rollbackを維持。周期報酬境界はAwardSystemの既存代表で確認 |

新規testは0。独立methodを6件削除し、残した意味へ2件renameした。不要な保証を別の人工異常・provider・loopへ置換していない。

## 検証

| 確認 | 結果 |
|---|---|
| PHP全identifier列挙 | 116 files / 915 methods / **1,052 cases**。mainの1,058から6減。8 identifier消滅のうち2はrenameで、追加2件と対応 |
| PHP構文/Pint | 変更7ファイルPASS |
| focused PHPUnit | **68 tests / 2,680 assertions PASS**。error/failure/skip=0。選択identifierとJUnitの実行集合が一致 |
| frontend | Appの変更2ケースPASS、同fileのESLint PASS。他42ケースはfilter対象外 |
| 一時DB | 既存managerで`hakoniwa_parallel_172aff1a_01_test`を1個作成しcleanup。`pg_database`照合で残存0 |
| source照合 | PHP実行前後の7ファイルとcomposer.lockのSHA-256一致。変更はAGENTS・test・調査/実装文書のみ |

PHPUnitは既存ローカルdevelopment image（PHP 8.5.8 / PHPUnit 12.5.32）で実行した。残存2 Unit fileの全件、島登録・銀行/宿泊・装備/探索・queueの実selector/quantity、指輪決算、怪獣分配/rollback、周期報酬の既存代表を対象にした。選択した全68 identifier、削除6件、rename2件とsource hashは[機械的な検証記録](test-suite-phase01-results.json)に保存した。

frontendは既存ローカル依存を使い、`renders recovery and KARMA`と`opens a guest preview through public-only endpoints`だけを実行。jsdomの`scrollTo`未実装messageが出たが、両ケースとESLintはexit 0。依存の追加や更新は行っていない。

Full PHPUnit/Vitest、全体PHPStan、frontend typecheck/buildは未実行。runtime・型定義・build設定に変更がなく、今回の削除/縮小箇所と残す代表をfocusedで確認した。新たな未解消懸念はなく、同じ全suiteの追加実行はしない。focused PHPUnitの所要時間は2分53.858秒だが、同一条件の変更前測定がないため、suite全体の短縮率は主張しない。

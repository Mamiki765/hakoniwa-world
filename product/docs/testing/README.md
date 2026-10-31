# テストと検証記録の入口

実行対象・引数は [Composer scripts](../../composer.json)、[PHPUnit設定](../../phpunit.xml)、[scope planner](../../tests/Support/TestShardPlanner.php)、[Quality CI](../../../.github/workflows/quality.yml)を正本とする。環境の準備とDBの分離は[ローカル開発手順](../../../docs/operations/local-development.md)を参照する。保存された過去の件数を現行discoveryの代わりに使わない。

## 通常の実行

以下は依存と専用test DBを用意した`product/`での入口。変更に必要なscopeと既存代表を選び、repository-wide回帰はexact-head CIで確認する。

| 対象 | backend | frontend |
|---|---|---|
| 地上・共通 | `composer test:surface` | `npm run test:surface` |
| 地底・共通 | `composer test:underground` | `npm run test:underground` |
| 全体 | `composer test:all`（`composer test`も同じ） | `npm run test:all`（`npm test`も同じ） |
| 既存代表に限定 | `composer test:surface -- --filter TurnCronNoOpTest` | `npm run test:surface -- resources/js/map/projection.surface.test.ts` |

backendのsurfaceは`Shared/Unit/Feature`、undergroundは`Shared/Underground`、fullは4区分全部を選ぶ。Composerから同じ`tests/scripts/run_parallel_tests.sh`へ入り、絞り込みは`focused`として記録される。並列が必要なときの入口は`composer test:parallel -- 4 surface`などで、Windows wrapperも同じrunnerを使う。個別実行のために別のDB設定やテスト集合をコピーしない。

QualityはPHPUnit設定からfileを検出し、固定plan・16 shardの割当と識別子の過不足を検査する。フロントエンドの3区分は[Vite設定](../../vite.config.js)の`*.shared.test.ts`、`*.surface.test.ts`、`*.underground.test.ts`から検出する。現在の件数・成功の証拠は対象SHAのdiscoveryとCI結果で確認する。

## 保全するテスト資産

- `tests/Concerns`と`tests/Support`はfixture・DB分離・worker・選択と証拠保存の実装である。`Support/ReusableSurfaceTemplateBuilderTest.php`は通常suiteの外にあるが、DB managerがtemplate構築に直接使う。
- `tests/Support/*_worker.php`は並行テストから別processとして起動される。worker単体の実行や、ファイル名だけでの削除は行わない。
- `tests/Fixtures`、`tests/Underground/Fixtures`、フロントエンドの`__fixtures__`やtest helperは回帰テストの入力である。`party-presentation.json`には[生成元](../../tests/scripts/generate_party_presentation_fixture.php)とPHP側fixtureがある。生成する際は出力先を明示し、既存入力を無関係に上書きしない。
- Upgrade、concurrency、rollback、retention、idempotencyなどの過去事象を契機にしたテストも、現行の到達経路を守る場合は現役である。古い版番号や日付だけで一時検証と扱わない。

## 保存された調査と測定器

`baseline-6717522/`と`test-suite-audit.json`、`test-suite-identifiers.txt`、`test-suite-inventory.csv`、`test-suite-main-delta.json`、`test-suite-phase01-results.json`は2026-09-15の固定SHAを対象にした証拠で、現在のテスト一覧ではない。識別子や件数を上書きして更新するのではなく、比較対象SHA・条件を保持して読む。DB N+1資料も各本文の固定時点と[最終記録](post-5.0.1-nplus1.md)を確認し、当時の未修正候補を現在の未実装と断定しない。

[3.10.0の測定器・結果](../releases/3.10.0-balance/)と[4.5.0の測定器・結果](../releases/4.5.0-skills/)は、本文・入力・出力・再現条件を合わせて保全する。通常CIの恒久的なDPS合格値ではなく、現行runtimeでの再現可能性は別確認である。`__DIR__`からproduct rootを求めるscriptもあるため、本文をarchiveへ移したことだけを理由にscriptを単純移動しない。匿名集計から元の非公開入力を推測・復元しない。

[combat laboratory](../../../docs/architecture/underground-combat-laboratory.md)から参照される`underground-balance-foundation-*.json`と、旧Trial1測定の`underground-trial1-v1-1000-seeds.json`も過去の測定証拠として保持する。[manual/indexes.php](../manual/indexes.php)はゲーム内マニュアルが読む文章であり、検証scriptではない。

実行証拠はrunnerが作る`storage/framework/testing/test-evidence/`の固定plan・対象SHA/hash・選択区分・log・JUnit・cleanup結果を一緒に扱う。`focused`の成功をscope全件の成功へ読み替えず、欠けたmetadataや不明値はそのまま残す。詳細ログ・receiptの運用上の整理は[retention runbook](../operations/receipt-audit-retention.md)を参照し、リポジトリ整理と本番データ削除を混同しない。

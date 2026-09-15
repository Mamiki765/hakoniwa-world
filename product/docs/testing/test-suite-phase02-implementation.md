# Test suite再設計 Phase 2 実装・検証記録

Date: 2026-09-15

Branch: `release/4.2.0`

Base: `origin/main` `00182bd0eaee52f34194c7b40bc5e98712108718`

## 実装した境界

- `phpunit.xml`へ非重複の`Shared` suiteを追加した。FullはShared・Unit・Feature・Underground、SurfaceはShared・Unit・Feature、UndergroundはShared・Undergroundのset unionとした。
- `TestShardPlanner`をscope選択の唯一の正本とし、`full` / `surface` / `underground`と`all` aliasを実装した。class名や文字列filterでdomainを推測しない。
- planner CLI、shell runner、Windows wrapper、Composer、DB manager、manifest、evidence、CIの呼出しへ同じscopeを渡した。Composerの3つの直列aliasも同じdispatcherを1 workerで呼ぶ。
- PHPUnit追加引数はshell配列で渡す。追加引数があるrunはevidenceへ`selection_mode=focused`と記録し、scope全体の結果と区別する。
- evidenceへscope、選択file集合hash、実行identifier集合hash、source tree hash、shard割当を追加した。JUnitのcase数は`testcase`要素を直接数え、providerを含む実行件数を落とさない。
- DB名、`APP_ENV=testing`、一時config、manifest限定cleanupの既存guardを維持した。Phase 2以前のscopeなしmanifestは`full`として検証し、安全なcleanup retryを継続できる。

## Sharedへ移したowner

最初の候補11ファイルと`InquiryConcurrencyFailureTest`を`tests/Shared/{Unit,Feature}`へ移した。さらに、SP還元の既存migration caseを`UndergroundRuntimeTest`から`UndergroundSkillRefundUpgradeTest`へ移した。caseの意味を増やさず、実migration、既存progress、完了済みrequest、再構築完了までの停止を同じ1 caseで保持した。

scope不正値がDB準備前に失敗する契約には、plannerの既存validation群と同じ責務として1 methodを追加した。Phase 1完了時の1,052 identifiersから1,053になり、mainの1,058より5少ない。SP migrationは移動であり追加caseではない。

## 列挙結果

| scope | files | serial identifiers | 4 shard identifiers | duplicate | missing | unexpected |
|---|---:|---:|---:|---:|---:|---:|
| Full | 117 | 1,053 | 1,053 | 0 | 0 | 0 |
| Surface | 98 | 835 | 835 | 0 | 0 | 0 |
| Underground | 32 | 291 | 291 | 0 | 0 | 0 |

SurfaceとUndergroundの共通部分はSharedの13 filesだけで、Fullは両scopeのunionを各1回列挙する。`all`はFullと同じ117 filesになった。Undergroundを33分割したindex 32は0 filesとして正常に列挙され、無引数PHPUnitを起動しないshell分岐と対応する。

## 実行した確認

- `TestShardPlannerTest`: 9 tests / 55 assertions PASS。
- planner・DB manager focused dispatcher: 30 tests / 106 assertions PASS。
- `composer test:surface -- --filter TestShardPlannerTest`: 9 tests / 55 assertions PASS。同じdispatcherへfilterを渡した。
- SharedのSP migration caseをUnderground dispatcherで実行: 1 test / 12 assertions PASS。evidenceは`scope=underground`、`selection_mode=focused`、source tree hash、1件のidentifier hashを記録した。
- invalid scopeをplanner CLI、DB manager CLI、shell runnerで確認し、いずれもDB作成前にexit 1。
- Bash syntax、Composer JSON validation、変更PHPのPintを確認した。
- 対応する旧manifestが残っていた4つの`hakoniwa_parallel_3809b002_*_test`を、manifestを検証した上で限定cleanupした。最終確認で`hakoniwa_parallel_%_test`は0件。

development image buildは、Composer設定を反映するため実行したが、GitHub archive取得が連続timeoutしたため完了していない。既存containerへ今回の`composer.json`だけを一時反映してComposer entrypointを検証した。repositoryへ回避用mountやdependency変更は追加していない。

## Phase 3へ残すもの

- 通常map case専用fixture区分、初回generationとcaseごとのrollbackを実装する。
- 同一baselineで連続2 case、失敗後、単独、逆順の独立性を測る。
- fixture生成回数とfixture時間をevidenceへ追加する。Phase 2ではscopeと選択集合の配線までを確定し、map再利用を先行実装していない。

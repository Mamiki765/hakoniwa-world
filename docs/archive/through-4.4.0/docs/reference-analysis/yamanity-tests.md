# やまにてぃ テストと保守性

## 確認できたテスト

app/tests配下は次の5ファイル群である。

- Api Islands CommentsControllerTest。
- Api Islands DetailControllerTest（PATCH改名）。
- Api Islands Bbs IndexControllerTest。
- Web Register IndexControllerTest。
- Scnario/NextTurn.php（名前にtypoあり）。

TestCaseはRefreshDatabaseとseedを有効にし、Viteを無効化する（app/tests/TestCase.php:8-20）。CreatesApplicationはAPP_ENVがtestingでなければ例外にし、誤DB接続を防ぐ（CreatesApplication.php:14-24）。

NextTurnはRegisterIslandSeederを実行してexecute:turnの成功codeだけをassertする（NextTurn.php:8-15）。turn後の状態、順序、乱数、ログ、rollback、二重実行はassertしない。

## Factory、Fixture、Mock

User、Island、IslandStatus、IslandTerrain、TurnのFactoryがある。SeederはTurnSeederとRegisterIslandSeeder。固定fixtureやsnapshot、mock、fake clock/random generatorは確認できない。外部Google/Yahoo、webhookをmockしたtestもない。

## 未確認・存在しないもの

- Entity単体test。
- 各Plan/Cell/Disaster/Monster/Missileのunit test。
- JSON round-trip/schema互換test。
- turn phase順・失敗rollback・再実行・二重起動test。
- API plans/BBS delete/auth callback/maintenance/rate limit test。
- Vue component/unit/E2E test。
- TypeScript typecheck専用script、ESLint。
- PHPStan/Psalm等の静的解析。
- migration down test、外部キー整合性test。
- load/performance test。

## CI

GitHub Actionsはmain/developへのpush/PRでUbuntu、PHP 8.2.4、MySQL 8.0.19を起動し、Composer install、key生成、permission、migration、php artisan test tests/Appを実行する（.github/workflows/laravel.yml:3-65）。

frontend install/build/test、Pint、static analysis、coverageはCIにない。シナリオはtests/App以下なので実行対象だが、test file/class namingがPHPUnit discovery条件を満たすかは実行していないため要確認である。

## 保守性

参考にしたい点:

- testing環境以外を明示拒否。
- RefreshDatabaseとfactoryでController testを独立化。
- Query Detectorをdev dependencyとconfigに含める。
- Entityへのルール分離とResult object。

改善すべき点:

- Facade、Eloquent、global Auth/Request/config、mt_rand、nowが深く入り、純粋unit testが難しい。
- 重要ルール数に対してtest coverageが極端に少ない。
- testがHTTP successや件数中心で、domain invariantを保証しない。
- ExecuteTurnが巨大でphase単体testができない。
- UIの固定15列、API payload、cache behaviorが自動検証されない。

新作ではClock、RandomSource、Repository、RuleSetを注入し、phase単体、property-basedな六角座標、JSON schema migration、transaction rollback、idempotency、chunk境界、capital invariant、outboxを最初からtest対象にする。

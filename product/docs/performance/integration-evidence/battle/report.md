# 地底catalog・秘書item cacheの結合/scope確認

基準版 `2e93ec918774ad64d787aba99fc59a7bc3e54d16` と6patch統合tree `99585a57f9d48007a5581ddf6ae1b42554841c9e` を比較した。PHP 8.5.8、Laravel 13.22.0/current composer.lock vendor。専用隔離containerを使い、製品app/testsの編集、commit/push/CI、本番/OCI/secret操作は行っていない。

## 結果

この確認範囲でcatalogとitem cacheの混入・scope切替漏れは見つからなかった。外部probeは基準/統合の各版で15項目すべて成功し、catalog、通常HPの戦闘、変更HPの戦闘、item定義の4hashは版間一致した。probe終了までDB connectionは両版とも0件だった。

| 対象 | 確認したこと |
|---|---|
| 同scope | 統合版のPlayerCatalogは同instanceを返す。基準版は従来どおりtransientであることを区別して確認 |
| 可変HP | 同じcatalog/seedで異なるHPを与えると、各fightのinitial stateに対応する値が入る |
| 可変Lv | 同じresolver/catalog cacheを通してもLv10とLv20のcombat statsは異なる。個人の値はcatalogへcacheされない |
| RNG/状態 | A戦闘→HPの違うB戦闘→A戦闘を行い、Aのresult全体hashが再現。入力snapshotとcatalog manifestも変わらない |
| item cache | nested配列の呼出元変更とresolved rarity/priceがbase定義へ戻らない。他instanceも元の値を返す |
| item subclass | base cacheを温めた後もsubclassの動的definitions() overrideが次の呼出しへ反映される |
| framework reset | 実際に`app('queue.worker')`へ登録されたresetScope callbackを呼び、scoped markerとPlayerCatalogが破棄されることを確認 |
| 次scope | 新catalogの内容と同seed戦闘結果は前scopeと一致。定数由来item cacheは引き続き正しく使える |

加えて、統合版で既存のauthoring/config/manifest変更代表3件とitem caller-isolation/override代表2件を同一PHPUnit起動で実行し、**5 tests / 38 assertions成功**。これには同instanceでの設定変更、同mtime/同サイズのmanifest差替え、trial差替え、無効JSONのfail closed、scope resetを含む。`focused-freshness.log`を保存した。

## Laravelの実経路

今回のcurrent vendor sourceで次を確認した。

- `vendor/laravel/framework/src/Illuminate/Queue/QueueServiceProvider.php:240-276` が`queue.worker`をsingleton登録し、`:247`のreset callbackでlog context、query duration、`:263`の`forgetScopedInstances()`、Facade instance等をclearする。
- `Illuminate/Queue/Worker.php:236-237` はdaemon loopの各job予約より前にそのcallbackを呼ぶ。
- `Illuminate/Container/Container.php:528-532` のscoped登録はscoped listへabstractを記録し、`:1738-1748`のforgetScopedInstancesは対応するcontainer instanceをunsetする。
- 本体`AppServiceProvider.php`のPlayerCatalog scoped bindingが実containerへ入った状態でprobeした。手作りのreset関数への置換ではない。

外部probeはprotected propertyから**frameworkが実際に登録したClosure**を取得して実行した。daemonを常駐させたりjobをpop/dispatchしたりした試験ではない。callback実行の正確性と、daemonがそこを呼ぶことのsource確認を分けている。

`SecretaryItemCatalog::definitions()`のstatic cacheは`self`定数だけに由来するbase配列であり、scope resetで破棄される必要はない。返り値はcopy-on-writeで、個人残高/所有row/HP/RNGを持たない。PlayerCatalogのmemoはsource bytes・foundation object・grounds値だけを依存とし、個別player snapshotは毎回生成する。両cacheは異なる寿命を持つが、今回の同process/別scope比較で相互汚染を認めなかった。

## 現行起動構成のsource確認

- 本体`product/Dockerfile:21`はPHP 8.5.12 Apache runtime。`compose.yml`の定義serviceはwebとPostgreSQLで、queue/Octane/Horizon専用serviceを定義しない。
- `product/public/index.php:18-20`は標準のapplication bootstrap→handleRequest入口。
- `product/docs/operations/turn-scheduler-worker.md:3`は常駐worker/Laravel schedulerを追加しないと明記し、`:7-9`とcron wrappersは一回の`php artisan hakoniwa:turn:run` / `compensate`起動である。
- composer.json/lockと起動・運用sourceを検索し、Octane/Horizon依存やqueue:work/queue:listenの起動指定を見つけなかった。`config/queue.php`のdatabase既定値とframework queue機能の存在は、常駐workerの本番起動証拠とは扱わない。

これはrepository上の現在の構成であり、実本番process/cron登録を観測した結果ではない。本番に追加された未記載workerが存在しないと断定しない。Octaneは依存/起動構成がないため、そのサーバーを起動した検証は行っていない。

## 証拠と限界

- `scope-interaction-probe.php`: 製品外に置いた再実行用probe。
- `scope-interaction-base.json` / `scope-interaction-combined.json`: 各15項目と4hash、framework callbackの出所。
- `scope-comparison.json`: 両版のhash一致。
- `focused-freshness.log`: 5 tests / 38 assertions成功。
- `source-integrity.json`: 統合manifestの26変更fileすべてが元のSHA-256と一致することを終了時に確認。

追加の広範suite、長期試験、HTTP load/latency測定、DB競合試験は実施していない。worker daemonの実job配送/失敗/restart/PCNTL signalや本番PHP8.5.12は対象外。今回の目的は6変更を合わせた時の定義/可変状態/scope寿命の正確性であり、性能改善率をこの結果から追加主張しない。

## English summary

The baseline and six-patch integration passed 15 external checks each, with identical catalog, combat and item hashes. Different HP/levels produced fresh player state; repeating a seed after another fight reproduced the full result. The real Laravel queue.worker reset callback discarded scoped instances while constant item definitions stayed isolated and valid. Five focused existing tests passed (38 assertions). Repository configuration uses Apache and one-shot turn cron commands; production processes and an actual queue daemon/Octane server were not inspected or run. No product files changed.

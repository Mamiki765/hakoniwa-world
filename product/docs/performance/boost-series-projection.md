# ブースト一覧のseries照会

調査基準: main `2e93ec918774ad64d787aba99fc59a7bc3e54d16`（5.8.3）。本書はコード差分と隔離検証の記録で、本番反映を示さない。

`BoostScheduleService::adminStatus()`は開催回ごとに同じseriesを再取得していた。さらに公開一覧を作った後、管理一覧で`occurrences`を上書きしていた。表示する開催回を先に決め、そのdistinct seriesを一覧内で一括取得する。空一覧はseriesを照会しない。公開一覧は従来どおりkindごとの先頭、管理一覧はID降順・上限500件を返す。

予約・取消・補填枠・権限・lock・永続化・RNG・Rulesetは変更しない。現在時刻、timezone、title、null項目、開催状態、補填scheduleのprojectionを維持し、requestを跨ぐcacheや別のdefinition authorityは追加しない。

## 隔離検証

PHP 8.5.8、PostgreSQL 18.4、internal networkとtmpfsの合成fixture専用DB。依存は現行composer.lock。source DockerfileのPHP 8.5.12と実稼働環境は未検証。

| 確認 | 結果 |
|---|---|
| 同じseriesに3開催回を持つ直接service呼出 | series SELECT **4→1**、開催回SELECT **2→1**、権限用1照会を含む総SQL **7→3** |
| 拡張した既存代表2件 | **2 tests / 61 assertions成功**。空一覧、異なるseries/kind、補填schedule、公開の終了境界、管理ID順、title/null、取消後の管理表示、管理一覧のseries/開催回各1照会を確認 |
| 同じ合成fixtureのafter SQL probe | **1 test / 4 assertions成功**。時間改善率の保証には使わない |
| focused style | Pint `--test`、変更2 PHPファイル成功 |
| focused static analysis | 現行Larastan設定・level 6で変更serviceを検査し、エラーなし |

代表コマンド:

```sh
php vendor/bin/phpunit tests/Feature/BoostScheduleTest.php --filter 'test_preview_counts_exact_odd_slots_and_stale_manual_compensation_requires_reconfirmation|test_finite_recurrence_overlap_end_exclusive_public_boundary_and_cancellation'
php vendor/bin/pint --test app/Application/BoostScheduleService.php tests/Feature/BoostScheduleTest.php
php -d memory_limit=1G vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G app/Application/BoostScheduleService.php
```

SQL数は固定SHAのbeforeと、変更後sourceのSHA-256を記録したafterの各1呼出で比較した。隔離containerの静的解析は、同じ`phpstan.neon`のpathだけをcontainer内の絶対pathへ置き換えた設定を使った。大規模負荷・本番データ・全suite・CI・PHP 8.5.12は未実施。query countの回帰確認は既存代表に追加し、表示文言やRuleset値を別catalogとして固定していない。

## English summary

Boost lists now fetch their distinct series in one batch. The admin response no longer builds a public list that it immediately replaces. A synthetic three-occurrence fixture reduced series queries from four to one and total service queries from seven to three. Focused tests preserve ordering, empty lists, null fields, cancellation states, and compensation schedules. No gameplay, persistence, locking, RNG, or Ruleset changes are included; production latency and PHP 8.5.12 remain unverified.

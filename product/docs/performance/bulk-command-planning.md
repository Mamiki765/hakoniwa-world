# 一括開発計画の破棄候補を数だけ保持する

基準: `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。監査候補 CQ-02 のうち、純粋な計画配列の割当だけを対象にする。

`CommandQueueService::bulkPlan()` は保存しない候補にも `generated` wrapper を作り、preview/apply が両方の overflow policy を計算する。後段が必要とするのは既存計画の取消対象と、破棄総数だった。未登録候補の個別配列を作らず、取消対象は従来の suffix 順、破棄総数は `skipped_count + cancelled_count` とする。候補の取得・検証・並び・snapshot hash、queue 上限、埋立と整地の組、version、request replay、監査の内容と順、日課報酬は従来どおり。

## 測定

[生データ](evidence/bulk-plan-before-after.json)。PHP 8.5.8、同じプロセスで固定基準版と変更版を交互に各3回。空 queue、候補10,000、上限30、2 policy。候補配列を準備した後の `bulkPlan` のみを測った。

| 指標 | 基準版 | 変更版 |
|---|---:|---:|
| 保持メモリ増分 | 8,055,488 B | 25,456 B |
| 保存しない generated wrapper | 19,940 | 0 |
| 所要時間中央値 | 4.076 ms | 0.029 ms |

共有ホストの cold/warm 差が大きいため、時間倍率を実リクエストの速度保証に使わない。DB、地図走査、全候補の validation、snapshot、HTTP は測定外で、これらのコストは残る。候補10,000でも保持する計画配列は queue 上限に依存するが、元の候補配列は引き続き全件必要。

基準版を固定 SHA から取得し class 名だけ変え、private 純粋メソッドを比較した。空/29/30件の queue、候補0/2/3/31/32、挿入先頭/途中/末尾、group 1/2 の代表6条件×2 policy（12比較）で、保持する計画・取消ID順・全件数・preview取消表示が一致した。旧実装ロジックを新しい製品 test にコピーしてはいない。

## 回帰確認と残件

隔離 PHP 8.5.8 / PostgreSQL 18.4、current composer.lock。既存の一括登録6 case（preview変更拒否、overflow、fill_available、duplicate/stale、危険な上書き拒否、埋立pair）を実行し **6 tests / 89 assertions** 成功。生成候補のみが切られる既存 case に、保存される bulk receipt / audit の破棄数と、取消行を作らないことを追加した。変更2 PHP file の Pint 成功。全体回帰は最終 head の CI に委ねる。

CQ-01 の queue 応答 batch 化とは別メソッドで独立。既存 #350 の取得 helper 集約とも変更箇所が異なり、依存なし。移行、Ruleset、乱数消費、transaction / lock の変更なし。

浅瀬全件 lock と候補ごとの近接 SQL は残る。途中打切りで候補総数や preview token を変える案は採用しない。SQL set 化は別の意味互換性・競合検証が必要。

## English

Bulk planning now retains only existing items that must be cancelled and the total number of truncated entries. It avoids allocating wrappers for generated commands that will never be inserted. Queue order, grouped reclaim commands, preview/replay counts, audit records and rewards are preserved. In an isolated 10,000-candidate pure-method probe, retained plan memory fell from 8,055,488 to 25,456 bytes; this excludes candidate collection and database work. Twelve representative baseline comparisons matched, and six focused PostgreSQL tests passed. No schema, ruleset, RNG or locking changes are included.

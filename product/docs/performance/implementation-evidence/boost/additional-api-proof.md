# API・DB設計の追加proofと判断

基準: `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。既存の網羅調査成果物は変更していない。今回の追加proofは親が作成したBoost専用internal network/tmpfs PostgreSQL 18.4・PHP 8.5.8の合成環境だけで実行した。

## 採用: AP-02 Boost一覧のseries batch

- before: `/workspace/perf-audit-20261031/evidence/api-boost-series-queries.json`（同じ固定SHA、1series/3occurrences、series SELECT4/開催回SELECT2/総SQL7）。
- after: `boost-series-after-annotated.json`（同じfixture、series SELECT1/開催回SELECT1/総SQL3）。raw出力`boost-series-after.json`は結果を保全し、コピー元probeの古いlimits説明だけをannotated側で訂正した。両方にsource/元証拠hashを残す。
- 変更service SHA-256: `7675a4b6b40be8ab3d3b1c45688c30f1bd590b762c06feb1d3c0ea9090b62067`。
- focused既存代表: 2 tests/61 assertions成功。after query probe: 1 test/4 assertions成功。
- Pint `--test`変更2files成功、Larastan正規level6で変更serviceのエラーなし、`git diff --check`成功。
- 未検証: PHP8.5.12、本番、HTTP実時間、大規模/同時負荷、全suite/CI。単回時間の改善率は主張しない。

静的解析の最初の試行は128MB不足で終了し、containerに正規configが見えないlevel0実行もあった。これらは検証のauthorityへ採用せず、source `phpstan.neon`をpathのみ絶対化した`phpstan-boost.neon`、level6、1GB指定の最終成功を採用した。

## 条件付き候補として継続: M1/M2 市場のquery columns

`market-query-column-proof.php`は実際の市場tableへmigrationせず、transaction内で作成してrollbackするTEMP tableだけを使う。96行中active83行、価格/ILの同順位、category4種、optional weapon_style、quality未設定/nullを持つ合成入力。現在browseのfilter/sort部分をIDs/order/has_moreに限定してSQLと比較した。

`market-query-column-proof.json`の9代表case（新着、price、IL、複合条件、購入IL上限、weapon_style、2ページ目、0件、範囲外ページ）は全て一致。現在型の83行読込に対し、SQLのpage_size+1取得は最大21行、絞込時10/7行、空pageは0行となった。

採用する結論は「同じsnapshotに由来するSQL検索列で現行の代表的な選択/順序/page判定を再現できる」。generated列の追加・index・市場runtime変更は今回のBoost PRに含めない。実データshape、旧snapshotの型/欠損、admission/blocked_reasons/残高/容量、購入・取消競合、統計的時間、plan/index効率、column追加のlock/rewrite/WALは未検証。9caseの同値だけで市場API全体の同値や本番改善を断言しない。

## 現時点で採らない案

- 最終Turnだけをcache keyにするranking/visibility: Turn外登録・放棄・売買・profile等を覆わず、現在意味を維持できない。
- audit/battleの単純time partition: 現global request unique/PK/FKと競合する。directory/core設計と移行/復旧の証拠が必要。
- 96tableの一括統合や、全receiptを同じ保持期間へ揃える変更: owner/単位/再送/保持/正当性が違う。現調査では速度利益を支持する実測根拠なし。

大規模設計比較は既存 `db-design-api.md` を参照する。実データ変更・本番/OCI/credential操作・長期試験は行っていない。

English: The Boost batch lookup is implemented and verified with focused checks. A separate temporary-table proof preserved market filtering, ordering, and pagination for nine synthetic cases while fetching at most 21 instead of 83 rows. It does not validate a production migration or the full market API. Broader cache, partitioning, and receipt-unification ideas remain conditional or rejected pending their correctness and operational evidence.

# 地底catalog構築の再利用（BU-01）

基準main: `2e93ec918774ad64d787aba99fc59a7bc3e54d16`（5.8.3）。本変更はfoundation / exploration / trialのJSON解読・合成・検証の繰り返しを減らす。定義、計算、抽選確率、RNG、資産・receipt・lock・DB schemaは変更しない。Ruleset更新は不要。

`UndergroundAlphaV1PlayerCatalog`をrequest/worker scopeで共有し、同一のsource bytes・依存catalog・grounds設定から作ったimmutableな`AlphaV1BuildCatalog`を再利用する。以前はencounter選択/取得のたびにfoundationを解読し、explorationの全定義を構築し直していた。検証は初回と入力変更後に実施する。

ファイルは引き続き毎回読み、bytesを厳密比較する。mtime/sizeだけのmemoではないため、同じ秒・同じサイズで書き換わったファイルも検出する。grounds設定変更、trial manifest/path変更、foundation変更で依存結果も再構築する。scopeが終わればcontainerはinstanceを破棄し、手動生成した長寿命instanceでも内容変更を検出する。設定の正本は既存configとmanifestのままで、無効なJSONを以前のcacheで隠さない。返されたmanifest配列を呼出元が変更しても共有catalogは変わらない。

## 隔離測定

PHP 8.5.8、実source/configを使用、application boot/DB/networkなし。各groupを新resolverで16回呼び、constructor/JSON/readを計数した単発のmicrobenchmark。全4groupで出力hashがbefore/after一致した。

| 16回の操作 | catalog構築 before→after | JSON解読 before→after | file read before→after | 単発時間 ms before→after |
|---|---:|---:|---:|---:|
| foundation | 16→1 | 16→1 | 16→16 | 10.52→2.99 |
| exploration | 32→2 | 16→1 | 16→16 | 12.25→4.29 |
| trial_01 | 32→2 | 32→2 | 32→32 | 24.73→17.07 |
| 通常encounter準備の4取得 | 128→2 | 64→1 | 64→64 | 269.14→236.22 |

計数instrumentとhash生成を含み、HTTP latencyや本番の速度保証ではない。file IO・各selectorの検証等は残る。結果が速かった割合を全戦闘・全requestへ外挿しない。測定script/raw JSON/比較は作業成果の `perf-implementation-20261031/battle/evidence/catalog-benchmark*` に保全した。

## 確認

- 既存authoring代表を拡張し、同instanceで設定を差し替えても反映されることを確認。temp manifestによる同mtime/同サイズ変更、trial変更、無効JSON、scope切替、値の非変異、同seed結果一致も確認: **7 tests / 39 assertions成功**。
- 既存の同seed pure combat、PT保存/貸出、試練3の編成再送、bulk skip二重決算防止のfocused代表: **5 tests / 98 assertions成功**。
- 変更3 PHP filesのPint、対象2 app filesのPHPStan level 6/Larastan、`git diff --check`成功。PHPStanは初回の既定128M不足を、明示1G・repo相当設定で解消した。

全suite/CI、本番PHP 8.5.12、HTTP時間・高並行lock測定は未実施。migrationはない。BU-02/03/04と低順位候補の採否は隣接する個別文書を参照し、別の性能変更を本差分に混ぜない。

## English summary

Reuse immutable foundation, exploration and trial catalogs within a request/worker scope. Actual file bytes and authored configuration remain the authority and invalidate cached results, including same-size, same-mtime edits. File reads, gameplay, probabilities, RNG, settlement and database schema stay unchanged. Four bounded benchmark groups produced identical output hashes; catalog construction counts fell from 16/32/32/128 to 1/2/2/2. Focused checks passed (12 tests, 137 assertions), plus Pint and PHPStan level 6. Timings are single-run local observations, not production latency guarantees.

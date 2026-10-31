# 秘書アイテムカタログの構築再利用

基準: main `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。2026-10-31。

`SecretaryItemCatalog::definitions()` が、定数から作る同じ全item配列をlookup・装備上限表示・効果検証のたびに再構築していた。基底メソッドの完成した配列だけをlazyに再利用する。最初の呼出で作り、返却値はPHPのcopy-on-writeにより呼出側から変更できる。`definitionWithResolvedEconomics()`の個体別rarity/価格も基底へ書き戻さない。

virtualな`definitions()`呼出は維持する。subclassの差し替えや、そのsubclassが返す値の変更を横取りしない。`categoryLimits()`自体や`validatedEffectCatalog()`の結果はcacheせず、Ruleset設定・所有item・効果・検証例外を従来どおり都度扱う。DB・RNG・lock・資産・Ruleset snapshot/世代は変更しない。第二のdefinition authorityは追加しない。

## 隔離測定

PHP 8.5.8 CLI、opcache CLI無効、同じprocessで各3回の中央値。固定SHAの旧クラスをuncached subclassへ改名して比較し、current v41の全42item・効果・resolved economicsの出力hash一致を確認した。時間は純PHPの局所測定であり、API/Turn全体やproduction PHP 8.5.12の改善率ではない。

| 処理 | 1回の測定内の呼出数 | 旧ms | 新ms |
|---|---:|---:|---:|
| definitions | 5,000 | 178.928 | 0.265 |
| definition lookup | 5,000 | 210.773 | 0.466 |
| categoryLimits | 1,000 | 872.400 | 28.004 |
| validatedEffectCatalog | 500 | 1,325.442 | 122.639 |

validatedEffectCatalogの検証自体は残し、下位catalogの再構築だけを除去して約10.8倍になった。同じ時間を別の効果cache候補へ重複計上しない。生の測定器とJSONは作業成果物 `perf-implementation-20261031/catalog/evidence/scripts/catalog-bench.php` / `catalog-bench.json` に保存した。

## 確認

- 既存Unit契約へ、返却配列/個体別economicsの変更隔離と、基底読取後の動的subclass差し替えの2caseを追加。既存のcurrent/next/historical Ruleset、不正設定拒否も含め19 tests / 64 assertions成功。
- 既存gameplayの装備ポリシーsubclass、倉庫API、合成、capacity、弓のrollbackとsame-seed retryの代表5caseを確認。最初の実行で倉庫API1caseは隔離環境のmanual索引欠落で失敗し、基準worktreeの索引を環境へ供給してその1caseを再実行（1 test / 14 assertions成功）。他4caseは初回成功。
- 変更PHPの構文、Pint、catalog単体のPHPStan level 6、git diff --check成功。全suite・CI・productionの計測は未実施。

## 今回採用しなかった範囲

- `validatedEffectCatalog()`の追加cacheは、同じRulesetモデルへの設定再代入・refresh・historical identity・DIを正しく扱う別の境界が必要。今回の小さい定数cacheの効果を先に分離し、追加しない。
- `SurfaceShipCatalog::definitions()`のmap表示内反復も確認したが、入力はRulesetごとに異なる。`options()`と船一覧は既に一部memo済みであり、定数cacheを流用できない。payload変更を含むinvalidation設計と船のあるchunkの追加profileを先に要するため、今回は変更しない。

## English summary

Reuse only the completed, constant-derived base Secretary item catalog. Virtual overrides, caller array isolation, per-instance economics, Ruleset validation, DB behavior and deterministic RNG remain unchanged. Three short PHP 8.5.8 runs reduced 500 validated-effect-catalog calls from a median 1,325.442 ms to 122.639 ms with identical full output hashes. Focused unit/gameplay checks passed; whole-turn speed, production PHP 8.5.12 and full CI remain unmeasured. Ruleset-dependent effect and ship-catalog caches are deferred.

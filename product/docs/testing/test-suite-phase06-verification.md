# Test suite再設計 Phase 6 最終確認記録

Date: 2026-09-15

Branch: `release/4.2.0`

Tested implementation: `49a632c2ca40ab10ae62182d8e9778c192a65b64`

Base: `origin/main` `00182bd0eaee52f34194c7b40bc5e98712108718`

## PHPUnit Full4

`HAKONIWA_TESTED_SHA=49a632c2ca40ab10ae62182d8e9778c192a65b64`を明示し、4 workersの`full` scopeを1回実行した。evidence tokenは`2eb4e712`、source tree fingerprintは`05c1398be9524e90fe4c9361dbb3f6c078dc2c73788d54ccc730215a7dfde2d5`。

| worker | files | tests | standard / reusable / individual秒 | wall秒 |
|---:|---:|---:|---:|---:|
| 1 | 27 | 273 | 645 / 202 / 262 | 1,109 |
| 2 | 35 | 260 | 510 / 97 / 404 | 1,012 |
| 3 | 26 | 245 | 359 / 464 / 359 | 1,182 |
| 4 | 33 | 256 | 661 / 126 / 258 | 1,045 |

- discovered 121 files、executed 1,034、unique 1,034。failure 0、error 0、skip 0。
- identifier hashは`b1b283697ee1e87c3948cb0850843c5d4520c531edb5528991e01c0d18a521d2`。事前列挙でもserial / assigned / unionはすべて1,034、duplicate / missing / unexpectedは0だった。
- 最長workerは1,182秒（19分42秒）、worker差は最大170秒。fixture wallの合計は4,348秒、JUnit case秒数の合計は約4,344.147秒だった。
- reusable surface mapは各worker1回、合計4回だけ生成した。生成時間は0.272298 / 0.300373 / 0.285654 / 0.285353秒、合計1.143678秒。
- 12 fixture processesを実行した。schema準備そのものの回数はevidenceで独立計測していないため、fixture process数をschema migration回数へ読み替えない。
- run終了後の`hakoniwa_parallel_%_test`は0件。

再設計前のpassing履歴は最長1,111秒（18分31秒）、今回の初回は71秒長かった。test集合・source・SHAが異なるため厳密な性能比較ではないが、初回Fullからrepository全体が速くなったとは判断しない。通常map生成を33回から1回へしたfocused代表は188秒から155秒へ短縮しており、局所改善は実測済み。今回の全121 files timingを使う次回planは予測case負荷を約1,086秒ずつへ均すが、次回実測までは見込みとして扱う。

## frontendとstatic

- Vitest `test:all`: 22 files / 201 tests PASS。
- ESLint: PASS。
- `vue-tsc --noEmit`: PASS。
- Vite production build: PASS、72 modules transformed。
- PHP tests 147 filesのPint: PASS。
- open-question documentation contract: 85 unique IDs、stale fixed future PR roadmaps 0でPASS。

Full PASS後、timing入力がfixture別JUnitとworker統合JUnitを二重加算する不具合を見つけた。統合JUnitだけを読む修正と既存planner caseのassertion追加は12 tests / 76 assertionsでPASSし、PHPUnitの対象集合・fixture・application runtimeは変更していない。新しいrepository-wide懸念がないためFullは再実行せず、上記1回を最終Full authorityとする。

## 追補: focused反復最適化

Date: 2026-09-16

Base HEADは`4f21a37c6dff5706af847d6a05ca02150298a894`。PHP 8.5.8、PHPUnit 12.5.32、同じdevelopment container、同じ`MessageBoardApiTest::test_guest_can_read_board_with_private_viewer_safe_cache_headers`（1 test / 10 assertions）を使った。比較中にtest本文とgameplay codeは変更していない。container内に`.git`がないためrun evidenceの`tested_sha`と`working_tree_dirty`は`unknown`であり、hostでHEADと今回のrunner/fixture差分を別確認した。

### 原因の切り分け

| 状態 / token | 対象選択 | DB準備 | migration | map生成 | test process | cleanup | cleanup込み総wall |
|---|---:|---:|---:|---:|---:|---:|---:|
| 修正前 `fe3aaf75` | 18.33秒 | 0.55秒 | 10.67秒 | 0.27秒 | 18秒（migration/mapを包含） | 0.11秒 | 39.13秒 |
| LPT履歴省略 `42a5d0ee` | 17.67秒 | 0.48秒 | 11.02秒 | 0.26秒 | 18秒（migration/mapを包含） | 0.11秒 | 38.47秒 |
| template初回 `f51ada01` | 1.34秒 | 14.19秒 | build 7.30秒 / clone 0秒 | build 0.21秒 / clone 0回 | 4.55秒 | 0.09秒 | 21.55秒 |
| cache hit `1816e380` | 1.55秒 | 1.59秒 | 0秒 | 0回 | 4.33秒 | 0.08秒 | 8.80秒 |

- LPT省略だけの差は0.67秒であり、旧`command_and_selection` 18.33秒すべてをLPT費用とは扱わない。その後、focused時のrepository全source hashを外し、PHPUnitのfile/filter列挙で選択集合を固定したことで対象選択は約1～2秒になった。
- 修正前内訳の残り約9.20秒は、旧evidenceではPHPUnit bootstrap、fixture/test処理、shell orchestrationを分離していないため未分類とする。cache hitの計測済み項目外は約1.25秒で、profile起動・artifact処理等のrunner orchestrationを含む。
- Windows `.cmd`入口を含む観測wallは修正前約46秒、template初回23.53秒、cache hit 10.93秒だった。比較authorityは同じrunner開始点で保存した39.13秒と8.80秒で、初回構築費用を通常反復の短縮へ混ぜていない。
- template初回build自体は12.04秒。clone側では初回からmigration 0秒、map生成0回で、環境変数だけでなくDB markerとmap cell数を再検証してから既存transaction/rollbackへ渡した。

### cache identityと安全確認

- fingerprint: `5381904dab52ac3bb345483b15addd8fac8e98685cecef332fad0f9f06a0b2a5`
- 入力159 files、入力一覧hash: `799d1f501e7895c71e2b3e46d5109d1003b26f1956e20b9d0348b310befd5ef4`
- 対象はmigration/schema、current Ruleset/config、catalog install/publish、Debug32x32 generator/coverage、fixture実装とその依存、Composer/PHPUnit設定、PHP/PostgreSQL version。fixture入力とruntime versionの変更でfingerprintが変わり、無関係なtest本文追加では変わらないことをunit testで確認した。
- 初回run DBは`hakoniwa_parallel_f51ada01_01_test`、2回目は`hakoniwa_parallel_1816e380_01_test`。終了後はいずれも0件で、build DBも0件。
- 残したverified templateは`hakoniwa_surface_fixture_5381904dab52ac3b_template`だけ。markerは完全fingerprint / `debug-32x32` / `shared-world` / 1,024 cells、実数も1 world / 1,024 cells / 0 nationsだった。2回のcase変更はtemplateへ漏れていない。
- 0件filterは「matched no test identifiers」でDB準備前に失敗し、全件へfallbackしなかった。
- standard / reusable_surface / individualを各1 file・1 identifier選ぶ混在focused `defb58be`はtemplateを使わず従来経路で3 tests / 37 assertions PASS。run DBは終了後0件。
- planner/selector/fingerprintは16 tests / 89 assertions、template DB名/manifest guardは1 test / 11 assertionsでPASS。変更PHP 11 filesのPintとPHP/shell構文確認もPASSした。

repository-wide Full、Surface全件、Underground全件、Full CIは実行していない。これはPhase 6のsuite-wide結果を更新する実行ではなく、通常開発向けfocused経路だけの追補である。

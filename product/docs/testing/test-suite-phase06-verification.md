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

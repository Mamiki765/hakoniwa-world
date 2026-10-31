# 地底CPU・表示の低順位候補

確認基準: `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。以下は未採用であり、疑いと実測を分ける。

| 候補 | 確認した根拠 | 採否と次の確認 |
|---|---|---|
| status modifier集約 | 調査probeのlab32戦で`BuildCombatState::stat()`72,349回。毎回status effectsを走査する | 保留。status付与/解除/stack/round-end/覚醒で値が変わる。状態versionとprofileによる寄与確認なしに戦闘全体cacheへしない |
| RNG入口検証の共有 | 同32戦で20,147 draw / 3,939 streams。既登録labelにもUTF-8/range検証を行う | 保留。HMAC/stat/AIに対するCPU比率未測定。rejection sampling、private seed、domain、再現性を省略しない。旧乱数列一致を不要な制約にも加えない |
| simple形式logのround grouping | `UndergroundBattleLog.vue`はroundごとに全simple actionsをfilterするためO(RA) | 保留。長い現行Alphaのstructured形式とは別経路。browser測定なしに全戦闘の律速としない |
| story JSON再解読 | `UndergroundTrialThreeStory::scene/firstClear`は小さいstory JSONを都度読む | 保留。呼出は少数、時間寄与未測定。catalogとの差分を広げてまで同時変更しない |
| encounter/drop config検証 | BU-01後も`explorationEncounters()`はentryごとに`explorationDropConfig()`等の検証へ進む | 静的反復は確認、時間の内訳は未profile。通常準備benchに時間が残っただけで原因確定とせず、入力変更検出と一緒に別調査する |

借用numeric/slot projection cache、bulk skipのvault count集約、永久/期限付きbattle snapshot分離、5.8.2のRuleset JSONモデル内再利用は既改善である。第二の定義catalogや永久request registryを追加する案へ読み替えない。DB/transaction再設計は別調査成果`perf-audit-20261031/db-design-battle.md`に比較を残し、本変更で採用していない。

## English summary

Status aggregation, RNG validation, simple-log grouping, story reads and repeated encounter/drop validation remain lower-priority leads. Static repetition or call counts do not establish their share of latency. Keep mutable-state invalidation, unbiased randomness, versioned definitions and existing retention boundaries intact; no additional implementation is included.

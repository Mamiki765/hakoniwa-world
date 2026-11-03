# 貸出報酬の二段SELECT候補（BU-04）

確認基準: `2e93ec918774ad64d787aba99fc59a7bc3e54d16`。**重複SELECTとunique条件は確認済み。本PRでは未採用。**

`UndergroundLendingRewardService::settle()`はinsertOrIgnore後、券残高をuser_idで非lock取得→得たidでFOR UPDATE再取得し、日次rowもowner/dayで非lock取得→idで再lockする。schemaにはUNIQUE(user_id)とUNIQUE(owner_user_id,canonical_day)がある。自然unique条件で直接FOR UPDATEすれば4→2 SELECT/借用人、最大3人で6 SELECTの削減候補になる。

owner順、券残高→日次のlock順、日跨ぎ累計余り/日次上限、participation unique、ledger/auditを維持する。追加照合で、券/日次lockは戦闘CPUの後であり、現在はCPUの全時間をlender残高lockが占有しているわけではないことも確認した。

SQL実測と既存PG concurrency代表は未実施。この候補はmutable balanceのlock取得を扱うので、immutable catalog再利用とは別差分にする。既存報酬代表へquery計数を限定して接続し、再送/日次境界/同owner競合を確認してから採否を判断する。

## English summary

Two read-then-lock pairs can potentially become direct unique-key locking reads, reducing four SELECTs to two per borrowed member. Existing uniqueness and lock order were checked statically. Database query counts and concurrency behavior still need focused validation; deferred from catalog reuse.

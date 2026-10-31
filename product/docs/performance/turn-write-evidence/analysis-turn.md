# Turn の保存境界・#340 再調査（2026-10-31）

**結論：成長の二重 UPDATE と成長行のまとめ保存は、既存 PR #340 の採用候補として扱う。重複する新 PR は作らない。現在の gameplay・監査経路を壊す具体的な反例は、この静的確認では見つからなかった。一方、`updated_at`・任意の Eloquent observer・全ての途中 DB 状態まで完全一致する変更ではない。保証対象を明示して現在 main と既存 6 差分に対する限定検証へ進める価値がある。**

今回は静的調査のみ。製品ソース、DB、branch、PR を変更していない。runtime・CI・長期試験も起動していない。archive、production、認証情報は参照していない。以下の過去測定は履歴、今回の current-main 測定は既存の隔離成果、未実施試験は計画として区別する。

## 固定対象・根拠

- 現行 main：`2e93ec918774ad64d787aba99fc59a7bc3e54d16`（以下 M）。`/workspace/hakoniwa-world` の HEAD と clean status を再確認した。
- PR #340：`8509bd5c3c6666db580c6fda2a9c7ce986316642`（以下 W）。現在 main との merge-base は `12a4f242028e4d0bd8b8db118097762c4214b264`。残差は 4 ファイルで、Ruleset JSON memo は既に main にある。
- 二重 UPDATE 解消の既存 commit：`5f9d67d2f14fe1a6c23f7e1b94371184bd06b700`。Deferred writer：`ec963ef3374b2858b93b14983f682d1dbb6697eb`。小さい前者を別 PR に複製する根拠もない。採用単位を分けるなら既存作業の分割として扱う。
- W の関連保存実装と比較した M の `CompleteTurnEngine`、`MapCellStateService`、`MapCell`、`TurnEventRecorder`、schema の契約は、今回調査した差分に関して W から変わっていない。
- Laravel：M の `product/composer.lock:1127–1137` に固定された framework `v13.22.0` / `d354afa45334cdac523fbc015f203a78d7e2f5c6` の公式ソースで、モデル保存と SQL hook の順序を確認した。測定環境を新たに起動するための取得ではない。
- `AGENTS.md`、`product/docs/handoffs/current-status.md`、`docs/README.md`、`current-contracts.md`、関係する `docs/open-questions.md`、scheduler runbook を再確認した。repo・workspace に今回適用すべき `.agents/skills/*/SKILL.md` は見つからなかった。

固定リンク：

| 根拠 | 固定版の箇所 |
|---|---|
| 現在の成長・段階保存 | [M CompleteTurnEngine:1832–1880](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/CompleteTurnEngine.php#L1832-L1880) |
| W の成長・段階保存 | [W CompleteTurnEngine:1847–1901](https://github.com/Mamiki765/hakoniwa-world/blob/8509bd5c3c6666db580c6fda2a9c7ce986316642/product/app/Application/CompleteTurnEngine.php#L1847-L1901) |
| writer 本体 | [W DeferredMapCellWriter:18–135](https://github.com/Mamiki765/hakoniwa-world/blob/8509bd5c3c6666db580c6fda2a9c7ce986316642/product/app/Application/DeferredMapCellWriter.php#L18-L135) |
| phase の開始・flush・破棄 | [W CompleteTurnEngine:530–540](https://github.com/Mamiki765/hakoniwa-world/blob/8509bd5c3c6666db580c6fda2a9c7ce986316642/product/app/Application/CompleteTurnEngine.php#L530-L540) |
| 監査の INSERT と subject/metadata | [M TurnEventRecorder:14–32,70–108](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Application/TurnEventRecorder.php#L14-L108) |
| モデルの現在の契約 | [M MapCell:42–105](https://github.com/Mamiki765/hakoniwa-world/blob/2e93ec918774ad64d787aba99fc59a7bc3e54d16/product/app/Models/MapCell.php#L42-L105) |
| SQL hook は実行前だが dirty 確定後 | [framework Model:1494–1523](https://github.com/laravel/framework/blob/d354afa45334cdac523fbc015f203a78d7e2f5c6/src/Illuminate/Database/Eloquent/Model.php#L1494-L1523)、[Connection:799–827](https://github.com/laravel/framework/blob/d354afa45334cdac523fbc015f203a78d7e2f5c6/src/Illuminate/Database/Connection.php#L799-L827) |

## 過去に不採用だった、とは確認できない

5.8.2 は #340 の JSON memo だけを切り出した release であり、「残りが危険なので却下された」という記録ではない。#338 は interrupted Turn の手動回復という別件である。5.8.2 文書の #338 非包含を、writer 不採用理由に転用しない。

確認した非 archive の履歴は `/workspace/peridot-notes/lapis/2026-10-23-turn-runtime.md` と #340 の全コメント。note は `independentOfPendingCells` の trigger 前提の確認を求めている。次の固定レビューと計測が既にある。

- [重複実装をしないという当時の分担](https://github.com/Mamiki765/hakoniwa-world/pull/340#issuecomment-6353603100)。
- [W 固定の Codex review completed / findings=false](https://github.com/Mamiki765/hakoniwa-world/pull/340#issuecomment-6353736110)。これは現在 main との統合結果の保証や merge 許可ではない。
- [W 固定の独立確認・4 fixture 測定](https://github.com/Mamiki765/hakoniwa-world/pull/340#issuecomment-6353889630)。過去 base `12a4…` と W、同じ開始 rows・zero seed・固定時計、rollback と sequence reset、全 96 public table の内容比較。除外は fixture の remember_token と phase 計測時間のみだった、という実施記録がある。今回はその過去 snapshot 自体を再解析してはいない。

| 過去 fixture | base wall | W wall | SQL base → W |
|---|---:|---:|---:|
| 60×60 mature | 4.581s | 3.891s | 3355 → 2455 |
| 60×60 growth（各 2 回） | 4.144–4.180s | 2.033–2.391s | 3138 → 744 |
| 32×32 protection / forest | 2.267s | 0.616s | 696 → 186 |
| 60×60 mixed | 2.768s | 2.226s | 2344 → 548 |

過去環境は PHP8.5.12 / Laravel13.22.0 / PG18.4 / Excimer1ms / Linux x86_64 Docker / 内部 network / DB tmpfs / CLI OPcache off。現在の PHP8.5.8 の値との差を改善倍率にはしない。未 merge の理由は今回読んだ証拠からは確定できず、拒否・不具合を推測しない。

## 現在も削減対象が残る根拠

[current mature 測定](../audit-2026-10-31/turn-current-mature.json) は M / PHP8.5.8 / PG18.4、既存 synthetic mature fixture の隔離測定で、全 Turn **3977.031ms / 3355 SQL**、`process_cells` **3096.320ms / 3143 SQL**。内訳は **1113 INSERT / 18 SELECT / 2012 UPDATE**。同じ phase に施設・人口・version の UPDATE が 900 本と、その直後の version のみの UPDATE が 900 本ある。`stage_transitions=900`、森成長 0、新村 207、実火災 4。これは W 適用後の新測定ではない。

したがって成熟 fixture で二重 UPDATE を 900 本除ける見込みは、過去の推測だけでなく現在の query shape にも裏付けがある。**3355 → 2455 は現在 main については静的な予測であり、新しい実測値としては書かない。** 森成長や段階維持の人口成長が多い fixture は別途 writer の効果を測る必要がある。残る 1113 audit INSERT の集約境界は別担当の調査対象である。

既存 6 差分の統合測定は [integration report](../integration-evidence/turn/report.md) にあり、mature の 3355 SQL は変わっていなかった。その測定に #340 は含まれず、#340 と既存 6 差分の結合再検証の代用にはならない。

## 成長と段階変更の局所統合

M は人口を変えた後、`syncSettlementStage()` が段階変更時に `version++ → save → stage event`、その後成長側でさらに `version++ → save` を実行する。W は成長分の `version++` を先に行い、段階変更があれば段階側の 1 save にまとめる。乱数・人口計算・stage selector・施設状態の変更処理は同じである。

| 到達する状態 | M の最終 version 増分 | W の最終増分 | 保存の差 |
|---|---:|---:|---|
| 成長 > 0、段階変更あり | +2 | +2 | Eloquent 2 回 → 1 回 |
| 成長 > 0、段階変更なし | +1 | +1 | 1 回 → 成長 writer への保留 |
| 成長 0、段階補正あり | +1 | +1 | 同じ段階側の 1 回 |
| 成長 0、段階補正なし | 0 | 0 | 保存なし |
| capital / 固定 identity の成長 | +1 | +1 | stage は従来どおり bypass、成長のみ保留 |
| 森の実成長 | +1 | +1 | quantity の計算と増分を保持して保留 |

段階イベントの metadata は nation、成長後人口、from/to facility key であり、version や updated_at を含まない。recorder は subject の morph class と primary key を読むだけで MapCell を再取得しない。INSERT の件数・呼出し位置・順序は変わらず、map_cells UPDATE は sequence を消費しないため、**現在の stage event の ID 順・subject・metadata を変える静的要因はない**。audit 側に MapCell を読む trigger もない。直後の通常コードが観測する final version は両版 +2。

ただし段階 event の INSERT 時点で、M の DB version は +1、W は +2。これは「全ての途中状態まで同じ」という説明には反する。現在の audit INSERT はその差を読まないので具体的な gameplay regression ではない。将来 audit trigger や observer が version を読むなら前提の再監査が必要になる。

`applyFamine()` は保存の後に famine / population-decreased イベントを記録してから段階補正する（M `CompleteTurnEngine:1633–1672`）。高人口の減少や undersea-city 崩壊にも原因イベントと別の遷移がある。これらへ機械的に「同じセルだから 1 保存」を拡張する根拠はない。W が成長だけに限定していることを維持する。

## Deferred writer の安全境界

W は process_cells の間だけ有効にし、pending は ID → **共有している生の MapCell object**。最後に flush、成功・例外とも finally で end。対象は population / terrain_quantity / version のみ、最大 500 行の `UPDATE ... FROM VALUES`。他列が dirty なら通常の `save()` に戻す。

| 境界 | 現在コードを踏まえた判断 | 限界・反証になる変更 |
|---|---|---|
| phase 終端 | return 前に flush。後続 phase の DB 集計・read は未保存行を見ない | phase を跨ぐ buffer、別 transaction、flush 無し return は不可 |
| SELECT / lock / refresh | default Laravel connection の beforeExecuting が pending を先に flush。missile の cell lock（M MissileImpactResolver:120）と refresh（M CompleteTurnEngine:635–657）も対象 | 直接 PDO、別 connection、hook を通らない外部 reader を追加するならこの説明は成立しない。確認した active 経路には該当なし |
| audit INSERT | 現在は MapCell を読まないため保留を維持できる。順序を変えず同じ root transaction 内 | 新 trigger / INSERT 式 / callback がセルを読むなら allowlist は再検討。全 INSERT を許す拡張は不可 |
| pending でない別 1 cell の通常 UPDATE | 現 trigger は OLD.id の ships と NEW の map-space/terrain/facility definition だけを読む。pending の人口や木は読まない | 全 map_cells UPDATE を無条件に許す、複数行 UPDATE / subquery を許す、trigger が他セルを参照する拡張は不可 |
| pending 自身への UPDATE | ID が pending にあれば flush する。後述の同 object reentrance に注意 | 「同じセルも独立だから skip」への緩和は不可 |
| facility/owner/terrain 等の変更 | dirty 列を検出して通常 save に戻す。現在の ship guard を維持 | 3 列以外を raw batch へ広げるなら別の証明が必要 |
| 同一 attempt 内の状態 | shared object を参照する neighbor/target 判定は成長後の値を見る。追加 RNG はない | snapshot コピーに交換し、後続 mutation を上書きする writer は不可 |
| 失敗・限定 retry | root transaction rollback と finally end により既 flush / 未 flush とも失敗 attempt の game state を残さない。次 attempt は新 TurnState/RNG | phase 単位 commit、catch して部分継続、同じ pending object を retry に持越すのは不可 |
| SQL 型・上限 | population/version/quantity を bigint に cast、quantity null 可。500 行は ID 含め 2001 bindings | 全世界を 1 文にする必要はない。batch 拡大で parameter/memory/巨大 statement の利点を前提にしない |

trigger 根拠は M `product/database/schema/pgsql-schema.sql:392–435,6612`。対象 trigger は `UPDATE OF map_space_id, terrain_definition_id, facility_definition_id` なので 3 列 batch 自体では発火せず、段階施設変更の通常 save は従来どおり発火する。`MapCell` は fillable/casts/relations のみで、app の provider / event 登録に MapCell の saving/updating/updated/saved observer は見つからなかった。

atomicity 根拠は M `TurnRunner:244–264,311–347,349–375`。同一 root transaction、同一 write PDO / World lock、限定 SQLSTATE の完全 rollback 後 retry、新 TurnState と新 RNG を保持する。W はこの契約を変更しない。DB が既に削除された行を対象にする affected-row-count 不一致は raw writer に検出処理がないが、今回の locked surface cell / World lock から到達する削除競合は示せていない。unsupported な DB 直書きだけを障害 finding にしない。

## 同じ pending object への後続変更

具体的な追加確認境界は 2 つある。これは既存 W の重大障害を発見した、という主張ではない。

1. **動けない NPC pirate が、先に成長済みの settlement を襲う。** M `SurfaceShipTurnService:180–213` は destination 無し・course 変更無し・NPC の条件で SQL を挟まず pirateAttack に入れる。`391–448` は共有 coordinate map の cell を選び、人口を半減、facility を補正、version を増やして `save()`。pending の同じ cell object が save 前に変わり得る。
2. **弓の効果が無い通常怪獣 pass が成長済みセルを踏む。** M `SecretaryBowAttackService:81–83` は効果無しなら query を発行せず return できる。M `CompleteTurnEngine:753–770` の通常 monster pass は同じ cell map を使い、M `MonsterTurnService:380–403` は destination を変更して save する。terrain resolver の初回 query が毎回 barrier になるとは限らない。

Laravel の save は saving/updating イベント、timestamp 設定、dirty 値の確定を済ませてから SQL の `beforeExecuting` へ到達する。したがってそこで writer.flush が呼ばれると、pending object は既に次の mutation と場合によっては updated_at を持っている。非対象 dirty があれば flush 内から同じ model の save が再入する。`flushing` guard と pending を先に空にする処理により無限再帰は回避する。outer SQL は flush 前に確定した値を再度書き得る。

現 model に observer / setter side effect がなく、同じ dirty 値が保存されるため、ここまでから人口・version・asset が破壊される反例は導けていない。しかし **単純な read-before-flush テストだけでこの境界を検証済みとは言えない**。fixture の到達を保証して、最終 row と in-memory model、metadata、SQL 本数、trigger に対する副作用を比較する価値がある。とりわけ非 batch 列への fallback を通す代表を選ぶ。新汎用 framework や全 gameplay 分岐の組合せ列挙は不要。

## updated_at と observer を「保持」のどこに含めるか

W は flush の `freshTimestamp()` を batch 全行へ設定する（writer:107–123）。M の 1 件ごとの保存時刻と同じ保証ではない。段階遷移の局所統合も 2 回目の save 時刻を 1 回目へ移す。MapCell の timestamp は schema `timestamp(0)`（M schema:1184–1185）で、秒境界を跨ぐ Turn では差が現れ得る。

`updated_at` は gameplay 計算には使用されていないが、**owner の MapCell API は公開している**（M `MapCellPresenter:159–161`）。そのため「永続化される全値が無条件で同じ」とは書かない。現 PR の body は共通 flush 時刻になることを明記済み。時計固定の過去 96 表一致は、非固定時計の時刻の割当てまで同じことを証明しない。

また raw batch は Eloquent saving/updating/updated/saved、touchOwners、syncChanges を実行せず、syncOriginal のみを実行する。現在 MapCell に該当 observer / touches / change-inspection consumer は見つからないので、それだけで current production の P1/P2 とはしない。2 save → 1 save で任意 observer の通知回数まで完全保存することはできない。

親が strict に保持すべき契約を「現在 gameplay、version、audit 内容・順、root atomicity、同版 seed 再現」と置くなら、現 W を候補に残せる。時刻を各 logical save に対応させたい場合、少なくとも writer 側は defer 時に row ごとの timestamp を捕捉して VALUES に含める代案があるが、既存 W の修正として扱うべきで、timestamp 自体の数値を高速化前と完全一致させる保証ではない。任意 observer や全途中 DB 状態まで含む要求なら今回の coalescing の保証範囲と衝突し、条件を曖昧にしたまま採用しない。

connection callback は writer.begin ごとではなく connection ごとに登録する WeakMap guard がある。複数 writer を同じ connection に延々生成する常駐処理なら callback の寿命を再検討する必要があるが、現 scheduler は cron の単発 artisan であり常駐 worker を提供しない（M runbook:3,23）。現在運用の性能 blocker としては報告しない。

## 次の限定検証案（今回は未実行）

親が用意する同 locked dependency / dedicated DB / current-main+W の disposable tree で行う。既存 6 差分との結合版も source manifest を固定し、同時測定や別 fixture 同士の速度比較を避ける。

1. **正確性を先に確認。** mature、growth、実火災を通す mixed の既存 synthetic fixture を使う。開始 state、seed、時計、IDs/sequences を揃え、gameplay の全 row（version/資産/owner/quantity 含む）、audit ID 順・subject・metadata・draw fields、phase metrics を比較する。認証テーブル・秘密列は取得しない。時刻は除外列を列名で明記し、gameplay 比較とは別に観測する。
2. **増やす代表は具体的な不足だけ。** blocked pirate→pending 成長セル、no-bow monster→pending セルのうち fallback と同セル save を保証する代表を選ぶ。既存 fire/monster/ship test の拡張を優先する。timestamp について必要なら境界時刻を制御し、仕様上の扱いを assertion する。任意 observer の架空 regression test は足さない。
3. **失敗・retry。** 複数 batch を通る growth fixture で、既 flush / 未 flush が共存する地点の body failure を一度注入し、root rollback 後に cell、resources、audit、world.current_turn が残らないことを確認する。現 C1 代表を使い、同 run/seed/ruleset の rollback 後 attempt と fresh successful attempt の gameplay 内容を比較する。manual retry、production retry、接続断の自動再接続を試さない。
4. **測定。** correctness 後に同 fixture の main / current-main+W / six+W を対にし、親が決めた少数回だけ測る。wall / PHP CPU / SQL by phase/type / save count / batches・fallback / peak memory を報告し、各 run の値を残す。mature の局所統合と growth のまとめ保存を分けて説明する。固定時計は数値比較用、非固定の updated_at 観測は別 run とし性能値へ混ぜない。
5. **独立 review と final exact-head CI。** source 差分がない古い W review は証拠として活用しつつ、現在 main / 6 差分との新結合・追加テストについて必要な範囲を review する。local full suite や高コスト CI の反復はしない。製品の採否、PR の更新主体、merge は親が保持する。

現 W の専用 test は read-before-flush と end-discard の 2 例（W `DeferredMapCellWriterTest:19–61`）。growth performance test は 3600 セルで forest >1000 と UPDATE 上限を確認する（W `TurnRuntimePerformanceTest:139–154`）。これらと既存履歴は有用だが、同一 object へ後続 save、非固定 timestamp、current-main+six の結合を今回新たに実証したわけではない。

## 採用・棄却の整理

| 案 | 今回の扱い | 理由 |
|---|---|---|
| #340 の成長二重 UPDATE 解消 | 既存候補へ集約 | 現 main の 900 本を直接説明でき、final version/event は現経路で保持 |
| #340 の限定成長 writer | 既存候補へ集約、限定再検証へ | 成長の多い fixture の過去効果と static boundary がある。現 trigger / timestamp / 同 object 境界を明記 |
| 同実装の新 PR | 棄却 | 重複し、独立した価値がない |
| 「小さくする」ため二重 UPDATE 部分だけ複製 | 棄却 | 既存 commit が分離済み。必要なら #340 の採用単位として判断する |
| famine / 全 cell mutation を無条件 coalesce | 棄却 | audit・途中 read・trigger・version の異なる境界を跨ぐ |
| 全 phase / 全 SQL を最後にまとめる | 棄却 | current sequential causality / DB observer / root boundary の前提を失う |
| phase 別 commit・World lock 緩和・並列 cell 更新 | 棄却 | A-07 と retry/atomicity および phase 内因果が変わる |
| audit INSERT の限定 typed buffer | 別の候補 | 同一実装ではない。別担当が audit read / ID 順 / direct INSERT を反証中。本書では採用しない |
| 新しい MapCell UoW / chunk JSONB / schema 再設計 | 今は保留 | 既存 [DB design report](../audit-2026-10-31/db-design-turn.md) の比較対象。#340 の既存価値を取り込む前に別基盤を追加する根拠はない |

未実施：今回の source 調査に伴う新 runtime、非固定時計の比較、same-version の full-turn replay、current-main+W / six+W の fresh 測定、独立再レビュー、最新 CI。ブロック・拒否された操作はない。実装判断待ちという作業境界であり、未実施試験の成功を仮定しない。

## English summary

Keep the two remaining optimizations in existing PR #340 (`8509bd5c3c6666db580c6fda2a9c7ce986316642`); do not duplicate them in a new PR. Static inspection found no concrete regression in current gameplay, final cell versions, audit metadata/order, RNG use, or root-transaction retry semantics. Current-main evidence still contains 900 redundant version-only UPDATEs in the mature fixture, directly supporting the existing change.

The guarantee is narrower than exact equivalence of every intermediate database state, timestamp, or arbitrary model observer. Stage-transition audit INSERTs observe a different intermediate version if a future observer reads it. Batched rows receive one flush-time `updated_at`, which the owner cell API exposes. Raw batches bypass Eloquent save events; no current MapCell observer or consumer of change-tracking state was found. Existing frozen-clock equality results do not prove unfrozen timestamp equivalence.

Reachable follow-up boundaries are a blocked NPC pirate attacking a previously grown pending cell, and an ordinary monster mutating a pending cell when no bow query forces an earlier flush. The SQL hook runs after Eloquent has captured dirty values, so the same live model can enter fallback save during the hook. This is a focused validation target, not a demonstrated gameplay corruption finding. Fresh paired measurements, rollback/replay checks, current-main/six-patch integration, independent review and final exact-head CI remain for the parent. This task changed no product source and ran no new runtime or CI.

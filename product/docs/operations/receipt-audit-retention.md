# Receipt・戦闘snapshot・routine auditの保持整理

2026-10-19、5.6.0候補のcommand/serviceと照合したrunbook。コードが提供する操作と、本番での登録・実行実績は別であり、今回productionへの照会・整理・cron登録は行っていない。以下は自動実行の指示ではなく、operatorが承認された対象・backup・cutoffを確認して使う手順である。

## 対象ごとの境界

| 対象 | Command | 既定の動作 | `--apply`の効果 |
|---|---|---|---|
| 期限切れ詳細action log・image参照 | `underground:prune-battle-logs` | 期限切れlog・image参照を削除 | このcommandに`--apply`はない。[既存cron手順](../../../docs/operations/turn-cron.md#daily-underground-battle-log-prune)を参照 |
| 完了battle/member snapshot | `underground:compact-battle-history` | read-only preview | 期限切れdetailのない完了battleを縮約し、必要な旧統計を救済。receipt本体は削除しない |
| 生涯集計・検証境界 | `hakoniwa:underground:receipts:aggregate` | 書込み・削除なしのpreview | totals・statistics・checkpointを検証保存。receiptは削除せず、報酬も再付与しない |
| 古いreceipt | `hakoniwa:underground:receipts:purge` | read-only preview | 検証済み・永続化済み・pinのない連続prefixを削除 |
| 限定routine audit | `audit:compact-routine-events` | read-only preview | summary/帰属不明集約を保存し、対応するallowlistの元行だけを削除 |
| 保持中battleの統計報告 | `underground:statistics` | read-only report | このcommandに`--apply`はない。削除済み期間を含む生涯統計ではない |

command名の存在を本番稼働の証拠にしない。詳細logの1時間保持、receiptの30日境界、auditの明示cutoffは別の契約である。全tableの一括削除や、request identity・失敗履歴の一般的な期限削除へ拡張しない。

## Receipt: 準備 → 集約 → purge

`--profile`は既存profile ID、`--stream`は`battle|skip|bulk_skip|intro_request|all`。aggregate/purgeとも`--batch`はprofile × streamごとに1〜1,000（既定500）。`--before`はtimezone付きの固定日時を用い、実行時点から少なくとも30日前とする。未指定時のcutoffはその実行の30日前なので、比較するpreview/applyでは明示値を揃える。完了時刻がcutoffと同じreceiptも保持し、cutoffより古いものだけが候補になる。

1. 対象・backup・cutoff・batchを記録する。`PROFILE_ID`と`CUTOFF`は実値へ置換する。まず両commandのpreviewで`from_id`、`candidate_through_id`、`verified_through_id`、`stop_reason`を確認する。

   ```sh
   php artisan hakoniwa:underground:receipts:aggregate --profile=PROFILE_ID --stream=all --before=CUTOFF --batch=100
   php artisan hakoniwa:underground:receipts:purge --profile=PROFILE_ID --stream=all --before=CUTOFF --batch=100
   ```

2. battleが`legacy_statistics_pending`なら、先にsnapshot compactionのpreviewを確認し、承認された範囲だけapplyする。これはreceiptの集約とは別操作であり、**このcommandにはprofile/World絞込みoptionがない**。候補は接続先DB全体から選ぶため、receipt用`--profile`と同じ範囲に限定されると判断しない。

   ```sh
   php artisan underground:compact-battle-history --cutoff=CUTOFF --limit=100 --batch=100 --max-seconds=30
   # 上の対象と内容を確認し、変更承認後のみ
   php artisan underground:compact-battle-history --cutoff=CUTOFF --limit=100 --batch=100 --max-seconds=30 --apply
   ```

   `--cutoff`は必須のinclusive finished-at上限。`--limit`は1〜100,000、`--batch`は1〜1,000、`--max-seconds`は1〜3,600のsoft予算で、battle間に検査する。未完battle・現在有効な詳細logは対象外。applyはbattle row、party member rowsをlockし、同じtransactionで統計と縮約snapshotを保存する。旧統計の不明な内訳はnullのまま保持し、再戦・報酬再付与はしない。

3. aggregate previewを再確認し、承認後に同じ指定へ`--apply`を加える。

   ```sh
   php artisan hakoniwa:underground:receipts:aggregate --profile=PROFILE_ID --stream=all --before=CUTOFF --batch=100 --apply
   ```

   receipt書込みと同じprofileの排他lockを取り、ID順の連続prefixだけを検証する。previewもprofile lockを取るがcheckpointは作らない。applyはraw集計・保存値・境界のread-backが一致したtransactionだけを確定する。未完・保持期間内・未分類・未準備の先頭を飛び越さない。

   `statistics_upgrade_required`は旧battle aggregation version 1の停止理由。現在battle scopeはversion 2、他streamはversion 1であり、snapshot compactionだけでは旧checkpointを更新できない。現存raw全量と旧値を検証する内部serviceの明示rebuild経路はあるが、専用CLI/API・自動backfillはない。[管理ページの既存境界](4.4.0-admin-operations.md#古いログ)に従い、削除済み・不一致・証明不能なら停止する。SQLでversionだけ変更しない。aggregateは停止したprofile/streamをJSONで報告し、独立した他stream/profileを継続するが、未準備等の停止があれば最終終了コードは非ゼロ。予期しない例外は全体を停止する。

4. purge previewを再取得し、対象・停止理由を照合してから承認範囲だけapplyする。

   ```sh
   php artisan hakoniwa:underground:receipts:purge --profile=PROFILE_ID --stream=all --before=CUTOFF --batch=100
   # 上の対象と内容を確認し、削除承認後のみ
   php artisan hakoniwa:underground:receipts:purge --profile=PROFILE_ID --stream=all --before=CUTOFF --batch=100 --apply
   ```

   purge previewはprofile共有lock、applyは排他lockを使い、検証済みIDまでの連続prefixだけを削除する。未集約・生涯統計未保存・旧scope・進行中試練/導入story・未保存の初回challenge/story/異界初clear事実・有効log/image pinで停止する。battleに対応する貸出参加・image参照・party rowsも同じtransactionで整理するが、既存の恒久統計・初回事実・資産を再計算しない。削除件数とcheckpointのread-back不一致はrollbackする。再実行は確定済み境界の次から進み、過去集計を重ねない。

   `batch_limit`は今回の上限、`no_more_receipts`は候補末尾、`retention_window`は保持期間への到達。他の`stop_reason`は原因を確認する。purgeのpreviewはblockerがあっても成功終了できるため、exit codeだけで削除可能と判断しない。apply時にblockerがあれば非ゼロ。自動的にbatch拡大・全件loop・guard緩和を行わない。

実行記録には対象HEAD、環境、固定cutoff、profile/stream、範囲、停止理由、件数、終了コードを残す。部分完了時も確定済みprefixと未処理を分ける。これらのCLIにproduction環境の一律拒否やWorld Turn guardがあると仮定せず、operatorが対象環境と変更承認を確認する。

## Routine audit compaction

30日保持を自動適用するcommandではない。profile/World絞込みoptionはなく、接続先DB全体の候補を扱う。必須`--cutoff`はinclusive occurred-at上限で、対応する完了済み・非dry TurnRunの`completed_at`もcutoff以前であることをserviceが確認する。`--limit`はsummary/帰属不明TurnRun group数（1〜100,000、既定1,000）、`--max-seconds`はgroup間に確認するsoft予算（1〜3,600、既定30）。

```sh
php artisan audit:compact-routine-events --cutoff=CUTOFF --limit=100 --max-seconds=30
# previewのby_type・group・行数と対象を確認し、削除承認後のみ
php artisan audit:compact-routine-events --cutoff=CUTOFF --limit=100 --max-seconds=30 --apply
```

対象は`fire.prevented`、非負の数値incrementを持つ`forest.grown`、非負の数値increaseを持つ`population.increased`、requested/sold/revenueが全て数値0の`resource.automatic_sale`に限定する。同じWorld・Nation・Turn・TurnRun identityで集約し、summary rowをlockしたtransactionで集約と正確な元行削除を確定する。削除件数が一致しなければrollbackする。

旧forestのNation帰属が不明ならNationへ推定配賦せず、TurnRun row lockの下で`turn.routine_legacy_unattributed`へ保存する。summaryは帰属不完全を示し、既存集約と未削除rawが矛盾すれば停止する。管理操作・経済取引などallowlist外auditの一般的な削除や、自動cron登録を意味しない。

## 保持中の統計報告と正本

```sh
php artisan underground:statistics --from=FROM --to=TO --activity=exploration --activity=trial --max-rows=10000
```

期間は両端inclusive、activity未指定時はexploration/trial。`--max-rows`超過では部分reportを出さず失敗する。出力の`receipt_scope=retained_receipts`とknown/unknown/not-recordedを残し、削除済み期間を0件・0damageで補わない。このreportと、raw削除後も残るreceipt rollupの生涯集計を混同しない。

実装正本: [commands](../../app/Console/Commands)、[rollup](../../app/Application/Underground/UndergroundReceiptRollupService.php)、[purge](../../app/Application/Underground/UndergroundReceiptPurgeService.php)、[battle compactor](../../app/Application/Underground/UndergroundBattleHistoryCompactor.php)、[routine audit compactor](../../app/Application/RoutineAuditCompactor.php)。導入当時のD1/D4判断は`docs/archive/through-4.4.0/product/docs/releases/4.4.0-receipt-rollup-d1.md`と`4.4.0-receipt-durable-dependencies.md`に履歴として残るが、現在のCLI flagsや保護条件は上記コードで確認する。

# ver 1.4.0 release checklist

## Scope

この手順は、review済み`release/1.4.0`をproductionへdeployするときだけoperatorが実行する。`release/1.4.0`の`main` mergeはdeploy、database migration、cron変更、announcement公開を含まない。

公開済み`hakoniwa-2s-plus-v1`と`hakoniwa-2s-plus-v2`はimmutable audit recordとして保持する。ver 1.4.0は新しい`hakoniwa-2s-plus-v3`をpublishし、`shared-world`とlive referenceをforward migrationでv2からv3へ移す。World、Nation、cell、command queue、TurnRun、eventをresetしない。

## Repository release gate

- mobile workspace PRを`release/1.4.0`へ先にmergeする。
- final release SHA、`release/1.4.0`をbaseにした各PRのhead SHA、CI成功、review完了、未解決thread 0を記録する。
- worktreeがcleanで、`_references/`に差分がなく、意図した`product/`とdocumentationだけがrelease差分であることを確認する。
- ruleset authoring validatorを実行し、v1 SHA-256が`0c03226dd5c99c0293392ed1bc5528a03093084e622ff21e3784a8810c3b8ba0`、v2が`8c865b7e53593ad90a97357d50fa39e3ebdaf4e97bc925118b1012e01ea38234`のまま、v3が`3d03cb6912ba7082376e9b262fb95d03ca30917d8eecbbc521bf63b27a53ce36`であることを確認する。
- full backend、frontend test/typecheck/lint/build、PHPStan、Pint、documentation gate、fresh migration、v3 migration regressionを成功させる。

## Production deploy前

1. exact release SHA、image digest、World key、現在turn、migration一覧、TurnRun件数、queue item件数、monster instance件数、kill aggregate件数をdeployment recordへ記録する。
2. reverse proxyまたはload balancerでplayer write trafficを外部遮断し、turn cronと手動turn実行を停止する。repository既定ComposeのLaravel maintenance markerは`file` driverかつ`storage/`が非永続なので、container置換を跨ぐ停止境界には使わない。永続cache-backed maintenanceを別途構成済みの場合も、外部遮断を正本として併用する。旧v2 imageのweb containerで以後のpreflightとread-only auditを行い、外部からwrite routeが拒否され、停止中に新しいTurnRunやcommand queue itemが増えないことを確認する。
3. `product/docs/operations/database-backup-and-restore.md`のproduction wrapperで最初のcheckpoint backupを取得する。wrapperのexit 0、暗号化、upload、HEAD、remote size/MD5、`.uploaded` marker、local encrypted fileを確認する。remote verification前にlocal backupを削除せず、失敗時はdeployを中止する。この後にTurnRun retry、queue cancelその他のproduction mutationが必要になれば、このbackupを変更前checkpointとして保持する。
4. `php artisan hakoniwa:release:preflight --world=shared-world`を実行する。次target turnのnon-dry `pending`、`running`、`failed`、`blocked`はすべて停止要因である。検出時は現在のrelease windowを中止し、自動retryやreleaseを跨ぐretryを行わない。checkpoint backupを保持したまま旧v2運用として、同じruleset・seedのoperator retry境界を守って明示解消し、その後step 1から再開する。
5. 次のread-only auditをproduction PostgreSQLで、1つのrepeatable-read snapshotとして実行する。最初のv2 deployではWorldと全live referenceがv2で、queued v2 `territory_expand`、次target turnのnon-dry TurnRun、kill aggregate collisionが0件、`nation_monster_kill_stat_guard`がenabledでなければ進めない。既に同じmigrationを完了したexact retryではWorldと全live referenceがv3であることを要求する。

```sql
BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;

SELECT w.id, w.key, w.current_turn, r.key AS ruleset_key
FROM worlds w
JOIN ruleset_versions r ON r.id = w.ruleset_version_id
WHERE w.key = 'shared-world';

SELECT tr.id, tr.target_turn, tr.status, tr.attempt_count
FROM worlds w
JOIN turn_runs tr
  ON tr.world_id = w.id
 AND tr.target_turn = w.current_turn + 1
 AND tr.is_dry_run = false
WHERE w.key = 'shared-world'
ORDER BY tr.id;

SELECT item.id,
       queue.id AS queue_id,
       nation.id AS nation_id,
       definition.key AS command_key,
       ruleset.key AS definition_ruleset_key,
       item.queue_position,
       item.target_x,
       item.target_y,
       item.quantity,
       item.parameters,
       item.status,
       item.queued_by_membership_id,
       item.request_key,
       item.queued_at,
       item.cancelled_at,
       item.execution_started_at,
       item.execution_completed_at,
       item.execution_failed_at,
       item.failure_code,
       item.failure_metadata,
       item.created_at,
       item.updated_at
FROM nation_command_queue_items item
JOIN nation_command_queues queue ON queue.id = item.nation_command_queue_id
JOIN nations nation ON nation.id = queue.nation_id
JOIN worlds world ON world.id = nation.world_id
JOIN command_definitions definition ON definition.id = item.command_definition_id
JOIN ruleset_versions ruleset ON ruleset.id = definition.ruleset_version_id
WHERE world.key = 'shared-world'
ORDER BY item.id;

SELECT run.id,
       run.target_turn,
       ruleset.key AS ruleset_key,
       run.random_seed,
       run.source,
       run.is_dry_run,
       run.status,
       run.attempt_count,
       run.pipeline,
       run.phase_results,
       run.started_at,
       run.completed_at,
       run.failure_code,
       run.failure_message,
       run.failure_context,
       run.created_at,
       run.updated_at
FROM turn_runs run
JOIN worlds world ON world.id = run.world_id
JOIN ruleset_versions ruleset ON ruleset.id = run.ruleset_version_id
WHERE world.key = 'shared-world'
ORDER BY run.id;

SELECT item.id, item.queue_position, nation.id AS nation_id
FROM nation_command_queue_items item
JOIN nation_command_queues queue ON queue.id = item.nation_command_queue_id
JOIN nations nation ON nation.id = queue.nation_id
JOIN worlds world ON world.id = nation.world_id
JOIN command_definitions definition ON definition.id = item.command_definition_id
JOIN ruleset_versions ruleset ON ruleset.id = definition.ruleset_version_id
WHERE world.key = 'shared-world'
  AND ruleset.key = 'hakoniwa-2s-plus-v2'
  AND definition.key = 'territory_expand'
  AND item.status = 'queued'
ORDER BY item.id;

SELECT 'queue' AS reference_type, ruleset.key, COUNT(*)
FROM nation_command_queue_items item
JOIN nation_command_queues queue ON queue.id = item.nation_command_queue_id
JOIN nations nation ON nation.id = queue.nation_id
JOIN worlds world ON world.id = nation.world_id
JOIN command_definitions definition ON definition.id = item.command_definition_id
JOIN ruleset_versions ruleset ON ruleset.id = definition.ruleset_version_id
WHERE world.key = 'shared-world'
GROUP BY ruleset.key
UNION ALL
SELECT 'monster_instance', ruleset.key, COUNT(*)
FROM monster_instances instance
JOIN worlds world ON world.id = instance.world_id
JOIN monster_definitions definition ON definition.id = instance.monster_definition_id
JOIN ruleset_versions ruleset ON ruleset.id = definition.ruleset_version_id
WHERE world.key = 'shared-world'
GROUP BY ruleset.key
UNION ALL
SELECT 'monster_kill_stat', ruleset.key, COUNT(*)
FROM nation_monster_kill_stats stat
JOIN worlds world ON world.id = stat.world_id
JOIN monster_definitions definition ON definition.id = stat.monster_definition_id
JOIN ruleset_versions ruleset ON ruleset.id = definition.ruleset_version_id
WHERE world.key = 'shared-world'
GROUP BY ruleset.key
ORDER BY reference_type, key;

SELECT source_stat.id AS v2_stat_id, target_stat.id AS v3_stat_id
FROM nation_monster_kill_stats source_stat
JOIN monster_definitions source_definition
  ON source_definition.id = source_stat.monster_definition_id
JOIN ruleset_versions source_ruleset
  ON source_ruleset.id = source_definition.ruleset_version_id
 AND source_ruleset.key = 'hakoniwa-2s-plus-v2'
JOIN monster_definitions target_definition
  ON target_definition.key = source_definition.key
JOIN ruleset_versions target_ruleset
  ON target_ruleset.id = target_definition.ruleset_version_id
 AND target_ruleset.key = 'hakoniwa-2s-plus-v3'
JOIN nation_monster_kill_stats target_stat
  ON target_stat.world_id = source_stat.world_id
 AND target_stat.nation_id = source_stat.nation_id
 AND target_stat.monster_definition_id = target_definition.id
JOIN worlds world ON world.id = source_stat.world_id
WHERE world.key = 'shared-world'
ORDER BY source_stat.id;

SELECT tgname, tgenabled
FROM pg_trigger
WHERE tgrelid = 'nation_monster_kill_stats'::regclass
  AND tgname = 'nation_monster_kill_stat_guard';

ROLLBACK;
```

6. queued v2 `territory_expand`が1件でもあればreleaseを停止する。direct SQL、ad-hoc operator mutation、v3 definitionへの付け替え、item単体の擬似実行は禁止する。player本人と調整して旧v2 applicationの既存認証済みUI/APIからcancelするか、別途明示承認した旧v2の公式turn全体を通常runnerで完了・監視する。どちらも現在のrelease windowを中止して旧v2運用として行い、解消できなければrelease blockとする。
7. 前項または他のproduction mutationを行った場合は、exact stateとplayer intentを記録したうえで、このchecklistのstep 1から再開する。World turn、TurnRun、queueその他のdeployment recordを再baselineし、preflight、freeze、read-only audit、verified off-host backupをすべてやり直す。再開後のcheckpoint backupをdeploy前backupの正本とする。
8. 最終preflightとread-only auditをもう一度実行し、deploy前backup以降、停止中の記録値が変化していないことを確認する。

## Deployとmigration

1. 外部write traffic blockを維持したままexact reviewed imageへcontainerを置換する。新containerのLaravel maintenance stateは引き継がれると仮定せず、healthcheckが通ってもplayer writeとturn cronを再開しない。
2. 通常のforward pathとして`php artisan migrate --force`を1回実行する。migrationはWorld turn advisory lockを取得し、definition set、live reference、queued legacy command、次TurnRun、kill aggregate collisionをtransaction内でも再検証する。非ゼロなら停止したまま原因を調査し、migrationを手作業で部分適用しない。
3. `php artisan migrate:status`で`2026_08_10_000000_publish_hakoniwa_2s_plus_v3`と`2026_08_11_000000_create_island_messages`が`Ran`であることを確認する。
4. deploy前と同じread-only auditを実行し、Worldとqueue／monster instance／kill aggregateの全live referenceがv3、mismatchとcollisionが0件、kill-stat triggerがenabledであることを確認する。
5. 次のread-only schema auditを実行し、`island_messages` table、visitor/cooldown columns、11個のnamed foreign key／check／unique constraintのnameとtypeが存在することを確認する。`message_table`は非NULL、`user_columns`は2、`matched_constraints`と`expected_constraints`はともに11、`missing_constraints`は0でなければならない。列、参照先、delete action、check式のexact shapeは、reviewed migration SHAと`MessageBoardMigrationTest`／`MessageBoardIntegrityTest`を正本とし、同じqueryで出力する`constraint_definition`と照合する。deploy前と同じordered queue item／TurnRun SELECTを保存・再実行し、definition rulesetがv2からv3へ変わること以外のqueue列とTurnRun履歴が不変であることを照合する。World turnとmonster/kill aggregate件数も意図せず変化していないことを確認する。

```sql
BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;

SELECT to_regclass('public.island_messages') AS message_table;

SELECT COUNT(*) AS user_columns
FROM information_schema.columns
WHERE table_schema = 'public'
  AND table_name = 'users'
  AND column_name IN ('visitor_code', 'message_board_last_posted_at');

WITH expected(table_name, constraint_name, constraint_type) AS (
    VALUES
        ('users', 'users_visitor_code_unique', 'u'),
        ('users', 'users_visitor_code_format_check', 'c'),
        ('nations', 'nations_world_id_id_unique', 'u'),
        ('island_messages', 'island_messages_public_id_unique', 'u'),
        ('island_messages', 'island_messages_world_id_foreign', 'f'),
        ('island_messages', 'island_messages_author_user_id_foreign', 'f'),
        ('island_messages', 'island_messages_target_world_fk', 'f'),
        ('island_messages', 'island_messages_author_world_fk', 'f'),
        ('island_messages', 'island_messages_sender_world_fk', 'f'),
        ('island_messages', 'island_messages_body_length_check', 'c'),
        ('island_messages', 'island_messages_type_shape_check', 'c')
), actual AS (
    SELECT relation.relname::text AS table_name,
           constraint_record.conname::text AS constraint_name,
           constraint_record.contype::text AS constraint_type
    FROM pg_constraint constraint_record
    JOIN pg_class relation ON relation.oid = constraint_record.conrelid
    JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
    WHERE namespace.nspname = 'public'
)
SELECT COUNT(actual.constraint_name) AS matched_constraints,
       COUNT(*) AS expected_constraints,
       COUNT(*) - COUNT(actual.constraint_name) AS missing_constraints
FROM expected
LEFT JOIN actual USING (table_name, constraint_name, constraint_type);

SELECT relation.relname AS table_name,
       constraint_record.conname AS constraint_name,
       constraint_record.contype AS constraint_type,
       pg_get_constraintdef(constraint_record.oid, true) AS constraint_definition
FROM pg_constraint constraint_record
JOIN pg_class relation ON relation.oid = constraint_record.conrelid
JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
WHERE namespace.nspname = 'public'
  AND constraint_record.conname IN (
      'users_visitor_code_unique',
      'users_visitor_code_format_check',
      'nations_world_id_id_unique',
      'island_messages_public_id_unique',
      'island_messages_world_id_foreign',
      'island_messages_author_user_id_foreign',
      'island_messages_target_world_fk',
      'island_messages_author_world_fk',
      'island_messages_sender_world_fk',
      'island_messages_body_length_check',
      'island_messages_type_shape_check'
  )
ORDER BY relation.relname, constraint_record.conname;

ROLLBACK;
```
6. `php artisan hakoniwa:release:preflight --world=shared-world`を再実行する。`php artisan hakoniwa:turn:status --world=shared-world`とdry-run、public lobby、login、island map、command queue、event log、message boardのsmoke testを行う。
7. 外部からwrite routeがまだ拒否されていることを最終確認してから、外部write traffic blockと構成済みLaravel maintenanceを解除してplayer writeを再開し、最後にturn cronを再開する。次の公式turnを監視し、World turnとTurnRunが同じrulesetで完了したことを確認する。
8. `product/docs/ver-1.4.0-announcement.md`のtitle/bodyを既存のお知らせ管理画面から1回だけ公開し、public lobbyと詳細を確認する。migration、seeder、deploy scriptでannouncementを作らず、既存記事を上書き・重複作成しない。

## Rollback禁止境界

`php artisan migrate:rollback`、`migrate:fresh`、World reset、volume再作成をproductionで実行しない。message migrationの`down()`はmessage tableを削除し、その後のv3 migrationの`down()`はforward-only例外で停止するため、generic rollbackは部分的かつ破壊的である。

application rollbackまたはproduction restoreが必要な場合はplayer writeとturn cronを停止したままにする。対象environment、復元時点、deploy後writeの扱い、停止時間を明示承認し、検証済みdeploy前backupからのrestore、または別review済みforward conversionだけを使う。production DBにrestore rehearsal手順をそのまま向けない。

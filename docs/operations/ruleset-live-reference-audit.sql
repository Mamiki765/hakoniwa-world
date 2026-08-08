\set ON_ERROR_STOP on

BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY;

-- Inventory every persisted foreign key into a ruleset row or a ruleset-scoped
-- catalog. This is schema evidence; it does not classify historical TurnRuns as
-- live-state mismatches.
SELECT
    con.conrelid::regclass AS referencing_table,
    src.attname AS referencing_column,
    con.confrelid::regclass AS referenced_table,
    dst.attname AS referenced_column,
    con.conname AS constraint_name,
    con.condeferrable,
    con.condeferred
FROM pg_constraint con
JOIN LATERAL unnest(con.conkey) WITH ORDINALITY AS src_key(attnum, ordinality) ON true
JOIN LATERAL unnest(con.confkey) WITH ORDINALITY AS dst_key(attnum, ordinality)
  ON dst_key.ordinality = src_key.ordinality
JOIN pg_attribute src ON src.attrelid = con.conrelid AND src.attnum = src_key.attnum
JOIN pg_attribute dst ON dst.attrelid = con.confrelid AND dst.attnum = dst_key.attnum
WHERE con.contype = 'f'
  AND con.confrelid IN (
      'ruleset_versions'::regclass,
      'command_definitions'::regclass,
      'production_definitions'::regclass,
      'monster_definitions'::regclass
  )
ORDER BY con.conrelid::regclass::text, src.attname;

-- Confirm the active integrity boundary around A-class rows, including the
-- deferrable queue/World ruleset constraint and the named monster guards.
SELECT
    con.conrelid::regclass AS table_name,
    con.conname AS constraint_name,
    con.contype AS constraint_type,
    con.condeferrable,
    con.condeferred,
    pg_get_constraintdef(con.oid) AS definition
FROM pg_constraint con
WHERE con.conrelid IN (
    'worlds'::regclass,
    'nation_command_queue_items'::regclass,
    'monster_instances'::regclass,
    'nation_monster_kill_stats'::regclass
)
ORDER BY con.conrelid::regclass::text, con.conname;

SELECT
    trigger.tgrelid::regclass AS table_name,
    trigger.tgname AS trigger_name,
    trigger.tgenabled,
    pg_get_triggerdef(trigger.oid) AS definition
FROM pg_trigger trigger
WHERE trigger.tgrelid IN (
    'nation_command_queue_items'::regclass,
    'monster_instances'::regclass,
    'nation_monster_kill_stats'::regclass
)
  AND NOT trigger.tgisinternal
ORDER BY trigger.tgrelid::regclass::text, trigger.tgname;

SELECT
    worlds.id AS world_id,
    worlds.key AS world_key,
    worlds.current_turn,
    worlds.ruleset_version_id,
    ruleset_versions.key AS ruleset_key
FROM worlds
JOIN ruleset_versions ON ruleset_versions.id = worlds.ruleset_version_id
WHERE worlds.key = 'shared-world';

-- A-class live mutable references. Each type must report mismatch_count=0
-- after the 1.1.1 repair. TurnRuns are intentionally excluded because their
-- ruleset_version_id is a B-class historical execution snapshot.
WITH world_scope AS (
    SELECT w.id AS world_id, w.ruleset_version_id, rv.key AS world_ruleset_key
    FROM worlds w
    JOIN ruleset_versions rv ON rv.id = w.ruleset_version_id
    WHERE w.key = 'shared-world'
), live_references AS (
    SELECT
        'nation_command_queue_items'::text AS reference_type,
        item.id AS reference_id,
        nation.id AS nation_id,
        definition.id AS definition_id,
        definition.key AS definition_key,
        definition.ruleset_version_id AS definition_ruleset_id,
        ruleset.key AS definition_ruleset_key,
        world_scope.ruleset_version_id AS world_ruleset_id,
        world_scope.world_ruleset_key
    FROM world_scope
    JOIN nations nation ON nation.world_id = world_scope.world_id
    JOIN nation_command_queues queue ON queue.nation_id = nation.id
    JOIN nation_command_queue_items item ON item.nation_command_queue_id = queue.id
    JOIN command_definitions definition ON definition.id = item.command_definition_id
    JOIN ruleset_versions ruleset ON ruleset.id = definition.ruleset_version_id

    UNION ALL

    SELECT
        'monster_instances', instance.id, NULL::bigint,
        definition.id, definition.key, definition.ruleset_version_id,
        ruleset.key, world_scope.ruleset_version_id, world_scope.world_ruleset_key
    FROM world_scope
    JOIN monster_instances instance ON instance.world_id = world_scope.world_id
    JOIN monster_definitions definition ON definition.id = instance.monster_definition_id
    JOIN ruleset_versions ruleset ON ruleset.id = definition.ruleset_version_id

    UNION ALL

    SELECT
        'nation_monster_kill_stats', stat.id, stat.nation_id,
        definition.id, definition.key, definition.ruleset_version_id,
        ruleset.key, world_scope.ruleset_version_id, world_scope.world_ruleset_key
    FROM world_scope
    JOIN nation_monster_kill_stats stat ON stat.world_id = world_scope.world_id
    JOIN monster_definitions definition ON definition.id = stat.monster_definition_id
    JOIN ruleset_versions ruleset ON ruleset.id = definition.ruleset_version_id
), expected(reference_type) AS (
    VALUES
        ('nation_command_queue_items'::text),
        ('monster_instances'::text),
        ('nation_monster_kill_stats'::text)
), mismatches AS (
    SELECT * FROM live_references WHERE definition_ruleset_id <> world_ruleset_id
)
SELECT expected.reference_type, count(mismatches.reference_id) AS mismatch_count
FROM expected
LEFT JOIN mismatches USING (reference_type)
GROUP BY expected.reference_type
ORDER BY expected.reference_type;

WITH world_scope AS (
    SELECT w.id AS world_id, w.ruleset_version_id, rv.key AS world_ruleset_key
    FROM worlds w
    JOIN ruleset_versions rv ON rv.id = w.ruleset_version_id
    WHERE w.key = 'shared-world'
), live_references AS (
    SELECT
        'nation_command_queue_items'::text AS reference_type,
        item.id AS reference_id,
        nation.id AS nation_id,
        definition.id AS definition_id,
        definition.key AS definition_key,
        definition.ruleset_version_id AS definition_ruleset_id,
        ruleset.key AS definition_ruleset_key,
        world_scope.ruleset_version_id AS world_ruleset_id,
        world_scope.world_ruleset_key
    FROM world_scope
    JOIN nations nation ON nation.world_id = world_scope.world_id
    JOIN nation_command_queues queue ON queue.nation_id = nation.id
    JOIN nation_command_queue_items item ON item.nation_command_queue_id = queue.id
    JOIN command_definitions definition ON definition.id = item.command_definition_id
    JOIN ruleset_versions ruleset ON ruleset.id = definition.ruleset_version_id

    UNION ALL

    SELECT
        'monster_instances', instance.id, NULL::bigint,
        definition.id, definition.key, definition.ruleset_version_id,
        ruleset.key, world_scope.ruleset_version_id, world_scope.world_ruleset_key
    FROM world_scope
    JOIN monster_instances instance ON instance.world_id = world_scope.world_id
    JOIN monster_definitions definition ON definition.id = instance.monster_definition_id
    JOIN ruleset_versions ruleset ON ruleset.id = definition.ruleset_version_id

    UNION ALL

    SELECT
        'nation_monster_kill_stats', stat.id, stat.nation_id,
        definition.id, definition.key, definition.ruleset_version_id,
        ruleset.key, world_scope.ruleset_version_id, world_scope.world_ruleset_key
    FROM world_scope
    JOIN nation_monster_kill_stats stat ON stat.world_id = world_scope.world_id
    JOIN monster_definitions definition ON definition.id = stat.monster_definition_id
    JOIN ruleset_versions ruleset ON ruleset.id = definition.ruleset_version_id
)
SELECT
    reference_type,
    reference_id,
    nation_id,
    definition_id,
    definition_key,
    definition_ruleset_key,
    world_ruleset_key
FROM live_references
WHERE definition_ruleset_id <> world_ruleset_id
ORDER BY reference_type, reference_id;

-- Informational only: historical TurnRun snapshots are expected to retain the
-- ruleset used to create them and are never repair targets.
SELECT
    run_ruleset.key AS turn_run_ruleset_key,
    turn_runs.status,
    count(*) AS run_count,
    min(turn_runs.target_turn) AS first_target_turn,
    max(turn_runs.target_turn) AS last_target_turn
FROM worlds
JOIN turn_runs ON turn_runs.world_id = worlds.id
JOIN ruleset_versions run_ruleset ON run_ruleset.id = turn_runs.ruleset_version_id
WHERE worlds.key = 'shared-world'
GROUP BY run_ruleset.key, turn_runs.status
ORDER BY run_ruleset.key, turn_runs.status;

ROLLBACK;

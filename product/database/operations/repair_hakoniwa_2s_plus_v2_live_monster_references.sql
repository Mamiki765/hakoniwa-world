DO $operator_repair$
DECLARE
    world_identity record;
    world_record record;
    source_ruleset_id bigint;
    target_ruleset_id bigint;
    ruleset_row_count integer;
    ambiguous_keys text;
    mismatched_keys text;
    unexpected_instance_ids text;
    unexpected_stat_ids text;
    collision_rows text;
    trigger_row_count integer;
    trigger_state text;
    stats_to_repair bigint;
    repaired_instances bigint := 0;
    repaired_stats bigint := 0;
    queue_mismatches bigint;
    instance_mismatches bigint;
    stat_mismatches bigint;
BEGIN
    SELECT id, key
      INTO world_identity
      FROM worlds
     WHERE key = 'shared-world';

    IF NOT FOUND THEN
        RAISE NOTICE 'shared-world does not exist; live monster reference repair is a no-op';
        RETURN;
    END IF;

    IF NOT pg_try_advisory_xact_lock(
        hashtextextended('hakoniwa.turn.world.' || world_identity.id::text, 0)
    ) THEN
        RAISE EXCEPTION
            'Refusing to repair shared-world % (%) while a turn operation holds its advisory lock.',
            world_identity.id,
            world_identity.key;
    END IF;

    SELECT id, key, ruleset_version_id
      INTO world_record
      FROM worlds
     WHERE id = world_identity.id
       AND key = 'shared-world'
     FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'shared-world disappeared while acquiring the repair lock.';
    END IF;

    SELECT
        count(*),
        max(id) FILTER (WHERE key = 'hakoniwa-2s-plus-v1'),
        max(id) FILTER (WHERE key = 'hakoniwa-2s-plus-v2')
      INTO ruleset_row_count, source_ruleset_id, target_ruleset_id
      FROM ruleset_versions
     WHERE key IN ('hakoniwa-2s-plus-v1', 'hakoniwa-2s-plus-v2');

    IF ruleset_row_count <> 2
       OR source_ruleset_id IS NULL
       OR target_ruleset_id IS NULL THEN
        RAISE EXCEPTION 'The immutable v1 or v2 production ruleset row is missing.';
    END IF;

    IF world_record.ruleset_version_id <> target_ruleset_id THEN
        RAISE EXCEPTION
            'shared-world must already use hakoniwa-2s-plus-v2 before repairing live monster references.';
    END IF;

    LOCK TABLE monster_definitions IN SHARE MODE;
    LOCK TABLE monster_instances IN SHARE ROW EXCLUSIVE MODE;
    LOCK TABLE nation_monster_kill_stats IN SHARE ROW EXCLUSIVE MODE;

    SELECT string_agg(ruleset_key || ':' || definition_key, ', ' ORDER BY ruleset_key, definition_key)
      INTO ambiguous_keys
      FROM (
          SELECT ruleset.key AS ruleset_key, definition.key AS definition_key
            FROM monster_definitions definition
            JOIN ruleset_versions ruleset ON ruleset.id = definition.ruleset_version_id
           WHERE definition.ruleset_version_id IN (source_ruleset_id, target_ruleset_id)
           GROUP BY ruleset.key, definition.key
          HAVING count(*) <> 1
      ) duplicates;

    IF ambiguous_keys IS NOT NULL THEN
        RAISE EXCEPTION 'v1 or v2 has ambiguous monster definition keys: %', ambiguous_keys;
    END IF;

    SELECT string_agg(coalesce(source.key, target.key), ', ' ORDER BY coalesce(source.key, target.key))
      INTO mismatched_keys
      FROM (
          SELECT id, key
            FROM monster_definitions
           WHERE ruleset_version_id = source_ruleset_id
      ) source
      FULL OUTER JOIN (
          SELECT id, key
            FROM monster_definitions
           WHERE ruleset_version_id = target_ruleset_id
      ) target USING (key)
     WHERE source.id IS NULL OR target.id IS NULL;

    IF mismatched_keys IS NOT NULL THEN
        RAISE EXCEPTION 'v1 and v2 have different monster definition sets: %', mismatched_keys;
    END IF;

    SELECT string_agg(rows.id::text, ', ' ORDER BY rows.id)
      INTO unexpected_instance_ids
      FROM (
          SELECT instance.id
            FROM monster_instances instance
            JOIN monster_definitions definition ON definition.id = instance.monster_definition_id
           WHERE instance.world_id = world_record.id
             AND definition.ruleset_version_id NOT IN (source_ruleset_id, target_ruleset_id)
           ORDER BY instance.id
           LIMIT 20
      ) rows;

    IF unexpected_instance_ids IS NOT NULL THEN
        RAISE EXCEPTION
            'shared-world monster instance rows reference an unexpected ruleset; ids: %',
            unexpected_instance_ids;
    END IF;

    SELECT string_agg(rows.id::text, ', ' ORDER BY rows.id)
      INTO unexpected_stat_ids
      FROM (
          SELECT stat.id
            FROM nation_monster_kill_stats stat
            JOIN monster_definitions definition ON definition.id = stat.monster_definition_id
           WHERE stat.world_id = world_record.id
             AND definition.ruleset_version_id NOT IN (source_ruleset_id, target_ruleset_id)
           ORDER BY stat.id
           LIMIT 20
      ) rows;

    IF unexpected_stat_ids IS NOT NULL THEN
        RAISE EXCEPTION
            'shared-world monster kill stat rows reference an unexpected ruleset; ids: %',
            unexpected_stat_ids;
    END IF;

    SELECT string_agg(
               source_stat.id::text || '->' || target_stat.id::text,
               ', ' ORDER BY source_stat.id
           )
      INTO collision_rows
      FROM nation_monster_kill_stats source_stat
      JOIN monster_definitions source_definition
        ON source_definition.id = source_stat.monster_definition_id
       AND source_definition.ruleset_version_id = source_ruleset_id
      JOIN monster_definitions target_definition
        ON target_definition.ruleset_version_id = target_ruleset_id
       AND target_definition.key = source_definition.key
      JOIN nation_monster_kill_stats target_stat
        ON target_stat.world_id = source_stat.world_id
       AND target_stat.nation_id = source_stat.nation_id
       AND target_stat.monster_definition_id = target_definition.id
       AND target_stat.id <> source_stat.id
     WHERE source_stat.world_id = world_record.id;

    IF collision_rows IS NOT NULL THEN
        RAISE EXCEPTION
            'Monster kill stat collisions exist (%); refusing to merge aggregates.',
            collision_rows;
    END IF;

    SELECT count(*)
      INTO stats_to_repair
      FROM nation_monster_kill_stats stat
      JOIN monster_definitions definition ON definition.id = stat.monster_definition_id
     WHERE stat.world_id = world_record.id
       AND definition.ruleset_version_id = source_ruleset_id;

    SELECT count(*), max(trigger.tgenabled::text)
      INTO trigger_row_count, trigger_state
      FROM pg_trigger trigger
     WHERE trigger.tgrelid = 'nation_monster_kill_stats'::regclass
       AND trigger.tgname = 'nation_monster_kill_stat_guard'
       AND NOT trigger.tgisinternal;

    IF trigger_row_count <> 1 OR trigger_state <> 'O' THEN
        RAISE EXCEPTION
            'nation_monster_kill_stat_guard must exist exactly once and be enabled before the repair.';
    END IF;

    UPDATE monster_instances instance
       SET monster_definition_id = target_definition.id
      FROM monster_definitions source_definition
      JOIN monster_definitions target_definition
        ON target_definition.ruleset_version_id = target_ruleset_id
       AND target_definition.key = source_definition.key
     WHERE instance.world_id = world_record.id
       AND instance.monster_definition_id = source_definition.id
       AND source_definition.ruleset_version_id = source_ruleset_id;
    GET DIAGNOSTICS repaired_instances = ROW_COUNT;

    IF stats_to_repair > 0 THEN
        ALTER TABLE nation_monster_kill_stats
            DISABLE TRIGGER nation_monster_kill_stat_guard;

        UPDATE nation_monster_kill_stats stat
           SET monster_definition_id = target_definition.id
          FROM monster_definitions source_definition
          JOIN monster_definitions target_definition
            ON target_definition.ruleset_version_id = target_ruleset_id
           AND target_definition.key = source_definition.key
         WHERE stat.world_id = world_record.id
           AND stat.monster_definition_id = source_definition.id
           AND source_definition.ruleset_version_id = source_ruleset_id;
        GET DIAGNOSTICS repaired_stats = ROW_COUNT;

        IF repaired_stats <> stats_to_repair THEN
            RAISE EXCEPTION
                'Expected to repair % monster kill stats, but repaired %.',
                stats_to_repair,
                repaired_stats;
        END IF;

        ALTER TABLE nation_monster_kill_stats
            ENABLE TRIGGER nation_monster_kill_stat_guard;
    END IF;

    SELECT count(*), max(trigger.tgenabled::text)
      INTO trigger_row_count, trigger_state
      FROM pg_trigger trigger
     WHERE trigger.tgrelid = 'nation_monster_kill_stats'::regclass
       AND trigger.tgname = 'nation_monster_kill_stat_guard'
       AND NOT trigger.tgisinternal;

    IF trigger_row_count <> 1 OR trigger_state <> 'O' THEN
        RAISE EXCEPTION
            'nation_monster_kill_stat_guard must exist exactly once and be enabled after the repair.';
    END IF;

    SELECT count(*)
      INTO queue_mismatches
      FROM nation_command_queue_items item
      JOIN nation_command_queues queue ON queue.id = item.nation_command_queue_id
      JOIN nations nation ON nation.id = queue.nation_id
      JOIN command_definitions definition ON definition.id = item.command_definition_id
     WHERE nation.world_id = world_record.id
       AND definition.ruleset_version_id <> target_ruleset_id;

    SELECT count(*)
      INTO instance_mismatches
      FROM monster_instances instance
      JOIN monster_definitions definition ON definition.id = instance.monster_definition_id
     WHERE instance.world_id = world_record.id
       AND definition.ruleset_version_id <> target_ruleset_id;

    SELECT count(*)
      INTO stat_mismatches
      FROM nation_monster_kill_stats stat
      JOIN monster_definitions definition ON definition.id = stat.monster_definition_id
     WHERE stat.world_id = world_record.id
       AND definition.ruleset_version_id <> target_ruleset_id;

    IF queue_mismatches <> 0
       OR instance_mismatches <> 0
       OR stat_mismatches <> 0 THEN
        RAISE EXCEPTION
            'shared-world live ruleset reference mismatch after repair (queue=%, instances=%, kill_stats=%).',
            queue_mismatches,
            instance_mismatches,
            stat_mismatches;
    END IF;

    RAISE NOTICE
        'live monster reference repair complete (world_id=%, instances=%, kill_stats=%)',
        world_record.id,
        repaired_instances,
        repaired_stats;
END
$operator_repair$;

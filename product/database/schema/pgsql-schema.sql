--
-- PostgreSQL database dump
--

\restrict AIHFphxJP48UpE3pFUEXLH31hK9jVCb4eqFdqiUNflydUNPiXW2UCB06T64hwuO

-- Dumped from database version 18.4 (Debian 18.4-1.pgdg12+1)
-- Dumped by pg_dump version 18.4 (Debian 18.4-1.pgdg12+1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;



--
-- Name: enforce_queue_item_world_ruleset_match(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.enforce_queue_item_world_ruleset_match() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    world_ruleset_id bigint;
    definition_ruleset_id bigint;
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM nation_command_queue_items
        WHERE id = NEW.id
    ) THEN
        RETURN NEW;
    END IF;

    IF NEW.target_context = 'underground_slot' THEN
        RETURN NEW;
    END IF;

    SELECT worlds.ruleset_version_id, command_definitions.ruleset_version_id
    INTO world_ruleset_id, definition_ruleset_id
    FROM nation_command_queues
    INNER JOIN nations ON nations.id = nation_command_queues.nation_id
    INNER JOIN worlds ON worlds.id = nations.world_id
    INNER JOIN command_definitions ON command_definitions.id = NEW.command_definition_id
    WHERE nation_command_queues.id = NEW.nation_command_queue_id;

    IF NOT FOUND OR world_ruleset_id IS DISTINCT FROM definition_ruleset_id THEN
        RAISE EXCEPTION
            'queue item % command definition ruleset % does not match World ruleset %',
            NEW.id,
            definition_ruleset_id,
            world_ruleset_id
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;


--
-- Name: reject_nation_achievement_delete(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.reject_nation_achievement_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM worlds WHERE id = OLD.world_id) THEN
        RETURN OLD;
    END IF;
    RAISE EXCEPTION 'Nation achievement state is permanent while its World exists';
END;
$$;


--
-- Name: reject_nation_award_update(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.reject_nation_award_update() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    RAISE EXCEPTION 'Nation award occurrences are immutable';
END;
$$;


--
-- Name: reject_nation_monster_kill_stat_delete(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.reject_nation_monster_kill_stat_delete() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM worlds WHERE id = OLD.world_id) THEN
        RETURN OLD;
    END IF;
    RAISE EXCEPTION 'monster kill stats are permanent while their World exists';
END;
$$;


--
-- Name: validate_buried_treasure_identity(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.validate_buried_treasure_identity() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  cell_world_id bigint;
  resolving_nation_world_id bigint;
BEGIN
  SELECT space.world_id INTO cell_world_id
    FROM map_cells cell
    JOIN map_spaces space ON space.id = cell.map_space_id
   WHERE cell.id = NEW.map_cell_id;
  IF cell_world_id IS NULL OR cell_world_id <> NEW.world_id THEN
    RAISE EXCEPTION 'Buried Treasure cell must belong to its World.';
  END IF;
  IF NEW.resolved_by_nation_id IS NOT NULL THEN
    SELECT world_id INTO resolving_nation_world_id
      FROM nations
     WHERE id = NEW.resolved_by_nation_id;
    IF resolving_nation_world_id IS NULL OR resolving_nation_world_id <> NEW.world_id THEN
      RAISE EXCEPTION 'Buried Treasure resolving Nation must belong to its World.';
    END IF;
  END IF;

  RETURN NEW;
END;
$$;


--
-- Name: validate_monster_instance_world_ruleset(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.validate_monster_instance_world_ruleset() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    world_ruleset bigint;
    definition_ruleset bigint;
    definition_min_hp integer;
    definition_max_hp integer;
    ruleset_settings jsonb;
    central_contract jsonb;
    central_facility_keys jsonb;
    central_percent_per_level integer;
    central_facility_key_count integer;
    central_facility_definition_count integer;
    central_maximum_level integer;
    maximum_spawned_hp bigint;
BEGIN
    SELECT ruleset_version_id INTO world_ruleset FROM worlds WHERE id = NEW.world_id;
    SELECT definition.ruleset_version_id,
           definition.base_hp,
           definition.base_hp + definition.hp_variation,
           ruleset.settings
      INTO definition_ruleset, definition_min_hp, definition_max_hp, ruleset_settings
      FROM monster_definitions definition
      JOIN ruleset_versions ruleset ON ruleset.id = definition.ruleset_version_id
     WHERE definition.id = NEW.monster_definition_id;
    IF world_ruleset IS NULL OR definition_ruleset IS NULL OR world_ruleset <> definition_ruleset THEN
        RAISE EXCEPTION 'monster definition must belong to the World current ruleset';
    END IF;

    maximum_spawned_hp := definition_max_hp;
    central_contract := ruleset_settings #> '{central_facilities,natural_monster_hp}';
    IF central_contract IS NOT NULL THEN
        central_facility_keys := central_contract -> 'facility_keys';
        central_percent_per_level := (central_contract ->> 'percent_per_level')::integer;
        IF jsonb_typeof(central_contract) <> 'object'
           OR jsonb_typeof(central_facility_keys) <> 'array'
           OR jsonb_array_length(central_facility_keys) < 1
           OR central_percent_per_level < 1 THEN
            RAISE EXCEPTION 'central facility monster HP contract is invalid';
        END IF;

        SELECT count(*),
               count(ruleset_settings #> ARRAY['facility_definitions', facility_key, 'maximum_scale']),
               COALESCE(sum((ruleset_settings #>> ARRAY['facility_definitions', facility_key, 'maximum_scale'])::integer), 0)
          INTO central_facility_key_count, central_facility_definition_count, central_maximum_level
          FROM jsonb_array_elements_text(central_facility_keys) AS authored(facility_key);
        IF central_facility_key_count <> central_facility_definition_count
           OR central_maximum_level < 1 THEN
            RAISE EXCEPTION 'central facility maximum level contract is invalid';
        END IF;

        maximum_spawned_hp := LEAST(
            32767,
            (
                definition_max_hp::bigint
                * (100::bigint + central_percent_per_level::bigint * central_maximum_level::bigint)
                + 99
            ) / 100
        );
    END IF;

    IF NEW.spawned_max_hp < definition_min_hp OR NEW.spawned_max_hp > maximum_spawned_hp THEN
        RAISE EXCEPTION 'spawned monster HP is outside its Ruleset range';
    END IF;
    RETURN NEW;
END;
$$;


--
-- Name: validate_monster_occupancy(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.validate_monster_occupancy() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    monster_world bigint;
    monster_state text;
    cell_world bigint;
    cell_facility text;
    cell_space text;
BEGIN
    PERFORM 1 FROM map_cells WHERE id = NEW.map_cell_id FOR UPDATE;
    SELECT world_id, state INTO monster_world, monster_state
      FROM monster_instances WHERE id = NEW.monster_instance_id;
    SELECT ms.world_id, fd.key, ms.key INTO cell_world, cell_facility, cell_space
      FROM map_cells mc
      JOIN map_spaces ms ON ms.id = mc.map_space_id
      LEFT JOIN facility_definitions fd ON fd.id = mc.facility_definition_id
      WHERE mc.id = NEW.map_cell_id;
    IF monster_state IS DISTINCT FROM 'alive' THEN
        RAISE EXCEPTION 'only an alive monster may occupy a cell';
    END IF;
    IF monster_world IS NULL OR cell_world IS NULL OR monster_world <> cell_world THEN
        RAISE EXCEPTION 'monster occupancy cannot cross World boundaries';
    END IF;
    IF cell_space IS DISTINCT FROM 'surface' THEN
        RAISE EXCEPTION 'monster occupancy is limited to the surface map';
    END IF;
    IF cell_facility = 'capital' THEN
        RAISE EXCEPTION 'Capital cells cannot contain monster occupancy';
    END IF;
    IF EXISTS (
      SELECT 1 FROM ships ship WHERE ship.map_cell_id = NEW.map_cell_id AND ship.state = 'active'
    ) THEN
        RAISE EXCEPTION 'A Monster cannot share a cell with a Ship';
    END IF;
    RETURN NEW;
END;
$$;


--
-- Name: validate_nation_achievement_world(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.validate_nation_achievement_world() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    nation_world bigint;
BEGIN
    SELECT world_id INTO nation_world FROM nations WHERE id = NEW.nation_id;
    IF nation_world IS NULL OR nation_world <> NEW.world_id THEN
        RAISE EXCEPTION 'Nation achievement state cannot cross World boundaries';
    END IF;
    RETURN NEW;
END;
$$;


--
-- Name: validate_nation_monster_cycle_seed_requirement_update(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.validate_nation_monster_cycle_seed_requirement_update() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF NEW.world_id <> OLD.world_id
            OR NEW.nation_id <> OLD.nation_id
            OR NEW.cycle_start_turn <> OLD.cycle_start_turn
            OR NEW.cycle_end_turn <> OLD.cycle_end_turn
            OR NEW.created_at IS DISTINCT FROM OLD.created_at
            OR OLD.completed_at IS NOT NULL
            OR NEW.completed_at IS NULL THEN
            RAISE EXCEPTION 'Monster cycle seed requirement may only be completed once';
        END IF;
    END IF;
    IF NEW.completed_at IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM nation_monster_cycle_stats
        WHERE world_id = NEW.world_id
          AND nation_id = NEW.nation_id
          AND cycle_start_turn = NEW.cycle_start_turn
          AND cycle_end_turn = NEW.cycle_end_turn
          AND seeded_at IS NOT NULL
    ) THEN
        RAISE EXCEPTION 'Monster cycle seed requirement completion requires a corresponding seeded stat';
    END IF;
    RETURN NEW;
END;
$$;


--
-- Name: validate_nation_monster_cycle_update(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.validate_nation_monster_cycle_update() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    current_world_turn bigint;
BEGIN
    SELECT current_turn INTO current_world_turn FROM worlds WHERE id = OLD.world_id;
    IF current_world_turn IS NULL THEN
        RAISE EXCEPTION 'Monster cycle update references a missing World';
    END IF;
    IF OLD.cycle_end_turn <= current_world_turn THEN
        RAISE EXCEPTION 'Completed monster cycle history is immutable';
    END IF;
    IF NEW.world_id <> OLD.world_id
        OR NEW.nation_id <> OLD.nation_id
        OR NEW.cycle_start_turn <> OLD.cycle_start_turn
        OR NEW.cycle_end_turn <> OLD.cycle_end_turn
        OR NEW.seeded_at IS DISTINCT FROM OLD.seeded_at
        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'Monster cycle identity and seed audit fields are immutable';
    END IF;
    IF NEW.kill_count <> OLD.kill_count + 1 OR NEW.version <> OLD.version + 1 THEN
        RAISE EXCEPTION 'Monster cycle runtime update must increment count and version by exactly one';
    END IF;
    RETURN NEW;
END;
$$;


--
-- Name: validate_nation_monster_kill_stat(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.validate_nation_monster_kill_stat() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    nation_world bigint;
    world_ruleset bigint;
    definition_ruleset bigint;
BEGIN
    SELECT world_id INTO nation_world FROM nations WHERE id = NEW.nation_id;
    SELECT ruleset_version_id INTO world_ruleset FROM worlds WHERE id = NEW.world_id;
    SELECT ruleset_version_id INTO definition_ruleset
      FROM monster_definitions WHERE id = NEW.monster_definition_id;
    IF nation_world IS NULL OR world_ruleset IS NULL OR definition_ruleset IS NULL
       OR nation_world <> NEW.world_id OR world_ruleset <> definition_ruleset THEN
        RAISE EXCEPTION 'monster kill stat references inconsistent World state';
    END IF;
    IF TG_OP = 'INSERT' AND (NEW.kill_count <> 1 OR NEW.first_killed_turn <> NEW.last_killed_turn OR NEW.version <> 1) THEN
        RAISE EXCEPTION 'first monster kill stat must start at count and version one';
    END IF;
    IF TG_OP = 'UPDATE' AND (
        NEW.world_id <> OLD.world_id
        OR NEW.nation_id <> OLD.nation_id
        OR NEW.monster_definition_id <> OLD.monster_definition_id
        OR NEW.first_killed_turn <> OLD.first_killed_turn
        OR NEW.kill_count <> OLD.kill_count + 1
        OR NEW.last_killed_turn < OLD.last_killed_turn
        OR NEW.version <> OLD.version + 1
    ) THEN
        RAISE EXCEPTION 'monster kill stat updates must be one atomic increment';
    END IF;
    RETURN NEW;
END;
$$;


--
-- Name: validate_surface_ship_cell_mutation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.validate_surface_ship_cell_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  ship_world_id bigint;
  cell_world_id bigint;
  cell_map_space_key varchar;
  cell_terrain_key varchar;
  cell_facility_id bigint;
  facility_visibility_policy varchar;
  facility_disguise_terrain_key varchar;
BEGIN
  IF to_regclass('public.ships') IS NULL THEN
    RETURN NEW;
  END IF;

  SELECT world_id INTO ship_world_id
    FROM ships
   WHERE map_cell_id = OLD.id
     AND state = 'active'
   FOR UPDATE;
  IF ship_world_id IS NULL THEN
    RETURN NEW;
  END IF;

  SELECT space.world_id, space.key, terrain.key, NEW.facility_definition_id,
         facility.visibility_policy, facility.disguise_terrain_key
    INTO cell_world_id, cell_map_space_key, cell_terrain_key, cell_facility_id,
         facility_visibility_policy, facility_disguise_terrain_key
    FROM map_spaces space
    JOIN terrain_definitions terrain ON terrain.id = NEW.terrain_definition_id
    LEFT JOIN facility_definitions facility ON facility.id = NEW.facility_definition_id
   WHERE space.id = NEW.map_space_id;

  IF cell_world_id IS NULL OR cell_world_id <> ship_world_id OR cell_map_space_key <> 'surface'
     OR cell_terrain_key <> 'sea'
     OR (cell_facility_id IS NOT NULL
       AND (facility_visibility_policy IS DISTINCT FROM 'disguised'
         OR facility_disguise_terrain_key IS DISTINCT FROM 'sea')) THEN
    RAISE EXCEPTION 'An occupied Ship cell must retain its Surface deep-sea coexistence contract.';
  END IF;

  RETURN NEW;
END;
$$;


--
-- Name: validate_surface_ship_identity(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.validate_surface_ship_identity() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
  nation_world_id bigint;
  nation_state varchar;
  world_ruleset_id bigint;
  ship_rules jsonb;
  ship_definition jsonb;
  definition_player_buildable boolean;
  type_capacity integer;
  cell_world_id bigint;
  cell_map_space_key varchar;
  cell_terrain_key varchar;
  cell_facility_id bigint;
  facility_visibility_policy varchar;
  facility_disguise_terrain_key varchar;
BEGIN
  SELECT ruleset_version_id INTO world_ruleset_id
    FROM worlds
   WHERE id = NEW.world_id;
  SELECT settings -> 'surface_ships' INTO ship_rules
    FROM ruleset_versions
   WHERE id = NEW.ruleset_version_id;
  IF world_ruleset_id IS NULL OR ship_rules IS NULL THEN
    RAISE EXCEPTION 'Ship ruleset provenance must reference a World and authored Surface Ship contract.';
  END IF;
  IF TG_OP = 'INSERT' AND NEW.ruleset_version_id <> world_ruleset_id THEN
    RAISE EXCEPTION 'A new Ship must bind the current World Ruleset snapshot.';
  END IF;
  IF TG_OP = 'UPDATE' AND NEW.ruleset_version_id <> OLD.ruleset_version_id THEN
    RAISE EXCEPTION 'Ship Ruleset provenance is immutable.';
  END IF;
  ship_definition := ship_rules -> 'definitions' -> NEW.ship_type_key;
  IF ship_definition IS NULL OR jsonb_typeof(ship_definition) <> 'object' THEN
    RAISE EXCEPTION 'Ship type must be authored by its Ruleset snapshot.';
  END IF;
  IF NEW.max_hp <> (ship_definition ->> 'maximum_hp')::integer THEN
    RAISE EXCEPTION 'Ship maximum HP must match its Ruleset snapshot.';
  END IF;
  IF ship_definition ? 'player_buildable' THEN
    IF jsonb_typeof(ship_definition -> 'player_buildable') <> 'boolean' THEN
      RAISE EXCEPTION 'Ship ownership contract must be authored as a boolean.';
    END IF;
    definition_player_buildable := (ship_definition ->> 'player_buildable')::boolean;
  ELSE
    definition_player_buildable := true;
  END IF;
  IF NEW.nation_id IS NULL AND definition_player_buildable THEN
    RAISE EXCEPTION 'A Player-buildable Ship must belong to a Nation.';
  END IF;
  IF NEW.nation_id IS NOT NULL AND NOT definition_player_buildable THEN
    RAISE EXCEPTION 'An NPC-only Ship cannot belong to a Nation.';
  END IF;
  type_capacity := (ship_rules ->> 'capacity_per_type')::integer;
  IF type_capacity IS NULL OR type_capacity < 1 THEN
    RAISE EXCEPTION 'Ship type capacity must be authored by its Ruleset snapshot.';
  END IF;

  IF NEW.nation_id IS NOT NULL THEN
    SELECT world_id, state INTO nation_world_id, nation_state
      FROM nations
     WHERE id = NEW.nation_id
     FOR UPDATE;
    IF nation_world_id IS NULL OR nation_world_id <> NEW.world_id THEN
      RAISE EXCEPTION 'Ship Nation must belong to the same World.';
    END IF;
  END IF;

  IF NEW.state = 'active' THEN
    PERFORM 1 FROM map_cells WHERE id = NEW.map_cell_id FOR UPDATE;
    SELECT space.world_id, space.key, terrain.key, cell.facility_definition_id,
           facility.visibility_policy, facility.disguise_terrain_key
      INTO cell_world_id, cell_map_space_key, cell_terrain_key, cell_facility_id,
           facility_visibility_policy, facility_disguise_terrain_key
      FROM map_cells cell
      JOIN map_spaces space ON space.id = cell.map_space_id
      JOIN terrain_definitions terrain ON terrain.id = cell.terrain_definition_id
      LEFT JOIN facility_definitions facility ON facility.id = cell.facility_definition_id
     WHERE cell.id = NEW.map_cell_id;
    IF cell_world_id IS NULL OR cell_world_id <> NEW.world_id OR cell_map_space_key <> 'surface' THEN
      RAISE EXCEPTION 'An active Ship must occupy a Surface cell in its World.';
    END IF;
    IF cell_terrain_key <> 'sea' THEN
      RAISE EXCEPTION 'An active Ship must occupy deep sea.';
    END IF;
    IF cell_facility_id IS NOT NULL
       AND (facility_visibility_policy IS DISTINCT FROM 'disguised'
         OR facility_disguise_terrain_key IS DISTINCT FROM 'sea') THEN
      RAISE EXCEPTION 'A Ship may coexist only with a facility canonically disguised as sea.';
    END IF;
    IF EXISTS (
      SELECT 1 FROM monster_occupancies occupancy WHERE occupancy.map_cell_id = NEW.map_cell_id
    ) THEN
      RAISE EXCEPTION 'A Ship cannot share a cell with a Monster.';
    END IF;
    IF NEW.nation_id IS NOT NULL AND nation_state = 'abandoned' THEN
      RAISE EXCEPTION 'An abandoned Nation cannot own an active Ship.';
    END IF;
    IF NEW.nation_id IS NOT NULL AND (
      SELECT count(*)
        FROM ships ship
       WHERE ship.nation_id = NEW.nation_id
         AND ship.ship_type_key = NEW.ship_type_key
         AND ship.state = 'active'
         AND ship.id IS DISTINCT FROM NEW.id
    ) >= type_capacity THEN
      RAISE EXCEPTION 'A Nation exceeded the active Ship capacity authored by its Ruleset snapshot.';
    END IF;
  END IF;

  RETURN NEW;
END;
$$;


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: announcements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.announcements (
    id bigint NOT NULL,
    title character varying(160) NOT NULL,
    body text NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    deleted_at timestamp(0) with time zone,
    body_format character varying(255) DEFAULT 'plain_text'::character varying NOT NULL,
    CONSTRAINT announcements_body_format_check CHECK (((body_format)::text = ANY ((ARRAY['plain_text'::character varying, 'markdown'::character varying])::text[])))
);


--
-- Name: announcements_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.announcements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: announcements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.announcements_id_seq OWNED BY public.announcements.id;


--
-- Name: auction_bids; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auction_bids (
    id bigint NOT NULL,
    auction_listing_id bigint NOT NULL,
    bidder_nation_id bigint NOT NULL,
    amount bigint NOT NULL,
    status character varying(16) DEFAULT 'highest'::character varying NOT NULL,
    placed_turn bigint NOT NULL,
    refunded_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT auction_bids_amount_check CHECK ((amount > 0)),
    CONSTRAINT auction_bids_status_check CHECK ((((status)::text = ANY (ARRAY[('highest'::character varying)::text, ('refunded'::character varying)::text, ('won'::character varying)::text])) AND ((((status)::text = 'refunded'::text) AND (refunded_at IS NOT NULL)) OR (((status)::text <> 'refunded'::text) AND (refunded_at IS NULL)))))
);


--
-- Name: auction_bids_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.auction_bids_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auction_bids_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.auction_bids_id_seq OWNED BY public.auction_bids.id;


--
-- Name: auction_listings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auction_listings (
    id bigint NOT NULL,
    world_id bigint NOT NULL,
    seller_type character varying(32) NOT NULL,
    seller_nation_id bigint,
    product_type character varying(16) NOT NULL,
    resource_definition_id bigint,
    secretary_item_instance_id bigint,
    item_key character varying(64),
    item_level integer,
    quantity bigint,
    start_price bigint NOT NULL,
    current_price bigint,
    highest_bidder_nation_id bigint,
    bid_count integer DEFAULT 0 NOT NULL,
    duration_turns smallint NOT NULL,
    started_turn bigint NOT NULL,
    ends_turn bigint NOT NULL,
    auto_relist boolean DEFAULT false NOT NULL,
    relist_count integer DEFAULT 0 NOT NULL,
    status character varying(16) DEFAULT 'active'::character varying NOT NULL,
    completed_turn bigint,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    original_secretary_item_instance_id bigint,
    CONSTRAINT auction_listings_bid_state_check CHECK ((((bid_count = 0) AND (current_price IS NULL) AND (highest_bidder_nation_id IS NULL)) OR ((bid_count > 0) AND (current_price IS NOT NULL) AND (highest_bidder_nation_id IS NOT NULL)))),
    CONSTRAINT auction_listings_npc_relist_check CHECK ((((seller_type)::text = 'nation'::text) OR (auto_relist = false))),
    CONSTRAINT auction_listings_price_check CHECK (((start_price > 0) AND ((current_price IS NULL) OR (current_price >= start_price)))),
    CONSTRAINT auction_listings_product_check CHECK (((((product_type)::text = 'resource'::text) AND (resource_definition_id IS NOT NULL) AND (secretary_item_instance_id IS NULL) AND (original_secretary_item_instance_id IS NULL) AND (item_key IS NULL) AND (item_level IS NULL) AND (quantity IS NOT NULL) AND (quantity > 0)) OR (((product_type)::text = 'item'::text) AND (resource_definition_id IS NULL) AND (quantity IS NULL) AND (item_key IS NOT NULL) AND (item_level IS NOT NULL) AND (item_level > 0) AND ((((seller_type)::text = 'nation'::text) AND (original_secretary_item_instance_id IS NOT NULL) AND ((secretary_item_instance_id IS NULL) OR (secretary_item_instance_id = original_secretary_item_instance_id)) AND (((status)::text <> 'active'::text) OR (secretary_item_instance_id IS NOT NULL))) OR (((seller_type)::text = 'hakoniwa_federation'::text) AND (secretary_item_instance_id IS NULL) AND (original_secretary_item_instance_id IS NULL)))))),
    CONSTRAINT auction_listings_seller_check CHECK (((((seller_type)::text = 'nation'::text) AND (seller_nation_id IS NOT NULL)) OR (((seller_type)::text = 'hakoniwa_federation'::text) AND (seller_nation_id IS NULL)))),
    CONSTRAINT auction_listings_status_check CHECK ((((status)::text = ANY (ARRAY[('active'::character varying)::text, ('cancelled'::character varying)::text, ('sold'::character varying)::text, ('expired'::character varying)::text])) AND ((((status)::text = 'active'::text) AND (completed_turn IS NULL)) OR (((status)::text <> 'active'::text) AND (completed_turn IS NOT NULL))))),
    CONSTRAINT auction_listings_turn_check CHECK (((duration_turns > 0) AND (ends_turn = (started_turn + duration_turns))))
);


--
-- Name: auction_listings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.auction_listings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auction_listings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.auction_listings_id_seq OWNED BY public.auction_listings.id;


--
-- Name: audit_events; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_events (
    id bigint NOT NULL,
    actor_user_id bigint,
    event_type character varying(255) NOT NULL,
    subject_type character varying(255),
    subject_id bigint,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    occurred_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    world_id bigint,
    turn bigint,
    nation_id bigint,
    x integer,
    y integer,
    message text,
    visibility character varying(16) DEFAULT 'admin'::character varying NOT NULL,
    severity character varying(16) DEFAULT 'info'::character varying NOT NULL,
    CONSTRAINT audit_events_severity_check CHECK (((severity)::text = ANY (ARRAY[('info'::character varying)::text, ('warning'::character varying)::text, ('critical'::character varying)::text]))),
    CONSTRAINT audit_events_visibility_check CHECK (((visibility)::text = ANY (ARRAY[('public'::character varying)::text, ('nation'::character varying)::text, ('private'::character varying)::text, ('admin'::character varying)::text])))
);


--
-- Name: audit_events_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.audit_events_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audit_events_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.audit_events_id_seq OWNED BY public.audit_events.id;


--
-- Name: auth_identities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.auth_identities (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    provider character varying(32) NOT NULL,
    provider_user_id character varying(191) NOT NULL,
    display_name character varying(255),
    avatar_url character varying(2048),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: auth_identities_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.auth_identities_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: auth_identities_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.auth_identities_id_seq OWNED BY public.auth_identities.id;


--
-- Name: buried_treasures; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.buried_treasures (
    id bigint NOT NULL,
    world_id bigint NOT NULL,
    map_cell_id bigint NOT NULL,
    source character varying(32) NOT NULL,
    reward_snapshot jsonb NOT NULL,
    created_turn bigint NOT NULL,
    state character varying(16) DEFAULT 'active'::character varying NOT NULL,
    resolved_by_nation_id bigint,
    resolved_turn bigint,
    resolution_reason character varying(48),
    resolved_at timestamp(0) with time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT buried_treasures_reward_check CHECK ((jsonb_typeof(reward_snapshot) = 'object'::text)),
    CONSTRAINT buried_treasures_source_check CHECK (((source)::text = ANY ((ARRAY['pirate_sink'::character varying, 'treasure_ship_sink'::character varying, 'meteor'::character varying, 'huge_meteor'::character varying, 'natural'::character varying])::text[]))),
    CONSTRAINT buried_treasures_state_check CHECK (((((state)::text = 'active'::text) AND (resolved_by_nation_id IS NULL) AND (resolved_turn IS NULL) AND (resolution_reason IS NULL) AND (resolved_at IS NULL)) OR (((state)::text = 'collected'::text) AND (resolved_by_nation_id IS NOT NULL) AND (resolved_turn IS NOT NULL) AND ((resolution_reason)::text = 'collected'::text) AND (resolved_at IS NOT NULL)) OR (((state)::text = 'removed'::text) AND (resolved_by_nation_id IS NULL) AND (resolved_turn IS NOT NULL) AND (resolution_reason IS NOT NULL) AND (resolved_at IS NOT NULL)))),
    CONSTRAINT buried_treasures_turn_check CHECK (((created_turn >= 1) AND ((resolved_turn IS NULL) OR (resolved_turn >= created_turn))))
);


--
-- Name: buried_treasures_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.buried_treasures_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: buried_treasures_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.buried_treasures_id_seq OWNED BY public.buried_treasures.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration bigint NOT NULL
);


--
-- Name: command_definitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.command_definitions (
    id bigint NOT NULL,
    ruleset_version_id bigint NOT NULL,
    key character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    description text NOT NULL,
    target_type character varying(255) NOT NULL,
    target_terrain_keys jsonb DEFAULT '[]'::jsonb NOT NULL,
    target_facility_keys jsonb DEFAULT '[]'::jsonb NOT NULL,
    requires_empty_facility boolean DEFAULT false NOT NULL,
    cost_money bigint DEFAULT '0'::bigint NOT NULL,
    required_resources jsonb DEFAULT '{}'::jsonb NOT NULL,
    execution_phase character varying(255) NOT NULL,
    result_terrain_key character varying(255),
    result_facility_key character varying(255),
    enabled boolean DEFAULT true NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: command_definitions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.command_definitions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: command_definitions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.command_definitions_id_seq OWNED BY public.command_definitions.id;


--
-- Name: compensation_grant_claims; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.compensation_grant_claims (
    id bigint NOT NULL,
    compensation_grant_id bigint NOT NULL,
    user_id bigint NOT NULL,
    request_key uuid NOT NULL,
    result jsonb NOT NULL,
    created_at timestamp(0) with time zone NOT NULL
);


--
-- Name: compensation_grant_claims_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.compensation_grant_claims_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: compensation_grant_claims_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.compensation_grant_claims_id_seq OWNED BY public.compensation_grant_claims.id;


--
-- Name: compensation_grant_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.compensation_grant_items (
    id bigint NOT NULL,
    compensation_grant_id bigint NOT NULL,
    asset_key character varying(32) NOT NULL,
    amount bigint NOT NULL,
    claimed_amount bigint DEFAULT '0'::bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT compensation_grant_items_amount_check CHECK (((amount > 0) AND (claimed_amount >= 0) AND (claimed_amount <= amount))),
    CONSTRAINT compensation_grant_items_asset_check CHECK (((asset_key)::text = ANY ((ARRAY['money'::character varying, 'wheat'::character varying, 'fish'::character varying, 'meat'::character varying, 'oil'::character varying, 'paradox'::character varying, 'skip_ticket'::character varying, 'underground_g'::character varying])::text[])))
);


--
-- Name: compensation_grant_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.compensation_grant_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: compensation_grant_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.compensation_grant_items_id_seq OWNED BY public.compensation_grant_items.id;


--
-- Name: compensation_grants; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.compensation_grants (
    id bigint NOT NULL,
    world_id bigint NOT NULL,
    nation_id bigint,
    recipient_user_id bigint NOT NULL,
    grant_key character varying(180) NOT NULL,
    operator_identifier character varying(120) NOT NULL,
    reason text NOT NULL,
    status character varying(16) DEFAULT 'pending'::character varying NOT NULL,
    claimed_at timestamp(0) with time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    expires_at timestamp with time zone NOT NULL,
    CONSTRAINT compensation_grants_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'partial'::character varying, 'claimed'::character varying, 'expired'::character varying])::text[])))
);


--
-- Name: compensation_grants_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.compensation_grants_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: compensation_grants_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.compensation_grants_id_seq OWNED BY public.compensation_grants.id;


--
-- Name: facility_definitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.facility_definitions (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    asset_key character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    enabled boolean DEFAULT true NOT NULL,
    build_command_key character varying(255),
    visibility_policy character varying(255) DEFAULT 'public'::character varying NOT NULL,
    disguise_terrain_key character varying(255),
    disguise_asset_key character varying(255),
    scale_unit_people integer,
    initial_scale integer,
    scale_increment integer,
    maximum_scale integer,
    workforce_per_scale_people integer,
    production_definition_key character varying(255),
    buildable_terrain_keys jsonb DEFAULT '[]'::jsonb NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    disguise_ownership_policy character varying(255)
);


--
-- Name: facility_definitions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.facility_definitions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: facility_definitions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.facility_definitions_id_seq OWNED BY public.facility_definitions.id;


--
-- Name: guide_conversation_topics; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.guide_conversation_topics (
    id bigint NOT NULL,
    initial_line text NOT NULL,
    choice_1 text NOT NULL,
    reply_1 text NOT NULL,
    choice_2 text,
    reply_2 text,
    choice_3 text,
    reply_3 text,
    unlock_key character varying(80) DEFAULT 'always'::character varying NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    created_by_user_id bigint NOT NULL,
    updated_by_user_id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT guide_conversation_topics_choice_2_pair_check CHECK (((choice_2 IS NULL) = (reply_2 IS NULL))),
    CONSTRAINT guide_conversation_topics_choice_3_pair_check CHECK (((choice_3 IS NULL) = (reply_3 IS NULL)))
);


--
-- Name: guide_conversation_topics_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.guide_conversation_topics_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: guide_conversation_topics_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.guide_conversation_topics_id_seq OWNED BY public.guide_conversation_topics.id;


--
-- Name: inquiries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inquiries (
    id bigint NOT NULL,
    submission_key uuid NOT NULL,
    user_id bigint NOT NULL,
    world_id bigint NOT NULL,
    nation_id bigint,
    submitted_turn bigint NOT NULL,
    application_version character varying(32) NOT NULL,
    category character varying(32) NOT NULL,
    subject character varying(160) NOT NULL,
    body text NOT NULL,
    attachment_token character varying(64),
    attachment_path character varying(96),
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT inquiries_attachment_pair_check CHECK ((((attachment_token IS NULL) AND (attachment_path IS NULL)) OR ((attachment_token IS NOT NULL) AND (attachment_path IS NOT NULL)))),
    CONSTRAINT inquiries_category_check CHECK (((category)::text = ANY (ARRAY[('bug'::character varying)::text, ('request'::character varying)::text, ('idea'::character varying)::text, ('secretary_fan_art'::character varying)::text, ('other'::character varying)::text])))
);


--
-- Name: inquiries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.inquiries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: inquiries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.inquiries_id_seq OWNED BY public.inquiries.id;


--
-- Name: island_messages; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.island_messages (
    id bigint NOT NULL,
    public_id uuid NOT NULL,
    world_id bigint NOT NULL,
    target_nation_id bigint NOT NULL,
    author_user_id bigint NOT NULL,
    author_kind character varying(16) NOT NULL,
    author_nation_id bigint,
    secret_sender_nation_id bigint,
    message_type character varying(16) NOT NULL,
    body text NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT island_messages_body_length_check CHECK (((char_length(body) >= 1) AND (char_length(body) <= 140))),
    CONSTRAINT island_messages_type_shape_check CHECK (((((message_type)::text = 'public'::text) AND (secret_sender_nation_id IS NULL) AND ((((author_kind)::text = 'visitor'::text) AND (author_nation_id IS NULL)) OR (((author_kind)::text = 'nation'::text) AND (author_nation_id IS NOT NULL)))) OR (((message_type)::text = 'secret'::text) AND ((author_kind)::text = 'nation'::text) AND (author_nation_id IS NOT NULL) AND (secret_sender_nation_id IS NOT NULL) AND (secret_sender_nation_id = author_nation_id) AND (secret_sender_nation_id <> target_nation_id))))
);


--
-- Name: island_messages_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.island_messages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: island_messages_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.island_messages_id_seq OWNED BY public.island_messages.id;


--
-- Name: map_cells; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.map_cells (
    id bigint NOT NULL,
    map_space_id bigint NOT NULL,
    map_chunk_id bigint NOT NULL,
    terrain_definition_id bigint NOT NULL,
    facility_definition_id bigint,
    owner_nation_id bigint,
    population bigint DEFAULT '0'::bigint NOT NULL,
    state character varying(255) DEFAULT 'generated'::character varying NOT NULL,
    version bigint DEFAULT '1'::bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    terrain_quantity bigint,
    facility_scale integer,
    facility_experience integer,
    facility_operational_state character varying(255),
    x integer NOT NULL,
    y integer NOT NULL,
    chunk_x integer NOT NULL,
    chunk_y integer NOT NULL,
    local_x smallint NOT NULL,
    local_y smallint NOT NULL,
    monument_definition_id bigint,
    monument_design_id bigint
);


--
-- Name: map_cells_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.map_cells_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: map_cells_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.map_cells_id_seq OWNED BY public.map_cells.id;


--
-- Name: map_chunks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.map_chunks (
    id bigint NOT NULL,
    map_space_id bigint NOT NULL,
    version bigint DEFAULT '1'::bigint NOT NULL,
    generated_at timestamp(0) with time zone,
    generator_id character varying(255),
    generator_version character varying(255),
    generation_seed character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    chunk_x integer NOT NULL,
    chunk_y integer NOT NULL
);


--
-- Name: map_chunks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.map_chunks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: map_chunks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.map_chunks_id_seq OWNED BY public.map_chunks.id;


--
-- Name: map_spaces; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.map_spaces (
    id bigint NOT NULL,
    world_id bigint NOT NULL,
    key character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    coordinate_system character varying(255) DEFAULT 'pointy_top_axial'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    min_x integer NOT NULL,
    max_x integer NOT NULL,
    min_y integer NOT NULL,
    max_y integer NOT NULL
);


--
-- Name: map_spaces_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.map_spaces_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: map_spaces_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.map_spaces_id_seq OWNED BY public.map_spaces.id;


--
-- Name: merchant_conversation_topics; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.merchant_conversation_topics (
    id bigint NOT NULL,
    question text NOT NULL,
    answer text NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    created_by_user_id bigint,
    updated_by_user_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: merchant_conversation_topics_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.merchant_conversation_topics_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: merchant_conversation_topics_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.merchant_conversation_topics_id_seq OWNED BY public.merchant_conversation_topics.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: moderation_records; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.moderation_records (
    id bigint NOT NULL,
    operator_identifier character varying(191) NOT NULL,
    category character varying(64) NOT NULL,
    target_type character varying(16) NOT NULL,
    target_id bigint NOT NULL,
    summary text NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    occurred_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT moderation_records_target_type_check CHECK (((target_type)::text = ANY (ARRAY[('nation'::character varying)::text, ('user'::character varying)::text])))
);


--
-- Name: moderation_records_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.moderation_records_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: moderation_records_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.moderation_records_id_seq OWNED BY public.moderation_records.id;


--
-- Name: monster_definitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.monster_definitions (
    id bigint NOT NULL,
    ruleset_version_id bigint NOT NULL,
    key character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    asset_key character varying(255) NOT NULL,
    hardened_asset_key character varying(255),
    base_hp smallint NOT NULL,
    hp_variation smallint NOT NULL,
    skill_key character varying(32) NOT NULL,
    movement_limit integer NOT NULL,
    natural_spawn_tier smallint,
    wreckage_value_money bigint NOT NULL,
    missile_base_experience smallint NOT NULL,
    skill_description character varying(255) NOT NULL,
    visibility character varying(32) NOT NULL,
    movement_terrain_contract jsonb NOT NULL,
    trample_contract jsonb NOT NULL,
    hardening_contract jsonb NOT NULL,
    source_metadata jsonb NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    display_order integer,
    experience_per_damage smallint,
    CONSTRAINT monster_definitions_display_order_non_negative CHECK (((display_order IS NULL) OR (display_order >= 0))),
    CONSTRAINT monster_definitions_experience_per_damage_non_negative CHECK (((experience_per_damage IS NULL) OR (experience_per_damage >= 0))),
    CONSTRAINT monster_definitions_hp_check CHECK (((base_hp >= 1) AND (hp_variation >= 0) AND ((base_hp + hp_variation) <= 32767))),
    CONSTRAINT monster_definitions_skill_check CHECK (((skill_key)::text = ANY (ARRAY[('none'::character varying)::text, ('move_2'::character varying)::text, ('move_9999'::character varying)::text, ('harden_odd'::character varying)::text, ('harden_even'::character varying)::text]))),
    CONSTRAINT monster_definitions_spawn_tier_check CHECK (((natural_spawn_tier IS NULL) OR ((natural_spawn_tier >= 1) AND (natural_spawn_tier <= 4)))),
    CONSTRAINT monster_definitions_visibility_check CHECK (((visibility)::text = 'public'::text))
);


--
-- Name: monster_definitions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.monster_definitions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: monster_definitions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.monster_definitions_id_seq OWNED BY public.monster_definitions.id;


--
-- Name: monster_instances; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.monster_instances (
    id bigint NOT NULL,
    world_id bigint NOT NULL,
    monster_definition_id bigint NOT NULL,
    current_hp smallint NOT NULL,
    spawned_max_hp smallint NOT NULL,
    state character varying(24) DEFAULT 'alive'::character varying NOT NULL,
    spawned_target_turn bigint NOT NULL,
    version bigint DEFAULT '1'::bigint NOT NULL,
    removal_reason character varying(255),
    removed_at timestamp(0) with time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT monster_instances_state_check CHECK (((((state)::text = 'alive'::text) AND ((current_hp >= 1) AND (current_hp <= spawned_max_hp)) AND (removal_reason IS NULL) AND (removed_at IS NULL)) OR (((state)::text = 'killed'::text) AND (current_hp = 0) AND (removal_reason IS NOT NULL) AND (removed_at IS NOT NULL)) OR (((state)::text = 'removed'::text) AND ((current_hp >= 0) AND (current_hp <= spawned_max_hp)) AND (removal_reason IS NOT NULL) AND (removed_at IS NOT NULL)))),
    CONSTRAINT monster_instances_turn_check CHECK ((spawned_target_turn >= 1))
);


--
-- Name: monster_instances_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.monster_instances_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: monster_instances_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.monster_instances_id_seq OWNED BY public.monster_instances.id;


--
-- Name: monster_occupancies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.monster_occupancies (
    id bigint NOT NULL,
    monster_instance_id bigint NOT NULL,
    map_cell_id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: monster_occupancies_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.monster_occupancies_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: monster_occupancies_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.monster_occupancies_id_seq OWNED BY public.monster_occupancies.id;


--
-- Name: monument_definitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.monument_definitions (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    asset_key character varying(255) NOT NULL,
    description text NOT NULL,
    effect_key character varying(255),
    enabled boolean DEFAULT true NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: monument_definitions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.monument_definitions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: monument_definitions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.monument_definitions_id_seq OWNED BY public.monument_definitions.id;


--
-- Name: nation_awards; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.nation_awards (
    id bigint NOT NULL,
    world_id bigint NOT NULL,
    nation_id bigint NOT NULL,
    award_key character varying(64) NOT NULL,
    awarded_turn integer NOT NULL,
    award_occurrence_key character varying(64) NOT NULL,
    created_at timestamp(0) with time zone NOT NULL,
    CONSTRAINT nation_awards_positive_turn CHECK ((awarded_turn >= 1))
);


--
-- Name: nation_awards_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.nation_awards_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: nation_awards_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.nation_awards_id_seq OWNED BY public.nation_awards.id;


--
-- Name: nation_capitals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.nation_capitals (
    id bigint NOT NULL,
    nation_id bigint NOT NULL,
    map_cell_id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    x integer NOT NULL,
    y integer NOT NULL
);


--
-- Name: nation_capitals_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.nation_capitals_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: nation_capitals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.nation_capitals_id_seq OWNED BY public.nation_capitals.id;


--
-- Name: nation_command_queue_bulk_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.nation_command_queue_bulk_requests (
    id bigint NOT NULL,
    nation_command_queue_id bigint CONSTRAINT nation_command_queue_bulk_requ_nation_command_queue_id_not_null NOT NULL,
    request_key uuid NOT NULL,
    action character varying(255) NOT NULL,
    "position" integer NOT NULL,
    candidate_count integer NOT NULL,
    inserted_count integer NOT NULL,
    truncated_count integer NOT NULL,
    created_at timestamp(0) with time zone NOT NULL
);


--
-- Name: nation_command_queue_bulk_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.nation_command_queue_bulk_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: nation_command_queue_bulk_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.nation_command_queue_bulk_requests_id_seq OWNED BY public.nation_command_queue_bulk_requests.id;


--
-- Name: nation_command_queue_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.nation_command_queue_items (
    id bigint NOT NULL,
    nation_command_queue_id bigint NOT NULL,
    command_definition_id bigint,
    queue_position integer,
    parameters jsonb DEFAULT '{}'::jsonb NOT NULL,
    status character varying(255) DEFAULT 'queued'::character varying NOT NULL,
    queued_by_membership_id bigint NOT NULL,
    request_key uuid NOT NULL,
    queued_at timestamp(0) with time zone NOT NULL,
    cancelled_at timestamp(0) with time zone,
    execution_started_at timestamp(0) with time zone,
    execution_completed_at timestamp(0) with time zone,
    execution_failed_at timestamp(0) with time zone,
    failure_code character varying(255),
    failure_metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    target_x integer,
    target_y integer,
    quantity smallint DEFAULT 1 NOT NULL,
    request_fingerprint character(64),
    request_ruleset_version_id bigint,
    target_context character varying(32) DEFAULT 'surface_cell'::character varying NOT NULL,
    target_layer smallint,
    target_slot_index smallint,
    underground_command_key character varying(64),
    paradox_execution_count integer DEFAULT 0 NOT NULL,
    CONSTRAINT nation_command_queue_items_quantity_check CHECK (((quantity >= 1) AND (quantity <= 99))),
    CONSTRAINT nation_command_queue_items_request_fingerprint_check CHECK (((request_fingerprint IS NULL) OR (request_fingerprint ~ '^[0-9a-f]{64}$'::text))),
    CONSTRAINT nation_command_queue_items_target_context_check CHECK (((((target_context)::text = 'surface_cell'::text) AND (command_definition_id IS NOT NULL) AND (underground_command_key IS NULL) AND (target_x IS NOT NULL) AND (target_y IS NOT NULL) AND (target_layer IS NULL) AND (target_slot_index IS NULL)) OR (((target_context)::text = 'underground_slot'::text) AND (command_definition_id IS NULL) AND ((underground_command_key)::text = ANY ((ARRAY['build_underground_city'::character varying, 'build_underground_farm'::character varying, 'build_underground_factory'::character varying, 'build_underground_missile_base'::character varying, 'remove_underground_facility'::character varying])::text[])) AND (target_x IS NULL) AND (target_y IS NULL) AND (target_layer >= 1) AND ((target_slot_index >= 0) AND (target_slot_index <= 3)))))
);


--
-- Name: nation_command_queue_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.nation_command_queue_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: nation_command_queue_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.nation_command_queue_items_id_seq OWNED BY public.nation_command_queue_items.id;


--
-- Name: nation_command_queues; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.nation_command_queues (
    id bigint NOT NULL,
    nation_id bigint NOT NULL,
    map_space_id bigint NOT NULL,
    version bigint DEFAULT '1'::bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: nation_command_queues_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.nation_command_queues_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: nation_command_queues_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.nation_command_queues_id_seq OWNED BY public.nation_command_queues.id;


--
-- Name: nation_creation_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.nation_creation_requests (
    id bigint NOT NULL,
    request_key uuid NOT NULL,
    user_id bigint NOT NULL,
    world_id bigint NOT NULL,
    nation_id bigint,
    status character varying(255) NOT NULL,
    generation_seed character varying(255) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    reserved_x integer,
    reserved_y integer
);


--
-- Name: nation_creation_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.nation_creation_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: nation_creation_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.nation_creation_requests_id_seq OWNED BY public.nation_creation_requests.id;


--
-- Name: nation_memberships; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.nation_memberships (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    world_id bigint NOT NULL,
    nation_id bigint NOT NULL,
    role character varying(255) DEFAULT 'owner'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: nation_memberships_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.nation_memberships_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: nation_memberships_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.nation_memberships_id_seq OWNED BY public.nation_memberships.id;


--
-- Name: nation_monster_cycle_seed_requirements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.nation_monster_cycle_seed_requirements (
    id bigint NOT NULL,
    world_id bigint NOT NULL,
    nation_id bigint NOT NULL,
    cycle_start_turn integer CONSTRAINT nation_monster_cycle_seed_requirement_cycle_start_turn_not_null NOT NULL,
    cycle_end_turn integer NOT NULL,
    completed_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone NOT NULL,
    CONSTRAINT nation_monster_cycle_seed_requirement_valid_interval CHECK (((cycle_start_turn >= 1) AND (mod((cycle_start_turn - 1), 100) = 0) AND (cycle_end_turn = (cycle_start_turn + 99))))
);


--
-- Name: nation_monster_cycle_seed_requirements_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.nation_monster_cycle_seed_requirements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: nation_monster_cycle_seed_requirements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.nation_monster_cycle_seed_requirements_id_seq OWNED BY public.nation_monster_cycle_seed_requirements.id;


--
-- Name: nation_monster_cycle_stats; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.nation_monster_cycle_stats (
    id bigint NOT NULL,
    world_id bigint NOT NULL,
    nation_id bigint NOT NULL,
    cycle_start_turn integer NOT NULL,
    cycle_end_turn integer NOT NULL,
    kill_count bigint DEFAULT '0'::bigint NOT NULL,
    version bigint DEFAULT '1'::bigint NOT NULL,
    seeded_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT nation_monster_cycle_stats_valid_interval CHECK (((cycle_start_turn >= 1) AND (mod((cycle_start_turn - 1), 100) = 0) AND (cycle_end_turn = (cycle_start_turn + 99)) AND (kill_count >= 0) AND (version >= 1)))
);


--
-- Name: nation_monster_cycle_stats_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.nation_monster_cycle_stats_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: nation_monster_cycle_stats_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.nation_monster_cycle_stats_id_seq OWNED BY public.nation_monster_cycle_stats.id;


--
-- Name: nation_monster_kill_stats; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.nation_monster_kill_stats (
    id bigint NOT NULL,
    world_id bigint NOT NULL,
    nation_id bigint NOT NULL,
    monster_definition_id bigint NOT NULL,
    kill_count bigint NOT NULL,
    first_killed_turn bigint NOT NULL,
    last_killed_turn bigint NOT NULL,
    version bigint DEFAULT '1'::bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT nation_monster_kill_stats_count_check CHECK ((kill_count >= 1)),
    CONSTRAINT nation_monster_kill_stats_turn_check CHECK (((first_killed_turn >= 1) AND (last_killed_turn >= first_killed_turn))),
    CONSTRAINT nation_monster_kill_stats_version_check CHECK ((version >= 1))
);


--
-- Name: nation_monster_kill_stats_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.nation_monster_kill_stats_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: nation_monster_kill_stats_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.nation_monster_kill_stats_id_seq OWNED BY public.nation_monster_kill_stats.id;


--
-- Name: nation_resource_sale_policies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.nation_resource_sale_policies (
    id bigint NOT NULL,
    nation_id bigint NOT NULL,
    resource_definition_id bigint NOT NULL,
    policy character varying(255) DEFAULT 'stockpile'::character varying NOT NULL,
    keep_amount bigint,
    version bigint DEFAULT '1'::bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: nation_resource_sale_policies_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.nation_resource_sale_policies_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: nation_resource_sale_policies_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.nation_resource_sale_policies_id_seq OWNED BY public.nation_resource_sale_policies.id;


--
-- Name: nation_resources; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.nation_resources (
    id bigint NOT NULL,
    nation_id bigint NOT NULL,
    resource_definition_id bigint NOT NULL,
    amount bigint DEFAULT '0'::bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: nation_resources_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.nation_resources_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: nation_resources_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.nation_resources_id_seq OWNED BY public.nation_resources.id;


--
-- Name: nation_underground_facilities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.nation_underground_facilities (
    id bigint NOT NULL,
    nation_id bigint NOT NULL,
    ruleset_version_id bigint NOT NULL,
    layer smallint NOT NULL,
    slot_index smallint NOT NULL,
    facility_key character varying(64) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT nation_underground_facilities_key_check CHECK (((facility_key)::text = ANY ((ARRAY['underground_city'::character varying, 'underground_farm'::character varying, 'underground_factory'::character varying, 'underground_missile_base'::character varying])::text[]))),
    CONSTRAINT nation_underground_facilities_layer_check CHECK ((layer >= 1)),
    CONSTRAINT nation_underground_facilities_slot_check CHECK (((slot_index >= 0) AND (slot_index <= 3)))
);


--
-- Name: nation_underground_facilities_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.nation_underground_facilities_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: nation_underground_facilities_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.nation_underground_facilities_id_seq OWNED BY public.nation_underground_facilities.id;


--
-- Name: nations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.nations (
    id bigint NOT NULL,
    world_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    money bigint DEFAULT '100'::bigint NOT NULL,
    state character varying(255) DEFAULT 'active'::character varying NOT NULL,
    state_reason character varying(255),
    state_started_turn bigint,
    resume_at_turn bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    nation_number integer NOT NULL,
    owner_name character varying(30) DEFAULT ''::character varying NOT NULL,
    profile_comment character varying(100) DEFAULT ''::character varying NOT NULL,
    idle_counter bigint DEFAULT 2000 NOT NULL,
    registered_turn bigint DEFAULT '1'::bigint NOT NULL,
    karma integer DEFAULT 0 NOT NULL,
    population_high_water bigint DEFAULT '0'::bigint NOT NULL,
    CONSTRAINT nations_idle_counter_check CHECK ((idle_counter >= 0)),
    CONSTRAINT nations_karma_range_check CHECK (((karma >= '-30'::integer) AND (karma <= 100))),
    CONSTRAINT nations_lifecycle_context_check CHECK (((((state)::text = 'active'::text) AND (state_reason IS NULL) AND (state_started_turn IS NULL) AND (resume_at_turn IS NULL)) OR (((state)::text = 'dormant'::text) AND ((state_reason)::text = ANY (ARRAY[('idle'::character varying)::text, ('collapse'::character varying)::text, ('manual'::character varying)::text])) AND (state_started_turn IS NOT NULL) AND ((((state_reason)::text = 'manual'::text) AND (resume_at_turn IS NOT NULL) AND (resume_at_turn > state_started_turn)) OR (((state_reason)::text <> 'manual'::text) AND (resume_at_turn IS NULL)))) OR (((state)::text = 'recovery'::text) AND (state_reason IS NULL) AND (state_started_turn IS NOT NULL) AND (resume_at_turn IS NOT NULL) AND (resume_at_turn > state_started_turn)) OR (((state)::text = 'abandoned'::text) AND (state_reason IS NULL) AND (state_started_turn IS NULL) AND (resume_at_turn IS NULL)))),
    CONSTRAINT nations_lifecycle_state_check CHECK (((state)::text = ANY (ARRAY[('active'::character varying)::text, ('dormant'::character varying)::text, ('recovery'::character varying)::text, ('abandoned'::character varying)::text]))),
    CONSTRAINT nations_nation_number_positive CHECK ((nation_number > 0)),
    CONSTRAINT nations_registered_turn_check CHECK ((registered_turn >= 1))
);


--
-- Name: nations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.nations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: nations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.nations_id_seq OWNED BY public.nations.id;


--
-- Name: production_definitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.production_definitions (
    id bigint NOT NULL,
    ruleset_version_id bigint NOT NULL,
    key character varying(255) NOT NULL,
    facility_definition_id bigint NOT NULL,
    output_resource_definition_id bigint NOT NULL,
    production_per_scale numeric(16,4) NOT NULL,
    required_workforce_per_scale integer NOT NULL,
    operating_condition character varying(255) NOT NULL,
    price_reference character varying(255) NOT NULL,
    enabled boolean DEFAULT true NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: production_definitions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.production_definitions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: production_definitions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.production_definitions_id_seq OWNED BY public.production_definitions.id;


--
-- Name: resource_definitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.resource_definitions (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    category character varying(255) NOT NULL,
    unit character varying(255) NOT NULL,
    nutrition_per_unit numeric(12,4),
    storable boolean DEFAULT true NOT NULL,
    tradable boolean DEFAULT false NOT NULL,
    sale_price_key character varying(255),
    sort_order integer DEFAULT 0 NOT NULL,
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    unit_label character varying(255)
);


--
-- Name: resource_definitions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.resource_definitions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: resource_definitions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.resource_definitions_id_seq OWNED BY public.resource_definitions.id;


--
-- Name: ruleset_versions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ruleset_versions (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    version integer NOT NULL,
    settings jsonb NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ruleset_versions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ruleset_versions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ruleset_versions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ruleset_versions_id_seq OWNED BY public.ruleset_versions.id;


--
-- Name: secretaries; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.secretaries (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    name character varying(30),
    named_at timestamp(0) with time zone,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    profile_biography text DEFAULT '全てが謎に包まれた、長耳の秘書。
かつては囚われの身になっていたが島主に救われ、後に才能を買われて秘書となった。
その身に不思議な力を宿している。'::text NOT NULL,
    main_image_path character varying(80),
    main_image_mime_type character varying(32),
    main_image_creation_method character varying(32),
    main_image_credit character varying(160),
    main_image_updated_at timestamp(0) with time zone,
    nickname character varying(6),
    portrait_preference character varying(16) DEFAULT 'full_body'::character varying NOT NULL,
    CONSTRAINT secretaries_main_image_state_check CHECK ((((main_image_path IS NULL) AND (main_image_mime_type IS NULL) AND (main_image_creation_method IS NULL) AND (main_image_credit IS NULL) AND (main_image_updated_at IS NULL)) OR (((main_image_path)::text ~ '^[0-9a-f]{64}\.(png|jpg|webp|gif)$'::text) AND ((main_image_mime_type)::text = ANY (ARRAY[('image/png'::character varying)::text, ('image/jpeg'::character varying)::text, ('image/webp'::character varying)::text, ('image/gif'::character varying)::text])) AND ((main_image_creation_method)::text = ANY (ARRAY[('self_made'::character varying)::text, ('ai_generated'::character varying)::text, ('commissioned_or_permitted'::character varying)::text, ('other'::character varying)::text])) AND ((main_image_credit IS NULL) OR (char_length((main_image_credit)::text) <= 160)) AND (main_image_updated_at IS NOT NULL)))),
    CONSTRAINT secretaries_name_state_check CHECK ((((name IS NULL) AND (named_at IS NULL)) OR ((name IS NOT NULL) AND (named_at IS NOT NULL)))),
    CONSTRAINT secretaries_nickname_check CHECK (((nickname IS NULL) OR ((char_length((nickname)::text) >= 1) AND (char_length((nickname)::text) <= 6)))),
    CONSTRAINT secretaries_portrait_preference_check CHECK (((portrait_preference)::text = ANY ((ARRAY['full_body'::character varying, 'bust'::character varying])::text[]))),
    CONSTRAINT secretaries_profile_biography_length_check CHECK ((char_length(profile_biography) <= 1000))
);


--
-- Name: secretaries_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.secretaries_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: secretaries_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.secretaries_id_seq OWNED BY public.secretaries.id;


--
-- Name: secretary_gacha_draws; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.secretary_gacha_draws (
    id bigint NOT NULL,
    secretary_id bigint NOT NULL,
    request_key uuid NOT NULL,
    ticket_item_instance_id bigint NOT NULL,
    ticket_key character varying(64) NOT NULL,
    ticket_level integer NOT NULL,
    result_snapshot jsonb NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: secretary_gacha_draws_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.secretary_gacha_draws_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: secretary_gacha_draws_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.secretary_gacha_draws_id_seq OWNED BY public.secretary_gacha_draws.id;


--
-- Name: secretary_guide_conversation_totals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.secretary_guide_conversation_totals (
    id bigint NOT NULL,
    secretary_id bigint NOT NULL,
    topics_started bigint DEFAULT '0'::bigint NOT NULL,
    normal_replies bigint DEFAULT '0'::bigint NOT NULL,
    punch_count bigint DEFAULT '0'::bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: secretary_guide_conversation_totals_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.secretary_guide_conversation_totals_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: secretary_guide_conversation_totals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.secretary_guide_conversation_totals_id_seq OWNED BY public.secretary_guide_conversation_totals.id;


--
-- Name: secretary_images; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.secretary_images (
    id bigint NOT NULL,
    secretary_id bigint NOT NULL,
    slot character varying(32) NOT NULL,
    path character varying(80) NOT NULL,
    mime_type character varying(32) NOT NULL,
    creation_method character varying(32) NOT NULL,
    credit character varying(160),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT secretary_images_creation_method_check CHECK (((creation_method)::text = ANY ((ARRAY['self_made'::character varying, 'ai_generated'::character varying, 'commissioned_or_permitted'::character varying, 'other'::character varying])::text[]))),
    CONSTRAINT secretary_images_credit_length_check CHECK (((char_length((credit)::text) >= 1) AND (char_length((credit)::text) <= 160))),
    CONSTRAINT secretary_images_mime_check CHECK (((mime_type)::text = ANY ((ARRAY['image/png'::character varying, 'image/jpeg'::character varying, 'image/webp'::character varying, 'image/gif'::character varying])::text[]))),
    CONSTRAINT secretary_images_path_check CHECK (((path)::text ~ '^[0-9a-f]{64}\.(png|jpg|webp|gif)$'::text)),
    CONSTRAINT secretary_images_slot_check CHECK (((slot)::text = ANY ((ARRAY['icon'::character varying, 'bust'::character varying, 'full_body'::character varying, 'awakening_icon'::character varying, 'awakening_bust'::character varying, 'awakening_full_body'::character varying])::text[])))
);


--
-- Name: secretary_images_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.secretary_images_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: secretary_images_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.secretary_images_id_seq OWNED BY public.secretary_images.id;


--
-- Name: secretary_item_instances; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.secretary_item_instances (
    id bigint NOT NULL,
    secretary_id bigint NOT NULL,
    item_key character varying(64) NOT NULL,
    level integer NOT NULL,
    equipped_slot smallint,
    grant_key character varying(128),
    obtained_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    is_escrowed boolean DEFAULT false NOT NULL,
    resolved_rarity character varying(32),
    resolved_fixed_sale_price_money bigint,
    CONSTRAINT secretary_item_instances_equipped_slot_check CHECK (((equipped_slot IS NULL) OR ((equipped_slot >= 1) AND (equipped_slot <= 5)))),
    CONSTRAINT secretary_item_instances_escrow_equipment_check CHECK (((NOT is_escrowed) OR (equipped_slot IS NULL))),
    CONSTRAINT secretary_item_instances_level_check CHECK ((level >= 1)),
    CONSTRAINT secretary_item_instances_resolved_economics_check CHECK ((((resolved_rarity IS NULL) AND (resolved_fixed_sale_price_money IS NULL)) OR ((resolved_rarity IS NOT NULL) AND (length((resolved_rarity)::text) > 0) AND (resolved_fixed_sale_price_money IS NOT NULL) AND (resolved_fixed_sale_price_money >= 0))))
);


--
-- Name: secretary_item_instances_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.secretary_item_instances_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: secretary_item_instances_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.secretary_item_instances_id_seq OWNED BY public.secretary_item_instances.id;


--
-- Name: secretary_lending_build_snapshots; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.secretary_lending_build_snapshots (
    id bigint NOT NULL,
    secretary_id bigint NOT NULL,
    build_identity character varying(100) NOT NULL,
    source_fingerprint character(64) NOT NULL,
    source_snapshot jsonb NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    projection_cache jsonb,
    CONSTRAINT secretary_lending_build_snapshots_fingerprint_check CHECK ((source_fingerprint ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT secretary_lending_build_snapshots_projection_cache_check CHECK (((projection_cache IS NULL) OR (jsonb_typeof(projection_cache) = 'object'::text)))
);


--
-- Name: secretary_lending_build_snapshots_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.secretary_lending_build_snapshots_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: secretary_lending_build_snapshots_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.secretary_lending_build_snapshots_id_seq OWNED BY public.secretary_lending_build_snapshots.id;


--
-- Name: secretary_lending_daily_rewards; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.secretary_lending_daily_rewards (
    id bigint NOT NULL,
    owner_user_id bigint NOT NULL,
    canonical_day date NOT NULL,
    participation_count integer DEFAULT 0 NOT NULL,
    tickets_awarded integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT secretary_lending_daily_ticket_cap_check CHECK ((tickets_awarded >= 0))
);


--
-- Name: secretary_lending_daily_rewards_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.secretary_lending_daily_rewards_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: secretary_lending_daily_rewards_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.secretary_lending_daily_rewards_id_seq OWNED BY public.secretary_lending_daily_rewards.id;


--
-- Name: secretary_lending_participations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.secretary_lending_participations (
    id bigint NOT NULL,
    underground_battle_id bigint NOT NULL,
    underground_party_member_id bigint CONSTRAINT secretary_lending_participa_underground_party_member_i_not_null NOT NULL,
    secretary_id bigint NOT NULL,
    owner_user_id bigint NOT NULL,
    canonical_day date NOT NULL,
    result character varying(16) NOT NULL,
    ticket_delta integer DEFAULT 0 NOT NULL,
    settled_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT secretary_lending_participation_result_check CHECK (((result)::text = ANY ((ARRAY['victory'::character varying, 'defeat'::character varying, 'withdrawal'::character varying])::text[]))),
    CONSTRAINT secretary_lending_participation_ticket_check CHECK ((ticket_delta = ANY (ARRAY[0, 1])))
);


--
-- Name: secretary_lending_participations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.secretary_lending_participations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: secretary_lending_participations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.secretary_lending_participations_id_seq OWNED BY public.secretary_lending_participations.id;


--
-- Name: secretary_lending_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.secretary_lending_settings (
    id bigint NOT NULL,
    secretary_id bigint NOT NULL,
    is_public boolean DEFAULT false NOT NULL,
    is_available boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: secretary_lending_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.secretary_lending_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: secretary_lending_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.secretary_lending_settings_id_seq OWNED BY public.secretary_lending_settings.id;


--
-- Name: secretary_skills; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.secretary_skills (
    id bigint NOT NULL,
    secretary_id bigint NOT NULL,
    skill_key character varying(255) NOT NULL,
    level integer NOT NULL,
    experience bigint NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    CONSTRAINT secretary_skills_experience_check CHECK ((experience >= 0)),
    CONSTRAINT secretary_skills_key_check CHECK (((skill_key)::text = ANY ((ARRAY['agricultural_policy'::character varying, 'specialty_development'::character varying, 'gold_vein_survey'::character varying, 'forest_management'::character varying, 'final_defense_line'::character varying, 'declining_birthrate_policy'::character varying, 'indomitable'::character varying, 'ship_operations'::character varying, 'navy'::character varying])::text[]))),
    CONSTRAINT secretary_skills_level_check CHECK ((level >= 0))
);


--
-- Name: secretary_skills_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.secretary_skills_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: secretary_skills_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.secretary_skills_id_seq OWNED BY public.secretary_skills.id;


--
-- Name: secretary_surface_states; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.secretary_surface_states (
    secretary_id bigint NOT NULL,
    monster_experience bigint DEFAULT '0'::bigint NOT NULL,
    equipment_version bigint DEFAULT '1'::bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT secretary_surface_equipment_version_check CHECK ((equipment_version >= 1)),
    CONSTRAINT secretary_surface_experience_check CHECK ((monster_experience >= 0))
);


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: ships; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ships (
    id bigint NOT NULL,
    world_id bigint NOT NULL,
    ruleset_version_id bigint NOT NULL,
    nation_id bigint,
    map_cell_id bigint,
    ship_type_key character varying(32) NOT NULL,
    current_hp smallint NOT NULL,
    max_hp smallint NOT NULL,
    heading smallint,
    state character varying(24) DEFAULT 'active'::character varying NOT NULL,
    version bigint DEFAULT '1'::bigint NOT NULL,
    removal_reason character varying(255),
    removed_at timestamp(0) with time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    population bigint,
    CONSTRAINT ships_heading_check CHECK (((heading IS NULL) OR ((heading >= 0) AND (heading <= 5)))),
    CONSTRAINT ships_hp_check CHECK ((((max_hp >= 1) AND (max_hp <= 32767)) AND ((current_hp >= 0) AND (current_hp <= max_hp)))),
    CONSTRAINT ships_ocean_population_check CHECK (((((ship_type_key)::text = 'pirate'::text) AND (nation_id IS NULL) AND (population > 0)) OR (((ship_type_key)::text <> 'pirate'::text) AND (population IS NULL)))),
    CONSTRAINT ships_state_check CHECK (((((state)::text = 'active'::text) AND (map_cell_id IS NOT NULL) AND (current_hp >= 1) AND (removal_reason IS NULL) AND (removed_at IS NULL)) OR (((state)::text = 'removed'::text) AND (map_cell_id IS NULL) AND (removal_reason IS NOT NULL) AND (removed_at IS NOT NULL)))),
    CONSTRAINT ships_version_check CHECK ((version >= 1))
);


--
-- Name: ships_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ships_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ships_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ships_id_seq OWNED BY public.ships.id;


--
-- Name: terrain_definitions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.terrain_definitions (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    asset_key character varying(255) NOT NULL,
    is_water boolean DEFAULT false NOT NULL,
    is_buildable boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    quantity_key character varying(255),
    quantity_label character varying(255),
    quantity_unit character varying(255),
    initial_quantity bigint,
    minimum_quantity bigint,
    maximum_quantity bigint,
    growth_rule_key character varying(255),
    metadata jsonb DEFAULT '{}'::jsonb NOT NULL
);


--
-- Name: terrain_definitions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.terrain_definitions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: terrain_definitions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.terrain_definitions_id_seq OWNED BY public.terrain_definitions.id;


--
-- Name: turn_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.turn_runs (
    id bigint NOT NULL,
    world_id bigint NOT NULL,
    target_turn bigint NOT NULL,
    ruleset_version_id bigint NOT NULL,
    random_seed character(64) NOT NULL,
    source character varying(16) NOT NULL,
    is_dry_run boolean DEFAULT false NOT NULL,
    status character varying(24) NOT NULL,
    attempt_count integer DEFAULT 1 NOT NULL,
    pipeline jsonb DEFAULT '[]'::jsonb NOT NULL,
    phase_results jsonb DEFAULT '[]'::jsonb NOT NULL,
    started_at timestamp(0) with time zone,
    completed_at timestamp(0) with time zone,
    failure_code character varying(255),
    failure_message text,
    failure_context jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: turn_runs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.turn_runs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: turn_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.turn_runs_id_seq OWNED BY public.turn_runs.id;


--
-- Name: underground_battle_image_references; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.underground_battle_image_references (
    id bigint NOT NULL,
    reference_key character varying(64) NOT NULL,
    underground_battle_id bigint,
    retained_until timestamp(0) with time zone NOT NULL,
    path character varying(80) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT underground_battle_image_refs_path_check CHECK (((path)::text ~ '^[0-9a-f]{64}\.(png|jpg|webp|gif)$'::text))
);


--
-- Name: underground_battle_image_references_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.underground_battle_image_references_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: underground_battle_image_references_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.underground_battle_image_references_id_seq OWNED BY public.underground_battle_image_references.id;


--
-- Name: underground_battle_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.underground_battle_logs (
    id bigint NOT NULL,
    underground_battle_id bigint NOT NULL,
    actions jsonb NOT NULL,
    expires_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    presentation jsonb
);


--
-- Name: underground_battle_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.underground_battle_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: underground_battle_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.underground_battle_logs_id_seq OWNED BY public.underground_battle_logs.id;


--
-- Name: underground_battles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.underground_battles (
    id bigint NOT NULL,
    underground_profile_id bigint NOT NULL,
    request_id uuid NOT NULL,
    request_fingerprint character(64) NOT NULL,
    runtime_identity character varying(64) NOT NULL,
    activity_type character varying(16) NOT NULL,
    activity_key character varying(64) NOT NULL,
    encounter_key character varying(64) NOT NULL,
    trial_run_key uuid,
    trial_battle_index smallint,
    result character varying(16) NOT NULL,
    rounds smallint NOT NULL,
    damage_dealt bigint NOT NULL,
    damage_received bigint NOT NULL,
    healing_done bigint NOT NULL,
    xp_awarded integer DEFAULT 0 NOT NULL,
    shard_delta bigint DEFAULT '0'::bigint NOT NULL,
    combat_level_before integer NOT NULL,
    combat_level_after integer NOT NULL,
    combat_xp_before bigint NOT NULL,
    combat_xp_after bigint NOT NULL,
    shard_balance_before bigint NOT NULL,
    shard_balance_after bigint NOT NULL,
    private_seed integer NOT NULL,
    snapshot jsonb NOT NULL,
    started_at timestamp(0) with time zone NOT NULL,
    finished_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    underground_party_id bigint,
    statistics_version smallint,
    statistics jsonb,
    compaction_version smallint,
    compacted_at timestamp(0) with time zone,
    CONSTRAINT underground_battles_activity_type_check CHECK (((activity_type)::text = ANY ((ARRAY['exploration'::character varying, 'trial'::character varying, 'tutorial'::character varying, 'story'::character varying, 'playtest'::character varying, 'guide_duel'::character varying])::text[]))),
    CONSTRAINT underground_battles_combat_level_after_positive CHECK ((combat_level_after >= 1)),
    CONSTRAINT underground_battles_combat_level_before_positive CHECK ((combat_level_before >= 1)),
    CONSTRAINT underground_battles_combat_xp_after_non_negative CHECK ((combat_xp_after >= 0)),
    CONSTRAINT underground_battles_combat_xp_before_non_negative CHECK ((combat_xp_before >= 0)),
    CONSTRAINT underground_battles_compaction_pair_check CHECK (((compaction_version IS NULL) = (compacted_at IS NULL))),
    CONSTRAINT underground_battles_compaction_version_positive CHECK (((compaction_version IS NULL) OR (compaction_version >= 1))),
    CONSTRAINT underground_battles_damage_dealt_non_negative CHECK ((damage_dealt >= 0)),
    CONSTRAINT underground_battles_damage_received_non_negative CHECK ((damage_received >= 0)),
    CONSTRAINT underground_battles_healing_done_non_negative CHECK ((healing_done >= 0)),
    CONSTRAINT underground_battles_private_seed_range CHECK (((private_seed >= 0) AND (private_seed <= 2147483647))),
    CONSTRAINT underground_battles_request_fingerprint_check CHECK ((request_fingerprint ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT underground_battles_result_check CHECK (((result)::text = ANY (ARRAY[('victory'::character varying)::text, ('defeat'::character varying)::text, ('withdrawal'::character varying)::text]))),
    CONSTRAINT underground_battles_rounds_range CHECK (((rounds >= 1) AND (rounds <= 100))),
    CONSTRAINT underground_battles_shard_balance_after_non_negative CHECK ((shard_balance_after >= 0)),
    CONSTRAINT underground_battles_shard_balance_before_non_negative CHECK ((shard_balance_before >= 0)),
    CONSTRAINT underground_battles_statistics_pair_check CHECK (((statistics_version IS NULL) = (statistics IS NULL))),
    CONSTRAINT underground_battles_statistics_version_positive CHECK (((statistics_version IS NULL) OR (statistics_version >= 1))),
    CONSTRAINT underground_battles_trial_battle_index_positive CHECK (((trial_battle_index IS NULL) OR (trial_battle_index >= 1))),
    CONSTRAINT underground_battles_trial_context_check CHECK (((((activity_type)::text = 'trial'::text) AND (trial_run_key IS NOT NULL) AND (trial_battle_index IS NOT NULL)) OR (((activity_type)::text <> 'trial'::text) AND (trial_run_key IS NULL) AND (trial_battle_index IS NULL)))),
    CONSTRAINT underground_battles_xp_awarded_non_negative CHECK ((xp_awarded >= 0))
);


--
-- Name: underground_battles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.underground_battles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: underground_battles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.underground_battles_id_seq OWNED BY public.underground_battles.id;


--
-- Name: underground_content_clear_progress; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.underground_content_clear_progress (
    id bigint NOT NULL,
    underground_profile_id bigint CONSTRAINT underground_content_clear_progr_underground_profile_id_not_null NOT NULL,
    content_type character varying(24) NOT NULL,
    content_key character varying(64) NOT NULL,
    actual_clear_count integer DEFAULT 0 NOT NULL,
    total_clear_count integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    first_cleared_at timestamp(0) with time zone,
    CONSTRAINT underground_content_clear_progress_count_check CHECK (((actual_clear_count >= 0) AND (total_clear_count >= actual_clear_count))),
    CONSTRAINT underground_content_clear_progress_type_check CHECK (((content_type)::text = ANY ((ARRAY['hunting_ground'::character varying, 'trial'::character varying, 'guide_duel'::character varying])::text[])))
);


--
-- Name: underground_content_clear_progress_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.underground_content_clear_progress_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: underground_content_clear_progress_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.underground_content_clear_progress_id_seq OWNED BY public.underground_content_clear_progress.id;


--
-- Name: underground_intro_progress; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.underground_intro_progress (
    id bigint NOT NULL,
    underground_profile_id bigint NOT NULL,
    stage character varying(32) DEFAULT 'not_started'::character varying NOT NULL,
    shopkeeper_name character varying(255),
    special_loss_required boolean,
    branch_identity character varying(32),
    tutorial_battle_id bigint,
    scripted_loss_battle_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    guide_recollection_max_completed smallint DEFAULT '0'::smallint CONSTRAINT underground_intro_progress_guide_recollection_max_comp_not_null NOT NULL,
    tutorial_encounter_key character varying(100),
    initial_growth_path_key character varying(64),
    CONSTRAINT underground_intro_progress_branch_identity_check CHECK (((branch_identity IS NULL) OR ((branch_identity)::text = ANY (ARRAY[('normal'::character varying)::text, ('legacy_temporary'::character varying)::text, ('true_name'::character varying)::text])))),
    CONSTRAINT underground_intro_progress_naming_check CHECK (((((stage)::text = ANY (ARRAY[('not_started'::character varying)::text, ('initial_descent'::character varying)::text, ('tutorial_ready'::character varying)::text, ('escape_pending'::character varying)::text, ('returned_after_tutorial'::character varying)::text, ('shopkeeper_encounter'::character varying)::text, ('shopkeeper_naming'::character varying)::text])) AND (shopkeeper_name IS NULL) AND (special_loss_required IS NULL) AND (branch_identity IS NULL)) OR (((stage)::text <> ALL (ARRAY[('not_started'::character varying)::text, ('initial_descent'::character varying)::text, ('tutorial_ready'::character varying)::text, ('escape_pending'::character varying)::text, ('returned_after_tutorial'::character varying)::text, ('shopkeeper_encounter'::character varying)::text, ('shopkeeper_naming'::character varying)::text])) AND (shopkeeper_name IS NOT NULL) AND (special_loss_required IS NOT NULL) AND (branch_identity IS NOT NULL)))),
    CONSTRAINT underground_intro_progress_recollection_completed_check CHECK (((guide_recollection_max_completed >= 0) AND (guide_recollection_max_completed <= 5))),
    CONSTRAINT underground_intro_progress_special_loss_check CHECK (((((branch_identity)::text = 'normal'::text) AND (special_loss_required = false) AND (scripted_loss_battle_id IS NULL)) OR (((branch_identity)::text = ANY (ARRAY[('legacy_temporary'::character varying)::text, ('true_name'::character varying)::text])) AND (special_loss_required = true) AND ((((stage)::text = 'special_loss_pending'::text) AND (scripted_loss_battle_id IS NULL)) OR (((stage)::text = ANY (ARRAY[('special_loss_complete'::character varying)::text, ('shop_explanation'::character varying)::text, ('contract_ready'::character varying)::text, ('crystal_selection'::character varying)::text, ('growth_path_selected'::character varying)::text, ('underground_open'::character varying)::text])) AND (scripted_loss_battle_id IS NOT NULL)))) OR ((branch_identity IS NULL) AND (special_loss_required IS NULL) AND (scripted_loss_battle_id IS NULL)))),
    CONSTRAINT underground_intro_progress_stage_check CHECK (((stage)::text = ANY (ARRAY[('not_started'::character varying)::text, ('initial_descent'::character varying)::text, ('tutorial_ready'::character varying)::text, ('escape_pending'::character varying)::text, ('returned_after_tutorial'::character varying)::text, ('shopkeeper_encounter'::character varying)::text, ('shopkeeper_naming'::character varying)::text, ('special_loss_pending'::character varying)::text, ('special_loss_complete'::character varying)::text, ('shop_explanation'::character varying)::text, ('contract_ready'::character varying)::text, ('crystal_selection'::character varying)::text, ('growth_path_selected'::character varying)::text, ('underground_open'::character varying)::text]))),
    CONSTRAINT underground_intro_progress_tutorial_check CHECK (((((stage)::text = ANY (ARRAY[('not_started'::character varying)::text, ('initial_descent'::character varying)::text, ('tutorial_ready'::character varying)::text])) AND (tutorial_battle_id IS NULL)) OR (((stage)::text <> ALL (ARRAY[('not_started'::character varying)::text, ('initial_descent'::character varying)::text, ('tutorial_ready'::character varying)::text])) AND (tutorial_battle_id IS NOT NULL))))
);


--
-- Name: underground_intro_progress_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.underground_intro_progress_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: underground_intro_progress_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.underground_intro_progress_id_seq OWNED BY public.underground_intro_progress.id;


--
-- Name: underground_intro_requests; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.underground_intro_requests (
    id bigint NOT NULL,
    underground_profile_id bigint NOT NULL,
    request_id uuid NOT NULL,
    request_fingerprint character(64) NOT NULL,
    operation character varying(32) NOT NULL,
    resulting_stage character varying(32) NOT NULL,
    underground_battle_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    result_payload jsonb,
    CONSTRAINT underground_intro_requests_fingerprint_check CHECK ((request_fingerprint ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT underground_intro_requests_operation_check CHECK (((operation)::text = ANY ((ARRAY['entry'::character varying, 'advance'::character varying, 'tutorial'::character varying, 'shopkeeper_name'::character varying, 'scripted_loss'::character varying, 'contract'::character varying, 'growth_path'::character varying, 'inn_rest'::character varying, 'bank_transfer'::character varying, 'playtest'::character varying, 'stp_allocate'::character varying, 'skill_acquire'::character varying, 'active_loadout'::character varying, 'awakening_message'::character varying, 'equipment_purchase'::character varying, 'equipment_sell'::character varying, 'equipment_equip'::character varying, 'equipment_unequip'::character varying, 'respec'::character varying, 'equipment_bulk_sell'::character varying, 'ai_configuration'::character varying, 'awakening_technique'::character varying, 'recollection_read'::character varying, 'rental_party'::character varying, 'residence_purchase'::character varying, 'lounge_event'::character varying, 'home_background'::character varying, 'guide_conversation'::character varying, 'distorted_stone_purchase'::character varying, 'equipment_polish'::character varying])::text[]))),
    CONSTRAINT underground_intro_requests_result_check CHECK ((((operation)::text = 'guide_conversation'::text) = (result_payload IS NOT NULL))),
    CONSTRAINT underground_intro_requests_stage_check CHECK (((resulting_stage)::text = ANY (ARRAY[('not_started'::character varying)::text, ('initial_descent'::character varying)::text, ('tutorial_ready'::character varying)::text, ('escape_pending'::character varying)::text, ('returned_after_tutorial'::character varying)::text, ('shopkeeper_encounter'::character varying)::text, ('shopkeeper_naming'::character varying)::text, ('special_loss_pending'::character varying)::text, ('special_loss_complete'::character varying)::text, ('shop_explanation'::character varying)::text, ('contract_ready'::character varying)::text, ('crystal_selection'::character varying)::text, ('growth_path_selected'::character varying)::text, ('underground_open'::character varying)::text])))
);


--
-- Name: underground_intro_requests_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.underground_intro_requests_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: underground_intro_requests_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.underground_intro_requests_id_seq OWNED BY public.underground_intro_requests.id;


--
-- Name: underground_owned_equipment; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.underground_owned_equipment (
    id bigint NOT NULL,
    underground_profile_id bigint NOT NULL,
    definition_key character varying(100) NOT NULL,
    catalog_identity character varying(100) NOT NULL,
    equipped_slot character varying(16),
    grant_key character varying(100),
    acquired_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    instance_kind character varying(16) DEFAULT 'fixed'::character varying NOT NULL,
    instance_identity character varying(64),
    generator_identity character varying(100),
    generated_payload jsonb,
    source_battle_id bigint,
    source_skip_settlement_id bigint,
    source_reward_index bigint,
    source_skip_batch_id bigint,
    polish_level integer DEFAULT 0 NOT NULL,
    CONSTRAINT underground_equipment_polish_check CHECK (((polish_level >= 0) AND ((polish_level = 0) OR (((instance_kind)::text = 'generated'::text) AND ((generated_payload ->> 'category'::text) = 'resonance'::text))))),
    CONSTRAINT underground_equipment_provenance_check CHECK ((((source_battle_id IS NULL) OR (source_battle_id > 0)) AND ((source_skip_settlement_id IS NULL) OR (source_skip_settlement_id > 0)) AND ((source_skip_batch_id IS NULL) OR (source_skip_batch_id > 0)) AND (((instance_kind)::text <> 'generated'::text) OR (source_battle_id IS NOT NULL) OR (source_reward_index IS NOT NULL)))),
    CONSTRAINT underground_owned_equipment_identity_check CHECK ((((definition_key)::text <> ''::text) AND ((catalog_identity)::text <> ''::text))),
    CONSTRAINT underground_owned_equipment_instance_check CHECK (((((instance_kind)::text = 'fixed'::text) AND (instance_identity IS NULL) AND (generator_identity IS NULL) AND (generated_payload IS NULL) AND (source_battle_id IS NULL) AND (source_skip_settlement_id IS NULL) AND (source_skip_batch_id IS NULL) AND (source_reward_index IS NULL)) OR (((instance_kind)::text = 'generated'::text) AND (instance_identity IS NOT NULL) AND (generator_identity IS NOT NULL) AND (generated_payload IS NOT NULL) AND (grant_key IS NOT NULL) AND (((source_battle_id IS NOT NULL) AND (source_skip_settlement_id IS NULL) AND (source_skip_batch_id IS NULL) AND ((source_reward_index IS NULL) OR (source_reward_index >= 1))) OR ((source_battle_id IS NULL) AND (source_skip_settlement_id IS NOT NULL) AND (source_skip_batch_id IS NULL) AND (source_reward_index >= 1)) OR ((source_battle_id IS NULL) AND (source_skip_settlement_id IS NULL) AND (source_skip_batch_id IS NOT NULL) AND (source_reward_index >= 1)))))),
    CONSTRAINT underground_owned_equipment_slot_check CHECK (((equipped_slot IS NULL) OR ((equipped_slot)::text = ANY ((ARRAY['weapon'::character varying, 'armor'::character varying, 'accessory_1'::character varying, 'accessory_2'::character varying, 'accessory_3'::character varying, 'resonance'::character varying])::text[]))))
);


--
-- Name: underground_owned_equipment_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.underground_owned_equipment_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: underground_owned_equipment_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.underground_owned_equipment_id_seq OWNED BY public.underground_owned_equipment.id;


--
-- Name: underground_parties; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.underground_parties (
    id bigint NOT NULL,
    leader_user_id bigint NOT NULL,
    leader_secretary_id bigint NOT NULL,
    content_type character varying(32) NOT NULL,
    content_key character varying(100) NOT NULL,
    content_identity character varying(128) NOT NULL,
    party_size smallint NOT NULL,
    leader_combat_level integer NOT NULL,
    snapshot jsonb NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT underground_parties_level_check CHECK ((leader_combat_level >= 1)),
    CONSTRAINT underground_parties_size_check CHECK (((party_size >= 1) AND (party_size <= 4)))
);


--
-- Name: underground_parties_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.underground_parties_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: underground_parties_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.underground_parties_id_seq OWNED BY public.underground_parties.id;


--
-- Name: underground_party_members; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.underground_party_members (
    id bigint NOT NULL,
    underground_party_id bigint NOT NULL,
    source_type character varying(32) NOT NULL,
    secretary_id bigint,
    source_owner_user_id bigint,
    combatant_id character varying(100) NOT NULL,
    original_level integer NOT NULL,
    effective_level integer NOT NULL,
    snapshot jsonb NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT underground_party_members_level_check CHECK (((original_level >= 1) AND (effective_level >= 1) AND (effective_level <= original_level))),
    CONSTRAINT underground_party_members_source_check CHECK (((source_type)::text = ANY ((ARRAY['self'::character varying, 'borrowed_secretary'::character varying, 'companion'::character varying])::text[])))
);


--
-- Name: underground_party_members_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.underground_party_members_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: underground_party_members_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.underground_party_members_id_seq OWNED BY public.underground_party_members.id;


--
-- Name: underground_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.underground_profiles (
    id bigint NOT NULL,
    secretary_id bigint NOT NULL,
    unlocked_area_layers integer DEFAULT 0 NOT NULL,
    combat_level integer DEFAULT 1 NOT NULL,
    combat_xp bigint DEFAULT '0'::bigint NOT NULL,
    shard_balance bigint DEFAULT '0'::bigint NOT NULL,
    banked_shard_balance bigint DEFAULT '0'::bigint NOT NULL,
    current_hp integer,
    next_battle_at timestamp(0) with time zone,
    underground_contract_completed_at timestamp(0) with time zone,
    growth_path_key character varying(32),
    growth_path_identity character varying(64),
    growth_path_selected_at timestamp(0) with time zone,
    unspent_stp integer DEFAULT 0 NOT NULL,
    allocated_vitality_stp integer DEFAULT 0 NOT NULL,
    allocated_might_stp integer DEFAULT 0 NOT NULL,
    allocated_finesse_stp integer DEFAULT 0 NOT NULL,
    allocated_spirit_stp integer DEFAULT 0 NOT NULL,
    allocated_agility_stp integer DEFAULT 0 NOT NULL,
    skill_points_total integer DEFAULT 0 NOT NULL,
    skill_points_unspent integer DEFAULT 0 NOT NULL,
    skill_tree_identity character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    awakening_gauge smallint DEFAULT '0'::smallint NOT NULL,
    awakening_message character varying(100),
    last_respec_at timestamp(0) with time zone,
    custom_ai_rules jsonb,
    awakening_technique_key character varying(64),
    shining_kingdom_key_balance bigint DEFAULT '0'::bigint NOT NULL,
    skill_rebuild_required boolean DEFAULT false NOT NULL,
    rental_party jsonb DEFAULT '[]'::jsonb NOT NULL,
    villa_purchased_at timestamp(0) with time zone,
    mirror_purchased_at timestamp(0) with time zone,
    exchange_intro_page smallint DEFAULT '0'::smallint NOT NULL,
    mirror_event_completed_at timestamp(0) with time zone,
    home_background_key character varying(120),
    trophy_shelf_purchased_at timestamp(0) with time zone,
    vault_expansion_purchased_at timestamp(0) with time zone,
    resonance_expansion_purchased_at timestamp(0) with time zone,
    distorted_stone_balance bigint DEFAULT '0'::bigint NOT NULL,
    distorted_stone_purchase_day date,
    distorted_stone_purchase_count integer DEFAULT 0 NOT NULL,
    polishing_tutorial_completed_at timestamp(0) with time zone,
    otherworld_discovered_at timestamp(0) with time zone,
    yunagi_harbor_key_balance bigint DEFAULT '0'::bigint NOT NULL,
    CONSTRAINT underground_otherworld_balances_check CHECK (((distorted_stone_balance >= 0) AND (distorted_stone_purchase_count >= 0))),
    CONSTRAINT underground_profiles_awakening_gauge_check CHECK (((awakening_gauge >= 0) AND (awakening_gauge <= 1000))),
    CONSTRAINT underground_profiles_awakening_message_check CHECK (((awakening_message IS NULL) OR (((char_length((awakening_message)::text) >= 1) AND (char_length((awakening_message)::text) <= 100)) AND ((awakening_message)::text !~ '[\r\n]'::text)))),
    CONSTRAINT underground_profiles_awakening_technique_check CHECK (((awakening_technique_key IS NULL) OR (((growth_path_key)::text = 'martial_red'::text) AND ((awakening_technique_key)::text = ANY ((ARRAY['decisive_heavenrend'::character varying, 'shura_bloodline'::character varying])::text[]))) OR (((growth_path_key)::text = 'guardianship_blue'::text) AND ((awakening_technique_key)::text = ANY ((ARRAY['absolute_aegis'::character varying, 'fortress_strike'::character varying])::text[]))) OR (((growth_path_key)::text = 'blessing_green'::text) AND ((awakening_technique_key)::text = ANY ((ARRAY['life_requiem'::character varying, 'judgment_light'::character varying])::text[]))) OR (((growth_path_key)::text = 'free_black'::text) AND ((awakening_technique_key)::text = ANY ((ARRAY['limitless_reprise'::character varying, 'formless_strike'::character varying])::text[]))))),
    CONSTRAINT underground_profiles_banked_shard_balance_non_negative CHECK ((banked_shard_balance >= 0)),
    CONSTRAINT underground_profiles_combat_level_positive CHECK ((combat_level >= 1)),
    CONSTRAINT underground_profiles_combat_xp_non_negative CHECK ((combat_xp >= 0)),
    CONSTRAINT underground_profiles_current_hp_positive CHECK (((current_hp IS NULL) OR (current_hp >= 1))),
    CONSTRAINT underground_profiles_custom_ai_rules_check CHECK (((custom_ai_rules IS NULL) OR ((jsonb_typeof(custom_ai_rules) = 'array'::text) AND (jsonb_array_length(custom_ai_rules) <= 20)))),
    CONSTRAINT underground_profiles_growth_path_check CHECK ((((growth_path_key IS NULL) AND (growth_path_identity IS NULL) AND (growth_path_selected_at IS NULL)) OR ((underground_contract_completed_at IS NOT NULL) AND ((growth_path_key)::text = ANY (ARRAY[('martial_red'::character varying)::text, ('guardianship_blue'::character varying)::text, ('blessing_green'::character varying)::text, ('free_black'::character varying)::text])) AND ((growth_path_identity)::text = 'secretary-underground-growth-alpha-v1'::text) AND (growth_path_selected_at IS NOT NULL) AND (growth_path_selected_at >= underground_contract_completed_at)))),
    CONSTRAINT underground_profiles_rental_party_check CHECK (((jsonb_typeof(rental_party) = 'array'::text) AND (jsonb_array_length(rental_party) <= 3))),
    CONSTRAINT underground_profiles_shard_balance_non_negative CHECK ((shard_balance >= 0)),
    CONSTRAINT underground_profiles_shining_kingdom_key_non_negative CHECK ((shining_kingdom_key_balance >= 0)),
    CONSTRAINT underground_profiles_skill_points_check CHECK (((skill_points_total >= 0) AND (skill_points_unspent >= 0) AND (skill_points_unspent <= skill_points_total) AND (((growth_path_key IS NULL) AND (skill_points_total = 0) AND (skill_points_unspent = 0) AND (skill_tree_identity IS NULL)) OR ((growth_path_key IS NOT NULL) AND (skill_tree_identity IS NOT NULL))))),
    CONSTRAINT underground_profiles_stp_entitlement_check CHECK (((growth_path_key IS NOT NULL) OR ((((((unspent_stp + allocated_vitality_stp) + allocated_might_stp) + allocated_finesse_stp) + allocated_spirit_stp) + allocated_agility_stp) = 0))),
    CONSTRAINT underground_profiles_stp_non_negative CHECK (((unspent_stp >= 0) AND (allocated_vitality_stp >= 0) AND (allocated_might_stp >= 0) AND (allocated_finesse_stp >= 0) AND (allocated_spirit_stp >= 0) AND (allocated_agility_stp >= 0))),
    CONSTRAINT underground_profiles_unlocked_area_layers_non_negative CHECK ((unlocked_area_layers >= 0)),
    CONSTRAINT underground_profiles_yunagi_harbor_key_non_negative CHECK ((yunagi_harbor_key_balance >= 0))
);


--
-- Name: underground_profiles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.underground_profiles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: underground_profiles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.underground_profiles_id_seq OWNED BY public.underground_profiles.id;


--
-- Name: underground_receipt_rollups; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.underground_receipt_rollups (
    underground_profile_id bigint NOT NULL,
    stream character varying(16) NOT NULL,
    verified_through_id bigint NOT NULL,
    aggregation_version smallint NOT NULL,
    receipt_count bigint DEFAULT '0'::bigint NOT NULL,
    battle_count bigint DEFAULT '0'::bigint NOT NULL,
    victory_count bigint DEFAULT '0'::bigint NOT NULL,
    damage_dealt_sum bigint DEFAULT '0'::bigint NOT NULL,
    damage_dealt_known_count bigint DEFAULT '0'::bigint NOT NULL,
    damage_received_sum bigint DEFAULT '0'::bigint NOT NULL,
    damage_received_known_count bigint DEFAULT '0'::bigint CONSTRAINT underground_receipt_rollups_damage_received_known_coun_not_null NOT NULL,
    skip_tickets_used bigint DEFAULT '0'::bigint NOT NULL,
    last_batch jsonb NOT NULL,
    verified_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) with time zone,
    updated_at timestamp(0) with time zone,
    lifetime_statistics jsonb,
    deleted_through_id bigint DEFAULT '0'::bigint NOT NULL,
    deleted_receipt_count bigint DEFAULT '0'::bigint NOT NULL,
    last_deletion jsonb,
    CONSTRAINT underground_receipt_rollups_counts_check CHECK (((verified_through_id > 0) AND (aggregation_version > 0) AND (receipt_count > 0) AND ((battle_count >= 0) AND (battle_count <= receipt_count)) AND ((victory_count >= 0) AND (victory_count <= battle_count)) AND ((damage_dealt_known_count >= 0) AND (damage_dealt_known_count <= battle_count)) AND ((damage_received_known_count >= 0) AND (damage_received_known_count <= battle_count)) AND (damage_dealt_sum >= 0) AND (damage_received_sum >= 0) AND (skip_tickets_used >= 0))),
    CONSTRAINT underground_receipt_rollups_deletion_check CHECK ((((deleted_through_id >= 0) AND (deleted_through_id <= verified_through_id)) AND ((deleted_receipt_count >= 0) AND (deleted_receipt_count <= receipt_count)))),
    CONSTRAINT underground_receipt_rollups_stream_check CHECK (((stream)::text = ANY ((ARRAY['battle'::character varying, 'skip'::character varying, 'bulk_skip'::character varying, 'intro_request'::character varying])::text[])))
);


--
-- Name: underground_skill_allocations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.underground_skill_allocations (
    id bigint NOT NULL,
    underground_profile_id bigint NOT NULL,
    node_key character varying(100) NOT NULL,
    rank smallint NOT NULL,
    active_slot smallint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT underground_skill_allocations_active_slot_range CHECK (((active_slot IS NULL) OR ((active_slot >= 1) AND (active_slot <= 5)))),
    CONSTRAINT underground_skill_allocations_rank_positive CHECK ((rank >= 1))
);


--
-- Name: underground_skill_allocations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.underground_skill_allocations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: underground_skill_allocations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.underground_skill_allocations_id_seq OWNED BY public.underground_skill_allocations.id;


--
-- Name: underground_skip_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.underground_skip_batches (
    id bigint NOT NULL,
    underground_profile_id bigint NOT NULL,
    user_id bigint NOT NULL,
    request_id uuid NOT NULL,
    request_fingerprint character(64) NOT NULL,
    skip_identity character varying(100) NOT NULL,
    content_type character varying(24) NOT NULL,
    content_key character varying(64) NOT NULL,
    content_identity character varying(128) NOT NULL,
    execution_count integer NOT NULL,
    ticket_cost bigint NOT NULL,
    xp_awarded bigint NOT NULL,
    shard_awarded bigint NOT NULL,
    combat_level_before integer NOT NULL,
    combat_level_after integer NOT NULL,
    combat_xp_before bigint NOT NULL,
    combat_xp_after bigint NOT NULL,
    shard_balance_before bigint NOT NULL,
    shard_balance_after bigint NOT NULL,
    reward_snapshot jsonb NOT NULL,
    settled_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT underground_skip_batches_cost_check CHECK (((execution_count >= 1) AND (ticket_cost >= 1) AND ((content_type)::text = ANY ((ARRAY['hunting_ground'::character varying, 'trial'::character varying])::text[])))),
    CONSTRAINT underground_skip_batches_fingerprint_check CHECK ((request_fingerprint ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT underground_skip_batches_progression_check CHECK (((combat_level_before >= 1) AND (combat_level_after >= combat_level_before) AND (combat_xp_after >= combat_xp_before) AND (shard_balance_after >= shard_balance_before)))
);


--
-- Name: underground_skip_batches_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.underground_skip_batches_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: underground_skip_batches_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.underground_skip_batches_id_seq OWNED BY public.underground_skip_batches.id;


--
-- Name: underground_skip_settlements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.underground_skip_settlements (
    id bigint NOT NULL,
    underground_profile_id bigint NOT NULL,
    user_id bigint NOT NULL,
    request_id uuid NOT NULL,
    request_fingerprint character(64) NOT NULL,
    skip_identity character varying(100) NOT NULL,
    content_type character varying(24) NOT NULL,
    content_key character varying(64) NOT NULL,
    content_identity character varying(128) NOT NULL,
    ticket_cost smallint NOT NULL,
    xp_awarded integer NOT NULL,
    shard_awarded bigint NOT NULL,
    combat_level_before integer NOT NULL,
    combat_level_after integer NOT NULL,
    combat_xp_before bigint NOT NULL,
    combat_xp_after bigint NOT NULL,
    shard_balance_before bigint NOT NULL,
    shard_balance_after bigint NOT NULL,
    private_seed integer NOT NULL,
    reward_snapshot jsonb NOT NULL,
    settled_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT underground_skip_settlements_fingerprint_check CHECK ((request_fingerprint ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT underground_skip_settlements_progression_check CHECK (((combat_level_before >= 1) AND (combat_level_after >= combat_level_before) AND (combat_xp_after >= combat_xp_before) AND (shard_balance_after >= shard_balance_before))),
    CONSTRAINT underground_skip_settlements_type_cost_check CHECK (((((content_type)::text = 'hunting_ground'::text) AND (ticket_cost = 1)) OR (((content_type)::text = 'trial'::text) AND (ticket_cost = 10))))
);


--
-- Name: underground_skip_settlements_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.underground_skip_settlements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: underground_skip_settlements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.underground_skip_settlements_id_seq OWNED BY public.underground_skip_settlements.id;


--
-- Name: underground_trial_progress; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.underground_trial_progress (
    id bigint NOT NULL,
    underground_profile_id bigint NOT NULL,
    trial_key character varying(64) NOT NULL,
    unlocked_at timestamp(0) with time zone NOT NULL,
    first_cleared_at timestamp(0) with time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    first_challenged_at timestamp(0) with time zone,
    first_challenge_intro text,
    first_clear_story jsonb,
    first_milestone_stories jsonb
);


--
-- Name: underground_trial_progress_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.underground_trial_progress_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: underground_trial_progress_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.underground_trial_progress_id_seq OWNED BY public.underground_trial_progress.id;


--
-- Name: underground_trial_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.underground_trial_runs (
    id bigint NOT NULL,
    underground_profile_id bigint NOT NULL,
    run_key uuid NOT NULL,
    trial_key character varying(64) NOT NULL,
    trial_content_identity character varying(128) NOT NULL,
    next_battle_index smallint DEFAULT '1'::smallint NOT NULL,
    status character varying(16) NOT NULL,
    started_at timestamp(0) with time zone NOT NULL,
    ended_at timestamp(0) with time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT underground_trial_runs_content_identity_not_empty CHECK ((char_length((trial_content_identity)::text) > 0)),
    CONSTRAINT underground_trial_runs_next_battle_index_positive CHECK ((next_battle_index >= 1)),
    CONSTRAINT underground_trial_runs_status_check CHECK (((status)::text = ANY (ARRAY[('active'::character varying)::text, ('withdrawn'::character varying)::text, ('defeated'::character varying)::text, ('cleared'::character varying)::text])))
);


--
-- Name: underground_trial_runs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.underground_trial_runs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: underground_trial_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.underground_trial_runs_id_seq OWNED BY public.underground_trial_runs.id;


--
-- Name: user_daily_login_claims; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_daily_login_claims (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    canonical_day date NOT NULL,
    paradox_awarded integer NOT NULL,
    skip_tickets_awarded integer NOT NULL,
    claimed_at timestamp(0) with time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT user_daily_login_award_check CHECK (((paradox_awarded > 0) AND (skip_tickets_awarded > 0)))
);


--
-- Name: user_daily_login_claims_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_daily_login_claims_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_daily_login_claims_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_daily_login_claims_id_seq OWNED BY public.user_daily_login_claims.id;


--
-- Name: user_daily_quest_activities; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_daily_quest_activities (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    canonical_day date NOT NULL,
    quest_key character varying(64) NOT NULL,
    entry_key character varying(180) NOT NULL,
    amount integer NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT user_daily_quest_activity_amount_check CHECK ((amount > 0)),
    CONSTRAINT user_daily_quest_activity_key_check CHECK (((quest_key)::text = ANY ((ARRAY['development_opened'::character varying, 'underground_battles'::character varying, 'command_registered'::character varying])::text[])))
);


--
-- Name: user_daily_quest_activities_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_daily_quest_activities_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_daily_quest_activities_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_daily_quest_activities_id_seq OWNED BY public.user_daily_quest_activities.id;


--
-- Name: user_daily_quest_progress; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_daily_quest_progress (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    canonical_day date NOT NULL,
    quest_key character varying(64) NOT NULL,
    progress integer DEFAULT 0 NOT NULL,
    target integer NOT NULL,
    paradox_awarded integer DEFAULT 0 NOT NULL,
    completed_at timestamp(0) with time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT user_daily_quest_progress_key_check CHECK (((quest_key)::text = ANY ((ARRAY['development_opened'::character varying, 'underground_battles'::character varying, 'command_registered'::character varying])::text[]))),
    CONSTRAINT user_daily_quest_progress_value_check CHECK (((progress <= target) AND (target > 0) AND (((completed_at IS NULL) AND (paradox_awarded = 0)) OR ((completed_at IS NOT NULL) AND (progress = target) AND (paradox_awarded > 0)))))
);


--
-- Name: user_daily_quest_progress_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_daily_quest_progress_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_daily_quest_progress_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_daily_quest_progress_id_seq OWNED BY public.user_daily_quest_progress.id;


--
-- Name: user_monument_designs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_monument_designs (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    name character varying(40) DEFAULT 'オリジナル記念碑'::character varying NOT NULL,
    image_path character varying(96),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: user_monument_designs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_monument_designs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_monument_designs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_monument_designs_id_seq OWNED BY public.user_monument_designs.id;


--
-- Name: user_paradox_balances; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_paradox_balances (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    balance bigint DEFAULT '0'::bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT user_paradox_balance_nonnegative_check CHECK ((balance >= 0))
);


--
-- Name: user_paradox_balances_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_paradox_balances_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_paradox_balances_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_paradox_balances_id_seq OWNED BY public.user_paradox_balances.id;


--
-- Name: user_paradox_ledger; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_paradox_ledger (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    entry_key character varying(180) NOT NULL,
    delta bigint NOT NULL,
    balance_before bigint NOT NULL,
    balance_after bigint NOT NULL,
    source_kind character varying(32) NOT NULL,
    canonical_day date,
    metadata jsonb,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT user_paradox_ledger_delta_check CHECK (((delta <> 0) AND (balance_before >= 0) AND (balance_after >= 0) AND (balance_after = (balance_before + delta)))),
    CONSTRAINT user_paradox_ledger_source_check CHECK (((source_kind)::text = ANY ((ARRAY['daily_login'::character varying, 'daily_quest'::character varying, 'command'::character varying, 'compensation'::character varying, 'underground_respec'::character varying])::text[])))
);


--
-- Name: user_paradox_ledger_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_paradox_ledger_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_paradox_ledger_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_paradox_ledger_id_seq OWNED BY public.user_paradox_ledger.id;


--
-- Name: user_skip_ticket_balances; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_skip_ticket_balances (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    balance integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    lifetime_participation_count bigint DEFAULT '0'::bigint NOT NULL,
    CONSTRAINT lending_lifetime_count_check CHECK ((lifetime_participation_count >= 0))
);


--
-- Name: user_skip_ticket_balances_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_skip_ticket_balances_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_skip_ticket_balances_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_skip_ticket_balances_id_seq OWNED BY public.user_skip_ticket_balances.id;


--
-- Name: user_skip_ticket_ledger; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.user_skip_ticket_ledger (
    id bigint NOT NULL,
    user_id bigint NOT NULL,
    underground_battle_id bigint,
    underground_party_member_id bigint,
    entry_key character varying(180) NOT NULL,
    delta integer NOT NULL,
    balance_before integer NOT NULL,
    balance_after integer NOT NULL,
    canonical_day date NOT NULL,
    metadata jsonb,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    underground_skip_settlement_id bigint,
    underground_skip_batch_id bigint,
    CONSTRAINT user_skip_ticket_ledger_delta_check CHECK (((delta <> 0) AND (balance_before >= 0) AND (balance_after >= 0) AND (balance_after = (balance_before + delta)))),
    CONSTRAINT user_skip_ticket_ledger_skip_source_check CHECK (((underground_skip_settlement_id IS NULL) OR (underground_skip_batch_id IS NULL)))
);


--
-- Name: user_skip_ticket_ledger_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.user_skip_ticket_ledger_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: user_skip_ticket_ledger_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.user_skip_ticket_ledger_id_seq OWNED BY public.user_skip_ticket_ledger.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    display_name character varying(255) NOT NULL,
    remember_token character varying(100),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    visitor_code character varying(8),
    message_board_last_posted_at timestamp(0) with time zone,
    show_ai_generated_secretary_images boolean,
    secretary_image_fallback character varying(16),
    CONSTRAINT users_secretary_image_preferences_check CHECK ((((show_ai_generated_secretary_images IS NULL) AND (secretary_image_fallback IS NULL)) OR ((show_ai_generated_secretary_images IS NOT NULL) AND ((secretary_image_fallback)::text = ANY (ARRAY[('silhouette'::character varying)::text, ('peridot'::character varying)::text]))))),
    CONSTRAINT users_visitor_code_format_check CHECK (((visitor_code IS NULL) OR ((visitor_code)::text ~ '^[A-Za-z0-9]{8}$'::text)))
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: world_generation_runs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.world_generation_runs (
    id bigint NOT NULL,
    map_space_id bigint NOT NULL,
    generator_id character varying(255) NOT NULL,
    generator_version character varying(255) NOT NULL,
    seed character varying(255) NOT NULL,
    status character varying(255) NOT NULL,
    completed_at timestamp(0) with time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: world_generation_runs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.world_generation_runs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: world_generation_runs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.world_generation_runs_id_seq OWNED BY public.world_generation_runs.id;


--
-- Name: worlds; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.worlds (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    ruleset_version_id bigint NOT NULL,
    current_turn bigint DEFAULT 1 NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    turn_schedule_origin_at timestamp(0) with time zone
);


--
-- Name: worlds_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.worlds_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: worlds_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.worlds_id_seq OWNED BY public.worlds.id;


--
-- Name: announcements id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.announcements ALTER COLUMN id SET DEFAULT nextval('public.announcements_id_seq'::regclass);


--
-- Name: auction_bids id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_bids ALTER COLUMN id SET DEFAULT nextval('public.auction_bids_id_seq'::regclass);


--
-- Name: auction_listings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_listings ALTER COLUMN id SET DEFAULT nextval('public.auction_listings_id_seq'::regclass);


--
-- Name: audit_events id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_events ALTER COLUMN id SET DEFAULT nextval('public.audit_events_id_seq'::regclass);


--
-- Name: auth_identities id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_identities ALTER COLUMN id SET DEFAULT nextval('public.auth_identities_id_seq'::regclass);


--
-- Name: buried_treasures id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.buried_treasures ALTER COLUMN id SET DEFAULT nextval('public.buried_treasures_id_seq'::regclass);


--
-- Name: command_definitions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.command_definitions ALTER COLUMN id SET DEFAULT nextval('public.command_definitions_id_seq'::regclass);


--
-- Name: compensation_grant_claims id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compensation_grant_claims ALTER COLUMN id SET DEFAULT nextval('public.compensation_grant_claims_id_seq'::regclass);


--
-- Name: compensation_grant_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compensation_grant_items ALTER COLUMN id SET DEFAULT nextval('public.compensation_grant_items_id_seq'::regclass);


--
-- Name: compensation_grants id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compensation_grants ALTER COLUMN id SET DEFAULT nextval('public.compensation_grants_id_seq'::regclass);


--
-- Name: facility_definitions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.facility_definitions ALTER COLUMN id SET DEFAULT nextval('public.facility_definitions_id_seq'::regclass);


--
-- Name: guide_conversation_topics id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guide_conversation_topics ALTER COLUMN id SET DEFAULT nextval('public.guide_conversation_topics_id_seq'::regclass);


--
-- Name: inquiries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inquiries ALTER COLUMN id SET DEFAULT nextval('public.inquiries_id_seq'::regclass);


--
-- Name: island_messages id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.island_messages ALTER COLUMN id SET DEFAULT nextval('public.island_messages_id_seq'::regclass);


--
-- Name: map_cells id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_cells ALTER COLUMN id SET DEFAULT nextval('public.map_cells_id_seq'::regclass);


--
-- Name: map_chunks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_chunks ALTER COLUMN id SET DEFAULT nextval('public.map_chunks_id_seq'::regclass);


--
-- Name: map_spaces id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_spaces ALTER COLUMN id SET DEFAULT nextval('public.map_spaces_id_seq'::regclass);


--
-- Name: merchant_conversation_topics id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.merchant_conversation_topics ALTER COLUMN id SET DEFAULT nextval('public.merchant_conversation_topics_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: moderation_records id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.moderation_records ALTER COLUMN id SET DEFAULT nextval('public.moderation_records_id_seq'::regclass);


--
-- Name: monster_definitions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monster_definitions ALTER COLUMN id SET DEFAULT nextval('public.monster_definitions_id_seq'::regclass);


--
-- Name: monster_instances id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monster_instances ALTER COLUMN id SET DEFAULT nextval('public.monster_instances_id_seq'::regclass);


--
-- Name: monster_occupancies id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monster_occupancies ALTER COLUMN id SET DEFAULT nextval('public.monster_occupancies_id_seq'::regclass);


--
-- Name: monument_definitions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monument_definitions ALTER COLUMN id SET DEFAULT nextval('public.monument_definitions_id_seq'::regclass);


--
-- Name: nation_awards id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_awards ALTER COLUMN id SET DEFAULT nextval('public.nation_awards_id_seq'::regclass);


--
-- Name: nation_capitals id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_capitals ALTER COLUMN id SET DEFAULT nextval('public.nation_capitals_id_seq'::regclass);


--
-- Name: nation_command_queue_bulk_requests id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_command_queue_bulk_requests ALTER COLUMN id SET DEFAULT nextval('public.nation_command_queue_bulk_requests_id_seq'::regclass);


--
-- Name: nation_command_queue_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_command_queue_items ALTER COLUMN id SET DEFAULT nextval('public.nation_command_queue_items_id_seq'::regclass);


--
-- Name: nation_command_queues id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_command_queues ALTER COLUMN id SET DEFAULT nextval('public.nation_command_queues_id_seq'::regclass);


--
-- Name: nation_creation_requests id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_creation_requests ALTER COLUMN id SET DEFAULT nextval('public.nation_creation_requests_id_seq'::regclass);


--
-- Name: nation_memberships id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_memberships ALTER COLUMN id SET DEFAULT nextval('public.nation_memberships_id_seq'::regclass);


--
-- Name: nation_monster_cycle_seed_requirements id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_monster_cycle_seed_requirements ALTER COLUMN id SET DEFAULT nextval('public.nation_monster_cycle_seed_requirements_id_seq'::regclass);


--
-- Name: nation_monster_cycle_stats id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_monster_cycle_stats ALTER COLUMN id SET DEFAULT nextval('public.nation_monster_cycle_stats_id_seq'::regclass);


--
-- Name: nation_monster_kill_stats id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_monster_kill_stats ALTER COLUMN id SET DEFAULT nextval('public.nation_monster_kill_stats_id_seq'::regclass);


--
-- Name: nation_resource_sale_policies id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_resource_sale_policies ALTER COLUMN id SET DEFAULT nextval('public.nation_resource_sale_policies_id_seq'::regclass);


--
-- Name: nation_resources id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_resources ALTER COLUMN id SET DEFAULT nextval('public.nation_resources_id_seq'::regclass);


--
-- Name: nation_underground_facilities id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_underground_facilities ALTER COLUMN id SET DEFAULT nextval('public.nation_underground_facilities_id_seq'::regclass);


--
-- Name: nations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nations ALTER COLUMN id SET DEFAULT nextval('public.nations_id_seq'::regclass);


--
-- Name: production_definitions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.production_definitions ALTER COLUMN id SET DEFAULT nextval('public.production_definitions_id_seq'::regclass);


--
-- Name: resource_definitions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.resource_definitions ALTER COLUMN id SET DEFAULT nextval('public.resource_definitions_id_seq'::regclass);


--
-- Name: ruleset_versions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ruleset_versions ALTER COLUMN id SET DEFAULT nextval('public.ruleset_versions_id_seq'::regclass);


--
-- Name: secretaries id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretaries ALTER COLUMN id SET DEFAULT nextval('public.secretaries_id_seq'::regclass);


--
-- Name: secretary_gacha_draws id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_gacha_draws ALTER COLUMN id SET DEFAULT nextval('public.secretary_gacha_draws_id_seq'::regclass);


--
-- Name: secretary_guide_conversation_totals id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_guide_conversation_totals ALTER COLUMN id SET DEFAULT nextval('public.secretary_guide_conversation_totals_id_seq'::regclass);


--
-- Name: secretary_images id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_images ALTER COLUMN id SET DEFAULT nextval('public.secretary_images_id_seq'::regclass);


--
-- Name: secretary_item_instances id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_item_instances ALTER COLUMN id SET DEFAULT nextval('public.secretary_item_instances_id_seq'::regclass);


--
-- Name: secretary_lending_build_snapshots id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_build_snapshots ALTER COLUMN id SET DEFAULT nextval('public.secretary_lending_build_snapshots_id_seq'::regclass);


--
-- Name: secretary_lending_daily_rewards id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_daily_rewards ALTER COLUMN id SET DEFAULT nextval('public.secretary_lending_daily_rewards_id_seq'::regclass);


--
-- Name: secretary_lending_participations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_participations ALTER COLUMN id SET DEFAULT nextval('public.secretary_lending_participations_id_seq'::regclass);


--
-- Name: secretary_lending_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_settings ALTER COLUMN id SET DEFAULT nextval('public.secretary_lending_settings_id_seq'::regclass);


--
-- Name: secretary_skills id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_skills ALTER COLUMN id SET DEFAULT nextval('public.secretary_skills_id_seq'::regclass);


--
-- Name: ships id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ships ALTER COLUMN id SET DEFAULT nextval('public.ships_id_seq'::regclass);


--
-- Name: terrain_definitions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.terrain_definitions ALTER COLUMN id SET DEFAULT nextval('public.terrain_definitions_id_seq'::regclass);


--
-- Name: turn_runs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.turn_runs ALTER COLUMN id SET DEFAULT nextval('public.turn_runs_id_seq'::regclass);


--
-- Name: underground_battle_image_references id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_battle_image_references ALTER COLUMN id SET DEFAULT nextval('public.underground_battle_image_references_id_seq'::regclass);


--
-- Name: underground_battle_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_battle_logs ALTER COLUMN id SET DEFAULT nextval('public.underground_battle_logs_id_seq'::regclass);


--
-- Name: underground_battles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_battles ALTER COLUMN id SET DEFAULT nextval('public.underground_battles_id_seq'::regclass);


--
-- Name: underground_content_clear_progress id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_content_clear_progress ALTER COLUMN id SET DEFAULT nextval('public.underground_content_clear_progress_id_seq'::regclass);


--
-- Name: underground_intro_progress id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_intro_progress ALTER COLUMN id SET DEFAULT nextval('public.underground_intro_progress_id_seq'::regclass);


--
-- Name: underground_intro_requests id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_intro_requests ALTER COLUMN id SET DEFAULT nextval('public.underground_intro_requests_id_seq'::regclass);


--
-- Name: underground_owned_equipment id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_owned_equipment ALTER COLUMN id SET DEFAULT nextval('public.underground_owned_equipment_id_seq'::regclass);


--
-- Name: underground_parties id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_parties ALTER COLUMN id SET DEFAULT nextval('public.underground_parties_id_seq'::regclass);


--
-- Name: underground_party_members id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_party_members ALTER COLUMN id SET DEFAULT nextval('public.underground_party_members_id_seq'::regclass);


--
-- Name: underground_profiles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_profiles ALTER COLUMN id SET DEFAULT nextval('public.underground_profiles_id_seq'::regclass);


--
-- Name: underground_skill_allocations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_skill_allocations ALTER COLUMN id SET DEFAULT nextval('public.underground_skill_allocations_id_seq'::regclass);


--
-- Name: underground_skip_batches id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_skip_batches ALTER COLUMN id SET DEFAULT nextval('public.underground_skip_batches_id_seq'::regclass);


--
-- Name: underground_skip_settlements id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_skip_settlements ALTER COLUMN id SET DEFAULT nextval('public.underground_skip_settlements_id_seq'::regclass);


--
-- Name: underground_trial_progress id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_trial_progress ALTER COLUMN id SET DEFAULT nextval('public.underground_trial_progress_id_seq'::regclass);


--
-- Name: underground_trial_runs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_trial_runs ALTER COLUMN id SET DEFAULT nextval('public.underground_trial_runs_id_seq'::regclass);


--
-- Name: user_daily_login_claims id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_daily_login_claims ALTER COLUMN id SET DEFAULT nextval('public.user_daily_login_claims_id_seq'::regclass);


--
-- Name: user_daily_quest_activities id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_daily_quest_activities ALTER COLUMN id SET DEFAULT nextval('public.user_daily_quest_activities_id_seq'::regclass);


--
-- Name: user_daily_quest_progress id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_daily_quest_progress ALTER COLUMN id SET DEFAULT nextval('public.user_daily_quest_progress_id_seq'::regclass);


--
-- Name: user_monument_designs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_monument_designs ALTER COLUMN id SET DEFAULT nextval('public.user_monument_designs_id_seq'::regclass);


--
-- Name: user_paradox_balances id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_paradox_balances ALTER COLUMN id SET DEFAULT nextval('public.user_paradox_balances_id_seq'::regclass);


--
-- Name: user_paradox_ledger id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_paradox_ledger ALTER COLUMN id SET DEFAULT nextval('public.user_paradox_ledger_id_seq'::regclass);


--
-- Name: user_skip_ticket_balances id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_skip_ticket_balances ALTER COLUMN id SET DEFAULT nextval('public.user_skip_ticket_balances_id_seq'::regclass);


--
-- Name: user_skip_ticket_ledger id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_skip_ticket_ledger ALTER COLUMN id SET DEFAULT nextval('public.user_skip_ticket_ledger_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: world_generation_runs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.world_generation_runs ALTER COLUMN id SET DEFAULT nextval('public.world_generation_runs_id_seq'::regclass);


--
-- Name: worlds id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.worlds ALTER COLUMN id SET DEFAULT nextval('public.worlds_id_seq'::regclass);


--
-- Name: announcements announcements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.announcements
    ADD CONSTRAINT announcements_pkey PRIMARY KEY (id);


--
-- Name: auction_bids auction_bids_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_bids
    ADD CONSTRAINT auction_bids_pkey PRIMARY KEY (id);


--
-- Name: auction_listings auction_listings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_listings
    ADD CONSTRAINT auction_listings_pkey PRIMARY KEY (id);


--
-- Name: audit_events audit_events_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_events
    ADD CONSTRAINT audit_events_pkey PRIMARY KEY (id);


--
-- Name: auth_identities auth_identities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_identities
    ADD CONSTRAINT auth_identities_pkey PRIMARY KEY (id);


--
-- Name: auth_identities auth_identities_provider_provider_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_identities
    ADD CONSTRAINT auth_identities_provider_provider_user_id_unique UNIQUE (provider, provider_user_id);


--
-- Name: auth_identities auth_identities_user_id_provider_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_identities
    ADD CONSTRAINT auth_identities_user_id_provider_unique UNIQUE (user_id, provider);


--
-- Name: buried_treasures buried_treasures_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.buried_treasures
    ADD CONSTRAINT buried_treasures_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: command_definitions command_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.command_definitions
    ADD CONSTRAINT command_definitions_pkey PRIMARY KEY (id);


--
-- Name: command_definitions command_definitions_ruleset_version_id_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.command_definitions
    ADD CONSTRAINT command_definitions_ruleset_version_id_key_unique UNIQUE (ruleset_version_id, key);


--
-- Name: compensation_grant_claims compensation_grant_claims_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compensation_grant_claims
    ADD CONSTRAINT compensation_grant_claims_pkey PRIMARY KEY (id);


--
-- Name: compensation_grant_claims compensation_grant_claims_request_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compensation_grant_claims
    ADD CONSTRAINT compensation_grant_claims_request_key_unique UNIQUE (request_key);


--
-- Name: compensation_grant_items compensation_grant_item_asset_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compensation_grant_items
    ADD CONSTRAINT compensation_grant_item_asset_unique UNIQUE (compensation_grant_id, asset_key);


--
-- Name: compensation_grant_items compensation_grant_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compensation_grant_items
    ADD CONSTRAINT compensation_grant_items_pkey PRIMARY KEY (id);


--
-- Name: compensation_grants compensation_grants_grant_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compensation_grants
    ADD CONSTRAINT compensation_grants_grant_key_unique UNIQUE (grant_key);


--
-- Name: compensation_grants compensation_grants_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compensation_grants
    ADD CONSTRAINT compensation_grants_pkey PRIMARY KEY (id);


--
-- Name: facility_definitions facility_definitions_asset_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.facility_definitions
    ADD CONSTRAINT facility_definitions_asset_key_unique UNIQUE (asset_key);


--
-- Name: facility_definitions facility_definitions_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.facility_definitions
    ADD CONSTRAINT facility_definitions_key_unique UNIQUE (key);


--
-- Name: facility_definitions facility_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.facility_definitions
    ADD CONSTRAINT facility_definitions_pkey PRIMARY KEY (id);


--
-- Name: guide_conversation_topics guide_conversation_topics_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guide_conversation_topics
    ADD CONSTRAINT guide_conversation_topics_pkey PRIMARY KEY (id);


--
-- Name: inquiries inquiries_attachment_path_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inquiries
    ADD CONSTRAINT inquiries_attachment_path_unique UNIQUE (attachment_path);


--
-- Name: inquiries inquiries_attachment_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inquiries
    ADD CONSTRAINT inquiries_attachment_token_unique UNIQUE (attachment_token);


--
-- Name: inquiries inquiries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inquiries
    ADD CONSTRAINT inquiries_pkey PRIMARY KEY (id);


--
-- Name: inquiries inquiries_user_id_submission_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inquiries
    ADD CONSTRAINT inquiries_user_id_submission_key_unique UNIQUE (user_id, submission_key);


--
-- Name: island_messages island_messages_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.island_messages
    ADD CONSTRAINT island_messages_pkey PRIMARY KEY (id);


--
-- Name: island_messages island_messages_public_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.island_messages
    ADD CONSTRAINT island_messages_public_id_unique UNIQUE (public_id);


--
-- Name: secretary_lending_participations lending_participation_battle_member_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_participations
    ADD CONSTRAINT lending_participation_battle_member_unique UNIQUE (underground_battle_id, underground_party_member_id);


--
-- Name: map_cells map_cells_map_space_xy_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_cells
    ADD CONSTRAINT map_cells_map_space_xy_unique UNIQUE (map_space_id, x, y);


--
-- Name: map_cells map_cells_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_cells
    ADD CONSTRAINT map_cells_pkey PRIMARY KEY (id);


--
-- Name: map_chunks map_chunks_map_space_xy_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_chunks
    ADD CONSTRAINT map_chunks_map_space_xy_unique UNIQUE (map_space_id, chunk_x, chunk_y);


--
-- Name: map_chunks map_chunks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_chunks
    ADD CONSTRAINT map_chunks_pkey PRIMARY KEY (id);


--
-- Name: map_spaces map_spaces_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_spaces
    ADD CONSTRAINT map_spaces_pkey PRIMARY KEY (id);


--
-- Name: map_spaces map_spaces_world_id_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_spaces
    ADD CONSTRAINT map_spaces_world_id_key_unique UNIQUE (world_id, key);


--
-- Name: merchant_conversation_topics merchant_conversation_topics_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.merchant_conversation_topics
    ADD CONSTRAINT merchant_conversation_topics_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: moderation_records moderation_records_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.moderation_records
    ADD CONSTRAINT moderation_records_pkey PRIMARY KEY (id);


--
-- Name: monster_definitions monster_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monster_definitions
    ADD CONSTRAINT monster_definitions_pkey PRIMARY KEY (id);


--
-- Name: monster_definitions monster_definitions_ruleset_version_id_asset_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monster_definitions
    ADD CONSTRAINT monster_definitions_ruleset_version_id_asset_key_unique UNIQUE (ruleset_version_id, asset_key);


--
-- Name: monster_definitions monster_definitions_ruleset_version_id_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monster_definitions
    ADD CONSTRAINT monster_definitions_ruleset_version_id_key_unique UNIQUE (ruleset_version_id, key);


--
-- Name: monster_instances monster_instances_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monster_instances
    ADD CONSTRAINT monster_instances_pkey PRIMARY KEY (id);


--
-- Name: monster_occupancies monster_occupancies_map_cell_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monster_occupancies
    ADD CONSTRAINT monster_occupancies_map_cell_id_unique UNIQUE (map_cell_id);


--
-- Name: monster_occupancies monster_occupancies_monster_instance_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monster_occupancies
    ADD CONSTRAINT monster_occupancies_monster_instance_id_unique UNIQUE (monster_instance_id);


--
-- Name: monster_occupancies monster_occupancies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monster_occupancies
    ADD CONSTRAINT monster_occupancies_pkey PRIMARY KEY (id);


--
-- Name: monument_definitions monument_definitions_asset_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monument_definitions
    ADD CONSTRAINT monument_definitions_asset_key_unique UNIQUE (asset_key);


--
-- Name: monument_definitions monument_definitions_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monument_definitions
    ADD CONSTRAINT monument_definitions_key_unique UNIQUE (key);


--
-- Name: monument_definitions monument_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monument_definitions
    ADD CONSTRAINT monument_definitions_pkey PRIMARY KEY (id);


--
-- Name: nation_awards nation_awards_occurrence_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_awards
    ADD CONSTRAINT nation_awards_occurrence_unique UNIQUE (world_id, nation_id, award_key, award_occurrence_key);


--
-- Name: nation_awards nation_awards_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_awards
    ADD CONSTRAINT nation_awards_pkey PRIMARY KEY (id);


--
-- Name: nation_capitals nation_capitals_map_cell_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_capitals
    ADD CONSTRAINT nation_capitals_map_cell_id_unique UNIQUE (map_cell_id);


--
-- Name: nation_capitals nation_capitals_nation_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_capitals
    ADD CONSTRAINT nation_capitals_nation_id_unique UNIQUE (nation_id);


--
-- Name: nation_capitals nation_capitals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_capitals
    ADD CONSTRAINT nation_capitals_pkey PRIMARY KEY (id);


--
-- Name: nation_command_queue_bulk_requests nation_command_queue_bulk_request_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_command_queue_bulk_requests
    ADD CONSTRAINT nation_command_queue_bulk_request_unique UNIQUE (nation_command_queue_id, request_key);


--
-- Name: nation_command_queue_bulk_requests nation_command_queue_bulk_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_command_queue_bulk_requests
    ADD CONSTRAINT nation_command_queue_bulk_requests_pkey PRIMARY KEY (id);


--
-- Name: nation_command_queue_items nation_command_queue_items_nation_command_queue_id_queue_positi; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_command_queue_items
    ADD CONSTRAINT nation_command_queue_items_nation_command_queue_id_queue_positi UNIQUE (nation_command_queue_id, queue_position);


--
-- Name: nation_command_queue_items nation_command_queue_items_nation_command_queue_id_request_key_; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_command_queue_items
    ADD CONSTRAINT nation_command_queue_items_nation_command_queue_id_request_key_ UNIQUE (nation_command_queue_id, request_key);


--
-- Name: nation_command_queue_items nation_command_queue_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_command_queue_items
    ADD CONSTRAINT nation_command_queue_items_pkey PRIMARY KEY (id);


--
-- Name: nation_command_queues nation_command_queues_nation_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_command_queues
    ADD CONSTRAINT nation_command_queues_nation_id_unique UNIQUE (nation_id);


--
-- Name: nation_command_queues nation_command_queues_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_command_queues
    ADD CONSTRAINT nation_command_queues_pkey PRIMARY KEY (id);


--
-- Name: nation_creation_requests nation_creation_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_creation_requests
    ADD CONSTRAINT nation_creation_requests_pkey PRIMARY KEY (id);


--
-- Name: nation_creation_requests nation_creation_requests_request_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_creation_requests
    ADD CONSTRAINT nation_creation_requests_request_key_unique UNIQUE (request_key);


--
-- Name: nation_memberships nation_memberships_nation_id_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_memberships
    ADD CONSTRAINT nation_memberships_nation_id_user_id_unique UNIQUE (nation_id, user_id);


--
-- Name: nation_memberships nation_memberships_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_memberships
    ADD CONSTRAINT nation_memberships_pkey PRIMARY KEY (id);


--
-- Name: nation_memberships nation_memberships_user_id_world_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_memberships
    ADD CONSTRAINT nation_memberships_user_id_world_id_unique UNIQUE (user_id, world_id);


--
-- Name: nation_monster_cycle_seed_requirements nation_monster_cycle_seed_requirement_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_monster_cycle_seed_requirements
    ADD CONSTRAINT nation_monster_cycle_seed_requirement_unique UNIQUE (world_id, nation_id, cycle_start_turn);


--
-- Name: nation_monster_cycle_seed_requirements nation_monster_cycle_seed_requirements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_monster_cycle_seed_requirements
    ADD CONSTRAINT nation_monster_cycle_seed_requirements_pkey PRIMARY KEY (id);


--
-- Name: nation_monster_cycle_stats nation_monster_cycle_stats_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_monster_cycle_stats
    ADD CONSTRAINT nation_monster_cycle_stats_pkey PRIMARY KEY (id);


--
-- Name: nation_monster_cycle_stats nation_monster_cycle_stats_scope_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_monster_cycle_stats
    ADD CONSTRAINT nation_monster_cycle_stats_scope_unique UNIQUE (world_id, nation_id, cycle_start_turn);


--
-- Name: nation_monster_kill_stats nation_monster_kill_stats_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_monster_kill_stats
    ADD CONSTRAINT nation_monster_kill_stats_pkey PRIMARY KEY (id);


--
-- Name: nation_monster_kill_stats nation_monster_kill_stats_scope_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_monster_kill_stats
    ADD CONSTRAINT nation_monster_kill_stats_scope_unique UNIQUE (world_id, nation_id, monster_definition_id);


--
-- Name: nation_resource_sale_policies nation_resource_sale_policies_nation_id_resource_definition_id_; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_resource_sale_policies
    ADD CONSTRAINT nation_resource_sale_policies_nation_id_resource_definition_id_ UNIQUE (nation_id, resource_definition_id);


--
-- Name: nation_resource_sale_policies nation_resource_sale_policies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_resource_sale_policies
    ADD CONSTRAINT nation_resource_sale_policies_pkey PRIMARY KEY (id);


--
-- Name: nation_resources nation_resources_nation_id_resource_definition_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_resources
    ADD CONSTRAINT nation_resources_nation_id_resource_definition_id_unique UNIQUE (nation_id, resource_definition_id);


--
-- Name: nation_resources nation_resources_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_resources
    ADD CONSTRAINT nation_resources_pkey PRIMARY KEY (id);


--
-- Name: nation_underground_facilities nation_underground_facilities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_underground_facilities
    ADD CONSTRAINT nation_underground_facilities_pkey PRIMARY KEY (id);


--
-- Name: nation_underground_facilities nation_underground_facilities_slot_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_underground_facilities
    ADD CONSTRAINT nation_underground_facilities_slot_unique UNIQUE (nation_id, layer, slot_index);


--
-- Name: nations nations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nations
    ADD CONSTRAINT nations_pkey PRIMARY KEY (id);


--
-- Name: nations nations_world_id_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nations
    ADD CONSTRAINT nations_world_id_id_unique UNIQUE (world_id, id);


--
-- Name: nations nations_world_id_name_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nations
    ADD CONSTRAINT nations_world_id_name_unique UNIQUE (world_id, name);


--
-- Name: nations nations_world_id_nation_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nations
    ADD CONSTRAINT nations_world_id_nation_number_unique UNIQUE (world_id, nation_number);


--
-- Name: production_definitions production_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.production_definitions
    ADD CONSTRAINT production_definitions_pkey PRIMARY KEY (id);


--
-- Name: production_definitions production_definitions_ruleset_version_id_facility_definition_i; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.production_definitions
    ADD CONSTRAINT production_definitions_ruleset_version_id_facility_definition_i UNIQUE (ruleset_version_id, facility_definition_id);


--
-- Name: production_definitions production_definitions_ruleset_version_id_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.production_definitions
    ADD CONSTRAINT production_definitions_ruleset_version_id_key_unique UNIQUE (ruleset_version_id, key);


--
-- Name: resource_definitions resource_definitions_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.resource_definitions
    ADD CONSTRAINT resource_definitions_key_unique UNIQUE (key);


--
-- Name: resource_definitions resource_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.resource_definitions
    ADD CONSTRAINT resource_definitions_pkey PRIMARY KEY (id);


--
-- Name: ruleset_versions ruleset_versions_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ruleset_versions
    ADD CONSTRAINT ruleset_versions_key_unique UNIQUE (key);


--
-- Name: ruleset_versions ruleset_versions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ruleset_versions
    ADD CONSTRAINT ruleset_versions_pkey PRIMARY KEY (id);


--
-- Name: secretaries secretaries_main_image_path_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretaries
    ADD CONSTRAINT secretaries_main_image_path_unique UNIQUE (main_image_path);


--
-- Name: secretaries secretaries_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretaries
    ADD CONSTRAINT secretaries_pkey PRIMARY KEY (id);


--
-- Name: secretaries secretaries_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretaries
    ADD CONSTRAINT secretaries_user_id_unique UNIQUE (user_id);


--
-- Name: secretary_gacha_draws secretary_gacha_draws_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_gacha_draws
    ADD CONSTRAINT secretary_gacha_draws_pkey PRIMARY KEY (id);


--
-- Name: secretary_gacha_draws secretary_gacha_draws_secretary_id_request_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_gacha_draws
    ADD CONSTRAINT secretary_gacha_draws_secretary_id_request_key_unique UNIQUE (secretary_id, request_key);


--
-- Name: secretary_guide_conversation_totals secretary_guide_conversation_totals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_guide_conversation_totals
    ADD CONSTRAINT secretary_guide_conversation_totals_pkey PRIMARY KEY (id);


--
-- Name: secretary_guide_conversation_totals secretary_guide_conversation_totals_secretary_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_guide_conversation_totals
    ADD CONSTRAINT secretary_guide_conversation_totals_secretary_id_unique UNIQUE (secretary_id);


--
-- Name: secretary_images secretary_images_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_images
    ADD CONSTRAINT secretary_images_pkey PRIMARY KEY (id);


--
-- Name: secretary_images secretary_images_secretary_id_slot_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_images
    ADD CONSTRAINT secretary_images_secretary_id_slot_unique UNIQUE (secretary_id, slot);


--
-- Name: secretary_item_instances secretary_item_instances_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_item_instances
    ADD CONSTRAINT secretary_item_instances_pkey PRIMARY KEY (id);


--
-- Name: secretary_item_instances secretary_item_instances_secretary_id_grant_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_item_instances
    ADD CONSTRAINT secretary_item_instances_secretary_id_grant_key_unique UNIQUE (secretary_id, grant_key);


--
-- Name: secretary_lending_build_snapshots secretary_lending_build_snapshots_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_build_snapshots
    ADD CONSTRAINT secretary_lending_build_snapshots_pkey PRIMARY KEY (id);


--
-- Name: secretary_lending_build_snapshots secretary_lending_build_snapshots_secretary_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_build_snapshots
    ADD CONSTRAINT secretary_lending_build_snapshots_secretary_id_unique UNIQUE (secretary_id);


--
-- Name: secretary_lending_daily_rewards secretary_lending_daily_rewards_owner_user_id_canonical_day_uni; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_daily_rewards
    ADD CONSTRAINT secretary_lending_daily_rewards_owner_user_id_canonical_day_uni UNIQUE (owner_user_id, canonical_day);


--
-- Name: secretary_lending_daily_rewards secretary_lending_daily_rewards_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_daily_rewards
    ADD CONSTRAINT secretary_lending_daily_rewards_pkey PRIMARY KEY (id);


--
-- Name: secretary_lending_participations secretary_lending_participations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_participations
    ADD CONSTRAINT secretary_lending_participations_pkey PRIMARY KEY (id);


--
-- Name: secretary_lending_settings secretary_lending_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_settings
    ADD CONSTRAINT secretary_lending_settings_pkey PRIMARY KEY (id);


--
-- Name: secretary_lending_settings secretary_lending_settings_secretary_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_settings
    ADD CONSTRAINT secretary_lending_settings_secretary_id_unique UNIQUE (secretary_id);


--
-- Name: secretary_skills secretary_skills_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_skills
    ADD CONSTRAINT secretary_skills_pkey PRIMARY KEY (id);


--
-- Name: secretary_skills secretary_skills_secretary_id_skill_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_skills
    ADD CONSTRAINT secretary_skills_secretary_id_skill_key_unique UNIQUE (secretary_id, skill_key);


--
-- Name: secretary_surface_states secretary_surface_states_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_surface_states
    ADD CONSTRAINT secretary_surface_states_pkey PRIMARY KEY (secretary_id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: ships ships_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ships
    ADD CONSTRAINT ships_pkey PRIMARY KEY (id);


--
-- Name: terrain_definitions terrain_definitions_asset_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.terrain_definitions
    ADD CONSTRAINT terrain_definitions_asset_key_unique UNIQUE (asset_key);


--
-- Name: terrain_definitions terrain_definitions_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.terrain_definitions
    ADD CONSTRAINT terrain_definitions_key_unique UNIQUE (key);


--
-- Name: terrain_definitions terrain_definitions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.terrain_definitions
    ADD CONSTRAINT terrain_definitions_pkey PRIMARY KEY (id);


--
-- Name: turn_runs turn_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.turn_runs
    ADD CONSTRAINT turn_runs_pkey PRIMARY KEY (id);


--
-- Name: underground_battle_image_references underground_battle_image_references_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_battle_image_references
    ADD CONSTRAINT underground_battle_image_references_pkey PRIMARY KEY (id);


--
-- Name: underground_battle_image_references underground_battle_image_refs_key_path_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_battle_image_references
    ADD CONSTRAINT underground_battle_image_refs_key_path_unique UNIQUE (reference_key, path);


--
-- Name: underground_battle_logs underground_battle_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_battle_logs
    ADD CONSTRAINT underground_battle_logs_pkey PRIMARY KEY (id);


--
-- Name: underground_battle_logs underground_battle_logs_underground_battle_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_battle_logs
    ADD CONSTRAINT underground_battle_logs_underground_battle_id_unique UNIQUE (underground_battle_id);


--
-- Name: underground_battles underground_battles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_battles
    ADD CONSTRAINT underground_battles_pkey PRIMARY KEY (id);


--
-- Name: underground_battles underground_battles_profile_request_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_battles
    ADD CONSTRAINT underground_battles_profile_request_unique UNIQUE (underground_profile_id, request_id);


--
-- Name: underground_battles underground_battles_underground_party_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_battles
    ADD CONSTRAINT underground_battles_underground_party_id_unique UNIQUE (underground_party_id);


--
-- Name: underground_content_clear_progress underground_content_clear_progress_identity_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_content_clear_progress
    ADD CONSTRAINT underground_content_clear_progress_identity_unique UNIQUE (underground_profile_id, content_type, content_key);


--
-- Name: underground_content_clear_progress underground_content_clear_progress_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_content_clear_progress
    ADD CONSTRAINT underground_content_clear_progress_pkey PRIMARY KEY (id);


--
-- Name: underground_owned_equipment underground_equipment_instance_identity_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_owned_equipment
    ADD CONSTRAINT underground_equipment_instance_identity_unique UNIQUE (instance_identity);


--
-- Name: underground_owned_equipment underground_equipment_profile_grant_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_owned_equipment
    ADD CONSTRAINT underground_equipment_profile_grant_unique UNIQUE (underground_profile_id, grant_key);


--
-- Name: underground_owned_equipment underground_equipment_profile_slot_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_owned_equipment
    ADD CONSTRAINT underground_equipment_profile_slot_unique UNIQUE (underground_profile_id, equipped_slot);


--
-- Name: underground_owned_equipment underground_equipment_source_skip_batch_reward_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_owned_equipment
    ADD CONSTRAINT underground_equipment_source_skip_batch_reward_unique UNIQUE (source_skip_batch_id, source_reward_index);


--
-- Name: underground_owned_equipment underground_equipment_source_skip_reward_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_owned_equipment
    ADD CONSTRAINT underground_equipment_source_skip_reward_unique UNIQUE (source_skip_settlement_id, source_reward_index);


--
-- Name: underground_intro_progress underground_intro_progress_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_intro_progress
    ADD CONSTRAINT underground_intro_progress_pkey PRIMARY KEY (id);


--
-- Name: underground_intro_progress underground_intro_progress_scripted_loss_battle_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_intro_progress
    ADD CONSTRAINT underground_intro_progress_scripted_loss_battle_id_unique UNIQUE (scripted_loss_battle_id);


--
-- Name: underground_intro_progress underground_intro_progress_tutorial_battle_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_intro_progress
    ADD CONSTRAINT underground_intro_progress_tutorial_battle_id_unique UNIQUE (tutorial_battle_id);


--
-- Name: underground_intro_progress underground_intro_progress_underground_profile_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_intro_progress
    ADD CONSTRAINT underground_intro_progress_underground_profile_id_unique UNIQUE (underground_profile_id);


--
-- Name: underground_intro_requests underground_intro_requests_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_intro_requests
    ADD CONSTRAINT underground_intro_requests_pkey PRIMARY KEY (id);


--
-- Name: underground_intro_requests underground_intro_requests_profile_request_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_intro_requests
    ADD CONSTRAINT underground_intro_requests_profile_request_unique UNIQUE (underground_profile_id, request_id);


--
-- Name: underground_owned_equipment underground_owned_equipment_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_owned_equipment
    ADD CONSTRAINT underground_owned_equipment_pkey PRIMARY KEY (id);


--
-- Name: underground_parties underground_parties_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_parties
    ADD CONSTRAINT underground_parties_pkey PRIMARY KEY (id);


--
-- Name: underground_party_members underground_party_members_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_party_members
    ADD CONSTRAINT underground_party_members_pkey PRIMARY KEY (id);


--
-- Name: underground_party_members underground_party_members_underground_party_id_combatant_id_uni; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_party_members
    ADD CONSTRAINT underground_party_members_underground_party_id_combatant_id_uni UNIQUE (underground_party_id, combatant_id);


--
-- Name: underground_party_members underground_party_members_underground_party_id_secretary_id_uni; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_party_members
    ADD CONSTRAINT underground_party_members_underground_party_id_secretary_id_uni UNIQUE (underground_party_id, secretary_id);


--
-- Name: underground_profiles underground_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_profiles
    ADD CONSTRAINT underground_profiles_pkey PRIMARY KEY (id);


--
-- Name: underground_profiles underground_profiles_secretary_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_profiles
    ADD CONSTRAINT underground_profiles_secretary_id_unique UNIQUE (secretary_id);


--
-- Name: underground_receipt_rollups underground_receipt_rollups_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_receipt_rollups
    ADD CONSTRAINT underground_receipt_rollups_pkey PRIMARY KEY (underground_profile_id, stream);


--
-- Name: underground_skill_allocations underground_skill_allocations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_skill_allocations
    ADD CONSTRAINT underground_skill_allocations_pkey PRIMARY KEY (id);


--
-- Name: underground_skill_allocations underground_skill_profile_node_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_skill_allocations
    ADD CONSTRAINT underground_skill_profile_node_unique UNIQUE (underground_profile_id, node_key);


--
-- Name: underground_skill_allocations underground_skill_profile_slot_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_skill_allocations
    ADD CONSTRAINT underground_skill_profile_slot_unique UNIQUE (underground_profile_id, active_slot);


--
-- Name: underground_skip_batches underground_skip_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_skip_batches
    ADD CONSTRAINT underground_skip_batches_pkey PRIMARY KEY (id);


--
-- Name: underground_skip_batches underground_skip_batches_profile_request_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_skip_batches
    ADD CONSTRAINT underground_skip_batches_profile_request_unique UNIQUE (underground_profile_id, request_id);


--
-- Name: underground_skip_settlements underground_skip_settlements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_skip_settlements
    ADD CONSTRAINT underground_skip_settlements_pkey PRIMARY KEY (id);


--
-- Name: underground_skip_settlements underground_skip_settlements_profile_request_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_skip_settlements
    ADD CONSTRAINT underground_skip_settlements_profile_request_unique UNIQUE (underground_profile_id, request_id);


--
-- Name: underground_trial_progress underground_trial_progress_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_trial_progress
    ADD CONSTRAINT underground_trial_progress_pkey PRIMARY KEY (id);


--
-- Name: underground_trial_progress underground_trial_progress_profile_trial_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_trial_progress
    ADD CONSTRAINT underground_trial_progress_profile_trial_unique UNIQUE (underground_profile_id, trial_key);


--
-- Name: underground_trial_runs underground_trial_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_trial_runs
    ADD CONSTRAINT underground_trial_runs_pkey PRIMARY KEY (id);


--
-- Name: underground_trial_runs underground_trial_runs_run_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_trial_runs
    ADD CONSTRAINT underground_trial_runs_run_key_unique UNIQUE (run_key);


--
-- Name: underground_trial_runs underground_trial_runs_underground_profile_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_trial_runs
    ADD CONSTRAINT underground_trial_runs_underground_profile_id_unique UNIQUE (underground_profile_id);


--
-- Name: user_daily_login_claims user_daily_login_claims_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_daily_login_claims
    ADD CONSTRAINT user_daily_login_claims_pkey PRIMARY KEY (id);


--
-- Name: user_daily_login_claims user_daily_login_claims_user_id_canonical_day_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_daily_login_claims
    ADD CONSTRAINT user_daily_login_claims_user_id_canonical_day_unique UNIQUE (user_id, canonical_day);


--
-- Name: user_daily_quest_activities user_daily_quest_activities_entry_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_daily_quest_activities
    ADD CONSTRAINT user_daily_quest_activities_entry_key_unique UNIQUE (entry_key);


--
-- Name: user_daily_quest_activities user_daily_quest_activities_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_daily_quest_activities
    ADD CONSTRAINT user_daily_quest_activities_pkey PRIMARY KEY (id);


--
-- Name: user_daily_quest_progress user_daily_quest_progress_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_daily_quest_progress
    ADD CONSTRAINT user_daily_quest_progress_pkey PRIMARY KEY (id);


--
-- Name: user_daily_quest_progress user_daily_quest_progress_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_daily_quest_progress
    ADD CONSTRAINT user_daily_quest_progress_unique UNIQUE (user_id, canonical_day, quest_key);


--
-- Name: user_monument_designs user_monument_designs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_monument_designs
    ADD CONSTRAINT user_monument_designs_pkey PRIMARY KEY (id);


--
-- Name: user_monument_designs user_monument_designs_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_monument_designs
    ADD CONSTRAINT user_monument_designs_user_id_unique UNIQUE (user_id);


--
-- Name: user_paradox_balances user_paradox_balances_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_paradox_balances
    ADD CONSTRAINT user_paradox_balances_pkey PRIMARY KEY (id);


--
-- Name: user_paradox_balances user_paradox_balances_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_paradox_balances
    ADD CONSTRAINT user_paradox_balances_user_id_unique UNIQUE (user_id);


--
-- Name: user_paradox_ledger user_paradox_ledger_entry_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_paradox_ledger
    ADD CONSTRAINT user_paradox_ledger_entry_key_unique UNIQUE (entry_key);


--
-- Name: user_paradox_ledger user_paradox_ledger_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_paradox_ledger
    ADD CONSTRAINT user_paradox_ledger_pkey PRIMARY KEY (id);


--
-- Name: user_skip_ticket_balances user_skip_ticket_balances_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_skip_ticket_balances
    ADD CONSTRAINT user_skip_ticket_balances_pkey PRIMARY KEY (id);


--
-- Name: user_skip_ticket_balances user_skip_ticket_balances_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_skip_ticket_balances
    ADD CONSTRAINT user_skip_ticket_balances_user_id_unique UNIQUE (user_id);


--
-- Name: user_skip_ticket_ledger user_skip_ticket_ledger_entry_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_skip_ticket_ledger
    ADD CONSTRAINT user_skip_ticket_ledger_entry_key_unique UNIQUE (entry_key);


--
-- Name: user_skip_ticket_ledger user_skip_ticket_ledger_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_skip_ticket_ledger
    ADD CONSTRAINT user_skip_ticket_ledger_pkey PRIMARY KEY (id);


--
-- Name: user_skip_ticket_ledger user_skip_ticket_ledger_skip_batch_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_skip_ticket_ledger
    ADD CONSTRAINT user_skip_ticket_ledger_skip_batch_unique UNIQUE (underground_skip_batch_id);


--
-- Name: user_skip_ticket_ledger user_skip_ticket_ledger_skip_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_skip_ticket_ledger
    ADD CONSTRAINT user_skip_ticket_ledger_skip_unique UNIQUE (underground_skip_settlement_id);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_visitor_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_visitor_code_unique UNIQUE (visitor_code);


--
-- Name: world_generation_runs world_generation_runs_map_space_id_generator_id_generator_versi; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.world_generation_runs
    ADD CONSTRAINT world_generation_runs_map_space_id_generator_id_generator_versi UNIQUE (map_space_id, generator_id, generator_version, seed);


--
-- Name: world_generation_runs world_generation_runs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.world_generation_runs
    ADD CONSTRAINT world_generation_runs_pkey PRIMARY KEY (id);


--
-- Name: worlds worlds_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.worlds
    ADD CONSTRAINT worlds_key_unique UNIQUE (key);


--
-- Name: worlds worlds_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.worlds
    ADD CONSTRAINT worlds_pkey PRIMARY KEY (id);


--
-- Name: announcements_created_at_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX announcements_created_at_id_index ON public.announcements USING btree (created_at, id);


--
-- Name: auction_bids_bidder_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auction_bids_bidder_index ON public.auction_bids USING btree (bidder_nation_id, id);


--
-- Name: auction_bids_one_highest_per_listing; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX auction_bids_one_highest_per_listing ON public.auction_bids USING btree (auction_listing_id) WHERE ((status)::text = 'highest'::text);


--
-- Name: auction_listings_active_item_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX auction_listings_active_item_unique ON public.auction_listings USING btree (secretary_item_instance_id) WHERE (((status)::text = 'active'::text) AND (secretary_item_instance_id IS NOT NULL));


--
-- Name: auction_listings_active_seller_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auction_listings_active_seller_index ON public.auction_listings USING btree (seller_nation_id, id) WHERE ((status)::text = 'active'::text);


--
-- Name: auction_listings_active_world_end_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX auction_listings_active_world_end_index ON public.auction_listings USING btree (world_id, ends_turn, id) WHERE ((status)::text = 'active'::text);


--
-- Name: audit_events_admin_request_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX audit_events_admin_request_unique ON public.audit_events USING btree (((metadata ->> 'request_id'::text))) WHERE ((event_type)::text = 'admin.operation_completed'::text);


--
-- Name: audit_events_nation_turn; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_events_nation_turn ON public.audit_events USING btree (nation_id, turn, id);


--
-- Name: audit_events_player_world_turn_id_desc_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_events_player_world_turn_id_desc_idx ON public.audit_events USING btree (((metadata ->> 'world_id'::text)), (((metadata ->> 'target_turn'::text))::bigint) DESC, id DESC) WHERE jsonb_exists(metadata, 'target_turn'::text);


--
-- Name: audit_events_subject_type_subject_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_events_subject_type_subject_id_index ON public.audit_events USING btree (subject_type, subject_id);


--
-- Name: audit_events_visibility_turn; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_events_visibility_turn ON public.audit_events USING btree (world_id, visibility, turn);


--
-- Name: audit_events_world_turn; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_events_world_turn ON public.audit_events USING btree (world_id, turn, id);


--
-- Name: buried_treasures_map_cell_id_state_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX buried_treasures_map_cell_id_state_id_index ON public.buried_treasures USING btree (map_cell_id, state, id);


--
-- Name: buried_treasures_world_id_state_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX buried_treasures_world_id_state_index ON public.buried_treasures USING btree (world_id, state);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: command_queue_active_order; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX command_queue_active_order ON public.nation_command_queue_items USING btree (nation_command_queue_id, status, queue_position);


--
-- Name: compensation_grant_claims_compensation_grant_id_created_at_inde; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX compensation_grant_claims_compensation_grant_id_created_at_inde ON public.compensation_grant_claims USING btree (compensation_grant_id, created_at);


--
-- Name: compensation_grants_expiry_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX compensation_grants_expiry_index ON public.compensation_grants USING btree (recipient_user_id, expires_at);


--
-- Name: compensation_grants_nation_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX compensation_grants_nation_id_status_index ON public.compensation_grants USING btree (nation_id, status);


--
-- Name: compensation_grants_recipient_user_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX compensation_grants_recipient_user_id_status_index ON public.compensation_grants USING btree (recipient_user_id, status);


--
-- Name: guide_conversation_topics_enabled_unlock_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX guide_conversation_topics_enabled_unlock_key_index ON public.guide_conversation_topics USING btree (enabled, unlock_key);


--
-- Name: inquiries_created_at_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inquiries_created_at_id_index ON public.inquiries USING btree (created_at, id);


--
-- Name: island_messages_author_cooldown_audit_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX island_messages_author_cooldown_audit_idx ON public.island_messages USING btree (author_user_id, created_at);


--
-- Name: island_messages_sender_timeline_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX island_messages_sender_timeline_idx ON public.island_messages USING btree (secret_sender_nation_id, created_at, id);


--
-- Name: island_messages_target_timeline_idx; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX island_messages_target_timeline_idx ON public.island_messages USING btree (target_nation_id, created_at, id);


--
-- Name: map_cells_facility_definition_id_facility_experience_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX map_cells_facility_definition_id_facility_experience_index ON public.map_cells USING btree (facility_definition_id, facility_experience);


--
-- Name: map_cells_facility_definition_id_facility_scale_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX map_cells_facility_definition_id_facility_scale_index ON public.map_cells USING btree (facility_definition_id, facility_scale);


--
-- Name: map_cells_map_space_chunk_xy_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX map_cells_map_space_chunk_xy_index ON public.map_cells USING btree (map_space_id, chunk_x, chunk_y);


--
-- Name: map_cells_owner_nation_id_map_space_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX map_cells_owner_nation_id_map_space_id_index ON public.map_cells USING btree (owner_nation_id, map_space_id);


--
-- Name: moderation_records_occurred; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX moderation_records_occurred ON public.moderation_records USING btree (occurred_at, id);


--
-- Name: moderation_records_target; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX moderation_records_target ON public.moderation_records USING btree (target_type, target_id, id);


--
-- Name: monster_definitions_ruleset_display_order_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX monster_definitions_ruleset_display_order_unique ON public.monster_definitions USING btree (ruleset_version_id, display_order) WHERE (display_order IS NOT NULL);


--
-- Name: monster_instances_world_id_state_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX monster_instances_world_id_state_index ON public.monster_instances USING btree (world_id, state);


--
-- Name: nation_awards_world_id_nation_id_awarded_turn_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX nation_awards_world_id_nation_id_awarded_turn_index ON public.nation_awards USING btree (world_id, nation_id, awarded_turn);


--
-- Name: nation_creation_requests_user_world_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX nation_creation_requests_user_world_index ON public.nation_creation_requests USING btree (user_id, world_id);


--
-- Name: nation_monster_cycle_seed_requirements_world_id_cycle_start_tur; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX nation_monster_cycle_seed_requirements_world_id_cycle_start_tur ON public.nation_monster_cycle_seed_requirements USING btree (world_id, cycle_start_turn, completed_at);


--
-- Name: nation_monster_cycle_stats_world_id_cycle_start_turn_kill_count; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX nation_monster_cycle_stats_world_id_cycle_start_turn_kill_count ON public.nation_monster_cycle_stats USING btree (world_id, cycle_start_turn, kill_count);


--
-- Name: nation_monster_kill_stats_world_id_nation_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX nation_monster_kill_stats_world_id_nation_id_index ON public.nation_monster_kill_stats USING btree (world_id, nation_id);


--
-- Name: resource_definitions_category_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX resource_definitions_category_index ON public.resource_definitions USING btree (category);


--
-- Name: secretary_item_instances_equipped_slot_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX secretary_item_instances_equipped_slot_unique ON public.secretary_item_instances USING btree (secretary_id, equipped_slot) WHERE (equipped_slot IS NOT NULL);


--
-- Name: secretary_item_instances_old_bow_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX secretary_item_instances_old_bow_unique ON public.secretary_item_instances USING btree (secretary_id) WHERE ((item_key)::text = 'old_bow'::text);


--
-- Name: secretary_item_instances_secretary_id_obtained_at_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX secretary_item_instances_secretary_id_obtained_at_id_index ON public.secretary_item_instances USING btree (secretary_id, obtained_at, id);


--
-- Name: secretary_lending_participations_owner_user_id_canonical_day_in; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX secretary_lending_participations_owner_user_id_canonical_day_in ON public.secretary_lending_participations USING btree (owner_user_id, canonical_day);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: ships_active_map_cell_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX ships_active_map_cell_unique ON public.ships USING btree (map_cell_id) WHERE ((state)::text = 'active'::text);


--
-- Name: ships_nation_id_state_ship_type_key_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ships_nation_id_state_ship_type_key_index ON public.ships USING btree (nation_id, state, ship_type_key);


--
-- Name: ships_world_id_state_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ships_world_id_state_index ON public.ships USING btree (world_id, state);


--
-- Name: turn_runs_world_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX turn_runs_world_id_created_at_index ON public.turn_runs USING btree (world_id, created_at);


--
-- Name: turn_runs_world_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX turn_runs_world_id_status_index ON public.turn_runs USING btree (world_id, status);


--
-- Name: turn_runs_world_target_live_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX turn_runs_world_target_live_unique ON public.turn_runs USING btree (world_id, target_turn) WHERE (is_dry_run = false);


--
-- Name: underground_battle_image_refs_battle_path_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX underground_battle_image_refs_battle_path_index ON public.underground_battle_image_references USING btree (underground_battle_id, path);


--
-- Name: underground_battle_image_refs_path_until_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX underground_battle_image_refs_path_until_index ON public.underground_battle_image_references USING btree (path, retained_until);


--
-- Name: underground_battle_logs_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX underground_battle_logs_expires_at_index ON public.underground_battle_logs USING btree (expires_at);


--
-- Name: underground_battles_profile_finished_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX underground_battles_profile_finished_at_index ON public.underground_battles USING btree (underground_profile_id, finished_at);


--
-- Name: underground_battles_rollup_cursor_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX underground_battles_rollup_cursor_index ON public.underground_battles USING btree (underground_profile_id, id);


--
-- Name: underground_battles_statistics_range_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX underground_battles_statistics_range_index ON public.underground_battles USING btree (activity_type, statistics_version, finished_at);


--
-- Name: underground_equipment_battle_reward_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX underground_equipment_battle_reward_unique ON public.underground_owned_equipment USING btree (source_battle_id, COALESCE(source_reward_index, (1)::bigint)) WHERE (source_battle_id IS NOT NULL);


--
-- Name: underground_equipment_vault_page_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX underground_equipment_vault_page_index ON public.underground_owned_equipment USING btree (underground_profile_id, acquired_at, id);


--
-- Name: underground_intro_requests_rollup_cursor_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX underground_intro_requests_rollup_cursor_index ON public.underground_intro_requests USING btree (underground_profile_id, id);


--
-- Name: underground_parties_leader_user_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX underground_parties_leader_user_id_created_at_index ON public.underground_parties USING btree (leader_user_id, created_at);


--
-- Name: underground_party_members_source_owner_user_id_source_type_inde; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX underground_party_members_source_owner_user_id_source_type_inde ON public.underground_party_members USING btree (source_owner_user_id, source_type);


--
-- Name: underground_skip_batches_rollup_cursor_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX underground_skip_batches_rollup_cursor_index ON public.underground_skip_batches USING btree (underground_profile_id, id);


--
-- Name: underground_skip_batches_underground_profile_id_content_type_co; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX underground_skip_batches_underground_profile_id_content_type_co ON public.underground_skip_batches USING btree (underground_profile_id, content_type, content_key);


--
-- Name: underground_skip_settlements_rollup_cursor_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX underground_skip_settlements_rollup_cursor_index ON public.underground_skip_settlements USING btree (underground_profile_id, id);


--
-- Name: underground_skip_settlements_underground_profile_id_content_typ; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX underground_skip_settlements_underground_profile_id_content_typ ON public.underground_skip_settlements USING btree (underground_profile_id, content_type, content_key);


--
-- Name: underground_trial_runs_profile_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX underground_trial_runs_profile_status_index ON public.underground_trial_runs USING btree (underground_profile_id, status);


--
-- Name: user_daily_quest_activity_progress_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_daily_quest_activity_progress_index ON public.user_daily_quest_activities USING btree (user_id, canonical_day, quest_key);


--
-- Name: user_paradox_ledger_user_id_canonical_day_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_paradox_ledger_user_id_canonical_day_index ON public.user_paradox_ledger USING btree (user_id, canonical_day);


--
-- Name: user_paradox_ledger_user_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_paradox_ledger_user_id_created_at_index ON public.user_paradox_ledger USING btree (user_id, created_at);


--
-- Name: user_skip_ticket_ledger_user_id_canonical_day_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX user_skip_ticket_ledger_user_id_canonical_day_index ON public.user_skip_ticket_ledger USING btree (user_id, canonical_day);


--
-- Name: buried_treasures buried_treasure_identity_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER buried_treasure_identity_guard BEFORE INSERT OR UPDATE ON public.buried_treasures FOR EACH ROW EXECUTE FUNCTION public.validate_buried_treasure_identity();


--
-- Name: monster_instances monster_instance_world_ruleset_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER monster_instance_world_ruleset_guard BEFORE INSERT OR UPDATE OF world_id, monster_definition_id, spawned_max_hp ON public.monster_instances FOR EACH ROW EXECUTE FUNCTION public.validate_monster_instance_world_ruleset();


--
-- Name: monster_occupancies monster_occupancy_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER monster_occupancy_guard BEFORE INSERT OR UPDATE ON public.monster_occupancies FOR EACH ROW EXECUTE FUNCTION public.validate_monster_occupancy();


--
-- Name: nation_awards nation_award_delete_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER nation_award_delete_guard BEFORE DELETE ON public.nation_awards FOR EACH ROW EXECUTE FUNCTION public.reject_nation_achievement_delete();


--
-- Name: nation_awards nation_award_update_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER nation_award_update_guard BEFORE UPDATE ON public.nation_awards FOR EACH ROW EXECUTE FUNCTION public.reject_nation_award_update();


--
-- Name: nation_awards nation_award_world_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER nation_award_world_guard BEFORE INSERT OR UPDATE OF world_id, nation_id ON public.nation_awards FOR EACH ROW EXECUTE FUNCTION public.validate_nation_achievement_world();


--
-- Name: nation_command_queue_items nation_command_queue_items_world_ruleset_match; Type: TRIGGER; Schema: public; Owner: -
--

CREATE CONSTRAINT TRIGGER nation_command_queue_items_world_ruleset_match AFTER INSERT OR UPDATE OF nation_command_queue_id, command_definition_id, status ON public.nation_command_queue_items DEFERRABLE INITIALLY IMMEDIATE FOR EACH ROW EXECUTE FUNCTION public.enforce_queue_item_world_ruleset_match();


--
-- Name: nation_monster_cycle_stats nation_monster_cycle_delete_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER nation_monster_cycle_delete_guard BEFORE DELETE ON public.nation_monster_cycle_stats FOR EACH ROW EXECUTE FUNCTION public.reject_nation_achievement_delete();


--
-- Name: nation_monster_cycle_seed_requirements nation_monster_cycle_seed_requirement_delete_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER nation_monster_cycle_seed_requirement_delete_guard BEFORE DELETE ON public.nation_monster_cycle_seed_requirements FOR EACH ROW EXECUTE FUNCTION public.reject_nation_achievement_delete();


--
-- Name: nation_monster_cycle_seed_requirements nation_monster_cycle_seed_requirement_update_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER nation_monster_cycle_seed_requirement_update_guard BEFORE INSERT OR UPDATE ON public.nation_monster_cycle_seed_requirements FOR EACH ROW EXECUTE FUNCTION public.validate_nation_monster_cycle_seed_requirement_update();


--
-- Name: nation_monster_cycle_seed_requirements nation_monster_cycle_seed_requirement_world_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER nation_monster_cycle_seed_requirement_world_guard BEFORE INSERT OR UPDATE OF world_id, nation_id ON public.nation_monster_cycle_seed_requirements FOR EACH ROW EXECUTE FUNCTION public.validate_nation_achievement_world();


--
-- Name: nation_monster_cycle_stats nation_monster_cycle_update_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER nation_monster_cycle_update_guard BEFORE UPDATE ON public.nation_monster_cycle_stats FOR EACH ROW EXECUTE FUNCTION public.validate_nation_monster_cycle_update();


--
-- Name: nation_monster_cycle_stats nation_monster_cycle_world_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER nation_monster_cycle_world_guard BEFORE INSERT OR UPDATE OF world_id, nation_id ON public.nation_monster_cycle_stats FOR EACH ROW EXECUTE FUNCTION public.validate_nation_achievement_world();


--
-- Name: nation_monster_kill_stats nation_monster_kill_stat_delete_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER nation_monster_kill_stat_delete_guard BEFORE DELETE ON public.nation_monster_kill_stats FOR EACH ROW EXECUTE FUNCTION public.reject_nation_monster_kill_stat_delete();


--
-- Name: nation_monster_kill_stats nation_monster_kill_stat_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER nation_monster_kill_stat_guard BEFORE INSERT OR UPDATE ON public.nation_monster_kill_stats FOR EACH ROW EXECUTE FUNCTION public.validate_nation_monster_kill_stat();


--
-- Name: map_cells surface_ship_cell_mutation_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER surface_ship_cell_mutation_guard BEFORE UPDATE OF map_space_id, terrain_definition_id, facility_definition_id ON public.map_cells FOR EACH ROW EXECUTE FUNCTION public.validate_surface_ship_cell_mutation();


--
-- Name: ships surface_ship_identity_guard; Type: TRIGGER; Schema: public; Owner: -
--

CREATE TRIGGER surface_ship_identity_guard BEFORE INSERT OR UPDATE OF world_id, ruleset_version_id, nation_id, map_cell_id, ship_type_key, max_hp, state ON public.ships FOR EACH ROW EXECUTE FUNCTION public.validate_surface_ship_identity();


--
-- Name: auction_bids auction_bids_auction_listing_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_bids
    ADD CONSTRAINT auction_bids_auction_listing_id_foreign FOREIGN KEY (auction_listing_id) REFERENCES public.auction_listings(id) ON DELETE RESTRICT;


--
-- Name: auction_bids auction_bids_bidder_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_bids
    ADD CONSTRAINT auction_bids_bidder_nation_id_foreign FOREIGN KEY (bidder_nation_id) REFERENCES public.nations(id) ON DELETE RESTRICT;


--
-- Name: auction_listings auction_listings_highest_bidder_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_listings
    ADD CONSTRAINT auction_listings_highest_bidder_nation_id_foreign FOREIGN KEY (highest_bidder_nation_id) REFERENCES public.nations(id) ON DELETE RESTRICT;


--
-- Name: auction_listings auction_listings_resource_definition_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_listings
    ADD CONSTRAINT auction_listings_resource_definition_id_foreign FOREIGN KEY (resource_definition_id) REFERENCES public.resource_definitions(id) ON DELETE RESTRICT;


--
-- Name: auction_listings auction_listings_secretary_item_instance_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_listings
    ADD CONSTRAINT auction_listings_secretary_item_instance_id_foreign FOREIGN KEY (secretary_item_instance_id) REFERENCES public.secretary_item_instances(id) ON DELETE SET NULL;


--
-- Name: auction_listings auction_listings_seller_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_listings
    ADD CONSTRAINT auction_listings_seller_nation_id_foreign FOREIGN KEY (seller_nation_id) REFERENCES public.nations(id) ON DELETE RESTRICT;


--
-- Name: auction_listings auction_listings_world_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auction_listings
    ADD CONSTRAINT auction_listings_world_id_foreign FOREIGN KEY (world_id) REFERENCES public.worlds(id) ON DELETE RESTRICT;


--
-- Name: audit_events audit_events_actor_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_events
    ADD CONSTRAINT audit_events_actor_user_id_foreign FOREIGN KEY (actor_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: audit_events audit_events_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_events
    ADD CONSTRAINT audit_events_nation_id_foreign FOREIGN KEY (nation_id) REFERENCES public.nations(id) ON DELETE SET NULL;


--
-- Name: audit_events audit_events_world_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_events
    ADD CONSTRAINT audit_events_world_id_foreign FOREIGN KEY (world_id) REFERENCES public.worlds(id) ON DELETE CASCADE;


--
-- Name: auth_identities auth_identities_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.auth_identities
    ADD CONSTRAINT auth_identities_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: buried_treasures buried_treasures_map_cell_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.buried_treasures
    ADD CONSTRAINT buried_treasures_map_cell_id_foreign FOREIGN KEY (map_cell_id) REFERENCES public.map_cells(id) ON DELETE CASCADE;


--
-- Name: buried_treasures buried_treasures_resolved_by_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.buried_treasures
    ADD CONSTRAINT buried_treasures_resolved_by_nation_id_foreign FOREIGN KEY (resolved_by_nation_id) REFERENCES public.nations(id) ON DELETE SET NULL;


--
-- Name: buried_treasures buried_treasures_world_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.buried_treasures
    ADD CONSTRAINT buried_treasures_world_id_foreign FOREIGN KEY (world_id) REFERENCES public.worlds(id) ON DELETE CASCADE;


--
-- Name: command_definitions command_definitions_ruleset_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.command_definitions
    ADD CONSTRAINT command_definitions_ruleset_version_id_foreign FOREIGN KEY (ruleset_version_id) REFERENCES public.ruleset_versions(id) ON DELETE CASCADE;


--
-- Name: compensation_grant_claims compensation_grant_claims_compensation_grant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compensation_grant_claims
    ADD CONSTRAINT compensation_grant_claims_compensation_grant_id_foreign FOREIGN KEY (compensation_grant_id) REFERENCES public.compensation_grants(id) ON DELETE RESTRICT;


--
-- Name: compensation_grant_claims compensation_grant_claims_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compensation_grant_claims
    ADD CONSTRAINT compensation_grant_claims_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: compensation_grant_items compensation_grant_items_compensation_grant_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compensation_grant_items
    ADD CONSTRAINT compensation_grant_items_compensation_grant_id_foreign FOREIGN KEY (compensation_grant_id) REFERENCES public.compensation_grants(id) ON DELETE CASCADE;


--
-- Name: compensation_grants compensation_grants_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compensation_grants
    ADD CONSTRAINT compensation_grants_nation_id_foreign FOREIGN KEY (nation_id) REFERENCES public.nations(id) ON DELETE RESTRICT;


--
-- Name: compensation_grants compensation_grants_recipient_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compensation_grants
    ADD CONSTRAINT compensation_grants_recipient_user_id_foreign FOREIGN KEY (recipient_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: compensation_grants compensation_grants_world_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compensation_grants
    ADD CONSTRAINT compensation_grants_world_id_foreign FOREIGN KEY (world_id) REFERENCES public.worlds(id) ON DELETE RESTRICT;


--
-- Name: guide_conversation_topics guide_conversation_topics_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guide_conversation_topics
    ADD CONSTRAINT guide_conversation_topics_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: guide_conversation_topics guide_conversation_topics_updated_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.guide_conversation_topics
    ADD CONSTRAINT guide_conversation_topics_updated_by_user_id_foreign FOREIGN KEY (updated_by_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: inquiries inquiries_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inquiries
    ADD CONSTRAINT inquiries_nation_id_foreign FOREIGN KEY (nation_id) REFERENCES public.nations(id) ON DELETE SET NULL;


--
-- Name: inquiries inquiries_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inquiries
    ADD CONSTRAINT inquiries_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: inquiries inquiries_world_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inquiries
    ADD CONSTRAINT inquiries_world_id_foreign FOREIGN KEY (world_id) REFERENCES public.worlds(id) ON DELETE RESTRICT;


--
-- Name: island_messages island_messages_author_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.island_messages
    ADD CONSTRAINT island_messages_author_user_id_foreign FOREIGN KEY (author_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: island_messages island_messages_author_world_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.island_messages
    ADD CONSTRAINT island_messages_author_world_fk FOREIGN KEY (world_id, author_nation_id) REFERENCES public.nations(world_id, id) ON DELETE RESTRICT;


--
-- Name: island_messages island_messages_sender_world_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.island_messages
    ADD CONSTRAINT island_messages_sender_world_fk FOREIGN KEY (world_id, secret_sender_nation_id) REFERENCES public.nations(world_id, id) ON DELETE RESTRICT;


--
-- Name: island_messages island_messages_target_world_fk; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.island_messages
    ADD CONSTRAINT island_messages_target_world_fk FOREIGN KEY (world_id, target_nation_id) REFERENCES public.nations(world_id, id) ON DELETE RESTRICT;


--
-- Name: island_messages island_messages_world_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.island_messages
    ADD CONSTRAINT island_messages_world_id_foreign FOREIGN KEY (world_id) REFERENCES public.worlds(id) ON DELETE CASCADE;


--
-- Name: map_cells map_cells_facility_definition_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_cells
    ADD CONSTRAINT map_cells_facility_definition_id_foreign FOREIGN KEY (facility_definition_id) REFERENCES public.facility_definitions(id);


--
-- Name: map_cells map_cells_map_chunk_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_cells
    ADD CONSTRAINT map_cells_map_chunk_id_foreign FOREIGN KEY (map_chunk_id) REFERENCES public.map_chunks(id) ON DELETE CASCADE;


--
-- Name: map_cells map_cells_map_space_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_cells
    ADD CONSTRAINT map_cells_map_space_id_foreign FOREIGN KEY (map_space_id) REFERENCES public.map_spaces(id) ON DELETE CASCADE;


--
-- Name: map_cells map_cells_monument_definition_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_cells
    ADD CONSTRAINT map_cells_monument_definition_id_foreign FOREIGN KEY (monument_definition_id) REFERENCES public.monument_definitions(id) ON DELETE RESTRICT;


--
-- Name: map_cells map_cells_monument_design_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_cells
    ADD CONSTRAINT map_cells_monument_design_id_foreign FOREIGN KEY (monument_design_id) REFERENCES public.user_monument_designs(id) ON DELETE SET NULL;


--
-- Name: map_cells map_cells_owner_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_cells
    ADD CONSTRAINT map_cells_owner_nation_id_foreign FOREIGN KEY (owner_nation_id) REFERENCES public.nations(id) ON DELETE SET NULL;


--
-- Name: map_cells map_cells_terrain_definition_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_cells
    ADD CONSTRAINT map_cells_terrain_definition_id_foreign FOREIGN KEY (terrain_definition_id) REFERENCES public.terrain_definitions(id);


--
-- Name: map_chunks map_chunks_map_space_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_chunks
    ADD CONSTRAINT map_chunks_map_space_id_foreign FOREIGN KEY (map_space_id) REFERENCES public.map_spaces(id) ON DELETE CASCADE;


--
-- Name: map_spaces map_spaces_world_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.map_spaces
    ADD CONSTRAINT map_spaces_world_id_foreign FOREIGN KEY (world_id) REFERENCES public.worlds(id) ON DELETE CASCADE;


--
-- Name: merchant_conversation_topics merchant_conversation_topics_created_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.merchant_conversation_topics
    ADD CONSTRAINT merchant_conversation_topics_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: merchant_conversation_topics merchant_conversation_topics_updated_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.merchant_conversation_topics
    ADD CONSTRAINT merchant_conversation_topics_updated_by_user_id_foreign FOREIGN KEY (updated_by_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: monster_definitions monster_definitions_ruleset_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monster_definitions
    ADD CONSTRAINT monster_definitions_ruleset_version_id_foreign FOREIGN KEY (ruleset_version_id) REFERENCES public.ruleset_versions(id) ON DELETE CASCADE;


--
-- Name: monster_instances monster_instances_monster_definition_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monster_instances
    ADD CONSTRAINT monster_instances_monster_definition_id_foreign FOREIGN KEY (monster_definition_id) REFERENCES public.monster_definitions(id) ON DELETE RESTRICT;


--
-- Name: monster_instances monster_instances_world_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monster_instances
    ADD CONSTRAINT monster_instances_world_id_foreign FOREIGN KEY (world_id) REFERENCES public.worlds(id) ON DELETE CASCADE;


--
-- Name: monster_occupancies monster_occupancies_map_cell_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monster_occupancies
    ADD CONSTRAINT monster_occupancies_map_cell_id_foreign FOREIGN KEY (map_cell_id) REFERENCES public.map_cells(id) ON DELETE CASCADE;


--
-- Name: monster_occupancies monster_occupancies_monster_instance_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.monster_occupancies
    ADD CONSTRAINT monster_occupancies_monster_instance_id_foreign FOREIGN KEY (monster_instance_id) REFERENCES public.monster_instances(id) ON DELETE CASCADE;


--
-- Name: nation_awards nation_awards_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_awards
    ADD CONSTRAINT nation_awards_nation_id_foreign FOREIGN KEY (nation_id) REFERENCES public.nations(id) ON DELETE CASCADE;


--
-- Name: nation_awards nation_awards_world_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_awards
    ADD CONSTRAINT nation_awards_world_id_foreign FOREIGN KEY (world_id) REFERENCES public.worlds(id) ON DELETE CASCADE;


--
-- Name: nation_capitals nation_capitals_map_cell_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_capitals
    ADD CONSTRAINT nation_capitals_map_cell_id_foreign FOREIGN KEY (map_cell_id) REFERENCES public.map_cells(id) ON DELETE CASCADE;


--
-- Name: nation_capitals nation_capitals_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_capitals
    ADD CONSTRAINT nation_capitals_nation_id_foreign FOREIGN KEY (nation_id) REFERENCES public.nations(id) ON DELETE CASCADE;


--
-- Name: nation_command_queue_bulk_requests nation_command_queue_bulk_requests_nation_command_queue_id_fore; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_command_queue_bulk_requests
    ADD CONSTRAINT nation_command_queue_bulk_requests_nation_command_queue_id_fore FOREIGN KEY (nation_command_queue_id) REFERENCES public.nation_command_queues(id) ON DELETE CASCADE;


--
-- Name: nation_command_queue_items nation_command_queue_items_command_definition_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_command_queue_items
    ADD CONSTRAINT nation_command_queue_items_command_definition_id_foreign FOREIGN KEY (command_definition_id) REFERENCES public.command_definitions(id) ON DELETE RESTRICT;


--
-- Name: nation_command_queue_items nation_command_queue_items_nation_command_queue_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_command_queue_items
    ADD CONSTRAINT nation_command_queue_items_nation_command_queue_id_foreign FOREIGN KEY (nation_command_queue_id) REFERENCES public.nation_command_queues(id) ON DELETE CASCADE;


--
-- Name: nation_command_queue_items nation_command_queue_items_queued_by_membership_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_command_queue_items
    ADD CONSTRAINT nation_command_queue_items_queued_by_membership_id_foreign FOREIGN KEY (queued_by_membership_id) REFERENCES public.nation_memberships(id) ON DELETE RESTRICT;


--
-- Name: nation_command_queue_items nation_command_queue_items_request_ruleset_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_command_queue_items
    ADD CONSTRAINT nation_command_queue_items_request_ruleset_version_id_foreign FOREIGN KEY (request_ruleset_version_id) REFERENCES public.ruleset_versions(id) ON DELETE RESTRICT;


--
-- Name: nation_command_queues nation_command_queues_map_space_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_command_queues
    ADD CONSTRAINT nation_command_queues_map_space_id_foreign FOREIGN KEY (map_space_id) REFERENCES public.map_spaces(id) ON DELETE CASCADE;


--
-- Name: nation_command_queues nation_command_queues_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_command_queues
    ADD CONSTRAINT nation_command_queues_nation_id_foreign FOREIGN KEY (nation_id) REFERENCES public.nations(id) ON DELETE CASCADE;


--
-- Name: nation_creation_requests nation_creation_requests_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_creation_requests
    ADD CONSTRAINT nation_creation_requests_nation_id_foreign FOREIGN KEY (nation_id) REFERENCES public.nations(id) ON DELETE SET NULL;


--
-- Name: nation_creation_requests nation_creation_requests_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_creation_requests
    ADD CONSTRAINT nation_creation_requests_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: nation_creation_requests nation_creation_requests_world_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_creation_requests
    ADD CONSTRAINT nation_creation_requests_world_id_foreign FOREIGN KEY (world_id) REFERENCES public.worlds(id) ON DELETE CASCADE;


--
-- Name: nation_memberships nation_memberships_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_memberships
    ADD CONSTRAINT nation_memberships_nation_id_foreign FOREIGN KEY (nation_id) REFERENCES public.nations(id) ON DELETE CASCADE;


--
-- Name: nation_memberships nation_memberships_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_memberships
    ADD CONSTRAINT nation_memberships_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: nation_memberships nation_memberships_world_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_memberships
    ADD CONSTRAINT nation_memberships_world_id_foreign FOREIGN KEY (world_id) REFERENCES public.worlds(id) ON DELETE CASCADE;


--
-- Name: nation_monster_cycle_seed_requirements nation_monster_cycle_seed_requirements_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_monster_cycle_seed_requirements
    ADD CONSTRAINT nation_monster_cycle_seed_requirements_nation_id_foreign FOREIGN KEY (nation_id) REFERENCES public.nations(id) ON DELETE CASCADE;


--
-- Name: nation_monster_cycle_seed_requirements nation_monster_cycle_seed_requirements_world_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_monster_cycle_seed_requirements
    ADD CONSTRAINT nation_monster_cycle_seed_requirements_world_id_foreign FOREIGN KEY (world_id) REFERENCES public.worlds(id) ON DELETE CASCADE;


--
-- Name: nation_monster_cycle_stats nation_monster_cycle_stats_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_monster_cycle_stats
    ADD CONSTRAINT nation_monster_cycle_stats_nation_id_foreign FOREIGN KEY (nation_id) REFERENCES public.nations(id) ON DELETE CASCADE;


--
-- Name: nation_monster_cycle_stats nation_monster_cycle_stats_world_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_monster_cycle_stats
    ADD CONSTRAINT nation_monster_cycle_stats_world_id_foreign FOREIGN KEY (world_id) REFERENCES public.worlds(id) ON DELETE CASCADE;


--
-- Name: nation_monster_kill_stats nation_monster_kill_stats_monster_definition_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_monster_kill_stats
    ADD CONSTRAINT nation_monster_kill_stats_monster_definition_id_foreign FOREIGN KEY (monster_definition_id) REFERENCES public.monster_definitions(id) ON DELETE RESTRICT;


--
-- Name: nation_monster_kill_stats nation_monster_kill_stats_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_monster_kill_stats
    ADD CONSTRAINT nation_monster_kill_stats_nation_id_foreign FOREIGN KEY (nation_id) REFERENCES public.nations(id) ON DELETE CASCADE;


--
-- Name: nation_monster_kill_stats nation_monster_kill_stats_world_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_monster_kill_stats
    ADD CONSTRAINT nation_monster_kill_stats_world_id_foreign FOREIGN KEY (world_id) REFERENCES public.worlds(id) ON DELETE CASCADE;


--
-- Name: nation_resource_sale_policies nation_resource_sale_policies_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_resource_sale_policies
    ADD CONSTRAINT nation_resource_sale_policies_nation_id_foreign FOREIGN KEY (nation_id) REFERENCES public.nations(id) ON DELETE CASCADE;


--
-- Name: nation_resource_sale_policies nation_resource_sale_policies_resource_definition_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_resource_sale_policies
    ADD CONSTRAINT nation_resource_sale_policies_resource_definition_id_foreign FOREIGN KEY (resource_definition_id) REFERENCES public.resource_definitions(id) ON DELETE CASCADE;


--
-- Name: nation_resources nation_resources_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_resources
    ADD CONSTRAINT nation_resources_nation_id_foreign FOREIGN KEY (nation_id) REFERENCES public.nations(id) ON DELETE CASCADE;


--
-- Name: nation_resources nation_resources_resource_definition_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_resources
    ADD CONSTRAINT nation_resources_resource_definition_id_foreign FOREIGN KEY (resource_definition_id) REFERENCES public.resource_definitions(id) ON DELETE CASCADE;


--
-- Name: nation_underground_facilities nation_underground_facilities_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_underground_facilities
    ADD CONSTRAINT nation_underground_facilities_nation_id_foreign FOREIGN KEY (nation_id) REFERENCES public.nations(id) ON DELETE CASCADE;


--
-- Name: nation_underground_facilities nation_underground_facilities_ruleset_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nation_underground_facilities
    ADD CONSTRAINT nation_underground_facilities_ruleset_version_id_foreign FOREIGN KEY (ruleset_version_id) REFERENCES public.ruleset_versions(id) ON DELETE RESTRICT;


--
-- Name: nations nations_world_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.nations
    ADD CONSTRAINT nations_world_id_foreign FOREIGN KEY (world_id) REFERENCES public.worlds(id) ON DELETE CASCADE;


--
-- Name: production_definitions production_definitions_facility_definition_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.production_definitions
    ADD CONSTRAINT production_definitions_facility_definition_id_foreign FOREIGN KEY (facility_definition_id) REFERENCES public.facility_definitions(id) ON DELETE CASCADE;


--
-- Name: production_definitions production_definitions_output_resource_definition_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.production_definitions
    ADD CONSTRAINT production_definitions_output_resource_definition_id_foreign FOREIGN KEY (output_resource_definition_id) REFERENCES public.resource_definitions(id) ON DELETE CASCADE;


--
-- Name: production_definitions production_definitions_ruleset_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.production_definitions
    ADD CONSTRAINT production_definitions_ruleset_version_id_foreign FOREIGN KEY (ruleset_version_id) REFERENCES public.ruleset_versions(id) ON DELETE CASCADE;


--
-- Name: secretaries secretaries_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretaries
    ADD CONSTRAINT secretaries_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: secretary_gacha_draws secretary_gacha_draws_secretary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_gacha_draws
    ADD CONSTRAINT secretary_gacha_draws_secretary_id_foreign FOREIGN KEY (secretary_id) REFERENCES public.secretaries(id) ON DELETE CASCADE;


--
-- Name: secretary_guide_conversation_totals secretary_guide_conversation_totals_secretary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_guide_conversation_totals
    ADD CONSTRAINT secretary_guide_conversation_totals_secretary_id_foreign FOREIGN KEY (secretary_id) REFERENCES public.secretaries(id) ON DELETE CASCADE;


--
-- Name: secretary_images secretary_images_secretary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_images
    ADD CONSTRAINT secretary_images_secretary_id_foreign FOREIGN KEY (secretary_id) REFERENCES public.secretaries(id) ON DELETE CASCADE;


--
-- Name: secretary_item_instances secretary_item_instances_secretary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_item_instances
    ADD CONSTRAINT secretary_item_instances_secretary_id_foreign FOREIGN KEY (secretary_id) REFERENCES public.secretaries(id) ON DELETE CASCADE;


--
-- Name: secretary_lending_build_snapshots secretary_lending_build_snapshots_secretary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_build_snapshots
    ADD CONSTRAINT secretary_lending_build_snapshots_secretary_id_foreign FOREIGN KEY (secretary_id) REFERENCES public.secretaries(id) ON DELETE CASCADE;


--
-- Name: secretary_lending_daily_rewards secretary_lending_daily_rewards_owner_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_daily_rewards
    ADD CONSTRAINT secretary_lending_daily_rewards_owner_user_id_foreign FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: secretary_lending_participations secretary_lending_participations_owner_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_participations
    ADD CONSTRAINT secretary_lending_participations_owner_user_id_foreign FOREIGN KEY (owner_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: secretary_lending_participations secretary_lending_participations_secretary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_participations
    ADD CONSTRAINT secretary_lending_participations_secretary_id_foreign FOREIGN KEY (secretary_id) REFERENCES public.secretaries(id) ON DELETE RESTRICT;


--
-- Name: secretary_lending_participations secretary_lending_participations_underground_battle_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_participations
    ADD CONSTRAINT secretary_lending_participations_underground_battle_id_foreign FOREIGN KEY (underground_battle_id) REFERENCES public.underground_battles(id) ON DELETE RESTRICT;


--
-- Name: secretary_lending_participations secretary_lending_participations_underground_party_member_id_fo; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_participations
    ADD CONSTRAINT secretary_lending_participations_underground_party_member_id_fo FOREIGN KEY (underground_party_member_id) REFERENCES public.underground_party_members(id) ON DELETE RESTRICT;


--
-- Name: secretary_lending_settings secretary_lending_settings_secretary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_lending_settings
    ADD CONSTRAINT secretary_lending_settings_secretary_id_foreign FOREIGN KEY (secretary_id) REFERENCES public.secretaries(id) ON DELETE CASCADE;


--
-- Name: secretary_skills secretary_skills_secretary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_skills
    ADD CONSTRAINT secretary_skills_secretary_id_foreign FOREIGN KEY (secretary_id) REFERENCES public.secretaries(id) ON DELETE CASCADE;


--
-- Name: secretary_surface_states secretary_surface_states_secretary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_surface_states
    ADD CONSTRAINT secretary_surface_states_secretary_id_foreign FOREIGN KEY (secretary_id) REFERENCES public.secretaries(id) ON DELETE CASCADE;


--
-- Name: ships ships_map_cell_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ships
    ADD CONSTRAINT ships_map_cell_id_foreign FOREIGN KEY (map_cell_id) REFERENCES public.map_cells(id) ON DELETE CASCADE;


--
-- Name: ships ships_nation_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ships
    ADD CONSTRAINT ships_nation_id_foreign FOREIGN KEY (nation_id) REFERENCES public.nations(id) ON DELETE CASCADE;


--
-- Name: ships ships_ruleset_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ships
    ADD CONSTRAINT ships_ruleset_version_id_foreign FOREIGN KEY (ruleset_version_id) REFERENCES public.ruleset_versions(id) ON DELETE RESTRICT;


--
-- Name: ships ships_world_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ships
    ADD CONSTRAINT ships_world_id_foreign FOREIGN KEY (world_id) REFERENCES public.worlds(id) ON DELETE CASCADE;


--
-- Name: turn_runs turn_runs_ruleset_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.turn_runs
    ADD CONSTRAINT turn_runs_ruleset_version_id_foreign FOREIGN KEY (ruleset_version_id) REFERENCES public.ruleset_versions(id) ON DELETE RESTRICT;


--
-- Name: turn_runs turn_runs_world_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.turn_runs
    ADD CONSTRAINT turn_runs_world_id_foreign FOREIGN KEY (world_id) REFERENCES public.worlds(id) ON DELETE CASCADE;


--
-- Name: underground_battle_image_references underground_battle_image_references_underground_battle_id_forei; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_battle_image_references
    ADD CONSTRAINT underground_battle_image_references_underground_battle_id_forei FOREIGN KEY (underground_battle_id) REFERENCES public.underground_battles(id) ON DELETE SET NULL;


--
-- Name: underground_battle_logs underground_battle_logs_underground_battle_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_battle_logs
    ADD CONSTRAINT underground_battle_logs_underground_battle_id_foreign FOREIGN KEY (underground_battle_id) REFERENCES public.underground_battles(id) ON DELETE CASCADE;


--
-- Name: underground_battles underground_battles_underground_party_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_battles
    ADD CONSTRAINT underground_battles_underground_party_id_foreign FOREIGN KEY (underground_party_id) REFERENCES public.underground_parties(id) ON DELETE RESTRICT;


--
-- Name: underground_battles underground_battles_underground_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_battles
    ADD CONSTRAINT underground_battles_underground_profile_id_foreign FOREIGN KEY (underground_profile_id) REFERENCES public.underground_profiles(id) ON DELETE CASCADE;


--
-- Name: underground_content_clear_progress underground_content_clear_progress_underground_profile_id_forei; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_content_clear_progress
    ADD CONSTRAINT underground_content_clear_progress_underground_profile_id_forei FOREIGN KEY (underground_profile_id) REFERENCES public.underground_profiles(id) ON DELETE CASCADE;


--
-- Name: underground_intro_progress underground_intro_progress_underground_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_intro_progress
    ADD CONSTRAINT underground_intro_progress_underground_profile_id_foreign FOREIGN KEY (underground_profile_id) REFERENCES public.underground_profiles(id) ON DELETE CASCADE;


--
-- Name: underground_intro_requests underground_intro_requests_underground_battle_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_intro_requests
    ADD CONSTRAINT underground_intro_requests_underground_battle_id_foreign FOREIGN KEY (underground_battle_id) REFERENCES public.underground_battles(id) ON DELETE SET NULL;


--
-- Name: underground_intro_requests underground_intro_requests_underground_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_intro_requests
    ADD CONSTRAINT underground_intro_requests_underground_profile_id_foreign FOREIGN KEY (underground_profile_id) REFERENCES public.underground_profiles(id) ON DELETE CASCADE;


--
-- Name: underground_owned_equipment underground_owned_equipment_underground_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_owned_equipment
    ADD CONSTRAINT underground_owned_equipment_underground_profile_id_foreign FOREIGN KEY (underground_profile_id) REFERENCES public.underground_profiles(id) ON DELETE CASCADE;


--
-- Name: underground_parties underground_parties_leader_secretary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_parties
    ADD CONSTRAINT underground_parties_leader_secretary_id_foreign FOREIGN KEY (leader_secretary_id) REFERENCES public.secretaries(id) ON DELETE RESTRICT;


--
-- Name: underground_parties underground_parties_leader_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_parties
    ADD CONSTRAINT underground_parties_leader_user_id_foreign FOREIGN KEY (leader_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: underground_party_members underground_party_members_secretary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_party_members
    ADD CONSTRAINT underground_party_members_secretary_id_foreign FOREIGN KEY (secretary_id) REFERENCES public.secretaries(id) ON DELETE RESTRICT;


--
-- Name: underground_party_members underground_party_members_source_owner_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_party_members
    ADD CONSTRAINT underground_party_members_source_owner_user_id_foreign FOREIGN KEY (source_owner_user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: underground_party_members underground_party_members_underground_party_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_party_members
    ADD CONSTRAINT underground_party_members_underground_party_id_foreign FOREIGN KEY (underground_party_id) REFERENCES public.underground_parties(id) ON DELETE RESTRICT;


--
-- Name: underground_profiles underground_profiles_secretary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_profiles
    ADD CONSTRAINT underground_profiles_secretary_id_foreign FOREIGN KEY (secretary_id) REFERENCES public.secretaries(id) ON DELETE CASCADE;


--
-- Name: underground_receipt_rollups underground_receipt_rollups_underground_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_receipt_rollups
    ADD CONSTRAINT underground_receipt_rollups_underground_profile_id_foreign FOREIGN KEY (underground_profile_id) REFERENCES public.underground_profiles(id) ON DELETE CASCADE;


--
-- Name: underground_skill_allocations underground_skill_allocations_underground_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_skill_allocations
    ADD CONSTRAINT underground_skill_allocations_underground_profile_id_foreign FOREIGN KEY (underground_profile_id) REFERENCES public.underground_profiles(id) ON DELETE CASCADE;


--
-- Name: underground_skip_batches underground_skip_batches_underground_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_skip_batches
    ADD CONSTRAINT underground_skip_batches_underground_profile_id_foreign FOREIGN KEY (underground_profile_id) REFERENCES public.underground_profiles(id) ON DELETE CASCADE;


--
-- Name: underground_skip_batches underground_skip_batches_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_skip_batches
    ADD CONSTRAINT underground_skip_batches_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: underground_skip_settlements underground_skip_settlements_underground_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_skip_settlements
    ADD CONSTRAINT underground_skip_settlements_underground_profile_id_foreign FOREIGN KEY (underground_profile_id) REFERENCES public.underground_profiles(id) ON DELETE CASCADE;


--
-- Name: underground_skip_settlements underground_skip_settlements_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_skip_settlements
    ADD CONSTRAINT underground_skip_settlements_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: underground_trial_progress underground_trial_progress_underground_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_trial_progress
    ADD CONSTRAINT underground_trial_progress_underground_profile_id_foreign FOREIGN KEY (underground_profile_id) REFERENCES public.underground_profiles(id) ON DELETE CASCADE;


--
-- Name: underground_trial_runs underground_trial_runs_underground_profile_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.underground_trial_runs
    ADD CONSTRAINT underground_trial_runs_underground_profile_id_foreign FOREIGN KEY (underground_profile_id) REFERENCES public.underground_profiles(id) ON DELETE CASCADE;


--
-- Name: user_daily_login_claims user_daily_login_claims_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_daily_login_claims
    ADD CONSTRAINT user_daily_login_claims_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: user_daily_quest_activities user_daily_quest_activities_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_daily_quest_activities
    ADD CONSTRAINT user_daily_quest_activities_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: user_daily_quest_progress user_daily_quest_progress_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_daily_quest_progress
    ADD CONSTRAINT user_daily_quest_progress_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: user_monument_designs user_monument_designs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_monument_designs
    ADD CONSTRAINT user_monument_designs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: user_paradox_balances user_paradox_balances_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_paradox_balances
    ADD CONSTRAINT user_paradox_balances_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: user_paradox_ledger user_paradox_ledger_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_paradox_ledger
    ADD CONSTRAINT user_paradox_ledger_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: user_skip_ticket_balances user_skip_ticket_balances_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_skip_ticket_balances
    ADD CONSTRAINT user_skip_ticket_balances_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: user_skip_ticket_ledger user_skip_ticket_ledger_underground_battle_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_skip_ticket_ledger
    ADD CONSTRAINT user_skip_ticket_ledger_underground_battle_id_foreign FOREIGN KEY (underground_battle_id) REFERENCES public.underground_battles(id) ON DELETE SET NULL;


--
-- Name: user_skip_ticket_ledger user_skip_ticket_ledger_underground_party_member_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_skip_ticket_ledger
    ADD CONSTRAINT user_skip_ticket_ledger_underground_party_member_id_foreign FOREIGN KEY (underground_party_member_id) REFERENCES public.underground_party_members(id) ON DELETE SET NULL;


--
-- Name: user_skip_ticket_ledger user_skip_ticket_ledger_underground_skip_batch_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_skip_ticket_ledger
    ADD CONSTRAINT user_skip_ticket_ledger_underground_skip_batch_id_foreign FOREIGN KEY (underground_skip_batch_id) REFERENCES public.underground_skip_batches(id) ON DELETE SET NULL;


--
-- Name: user_skip_ticket_ledger user_skip_ticket_ledger_underground_skip_settlement_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_skip_ticket_ledger
    ADD CONSTRAINT user_skip_ticket_ledger_underground_skip_settlement_id_foreign FOREIGN KEY (underground_skip_settlement_id) REFERENCES public.underground_skip_settlements(id) ON DELETE SET NULL;


--
-- Name: user_skip_ticket_ledger user_skip_ticket_ledger_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.user_skip_ticket_ledger
    ADD CONSTRAINT user_skip_ticket_ledger_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: world_generation_runs world_generation_runs_map_space_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.world_generation_runs
    ADD CONSTRAINT world_generation_runs_map_space_id_foreign FOREIGN KEY (map_space_id) REFERENCES public.map_spaces(id) ON DELETE CASCADE;


--
-- Name: worlds worlds_ruleset_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.worlds
    ADD CONSTRAINT worlds_ruleset_version_id_foreign FOREIGN KEY (ruleset_version_id) REFERENCES public.ruleset_versions(id);


--
-- PostgreSQL database dump complete
--

\unrestrict AIHFphxJP48UpE3pFUEXLH31hK9jVCb4eqFdqiUNflydUNPiXW2UCB06T64hwuO

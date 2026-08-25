--
-- PostgreSQL database dump
--

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
-- Name: reject_monster_kill_record_mutation(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.reject_monster_kill_record_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- A kill fact remains immutable while its World exists. The pre-release
    -- reset path deletes the World root, so its FK cascades may remove the
    -- otherwise immutable World-owned graph without a session-level bypass.
    IF TG_OP = 'DELETE' AND NOT EXISTS (SELECT 1 FROM worlds WHERE id = OLD.world_id) THEN
        RETURN OLD;
    END IF;
    RAISE EXCEPTION 'monster kill records are immutable';
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
BEGIN
    SELECT ruleset_version_id INTO world_ruleset FROM worlds WHERE id = NEW.world_id;
    SELECT ruleset_version_id, base_hp, base_hp + hp_variation
      INTO definition_ruleset, definition_min_hp, definition_max_hp
      FROM monster_definitions WHERE id = NEW.monster_definition_id;
    IF world_ruleset IS NULL OR definition_ruleset IS NULL OR world_ruleset <> definition_ruleset THEN
        RAISE EXCEPTION 'monster definition must belong to the World current ruleset';
    END IF;
    IF NEW.spawned_max_hp < definition_min_hp OR NEW.spawned_max_hp > definition_max_hp THEN
        RAISE EXCEPTION 'spawned monster HP is outside its definition range';
    END IF;
    RETURN NEW;
END;
$$;


--
-- Name: validate_monster_kill_record(); Type: FUNCTION; Schema: public; Owner: -
--

CREATE FUNCTION public.validate_monster_kill_record() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    monster_world bigint;
    monster_definition bigint;
    monster_state text;
    killer_world bigint;
    host_world bigint;
    base_world bigint;
BEGIN
    SELECT world_id, monster_definition_id, state
      INTO monster_world, monster_definition, monster_state
      FROM monster_instances WHERE id = NEW.monster_instance_id;
    SELECT world_id INTO killer_world FROM nations WHERE id = NEW.killer_nation_id;
    IF NEW.host_nation_id IS NOT NULL THEN
        SELECT world_id INTO host_world FROM nations WHERE id = NEW.host_nation_id;
    END IF;
    IF NEW.firing_base_id IS NOT NULL THEN
        SELECT ms.world_id INTO base_world FROM map_cells mc
          JOIN map_spaces ms ON ms.id = mc.map_space_id WHERE mc.id = NEW.firing_base_id;
    END IF;
    IF monster_state IS DISTINCT FROM 'killed'
       OR monster_world <> NEW.world_id OR monster_definition <> NEW.monster_definition_id
       OR killer_world <> NEW.world_id
       OR (NEW.host_nation_id IS NOT NULL AND host_world <> NEW.world_id)
       OR (NEW.firing_base_id IS NOT NULL AND base_world <> NEW.world_id) THEN
        RAISE EXCEPTION 'monster kill record references inconsistent World state';
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
    deleted_at timestamp(0) with time zone
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
    CONSTRAINT auction_bids_status_check CHECK ((((status)::text = ANY ((ARRAY['highest'::character varying, 'refunded'::character varying, 'won'::character varying])::text[])) AND ((((status)::text = 'refunded'::text) AND (refunded_at IS NOT NULL)) OR (((status)::text <> 'refunded'::text) AND (refunded_at IS NULL)))))
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
    CONSTRAINT auction_listings_bid_state_check CHECK ((((bid_count = 0) AND (current_price IS NULL) AND (highest_bidder_nation_id IS NULL)) OR ((bid_count > 0) AND (current_price IS NOT NULL) AND (highest_bidder_nation_id IS NOT NULL)))),
    CONSTRAINT auction_listings_npc_relist_check CHECK ((((seller_type)::text = 'nation'::text) OR (auto_relist = false))),
    CONSTRAINT auction_listings_price_check CHECK (((start_price > 0) AND ((current_price IS NULL) OR (current_price >= start_price)))),
    CONSTRAINT auction_listings_product_check CHECK (((((product_type)::text = 'resource'::text) AND (resource_definition_id IS NOT NULL) AND (secretary_item_instance_id IS NULL) AND (item_key IS NULL) AND (item_level IS NULL) AND (quantity IS NOT NULL) AND (quantity > 0)) OR (((product_type)::text = 'item'::text) AND (resource_definition_id IS NULL) AND (quantity IS NULL) AND (item_key IS NOT NULL) AND (item_level IS NOT NULL) AND (item_level > 0) AND ((((seller_type)::text = 'nation'::text) AND (secretary_item_instance_id IS NOT NULL)) OR (((seller_type)::text = 'hakoniwa_federation'::text) AND (secretary_item_instance_id IS NULL)))))),
    CONSTRAINT auction_listings_seller_check CHECK (((((seller_type)::text = 'nation'::text) AND (seller_nation_id IS NOT NULL)) OR (((seller_type)::text = 'hakoniwa_federation'::text) AND (seller_nation_id IS NULL)))),
    CONSTRAINT auction_listings_status_check CHECK ((((status)::text = ANY ((ARRAY['active'::character varying, 'cancelled'::character varying, 'sold'::character varying, 'expired'::character varying])::text[])) AND ((((status)::text = 'active'::text) AND (completed_turn IS NULL)) OR (((status)::text <> 'active'::text) AND (completed_turn IS NOT NULL))))),
    CONSTRAINT auction_listings_turn_check CHECK ((((duration_turns >= 3) AND (duration_turns <= 84)) AND (ends_turn = (started_turn + duration_turns))))
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
    monument_definition_id bigint
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
    CONSTRAINT monster_definitions_hp_check CHECK (((base_hp >= 1) AND (hp_variation <= 18) AND ((base_hp + hp_variation) <= 65535))),
    CONSTRAINT monster_definitions_skill_check CHECK (((skill_key)::text = ANY (ARRAY[('none'::character varying)::text, ('move_2'::character varying)::text, ('move_9999'::character varying)::text, ('harden_odd'::character varying)::text, ('harden_even'::character varying)::text]))),
    CONSTRAINT monster_definitions_spawn_tier_check CHECK (((natural_spawn_tier IS NULL) OR ((natural_spawn_tier >= 1) AND (natural_spawn_tier <= 3)))),
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
    command_definition_id bigint NOT NULL,
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
    target_x integer NOT NULL,
    target_y integer NOT NULL,
    quantity smallint DEFAULT 1 NOT NULL,
    request_fingerprint character(64),
    request_ruleset_version_id bigint,
    CONSTRAINT nation_command_queue_items_quantity_check CHECK (((quantity >= 1) AND (quantity <= 99))),
    CONSTRAINT nation_command_queue_items_request_fingerprint_check CHECK (((request_fingerprint IS NULL) OR (request_fingerprint ~ '^[0-9a-f]{64}$'::text)))
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
    equipment_version bigint DEFAULT '1'::bigint NOT NULL,
    profile_biography text DEFAULT E'全てが謎に包まれた、長耳の秘書。\nかつては囚われの身になっていたが島主に救われ、後に才能を買われて秘書となった。\nその身に不思議な力を宿している。'::text NOT NULL,
    main_image_path character varying(80),
    main_image_mime_type character varying(32),
    main_image_creation_method character varying(32),
    main_image_credit character varying(160),
    main_image_updated_at timestamp(0) with time zone,
    monster_experience bigint DEFAULT '0'::bigint NOT NULL,
    CONSTRAINT secretaries_equipment_version_check CHECK ((equipment_version >= 1)),
    CONSTRAINT secretaries_main_image_state_check CHECK ((((main_image_path IS NULL) AND (main_image_mime_type IS NULL) AND (main_image_creation_method IS NULL) AND (main_image_credit IS NULL) AND (main_image_updated_at IS NULL)) OR (((main_image_path)::text ~ '^[0-9a-f]{64}\.(png|jpg|webp|gif)$'::text) AND ((main_image_mime_type)::text = ANY ((ARRAY['image/png'::character varying, 'image/jpeg'::character varying, 'image/webp'::character varying, 'image/gif'::character varying])::text[])) AND ((main_image_creation_method)::text = ANY ((ARRAY['self_made'::character varying, 'ai_generated'::character varying, 'commissioned_or_permitted'::character varying, 'other'::character varying])::text[])) AND ((main_image_credit IS NULL) OR (char_length((main_image_credit)::text) <= 160)) AND (main_image_updated_at IS NOT NULL)))),
    CONSTRAINT secretaries_monster_experience_non_negative CHECK ((monster_experience >= 0)),
    CONSTRAINT secretaries_name_state_check CHECK ((((name IS NULL) AND (named_at IS NULL)) OR ((name IS NOT NULL) AND (named_at IS NOT NULL)))),
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
    CONSTRAINT secretary_item_instances_equipped_slot_check CHECK (((equipped_slot IS NULL) OR ((equipped_slot >= 1) AND (equipped_slot <= 5)))),
    CONSTRAINT secretary_item_instances_escrow_equipment_check CHECK (((NOT is_escrowed) OR (equipped_slot IS NULL))),
    CONSTRAINT secretary_item_instances_level_check CHECK ((level >= 1))
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
    CONSTRAINT secretary_skills_key_check CHECK (((skill_key)::text = ANY ((ARRAY['agricultural_policy'::character varying, 'specialty_development'::character varying, 'gold_vein_survey'::character varying, 'forest_management'::character varying, 'final_defense_line'::character varying])::text[]))),
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
    CONSTRAINT users_secretary_image_preferences_check CHECK ((((show_ai_generated_secretary_images IS NULL) AND (secretary_image_fallback IS NULL)) OR ((show_ai_generated_secretary_images IS NOT NULL) AND ((secretary_image_fallback)::text = ANY ((ARRAY['silhouette'::character varying, 'peridot'::character varying])::text[]))))),
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
    updated_at timestamp(0) without time zone
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
-- Name: command_definitions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.command_definitions ALTER COLUMN id SET DEFAULT nextval('public.command_definitions_id_seq'::regclass);


--
-- Name: facility_definitions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.facility_definitions ALTER COLUMN id SET DEFAULT nextval('public.facility_definitions_id_seq'::regclass);


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
-- Name: secretary_item_instances id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_item_instances ALTER COLUMN id SET DEFAULT nextval('public.secretary_item_instances_id_seq'::regclass);


--
-- Name: secretary_skills id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_skills ALTER COLUMN id SET DEFAULT nextval('public.secretary_skills_id_seq'::regclass);


--
-- Name: terrain_definitions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.terrain_definitions ALTER COLUMN id SET DEFAULT nextval('public.terrain_definitions_id_seq'::regclass);


--
-- Name: turn_runs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.turn_runs ALTER COLUMN id SET DEFAULT nextval('public.turn_runs_id_seq'::regclass);


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
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


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
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


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

CREATE CONSTRAINT TRIGGER nation_command_queue_items_world_ruleset_match AFTER INSERT OR UPDATE OF nation_command_queue_id, command_definition_id ON public.nation_command_queue_items DEFERRABLE INITIALLY IMMEDIATE FOR EACH ROW EXECUTE FUNCTION public.enforce_queue_item_world_ruleset_match();


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
    ADD CONSTRAINT auction_listings_secretary_item_instance_id_foreign FOREIGN KEY (secretary_item_instance_id) REFERENCES public.secretary_item_instances(id) ON DELETE RESTRICT;


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
-- Name: command_definitions command_definitions_ruleset_version_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.command_definitions
    ADD CONSTRAINT command_definitions_ruleset_version_id_foreign FOREIGN KEY (ruleset_version_id) REFERENCES public.ruleset_versions(id) ON DELETE CASCADE;


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
-- Name: secretary_item_instances secretary_item_instances_secretary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_item_instances
    ADD CONSTRAINT secretary_item_instances_secretary_id_foreign FOREIGN KEY (secretary_id) REFERENCES public.secretaries(id) ON DELETE CASCADE;


--
-- Name: secretary_skills secretary_skills_secretary_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.secretary_skills
    ADD CONSTRAINT secretary_skills_secretary_id_foreign FOREIGN KEY (secretary_id) REFERENCES public.secretaries(id) ON DELETE CASCADE;


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

--
-- PostgreSQL database dump
--

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
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0001_01_01_000000_create_users_table	1
2	0001_01_01_000001_create_cache_table	1
3	2026_07_26_000000_create_hakoniwa_schema	1
4	2026_07_26_010000_add_roadmap_pr2_systems	1
5	2026_07_26_020000_replace_axial_coordinates_with_staggered_xy	1
6	2026_07_27_000000_add_command_parameter_metadata	1
7	2026_07_27_010000_publish_roadmap_pr6_ruleset	1
8	2026_07_28_000000_add_universal_quantity_to_command_queue_items	1
9	2026_07_28_010000_normalize_food_resources_to_tons	1
10	2026_07_28_999999_enforce_queue_item_ruleset_consistency	1
11	2026_07_29_000000_publish_roadmap_pr7_ruleset	1
12	2026_07_29_010000_create_turn_runs	1
13	2026_07_30_000000_publish_roadmap_pr11_ruleset	1
14	2026_08_01_000000_start_world_turns_at_one	1
15	2026_08_02_000000_publish_roadmap_pr14_ruleset	1
16	2026_08_02_010000_publish_roadmap_pr15_ruleset	1
17	2026_08_02_020000_add_per_world_nation_numbers	1
18	2026_08_04_000000_publish_roadmap_pr18_ruleset	1
19	2026_08_04_010000_add_nation_profiles	1
20	2026_08_04_020000_publish_roadmap_pr19_ruleset	1
21	2026_08_05_000000_create_monster_system_and_publish_roadmap_pr21_ruleset	1
22	2026_08_05_010000_add_pr22_command_event_state_and_publish_ruleset	1
23	2026_08_05_020000_prepare_first_production_release	1
24	2026_08_09_000000_publish_hakoniwa_2s_plus_v2	1
25	2026_08_09_010000_create_announcements	1
26	2026_08_09_020000_repair_hakoniwa_2s_plus_v2_live_monster_references	1
27	2026_08_09_030000_repair_deterministic_application_timestamps	1
28	2026_08_09_040000_create_nation_awards_and_monster_cycles	1
29	2026_08_10_000000_publish_hakoniwa_2s_plus_v3	1
30	2026_08_11_000000_create_island_messages	1
31	2026_08_13_000000_publish_hakoniwa_2s_plus_v4	1
32	2026_08_14_000000_publish_hakoniwa_2s_plus_v5	1
33	2026_08_15_000000_enable_nation_reregistration	1
34	2026_08_16_000000_publish_hakoniwa_2s_plus_v6	1
35	2026_08_16_010000_create_nation_command_queue_bulk_requests	1
36	2026_08_16_020000_create_secretary_system	1
37	2026_08_16_030000_publish_hakoniwa_2s_plus_v7	1
38	2026_08_16_040000_publish_hakoniwa_2s_plus_v8	1
39	2026_08_17_000000_publish_hakoniwa_2s_plus_v9	1
40	2026_08_17_010000_create_secretary_items_and_inquiries	1
41	2026_08_19_000000_add_command_request_fingerprint	1
42	2026_08_19_010000_publish_hakoniwa_2s_plus_v10	1
43	2026_08_20_000000_add_secretary_equipment_version	1
44	2026_08_20_010000_add_monster_definition_display_order	1
45	2026_08_21_000000_add_command_request_ruleset_provenance	1
46	2026_08_21_010000_publish_hakoniwa_2s_plus_v11	1
47	2026_08_22_000000_rebaseline_ver_2_4_install_and_upgrade	1
48	2026_08_23_000000_add_nation_dormancy_and_publish_v12	1
49	2026_08_23_010000_add_nation_karma_and_publish_v13	1
50	2026_08_24_000000_add_secretary_profiles_and_publish_v14	1
51	2026_08_24_010000_add_monster_experience_and_publish_v15	1
52	2026_08_25_000000_add_oil_resource_and_publish_v16	1
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 52, true);


--
-- PostgreSQL database dump complete
--

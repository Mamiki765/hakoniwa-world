<?php

namespace App\Domain\Command;

enum CommandFailureReason: string
{
    case InsufficientFunds = 'insufficient_funds';
    case InsufficientResource = 'insufficient_resource';
    case InsufficientPopulation = 'insufficient_population';
    case PopulationExists = 'population_exists';
    case InvalidTerrain = 'invalid_terrain';
    case MissingAdjacentTerritory = 'missing_adjacent_territory';
    case NoAdjacentOwnedLand = 'no_adjacent_owned_land';
    case NoAdjacentDeepSea = 'no_adjacent_deep_sea';
    case ForeignAdjacentWater = 'foreign_adjacent_water';
    case ForeignOwned = 'foreign_owned';
    case NotOwned = 'not_owned';
    case AlreadyOwned = 'already_owned';
    case OccupiedByMonster = 'occupied_by_monster';
    case FacilityExists = 'facility_exists';
    case InvalidFacility = 'invalid_facility';
    case InvalidFacilityScale = 'invalid_facility_scale';
    case CapitalProtected = 'capital_protected';
    case NoTarget = 'no_target';
    case InvalidTargetNation = 'invalid_target_nation';
    case SameNationTarget = 'same_nation_target';
    case InvalidParameter = 'invalid_parameter';
    case NoLaunchBase = 'no_launch_base';
    case NoPort = 'no_port';
    case ShipCapacityReached = 'ship_capacity_reached';
    case NoShipSpawnCell = 'no_ship_spawn_cell';
    case RulesetMismatch = 'ruleset_mismatch';
    case CeasefireProhibited = 'ceasefire_prohibited';
}

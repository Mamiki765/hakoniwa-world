<?php

namespace Tests\Feature;

use App\Application\AuthIdentityService;
use App\Application\ExternalIdentityData;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Models\MapCell;
use App\Models\Nation;
use App\Models\NationCapital;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoadmapPr2MigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_and_remigrate_preserve_existing_world_and_backfill_typed_state(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = app(AuthIdentityService::class)->authenticate('discord', new ExternalIdentityData('migration-user', '移行利用者'));
        $nation = app(NationCreationService::class)->create($user, $world, '移行国');
        $capital = $nation->capital;
        $migration = require database_path('migrations/2026_07_26_010000_add_roadmap_pr2_systems.php');

        $migration->down();
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, DB::table('auth_identities')->count());
        $this->assertSame(1, World::query()->count());
        $this->assertSame(1, Nation::query()->count());
        $this->assertSame(3600, MapCell::query()->count());
        $this->assertSame($capital->id, NationCapital::query()->value('id'));
        $this->assertFalse(Schema::hasColumn('map_cells', 'terrain_quantity'));

        $migration->up();
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, DB::table('auth_identities')->count());
        $this->assertSame(1, World::query()->count());
        $this->assertSame(1, Nation::query()->count());
        $this->assertSame(3600, MapCell::query()->count());
        $this->assertSame($capital->id, NationCapital::query()->value('id'));
        $this->assertSame(3, MapCell::query()->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->where('terrain_quantity', 500)->count());
        $this->assertSame(1, MapCell::query()->whereHas('facility', fn ($query) => $query->where('key', 'missile_base'))
            ->where('facility_experience', 0)->whereNull('facility_scale')->count());
        $this->assertSame(5, DB::table('nation_resource_sale_policies')->where('nation_id', $nation->id)->count());
        $this->assertSame(7, DB::table('command_definitions')->count());
        $this->assertSame(3, DB::table('production_definitions')->count());
    }
}

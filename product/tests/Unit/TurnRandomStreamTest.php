<?php

namespace Tests\Unit;

use App\Domain\Turn\TurnRandomStreamFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TurnRandomStreamTest extends TestCase
{
    private const MASTER_SEED = '0000000000000000000000000000000000000000000000000000000000000000';

    public function test_same_master_seed_and_label_produce_the_same_sequence(): void
    {
        $first = new TurnRandomStreamFactory(self::MASTER_SEED);
        $second = new TurnRandomStreamFactory(self::MASTER_SEED);

        $this->assertSame(
            $this->draws($first, TurnRandomStreamFactory::DEVELOPMENT_NATION_ORDER),
            $this->draws($second, TurnRandomStreamFactory::DEVELOPMENT_NATION_ORDER),
        );
    }

    public function test_different_labels_have_independent_sequences(): void
    {
        $factory = new TurnRandomStreamFactory(self::MASTER_SEED);

        $this->assertNotSame(
            $this->draws($factory, TurnRandomStreamFactory::DEVELOPMENT_NATION_ORDER),
            $this->draws($factory, TurnRandomStreamFactory::SURFACE_CELL_ORDER),
        );
    }

    public function test_extra_draws_from_one_label_do_not_change_another_label(): void
    {
        $withExtraDraws = new TurnRandomStreamFactory(self::MASTER_SEED);
        $nationStream = $withExtraDraws->stream(TurnRandomStreamFactory::DEVELOPMENT_NATION_ORDER);
        for ($index = 0; $index < 25; $index++) {
            $nationStream->integer(0, 999);
        }

        $fresh = new TurnRandomStreamFactory(self::MASTER_SEED);

        $this->assertSame(
            $this->draws($fresh, TurnRandomStreamFactory::SURFACE_CELL_ORDER),
            $this->draws($withExtraDraws, TurnRandomStreamFactory::SURFACE_CELL_ORDER),
        );
    }

    public function test_monument_flight_center_draw_is_retry_stable_isolated_and_bounded_to_exactly_256_candidates(): void
    {
        $label = TurnRandomStreamFactory::monumentFlight(42);
        $first = new TurnRandomStreamFactory(self::MASTER_SEED);
        $first->stream(TurnRandomStreamFactory::DEVELOPMENT_NATION_ORDER)->integer(0, 999);
        $firstDraw = $first->stream($label)->integer(0, 255);
        $retryDraw = (new TurnRandomStreamFactory(self::MASTER_SEED))->stream($label)->integer(0, 255);

        $this->assertSame($firstDraw, $retryDraw);
        $this->assertGreaterThanOrEqual(0, $firstDraw);
        $this->assertLessThan(256, $firstDraw);
        $this->assertNotSame($label, TurnRandomStreamFactory::monumentFlight(43));
    }

    public function test_fixed_seed_and_label_vector_is_stable(): void
    {
        $factory = new TurnRandomStreamFactory(self::MASTER_SEED);
        $stream = $factory->stream(TurnRandomStreamFactory::DEVELOPMENT_NATION_ORDER);

        $this->assertSame([
            798_573_741,
            211_079_881,
            2_020_366_191,
            1_264_525_296,
            147_734_121,
            908_495_757,
            881_984_575,
            1_378_879_767,
        ], array_map(
            static fn (): int => $stream->integer(0, 2_147_483_647),
            range(1, 8),
        ));

        $fresh = new TurnRandomStreamFactory(self::MASTER_SEED);
        $this->assertSame(
            [9, 3, 5, 4, 1, 6, 7, 8, 2, 10],
            $fresh->stream(TurnRandomStreamFactory::DEVELOPMENT_NATION_ORDER)
                ->shuffle(range(1, 10)),
        );
    }

    public function test_aoi_world_spawn_uses_the_approved_raw_stream_labels_without_changing_existing_identities(): void
    {
        $this->assertSame(
            'global_disasters:aoi_inora:trigger:v1',
            TurnRandomStreamFactory::monsterWorldSpawn('trigger', 1),
        );
        $this->assertSame(
            'global_disasters:aoi_inora:candidate:v1',
            TurnRandomStreamFactory::monsterWorldSpawn('candidate', 1),
        );
        $this->assertSame(
            'global_disasters:aoi_inora:hp:v1',
            TurnRandomStreamFactory::monsterWorldSpawn('hp', 1),
        );
        $this->assertSame(
            'global_disasters:monster_spawn:nation:7:trigger:v1',
            TurnRandomStreamFactory::monsterSpawn(7, 'trigger', 1),
        );
        $this->assertSame(
            'global_disasters:huge_meteor:trigger:v1',
            TurnRandomStreamFactory::GLOBAL_HUGE_METEOR_TRIGGER,
        );
    }

    #[DataProvider('invalidSeedProvider')]
    public function test_invalid_master_seed_is_rejected(string $seed): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TurnRandomStreamFactory($seed);
    }

    /** @return array<string, array{string}> */
    public static function invalidSeedProvider(): array
    {
        return [
            'empty' => [''],
            'short' => [str_repeat('a', 63)],
            'uppercase' => [str_repeat('A', 64)],
            'not hexadecimal' => [str_repeat('z', 64)],
        ];
    }

    public function test_empty_label_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TurnRandomStreamFactory(self::MASTER_SEED))->stream('');
    }

    #[DataProvider('invalidRangeProvider')]
    public function test_invalid_integer_range_is_rejected(mixed $minimum, mixed $maximum): void
    {
        $stream = (new TurnRandomStreamFactory(self::MASTER_SEED))->stream('test:range');

        $this->expectException(InvalidArgumentException::class);
        $stream->integer($minimum, $maximum);
    }

    /** @return array<string, array{mixed, mixed}> */
    public static function invalidRangeProvider(): array
    {
        return [
            'minimum exceeds maximum' => [2, 1],
            'float minimum' => [0.0, 1],
            'string maximum' => [0, '1'],
            'below signed int32' => [-2_147_483_649, 0],
            'above signed int32' => [0, 2_147_483_648],
        ];
    }

    public function test_bounded_draws_remain_inclusive_and_in_range(): void
    {
        $stream = (new TurnRandomStreamFactory(self::MASTER_SEED))->stream('test:bounded');
        $draws = array_map(
            static fn (): int => $stream->integer(-7, 13),
            range(1, 1_000),
        );

        $this->assertSame(-7, min($draws));
        $this->assertSame(13, max($draws));
        foreach ($draws as $draw) {
            $this->assertGreaterThanOrEqual(-7, $draw);
            $this->assertLessThanOrEqual(13, $draw);
        }
    }

    public function test_shuffle_preserves_every_input_element_exactly_once(): void
    {
        $input = range(1, 100);
        $shuffled = (new TurnRandomStreamFactory(self::MASTER_SEED))
            ->stream('test:shuffle')
            ->shuffle($input);
        $sorted = $shuffled;
        sort($sorted);

        $this->assertNotSame($input, $shuffled);
        $this->assertSame($input, $sorted);
        $this->assertCount(count($input), array_unique($shuffled));
    }

    /** @return list<int> */
    private function draws(TurnRandomStreamFactory $factory, string $label): array
    {
        $stream = $factory->stream($label);

        return array_map(
            static fn (): int => $stream->integer(0, 1_000_000),
            range(1, 20),
        );
    }
}

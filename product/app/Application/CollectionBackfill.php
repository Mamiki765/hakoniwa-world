<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryItemCatalog;
use App\Services\ManualIndexPresenter;
use Illuminate\Support\Facades\DB;

/**
 * Fills collections from records that predate the collection. Durable kill
 * statistics and owned items are complete; logs only cover what was retained.
 * Re-running it inserts nothing new.
 */
final class CollectionBackfill
{
    public function __construct(
        private readonly CollectionRegistrar $registrar,
        private readonly SecretaryItemCatalog $items,
        private readonly ManualIndexPresenter $manual,
    ) {}

    /** @return array<string, mixed> */
    public function preview(): array
    {
        return $this->report($this->candidates(), false);
    }

    /** @return array<string, mixed> */
    public function apply(): array
    {
        return DB::transaction(function (): array {
            $candidates = $this->candidates();
            $inserted = 0;
            foreach ($candidates as $candidate) {
                if ($this->registrar->registerBackfill($candidate['user_id'], $candidate['kind'], $candidate['key'], $candidate['first_turn'])) {
                    $inserted++;
                }
            }

            return [...$this->report($candidates, true), 'inserted' => $inserted];
        });
    }

    /** @param list<array{user_id: int, kind: string, key: string, first_turn: int|null, sources: list<string>}> $candidates
     * @return array<string, mixed>
     */
    private function report(array $candidates, bool $applied): array
    {
        $bySource = [];
        foreach ($candidates as $candidate) {
            foreach ($candidate['sources'] as $source) {
                $bySource[$source] = ($bySource[$source] ?? 0) + 1;
            }
        }
        ksort($bySource);

        return [
            'applied' => $applied,
            'candidates' => count($candidates),
            'users' => count(array_unique(array_column($candidates, 'user_id'))),
            'monster_entries' => count(array_filter($candidates, fn (array $row): bool => $row['kind'] === 'monster')),
            'item_entries' => count(array_filter($candidates, fn (array $row): bool => $row['kind'] === 'item')),
            'entries_by_source' => $bySource,
        ];
    }

    /** @return list<array{user_id: int, kind: string, key: string, first_turn: int|null, sources: list<string>}> */
    private function candidates(): array
    {
        $monsterKeys = array_column($this->manual->monsters(), 'key');
        $itemKeys = array_keys($this->items->definitions());
        $merged = [];
        $add = function (int $userId, string $kind, string $key, ?int $turn, string $source) use (&$merged, $monsterKeys, $itemKeys): void {
            if (! in_array($key, $kind === 'monster' ? $monsterKeys : $itemKeys, true)) {
                return;
            }
            $id = "{$userId}:{$kind}:{$key}";
            $current = $merged[$id] ?? ['user_id' => $userId, 'kind' => $kind, 'key' => $key, 'first_turn' => null, 'sources' => []];
            if ($turn !== null && ($current['first_turn'] === null || $turn < $current['first_turn'])) {
                $current['first_turn'] = $turn;
            }
            if (! in_array($source, $current['sources'], true)) {
                $current['sources'][] = $source;
            }
            $merged[$id] = $current;
        };

        $owners = $this->ownersByNation();
        $addForNation = function (int $nationId, string $kind, string $key, ?int $turn, string $source) use ($owners, $add): void {
            if (isset($owners[$nationId])) {
                $add($owners[$nationId], $kind, $key, $turn, $source);
            }
        };

        foreach (DB::select(<<<'SQL'
SELECT stat.nation_id, definition.key, MIN(stat.first_killed_turn) AS first_turn
FROM nation_monster_kill_stats stat
JOIN monster_definitions definition ON definition.id = stat.monster_definition_id
GROUP BY stat.nation_id, definition.key
SQL) as $row) {
            $addForNation((int) $row->nation_id, 'monster', (string) $row->key, (int) $row->first_turn, 'kill_stats');
        }
        foreach (DB::select(<<<'SQL'
SELECT (event.metadata->>'attacker_nation_id')::bigint AS nation_id, event.metadata->>'monster_key' AS key, MIN(event.turn) AS first_turn
FROM audit_events event
WHERE event.event_type IN ('monster.damaged', 'monster.killed')
  AND event.metadata->>'attacker_nation_id' IS NOT NULL
  AND event.metadata->>'monster_key' IS NOT NULL
  AND COALESCE((event.metadata->>'actual_damage')::bigint, 0) > 0
GROUP BY 1, 2
SQL) as $row) {
            $addForNation((int) $row->nation_id, 'monster', (string) $row->key, $row->first_turn === null ? null : (int) $row->first_turn, 'damage_events');
        }

        foreach (DB::select(<<<'SQL'
SELECT DISTINCT secretary.user_id, item.item_key AS key
FROM secretary_item_instances item JOIN secretaries secretary ON secretary.id = item.secretary_id
SQL) as $row) {
            $add((int) $row->user_id, 'item', (string) $row->key, null, 'owned_items');
        }
        foreach (DB::select(<<<'SQL'
SELECT DISTINCT secretary.user_id, event.metadata->>'item_key' AS key
FROM audit_events event
JOIN secretaries secretary ON secretary.id = (event.metadata->>'secretary_id')::bigint
WHERE event.event_type = 'secretary.item_sold' AND event.metadata->>'item_key' IS NOT NULL
SQL) as $row) {
            $add((int) $row->user_id, 'item', (string) $row->key, null, 'sold_items');
        }
        foreach (DB::select(<<<'SQL'
SELECT secretary.user_id, draw.result_snapshot
FROM secretary_gacha_draws draw JOIN secretaries secretary ON secretary.id = draw.secretary_id
SQL) as $row) {
            $snapshot = json_decode((string) $row->result_snapshot, true, 512, JSON_THROW_ON_ERROR);
            $results = is_array($snapshot) && array_is_list($snapshot) ? $snapshot : ($snapshot['result']['items'] ?? []);
            foreach (is_array($results) ? $results : [] as $result) {
                if (is_array($result) && is_string($result['key'] ?? null)) {
                    $add((int) $row->user_id, 'item', $result['key'], null, 'ticket_draws');
                }
            }
        }
        foreach (DB::select(<<<'SQL'
SELECT listing.seller_nation_id AS nation_id, listing.item_key AS key
FROM auction_listings listing WHERE listing.product_type = 'item'
UNION
SELECT listing.highest_bidder_nation_id, listing.item_key
FROM auction_listings listing
WHERE listing.product_type = 'item' AND listing.status = 'sold' AND listing.highest_bidder_nation_id IS NOT NULL
SQL) as $row) {
            $addForNation((int) $row->nation_id, 'item', (string) $row->key, null, 'trading_post');
        }

        ksort($merged);

        return array_values($merged);
    }

    /**
     * Current owners, plus the single provable creator of an abandoned Nation
     * whose membership was removed. Ambiguous Nations are left out.
     *
     * @return array<int, int>
     */
    private function ownersByNation(): array
    {
        $owners = [];
        foreach (DB::table('nation_memberships')->where('role', 'owner')->get(['nation_id', 'user_id']) as $row) {
            $owners[(int) $row->nation_id] = (int) $row->user_id;
        }
        $orphans = DB::table('nations')->where('state', 'abandoned')->whereNotIn('id', array_keys($owners) ?: [0])->pluck('id')->all();
        if ($orphans === []) {
            return $owners;
        }
        $claims = [];
        foreach (DB::table('nation_creation_requests')->whereIn('nation_id', $orphans)->where('status', 'completed')->get(['nation_id', 'user_id']) as $row) {
            $claims[(int) $row->nation_id][(int) $row->user_id] = true;
        }
        foreach (DB::table('audit_events')->whereIn('nation_id', $orphans)->where('event_type', 'nation.created')
            ->whereNotNull('actor_user_id')->get(['nation_id', 'actor_user_id']) as $row) {
            $claims[(int) $row->nation_id][(int) $row->actor_user_id] = true;
        }
        foreach ($claims as $nationId => $users) {
            if (count($users) === 1) {
                $owners[$nationId] = (int) array_key_first($users);
            }
        }

        return $owners;
    }
}

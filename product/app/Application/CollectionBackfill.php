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

        foreach (DB::select(<<<'SQL'
SELECT membership.user_id, definition.key, MIN(stat.first_killed_turn) AS first_turn
FROM nation_monster_kill_stats stat
JOIN monster_definitions definition ON definition.id = stat.monster_definition_id
JOIN nation_memberships membership ON membership.nation_id = stat.nation_id AND membership.role = 'owner'
GROUP BY membership.user_id, definition.key
SQL) as $row) {
            $add((int) $row->user_id, 'monster', (string) $row->key, (int) $row->first_turn, 'kill_stats');
        }
        foreach (DB::select(<<<'SQL'
SELECT membership.user_id, event.metadata->>'monster_key' AS key, MIN(event.turn) AS first_turn
FROM audit_events event
JOIN nation_memberships membership
  ON membership.nation_id = (event.metadata->>'attacker_nation_id')::bigint AND membership.role = 'owner'
WHERE event.event_type IN ('monster.damaged', 'monster.killed')
  AND event.metadata->>'attacker_nation_id' IS NOT NULL
  AND event.metadata->>'monster_key' IS NOT NULL
  AND COALESCE((event.metadata->>'actual_damage')::bigint, 0) > 0
GROUP BY membership.user_id, event.metadata->>'monster_key'
SQL) as $row) {
            $add((int) $row->user_id, 'monster', (string) $row->key, $row->first_turn === null ? null : (int) $row->first_turn, 'damage_events');
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
SELECT DISTINCT membership.user_id, listing.item_key AS key
FROM auction_listings listing
JOIN nation_memberships membership ON membership.role = 'owner' AND (
    membership.nation_id = listing.seller_nation_id
    OR (listing.status = 'sold' AND membership.nation_id = listing.highest_bidder_nation_id))
WHERE listing.product_type = 'item'
SQL) as $row) {
            $add((int) $row->user_id, 'item', (string) $row->key, null, 'trading_post');
        }

        ksort($merged);

        return array_values($merged);
    }
}

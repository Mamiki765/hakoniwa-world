<?php

namespace App\Application;

use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Records collection discoveries. It reads no randomness and changes no Nation
 * state, so calling it inside a turn keeps the turn result unchanged.
 */
final class CollectionRegistrar
{
    public const KINDS = ['monster', 'item'];

    public function registerItem(int $userId, string $itemKey): void
    {
        $this->insert($userId, 'item', $itemKey, 'live', null);
    }

    /** Registers the owner of the attacking Nation once the monster actually lost HP. */
    public function registerMonsterForNation(int $nationId, string $monsterKey, int $turn): void
    {
        DB::statement(<<<'SQL'
INSERT INTO user_collection_entries (user_id, kind, entry_key, source, first_turn, registered_at, created_at, updated_at)
SELECT membership.user_id, 'monster', ?, 'live', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM nation_memberships membership
WHERE membership.nation_id = ? AND membership.role = 'owner'
ON CONFLICT (user_id, kind, entry_key) DO NOTHING
SQL, [$monsterKey, $turn, $nationId]);
    }

    public function registerBackfill(int $userId, string $kind, string $entryKey, ?int $firstTurn): bool
    {
        return $this->insert($userId, $kind, $entryKey, 'backfill', $firstTurn);
    }

    private function insert(int $userId, string $kind, string $entryKey, string $source, ?int $firstTurn): bool
    {
        if (! in_array($kind, self::KINDS, true) || $entryKey === '') {
            throw new DomainException('Unknown collection entry.');
        }
        $now = now();

        return DB::table('user_collection_entries')->insertOrIgnore([
            'user_id' => $userId,
            'kind' => $kind,
            'entry_key' => $entryKey,
            'source' => $source,
            'first_turn' => $firstTurn,
            'registered_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }
}

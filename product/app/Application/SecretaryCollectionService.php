<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryInventoryException;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Secretary\SecretaryItemGameplayContract;
use App\Models\User;
use App\Services\ManualIndexPresenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** User-scoped monster and surface item collection. Each entry and milestone pays Paradox exactly once. */
final class SecretaryCollectionService
{
    public const ENTRY_PARADOX = 5;

    public const BONUS_INTERVAL = 10;

    public const BONUS_PARADOX = 20;

    public function __construct(
        private readonly ParadoxBalanceService $paradox,
        private readonly SecretaryItemCatalog $items,
        private readonly SecretaryItemGameplayContract $gameplay,
        private readonly ManualIndexPresenter $manual,
    ) {}

    /** @return array<string, mixed> */
    public function index(User $user): array
    {
        $entries = DB::table('user_collection_entries')->where('user_id', $user->id)->get()
            ->groupBy('kind')->map(fn ($rows) => $rows->keyBy('entry_key'));
        $claimedBonuses = DB::table('user_collection_bonus_claims')->where('user_id', $user->id)
            ->pluck('milestone')->map(fn ($value): int => (int) $value)->all();
        /** @var array<string, mixed> $settings */
        $settings = config('hakoniwa.ruleset', []);

        $monsters = [];
        foreach ($this->monsterCatalog() as $monster) {
            $row = $entries->get('monster')?->get($monster['key']);
            $monsters[] = $row === null
                ? ['registered' => false, 'hint' => $monster['appearance']]
                : [
                    'registered' => true,
                    'key' => $monster['key'],
                    'name' => $monster['name'],
                    'hp' => $monster['hp'],
                    'experience' => $monster['experience'],
                    'appearance' => $monster['appearance'],
                    'note' => $monster['note'],
                    'first_turn' => $row->first_turn === null ? null : (int) $row->first_turn,
                    'claimed' => $row->claimed_at !== null,
                ];
        }

        $items = [];
        foreach ($this->items->definitions() as $key => $definition) {
            $row = $entries->get('item')?->get($key);
            $common = [
                'category' => $definition['category'],
                'category_label' => $definition['category_label'],
                'rarity' => $definition['rarity'],
                'rarity_label' => $definition['rarity_label'],
            ];
            $items[] = $row === null
                ? ['registered' => false, ...$common]
                : [
                    'registered' => true,
                    'key' => $key,
                    'name' => $definition['name'],
                    ...$common,
                    'max_level' => $definition['max_level'],
                    'synthesis_level_cap' => $definition['synthesis_level_cap'] ?? null,
                    'effect_text' => $this->gameplay->effectText($settings, $key, $definition['max_level']),
                    'flavor_text' => $definition['flavor_text'],
                    'fixed_sale_price_money' => $definition['fixed_sale_price_money'],
                    'tradable' => $definition['tradable'],
                    'registered_at' => $this->iso($row->registered_at),
                    'claimed' => $row->claimed_at !== null,
                ];
        }

        $registered = count(array_filter($monsters, fn (array $entry): bool => $entry['registered']))
            + count(array_filter($items, fn (array $entry): bool => $entry['registered']));
        $total = count($monsters) + count($items);
        $bonuses = [];
        for ($milestone = self::BONUS_INTERVAL; $milestone <= $total; $milestone += self::BONUS_INTERVAL) {
            $bonuses[] = [
                'milestone' => $milestone,
                'reached' => $registered >= $milestone,
                'claimed' => in_array($milestone, $claimedBonuses, true),
            ];
        }
        $unclaimed = count(array_filter([...$monsters, ...$items], fn (array $entry): bool => $entry['registered'] && ! $entry['claimed']))
            + count(array_filter($bonuses, fn (array $bonus): bool => $bonus['reached'] && ! $bonus['claimed']));
        $nextMilestone = (intdiv($registered, self::BONUS_INTERVAL) + 1) * self::BONUS_INTERVAL;

        return [
            'summary' => [
                'registered' => $registered,
                'total' => $total,
                'unclaimed' => $unclaimed,
                'next_bonus_remaining' => $nextMilestone <= $total ? $nextMilestone - $registered : null,
                'entry_paradox' => self::ENTRY_PARADOX,
                'bonus_paradox' => self::BONUS_PARADOX,
            ],
            'monsters' => $monsters,
            'items' => $items,
            'bonuses' => $bonuses,
            'paradox_balance' => $this->paradox->balanceFor($user->id),
        ];
    }

    public function unclaimedCount(User $user): int
    {
        return (int) $this->index($user)['summary']['unclaimed'];
    }

    /** @return array<string, mixed> */
    public function claim(User $user, string $kind, string $key): array
    {
        DB::transaction(function () use ($user, $kind, $key): void {
            if ($kind === 'bonus') {
                $this->claimBonus($user->id, $this->milestone($key));

                return;
            }
            if (! in_array($kind, CollectionRegistrar::KINDS, true) || ! $this->catalogHas($kind, $key)) {
                throw new SecretaryInventoryException('collection_unknown_entry', '図鑑にない項目です。');
            }
            $row = DB::table('user_collection_entries')
                ->where('user_id', $user->id)->where('kind', $kind)->where('entry_key', $key)
                ->lockForUpdate()->first();
            if ($row === null) {
                throw new SecretaryInventoryException('collection_not_registered', 'まだ図鑑に登録されていません。');
            }
            $this->claimEntry($user->id, $row);
        }, 3);

        return $this->index($user);
    }

    /** @return array<string, mixed> */
    public function claimAll(User $user): array
    {
        DB::transaction(function () use ($user): void {
            $rows = DB::table('user_collection_entries')->where('user_id', $user->id)
                ->whereNull('claimed_at')->orderBy('id')->lockForUpdate()->get();
            foreach ($rows as $row) {
                if ($this->catalogHas((string) $row->kind, (string) $row->entry_key)) {
                    $this->claimEntry($user->id, $row);
                }
            }
            $registered = $this->registeredCount($user->id);
            for ($milestone = self::BONUS_INTERVAL; $milestone <= $registered; $milestone += self::BONUS_INTERVAL) {
                $this->claimBonus($user->id, $milestone);
            }
        }, 3);

        return $this->index($user);
    }

    private function claimEntry(int $userId, object $row): void
    {
        if ($row->claimed_at !== null) {
            return;
        }
        $this->paradox->credit(
            $userId,
            self::ENTRY_PARADOX,
            "collection:{$row->kind}:{$userId}:{$row->entry_key}",
            'collection',
            metadata: ['kind' => $row->kind, 'entry_key' => $row->entry_key],
        );
        DB::table('user_collection_entries')->where('id', $row->id)
            ->update(['claimed_at' => now(), 'updated_at' => now()]);
    }

    private function claimBonus(int $userId, int $milestone): void
    {
        $this->paradox->balanceForUpdate($userId);
        if ($milestone % self::BONUS_INTERVAL !== 0 || $this->registeredCount($userId) < $milestone) {
            throw new SecretaryInventoryException('collection_bonus_unreached', 'このボーナスはまだ受け取れません。');
        }
        $inserted = DB::table('user_collection_bonus_claims')->insertOrIgnore([
            'user_id' => $userId,
            'milestone' => $milestone,
            'claimed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted === 0) {
            return;
        }
        $this->paradox->credit(
            $userId,
            self::BONUS_PARADOX,
            "collection:bonus:{$userId}:{$milestone}",
            'collection',
            metadata: ['milestone' => $milestone],
        );
    }

    private function registeredCount(int $userId): int
    {
        $keys = DB::table('user_collection_entries')->where('user_id', $userId)->get(['kind', 'entry_key']);

        return $keys->filter(fn (object $row): bool => $this->catalogHas((string) $row->kind, (string) $row->entry_key))->count();
    }

    private function milestone(string $key): int
    {
        if (preg_match('/^[1-9][0-9]{0,8}$/', $key) !== 1) {
            throw new SecretaryInventoryException('collection_bonus_unreached', 'このボーナスはまだ受け取れません。');
        }

        return (int) $key;
    }

    private function catalogHas(string $kind, string $key): bool
    {
        return match ($kind) {
            'item' => array_key_exists($key, $this->items->definitions()),
            'monster' => in_array($key, array_column($this->monsterCatalog(), 'key'), true),
            default => false,
        };
    }

    /** @return list<array{key: string, name: string, hp: string, experience: string, appearance: string, note: string}> */
    private function monsterCatalog(): array
    {
        return array_map(static fn (array $row): array => [
            'key' => (string) $row['key'],
            'name' => (string) $row['name'],
            'hp' => (string) $row['hp'],
            'experience' => (string) $row['experience'],
            'appearance' => (string) $row['appearance'],
            'note' => (string) $row['note'],
        ], $this->manual->monsters());
    }

    private function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->toIso8601String();
    }
}

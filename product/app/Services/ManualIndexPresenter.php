<?php

namespace App\Services;

use App\Domain\Ship\SurfaceShipCatalog;
use App\Domain\Underground\Facility\UndergroundCommandCatalog;

/**
 * マニュアルの施設索引・コマンド索引を、いまの Ruleset から組み立てる。
 * 名前・費用・ターン消費・ランク2の名前は Ruleset を正本にし、ここでは写さない。
 * 文章（どこに建てるか・ひとこと）だけを docs/manual/indexes.php から重ねる。
 * 文章が無い項目も Ruleset の説明で索引に出るので、新しい施設やコマンドが黙って消えることはない。
 */
final class ManualIndexPresenter
{
    /**
     * @param  array<string, mixed>|null  $settings
     * @param  array<string, mixed>|null  $notes
     */
    public function __construct(
        private readonly SurfaceShipCatalog $ships,
        private readonly UndergroundCommandCatalog $undergroundCommands,
        private readonly ?array $settings = null,
        private readonly ?array $notes = null,
    ) {}

    /** @return list<array{label: string, rows: list<array{key: string, name: string, place: string, cost: string, note: string, rank_two: ?string}>}> */
    public function facilities(): array
    {
        $settings = $this->settings();
        $notes = $this->notes()['facilities'];
        $commands = array_column($settings['command_definitions'] ?? [], null, 'key');
        $ranks = $settings['facility_rank_system']['definitions'] ?? [];
        $groups = $this->emptyGroups($this->notes()['facility_groups']);

        foreach ($settings['facility_definitions'] ?? [] as $key => $definition) {
            $note = $notes[$key] ?? null;
            if ($note === null && ($definition['build_command_key'] ?? null) === null) {
                continue;
            }
            $command = $commands[$definition['build_command_key'] ?? ''] ?? null;
            $rank = $ranks[$key] ?? null;
            $groups[$this->group($groups, $note['group'] ?? null, 'other')]['rows'][] = [
                'key' => (string) $key,
                'name' => (string) $definition['name'],
                'place' => (string) ($note['place'] ?? ''),
                'cost' => $note['cost'] ?? ($command === null ? '—' : $this->money((int) $command['cost_money'])),
                'note' => (string) ($note['note'] ?? ($command['description'] ?? '')),
                'rank_two' => isset($rank['rank_two_name'])
                    ? "{$rank['rank_two_name']}：".($rank['promotion_description'] ?? '').'。'.($rank['rank_two_effect_description'] ?? '')
                    : null,
            ];
        }

        $underground = $settings['underground_facility_development']['facility_definitions'] ?? [];
        foreach ($this->undergroundCommands->all($settings) as $definition) {
            if ($definition->action !== 'build' || ! isset($underground[$definition->facility_key])) {
                continue;
            }
            $note = $notes[$definition->facility_key] ?? null;
            $groups[$this->group($groups, $note['group'] ?? null, 'underground')]['rows'][] = [
                'key' => (string) $definition->facility_key,
                'name' => (string) $underground[$definition->facility_key]['name'],
                'place' => (string) ($note['place'] ?? '首都地下の空き枠'),
                'cost' => $this->money($definition->cost_money),
                'note' => (string) ($note['note'] ?? $definition->description),
                'rank_two' => null,
            ];
        }

        // 文章側の並び（indexes.php の書き順）でグループ内を並べる。文章が無い項目は後ろ。
        $order = array_flip(array_keys($notes));
        foreach ($groups as &$group) {
            usort($group['rows'], static fn (array $left, array $right): int => ($order[$left['key']] ?? PHP_INT_MAX) <=> ($order[$right['key']] ?? PHP_INT_MAX));
        }
        unset($group);

        return $this->nonEmpty($groups);
    }

    /** @return list<array{name: string, cost: string, note: string}> */
    public function ships(): array
    {
        $notes = $this->notes()['ships'];
        $rows = [];
        foreach ($this->ships->definitions($this->settings()) as $definition) {
            if (! $definition->playerBuildable) {
                continue;
            }
            $rows[$definition->sortOrder.'-'.$definition->key] = [
                'name' => $definition->name,
                'cost' => $this->money($definition->buildCostMoney),
                'note' => (string) ($notes[$definition->key] ?? ''),
            ];
        }
        ksort($rows, SORT_NATURAL);

        return array_values($rows);
    }

    /** @return list<string> 秘書の地上技能の名前。効果と条件は秘書画面を正本にする。 */
    public function skills(): array
    {
        return array_values(array_map(
            static fn (array $skill): string => (string) $skill['name'],
            array_filter($this->settings()['secretary']['skills'] ?? [], static fn (mixed $skill): bool => is_array($skill) && isset($skill['name'])),
        ));
    }

    /** @return list<array{label: string, rows: list<array{name: string, cost: string, turn: bool, note: string}>}> */
    public function commands(): array
    {
        $settings = $this->settings();
        $notes = $this->notes()['commands'];
        $groups = $this->emptyGroups($this->notes()['command_groups']);

        $definitions = $settings['command_definitions'] ?? [];
        usort($definitions, static fn (array $left, array $right): int => $left['sort_order'] <=> $right['sort_order']);
        foreach ($definitions as $definition) {
            $note = $notes[$definition['key']] ?? null;
            $metadata = $definition['metadata'] ?? [];
            $groups[$this->group($groups, $note['group'] ?? null, 'other')]['rows'][] = [
                'name' => (string) ($note['label'] ?? $definition['name']),
                'cost' => ($note['cost_prefix'] ?? '').($note['cost'] ?? $this->commandCost($definition)),
                'turn' => (bool) ($metadata['consumes_turn'] ?? true),
                'note' => (string) ($note['note'] ?? $definition['description']),
            ];
        }
        foreach ($this->undergroundCommands->all($settings) as $definition) {
            $note = $notes[$definition->key] ?? null;
            $groups[$this->group($groups, $note['group'] ?? null, 'underground')]['rows'][] = [
                'name' => $definition->name,
                'cost' => $this->money($definition->cost_money),
                'turn' => (bool) ($definition->metadata['consumes_turn'] ?? true),
                'note' => (string) ($note['note'] ?? $definition->description),
            ];
        }

        return $this->nonEmpty($groups);
    }

    /** @param array<string, mixed> $definition */
    private function commandCost(array $definition): string
    {
        $metadata = $definition['metadata'] ?? [];
        $money = (int) $definition['cost_money'];
        $options = array_filter(
            $metadata[$metadata['quantity_selects_catalog'] ?? ''] ?? [],
            static fn (mixed $option): bool => is_array($option) && isset($option['cost_money']) && ($option['enabled'] ?? true),
        );
        if (($metadata['quantity_selects_catalog'] ?? null) === SurfaceShipCatalog::CATALOG) {
            $options = array_map(
                static fn ($ship): array => ['cost_money' => $ship->buildCostMoney],
                array_filter($this->ships->definitions($this->settings()), static fn ($ship): bool => $ship->playerBuildable),
            );
        }
        if ($options !== []) {
            $costs = array_map(static fn (array $option): int => (int) $option['cost_money'], $options);
            $money = min($costs);
            if ($money !== max($costs)) {
                return $this->money($money).'〜';
            }
        }
        $label = $money === 0 ? '無料' : $this->money($money);
        if (isset($metadata['cost_paradox'])) {
            $label .= '＋'.number_format((int) $metadata['cost_paradox']).'Pd';
        }

        return $label;
    }

    /** @param array<string, mixed> $groups */
    private function group(array $groups, ?string $wanted, string $fallback): string
    {
        foreach ([$wanted, $fallback] as $key) {
            if ($key !== null && isset($groups[$key])) {
                return $key;
            }
        }

        return 'other';
    }

    private function money(int $money): string
    {
        return number_format($money).'億円';
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, array{label: string, rows: list<array<string, mixed>>}>
     */
    private function emptyGroups(array $labels): array
    {
        $groups = [];
        foreach ($labels + ['other' => 'その他'] as $key => $label) {
            $groups[$key] = ['label' => $label, 'rows' => []];
        }

        return $groups;
    }

    /**
     * @param  array<string, array{label: string, rows: list<array<string, mixed>>}>  $groups
     * @return list<array{label: string, rows: list<array<string, mixed>>}>
     */
    private function nonEmpty(array $groups): array
    {
        return array_values(array_filter($groups, static fn (array $group): bool => $group['rows'] !== []));
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return $this->settings ?? config('hakoniwa.ruleset', []);
    }

    /** @return array<string, mixed> */
    private function notes(): array
    {
        return $this->notes ?? require base_path('docs/manual/indexes.php');
    }
}

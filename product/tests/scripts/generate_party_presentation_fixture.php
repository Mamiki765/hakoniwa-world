<?php

declare(strict_types=1);

use App\Application\Underground\UndergroundPartyBattleProjector;
use Tests\Underground\Fixtures\PartyPresentationFixture;

$productRoot = dirname(__DIR__, 2);
require $productRoot.'/vendor/autoload.php';

$fixture = PartyPresentationFixture::create();
$projection = (new UndergroundPartyBattleProjector)->project(
    $fixture['result'],
    $fixture['member_snapshots'],
    $fixture['catalog'],
);

$partyMembers = [];
$partyEnemies = [];
foreach ($fixture['member_snapshots'] as $combatantId => $member) {
    $images = is_array($member['image_references'] ?? null) ? $member['image_references'] : [];
    $icon = is_array($images['compact'] ?? null) ? $images['compact'] : [];
    $portrait = is_array($images['normal'] ?? null) ? $images['normal'] : [];
    $row = [
        'team' => ($member['team'] ?? null) === 'enemy' ? 'enemy' : 'player',
        'combatant_id' => $combatantId,
        'display_name' => is_string($member['display_name'] ?? null)
            ? $member['display_name']
            : (is_string($member['label'] ?? null) ? $member['label'] : $combatantId),
        'icon_url' => is_string($icon['url'] ?? null) ? $icon['url'] : null,
        'portrait_url' => is_string($portrait['url'] ?? null) ? $portrait['url'] : null,
        'image_references' => $images,
        'state' => null,
        'awakening_state' => null,
    ];
    if ($row['team'] === 'player') {
        $partyMembers[] = $row;
    } else {
        $partyEnemies[] = $row;
    }
}

$summary = $projection['summary'];
$winner = $summary['winner'] ?? 'stalemate';
$result = match ($winner) {
    'player' => 'victory',
    'enemy' => 'defeat',
    default => 'stalemate',
};
$payload = [
    'id' => 'party-presentation-fixture',
    'context' => 'exploration',
    'presentation_log_version' => $projection['version'],
    'party' => [
        'party_id' => 'fixture-party',
        'party_size' => count($partyMembers),
        'enemy_count' => count($partyEnemies),
        'members' => $partyMembers,
        'enemies' => $partyEnemies,
    ],
    'portrait_events' => $projection['portrait_events'],
    'player_display_name' => 'Leader',
    'encounter_name' => 'PT表示試験体',
    'result' => $result,
    'rounds' => $projection['rounds'],
    'rounds_count' => $summary['rounds'],
    'xp_awarded' => 0,
    'shard_delta' => 0,
    'detail_available' => true,
    'summary' => $summary,
    'initial_state' => $projection['initial_state'],
    'rewards' => ['xp' => 0, 'shards' => 0],
];

$target = $argv[1] ?? $productRoot.'/resources/js/components/__fixtures__/party-presentation.json';
$target = is_string($target) && $target !== '' ? $target : throw new RuntimeException('Fixture output path is invalid.');
$directory = dirname($target);
if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
    throw new RuntimeException('Fixture output directory cannot be created.');
}
$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
if (file_put_contents($target, $json, LOCK_EX) === false) {
    throw new RuntimeException('Fixture output cannot be written.');
}

fwrite(STDOUT, $target."\n");

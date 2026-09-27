<?php

namespace App\Application\Underground;

use JsonException;
use RuntimeException;

final class UndergroundTrialThreeStory
{
    /** @return array<string, mixed> */
    private function source(): array
    {
        try {
            $source = json_decode(
                file_get_contents(config_path('underground/trial3-story.json')) ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Trial 3 story source is invalid.', previous: $exception);
        }
        if (! is_array($source)) {
            throw new RuntimeException('Trial 3 story source is invalid.');
        }

        return $source;
    }

    public function scene(string $key): string
    {
        $scene = $this->source()[$key] ?? null;

        return is_string($scene) && $scene !== ''
            ? $scene
            : throw new RuntimeException("Trial 3 story scene [{$key}] is invalid.");
    }

    /** @return array{title: string, body: string} */
    public function firstClear(string $secretaryName, ?string $guideName): array
    {
        $story = $this->source()['first_clear'] ?? null;
        if (! is_array($story) || ! is_string($story['title'] ?? null)
            || ! is_string($story['body'] ?? null)) {
            throw new RuntimeException('Trial 3 first-clear story is invalid.');
        }

        return [
            'title' => $story['title'],
            'body' => str_replace(
                ['(秘書名)', '(案内人名)'],
                [$secretaryName, $guideName ?: 'リカ＝サキュバス'],
                $story['body'],
            ),
        ];
    }
}

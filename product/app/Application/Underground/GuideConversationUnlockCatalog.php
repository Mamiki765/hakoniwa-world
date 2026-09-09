<?php

namespace App\Application\Underground;

use RuntimeException;

final class GuideConversationUnlockCatalog
{
    /** @return list<array{key: string, label: string}> */
    public function options(): array
    {
        $options = [['key' => 'always', 'label' => '常時']];
        $trials = config('underground-runtime.trials');
        if (! is_array($trials)) {
            throw new RuntimeException('Underground trial catalog is invalid.');
        }
        foreach ($trials as $key => $trial) {
            if (! is_string($key) || ! is_array($trial) || ! is_string($trial['label'] ?? null)) {
                throw new RuntimeException('Underground trial catalog is invalid.');
            }
            $options[] = [
                'key' => $this->trialFirstClearKey($key),
                'label' => $trial['label'].' 初回クリア',
            ];
        }

        return $options;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_column($this->options(), 'key');
    }

    public function trialFirstClearKey(string $trialKey): string
    {
        return $trialKey.'_first_clear';
    }
}

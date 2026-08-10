<?php

namespace App\Application;

use RuntimeException;

class VisitorCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public function candidate(string $provider, string $providerUserId, int $collisionCounter): string
    {
        if (! in_array($provider, ['discord', 'google'], true) || $providerUserId === '' || $collisionCounter < 0) {
            throw new RuntimeException('Visitor code input is invalid.');
        }

        $key = $this->applicationKey();
        $domain = implode('|', [
            'hakoniwa-message-board-visitor:v1',
            $provider,
            $providerUserId,
            "collision:{$collisionCounter}",
        ]);
        $candidate = '';

        for ($block = 0; strlen($candidate) < 8; $block++) {
            $digest = hash_hmac('sha256', $domain."|block:{$block}", $key, true);
            foreach (unpack('C*', $digest) ?: [] as $byte) {
                if ($byte >= 248) {
                    continue;
                }
                $candidate .= self::ALPHABET[$byte % 62];
                if (strlen($candidate) === 8) {
                    return $candidate;
                }
            }
        }

        throw new RuntimeException('Visitor code derivation did not produce a candidate.');
    }

    private function applicationKey(): string
    {
        $configured = config('app.key');
        if (! is_string($configured) || $configured === '') {
            throw new RuntimeException('APP_KEY is required for visitor code allocation.');
        }
        if (! str_starts_with($configured, 'base64:')) {
            return $configured;
        }

        $decoded = base64_decode(substr($configured, 7), true);
        if (! is_string($decoded) || $decoded === '') {
            throw new RuntimeException('APP_KEY is not valid base64.');
        }

        return $decoded;
    }
}

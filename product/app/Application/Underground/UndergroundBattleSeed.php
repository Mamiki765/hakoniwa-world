<?php

namespace App\Application\Underground;

use RuntimeException;

final class UndergroundBattleSeed
{
    public function forRequest(int $profileId, string $requestId, string $runtimeIdentity): int
    {
        $applicationKey = (string) config('app.key');
        if ($applicationKey === '') {
            throw new RuntimeException('The application key is required for private Underground battle seeds.');
        }

        $digest = hash_hmac(
            'sha256',
            $runtimeIdentity."\0".$profileId."\0".$requestId,
            $applicationKey,
            true,
        );
        $decoded = unpack('Nseed', substr($digest, 0, 4));
        if (! is_array($decoded) || ! is_int($decoded['seed'] ?? null)) {
            throw new RuntimeException('Unable to derive an Underground battle seed.');
        }

        return $decoded['seed'] & 0x7FFFFFFF;
    }
}

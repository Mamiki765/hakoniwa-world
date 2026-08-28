<?php

namespace App\Application\Underground;

use InvalidArgumentException;

final class UndergroundReportSourceIdentity
{
    public function resolve(?string $explicitCommitSha, ?string $detectedCommitSha): string
    {
        if ($explicitCommitSha !== null && ! $this->validCommitSha($explicitCommitSha)) {
            throw new InvalidArgumentException('--commit-sha must be exactly 40 lowercase hexadecimal characters.');
        }
        $detectedCommitSha = is_string($detectedCommitSha) && $this->validCommitSha($detectedCommitSha)
            ? $detectedCommitSha
            : null;
        if ($explicitCommitSha !== null
            && $detectedCommitSha !== null
            && ! hash_equals($detectedCommitSha, $explicitCommitSha)) {
            throw new InvalidArgumentException(
                '--commit-sha must match the detected Git HEAD when repository metadata is available.',
            );
        }

        return $explicitCommitSha ?? $detectedCommitSha ?? 'unknown';
    }

    private function validCommitSha(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{40}\z/D', $value) === 1;
    }
}

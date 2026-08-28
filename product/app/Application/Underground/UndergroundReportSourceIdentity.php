<?php

namespace App\Application\Underground;

use InvalidArgumentException;

final class UndergroundReportSourceIdentity
{
    public function resolve(
        ?string $explicitCommitSha,
        ?string $detectedCommitSha,
        ?bool $workingTreeDirty,
    ): string {
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
        if ($detectedCommitSha !== null && $workingTreeDirty !== false) {
            throw new InvalidArgumentException($workingTreeDirty === true
                ? 'Underground reports require a clean Git worktree.'
                : 'Unable to verify a clean Git worktree for the detected source commit.');
        }

        $commitSha = $explicitCommitSha ?? $detectedCommitSha;
        if ($commitSha === null) {
            throw new InvalidArgumentException(
                'Unable to determine the exact source commit; provide --commit-sha when Git metadata is unavailable.',
            );
        }

        return $commitSha;
    }

    private function validCommitSha(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{40}\z/D', $value) === 1;
    }
}

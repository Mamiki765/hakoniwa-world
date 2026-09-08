<?php

namespace App\Application;

use App\Models\Secretary;
use App\Models\SecretaryImage;
use App\Models\UndergroundBattle;
use App\Models\UndergroundBattleImageReference;
use App\Models\UndergroundBattleLog;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Keeps image files available for the same period as the battle action log.
 *
 * Battle snapshots keep display metadata, while this table keeps only the
 * normalized file-path relationship needed for safe replacement and prune.
 * The service deliberately accepts the small member snapshot produced at
 * battle creation; it never searches existing battle JSON on image upload.
 */
final readonly class SecretaryImageRetentionService
{
    private const IMAGE_DISK = 'secretary_images';

    private const PATH_PATTERN = '/\\A[0-9a-f]{64}\\.(?:png|jpg|webp|gif)\\z/';

    /**
     * Build the canonical reservation identity for a profile-scoped battle
     * request. Request IDs are only idempotent within a profile, so the raw
     * UUID must never be used as a global image-reference key.
     */
    public function reservationKey(int $profileId, string $requestId): string
    {
        if ($profileId < 1 || $requestId === '') {
            throw new \InvalidArgumentException('Battle image retention reservation identity is invalid.');
        }

        return hash('sha256', 'underground-battle-image-reference:v1:'.$profileId.':'.$requestId);
    }

    /**
     * Reserve image paths while a battle snapshot is prepared.
     *
     * The caller must invoke this inside the same short transaction that locks
     * the source Secretary/image rows. The request key is retained when the
     * rows are promoted, which makes a retry idempotent and closes the gap
     * before the battle row exists.
     *
     * @param  array<array-key, mixed>  $memberSnapshots
     */
    public function reserveBattleImages(
        string $referenceKey,
        array $memberSnapshots,
        DateTimeInterface $retainedUntil,
    ): int {
        $this->assertReferenceKey($referenceKey);
        $paths = $this->pathsFromMemberSnapshots($memberSnapshots);
        // Another request may have settled while this preparation waited on
        // the source lock. Its existing lease belongs to the same scoped
        // request; leave it intact and let runtime recover the saved result.
        if (UndergroundBattleImageReference::query()
            ->where('reference_key', $referenceKey)
            ->whereNotNull('underground_battle_id')
            ->exists()) {
            return 0;
        }

        return $this->reservePaths($referenceKey, $paths, $retainedUntil);
    }

    /**
     * Reserve image paths from the explicit image containers in a snapshot.
     * No arbitrary snapshot JSON is searched.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function reserveSnapshotImages(
        string $referenceKey,
        array $snapshot,
        DateTimeInterface $retainedUntil,
    ): int {
        $this->assertReferenceKey($referenceKey);

        return $this->reserveBattleImages(
            $referenceKey,
            $this->memberSnapshotsFromSnapshot($snapshot),
            $retainedUntil,
        );
    }

    /**
     * Retain image paths from party member snapshots until the battle log
     * expires. Call this after the battle and action log are created in the
     * same settlement transaction. If a request-keyed lease already exists,
     * this promotes it atomically to the persisted battle.
     *
     * @param  array<array-key, mixed>  $memberSnapshots
     */
    public function retainBattleImages(
        UndergroundBattle $battle,
        array $memberSnapshots,
        ?DateTimeInterface $retainedUntil = null,
        ?string $referenceKey = null,
    ): int {
        $battleId = $this->battleId($battle);
        $referenceKey ??= $this->referenceKeyForBattle($battle, $battleId);
        $this->assertReferenceKey($referenceKey);
        $paths = $this->pathsFromMemberSnapshots($memberSnapshots);
        if ($paths === []) {
            return 0;
        }
        $retainedUntil = $this->retentionUntilForBattle($battle, $retainedUntil);
        $this->assertReferenceKeyOwnership($referenceKey, $battleId);
        $now = Carbon::now();
        $rows = array_map(
            static fn (string $path): array => [
                'reference_key' => $referenceKey,
                'underground_battle_id' => $battleId,
                'retained_until' => $retainedUntil,
                'path' => $path,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $paths,
        );
        UndergroundBattleImageReference::query()->upsert(
            $rows,
            ['reference_key', 'path'],
            ['underground_battle_id', 'retained_until', 'updated_at'],
        );

        return count($rows);
    }

    /**
     * Promote a request-keyed lease after its battle and action log exist.
     * This method is safe to call more than once for the same request key.
     */
    public function promoteReservedImages(
        string $referenceKey,
        UndergroundBattle $battle,
        ?DateTimeInterface $retainedUntil = null,
    ): int {
        $this->assertReferenceKey($referenceKey);
        $battleId = $this->battleId($battle);
        $retainedUntil = $this->retentionUntilForBattle($battle, $retainedUntil);
        $this->assertReferenceKeyOwnership($referenceKey, $battleId);

        return UndergroundBattleImageReference::query()
            ->where('reference_key', $referenceKey)
            ->where(function ($query) use ($battleId): void {
                $query->whereNull('underground_battle_id')
                    ->orWhere('underground_battle_id', $battleId);
            })
            ->update([
                'underground_battle_id' => $battleId,
                'retained_until' => $retainedUntil,
                'updated_at' => now(),
            ]);
    }

    /**
     * Retain image paths from a stored battle snapshot. Only the known image
     * reference containers are read so arbitrary snapshot data is untouched.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function retainSnapshotImages(
        UndergroundBattle $battle,
        array $snapshot,
        ?DateTimeInterface $retainedUntil = null,
        ?string $referenceKey = null,
    ): int {
        return $this->retainBattleImages(
            $battle,
            $this->memberSnapshotsFromSnapshot($snapshot),
            $retainedUntil,
            $referenceKey,
        );
    }

    /**
     * Return whether a path is covered by an active battle retention lease.
     * Both request-keyed preparation leases and promoted battle references are
     * checked, so image replacement never needs to inspect battle JSON.
     */
    public function isRetained(string $path, ?DateTimeInterface $now = null): bool
    {
        if (! preg_match(self::PATH_PATTERN, $path)) {
            return false;
        }
        $at = $now instanceof DateTimeInterface ? Carbon::instance($now) : Carbon::now();

        return DB::table('underground_battle_image_references as refs')
            ->where('refs.path', $path)
            ->where('refs.retained_until', '>', $at)
            ->exists();
    }

    /**
     * Remove expired preparation/battle leases and delete files when no
     * current Secretary image still uses the path. The conditional delete
     * protects a lease extended between candidate selection and deletion.
     *
     * @return int number of normalized references removed
     */
    public function pruneExpired(int $batchSize = 1000, ?DateTimeInterface $now = null): int
    {
        if ($batchSize < 1 || $batchSize > 10_000) {
            throw new \InvalidArgumentException('Secretary image retention batch size is invalid.');
        }
        $at = $now instanceof DateTimeInterface ? Carbon::instance($now) : Carbon::now();
        $rows = DB::table('underground_battle_image_references as refs')
            ->where('refs.retained_until', '<=', $at)
            ->orderBy('refs.id')
            ->limit($batchSize)
            ->get(['refs.id', 'refs.path']);
        if ($rows->isEmpty()) {
            return 0;
        }
        $ids = $rows->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $paths = $rows->pluck('path')->filter(static fn (mixed $path): bool => is_string($path))->unique()->values()->all();
        // Recheck the lease timestamp in the delete predicate. A settlement
        // that extends a path after the candidate query must win over prune.
        $deleted = DB::table('underground_battle_image_references')
            ->whereIn('id', $ids)
            ->where('retained_until', '<=', $at)
            ->delete();

        foreach ($paths as $path) {
            $this->deleteIfUnreferenced($path, $at);
        }

        return $deleted;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function memberSnapshotsFromSnapshot(array $snapshot): array
    {
        $members = [];
        foreach (['player_image_references', 'image_references'] as $key) {
            if (is_array($snapshot[$key] ?? null)) {
                $members[] = ['image_references' => $snapshot[$key]];
            }
        }
        $party = $snapshot['party'] ?? null;
        if (is_array($party) && is_array($party['members'] ?? null)) {
            foreach ($party['members'] as $member) {
                if (is_array($member)) {
                    $members[] = $member;
                }
            }
        }
        if (is_array($snapshot['portrait_events'] ?? null)) {
            foreach ($snapshot['portrait_events'] as $event) {
                if (! is_array($event)) {
                    continue;
                }
                if (is_array($event['image_refs'] ?? null)) {
                    $members[] = ['image_references' => $event['image_refs']];
                }
                if (is_array($event['image_ref'] ?? null)) {
                    $members[] = ['image_references' => ['event' => $event['image_ref']]];
                }
            }
        }

        return $members;
    }

    /**
     * @param  list<string>  $paths
     */
    private function reservePaths(
        string $referenceKey,
        array $paths,
        DateTimeInterface $retainedUntil,
    ): int {
        if ($paths === []) {
            return 0;
        }
        $now = Carbon::now();
        $until = Carbon::instance($retainedUntil);
        if ($until->lessThanOrEqualTo($now)) {
            throw new \InvalidArgumentException('Battle image retention lease must be in the future.');
        }
        $rows = array_map(
            static fn (string $path): array => [
                'reference_key' => $referenceKey,
                'underground_battle_id' => null,
                'retained_until' => $until,
                'path' => $path,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $paths,
        );
        UndergroundBattleImageReference::query()->upsert(
            $rows,
            ['reference_key', 'path'],
            ['retained_until', 'updated_at'],
        );

        return count($rows);
    }

    private function battleId(UndergroundBattle $battle): int
    {
        $battleId = $battle->getKey();
        if (! is_int($battleId) && ! (is_string($battleId) && ctype_digit($battleId))) {
            throw new \InvalidArgumentException('Battle image retention requires a persisted battle.');
        }

        return (int) $battleId;
    }

    private function referenceKeyForBattle(UndergroundBattle $battle, int $battleId): string
    {
        $requestId = $battle->getAttribute('request_id');
        $profileId = $battle->getAttribute('underground_profile_id');
        if (is_string($requestId)
            && $requestId !== ''
            && (is_int($profileId) || (is_string($profileId) && ctype_digit($profileId)))) {
            $reservationKey = $this->reservationKey((int) $profileId, $requestId);
            if (UndergroundBattleImageReference::query()->where('reference_key', $reservationKey)->exists()) {
                return $reservationKey;
            }
        }

        return 'battle:'.$battleId;
    }

    private function retentionUntilForBattle(
        UndergroundBattle $battle,
        ?DateTimeInterface $retainedUntil,
    ): Carbon {
        if ($retainedUntil instanceof DateTimeInterface) {
            $until = Carbon::instance($retainedUntil);
        } else {
            $log = $battle->relationLoaded('log')
                ? $battle->getRelation('log')
                : $battle->log()->first();
            if (! $log instanceof UndergroundBattleLog) {
                throw new \RuntimeException('Battle image retention requires an action log expiration.');
            }
            $expiresAt = $log->getAttribute('expires_at');
            if (! $expiresAt instanceof DateTimeInterface) {
                throw new \RuntimeException('Battle image retention requires an action log expiration.');
            }
            $until = Carbon::instance($expiresAt);
        }
        if ($until->lessThanOrEqualTo(Carbon::now())) {
            throw new \InvalidArgumentException('Battle image retention lease must be in the future.');
        }

        return $until;
    }

    private function assertReferenceKey(string $referenceKey): void
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9:_-]{0,63}\z/', $referenceKey)) {
            throw new \InvalidArgumentException('Battle image retention reference key is invalid.');
        }
    }

    private function assertReferenceKeyOwnership(string $referenceKey, int $battleId): void
    {
        $otherBattleExists = UndergroundBattleImageReference::query()
            ->where('reference_key', $referenceKey)
            ->whereNotNull('underground_battle_id')
            ->where('underground_battle_id', '!=', $battleId)
            ->exists();
        if ($otherBattleExists) {
            throw new \RuntimeException('Battle image retention reference key is already bound to another battle.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $memberSnapshots
     * @return list<string>
     */
    private function pathsFromMemberSnapshots(array $memberSnapshots): array
    {
        $paths = [];
        foreach ($memberSnapshots as $member) {
            if (! is_array($member)) {
                continue;
            }
            $member = is_array($member['snapshot'] ?? null) ? $member['snapshot'] : $member;
            $references = $member['image_references'] ?? null;
            if (! is_array($references)) {
                continue;
            }
            foreach ($references as $reference) {
                if (! is_array($reference)) {
                    continue;
                }
                $path = $reference['path'] ?? null;
                if (is_string($path) && preg_match(self::PATH_PATTERN, $path)) {
                    $paths[$path] = true;
                }
            }
        }

        return array_keys($paths);
    }

    private function deleteIfUnreferenced(string $path, Carbon $now): void
    {
        if (! preg_match(self::PATH_PATTERN, $path)
            || $this->isRetained($path, $now)
            || Secretary::query()->where('main_image_path', $path)->exists()
            || SecretaryImage::query()->where('path', $path)->exists()) {
            return;
        }
        try {
            if (Storage::disk(self::IMAGE_DISK)->delete($path)) {
                return;
            }
            Log::error('Secretary image retention cleanup left an orphaned file.', [
                'old_path' => $path,
                'exception_class' => null,
            ]);
        } catch (Throwable $exception) {
            Log::error('Secretary image retention cleanup left an orphaned file.', [
                'old_path' => $path,
                'exception_class' => $exception::class,
            ]);
        }
    }
}

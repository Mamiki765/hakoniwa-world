<?php

namespace App\Application;

use App\Domain\MessageBoard\MessageBoardContract;
use App\Domain\MessageBoard\MessageBoardCooldownException;
use App\Domain\MessageBoard\MessageBoardValidationException;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Models\IslandMessage;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\User;
use App\Models\World;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class MessageBoardService
{
    public function __construct(
        private readonly VisitorCodeAllocator $visitorCodes,
        private readonly MessageBoardAuditRecorder $audit,
        private readonly CurrentRulesetGuard $rulesetGuard,
    ) {}

    /** @return array<string, mixed> */
    public function timeline(Nation $board, ?User $viewer): array
    {
        $viewerNation = $viewer === null ? null : $this->viewerNation($viewer, $board->world_id);
        $viewerHasNation = $viewerNation !== null || ($viewer !== null && NationMembership::query()
            ->where('user_id', $viewer->id)
            ->where('role', 'owner')
            ->exists());
        $viewerOwnsBoard = $viewerNation?->id === $board->id;
        $canSendSecret = $viewerNation !== null
            && $viewerNation->id !== $board->id
            && $this->isReachable($viewerNation)
            && $this->isReachable($board);

        $messages = IslandMessage::query()
            ->where(function (Builder $query) use ($board, $viewerOwnsBoard): void {
                $query->where('target_nation_id', $board->id);
                if ($viewerOwnsBoard) {
                    $query->orWhere(function (Builder $outgoing) use ($board): void {
                        $outgoing->where('message_type', IslandMessage::TYPE_SECRET)
                            ->where('secret_sender_nation_id', $board->id);
                    });
                }
            })
            ->with([
                'authorUser:id,visitor_code',
                'authorNation:id,nation_number,name',
                'secretSenderNation:id,nation_number,name',
                'targetNation:id,nation_number,name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(MessageBoardContract::TIMELINE_LIMIT)
            ->get();

        return [
            'board' => [
                'nation_number' => (int) $board->nation_number,
                'name' => $board->name,
            ],
            'entries' => $messages->map(
                fn (IslandMessage $message): array => $this->project($message, $board, $viewerNation),
            )->values()->all(),
            'viewer' => [
                'authenticated' => $viewer !== null,
                'can_post' => $viewer !== null && $this->isReachable($board)
                    && ($viewerNation !== null || ! $viewerHasNation),
                'author_type' => $viewer === null ? null : match (true) {
                    ! $viewerHasNation => 'visitor',
                    $viewerNation === null => null,
                    $viewerNation->id === $board->id => 'owner',
                    default => 'other_nation',
                },
                'can_send_secret' => $canSendSecret,
            ],
            'contract' => [
                'latest_limit' => MessageBoardContract::TIMELINE_LIMIT,
                'body_max_characters' => MessageBoardContract::BODY_MAX_CHARACTERS,
                'cooldown_seconds' => MessageBoardContract::COOLDOWN_SECONDS,
                ...($canSendSecret ? [
                    'secret_cost_money' => MessageBoardContract::SECRET_COST_MONEY,
                    'secret_cost_display' => '100億円',
                ] : []),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function postPublic(User $user, Nation $target, string $body): array
    {
        $body = $this->validBody($body);

        DB::transaction(function () use ($user, $target, $body): void {
            $world = World::query()->whereKey($target->world_id)->lockForUpdate()->firstOrFail();
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $membership = NationMembership::query()
                ->where('user_id', $lockedUser->id)
                ->where('world_id', $world->id)
                ->where('role', 'owner')
                ->first();
            if ($membership === null && NationMembership::query()
                ->where('user_id', $lockedUser->id)
                ->where('role', 'owner')
                ->exists()) {
                throw new MessageBoardValidationException(
                    'target_nation',
                    '別Worldの島からこの伝言板へ投稿することはできません。',
                );
            }
            $nationIds = [$target->id];
            if ($membership !== null) {
                $nationIds[] = (int) $membership->nation_id;
            }
            $lockedNations = $this->lockNations($world, $nationIds);
            $lockedTarget = $lockedNations->firstWhere('id', $target->id);
            if (! $lockedTarget instanceof Nation || ! $this->isReachable($lockedTarget)) {
                throw new MessageBoardValidationException('target_nation', 'この島の伝言板には投稿できません。');
            }
            $authorNation = $membership === null
                ? null
                : $lockedNations->firstWhere('id', (int) $membership->nation_id);
            if ($membership !== null && ! $authorNation instanceof Nation) {
                throw new MessageBoardValidationException('target_nation', '投稿者の島情報が一致しません。');
            }
            $this->assertWorldMutable($world);

            $now = now();
            $this->assertCooldown($lockedUser, $now);
            if ($authorNation === null) {
                $this->visitorCodes->allocate($lockedUser);
            }

            IslandMessage::query()->create([
                'public_id' => (string) Str::uuid(),
                'world_id' => $world->id,
                'target_nation_id' => $lockedTarget->id,
                'author_user_id' => $lockedUser->id,
                'author_kind' => $authorNation === null
                    ? IslandMessage::AUTHOR_VISITOR
                    : IslandMessage::AUTHOR_NATION,
                'author_nation_id' => $authorNation?->id,
                'secret_sender_nation_id' => null,
                'message_type' => IslandMessage::TYPE_PUBLIC,
                'body' => $body,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $lockedUser->forceFill(['message_board_last_posted_at' => $now])->save();
            $this->retainTargetBoard($lockedTarget);
        }, 3);

        return $this->timeline($target->fresh(), $user->fresh());
    }

    /** @return array<string, mixed> */
    public function postSecret(User $user, Nation $target, string $body): array
    {
        $body = $this->validBody($body);

        DB::transaction(function () use ($user, $target, $body): void {
            $world = World::query()->whereKey($target->world_id)->lockForUpdate()->firstOrFail();
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $membership = NationMembership::query()
                ->where('user_id', $lockedUser->id)
                ->where('world_id', $world->id)
                ->where('role', 'owner')
                ->first();
            if ($membership === null) {
                throw new AuthorizationException('秘密通信は島主だけが送信できます。');
            }
            if ((int) $membership->nation_id === $target->id) {
                throw new MessageBoardValidationException('target_nation', '自分の島へ秘密通信は送れません。');
            }

            $lockedNations = $this->lockNations($world, [(int) $membership->nation_id, $target->id]);
            $sender = $lockedNations->firstWhere('id', (int) $membership->nation_id);
            $lockedTarget = $lockedNations->firstWhere('id', $target->id);
            if (! $sender instanceof Nation || ! $lockedTarget instanceof Nation) {
                throw new MessageBoardValidationException('target_nation', '同じWorldの島を指定してください。');
            }
            if (! $this->isReachable($sender) || ! $this->isReachable($lockedTarget)) {
                throw new MessageBoardValidationException('target_nation', 'この島とは秘密通信できません。');
            }
            $this->assertWorldMutable($world);

            $now = now();
            $this->assertCooldown($lockedUser, $now);
            if ((int) $sender->money < MessageBoardContract::SECRET_COST_MONEY) {
                throw new MessageBoardValidationException('money', '秘密通信には100億円必要です。');
            }

            $sender->money = (int) $sender->money - MessageBoardContract::SECRET_COST_MONEY;
            $sender->save();
            $message = IslandMessage::query()->create([
                'public_id' => (string) Str::uuid(),
                'world_id' => $world->id,
                'target_nation_id' => $lockedTarget->id,
                'author_user_id' => $lockedUser->id,
                'author_kind' => IslandMessage::AUTHOR_NATION,
                'author_nation_id' => $sender->id,
                'secret_sender_nation_id' => $sender->id,
                'message_type' => IslandMessage::TYPE_SECRET,
                'body' => $body,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $lockedUser->forceFill(['message_board_last_posted_at' => $now])->save();
            $this->retainTargetBoard($lockedTarget);
            $this->audit->secretSent(
                $world,
                $lockedUser,
                $sender,
                $lockedTarget,
                $message,
                MessageBoardContract::SECRET_COST_MONEY,
            );
        }, 3);

        return $this->timeline($target->fresh(), $user->fresh());
    }

    private function validBody(string $body): string
    {
        $length = mb_strlen($body, 'UTF-8');
        if ($length < 1 || $length > MessageBoardContract::BODY_MAX_CHARACTERS) {
            throw new MessageBoardValidationException(
                'body',
                '本文は1〜140文字で入力してください。',
            );
        }

        return $body;
    }

    private function assertCooldown(User $user, Carbon $now): void
    {
        $lastPostedAt = $user->message_board_last_posted_at;
        if (! $lastPostedAt instanceof Carbon) {
            return;
        }
        $retryAt = $lastPostedAt->copy()->addSeconds(MessageBoardContract::COOLDOWN_SECONDS);
        if ($now->greaterThanOrEqualTo($retryAt)) {
            return;
        }

        $remainingMilliseconds = $now->diffInMilliseconds($retryAt);
        throw new MessageBoardCooldownException(
            max(1, (int) ceil($remainingMilliseconds / 1000)),
            $retryAt,
        );
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Nation>
     */
    private function lockNations(World $world, array $ids): Collection
    {
        $uniqueIds = array_values(array_unique(array_map('intval', $ids)));
        sort($uniqueIds, SORT_NUMERIC);

        $nations = Nation::query()
            ->where('world_id', $world->id)
            ->whereIn('id', $uniqueIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($nations->count() !== count($uniqueIds)) {
            throw new MessageBoardValidationException('target_nation', '同じWorldの島を指定してください。');
        }

        return $nations;
    }

    private function retainTargetBoard(Nation $target): void
    {
        $deleteIds = IslandMessage::query()
            ->where('target_nation_id', $target->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->skip(MessageBoardContract::TARGET_RETENTION_LIMIT)
            ->pluck('id');
        if ($deleteIds->isNotEmpty()) {
            IslandMessage::query()->whereIn('id', $deleteIds)->delete();
        }
    }

    private function viewerNation(User $viewer, int $worldId): ?Nation
    {
        $membership = NationMembership::query()
            ->where('user_id', $viewer->id)
            ->where('world_id', $worldId)
            ->where('role', 'owner')
            ->with('nation')
            ->first();

        $nation = $membership?->nation;

        return $nation instanceof Nation && $nation->world_id === $worldId ? $nation : null;
    }

    /** @return array<string, mixed> */
    private function project(IslandMessage $message, Nation $board, ?Nation $viewerNation): array
    {
        $base = [
            'key' => $message->public_id,
            'created_at' => $message->created_at->toIso8601String(),
        ];
        if ($message->message_type === IslandMessage::TYPE_PUBLIC) {
            return [...$base, ...$this->projectPublic($message, $board)];
        }
        if ($message->message_type !== IslandMessage::TYPE_SECRET) {
            throw new RuntimeException('Stored island message type is invalid.');
        }

        $isOutgoing = $message->secret_sender_nation_id === $board->id
            && $message->target_nation_id !== $board->id;
        $authorized = $viewerNation !== null && in_array($viewerNation->id, [
            $message->secret_sender_nation_id,
            $message->target_nation_id,
        ], true);

        if (! $authorized) {
            return [
                ...$base,
                'kind' => 'secret_placeholder',
                'text' => MessageBoardContract::SECRET_PLACEHOLDER,
            ];
        }

        $counterpart = $isOutgoing ? $message->targetNation : $message->secretSenderNation;
        if (! $counterpart instanceof Nation) {
            throw new RuntimeException('Secret communication counterpart is missing.');
        }

        return [
            ...$base,
            'kind' => 'secret',
            'label' => '秘密通信',
            'direction' => $isOutgoing ? 'outgoing' : 'incoming',
            'body' => $message->body,
            'counterpart' => [
                'nation_number' => (int) $counterpart->nation_number,
                'name' => $counterpart->name,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function projectPublic(IslandMessage $message, Nation $board): array
    {
        if ($message->author_kind === IslandMessage::AUTHOR_VISITOR) {
            $visitorCode = $message->authorUser->visitor_code;
            if (! is_string($visitorCode) || $visitorCode === '') {
                throw new RuntimeException('Tourist message author has no persisted visitor code.');
            }

            return [
                'kind' => 'public',
                'body' => $message->body,
                'author' => [
                    'type' => 'visitor',
                    'label' => '観光客',
                    'display_name' => "観光客(ID:{$visitorCode})",
                    'visitor_code' => $visitorCode,
                ],
            ];
        }

        $authorNation = $message->authorNation;
        if (! $authorNation instanceof Nation) {
            throw new RuntimeException('Nation message author is missing.');
        }
        $isOwner = $authorNation->id === $board->id;

        return [
            'kind' => 'public',
            'body' => $message->body,
            'author' => [
                'type' => $isOwner ? 'owner' : 'other_nation',
                'label' => $isOwner ? '島主' : '他島',
                'nation' => [
                    'nation_number' => (int) $authorNation->nation_number,
                    'name' => $authorNation->name,
                ],
            ],
        ];
    }

    private function isReachable(Nation $nation): bool
    {
        return $nation->state !== 'sunken_archived';
    }

    private function assertWorldMutable(World $world): void
    {
        $this->rulesetGuard->assertMutable($world, $world->rulesetVersion()->firstOrFail());
    }
}

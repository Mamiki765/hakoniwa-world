<?php

namespace App\Application;

use App\Models\IslandMessage;
use App\Models\Nation;
use App\Models\User;
use App\Models\World;
use Illuminate\Support\Facades\DB;

class MessageBoardAuditRecorder
{
    public function secretSent(
        World $world,
        User $user,
        Nation $sender,
        Nation $target,
        IslandMessage $message,
        int $cost,
    ): void {
        $occurredAt = now();
        DB::table('audit_events')->insert([
            'actor_user_id' => $user->id,
            'world_id' => $world->id,
            'turn' => $world->current_turn,
            'nation_id' => $sender->id,
            'x' => null,
            'y' => null,
            'message' => null,
            'visibility' => 'private',
            'event_type' => 'message_board.secret_sent',
            'severity' => 'info',
            'subject_type' => IslandMessage::class,
            'subject_id' => $message->id,
            'metadata' => json_encode([
                'world_id' => $world->id,
                'sender_nation_id' => $sender->id,
                'target_nation_id' => $target->id,
                'cost_money' => $cost,
                'message_type' => IslandMessage::TYPE_SECRET,
                'message_record_id' => $message->id,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }
}

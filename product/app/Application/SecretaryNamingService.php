<?php

namespace App\Application;

use App\Models\Secretary;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class SecretaryNamingService
{
    public function name(User $user, string $name): Secretary
    {
        return DB::transaction(function () use ($user, $name): Secretary {
            $secretary = Secretary::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $secretary instanceof Secretary) {
                throw new DomainException('Secretaryは最初のNation登録成功時に作成されます。');
            }
            if ($secretary->name !== null || $secretary->named_at !== null) {
                throw new DomainException('Secretaryはすでに命名されています。');
            }
            $secretary->update(['name' => $name, 'named_at' => now()]);

            return $secretary->load('skills');
        });
    }

    public function rename(User $user, string $name): Secretary
    {
        return DB::transaction(function () use ($user, $name): Secretary {
            $secretary = Secretary::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $secretary instanceof Secretary || $secretary->name === null || $secretary->named_at === null) {
                throw new DomainException('既存の命名済みSecretaryだけが改名できます。');
            }
            if ($secretary->name === $name) {
                return $secretary->load('skills');
            }

            $oldName = $secretary->name;
            $secretary->update(['name' => $name]);
            $occurredAt = now();
            DB::table('audit_events')->insert([
                'actor_user_id' => $user->id,
                'event_type' => 'secretary.renamed',
                'severity' => 'info',
                'visibility' => 'private',
                'subject_type' => Secretary::class,
                'subject_id' => $secretary->id,
                'metadata' => json_encode([
                    'secretary_id' => $secretary->id,
                    'user_id' => $user->id,
                    'old_name' => $oldName,
                    'new_name' => $name,
                    'occurred_at' => $occurredAt->toIso8601String(),
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            return $secretary->load('skills');
        }, 3);
    }
}

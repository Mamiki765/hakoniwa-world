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
}

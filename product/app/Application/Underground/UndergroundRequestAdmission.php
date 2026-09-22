<?php

namespace App\Application\Underground;

use App\Models\Secretary;
use App\Models\UndergroundProfile;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A signed 24-hour admission, with no permanent request registry. */
final class UndergroundRequestAdmission
{
    public const FORMAT = 1;

    public const LIFETIME_SECONDS = 86_400;

    public const ATTRIBUTE = 'underground_request_admission';

    public const MESSAGE = '受付期限切れ、または古い画面からの操作です。再読込して操作し直してください。';

    public static function required(string $method, string $path): bool
    {
        return ! in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)
            && str_starts_with(ltrim($path, '/'), 'api/v1/me/underground/')
            && ! in_array(ltrim($path, '/'), [
                'api/v1/me/underground/requests',
                'api/v1/me/underground/equipment/vault/bulk-sell/preview',
            ], true);
    }

    /** @return array{request_id:string,token:string,expires_at:int,format:int} */
    public function issue(User $user, string $method, string $path): array
    {
        $secretary = Secretary::query()->where('user_id', $user->id)->first();
        if (! $secretary instanceof Secretary || $secretary->name === null) {
            throw new UndergroundRuntimeException('underground_secretary_missing', '名前のある秘書が必要です。');
        }
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
        $now = now()->getTimestamp();
        $identity = (string) Str::uuid();
        $claims = [
            'format' => self::FORMAT, 'profile_id' => $profile->id,
            'operation' => strtoupper($method).' /'.ltrim($path, '/'),
            'request_id' => $identity, 'issued_at' => $now, 'expires_at' => $now + self::LIFETIME_SECONDS,
        ];

        return ['request_id' => $identity, 'token' => Crypt::encryptString(json_encode($claims, JSON_THROW_ON_ERROR)),
            'expires_at' => $claims['expires_at'], 'format' => self::FORMAT];
    }

    public function validate(Request $request): void
    {
        try {
            $claims = json_decode(Crypt::decryptString($request->header('X-Underground-Receipt', '')), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw new UndergroundRuntimeException('underground_request_reload_required', self::MESSAGE);
        }
        $profileId = UndergroundProfile::query()->whereHas('secretary', fn ($q) => $q->where('user_id', $request->user()?->id))->value('id');
        if (! is_array($claims) || ($claims['format'] ?? null) !== self::FORMAT
            || ($claims['profile_id'] ?? null) !== $profileId
            || ($claims['operation'] ?? null) !== $request->method().' /'.$request->path()
            || ! is_string($claims['request_id'] ?? null) || ! Str::isUuid($claims['request_id'])
            || $claims['request_id'] !== $request->input('request_id', $request->header('X-Underground-Request-Id'))
            || ! is_int($claims['issued_at'] ?? null) || ! is_int($claims['expires_at'] ?? null)
            || $claims['expires_at'] - $claims['issued_at'] !== self::LIFETIME_SECONDS
            || $claims['issued_at'] > now()->getTimestamp()) {
            throw new UndergroundRuntimeException('underground_request_reload_required', self::MESSAGE);
        }
        $request->attributes->set(self::ATTRIBUTE, $claims);
        $this->assertTime($claims);
    }

    /** Called after the existing profile lock, before any settlement or asset write. */
    public function assertLockedProfile(UndergroundProfile $profile): void
    {
        $claims = request()->attributes->get(self::ATTRIBUTE);
        if (! is_array($claims)) {
            return; // Internal services/CLI are not HTTP admission endpoints.
        }
        if ($claims['profile_id'] !== $profile->id) {
            throw new UndergroundRuntimeException('underground_request_reload_required', self::MESSAGE);
        }
        $this->assertTime($claims);
    }

    /** @param array<string, mixed> $claims */
    private function assertTime(array $claims): void
    {
        if (now()->getTimestamp() < $claims['expires_at']) {
            return;
        }
        // An expired token can only replay an existing result. The second check
        // under the writer's profile lock closes the race against manual purge.
        foreach (['underground_battles', 'underground_skip_settlements', 'underground_skip_batches', 'underground_intro_requests'] as $table) {
            if (DB::table($table)->where('underground_profile_id', $claims['profile_id'])->where('request_id', $claims['request_id'])->exists()) {
                return;
            }
        }
        throw new UndergroundRuntimeException('underground_request_expired', self::MESSAGE);
    }
}

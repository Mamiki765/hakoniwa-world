<?php

namespace Tests\Concerns;

use App\Application\Underground\UndergroundProfileService;
use App\Application\Underground\UndergroundRequestAdmission;
use App\Models\Secretary;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** Existing gameplay scenarios supply a trusted server-issued admission fixture.
 * Admission contract tests disable this adapter and use the real issuance API.
 */
trait SendsUndergroundAdmission
{
    protected bool $withUndergroundAdmission = true;

    /** @var array<string, string> */
    private array $undergroundAdmissionFixtures = [];

    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        if ($this->withUndergroundAdmission && UndergroundRequestAdmission::required($method, $uri)
            && ! array_key_exists('X-Underground-Receipt', $headers) && auth()->check()) {
            $secretary = Secretary::query()->where('user_id', auth()->id())->first();
            if ($secretary instanceof Secretary && $secretary->name !== null) {
                $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
                $identity = $data['request_id'] ?? (string) Str::uuid();
                $headers['X-Underground-Request-Id'] = $identity;
                $operation = strtoupper($method).' /'.ltrim($uri, '/');
                $key = $profile->id.':'.$operation.':'.$identity;
                // Fixed UUIDs are fixture identities, never accepted by the public issuer.
                $headers['X-Underground-Receipt'] = $this->undergroundAdmissionFixtures[$key] ??= Crypt::encryptString(json_encode([
                    'format' => UndergroundRequestAdmission::FORMAT, 'profile_id' => $profile->id,
                    'operation' => $operation, 'request_id' => $identity,
                    'issued_at' => now()->getTimestamp(), 'expires_at' => now()->getTimestamp() + UndergroundRequestAdmission::LIFETIME_SECONDS,
                ], JSON_THROW_ON_ERROR));
            }
        }

        return parent::json($method, $uri, $data, $headers, $options);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $nation_id
 * @property int $ruleset_version_id
 * @property int $layer
 * @property int $slot_index
 * @property string $facility_key
 * @property-read Nation $nation
 * @property-read RulesetVersion $rulesetVersion
 */
final class NationUndergroundFacility extends Model
{
    protected $fillable = ['nation_id', 'ruleset_version_id', 'layer', 'slot_index', 'facility_key'];

    protected function casts(): array
    {
        return [
            'nation_id' => 'integer',
            'ruleset_version_id' => 'integer',
            'layer' => 'integer',
            'slot_index' => 'integer',
        ];
    }

    /** @return BelongsTo<Nation, $this> */
    public function nation(): BelongsTo
    {
        return $this->belongsTo(Nation::class);
    }

    /** @return BelongsTo<RulesetVersion, $this> */
    public function rulesetVersion(): BelongsTo
    {
        return $this->belongsTo(RulesetVersion::class);
    }
}

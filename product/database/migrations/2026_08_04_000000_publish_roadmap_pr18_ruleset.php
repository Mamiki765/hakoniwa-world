<?php

use App\Application\RulesetPublisher;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const TARGET_KEY = 'roadmap-pr18-v1';

    public function up(): void
    {
        $published = config('hakoniwa.published_rulesets');
        $settings = is_array($published) ? ($published[self::TARGET_KEY] ?? null) : null;
        if (! is_array($settings)) {
            throw new RuntimeException('The immutable roadmap-pr18-v1 ruleset snapshot is missing.');
        }

        // Pre-release Worlds on older rulesets deliberately remain historical and
        // reset-required. Publishing PR18 must not repoint Worlds or queue items.
        app(RulesetPublisher::class)->publish($settings);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The roadmap-pr18-v1 ruleset publication is forward-only; restore from an explicit backup instead.',
        );
    }
};

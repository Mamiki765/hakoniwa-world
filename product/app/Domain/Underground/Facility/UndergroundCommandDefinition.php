<?php

namespace App\Domain\Underground\Facility;

final readonly class UndergroundCommandDefinition
{
    public string $target_type;

    public string $execution_phase;

    public bool $requires_empty_facility;

    /** @var array<string, int> */
    public array $required_resources;

    /** @var array<string, mixed> */
    public array $metadata;

    /** @param array<string, int> $effect */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public int $cost_money,
        public string $action,
        public ?string $facility_key,
        public array $effect,
        public int $sort_order,
    ) {
        $this->target_type = 'underground_slot';
        $this->execution_phase = 'underground_facility';
        $this->requires_empty_facility = $this->action === 'build';
        $this->required_resources = [];
        $this->metadata = [
            'consumes_turn' => true,
            'parameters' => [],
            'quantity_semantics' => 'unused',
            'underground_action' => $this->action,
            'underground_facility_key' => $this->facility_key,
            'underground_facility_effect' => $this->effect,
        ];
    }
}

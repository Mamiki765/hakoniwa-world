<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = true;

    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE auction_listings
  ADD COLUMN original_secretary_item_instance_id bigint
SQL);

        DB::statement(<<<'SQL'
UPDATE auction_listings
SET original_secretary_item_instance_id = secretary_item_instance_id
WHERE product_type = 'item'
  AND seller_type = 'nation'
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE auction_listings
  DROP CONSTRAINT auction_listings_product_check,
  ADD CONSTRAINT auction_listings_product_check CHECK (
    (
      product_type = 'resource'
      AND resource_definition_id IS NOT NULL
      AND secretary_item_instance_id IS NULL
      AND original_secretary_item_instance_id IS NULL
      AND item_key IS NULL
      AND item_level IS NULL
      AND quantity IS NOT NULL
      AND quantity > 0
    )
    OR
    (
      product_type = 'item'
      AND resource_definition_id IS NULL
      AND quantity IS NULL
      AND item_key IS NOT NULL
      AND item_level IS NOT NULL
      AND item_level > 0
      AND (
        (
          seller_type = 'nation'
          AND original_secretary_item_instance_id IS NOT NULL
          AND (secretary_item_instance_id IS NULL OR secretary_item_instance_id = original_secretary_item_instance_id)
          AND (status <> 'active' OR secretary_item_instance_id IS NOT NULL)
        )
        OR
        (
          seller_type = 'hakoniwa_federation'
          AND secretary_item_instance_id IS NULL
          AND original_secretary_item_instance_id IS NULL
        )
      )
    )
  ),
  DROP CONSTRAINT auction_listings_secretary_item_instance_id_foreign,
  ADD CONSTRAINT auction_listings_secretary_item_instance_id_foreign
    FOREIGN KEY (secretary_item_instance_id)
    REFERENCES secretary_item_instances(id)
    ON DELETE SET NULL
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The 3.8.0 completed auction item history migration is forward-only; restore the verified pre-migration backup.',
        );
    }
};

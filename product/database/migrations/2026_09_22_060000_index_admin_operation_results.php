<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX audit_events_admin_request_unique ON audit_events ((metadata->>'request_id')) WHERE event_type = 'admin.operation_completed'");
    }

    public function down(): void
    {
        throw new RuntimeException('Admin operation result identities are forward-only.');
    }
};

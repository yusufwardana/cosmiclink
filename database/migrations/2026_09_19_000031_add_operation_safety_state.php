<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_operation_logs', function (Blueprint $table) {
            $table->string('outcome')->nullable()->after('status');
            $table->string('safety_scope')->nullable()->after('outcome');
            $table->timestamp('reserved_at')->nullable()->after('started_at');
            $table->index(['tenant_id', 'safety_scope', 'status'], 'network_operation_safety_state');
            $table->unique(['tenant_id', 'idempotency_key'], 'network_operation_idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('network_operation_logs', function (Blueprint $table) {
            $table->dropUnique('network_operation_idempotency_key');
            $table->dropIndex('network_operation_safety_state');
            $table->dropColumn(['outcome', 'safety_scope', 'reserved_at']);
        });
    }
};

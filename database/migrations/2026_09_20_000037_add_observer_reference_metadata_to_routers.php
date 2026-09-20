<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->string('observer_agent_ref')->nullable()->index();
            $table->string('observer_installation_id')->nullable();
            $table->string('observer_credential_ref')->nullable();
            $table->string('observer_credential_purpose')->nullable();
            $table->unsignedInteger('observer_credential_version')->nullable();
            $table->string('observer_credential_status')->nullable();
            $table->string('observer_migration_state')->default('LEGACY');
            $table->timestamp('observer_reference_bound_at')->nullable();
            $table->timestamp('observer_reference_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn([
                'observer_agent_ref', 'observer_installation_id', 'observer_credential_ref',
                'observer_credential_purpose', 'observer_credential_version', 'observer_credential_status',
                'observer_migration_state', 'observer_reference_bound_at', 'observer_reference_synced_at',
            ]);
        });
    }
};

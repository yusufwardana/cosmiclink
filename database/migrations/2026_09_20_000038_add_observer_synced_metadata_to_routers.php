<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->string('observer_synced_agent_ref')->nullable();
            $table->string('observer_synced_installation_id')->nullable();
            $table->string('observer_synced_credential_ref')->nullable();
            $table->string('observer_synced_credential_purpose')->nullable();
            $table->unsignedInteger('observer_synced_credential_version')->nullable();
            $table->string('observer_synced_credential_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn([
                'observer_synced_agent_ref', 'observer_synced_installation_id', 'observer_synced_credential_ref',
                'observer_synced_credential_purpose', 'observer_synced_credential_version', 'observer_synced_credential_status',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_agent_jobs', function (Blueprint $table) {
            $table->foreignId('network_account_id')->nullable()->after('router_id')->constrained()->restrictOnDelete();
            $table->foreignId('network_operation_log_id')->nullable()->after('network_account_id')->constrained()->restrictOnDelete();
            $table->string('protocol_version', 64)->nullable();
            $table->string('operation', 64)->nullable();
            $table->string('execution_id', 191)->nullable()->index();
            $table->string('idempotency_key', 191)->nullable();
            $table->string('request_digest', 64)->nullable();
            $table->string('reservation_ref', 191)->nullable();
            $table->string('installation_id', 191)->nullable();
            $table->string('credential_ref', 191)->nullable();
            $table->string('credential_purpose', 16)->nullable();
            $table->unsignedInteger('credential_version')->nullable();
            $table->string('observer_credential_ref', 191)->nullable();
            $table->string('observer_credential_purpose', 16)->nullable();
            $table->unsignedInteger('observer_credential_version')->nullable();
            $table->string('target_identity_ref', 191)->nullable();
            $table->string('account_ref', 191)->nullable();
            $table->string('terminal_result_digest', 64)->nullable();
            $table->unique('network_operation_log_id');
        });
    }

    public function down(): void
    {
        Schema::table('network_agent_jobs', function (Blueprint $table) {
            $table->dropUnique(['network_operation_log_id']);
            $table->dropIndex(['execution_id']);
            $table->dropConstrainedForeignId('network_operation_log_id');
            $table->dropConstrainedForeignId('network_account_id');
            $table->dropColumn([
                'protocol_version', 'operation', 'execution_id', 'idempotency_key', 'request_digest',
                'reservation_ref', 'installation_id', 'credential_ref', 'credential_purpose',
                'credential_version', 'observer_credential_ref', 'observer_credential_purpose',
                'observer_credential_version', 'target_identity_ref', 'account_ref', 'terminal_result_digest',
            ]);
        });
    }
};

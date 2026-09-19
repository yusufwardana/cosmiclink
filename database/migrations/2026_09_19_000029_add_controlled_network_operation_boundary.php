<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_accounts', function (Blueprint $table) {
            $table->string('management_state')->default('ADOPTED')->after('status');
            $table->timestamp('managed_at')->nullable()->after('management_state');
            $table->foreignId('managed_by_user_id')->nullable()->after('managed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable()->after('managed_by_user_id');
            $table->foreignId('revoked_by_user_id')->nullable()->after('revoked_at')->constrained('users')->nullOnDelete();
            $table->json('management_scope')->nullable()->after('revoked_by_user_id');
            $table->index(['tenant_id', 'management_state']);
        });

        Schema::table('network_operation_logs', function (Blueprint $table) {
            $table->foreignId('network_account_id')->nullable()->after('customer_connection_id')->constrained()->nullOnDelete();
            $table->string('idempotency_key')->nullable()->after('operation');
            $table->string('request_digest', 64)->nullable()->after('idempotency_key');
            $table->string('provider')->nullable()->after('request_digest');
            $table->string('execution_mode')->nullable()->after('provider');
            $table->json('preflight_evidence')->nullable()->after('result_payload');
            $table->json('postflight_evidence')->nullable()->after('preflight_evidence');
            $table->string('failure_code')->nullable()->after('error_message');
            $table->foreignId('resolved_by_user_id')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->after('resolved_by_user_id');
            $table->text('resolution_note')->nullable()->after('resolved_at');
            $table->index(['tenant_id', 'network_account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('network_operation_logs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'network_account_id', 'created_at']);
            $table->dropForeign(['resolved_by_user_id']);
            $table->dropForeign(['network_account_id']);
            $table->dropColumn([
                'network_account_id',
                'idempotency_key',
                'request_digest',
                'provider',
                'execution_mode',
                'preflight_evidence',
                'postflight_evidence',
                'failure_code',
                'resolved_by_user_id',
                'resolved_at',
                'resolution_note',
            ]);
        });

        Schema::table('network_accounts', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'management_state']);
            $table->dropForeign(['managed_by_user_id']);
            $table->dropForeign(['revoked_by_user_id']);
            $table->dropColumn([
                'management_state',
                'managed_at',
                'managed_by_user_id',
                'revoked_at',
                'revoked_by_user_id',
                'management_scope',
            ]);
        });
    }
};

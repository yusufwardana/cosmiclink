<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6i Task 2.8 - audit record for every managed target action decision.
 *
 * One row is written per attempted controlled lifecycle action - including
 * denials - so the audit trail explains *why* nothing happened. The row carries
 * references and digests only: the confirmation phrase is stored as a salted
 * sha256 digest and the RouterOS target as an identity fingerprint, so no
 * plaintext confirmation token, challenge, or credential can ever be read back
 * from this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_target_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('network_operation_log_id')->nullable()->constrained('network_operation_logs')->nullOnDelete();
            $table->foreignId('preflight_evidence_id')->nullable()->constrained('managed_target_preflight_evidence')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operation', 64);
            $table->string('status', 24)->default('DENIED');
            $table->string('reason_code', 64)->nullable();
            $table->string('failed_precondition', 16)->nullable();
            $table->string('target_identity_type', 20)->nullable();
            $table->string('target_identity_ref', 128)->nullable();
            $table->char('target_identity_fingerprint', 64)->nullable();
            $table->char('confirmation_digest', 64)->nullable();
            $table->string('idempotency_key')->nullable();
            $table->string('request_digest', 64)->nullable();
            $table->timestamp('decided_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'network_account_id', 'created_at'], 'managed_target_actions_account_idx');
            $table->index(['tenant_id', 'operation', 'status', 'decided_at'], 'managed_target_actions_lookup_idx');
            $table->index(['idempotency_key'], 'managed_target_actions_idempotency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_target_actions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6i Task 2.8 - per-account RouterOS target preflight evidence store.
 *
 * A row records what the managed lifecycle observed about one target before an
 * operation could be attempted: the target identity the evidence was taken
 * against, the account identity it was validated with, the bounded outcome, and
 * a short validity window. Evidence rows are inputs to the ordered
 * precondition evaluation - they never authorise an action by themselves, and
 * they only ever persist safe projections (references and digests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_target_preflight_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discovery_snapshot_id')->nullable()->constrained('network_discovery_snapshots')->nullOnDelete();
            $table->foreignId('network_reconciliation_evidence_id')->nullable()->constrained('network_reconciliation_evidence')->nullOnDelete();
            $table->string('operation', 64);
            $table->string('outcome', 32);
            $table->string('reason_code', 64)->nullable();
            $table->string('target_identity_type', 20)->nullable();
            $table->string('target_identity_ref', 128)->nullable();
            $table->char('target_identity_fingerprint', 64)->nullable();
            $table->char('account_identity_fingerprint', 64)->nullable();
            $table->string('routeros_version', 32)->nullable();
            $table->unsignedInteger('active_session_count')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('observed_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'network_account_id', 'operation', 'observed_at'], 'managed_target_preflight_lookup_idx');
            $table->index(['tenant_id', 'expires_at'], 'managed_target_preflight_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_target_preflight_evidence');
    }
};

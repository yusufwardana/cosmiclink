<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6i Task 2.8 - account management transition audit.
 *
 * The managed lifecycle changes an account between OBSERVED, ADOPTED, MANAGED,
 * UNMANAGED, and REVOKED. Every such change needs an immutable, ordered history
 * so an operator can later prove which account was managed, by whom, under which
 * scope, and against which RouterOS target identity - including changes that were
 * structurally recorded but not part of the documented transition graph
 * (`policy_allowed` = false), which Task 3's lifecycle must then refuse to repeat.
 *
 * Scope is stored as a digest only: the granted operation set is comparable
 * without this table becoming a second copy of the scope definition, and there
 * are no credential, secret, or plaintext confirmation columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_account_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('from_state', 16);
            $table->string('to_state', 16);
            $table->boolean('policy_allowed')->default(false);
            $table->string('reason_code', 64)->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_identity_type', 20)->nullable();
            $table->string('target_identity_ref', 128)->nullable();
            $table->char('target_identity_fingerprint', 64)->nullable();
            $table->string('routeros_version', 32)->nullable();
            $table->char('scope_digest', 64)->nullable();
            $table->string('approval_reference', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['network_account_id', 'sequence'], 'network_account_transitions_sequence_uq');
            $table->index(['tenant_id', 'network_account_id', 'occurred_at'], 'network_account_transitions_account_idx');
            $table->index(['tenant_id', 'policy_allowed', 'occurred_at'], 'network_account_transitions_policy_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_account_transitions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6i Task 2.8 - observed RouterOS session context for managed accounts.
 *
 * Rows are projections of read-only discovery evidence (`active_sessions`),
 * never a live router handle: each row records how a session was addressed at a
 * point in time, and is superseded when a later enumeration no longer reports
 * it. Session names are the PPP session name (which mirrors the account
 * username); caller-id and address are diagnostics only. No password, egress,
 * or secrets column exists or may be added here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_target_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discovery_snapshot_id')->nullable()->constrained('network_discovery_snapshots')->nullOnDelete();
            $table->string('session_ref', 128);
            $table->string('session_ref_type', 20);
            $table->string('session_ref_fingerprint', 64)->nullable();
            $table->string('session_name', 191)->nullable();
            $table->string('service', 64)->nullable();
            $table->string('address', 64)->nullable();
            $table->string('caller_id', 191)->nullable();
            $table->unsignedInteger('uptime_seconds')->nullable();
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamp('observed_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'network_account_id', 'status', 'observed_at'], 'managed_target_sessions_account_status_idx');
            $table->index(['tenant_id', 'router_id', 'session_ref'], 'managed_target_sessions_router_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_target_sessions');
    }
};

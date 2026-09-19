<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6i Task 2.8 - per-account RouterOS target identity context.
 *
 * These columns are a safe projection of the adopted discovery evidence: the
 * RouterOS reference form used to address the target, its deterministic
 * fingerprint, the RouterOS version observed with it, and the snapshot/resource
 * rows that produced it. No credential, challenge, or plaintext confirmation is
 * ever stored here. Populated at adoption, cleared at unadopt, and only ever
 * advisory input to the default-deny controlled gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_accounts', function (Blueprint $table) {
            $table->string('router_identity_ref', 128)->nullable()->after('management_scope');
            $table->string('router_identity_type', 20)->nullable()->after('router_identity_ref');
            $table->char('router_identity_fingerprint', 64)->nullable()->after('router_identity_type');
            $table->string('routeros_version', 32)->nullable()->after('router_identity_fingerprint');
            $table->foreignId('router_identity_snapshot_id')->nullable()->after('routeros_version')->constrained('network_discovery_snapshots')->nullOnDelete();
            $table->foreignId('router_identity_resource_id')->nullable()->after('router_identity_snapshot_id')->constrained('discovered_network_resources')->nullOnDelete();
            $table->timestamp('router_identity_evidence_at')->nullable()->after('router_identity_resource_id');
            $table->index(['tenant_id', 'router_id', 'router_identity_fingerprint'], 'network_accounts_identity_fingerprint_idx');
        });
    }

    public function down(): void
    {
        Schema::table('network_accounts', function (Blueprint $table) {
            $table->dropIndex('network_accounts_identity_fingerprint_idx');
            $table->dropForeign(['router_identity_resource_id']);
            $table->dropForeign(['router_identity_snapshot_id']);
            $table->dropColumn([
                'router_identity_ref',
                'router_identity_type',
                'router_identity_fingerprint',
                'routeros_version',
                'router_identity_snapshot_id',
                'router_identity_resource_id',
                'router_identity_evidence_at',
            ]);
        });
    }
};

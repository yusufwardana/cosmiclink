<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_reconciliation_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('adopted_resource_id')->constrained('discovered_network_resources')->restrictOnDelete();
            $table->foreignId('compared_resource_id')->nullable()->constrained('discovered_network_resources')->nullOnDelete();
            $table->foreignId('discovery_snapshot_id')->nullable()->constrained('network_discovery_snapshots')->nullOnDelete();
            $table->foreignId('network_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome');
            $table->timestamp('discovered_at')->nullable();
            $table->timestamp('reconciled_at');
            $table->string('adopted_fingerprint', 64)->nullable();
            $table->string('compared_fingerprint', 64)->nullable();
            $table->string('relationship_fingerprint', 64);
            $table->timestamps();
            $table->index(['tenant_id', 'router_id', 'adopted_resource_id', 'reconciled_at'], 'reconciliation_evidence_resource_history');
            $table->index(['tenant_id', 'router_id', 'outcome', 'discovered_at'], 'reconciliation_evidence_outcome_freshness');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_reconciliation_evidence');
    }
};

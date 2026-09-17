<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_discovery_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider');
            $table->string('status');
            $table->timestamp('discovered_at')->nullable();
            $table->json('summary')->nullable();
            $table->json('snapshot')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'router_id', 'created_at']);
        });
        Schema::create('discovered_network_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discovery_snapshot_id')->nullable()->constrained('network_discovery_snapshots')->nullOnDelete();
            $table->foreignId('customer_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('network_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('resource_type');
            $table->string('external_ref');
            $table->string('name');
            $table->string('management_state')->default('DISCOVERED');
            $table->string('fingerprint', 64);
            $table->json('normalized_data');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'router_id', 'resource_type', 'fingerprint'], 'discovered_resource_fingerprint_unique');
            $table->index(['tenant_id', 'router_id', 'resource_type']);
        });
        Schema::create('network_discovery_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discovered_network_resource_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->json('details')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['tenant_id', 'router_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_discovery_audits');
        Schema::dropIfExists('discovered_network_resources');
        Schema::dropIfExists('network_discovery_snapshots');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source');
            $table->string('network_identity');
            $table->ipAddress('ip_address')->nullable();
            $table->string('mac_address', 32)->nullable();
            $table->string('interface')->nullable();
            $table->string('server')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'router_id', 'source', 'network_identity', 'ip_address', 'mac_address'], 'device_observation_identity_unique');
            $table->index(['tenant_id', 'customer_connection_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_observations');
    }
};
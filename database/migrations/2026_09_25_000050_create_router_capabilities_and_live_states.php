<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_capability_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->string('routeros_version')->nullable();
            $table->unsignedSmallInteger('routeros_major')->nullable();
            $table->string('architecture')->nullable();
            $table->string('board')->nullable();
            $table->json('capabilities');
            $table->string('source')->default('monitoring');
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->index(['tenant_id', 'router_id', 'verified_at'], 'router_capability_snapshots_lookup');
        });

        Schema::create('live_connection_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_connection_id')->constrained()->cascadeOnDelete();
            $table->string('state');
            $table->string('signal_source');
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->unsignedBigInteger('upload_bps')->nullable();
            $table->unsignedBigInteger('download_bps')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedTinyInteger('failure_streak')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('observed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'customer_connection_id'], 'live_connection_states_connection_unique');
            $table->index(['tenant_id', 'router_id', 'state'], 'live_connection_states_router_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_connection_states');
        Schema::dropIfExists('router_capability_snapshots');
    }
};

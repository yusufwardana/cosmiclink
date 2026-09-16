<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('health_state');
            $table->boolean('reachable')->nullable();
            $table->boolean('online')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedTinyInteger('packet_loss_percent')->nullable();
            $table->timestamp('observed_at');
            $table->string('provider');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'subject_type', 'subject_id', 'observed_at']);
            $table->index(['tenant_id', 'health_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_observations');
    }
};

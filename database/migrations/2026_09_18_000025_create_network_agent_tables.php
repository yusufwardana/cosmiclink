<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('identifier')->unique();
            $table->string('name');
            $table->string('token_hash');
            $table->string('version')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'last_seen_at']);
        });

        Schema::create('network_agent_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('job_type');
            $table->string('status')->default('PENDING');
            $table->unsignedInteger('attempt')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('result_meta')->nullable();
            $table->string('error_code')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'network_agent_id', 'status', 'id']);
            $table->index(['tenant_id', 'router_id', 'job_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_agent_jobs');
        Schema::dropIfExists('network_agents');
    }
};

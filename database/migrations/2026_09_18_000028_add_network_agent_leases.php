<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_agent_jobs', function (Blueprint $table) {
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('last_progress_at')->nullable();
            $table->uuid('fence')->nullable();
            $table->boolean('lease_aware')->default(false);
        });
        Schema::create('network_agent_job_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_agent_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt');
            $table->string('status', 16);
            $table->timestamp('claimed_at');
            $table->timestamp('lease_expires_at');
            $table->timestamp('last_progress_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->unique(['network_agent_job_id', 'attempt']);
            $table->index(['tenant_id', 'network_agent_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_agent_job_attempts');
        Schema::table('network_agent_jobs', fn (Blueprint $table) => $table->dropColumn(['lease_expires_at', 'last_progress_at', 'fence', 'lease_aware']));
    }
};

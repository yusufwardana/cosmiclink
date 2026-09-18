<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_agents', function (Blueprint $table) {
            $table->string('observed_health', 16)->nullable();
        });
        Schema::create('network_agent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_agent_job_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt')->nullable();
            $table->string('type', 32);
            $table->string('code', 64)->nullable();
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'network_agent_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_agent_events');
        Schema::table('network_agents', fn (Blueprint $table) => $table->dropColumn('observed_health'));
    }
};

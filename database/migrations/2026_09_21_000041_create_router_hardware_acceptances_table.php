<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_hardware_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->string('agent_ref', 191);
            $table->string('installation_id', 191);
            $table->string('target', 64);
            $table->string('routeros_version', 64);
            $table->string('observed_identity', 191)->nullable();
            $table->string('architecture', 64)->nullable();
            $table->unsignedBigInteger('discovery_snapshot_id')->nullable();
            $table->foreignId('accepted_by_user_id')->constrained('users');
            $table->string('confirmation_digest', 64);
            $table->datetime('accepted_at');
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users');
            $table->datetime('revoked_at')->nullable();
            $table->index(['router_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_hardware_acceptances');
    }
};

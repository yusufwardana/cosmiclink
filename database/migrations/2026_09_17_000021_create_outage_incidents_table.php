<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outage_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('detected');
            $table->timestamp('detected_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('correlation_count')->default(0);
            $table->unsignedInteger('evidence_window_minutes')->default(10);
            $table->timestamps();
            $table->index(['tenant_id', 'router_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outage_incidents');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outage_affected_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outage_incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_connection_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['outage_incident_id', 'customer_connection_id']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outage_affected_connections');
    }
};

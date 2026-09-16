<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_automation_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_connection_id')->constrained()->restrictOnDelete();
            $table->string('action');
            $table->string('status');
            $table->timestamp('attempted_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'action', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_automation_attempts');
    }
};

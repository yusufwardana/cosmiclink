<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->foreignId('outage_incident_id')->nullable()->after('invoice_id')->constrained()->nullOnDelete();
            $table->index(['outage_incident_id', 'template', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropIndex(['outage_incident_id', 'template', 'customer_id']);
            $table->dropConstrainedForeignId('outage_incident_id');
        });
    }
};

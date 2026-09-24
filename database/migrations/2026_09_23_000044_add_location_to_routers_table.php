<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('last_seen_at');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->timestamp('location_updated_at')->nullable()->after('longitude');
            $table->index(['tenant_id', 'latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'latitude', 'longitude']);
            $table->dropColumn(['latitude', 'longitude', 'location_updated_at']);
        });
    }
};
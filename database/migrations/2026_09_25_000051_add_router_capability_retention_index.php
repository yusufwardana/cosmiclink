<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('router_capability_snapshots', function (Blueprint $table) {
            $table->index('verified_at', 'router_capability_snapshots_retention');
        });
    }

    public function down(): void
    {
        Schema::table('router_capability_snapshots', function (Blueprint $table) {
            $table->dropIndex('router_capability_snapshots_retention');
        });
    }
};

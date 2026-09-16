<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', fn (Blueprint $table) => $table->string('monitoring_state')->default('online')->after('last_seen_at'));
        Schema::table('customer_connections', fn (Blueprint $table) => $table->string('monitoring_state')->default('online')->after('metadata'));
    }

    public function down(): void
    {
        Schema::table('routers', fn (Blueprint $table) => $table->dropColumn('monitoring_state'));
        Schema::table('customer_connections', fn (Blueprint $table) => $table->dropColumn('monitoring_state'));
    }
};

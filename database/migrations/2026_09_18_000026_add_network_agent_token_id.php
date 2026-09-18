<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_agents', function (Blueprint $table) {
            $table->string('token_id', 32)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('network_agents', function (Blueprint $table) {
            $table->dropColumn('token_id');
        });
    }
};

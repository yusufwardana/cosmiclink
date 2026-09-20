<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_operation_logs', function (Blueprint $table) {
            $table->string('execution_id', 191)->nullable()->after('request_digest')->index();
        });
    }

    public function down(): void
    {
        Schema::table('network_operation_logs', function (Blueprint $table) {
            $table->dropIndex(['execution_id']);
            $table->dropColumn('execution_id');
        });
    }
};

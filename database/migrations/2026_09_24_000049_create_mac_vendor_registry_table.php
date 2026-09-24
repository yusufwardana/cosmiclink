<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mac_vendor_registry', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 12);
            $table->unsignedSmallInteger('prefix_length');
            $table->string('vendor');
            $table->string('source', 100);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['prefix', 'prefix_length', 'source']);
            $table->index(['prefix_length', 'prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mac_vendor_registry');
    }
};
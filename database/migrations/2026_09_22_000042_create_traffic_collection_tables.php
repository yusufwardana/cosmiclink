<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->timestamp('collected_at');
            $table->string('provider');
            $table->json('datasets');
            $table->boolean('enrichment_collected')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'router_id', 'collected_at'], 'traffic_collections_router_time_unique');
            $table->index(['tenant_id', 'router_id', 'collected_at']);
        });

        Schema::create('traffic_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('traffic_collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->string('source_key');
            $table->string('subject_key')->nullable();
            $table->unsignedBigInteger('upload_bytes')->nullable();
            $table->unsignedBigInteger('download_bytes')->nullable();
            $table->unsignedBigInteger('upload_delta_bytes')->nullable();
            $table->unsignedBigInteger('download_delta_bytes')->nullable();
            $table->string('delta_status');
            $table->timestamp('observed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['traffic_collection_id', 'source_type', 'source_key'], 'traffic_samples_collection_source_unique');
            $table->index(['tenant_id', 'router_id', 'source_type', 'source_key', 'observed_at'], 'traffic_samples_history_index');
        });

        Schema::create('traffic_buckets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->string('source_type');
            $table->string('subject_key');
            $table->timestamp('bucket_started_at');
            $table->unsignedBigInteger('upload_bytes')->default(0);
            $table->unsignedBigInteger('download_bytes')->default(0);
            $table->unsignedInteger('sample_count')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'router_id', 'source_type', 'subject_key', 'bucket_started_at'], 'traffic_buckets_subject_time_unique');
            $table->index(['tenant_id', 'router_id', 'bucket_started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_buckets');
        Schema::dropIfExists('traffic_samples');
        Schema::dropIfExists('traffic_collections');
    }
};

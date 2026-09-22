<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('traffic_buckets', function (Blueprint $table) {
            $table->json('metadata')->nullable();
            $table->boolean('subscriber_authoritative')->default(false);
            $table->index(
                ['tenant_id', 'router_id', 'source_type', 'subscriber_authoritative', 'bucket_started_at'],
                'traffic_buckets_analytics_scope_index'
            );
            $table->index(
                ['tenant_id', 'source_type', 'subject_key', 'bucket_started_at'],
                'traffic_buckets_subject_history_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('traffic_buckets', function (Blueprint $table) {
            $table->dropIndex('traffic_buckets_analytics_scope_index');
            $table->dropIndex('traffic_buckets_subject_history_index');
            $table->dropColumn(['metadata', 'subscriber_authoritative']);
        });
    }
};

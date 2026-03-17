<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop old unique constraint
        Schema::table('attribution_results', function (Blueprint $table) {
            $table->dropUnique('ar_unique_attribution');
        });

        // Add campaign columns
        Schema::table('attribution_results', function (Blueprint $table) {
            $table->string('campaign_type', 30)->default('')->after('effort_id');
            $table->unsignedBigInteger('campaign_id')->default(0)->after('campaign_type');
        });

        // Truncate stale data (rebuilt on next pipeline run)
        DB::table('attribution_results')->truncate();
        DB::table('summary_attribution_daily')->truncate();
        DB::table('summary_attribution_by_effort')->truncate();

        // Remove defaults now that table is empty
        Schema::table('attribution_results', function (Blueprint $table) {
            $table->string('campaign_type', 30)->default(null)->change();
            $table->unsignedBigInteger('campaign_id')->default(null)->change();
        });

        // New unique constraint including campaign columns
        Schema::table('attribution_results', function (Blueprint $table) {
            $table->unique(
                ['conversion_type', 'conversion_id', 'effort_id', 'campaign_type', 'campaign_id', 'model'],
                'ar_unique_attribution'
            );
            $table->index(['campaign_type', 'campaign_id']);
        });

        // Create summary_attribution_by_campaign table
        Schema::create('summary_attribution_by_campaign', function (Blueprint $table) {
            $table->unsignedBigInteger('workspace_id');
            $table->string('campaign_type', 30);
            $table->unsignedBigInteger('campaign_id');
            $table->date('summary_date');
            $table->string('model', 30);
            $table->unsignedInteger('attributed_conversions')->default(0);
            $table->decimal('attributed_revenue', 14, 2)->default(0);
            $table->decimal('total_weight', 10, 4)->default(0);
            $table->timestamp('summarized_at');

            $table->primary(
                ['workspace_id', 'campaign_type', 'campaign_id', 'summary_date', 'model'],
                'sabc_primary'
            );
            $table->index(['campaign_type', 'campaign_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('summary_attribution_by_campaign');

        Schema::table('attribution_results', function (Blueprint $table) {
            $table->dropUnique('ar_unique_attribution');
            $table->dropIndex(['campaign_type', 'campaign_id']);
        });

        Schema::table('attribution_results', function (Blueprint $table) {
            $table->dropColumn(['campaign_type', 'campaign_id']);
        });

        Schema::table('attribution_results', function (Blueprint $table) {
            $table->unique(
                ['conversion_type', 'conversion_id', 'effort_id', 'model'],
                'ar_unique_attribution'
            );
        });
    }
};

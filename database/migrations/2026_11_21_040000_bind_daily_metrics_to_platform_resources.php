<?php

use App\Models\Campaign;
use App\Services\Campaigns\CampaignResourceInventory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_ads_performance_data', function (Blueprint $table) {
            $table->string('google_campaign_id')->nullable();
            $table->foreignId('strategy_id')->nullable()->constrained()->nullOnDelete();
            $table->unique(['campaign_id', 'google_campaign_id', 'date'], 'google_resource_daily_unique');
        });
        Schema::table('facebook_ads_performance_data', function (Blueprint $table) {
            $table->foreignId('strategy_id')->nullable()->constrained()->nullOnDelete();
        });

        // The old Google importer queried only this exact parent resource. A duplicate day
        // or missing account history cannot be attributed safely during a migration.
        DB::table('campaigns')->whereNotNull('google_ads_campaign_id')->orderBy('id')->chunkById(200, function ($campaigns) {
            foreach ($campaigns as $campaign) {
                $name = $campaign->google_ads_campaign_id;
                if (! preg_match('~^customers/(\d+)/campaigns/(\d+)$~D', $name, $parts)) {
                    continue;
                }
                $uniqueDays = DB::table('google_ads_performance_data')->where('campaign_id', $campaign->id)
                    ->groupBy('date')->havingRaw('count(*) = 1')->pluck('date');
                DB::table('google_ads_performance_data')->where('campaign_id', $campaign->id)
                    ->whereIn('date', $uniqueDays)->update(['google_campaign_id' => $parts[2]]);
            }
        });

        Campaign::with(['customer', 'strategies'])->withDeployedPlatforms()->chunkById(200, function ($campaigns) {
            foreach ($campaigns as $campaign) {
                foreach (CampaignResourceInventory::entries($campaign) as $entry) {
                    if ($entry['strategy_id'] === null) {
                        continue;
                    }
                    DB::table($entry['platform'].'_ads_performance_data')->where('campaign_id', $campaign->id)
                        ->where($entry['platform'].'_campaign_id', $entry['resource_id'])->whereNull('strategy_id')
                        ->update(['strategy_id' => $entry['strategy_id']]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('google_ads_performance_data', function (Blueprint $table) {
            $table->dropUnique('google_resource_daily_unique');
            $table->dropConstrainedForeignId('strategy_id');
            $table->dropColumn('google_campaign_id');
        });
        Schema::table('facebook_ads_performance_data', fn (Blueprint $table) => $table->dropConstrainedForeignId('strategy_id'));
    }
};

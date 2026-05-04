<?php

namespace App\Console\Commands;

use App\Models\MccAccount;
use App\Models\Setting;
use Google\Ads\GoogleAds\Lib\OAuth2TokenBuilder;
use Google\Ads\GoogleAds\Lib\V22\GoogleAdsClientBuilder;
use Google\Ads\GoogleAds\V22\Enums\ConversionActionCategoryEnum\ConversionActionCategory;
use Google\Ads\GoogleAds\V22\Enums\ConversionActionStatusEnum\ConversionActionStatus;
use Google\Ads\GoogleAds\V22\Enums\ConversionActionTypeEnum\ConversionActionType;
use Google\Ads\GoogleAds\V22\Enums\TrackingCodeTypeEnum\TrackingCodeType;
use Google\Ads\GoogleAds\V22\Resources\ConversionAction;
use Google\Ads\GoogleAds\V22\Resources\ConversionAction\ValueSettings;
use Google\Ads\GoogleAds\V22\Services\ConversionActionOperation;
use Google\Ads\GoogleAds\V22\Services\MutateConversionActionsRequest;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest;
use Google\Protobuf\FieldMask;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;

/**
 * Creates Google Ads conversion actions for sitetospend.com's own ad account
 * and stores the resulting labels in settings so they are served to the frontend
 * automatically without any manual steps.
 *
 * Run once after deployment, or with --force to re-provision.
 *
 * Usage:
 *   php artisan conversions:provision
 *   php artisan conversions:provision --force
 *   php artisan conversions:provision --archive="Spectra — sitetospend Conversion"
 */
class ProvisionConversionActions extends Command
{
    protected $signature   = 'conversions:provision
                              {--force : Re-create already provisioned actions}
                              {--archive= : Archive (remove) a conversion action by exact name}';
    protected $description = 'Provision Google Ads conversion actions for sitetospend.com and store labels in settings.';

    private array $actions = [
        'signup' => [
            'name'     => 'Spectra — Signup',
            'type'     => ConversionActionType::WEBPAGE,
            'category' => ConversionActionCategory::SIGNUP,
            'value'    => 99.0,
        ],
        'pricing_visit' => [
            'name'     => 'Spectra — Pricing Visit',
            'type'     => ConversionActionType::WEBPAGE,
            'category' => ConversionActionCategory::SIGNUP,
            'value'    => 5.0,
        ],
        'sandbox_launched' => [
            'name'     => 'Spectra — Sandbox Launched',
            'type'     => ConversionActionType::WEBPAGE,
            'category' => ConversionActionCategory::SIGNUP,
            'value'    => 35.0,
        ],
        'campaign_live' => [
            'name'     => 'Spectra — Campaign Live',
            'type'     => ConversionActionType::UPLOAD_CLICKS,
            'category' => ConversionActionCategory::SIGNUP,
            'value'    => 80.0,
        ],
        'seven_day_return' => [
            'name'     => 'Spectra — 7-Day Return',
            'type'     => ConversionActionType::UPLOAD_CLICKS,
            'category' => ConversionActionCategory::SIGNUP,
            'value'    => 50.0,
        ],
    ];

    public function handle(): int
    {
        $customerId = config('conversions.google_ads_customer_id');

        $client = $this->buildClient();
        if (!$client) {
            return self::FAILURE;
        }

        // Archive mode: remove an orphaned conversion action by name
        if ($archiveName = $this->option('archive')) {
            return $this->archiveByName($client, $customerId, $archiveName);
        }

        $this->info("Provisioning conversion actions for Google Ads account {$customerId}...");

        foreach ($this->actions as $event => $def) {
            $settingKey = "conversion_resource_name.{$event}";

            if (Setting::get($settingKey) && !$this->option('force')) {
                $this->line("  <comment>skipped</comment>  {$event} — already provisioned");
                continue;
            }

            $resourceName = $this->createOrFind($client, $customerId, $def);
            if (!$resourceName) {
                $this->error("  failed    {$event} — could not create conversion action");
                continue;
            }

            Setting::set($settingKey, $resourceName);

            if ($def['type'] === ConversionActionType::WEBPAGE) {
                $label = $this->extractLabel($client, $customerId, $resourceName);
                if ($label) {
                    Setting::set("conversion_label.{$event}", $label);
                    $this->line("  <info>done</info>      {$event} → <comment>{$label}</comment>");
                } else {
                    $this->warn("  done      {$event} → label extraction failed — tag snippet may not be ready yet, retry in a minute");
                }
            } else {
                $this->line("  <info>done</info>      {$event} → server-side only ({$resourceName})");
            }
        }

        $this->newLine();
        $this->info('Labels stored in settings. They will be served to the frontend on the next request.');

        return self::SUCCESS;
    }

    private function createOrFind($client, string $customerId, array $def): ?string
    {
        // Check if an action with this name already exists
        try {
            $response = $client->getGoogleAdsServiceClient()->search(
                new SearchGoogleAdsRequest([
                    'customer_id' => $customerId,
                    'query'       => "SELECT conversion_action.resource_name "
                        . "FROM conversion_action "
                        . "WHERE conversion_action.name = '" . addslashes($def['name']) . "' "
                        . "AND conversion_action.status = 'ENABLED' LIMIT 1",
                ])
            );
            foreach ($response->iterateAllElements() as $row) {
                $existing = $row->getConversionAction()->getResourceName();
                $this->line("  <comment>found</comment>     {$def['name']} — using existing action");
                return $existing;
            }
        } catch (\Exception $e) {
            // Fall through to create
        }

        try {
            $action = new ConversionAction([
                'name'                               => $def['name'],
                'type'                               => $def['type'],
                'category'                           => $def['category'],
                'status'                             => ConversionActionStatus::ENABLED,
                'view_through_lookback_window_days'  => 1,
                'click_through_lookback_window_days' => 30,
                'value_settings'                     => new ValueSettings([
                    'default_value'            => $def['value'],
                    'default_currency_code'    => 'USD',
                    'always_use_default_value' => true,
                ]),
            ]);

            $op = new ConversionActionOperation();
            $op->setCreate($action);

            $response = $client->getConversionActionServiceClient()->mutateConversionActions(
                new MutateConversionActionsRequest([
                    'customer_id' => $customerId,
                    'operations'  => [$op],
                ])
            );

            return $response->getResults()[0]->getResourceName();
        } catch (\Exception $e) {
            $this->error("    API error: " . $e->getMessage());
            return null;
        }
    }

    private function extractLabel($client, string $customerId, string $resourceName): ?string
    {
        try {
            $response = $client->getGoogleAdsServiceClient()->search(
                new SearchGoogleAdsRequest([
                    'customer_id' => $customerId,
                    'query'       => "SELECT conversion_action.tag_snippets "
                        . "FROM conversion_action "
                        . "WHERE conversion_action.resource_name = '{$resourceName}'",
                ])
            );

            foreach ($response->iterateAllElements() as $row) {
                foreach ($row->getConversionAction()->getTagSnippets() as $snippet) {
                    if ($snippet->getType() !== TrackingCodeType::WEBPAGE) {
                        continue;
                    }
                    if (preg_match("/'send_to':\s*'[^\/]+\/([^']+)'/", $snippet->getEventSnippet(), $m)) {
                        return $m[1];
                    }
                }
            }
        } catch (\Exception $e) {
            $this->warn("    Label extraction error: " . $e->getMessage());
        }

        return null;
    }

    private function archiveByName($client, string $customerId, string $name): int
    {
        $this->info("Looking for conversion action: \"{$name}\" in account {$customerId}...");

        try {
            $response = $client->getGoogleAdsServiceClient()->search(
                new SearchGoogleAdsRequest([
                    'customer_id' => $customerId,
                    'query'       => "SELECT conversion_action.resource_name, conversion_action.status "
                        . "FROM conversion_action "
                        . "WHERE conversion_action.name = '" . addslashes($name) . "' LIMIT 1",
                ])
            );

            $resourceName = null;
            foreach ($response->iterateAllElements() as $row) {
                $resourceName = $row->getConversionAction()->getResourceName();
            }

            if (!$resourceName) {
                $this->warn("No conversion action found with that name. Nothing changed.");
                return self::SUCCESS;
            }

            $this->line("Found: {$resourceName}");

            $action = new ConversionAction([
                'resource_name' => $resourceName,
                'status'        => ConversionActionStatus::REMOVED,
            ]);

            $op = new ConversionActionOperation();
            $op->setUpdate($action);
            $op->setUpdateMask(new FieldMask(['paths' => ['status']]));

            $client->getConversionActionServiceClient()->mutateConversionActions(
                new MutateConversionActionsRequest([
                    'customer_id' => $customerId,
                    'operations'  => [$op],
                ])
            );

            $this->info("Archived \"{$name}\" — it will no longer appear in Google Ads conversion reporting.");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Archive failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function buildClient(): ?\Google\Ads\GoogleAds\Lib\V22\GoogleAdsClient
    {
        $configPath = storage_path('app/google_ads_php.ini');
        if (!file_exists($configPath)) {
            $this->error('google_ads_php.ini not found at ' . $configPath);
            return null;
        }

        $mcc = MccAccount::getActive();
        if (!$mcc) {
            $this->error('No active MCC account found.');
            return null;
        }

        try {
            $refreshToken = Crypt::decryptString($mcc->refresh_token);

            $oAuth2 = (new OAuth2TokenBuilder())
                ->fromFile($configPath)
                ->withRefreshToken($refreshToken)
                ->build();

            return (new GoogleAdsClientBuilder())
                ->fromFile($configPath)
                ->withOAuth2Credential($oAuth2)
                ->withLoginCustomerId($mcc->google_customer_id)
                ->build();
        } catch (\Exception $e) {
            $this->error('Failed to build Google Ads client: ' . $e->getMessage());
            return null;
        }
    }
}

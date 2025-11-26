<?php

namespace App\Services\FacebookAds;

use App\Models\Customer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Facebook Page Service
 * 
 * Handles fetching and managing Facebook Pages for customers.
 * Users may have multiple Pages and need to select which one to use for ads.
 */
class PageService extends BaseFacebookAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Get all Facebook Pages the user has access to.
     *
     * @return array
     */
    public function getPages(): array
    {
        try {
            $response = $this->get('/me/accounts', [
                'fields' => 'id,name,access_token,category,picture,fan_count,link,is_published,verification_status',
                'limit' => 100,
            ]);

            if ($response && isset($response['data'])) {
                Log::info('Retrieved Facebook pages', [
                    'customer_id' => $this->customer->id,
                    'page_count' => count($response['data']),
                ]);
                return $response['data'];
            }

            return [];

        } catch (\Exception $e) {
            Log::error('Error fetching Facebook pages: ' . $e->getMessage(), [
                'customer_id' => $this->customer->id,
            ]);
            return [];
        }
    }

    /**
     * Get details of a specific Facebook Page.
     *
     * @param string $pageId
     * @return array|null
     */
    public function getPage(string $pageId): ?array
    {
        try {
            return $this->get("/{$pageId}", [
                'fields' => 'id,name,access_token,category,picture,fan_count,link,is_published,verification_status,about,description,website',
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching Facebook page: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Set the selected page for a customer.
     *
     * @param string $pageId
     * @param string $pageName
     * @return bool
     */
    public function setSelectedPage(string $pageId, string $pageName): bool
    {
        try {
            $this->customer->update([
                'facebook_page_id' => $pageId,
                'facebook_page_name' => $pageName,
            ]);

            Log::info('Facebook page selected', [
                'customer_id' => $this->customer->id,
                'page_id' => $pageId,
                'page_name' => $pageName,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Error setting Facebook page: ' . $e->getMessage(), [
                'customer_id' => $this->customer->id,
            ]);
            return false;
        }
    }

    /**
     * Check if the customer has a Facebook Page connected.
     *
     * @return bool
     */
    public function hasPage(): bool
    {
        return !empty($this->customer->facebook_page_id);
    }

    /**
     * Get the currently selected page info.
     *
     * @return array|null
     */
    public function getSelectedPage(): ?array
    {
        if (!$this->hasPage()) {
            return null;
        }

        return [
            'id' => $this->customer->facebook_page_id,
            'name' => $this->customer->facebook_page_name,
        ];
    }

    /**
     * Clear the selected page.
     *
     * @return bool
     */
    public function clearSelectedPage(): bool
    {
        try {
            $this->customer->update([
                'facebook_page_id' => null,
                'facebook_page_name' => null,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Error clearing Facebook page: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate that the selected page still exists and is accessible.
     *
     * @return array{valid: bool, error?: string}
     */
    public function validateSelectedPage(): array
    {
        if (!$this->hasPage()) {
            return [
                'valid' => false,
                'error' => 'No page selected',
            ];
        }

        $page = $this->getPage($this->customer->facebook_page_id);

        if (!$page) {
            return [
                'valid' => false,
                'error' => 'Page no longer accessible',
            ];
        }

        if (!($page['is_published'] ?? true)) {
            return [
                'valid' => true,
                'warning' => 'Page is not published',
            ];
        }

        return [
            'valid' => true,
        ];
    }
}

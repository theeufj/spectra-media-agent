<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Find a customer, user or campaign from anywhere in the console.
 *
 * Seventy-one admin routes and no way to search across them: finding a customer
 * by email, or a campaign by the platform id in a support ticket, meant knowing
 * which page to start from and paging through it.
 *
 * Searches the identifiers people actually arrive with — an email address from
 * a ticket, a domain from a complaint, a numeric campaign id from a platform
 * console — not just display names.
 */
class SearchController extends Controller
{
    private const PER_TYPE = 8;

    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        // Two characters matches half the database and is slower than useless.
        if (mb_strlen($term) < 2) {
            return response()->json(['results' => [], 'term' => $term]);
        }

        return response()->json([
            'term' => $term,
            'results' => [
                ...$this->customers($term),
                ...$this->users($term),
                ...$this->campaigns($term),
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function customers(string $term): array
    {
        $like = '%'.$term.'%';

        // withTrashed: a deleted customer is exactly what you are looking for
        // when someone asks why their campaigns stopped.
        return Customer::withTrashed()
            ->where(fn ($q) => $q
                ->where('name', 'ILIKE', $like)
                ->orWhere('website', 'ILIKE', $like)
                ->orWhere('google_ads_customer_id', 'ILIKE', $like)
                ->orWhere('facebook_ads_account_id', 'ILIKE', $like)
            )
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Customer $c) => [
                'type' => 'customer',
                'id' => $c->id,
                'title' => $c->name.($c->trashed() ? ' (deleted)' : ''),
                'subtitle' => $c->website ?: 'No website',
                'url' => route('admin.customers.show', $c->id),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function users(string $term): array
    {
        $like = '%'.$term.'%';

        return User::where(fn ($q) => $q
            ->where('name', 'ILIKE', $like)
            ->orWhere('email', 'ILIKE', $like)
        )
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (User $u) => [
                'type' => 'user',
                'id' => $u->id,
                'title' => $u->name,
                'subtitle' => $u->email,
                'url' => route('admin.users.index').'?search='.urlencode($u->email),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function campaigns(string $term): array
    {
        $like = '%'.$term.'%';

        // Platform ids are how a campaign is identified in a Google or Meta
        // console, which is where the question usually starts.
        return Campaign::with('customer')
            ->where(fn ($q) => $q
                ->where('name', 'ILIKE', $like)
                ->orWhere('google_ads_campaign_id', 'ILIKE', $like)
                ->orWhere('facebook_ads_campaign_id', 'ILIKE', $like)
                ->orWhere('microsoft_ads_campaign_id', 'ILIKE', $like)
                ->orWhere('linkedin_campaign_id', 'ILIKE', $like)
            )
            ->limit(self::PER_TYPE)
            ->get()
            ->map(fn (Campaign $c) => [
                'type' => 'campaign',
                'id' => $c->id,
                'title' => $c->name,
                // status is cast to CampaignStatus, so ->value is always there.
                'subtitle' => $c->customer->name.' — '.$c->status->value,
                'url' => route('admin.campaigns.show', $c->id),
            ])
            ->all();
    }
}

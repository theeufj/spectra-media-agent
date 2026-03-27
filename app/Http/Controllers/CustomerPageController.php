<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPage;
use Illuminate\Http\Request;

class CustomerPageController extends Controller
{
    /**
     * List pages for a customer.
     */
    public function index(Request $request, Customer $customer)
    {
        // If no customer_pages exist, backfill from knowledge_bases
        if ($customer->pages()->count() === 0) {
            $userIds = $customer->users()->pluck('users.id');
            $kbEntries = \App\Models\KnowledgeBase::whereIn('user_id', $userIds)
                ->whereNotNull('url')
                ->select('url')
                ->distinct()
                ->get();

            foreach ($kbEntries as $kb) {
                CustomerPage::firstOrCreate(
                    ['customer_id' => $customer->id, 'url' => $kb->url],
                    ['title' => basename(parse_url($kb->url, PHP_URL_PATH)) ?: parse_url($kb->url, PHP_URL_HOST)]
                );
            }
        }

        $query = $customer->pages();

        if ($request->has('type')) {
            $query->where('page_type', $request->query('type'));
        }

        if ($request->has('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                  ->orWhere('url', 'ilike', "%{$search}%");
            });
        }

        return response()->json($query->paginate(20));
    }
}

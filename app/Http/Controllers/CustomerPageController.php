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
        $active = $this->getActiveCustomer($request);
        $this->authorize('view', $customer);
        abort_unless($active && $active->id === $customer->id, 404);

        // If no customer_pages exist, backfill from knowledge_bases
        if ($customer->pages()->count() === 0) {
            $userIds = $customer->users()->pluck('users.id');
            $kbEntries = \App\Models\KnowledgeBase::where('customer_id', $customer->id)
                ->whereNull('excluded_at')->where('source_type', 'url')->whereNotNull('url')
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

        $excluded = \App\Models\KnowledgeBase::where('customer_id', $customer->id)->whereNotNull('excluded_at')->pluck('url')->filter()->all();
        $query = $customer->pages()->whereNotIn('url', $excluded);
        if ($request->has('ids')) {
            $ids = $request->validate(['ids' => 'required|array|max:20', 'ids.*' => 'integer'])['ids'];
            $query->whereIn('id', $ids);
        }

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

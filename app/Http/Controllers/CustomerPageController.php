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
        // Ensure the user has access to this customer (authorization logic needed here usually)
        // For now assuming middleware handles basic auth.

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

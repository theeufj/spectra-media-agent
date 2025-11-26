<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class RevenueController extends Controller
{
    /**
     * Display the revenue dashboard.
     */
    public function index()
    {
        return Inertia::render('Admin/Revenue', [
            'metrics' => $this->getRevenueMetrics(),
            'recentTransactions' => $this->getRecentTransactions(),
            'subscriptionBreakdown' => $this->getSubscriptionBreakdown(),
            'monthlyRevenue' => $this->getMonthlyRevenue(),
        ]);
    }

    /**
     * Get key revenue metrics.
     */
    private function getRevenueMetrics(): array
    {
        try {
            \Stripe\Stripe::setApiKey(config('cashier.secret'));

            // Get balance
            $balance = \Stripe\Balance::retrieve();
            $availableBalance = collect($balance->available)->sum('amount') / 100;

            // Get MRR from active subscriptions
            $subscriptions = \Stripe\Subscription::all([
                'status' => 'active',
                'limit' => 100,
            ]);

            $mrr = collect($subscriptions->data)->sum(function ($sub) {
                $amount = $sub->items->data[0]->price->unit_amount ?? 0;
                $interval = $sub->items->data[0]->price->recurring->interval ?? 'month';
                
                // Normalize to monthly
                if ($interval === 'year') {
                    return ($amount / 12) / 100;
                }
                return $amount / 100;
            });

            // Get total subscribers
            $activeSubscribers = $subscriptions->data ? count($subscriptions->data) : 0;

            // Get this month's revenue from Stripe
            $startOfMonth = now()->startOfMonth()->timestamp;
            $charges = \Stripe\Charge::all([
                'created' => ['gte' => $startOfMonth],
                'limit' => 100,
            ]);
            $monthlyTotal = collect($charges->data)
                ->where('status', 'succeeded')
                ->sum('amount') / 100;

            // Get churn - cancelled subscriptions this month
            $cancelledSubs = \Stripe\Subscription::all([
                'status' => 'canceled',
                'created' => ['gte' => $startOfMonth],
                'limit' => 100,
            ]);
            $churnCount = $cancelledSubs->data ? count($cancelledSubs->data) : 0;
            $churnRate = $activeSubscribers > 0 
                ? round(($churnCount / ($activeSubscribers + $churnCount)) * 100, 1) 
                : 0;

            // Get free vs paid users from database
            $totalUsers = User::count();
            $paidUsers = DB::table('subscriptions')
                ->where('stripe_status', 'active')
                ->distinct('user_id')
                ->count('user_id');
            $freeUsers = $totalUsers - $paidUsers;

            return [
                'mrr' => round($mrr, 2),
                'arr' => round($mrr * 12, 2),
                'availableBalance' => round($availableBalance, 2),
                'monthlyRevenue' => round($monthlyTotal, 2),
                'activeSubscribers' => $activeSubscribers,
                'churnRate' => $churnRate,
                'totalUsers' => $totalUsers,
                'paidUsers' => $paidUsers,
                'freeUsers' => $freeUsers,
                'conversionRate' => $totalUsers > 0 
                    ? round(($paidUsers / $totalUsers) * 100, 1) 
                    : 0,
            ];
        } catch (\Exception $e) {
            Log::error('Revenue metrics error: ' . $e->getMessage());
            
            // Return fallback data from database
            $totalUsers = User::count();
            $paidUsers = DB::table('subscriptions')
                ->where('stripe_status', 'active')
                ->distinct('user_id')
                ->count('user_id');

            return [
                'mrr' => 0,
                'arr' => 0,
                'availableBalance' => 0,
                'monthlyRevenue' => 0,
                'activeSubscribers' => $paidUsers,
                'churnRate' => 0,
                'totalUsers' => $totalUsers,
                'paidUsers' => $paidUsers,
                'freeUsers' => $totalUsers - $paidUsers,
                'conversionRate' => $totalUsers > 0 
                    ? round(($paidUsers / $totalUsers) * 100, 1) 
                    : 0,
                'error' => 'Could not fetch Stripe data',
            ];
        }
    }

    /**
     * Get recent transactions from Stripe.
     */
    private function getRecentTransactions(): array
    {
        try {
            \Stripe\Stripe::setApiKey(config('cashier.secret'));

            $charges = \Stripe\Charge::all([
                'limit' => 20,
            ]);

            return collect($charges->data)->map(function ($charge) {
                // Try to find user by stripe customer ID
                $user = User::where('stripe_id', $charge->customer)->first();

                return [
                    'id' => $charge->id,
                    'amount' => $charge->amount / 100,
                    'currency' => strtoupper($charge->currency),
                    'status' => $charge->status,
                    'description' => $charge->description ?? 'Subscription payment',
                    'customer' => $user ? $user->name : ($charge->billing_details->name ?? 'Unknown'),
                    'email' => $user ? $user->email : ($charge->billing_details->email ?? null),
                    'created' => date('Y-m-d H:i', $charge->created),
                    'receipt_url' => $charge->receipt_url,
                ];
            })->toArray();
        } catch (\Exception $e) {
            Log::error('Recent transactions error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get subscription breakdown by plan.
     */
    private function getSubscriptionBreakdown(): array
    {
        try {
            \Stripe\Stripe::setApiKey(config('cashier.secret'));

            $subscriptions = \Stripe\Subscription::all([
                'status' => 'active',
                'limit' => 100,
            ]);

            $breakdown = [];
            foreach ($subscriptions->data as $sub) {
                $priceId = $sub->items->data[0]->price->id ?? 'unknown';
                $productName = $sub->items->data[0]->price->nickname ?? 'Spectra Pro';
                $amount = ($sub->items->data[0]->price->unit_amount ?? 0) / 100;
                
                if (!isset($breakdown[$priceId])) {
                    $breakdown[$priceId] = [
                        'name' => $productName,
                        'price' => $amount,
                        'count' => 0,
                        'revenue' => 0,
                    ];
                }
                
                $breakdown[$priceId]['count']++;
                $breakdown[$priceId]['revenue'] += $amount;
            }

            return array_values($breakdown);
        } catch (\Exception $e) {
            Log::error('Subscription breakdown error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get monthly revenue for the last 12 months.
     */
    private function getMonthlyRevenue(): array
    {
        try {
            \Stripe\Stripe::setApiKey(config('cashier.secret'));

            $monthlyData = [];
            
            for ($i = 11; $i >= 0; $i--) {
                $date = now()->subMonths($i);
                $startOfMonth = $date->copy()->startOfMonth()->timestamp;
                $endOfMonth = $date->copy()->endOfMonth()->timestamp;

                $charges = \Stripe\Charge::all([
                    'created' => [
                        'gte' => $startOfMonth,
                        'lte' => $endOfMonth,
                    ],
                    'limit' => 100,
                ]);

                $revenue = collect($charges->data)
                    ->where('status', 'succeeded')
                    ->sum('amount') / 100;

                $monthlyData[] = [
                    'month' => $date->format('M Y'),
                    'revenue' => round($revenue, 2),
                ];
            }

            return $monthlyData;
        } catch (\Exception $e) {
            Log::error('Monthly revenue error: ' . $e->getMessage());
            
            // Return empty data for 12 months
            $monthlyData = [];
            for ($i = 11; $i >= 0; $i--) {
                $monthlyData[] = [
                    'month' => now()->subMonths($i)->format('M Y'),
                    'revenue' => 0,
                ];
            }
            return $monthlyData;
        }
    }

    /**
     * Issue a refund for a charge.
     */
    public function refund(Request $request, string $chargeId)
    {
        $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'reason' => 'nullable|string|in:duplicate,fraudulent,requested_by_customer',
        ]);

        try {
            \Stripe\Stripe::setApiKey(config('cashier.secret'));

            $refundParams = ['charge' => $chargeId];
            
            if ($request->amount) {
                $refundParams['amount'] = $request->amount * 100; // Convert to cents
            }
            
            if ($request->reason) {
                $refundParams['reason'] = $request->reason;
            }

            $refund = \Stripe\Refund::create($refundParams);

            // Log the refund
            \App\Models\ActivityLog::log(
                'refund_issued',
                "Refund issued for charge {$chargeId}",
                null,
                [
                    'charge_id' => $chargeId,
                    'refund_id' => $refund->id,
                    'amount' => $refund->amount / 100,
                ]
            );

            return redirect()->back()->with('flash', [
                'type' => 'success',
                'message' => 'Refund processed successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Refund error: ' . $e->getMessage());
            
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => 'Refund failed: ' . $e->getMessage(),
            ]);
        }
    }
}

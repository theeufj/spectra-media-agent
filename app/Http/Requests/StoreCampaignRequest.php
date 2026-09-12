<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCampaignRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Ensures the user belongs to the active customer.
     */
    public function authorize(): bool
    {
        $customerId = session('active_customer_id');

        if (! $customerId) {
            return false;
        }

        return $this->user()->customers()->where('customers.id', $customerId)->exists();
    }

    /**
     * Field names the customer will recognise from the form.
     *
     * Without this, a missing Brand Voice reads as "voice: The voice field is
     * required." on the review step — a column name, not a label, and nine
     * steps away from the field it refers to. Every name here matches the
     * label rendered in the wizard.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'Campaign Name',
            'reason' => 'why you are running this campaign',
            'goals' => 'your primary goals',
            'target_market' => 'Target Audience',
            'voice' => 'Brand Voice / Tone',
            'total_budget' => 'Total Budget',
            'daily_budget' => 'Daily Budget',
            'start_date' => 'Start Date',
            'end_date' => 'End Date',
            'primary_kpi' => 'Primary KPI / Target',
            'product_focus' => 'Product/Service Focus',
            'landing_page_url' => 'Campaign Destination',
            'exclusions' => 'Exclusions',
            'selected_pages' => 'Landing Pages',
            'platforms' => 'Platforms',
            'images' => 'Images',
            'videos' => 'Videos',
            'keywords' => 'Keywords',
        ];
    }

    /**
     * Get the validation rules that apply to the request.
     * This is the direct equivalent of Go's struct tags for validation.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('campaigns', 'name')->where('customer_id', session('active_customer_id')),
            ],
            'reason' => 'required|string',
            'goals' => 'required|string',
            'target_market' => 'required|string',
            'voice' => 'required|string',
            'total_budget' => 'required|numeric|min:0|max:9999999',
            'daily_budget' => 'nullable|numeric|min:0|max:999999',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'primary_kpi' => 'required|string',
            'product_focus' => 'nullable|string',
            'landing_page_url' => 'nullable|url',
            'exclusions' => 'nullable|string',
            'selected_pages' => 'nullable|array',
            // Scoped to this customer, not just to the table. `exists` runs
            // through the presence verifier, which bypasses CustomerScope
            // entirely — a bare `exists:customer_pages,id` accepts any tenant's
            // page id, and a queue worker (no acting user, scope inert) then
            // renders that page's url, title and price into this campaign's
            // strategy and ad copy.
            'selected_pages.*' => [
                Rule::exists('customer_pages', 'id')->where('customer_id', session('active_customer_id')),
            ],
            'keywords' => 'nullable|array|max:100',
            'keywords.*.text' => 'required_with:keywords|string|max:200',
            'keywords.*.match_type' => 'required_with:keywords|string|in:BROAD,PHRASE,EXACT',
            'keywords.*.avg_monthly_searches' => 'nullable|integer',
            'keywords.*.competition_index' => 'nullable|integer|min:0|max:100',
            'keywords.*.intent' => 'nullable|string|max:50',
            'keywords.*.cluster' => 'nullable|string|max:200',
            'keywords.*.funnel_stage' => 'nullable|string|max:50',
            'platforms' => 'required|array|min:1',
            'platforms.*' => 'string|in:google,facebook,microsoft,linkedin',
            'images' => 'nullable|array|max:10',
            'images.*' => 'file|mimes:jpeg,jpg,png,webp|max:10240',
            'seed_images' => 'nullable|array|max:5',
            'seed_images.*' => 'file|mimes:jpeg,jpg,png,webp|max:10240',
            'videos' => 'nullable|array|max:3',
            'videos.*' => 'file|mimes:mp4,mov,webm|max:102400',
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A campaign with this name already exists for your account.',
            'name.required' => 'Please provide a campaign name.',
            'total_budget.max' => 'Total budget cannot exceed $9,999,999.',
            'daily_budget.max' => 'Daily budget cannot exceed $999,999.',
            'platforms.required' => 'Select at least one advertising platform.',
            'platforms.min' => 'Select at least one advertising platform.',
            'start_date.required' => 'A campaign start date is required.',
            'end_date.after_or_equal' => 'End date must be on or after the start date.',
            'keywords.*.text.required_with' => 'Each keyword must have text.',
            'keywords.*.match_type.in' => 'Keyword match type must be BROAD, PHRASE, or EXACT.',
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCampaignRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Ensures the user belongs to the active customer.
     */
    public function authorize(): bool
    {
        $customerId = session('active_customer_id');

        if (!$customerId) {
            return false;
        }

        return $this->user()->customers()->where('customers.id', $customerId)->exists();
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
            'name' => 'required|string|max:255',
            'reason' => 'required|string',
            'goals' => 'required|string',
            'target_market' => 'required|string',
            'voice' => 'required|string',
            'total_budget' => 'required|numeric|min:0',
            'daily_budget' => 'nullable|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'primary_kpi' => 'required|string',
            'product_focus' => 'nullable|string',
            'landing_page_url' => 'nullable|url',
            'exclusions' => 'nullable|string',
            'selected_pages' => 'nullable|array',
            'selected_pages.*' => 'exists:customer_pages,id',
        ];
    }

}

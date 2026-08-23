<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetupAdSpendBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method_id' => 'sometimes|nullable|string',
            'daily_budget' => 'required|numeric|min:1',
            'days_to_charge' => 'sometimes|integer|min:1|max:30',
            // Lets the server refuse to charge for a campaign that isn't
            // ready to deploy (e.g. auto-generated budget not yet confirmed).
            'campaign_id' => 'sometimes|nullable|integer|exists:campaigns,id',
        ];
    }
}

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
            'payment_method_id' => 'required|string',
            'daily_budget' => 'required|numeric|min:1',
        ];
    }
}

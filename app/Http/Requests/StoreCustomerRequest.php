<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'business_type' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'country' => 'required|string|size:2',
            'timezone' => 'required|string|max:255|timezone',
            'currency_code' => 'nullable|string|size:3|uppercase',
            'website' => 'nullable|url|max:255',
            'phone' => 'nullable|string|max:20',
            'facebook_page_url' => 'nullable|string|max:500',
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->customers()->where('customers.id', $this->route('customer')->id)->exists();
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'business_type' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'country' => 'nullable|string|size:2',
            'timezone' => 'nullable|string|max:255|timezone',
            'currency_code' => 'nullable|string|size:3|uppercase',
            'website' => 'nullable|url|max:255',
            'phone' => 'nullable|string|max:20',
        ];
    }
}

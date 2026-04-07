<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddAdSpendCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:50|max:10000',
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeployCollateralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'required|string|in:ad_copy,image,video',
            'id' => 'required|integer',
        ];
    }
}

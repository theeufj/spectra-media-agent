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
            // should_deploy (default) flips deployment; is_seed marks an
            // image as AI reference material — images only.
            'field' => 'nullable|string|in:should_deploy,is_seed',
            // Optional. Absent means "flip it", which is what every caller did
            // before one card started standing for three rows at once.
            'value' => 'nullable|boolean',
        ];
    }
}

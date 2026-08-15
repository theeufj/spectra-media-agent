<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A stored revision of a named prompt.
 *
 * The prompt_versions table has existed since 2025 and PromptTestingService
 * queries PromptVersion::where(...), but the model was never created, so the
 * call resolved to a missing class and fatalled.
 */
class PromptVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'prompt_name',
        'version_number',
        'prompt_text',
    ];

    protected $casts = [
        'version_number' => 'integer',
    ];
}

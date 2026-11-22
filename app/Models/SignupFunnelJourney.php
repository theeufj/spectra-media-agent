<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class SignupFunnelJourney extends Model
{
    use MassPrunable;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    public function prunable(): Builder
    {
        return static::where('started_at', '<', now()->subDays(90));
    }
}

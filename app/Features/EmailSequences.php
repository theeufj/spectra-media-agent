<?php

namespace App\Features;

use App\Models\User;

/**
 * Off until the copy has been read and signed off.
 *
 * These chains write to people who are not yet customers, from a named
 * founder's address. Getting that wrong is not a bug you can quietly patch —
 * the email has already arrived. The flag exists so the machinery can be
 * built, deployed and test-sent to the founders without any possibility of a
 * real prospect receiving something nobody has read.
 *
 * Resolves globally rather than per user: an audience is a population, so
 * enabling this for one person and not another would send half a chain.
 */
class EmailSequences
{
    public function resolve(?User $user = null): bool
    {
        return (bool) config('email_sequences.enabled', false);
    }
}

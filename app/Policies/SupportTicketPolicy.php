<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;

class SupportTicketPolicy
{
    public function view(User $user, SupportTicket $supportTicket): bool
    {
        return $user->id === $supportTicket->user_id;
    }

    public function update(User $user, SupportTicket $supportTicket): bool
    {
        return $user->id === $supportTicket->user_id;
    }

    public function delete(User $user, SupportTicket $supportTicket): bool
    {
        return $user->id === $supportTicket->user_id;
    }
}

<?php

namespace App\Policies;

use App\Models\KnowledgeBase;
use App\Models\User;

class KnowledgeBasePolicy
{
    public function view(User $user, KnowledgeBase $knowledgeBase): bool
    {
        return $user->id === $knowledgeBase->user_id;
    }

    public function update(User $user, KnowledgeBase $knowledgeBase): bool
    {
        return $user->id === $knowledgeBase->user_id;
    }

    public function delete(User $user, KnowledgeBase $knowledgeBase): bool
    {
        return $user->id === $knowledgeBase->user_id;
    }
}

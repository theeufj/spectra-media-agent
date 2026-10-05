<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class SupportTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'customer_id',
        'subject',
        'description',
        'priority',
        'status',
        'category',
        'source',
        'transcript',
        'admin_response',
        'assigned_to',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'transcript' => 'array',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'in_progress']);
    }

    /** @param array<string, mixed> $meta */
    public function appendMessage(string $role, string $text, array $meta = [], bool $reopen = false): void
    {
        DB::transaction(function () use ($role, $text, $meta, $reopen) {
            $ticket = self::query()->lockForUpdate()->findOrFail($this->id);
            $changes = ['transcript' => [
                ...($ticket->transcript ?? []),
                ['role' => $role, 'text' => $text, 'at' => now()->toIso8601String(), ...$meta],
            ]];
            if ($reopen) {
                $changes['status'] = 'open';
                $changes['resolved_at'] = null;
            }
            $ticket->update($changes);
        });
        $this->refresh();
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'in_progress']);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}

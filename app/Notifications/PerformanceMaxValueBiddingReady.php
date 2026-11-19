<?php

namespace App\Notifications;

use App\Models\Campaign;
use Illuminate\Notifications\Notification;

class PerformanceMaxValueBiddingReady extends Notification
{
    public function __construct(private Campaign $campaign, private int $purchases) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Review Performance Max bidding',
            'message' => $this->campaign->name.' recorded '.$this->purchases.' Google-attributed paid conversions with value over four weeks. Review the purchase goal and amounts before switching to Maximize Conversion Value.',
            'action_text' => 'Review campaign',
            'action_url' => route('campaigns.show', $this->campaign, false),
        ];
    }
}

<?php

namespace App\Console\Commands;

use App\Models\EmailInbox;
use App\Models\User;
use Illuminate\Console\Command;

class InboxCreate extends Command
{
    protected $signature = 'inbox:create {user_email} {inbox_address} {display_name}';

    protected $description = 'Assign a sitetospend.com inbox to a user';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('user_email'))->first();

        if (! $user) {
            $this->error("No user found with email: {$this->argument('user_email')}");
            return 1;
        }

        $inbox = EmailInbox::updateOrCreate(
            ['user_id' => $user->id],
            [
                'email_address' => strtolower($this->argument('inbox_address')),
                'display_name' => $this->argument('display_name'),
            ]
        );

        $this->info("Inbox ready: {$inbox->email_address} → user #{$user->id} ({$user->email})");
        return 0;
    }
}

<?php

namespace App\Services\GTM;

use App\Models\Connection;

/** One administrator-authorized platform credential, shared across permitted GTM accounts. */
class GTMPlatformCredential
{
    public const SCOPES = [
        'openid', 'email',
        'https://www.googleapis.com/auth/tagmanager.readonly',
        'https://www.googleapis.com/auth/tagmanager.edit.containers',
        'https://www.googleapis.com/auth/tagmanager.edit.containerversions',
        'https://www.googleapis.com/auth/tagmanager.publish',
        'https://www.googleapis.com/auth/tagmanager.manage.users',
    ];

    public function connection(): ?Connection
    {
        $connection = Connection::where('platform', 'gtm_platform')
            ->where('account_id', (string) config('services.gtm.platform_account_id'))->latest('id')->first();
        $required = array_filter(self::SCOPES, fn ($scope) => str_starts_with($scope, 'https://'));
        $refresh = $connection?->getAttribute('refresh_token');
        $email = $connection?->getAttribute('account_name');

        return $connection && is_string($refresh) && $refresh !== '' && is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)
            && is_array($connection->scopes) && ! array_diff($required, $connection->scopes) ? $connection : null;
    }

    public function refreshToken(): ?string
    {
        $token = $this->connection()?->getAttribute('refresh_token') ?? config('services.gtm.platform_refresh_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function email(): ?string
    {
        $email = $this->connection()?->getAttribute('account_name') ?? config('services.gtm.platform_email');

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public function summary(): array
    {
        $connection = $this->connection();

        return ['email' => $this->email(), 'authorized_at' => $connection?->updated_at?->toIso8601String(),
            'ownership_checks_authorized' => $connection !== null,
            'account_id' => (string) config('services.gtm.platform_account_id')];
    }
}

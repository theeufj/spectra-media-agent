<?php

namespace App\Services\Demo;

use App\Models\User;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SavedAdPreview
{
    public const SESSION_KEY = 'signup_ad_preview';

    /**
     * @param  array{headlines:list<string>, descriptions:list<string>}  $adCopy
     */
    public function create(Request $request, string $url, array $adCopy): string
    {
        $token = bin2hex(random_bytes(32));
        $saved = Cache::put($this->key($token), [
            'browser' => hash('sha256', $request->session()->getId()),
            'draft' => ['url' => $url, 'ad_copy' => $adCopy],
        ], now()->addHours(2));
        if (! $saved) {
            throw new \RuntimeException('Unable to store an anonymous ad preview.');
        }

        return $token;
    }

    /** @return array{url:string, ad_copy:array{headlines:list<string>, descriptions:list<string>}}|null */
    public function get(Request $request, mixed $token): ?array
    {
        if (! is_string($token) || preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1
            || ! Tenant::singlePlan($request->attributes->get('tenant')['key'] ?? null)) {
            return null;
        }

        $stored = Cache::get($this->key($token));
        if (! is_array($stored) || ! hash_equals($stored['browser'], hash('sha256', $request->session()->getId()))) {
            return null;
        }

        return $stored['draft'];
    }

    public function reference(string $token): string
    {
        return hash('sha256', 'registration:'.$token);
    }

    public function registrationToken(Request $request): ?string
    {
        $token = $request->session()->get(self::SESSION_KEY);
        $reference = $request->input('preview_reference');

        return is_string($token) && is_string($reference) && hash_equals($this->reference($token), $reference) ? $token : null;
    }

    /** @param  \Closure(array): User  $register */
    public function claim(Request $request, string $token, \Closure $register): ?User
    {
        if ($this->get($request, $token) === null) {
            return null;
        }

        return Cache::lock($this->key($token).':claim', 30)->get(function () use ($request, $token, $register) {
            $draft = $this->get($request, $token);
            if ($draft === null) {
                return null;
            }

            $user = $register($draft);
            if ($user->wasRecentlyCreated) {
                Cache::forget($this->key($token));
            }

            return $user;
        }) ?: null;
    }

    private function key(string $token): string
    {
        return 'website-ad-preview:'.hash('sha256', $token);
    }
}

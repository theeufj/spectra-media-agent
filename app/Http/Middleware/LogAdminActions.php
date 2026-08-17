<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Record every admin action that changes something.
 *
 * Only three of nineteen admin controllers logged anything. Feature flags,
 * platform settings, MCC credentials and plan pricing — all of which change
 * behaviour for every customer at once — could be altered with no record of who
 * did it or when. MccAccountController in particular rotates the credentials the
 * entire platform authenticates with.
 *
 * Doing this per controller means remembering nineteen times, and the three that
 * were done prove how that goes. A middleware cannot be forgotten: any route
 * added to the admin group is covered the moment it exists.
 *
 * Reads are ignored. The log is for answering "who changed this", and a GET
 * changes nothing.
 */
class LogAdminActions
{
    /** Never record these, whatever else the payload contains. */
    private const REDACT = [
        'password', 'password_confirmation', 'token', 'refresh_token', 'access_token',
        'client_secret', 'api_key', 'secret', 'developer_token', 'stripe_secret',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $response;
        }

        // Logging must never break the action it is recording.
        try {
            $this->record($request, $response);
        } catch (\Throwable $e) {
            report($e);
            Log::error('LogAdminActions: failed to record admin action', ['error' => $e->getMessage()]);
        }

        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        $user = $request->user();
        $route = $request->route();

        ActivityLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'action' => 'admin.'.($route?->getName() ?? $request->path()),
            'subject_type' => $this->subjectType($route),
            'subject_id' => $this->subjectId($route),
            'description' => $this->describe($request, $response),
            'properties' => [
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                // The payload is what makes this answerable rather than merely
                // suggestive: "changed the plan" versus "changed the plan price
                // from 250 to 25".
                'input' => $this->safeInput($request),
                // A request made while impersonating is still the admin's doing.
                'impersonating' => session('impersonate_user_id'),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }

    /**
     * The model this route acts on, taken from its bound parameters.
     */
    private function subjectType(?\Illuminate\Routing\Route $route): ?string
    {
        foreach ($route?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof \Illuminate\Database\Eloquent\Model) {
                return $parameter::class;
            }
        }

        return null;
    }

    private function subjectId(?\Illuminate\Routing\Route $route): ?int
    {
        foreach ($route?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof \Illuminate\Database\Eloquent\Model) {
                return (int) $parameter->getKey();
            }
        }

        return null;
    }

    private function describe(Request $request, Response $response): string
    {
        $name = $request->route()?->getName() ?? $request->path();
        $outcome = $response->isSuccessful() || $response->isRedirection() ? 'succeeded' : 'failed';

        return "{$request->method()} {$name} {$outcome} ({$response->getStatusCode()})";
    }

    /**
     * @return array<string, mixed>
     */
    private function safeInput(Request $request): array
    {
        $input = $request->except(self::REDACT);

        // Long free text (ticket replies, notification bodies) would bloat every
        // row for no investigative value.
        return collect($input)
            ->map(fn ($value) => is_string($value) ? mb_substr($value, 0, 500) : $value)
            ->take(40)
            ->all();
    }
}

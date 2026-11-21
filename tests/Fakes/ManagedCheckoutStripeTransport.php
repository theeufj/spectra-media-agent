<?php

namespace Tests\Fakes;

use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

/** Shared, file-locked Stripe fixture so independent PHP workers see the same remote state. */
class ManagedCheckoutStripeTransport implements ClientInterface
{
    public bool $loseNextCreateResponse = false;

    public function __construct(private string $file) {}

    public function state(): array
    {
        return json_decode(file_get_contents($this->file), true) ?: ['sessions' => [], 'subscriptions' => [], 'creates' => []];
    }

    public function set(array $state): void
    {
        file_put_contents($this->file, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $handle = fopen($this->file, 'r+');
        try {
            flock($handle, LOCK_EX);
            $state = $this->state();
            $path = parse_url($absUrl, PHP_URL_PATH);
            $response = $this->respond($state, $method, $path, $params, $headers);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            fclose($handle);
        }
        if ($method === 'post' && $path === '/v1/checkout/sessions' && $this->loseNextCreateResponse) {
            $this->loseNextCreateResponse = false;
            throw new ApiConnectionException('The response was lost after the session was created.');
        }

        return [json_encode($response, JSON_THROW_ON_ERROR), 200, []];
    }

    private function respond(array &$state, string $method, string $path, array $params, array $headers): array
    {
        $list = fn (array $items) => ['object' => 'list', 'data' => array_values($items), 'has_more' => false, 'url' => $path];
        if ($method === 'get' && $path === '/v1/subscriptions') {
            return $list(array_filter($state['subscriptions'], fn ($s) => $s['customer'] === $params['customer']));
        }
        if ($method === 'get' && preg_match('~^/v1/subscriptions/(sub_\w+)$~', $path, $matches)) {
            return $state['subscriptions'][$matches[1]];
        }
        if ($method === 'get' && $path === '/v1/checkout/sessions') {
            return $list(array_filter($state['sessions'], fn ($s) => $s['customer'] === $params['customer'] && (! isset($params['status']) || $s['status'] === $params['status'])));
        }
        if ($method === 'get' && preg_match('~^/v1/checkout/sessions/(cs_\w+)/line_items$~', $path, $matches)) {
            return $list([['object' => 'item', 'price' => ['id' => $state['sessions'][$matches[1]]['price']]]]);
        }
        if (preg_match('~^/v1/checkout/sessions/(cs_\w+)(/expire)?$~', $path, $matches)) {
            if ($method === 'post' && ($matches[2] ?? null) === '/expire') {
                $state['sessions'][$matches[1]]['status'] = 'expired';
            }

            return $state['sessions'][$matches[1]];
        }
        if ($method === 'post' && $path === '/v1/checkout/sessions') {
            $header = collect($headers)->first(fn ($h) => str_starts_with(strtolower($h), 'idempotency-key: '));
            if (! $header) {
                throw new \RuntimeException('Checkout was posted without a Stripe idempotency key.');
            }
            $key = trim(substr($header, strlen('idempotency-key: ')));
            $state['creates'][] = ['key' => $key, 'parameters' => $params];
            $id = 'cs_'.substr(hash('sha256', $key), 0, 16);
            $state['sessions'][$id] ??= ['id' => $id, 'object' => 'checkout.session', 'status' => 'open', 'customer' => $params['customer'], 'mode' => 'subscription',
                'client_reference_id' => $params['client_reference_id'], 'price' => $params['line_items'][0]['price'], 'url' => 'https://checkout.stripe.test/'.$id];

            return $state['sessions'][$id];
        }

        throw new \RuntimeException('Unexpected Stripe call '.$method.' '.$path);
    }
}

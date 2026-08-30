<?php

namespace Tests;

use App\Models\Scopes\CustomerScope;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

abstract class TestCase extends BaseTestCase
{
    /**
     * Integration suites opt in explicitly and are expected to hit live APIs.
     * Everything else must not touch the network.
     */
    private const INTEGRATION_FLAGS = [
        'RUN_INTEGRATION_TESTS',
        'RUN_GOOGLE_ADS_INTEGRATION_TESTS',
        'RUN_FACEBOOK_INTEGRATION_TESTS',
        'RUN_LINKEDIN_ADS_INTEGRATION_TESTS',
        'RUN_MICROSOFT_ADS_INTEGRATION_TESTS',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Queued jobs must not run inline. phpunit.xml sets QUEUE_CONNECTION=sync,
        // and CustomerObserver dispatches ScrapeCustomerWebsite whenever a Customer
        // is created with a website — which CustomerFactory always sets. That job
        // drives Browsershot at a faker URL, so *every* test that created a Customer
        // spawned Chromium against a domain that doesn't exist and hung until killed.
        //
        // Safe to fake globally: every job test invokes handle() directly, and the
        // one test that genuinely dispatches already fakes the queue itself.
        Queue::fake();

        // Any HTTP call a test didn't explicitly fake becomes an immediate, named
        // failure rather than a hang. A test that needs HTTP should Http::fake() it.
        if (! $this->integrationTestsEnabled()) {
            Http::preventStrayRequests();
        }

        // The tenant scope memoises each user's customer list for the life of a
        // request. A test process is one long "request", so without this a user
        // created in one test would keep an earlier test's customer list.
        CustomerScope::flush();
    }

    private function integrationTestsEnabled(): bool
    {
        foreach (self::INTEGRATION_FLAGS as $flag) {
            // Read the environment directly rather than via env(): these flags come
            // from phpunit.xml's <env> block, and env() is the wrong tool outside
            // the config directory.
            $value = $_ENV[$flag] ?? $_SERVER[$flag] ?? getenv($flag);

            if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        return false;
    }
}

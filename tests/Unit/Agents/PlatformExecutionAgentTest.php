<?php

namespace Tests\Unit\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\PlatformExecutionAgent;
use App\Services\Agents\ValidationResult;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\Support\FakePlatformExecutionAgent;
use Tests\TestCase;

/**
 * The template method contract.
 *
 * execute() used to be abstract, so all four platform agents wrote their own
 * copy of this flow and the copies drifted — different validation accessors,
 * two of them catching \Exception instead of \Throwable, two of them never
 * calling report(). These tests exist so that can't happen again silently.
 */
class PlatformExecutionAgentTest extends TestCase
{
    // GeminiService::recordCost() writes an ai_costs row on every call, so
    // these tests were committing cost rows that leaked into the suite and
    // broke AiCostControllerTest's totals.
    use DatabaseTransactions;

    private function customer(): Customer
    {
        $customer = new Customer(['name' => 'Test Company', 'google_ads_customer_id' => '1234567890']);
        $customer->id = 1;

        return $customer;
    }

    private function context(): ExecutionContext
    {
        $customer = $this->customer();

        $campaign = new Campaign(['name' => 'Test Campaign']);
        $campaign->id = 42;

        $strategy = new Strategy(['platform' => 'test']);
        $strategy->id = 7;

        return new ExecutionContext($strategy, $campaign, $customer);
    }

    /**
     * @param  array<string, callable>  $steps
     */
    private function agent(Customer $customer, array $steps = []): FakePlatformExecutionAgent
    {
        return new FakePlatformExecutionAgent($customer, $steps);
    }

    public function test_gemini_service_resolved_from_container(): void
    {
        $geminiMock = Mockery::mock(GeminiService::class);
        $this->app->instance(GeminiService::class, $geminiMock);

        $agent = $this->agent($this->customer());

        $this->assertInstanceOf(PlatformExecutionAgent::class, $agent);
        $this->assertSame($geminiMock, $agent->getGemini());
    }

    public function test_execute_is_final_so_subclasses_cannot_reimplement_the_flow(): void
    {
        $method = new \ReflectionMethod(PlatformExecutionAgent::class, 'execute');

        $this->assertTrue($method->isFinal(), 'execute() must stay final — four divergent copies is how this started.');
    }

    public function test_runs_the_steps_in_order_and_stamps_execution_time(): void
    {
        $this->app->instance(GeminiService::class, Mockery::mock(GeminiService::class));

        $agent = $this->agent($this->customer());
        $result = $agent->execute($this->context());

        $this->assertTrue($result->success);
        $this->assertSame(['boot', 'validate', 'plan', 'execute'], $agent->calls);
        $this->assertGreaterThan(0.0, $result->executionTime, 'executors that do not time themselves must still be timed');
    }

    public function test_failed_validation_short_circuits_before_planning(): void
    {
        $this->app->instance(GeminiService::class, Mockery::mock(GeminiService::class));

        $agent = $this->agent($this->customer(), [
            'validate' => fn () => (new ValidationResult(true))
                ->addError('no_ad_copy', 'No ad copy available'),
        ]);

        $result = $agent->execute($this->context());

        $this->assertFalse($result->success);
        $this->assertSame(['boot', 'validate'], $agent->calls, 'a failed prerequisite must not generate or run a plan');
        $this->assertSame('No ad copy available', $result->errorMessage());
    }

    public function test_validation_warnings_survive_a_successful_deploy(): void
    {
        $this->app->instance(GeminiService::class, Mockery::mock(GeminiService::class));

        // Facebook's copy passed these into failure()'s metadata slot, so they
        // were dropped on failure and never carried at all on success.
        $agent = $this->agent($this->customer(), [
            'validate' => fn () => (new ValidationResult(true))
                ->addWarning('no_pixel', 'No Facebook Pixel configured'),
        ]);

        $result = $agent->execute($this->context());

        $this->assertTrue($result->success);
        $this->assertSame('No Facebook Pixel configured', $result->warningMessage());
    }

    public function test_a_thrown_exception_is_reported_and_carries_a_recovery_plan(): void
    {
        $this->app->instance(GeminiService::class, Mockery::mock(GeminiService::class));

        // Capture what reaches the report handler: deploy failures have to land
        // on the admin exception dashboard, which is fed from there.
        $reported = [];
        $handler = Mockery::mock(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $handler->shouldIgnoreMissing();
        /** @var \Mockery\Expectation $expectation */
        $expectation = $handler->shouldReceive('report');
        $expectation->andReturnUsing(function (\Throwable $e) use (&$reported) {
            $reported[] = $e;
        });
        $this->app->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, $handler);

        $agent = $this->agent($this->customer(), [
            'plan' => fn () => throw new \RuntimeException('API rate limit exceeded'),
        ]);

        $result = $agent->execute($this->context());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('rate limit', $result->errorMessage());
        $this->assertStringContainsString('Test Platform', $result->errorMessage());
        $this->assertArrayHasKey('recovery_plan', $result->metadata);
        $this->assertContains('recover', $agent->calls);
        $this->assertCount(1, $reported, 'deploy failures must reach the admin exception dashboard via report()');
    }

    public function test_an_error_not_just_an_exception_is_contained(): void
    {
        $this->app->instance(GeminiService::class, Mockery::mock(GeminiService::class));

        // Microsoft and LinkedIn caught \Exception, so a TypeError from an SDK
        // signature change sailed straight past the handler meant to contain it.
        $agent = $this->agent($this->customer(), [
            'execute' => fn () => throw new \TypeError('Argument #1 must be of type string, null given'),
        ]);

        $result = $agent->execute($this->context());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('must be of type string', $result->errorMessage());
        $this->assertArrayHasKey('recovery_plan', $result->metadata);
    }

    public function test_errors_are_always_agent_issues_never_a_string_array_union(): void
    {
        $this->app->instance(GeminiService::class, Mockery::mock(GeminiService::class));

        $agent = $this->agent($this->customer(), [
            // The old constructor path took bare strings; addError() took a
            // (code, message) pair. Consumers could not tell which they had.
            'execute' => fn () => ExecutionResult::failure(['a plain string error']),
        ]);

        $result = $agent->execute($this->context());

        // The list is typed, so assert the runtime shape the JSON columns see.
        $this->assertSame('a plain string error', $result->errorMessage());
        $this->assertSame(
            [['code' => 'general', 'message' => 'a plain string error']],
            $result->toArray()['errors'],
        );
    }
}

<?php

namespace Tests\Feature;

use App\Services\Agents\PlatformExecutionAgent;
use App\Services\Agents\RecoveryPlan;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A failed deployment must produce a diagnosis, not just a stack trace.
 *
 * Every execution agent implements handleExecutionError, but only the Microsoft
 * and LinkedIn agents ever called theirs. Facebook and Google — the two
 * platforms that have actually deployed campaigns — defined the method and threw
 * straight past it, so the only platforms whose failures were explained were the
 * ones that had never run.
 *
 * The handlers themselves were also broken in ways nothing could reveal while
 * they were unreachable: RecoveryPlan::fromJson takes the throwable plus the
 * json, and simple() takes a throwable and one action string. Both agents called
 * them with the wrong arity and the wrong types, so even the fallback path would
 * have failed.
 */
class DeploymentRecoveryTest extends TestCase
{
    /** @return list<array{class-string}> */
    public static function agents(): array
    {
        return [
            'google' => [\App\Services\Agents\GoogleAdsExecutionAgent::class],
            'facebook' => [\App\Services\Agents\FacebookAdsExecutionAgent::class],
            'microsoft' => [\App\Services\Agents\MicrosoftAdsExecutionAgent::class],
            'linkedin' => [\App\Services\Agents\LinkedInAdsExecutionAgent::class],
        ];
    }

    /**
     * @dataProvider agents
     *
     * @param  class-string  $agent
     */
    public function test_execute_routes_failures_through_recovery(string $agent): void
    {
        // Reading the source is crude, but it is the property that actually
        // matters and the one that was missing: the catch block has to reach
        // handleExecutionError rather than let the throwable escape.
        $source = file_get_contents((new \ReflectionClass($agent))->getFileName());

        $this->assertStringContainsString(
            '$this->handleExecutionError(',
            $source,
            $agent.' defines a recovery handler it never calls'
        );
    }

    /**
     * @dataProvider agents
     *
     * @param  class-string  $agent
     */
    public function test_the_handler_accepts_any_throwable(string $agent): void
    {
        // The failures worth recovering from include \Error — a TypeError, or a
        // call to a method that does not exist, which is how several deployment
        // paths in this codebase have actually broken. A handler typed
        // \Exception cannot be handed one.
        $parameter = (new ReflectionMethod($agent, 'handleExecutionError'))->getParameters()[0];

        $this->assertSame('Throwable', $parameter->getType()?->getName());
    }

    public function test_the_base_class_requires_a_throwable_handler(): void
    {
        $parameter = (new ReflectionMethod(PlatformExecutionAgent::class, 'handleExecutionError'))->getParameters()[0];

        $this->assertSame('Throwable', $parameter->getType()?->getName());
    }

    public function test_a_recovery_plan_can_be_built_from_an_error_not_only_an_exception(): void
    {
        // \Error is the case that mattered and the one the old signature refused.
        $plan = RecoveryPlan::simple(
            new \TypeError('argument must be string, array given'),
            'Check the creative payload',
            'A wrong argument type usually means a service contract changed.'
        );

        $this->assertInstanceOf(RecoveryPlan::class, $plan);
        $this->assertStringContainsString('array given', $plan->error?->getMessage() ?? '');
    }

    public function test_from_json_takes_the_error_alongside_the_payload(): void
    {
        // Called with the json alone, this raised ArgumentCountError — inside the
        // handler meant to rescue a failed deployment.
        $plan = RecoveryPlan::fromJson(
            new \RuntimeException('deploy failed'),
            json_encode(['recovery_actions' => ['Reconnect the account'], 'can_auto_recover' => false])
        );

        $this->assertInstanceOf(RecoveryPlan::class, $plan);
    }
}

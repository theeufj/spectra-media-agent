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
 *
 * Routing is now the template method's job, so it is asserted once on the base
 * class rather than four times by grepping subclass source. What each subclass
 * still owes is the handler itself — and, more importantly, that it has not
 * grown its own execute() again.
 */
class DeploymentRecoveryTest extends TestCase
{
    /** @return array<string, array{class-string}> */
    public static function agents(): array
    {
        return [
            'google' => [\App\Services\Agents\GoogleAdsExecutionAgent::class],
            'facebook' => [\App\Services\Agents\FacebookAdsExecutionAgent::class],
            'microsoft' => [\App\Services\Agents\MicrosoftAdsExecutionAgent::class],
            'linkedin' => [\App\Services\Agents\LinkedInAdsExecutionAgent::class],
        ];
    }

    public function test_execute_routes_failures_through_recovery(): void
    {
        // Reading the source is crude, but it is the property that actually
        // matters and the one that was missing: the catch block has to reach
        // handleExecutionError rather than let the throwable escape. There is
        // now one catch block to check instead of four.
        $source = file_get_contents((new \ReflectionClass(PlatformExecutionAgent::class))->getFileName());

        $this->assertStringContainsString(
            '$this->handleExecutionError(',
            $source,
            'the template method defines a recovery handler it never calls'
        );
        $this->assertStringContainsString(
            'catch (\Throwable $e)',
            $source,
            'the template must catch \Throwable — \Exception lets a TypeError past'
        );
        $this->assertStringContainsString(
            'report($e);',
            $source,
            'deploy failures must reach the admin exception dashboard'
        );
    }

    /**
     * @dataProvider agents
     *
     * @param  class-string  $agent
     */
    public function test_the_agent_does_not_reimplement_the_execution_flow(string $agent): void
    {
        // The four copies of execute() drifted: two caught \Exception instead of
        // \Throwable, two never called report(), and each read validation
        // through a different accessor. The flow belongs to the base class.
        $declaring = (new ReflectionMethod($agent, 'execute'))->getDeclaringClass()->getName();

        $this->assertSame(
            PlatformExecutionAgent::class,
            $declaring,
            $agent.' has its own execute() again — the drift this refactor removed'
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
        $type = (new ReflectionMethod($agent, 'handleExecutionError'))->getParameters()[0]->getType();

        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame('Throwable', $type->getName());
    }

    public function test_the_base_class_requires_a_throwable_handler(): void
    {
        $type = (new ReflectionMethod(PlatformExecutionAgent::class, 'handleExecutionError'))->getParameters()[0]->getType();

        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame('Throwable', $type->getName());
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

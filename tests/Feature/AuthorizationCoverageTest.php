<?php

namespace Tests\Feature;

use App\Models\Concerns\BelongsToCustomer;
use App\Models\Scopes\CustomerScope;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Tests\TestCase;

/**
 * Tenancy has to be structural, not remembered.
 *
 * Before this existed there were 298 web routes, 91 controllers, eight policies
 * and twenty `authorize()` calls between them — with four different ownership
 * idioms in use (a policy, a base-controller helper, an inline pivot query, and
 * implicit query scoping) and one registered policy that nothing ever invoked.
 * Whether a given action checked ownership depended on who wrote it.
 *
 * These tests assert the structure instead: every customer-owned model is
 * scoped, every one has a policy, and the scope actually filters.
 */
class AuthorizationCoverageTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    private function customerOwnedModels(): array
    {
        $models = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            if (in_array(BelongsToCustomer::class, class_uses_recursive($class), true)) {
                $models[] = $class;
            }
        }

        sort($models);

        return $models;
    }

    public function test_the_customer_owned_models_are_discoverable(): void
    {
        // A guard on the guard: if the trait were renamed or dropped, every
        // assertion below would pass vacuously.
        $this->assertGreaterThan(20, count($this->customerOwnedModels()));
    }

    public function test_every_customer_owned_model_has_a_policy(): void
    {
        $missing = [];

        foreach ($this->customerOwnedModels() as $model) {
            if (Gate::getPolicyFor($model) === null) {
                $missing[] = $model;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'These models belong to a Customer but have no policy, so their',
            'authorization is whatever each controller remembered to write.',
            'Add a class extending CustomerOwnedPolicy, or list the model in',
            'AppServiceProvider::GENERIC_CUSTOMER_OWNED_MODELS.',
        ]));
    }

    public function test_every_customer_owned_model_carries_the_tenant_scope(): void
    {
        $unscoped = [];

        foreach ($this->customerOwnedModels() as $model) {
            if (! array_key_exists(CustomerScope::class, (new $model)->getGlobalScopes())) {
                $unscoped[] = $model;
            }
        }

        $this->assertSame([], $unscoped, 'BelongsToCustomer must register CustomerScope');
    }

    public function test_every_customer_owned_model_actually_has_the_column(): void
    {
        $missing = [];

        foreach ($this->customerOwnedModels() as $model) {
            $instance = new $model;

            if (! $instance->getConnection()->getSchemaBuilder()->hasColumn($instance->getTable(), 'customer_id')) {
                $missing[] = $model;
            }
        }

        $this->assertSame([], $missing, 'the scope filters on customer_id, so the column has to exist');
    }

    public function test_the_scope_is_inert_without_an_authenticated_user(): void
    {
        // Queue workers and scheduled commands have no acting user. The nightly
        // batch jobs iterate every customer by design, and would silently
        // process nothing if the scope engaged there.
        $this->assertNull(CustomerScope::visibleCustomerIds());
    }

    public function test_no_route_model_binds_a_customer_owned_model_without_auth_middleware(): void
    {
        $owned = array_map(
            fn (string $class) => (new ReflectionClass($class))->getShortName(),
            $this->customerOwnedModels(),
        );

        $unprotected = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if (in_array('auth', $middleware, true) || in_array('auth:sanctum', $middleware, true)) {
                continue;
            }

            foreach ($route->signatureParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof \ReflectionNamedType && in_array(class_basename($type->getName()), $owned, true)) {
                    $unprotected[] = $route->methods()[0].' '.$route->uri().' binds '.class_basename($type->getName());
                }
            }
        }

        $this->assertSame([], $unprotected, implode("\n", [
            'These routes resolve a customer-owned model from the URL without',
            'requiring authentication. The tenant scope only engages for an',
            'authenticated user, so an unauthenticated binding reads across all',
            'tenants.',
        ]));
    }
}

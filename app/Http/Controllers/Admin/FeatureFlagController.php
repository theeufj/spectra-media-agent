<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Laravel\Pennant\Feature;

/**
 * Admin view over the Pennant flags in app/Features/.
 *
 * Pennant hands resolve() whatever Feature::for() was given, so evaluating a
 * flag against the wrong scope is a TypeError rather than a false. This page
 * used to ask every flag about every User, and AutoHealing/AutoOptimization
 * are typed for Customer — it 500'd before it rendered its first row. Worse,
 * had it rendered, its per-user toggles would have stored values against a
 * scope no runtime check reads: SelfHealingAgent, PauseWastefulAdGroups and
 * RecommendationApplier all ask `Feature::for($customer)`.
 *
 * So every flag is listed, and toggled, only against the scope its own
 * resolver declares — decided by Pennant's own isResolverValidForScope() so
 * the answer here cannot drift from the answer at the call site.
 */
class FeatureFlagController extends Controller
{
    /**
     * List all defined feature flags and their current state.
     */
    public function index()
    {
        $features = $this->discoverFeatures();

        $userFeatures = $this->featuresScopedTo($features, new User);
        $customerFeatures = $this->featuresScopedTo($features, new Customer);

        $users = User::select('id', 'name', 'email')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->hasRole('admin'),
                'plan' => optional($user->resolveCurrentPlan())->name,
                'flags' => $this->flagsFor($userFeatures, $user),
            ]);

        $customers = Customer::select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'flags' => $this->flagsFor($customerFeatures, $customer),
            ]);

        return Inertia::render('Admin/FeatureFlags', [
            'features' => $userFeatures,
            'users' => $users,
            'customerFeatures' => $customerFeatures,
            'customers' => $customers,
        ]);
    }

    /**
     * Toggle a feature for a specific user, a specific customer, or globally.
     */
    public function toggle(Request $request, string $feature)
    {
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'customer_id' => 'nullable|exists:customers,id',
            'active' => 'required|boolean',
        ]);

        $featureClass = $this->resolveFeatureClass($feature);

        if (! $featureClass) {
            return $this->flash('error', "Feature '{$feature}' not found.");
        }

        $active = $validated['active'];
        $action = $active ? 'activated' : 'deactivated';

        $scope = match (true) {
            (bool) ($validated['user_id'] ?? null) => User::findOrFail($validated['user_id']),
            (bool) ($validated['customer_id'] ?? null) => Customer::findOrFail($validated['customer_id']),
            default => null,
        };

        if ($scope === null) {
            // Global toggle. Pennant rewrites the stored rows rather than the
            // resolver, so this moves everyone who already has a value.
            if ($active) {
                Feature::activateForEveryone($featureClass);
            } else {
                Feature::deactivateForEveryone($featureClass);
            }

            return $this->flash('success', "Feature '{$feature}' {$action} globally.");
        }

        // Refuse rather than write a value nothing will ever read back: a flag
        // resolved per customer, stored against a user, is silently inert.
        if (! Feature::isResolverValidForScope($featureClass, $scope)) {
            return $this->flash('error', sprintf(
                "Feature '%s' is not resolved per %s — toggling it there would store a value no runtime check reads.",
                $feature,
                class_basename($scope),
            ));
        }

        if ($active) {
            Feature::for($scope)->activate($featureClass);
        } else {
            Feature::for($scope)->deactivate($featureClass);
        }

        return $this->flash('success', "Feature '{$feature}' {$action} for {$scope->name}.");
    }

    /**
     * Purge cached feature flag state (force re-evaluation).
     */
    public function purge(Request $request, string $feature)
    {
        $featureClass = $this->resolveFeatureClass($feature);

        if ($featureClass) {
            Feature::purge($featureClass);
        }

        return $this->flash('success', "Feature '{$feature}' cache purged. Next check will re-evaluate.");
    }

    /**
     * Discover all feature classes in app/Features/.
     */
    private function discoverFeatures(): array
    {
        $path = app_path('Features');

        if (! File::isDirectory($path)) {
            return [];
        }

        $features = [];
        foreach (File::files($path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = 'App\\Features\\'.$file->getFilenameWithoutExtension();

            // A class Pennant cannot resolve is not a flag; asking it for a
            // value would throw on a page whose whole job is to show values.
            if (! class_exists($className) || ! method_exists($className, 'resolve')) {
                continue;
            }

            $features[] = [
                'name' => $file->getFilenameWithoutExtension(),
                'class' => $className,
            ];
        }

        return $features;
    }

    /**
     * The features whose resolver accepts this kind of scope.
     */
    private function featuresScopedTo(array $features, Model $prototype): array
    {
        return array_values(array_filter(
            $features,
            fn (array $feature) => Feature::isResolverValidForScope($feature['class'], $prototype),
        ));
    }

    /**
     * Resolve every given feature against one scope, keyed by class name.
     */
    private function flagsFor(array $features, Model $scope): array
    {
        $flags = [];

        foreach ($features as $feature) {
            $flags[$feature['class']] = Feature::for($scope)->active($feature['class']);
        }

        return $flags;
    }

    /**
     * Resolve a feature slug to its fully qualified class name.
     */
    private function resolveFeatureClass(string $slug): ?string
    {
        $class = 'App\\Features\\'.$slug;

        return class_exists($class) ? $class : null;
    }

    private function flash(string $type, string $message)
    {
        return redirect()->back()->with('flash', [
            'type' => $type,
            'message' => $message,
        ]);
    }
}

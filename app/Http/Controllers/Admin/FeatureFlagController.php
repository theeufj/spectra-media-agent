<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Laravel\Pennant\Feature;

class FeatureFlagController extends Controller
{
    /**
     * List all defined feature flags and their current state.
     */
    public function index()
    {
        $features = $this->discoverFeatures();

        $users = User::select('id', 'name', 'email')
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($features) {
                $flags = [];
                foreach ($features as $feature) {
                    $flags[$feature['class']] = Feature::for($user)->active($feature['class']);
                }
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_admin' => $user->hasRole('admin'),
                    'plan' => optional($user->resolveCurrentPlan())->name,
                    'flags' => $flags,
                ];
            });

        return Inertia::render('Admin/FeatureFlags', [
            'features' => $features,
            'users' => $users,
        ]);
    }

    /**
     * Toggle a feature for a specific user or globally.
     */
    public function toggle(Request $request, string $feature)
    {
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'active' => 'required|boolean',
        ]);

        $featureClass = $this->resolveFeatureClass($feature);

        if (!$featureClass) {
            return redirect()->back()->with('flash', [
                'type' => 'error',
                'message' => "Feature '{$feature}' not found.",
            ]);
        }

        if ($validated['user_id']) {
            $user = User::findOrFail($validated['user_id']);
            if ($validated['active']) {
                Feature::for($user)->activate($featureClass);
            } else {
                Feature::for($user)->deactivate($featureClass);
            }
            $action = $validated['active'] ? 'activated' : 'deactivated';
            $message = "Feature '{$feature}' {$action} for {$user->name}.";
        } else {
            // Global toggle
            if ($validated['active']) {
                Feature::activateForEveryone($featureClass);
            } else {
                Feature::deactivateForEveryone($featureClass);
            }
            $action = $validated['active'] ? 'activated' : 'deactivated';
            $message = "Feature '{$feature}' {$action} globally.";
        }

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => $message,
        ]);
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

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => "Feature '{$feature}' cache purged. Next check will re-evaluate.",
        ]);
    }

    /**
     * Discover all feature classes in app/Features/.
     */
    private function discoverFeatures(): array
    {
        $path = app_path('Features');

        if (!File::isDirectory($path)) {
            return [];
        }

        $features = [];
        foreach (File::files($path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = 'App\\Features\\' . $file->getFilenameWithoutExtension();
            if (class_exists($className)) {
                $features[] = [
                    'name' => $file->getFilenameWithoutExtension(),
                    'class' => $className,
                ];
            }
        }

        return $features;
    }

    /**
     * Resolve a feature slug to its fully qualified class name.
     */
    private function resolveFeatureClass(string $slug): ?string
    {
        $class = 'App\\Features\\' . $slug;
        return class_exists($class) ? $class : null;
    }
}

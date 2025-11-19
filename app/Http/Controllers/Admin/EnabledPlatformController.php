<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EnabledPlatform;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EnabledPlatformController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $platforms = EnabledPlatform::ordered()->get();

        return Inertia::render('Admin/Platforms/Index', [
            'platforms' => $platforms,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Admin/Platforms/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:enabled_platforms,name',
            'slug' => 'required|string|max:255|unique:enabled_platforms,slug',
            'description' => 'nullable|string',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ]);

        EnabledPlatform::create($validated);

        return redirect()->route('admin.platforms.index')->with('success', 'Platform created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(EnabledPlatform $platform)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(EnabledPlatform $platform)
    {
        return Inertia::render('Admin/Platforms/Edit', [
            'platform' => $platform,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, EnabledPlatform $platform)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:enabled_platforms,name,' . $platform->id,
            'slug' => 'required|string|max:255|unique:enabled_platforms,slug,' . $platform->id,
            'description' => 'nullable|string',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ]);

        $platform->update($validated);

        return redirect()->route('admin.platforms.index')->with('success', 'Platform updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(EnabledPlatform $platform)
    {
        $platform->delete();

        return redirect()->route('admin.platforms.index')->with('success', 'Platform deleted successfully.');
    }

    /**
     * Toggle platform enabled status.
     */
    public function toggle(EnabledPlatform $platform)
    {
        $platform->update(['is_enabled' => !$platform->is_enabled]);

        return redirect()->back()->with('success', 'Platform status updated successfully.');
    }
}

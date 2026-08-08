<?php

namespace App\Http\Controllers;

/**
 * Entry point for the admin area.
 *
 * This class previously held 33 methods spanning users, customers, credit
 * ledgers, campaigns, platform credentials, notification templates and settings.
 * Those now live in focused controllers under App\Http\Controllers\Admin.
 */
class AdminController extends Controller
{
    public function index()
    {
        return redirect()->route('admin.users.index');
    }
}

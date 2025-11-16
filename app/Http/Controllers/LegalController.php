<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LegalController extends Controller
{
    /**
     * Show the terms of service page.
     *
     * @return \Inertia\Response
     */
    public function terms()
    {
        return \Inertia\Inertia::render('Legal/Terms');
    }

    /**
     * Show the privacy policy page.
     *
     * @return \Inertia\Response
     */
    public function privacy()
    {
        return \Inertia\Inertia::render('Legal/Privacy');
    }
}

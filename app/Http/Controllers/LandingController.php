<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LandingController extends Controller
{
    /**
     * Show the application's landing page.
     *
     * @return \Inertia\Response
     */
    public function index()
    {
        return \Inertia\Inertia::render('Landing');
    }
}

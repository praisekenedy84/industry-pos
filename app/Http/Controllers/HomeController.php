<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response|RedirectResponse
    {
        if (app()->bound('makerly.host') && app('makerly.host') === 'tenant') {
            return auth()->check()
                ? redirect()->route('dashboard')
                : redirect()->route('login');
        }

        return Inertia::render('Welcome');
    }
}

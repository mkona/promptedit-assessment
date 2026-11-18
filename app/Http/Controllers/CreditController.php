<?php

namespace App\Http\Controllers;

use App\Billing\Credits;
use App\Support\Nanodollars;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CreditController extends Controller
{
    public const PACKAGES = [1, 5, 20];

    public function store(Request $request, Credits $credits): RedirectResponse
    {
        $validated = $request->validate([
            'dollars' => ['required', 'integer', Rule::in(self::PACKAGES)],
        ]);

        $credits->purchase($request->user(), $validated['dollars'] * Nanodollars::PER_DOLLAR);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Added \${$validated['dollars']} in credits."]);

        return back();
    }
}

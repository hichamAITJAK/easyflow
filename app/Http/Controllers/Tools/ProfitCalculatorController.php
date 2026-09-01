<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class ProfitCalculatorController extends Controller
{
    /**
     * Standalone per-unit profit calculator: every figure is entered by the
     * user and computed client-side, so this only renders the page.
     */
    public function index(): Response
    {
        return Inertia::render('profit-calculator/index');
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\Locale;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /**
     * Remember the chosen language — on the account when there is one, and
     * always in a cookie so the login page itself can be read in it.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::enum(Locale::class)],
        ]);

        $locale = Locale::from($data['locale']);

        $request->user()?->forceFill(['locale' => $locale->value])->save();

        return back()->withCookie(cookie(SetLocale::COOKIE, $locale->value, 60 * 24 * 365));
    }
}

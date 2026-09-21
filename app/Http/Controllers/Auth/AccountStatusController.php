<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class AccountStatusController extends Controller
{
    private const REASONS = [
        // 'mobile-only-role' => [
        //     'title' => 'Use the mobile app to sign in',
        //     'message' => 'Fulfilment agents work from the EasyFlow mobile app. Sign in there with the same email and password.',
        // ],
        'user-disabled' => [
            'title' => 'Your account has been disabled',
            'message' => 'An administrator has disabled your account. Contact your workspace admin if you think this is a mistake.',
        ],
        'user-invited' => [
            'title' => 'Finish setting up your account',
            'message' => 'Your account has been created but is not active yet. Check your email for an invitation to finish setting up your password.',
        ],
        'business-suspended' => [
            'title' => 'This workspace is suspended',
            'message' => 'Access to this workspace has been suspended. Contact your workspace admin for details.',
        ],
        'business-cancelled' => [
            'title' => 'This workspace is no longer active',
            'message' => 'This workspace has been cancelled and is no longer accessible.',
        ],
    ];

    /**
     * Show why the user was blocked from logging in.
     */
    public function show(Request $request): Response
    {
        $reason = $request->string('reason')->value();

        abort_unless(array_key_exists($reason, self::REASONS), HttpResponse::HTTP_NOT_FOUND);

        return Inertia::render('auth/account-status', [
            'reason' => $reason,
            ...self::REASONS[$reason],
        ]);
    }
}

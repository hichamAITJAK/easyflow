<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\BusinessProfileUpdateRequest;
use App\Models\Business;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Business-level settings — the values that apply across the whole
 * business rather than to one user. Currently the business's own identity
 * details, which print on the commission invoices it issues.
 */
class BusinessController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/business', [
            'business' => $request->user()->business->only([
                'name', 'legal_name', 'logo', 'ice', 'rc', 'if_number',
                'phone', 'email', 'address', 'city',
            ]),
        ]);
    }

    /**
     * Update the business's own identity details. These print on commission
     * invoices, which is why they live here rather than being super-admin
     * data: the business owns its own letterhead.
     */
    public function updateProfile(BusinessProfileUpdateRequest $request): RedirectResponse
    {
        $business = $request->user()->business;
        $data = $request->validated();

        $business->fill([
            'name' => $data['name'],
            'legal_name' => $data['legal_name'] ?? null,
            'ice' => $data['ice'] ?? null,
            'rc' => $data['rc'] ?? null,
            'if_number' => $data['if_number'] ?? null,
            'phone' => PhoneNumber::format($data['phone'] ?? null),
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
        ]);

        if ($request->hasFile('logo')) {
            $this->deleteLogo($business);
            $business->logo = $request->file('logo')->store('business-logos', 'public');
        } elseif ($request->boolean('remove_logo')) {
            $this->deleteLogo($business);
            $business->logo = null;
        }

        $business->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Business details updated.'),
        ]);

        return back();
    }

    /**
     * Delete the stored logo file. Reads the raw column rather than the
     * accessor, which returns a public URL ("/storage/...") that no disk
     * path matches.
     */
    private function deleteLogo(Business $business): void
    {
        $path = $business->getRawOriginal('logo');

        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}

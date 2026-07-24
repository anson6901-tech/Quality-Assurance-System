<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class SecurityController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->password,
            'must_change_password' => false,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Password updated. You can now use the application.'),
        ]);

        return to_route('profile.edit');
    }
}

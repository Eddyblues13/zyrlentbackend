<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AdminProfileController extends Controller
{
    /**
     * Get the authenticated admin's profile.
     */
    public function show(Request $request)
    {
        return response()->json($request->user());
    }

    /**
     * Update admin profile (name, email).
     */
    public function update(Request $request)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'name'  => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255|unique:admins,email,' . $admin->id,
        ]);

        $admin->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'admin'   => $admin->fresh(),
        ]);
    }

    /**
     * Update admin password.
     */
    public function updatePassword(Request $request)
    {
        $admin = $request->user();

        $request->validate([
            'current_password' => 'required',
            'password'         => ['required', 'confirmed', Password::min(8)],
        ]);

        if (!Hash::check($request->current_password, $admin->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $admin->update([
            'password' => $request->password,
        ]);

        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }
}

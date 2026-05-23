<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class DeveloperApiAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Try getting API key from Bearer token
        $apiKey = $request->bearerToken();

        // If not found, try getting from query parameter or request body 'api_key'
        if (!$apiKey) {
            $apiKey = $request->input('api_key');
        }

        // 5SIM API can also use Authorization header directly or as query parameters
        if (!$apiKey) {
            return response()->json(['message' => 'Unauthorized: API Key is required.'], 401);
        }

        // Find the user associated with this key
        $user = User::where('api_key', $apiKey)->first();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized: Invalid API Key.'], 401);
        }

        // Check if user is suspended
        if ($user->is_suspended) {
            return response()->json(['message' => 'Forbidden: Your account has been suspended.'], 403);
        }

        // Log in the user for this request context (sets auth()->user())
        Auth::login($user);

        // Update the last active timestamp (without firing events)
        $user->timestamps = false;
        $user->update(['last_active_at' => now()]);
        $user->timestamps = true;

        return $next($request);
    }
}

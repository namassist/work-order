<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a user who was deactivated while logged in.
 *
 * Soft-deleted users need no check here: the user provider no longer finds
 * them, so their session is already unauthenticated.
 */
class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login')->with('status', __('Akun Anda telah dinonaktifkan.'));
        }

        return $next($request);
    }
}

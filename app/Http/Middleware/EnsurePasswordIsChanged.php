<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a user who still has the default password on the Security page
 * until they choose their own.
 */
class EnsurePasswordIsChanged
{
    /**
     * Routes the user needs to change their password or leave.
     *
     * @var list<string>
     */
    private const array ALLOWED_ROUTES = [
        'security.edit',
        'user-password.update',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'logout',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->must_change_password || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        Inertia::flash('toast', ['type' => 'warning', 'message' => __('Ganti password default Anda sebelum melanjutkan.')]);

        return to_route('security.edit');
    }
}

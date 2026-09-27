<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a self-registered account that is pending or rejected on its status
 * page (FLOW.md §3): it may sign in, but every other route redirects there,
 * except logout.
 */
class EnsureAccountIsApproved
{
    /**
     * Routes an account under review may use.
     *
     * @var list<string>
     */
    private const array ALLOWED_ROUTES = [
        'registration.status',
        'logout',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->isApproved() || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        return to_route('registration.status');
    }
}

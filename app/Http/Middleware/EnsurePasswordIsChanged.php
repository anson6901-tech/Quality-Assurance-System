<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->user()?->must_change_password
            && ! $request->routeIs('profile.edit', 'user-password.update', 'logout')
        ) {
            return to_route('profile.edit')
                ->with('status', 'password-change-required');
        }

        return $next($request);
    }
}

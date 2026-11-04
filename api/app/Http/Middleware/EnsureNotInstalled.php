<?php

namespace App\Http\Middleware;

use App\Services\Installer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shuts the installer once it has been used.
 *
 * An installer that stays reachable is a way for a stranger to drop the
 * database and make themselves an administrator, so this is the single gate
 * every install route passes through.
 *
 * A flat 404, not a 403 or an explanatory page: a system that answers
 * "already installed" is telling an anonymous visitor what software it runs
 * and that there is an installer to come back to if they ever manage to
 * remove the lock.
 */
class EnsureNotInstalled
{
    public function __construct(private readonly Installer $installer) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->installer->isInstalled(), 404);

        return $next($request);
    }
}

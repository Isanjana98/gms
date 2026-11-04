<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Biometric\DeviceDriverFactory;
use App\Support\GymSettings;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Both hold per-request caches (settings map, driver instances) that
        // are pointless if a fresh object is built at every injection point.
        $this->app->singleton(GymSettings::class);
        $this->app->singleton(DeviceDriverFactory::class);
    }

    public function boot(): void
    {
        /**
         * SRS 3 - The Super Administrator has full access by definition.
         *
         * Granted here rather than by assigning every permission, so a
         * feature added next month is covered without a migration. Returning
         * null rather than false for everyone else lets the normal permission
         * checks run.
         */
        Gate::before(fn (User $user) => $user->hasRole(Permissions::ROLE_SUPER_ADMIN) ? true : null);

        // Fail loudly in development when a relation is used without being
        // loaded: at 10,000 members (SRS 30) an N+1 in a list endpoint is the
        // difference between a fast page and an unusable one.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Mass-assigning an attribute the model does not declare is a bug in
        // the caller; silently dropping it hides it until data goes missing.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->enforceSessionIdleTimeout();
    }

    /**
     * SRS 26 - A session left unused for too long stops working.
     *
     * The absolute expiry on a token suits a manager's phone and is badly
     * wrong for the reception terminal: that machine sits in a public room,
     * signed in, and gets walked away from. Without this, the only thing
     * between a stranger and the member database is whether anybody remembered
     * to press Sign out.
     *
     * Hooked into Sanctum's own validity check rather than done in middleware,
     * for a reason that cost an afternoon: Sanctum stamps `last_used_at` as
     * soon as it resolves a token, so by the time any middleware runs the
     * value has already been advanced to now and the session always looks
     * fresh. This callback runs *before* that stamp, and gets the token
     * without a second query.
     *
     * The token is deleted rather than merely refused — otherwise the next
     * keystroke would bring the session back.
     */
    private function enforceSessionIdleTimeout(): void
    {
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid) {
            if (! $isValid) {
                return false;
            }

            $minutes = (int) config('gms.security.idle_timeout_minutes', 0);

            if ($minutes <= 0) {
                return true;
            }

            // A token that has never been used falls back to when it was
            // issued, so one created and abandoned still ages out.
            $lastUsed = $token->last_used_at ?? $token->created_at;

            if ($lastUsed && now()->diffInMinutes($lastUsed, absolute: true) >= $minutes) {
                $token->delete();

                return false;
            }

            return true;
        });
    }
}

<?php

namespace App\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * SRS 26 - How the session token reaches the browser.
 *
 * In an httpOnly cookie, never in localStorage. That is the whole point of
 * this class: a token in localStorage is readable by any script running on the
 * page, so a single cross-site scripting hole anywhere in the front end hands
 * an attacker a working session they can keep using from their own machine.
 * An httpOnly cookie is not readable by script at all — XSS can still make
 * requests as the user while they have the page open, but it cannot walk away
 * with the credential.
 *
 * The trade is that cookies are sent automatically, which is what CSRF
 * exploits. That is handled by SameSite plus the header check in
 * AuthenticateFromSessionCookie.
 */
class SessionCookie
{
    /**
     * The __Host- prefix is not decoration.
     *
     * A browser refuses to accept a __Host- cookie unless it is Secure, has
     * Path=/ and has no Domain attribute — which means no other host, and in
     * particular no sibling subdomain, can set it. Without it a compromised
     * blog.gym.lk can write a cookie for gym.lk and fixate a session.
     *
     * It requires HTTPS, so plain-HTTP development uses the bare name.
     */
    public function name(?Request $request = null): string
    {
        $base = (string) config('gms.security.cookie', 'gms_session');

        return $this->isSecure($request) ? '__Host-'.$base : $base;
    }

    /** The cookie carrying a freshly issued token. */
    public function make(string $token, \DateTimeInterface $expiresAt, ?Request $request = null): Cookie
    {
        return new Cookie(
            name: $this->name($request),
            value: $token,
            expire: $expiresAt,
            path: '/',
            // Null, not the app domain: an explicit Domain attribute makes the
            // cookie available to every subdomain, which is the opposite of
            // what __Host- is for.
            domain: null,
            secure: $this->isSecure($request),
            httpOnly: true,
            raw: false,
            /*
             * Lax, not Strict.
             *
             * Strict would sign the user out whenever they arrive by following
             * a link from anywhere else — including the link in a membership
             * expiry email — which in practice gets solved by users writing
             * their password on a sticky note. Lax already withholds the
             * cookie from every cross-site POST and every cross-site
             * subresource, which is what CSRF needs.
             */
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    /**
     * A companion cookie that script *is* allowed to read.
     *
     * It holds no secret — only the fact that a session exists. The front end
     * cannot see the httpOnly session cookie, so without this it has to ask
     * the server on every single page load whether anyone is signed in, and
     * anonymous visitors get a 401 on the way to the sign-in screen.
     *
     * Being forgeable does not matter: setting it by hand gets you an
     * unauthenticated request to /auth/me and a sign-in screen, because the
     * real credential is the other cookie.
     */
    public function makeHint(\DateTimeInterface $expiresAt, ?Request $request = null): Cookie
    {
        return new Cookie(
            name: $this->hintName($request),
            value: '1',
            expire: $expiresAt,
            path: '/',
            domain: null,
            secure: $this->isSecure($request),
            // The one cookie here that is deliberately readable.
            httpOnly: false,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    public function forgetHint(?Request $request = null): Cookie
    {
        return new Cookie(
            name: $this->hintName($request),
            value: '',
            expire: 1,
            path: '/',
            domain: null,
            secure: $this->isSecure($request),
            httpOnly: false,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    public function hintName(?Request $request = null): string
    {
        return $this->name($request).'_active';
    }

    /** A cookie that deletes the one above, matching name, path and flags. */
    public function forget(?Request $request = null): Cookie
    {
        return new Cookie(
            name: $this->name($request),
            value: '',
            expire: 1,
            path: '/',
            domain: null,
            secure: $this->isSecure($request),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    /**
     * Whether this connection can carry a Secure cookie.
     *
     * Keyed off the actual request rather than the environment so that a
     * production deployment behind a TLS-terminating proxy still gets Secure
     * cookies, and local HTTP development still works — a Secure cookie over
     * plain HTTP is silently dropped by the browser, which presents as
     * "signing in does nothing" with no error anywhere.
     */
    private function isSecure(?Request $request): bool
    {
        if (config('gms.security.force_secure_cookie') !== null) {
            return (bool) config('gms.security.force_secure_cookie');
        }

        return $request?->isSecure() ?? request()?->isSecure() ?? false;
    }
}

<?php

use App\Http\Controllers\Install\InstallController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Setup
|--------------------------------------------------------------------------
|
| First-run installation from a browser, for a host where there is no shell.
|
| **In its own file because it must not be in the `web` middleware group**,
| and living in routes/web.php is not a way to achieve that. `withRouting(web:
| ...)` applies that group to every route in the file it is given, and a
| `Route::middleware([...])->group()` inside it *adds* to the group rather
| than replacing it. These two routes spent their whole life with `web`
| attached, which meant:
|
|  - `EncryptCookies` resolved the encrypter, which threw
|    MissingAppKeyException, because a freshly unzipped application has no
|    .env and so no APP_KEY; and
|  - `StartSession` read the `sessions` table, which does not exist yet.
|
| Both of those are things the installer is here to create. The page that was
| supposed to explain the situation was the one page that could not load, and
| it answered a bare 500 with an empty log, because the failure happened
| before logging was configured.
|
| So this file is registered by bootstrap/app.php through `withRouting(then:
| ...)`, where nothing is applied except what is named below.
|
| Running without a session also means no CSRF token, which costs nothing
| here: these routes answer 404 the moment an installation exists, so there is
| no lasting state for a forged request to reach. The controller is written to
| match - it returns views directly and passes errors as view data, never
| `back()->withErrors()`, which would need the session this deliberately does
| not have.
|
| EnsureNotInstalled is what closes them, and it checks two independent
| things - a marker file and the database - because either alone is one
| mistake away from leaving an open door to dropping the database and creating
| an administrator.
|
*/

Route::get('/install', [InstallController::class, 'show'])->name('install.show');
Route::post('/install', [InstallController::class, 'run'])->name('install.run');

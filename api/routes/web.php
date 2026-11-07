<?php

use App\Http\Controllers\Site\HealthFormController;
use App\Http\Controllers\Site\SiteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The public website
|--------------------------------------------------------------------------
|
| Server-rendered, so a search engine indexing "gym in Colombo" is given real
| HTML rather than an empty page waiting for JavaScript, and a visitor reading
| the opening hours does not download the admin bundle to do it.
|
| Content comes from the admin panel. Anything the system already knows —
| prices, trainers, the address, the gym's name and logo — is read live from
| the same tables the admin panel writes, so there is no second copy to drift.
|
| The whole site 404s until someone switches it on under Website → Content,
| so a fresh installation never publishes placeholder copy under a real gym's
| name.
|
*/

Route::name('site.')->group(function () {
    Route::get('/', [SiteController::class, 'home'])->name('home');
    Route::get('/about', [SiteController::class, 'about'])->name('about');
    Route::get('/pricing', [SiteController::class, 'pricing'])->name('pricing');
    Route::get('/trainers', [SiteController::class, 'trainers'])->name('trainers');
    Route::get('/gallery', [SiteController::class, 'gallery'])->name('gallery');
    Route::get('/timetable', [SiteController::class, 'timetable'])->name('timetable');

    Route::get('/blog', [SiteController::class, 'blog'])->name('blog');
    // After /blog, or the literal path would be captured as a slug.
    Route::get('/blog/{slug}', [SiteController::class, 'post'])->name('post');

    Route::get('/contact', [SiteController::class, 'contact'])->name('contact');

    /*
     * Enquiries. Rate limited because this is the one public endpoint that
     * writes to the database, and an unthrottled form is a way to fill the
     * leads screen with rubbish faster than anyone can clear it.
     */
    Route::post('/contact', [SiteController::class, 'enquire'])
        ->middleware('throttle:10,10')
        ->name('enquire');
});

/*
|--------------------------------------------------------------------------
| The health form
|--------------------------------------------------------------------------
|
| Outside the site.* group on purpose: this must keep working when the public
| website is switched off. A gym that has not published a marketing site still
| screens the people it lets through the door.
|
| The token in the path is the entire authorisation — long, random, hashed at
| rest, single-use and expiring. Throttling is tighter than the contact form's
| because a token is guessable in principle and a slow brute force is the only
| realistic attack: 20 GETs a minute makes 2^256 take rather longer than the
| heat death of the universe, while never inconveniencing a real member, who
| loads this page once.
|
*/
Route::name('site.health.')->middleware('throttle:20,1')->group(function () {
    Route::get('/health/done/{reference}', [HealthFormController::class, 'done'])->name('done');
    Route::get('/health/{token}', [HealthFormController::class, 'show'])->name('show');
    Route::post('/health/{token}', [HealthFormController::class, 'submit'])->name('submit');
});

/*
|--------------------------------------------------------------------------
| The admin panel
|--------------------------------------------------------------------------
|
| The built single-page application lives in public/admin. Real files there -
| index.html, the hashed assets - are served by the web server before PHP is
| ever reached, so this route only ever sees a path that is not a file: one of
| the panel's own routes, such as /admin/members after a refresh.
|
| Doing it here rather than with Apache's FallbackResource or nginx's
| try_files means the panel behaves the same on every host, including
| `php artisan serve` and shared hosting where that configuration is not
| available. Getting it wrong is otherwise invisible until somebody presses
| reload on a page that is not the dashboard.
|
| It 404s when the panel has not been deployed, rather than 500ing on a
| missing file.
|
*/
Route::get('/admin/{path?}', function (?string $path = null) {
    $index = public_path('admin/index.html');

    abort_unless(is_file($index), 404);

    /*
     * Read rather than streamed, and explicitly private.
     *
     * response()->file() returns a BinaryFileResponse, which defaults to
     * "public" caching - wrong for the panel's entry point, which a shared
     * proxy must not hold. index.html also names the hashed asset files, so a
     * cached copy sends browsers looking for the previous build's assets
     * after a deployment and the panel loads into a blank screen.
     *
     * The file is a few hundred bytes; streaming buys nothing and a plain
     * response behaves the same everywhere.
     */
    return response(file_get_contents($index), 200, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-store, private',
    ]);
})->where('path', '.*')->name('admin');

/*
|--------------------------------------------------------------------------
| Setup
|--------------------------------------------------------------------------
|
| The installer is NOT here. It lives in routes/install.php, registered by
| bootstrap/app.php, because every route in this file is in the `web`
| middleware group - which needs an APP_KEY and a sessions table, the two
| things the installer exists to create. See that file for the full account.
|
*/

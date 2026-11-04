<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\BrandAsset;
use App\Models\Lead;
use App\Models\SitePost;
use App\Services\SiteContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as ViewResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public website.
 *
 * Server-rendered Blade rather than part of the React admin app, for two
 * reasons that both matter to a gym: a search engine indexing "gym in
 * Colombo" gets real HTML rather than an empty page waiting for JavaScript,
 * and a visitor does not download the 420KB admin bundle to read the opening
 * hours.
 */
class SiteController extends Controller
{
    public function __construct(private readonly SiteContent $content) {}

    public function home(): ViewResponse|Response
    {
        return $this->page('site.home', [
            'headline' => $this->content->headline(),
            'stats' => $this->content->stats(),
            'hero' => $this->content->heroImages()->take(5),
            'plans' => $this->content->plans()->take(3),
            'trainers' => $this->content->trainers()->take(4),
            'testimonials' => $this->content->testimonials()->take(6),
            'facilities' => $this->content->decoded('website.facilities'),
            'gallery' => $this->content->images(BrandAsset::TYPE_GALLERY)->take(8),
            'posts' => SitePost::query()->visible()->latest('published_at')->take(3)->get(),
        ]);
    }

    public function about(): ViewResponse|Response
    {
        return $this->page('site.about', [
            'title' => 'About',
            'facilities' => $this->content->decoded('website.facilities'),
            'gallery' => $this->content->images(BrandAsset::TYPE_GALLERY)->take(6),
        ]);
    }

    public function pricing(): ViewResponse|Response
    {
        return $this->page('site.pricing', [
            'title' => 'Membership prices',
            'metaDescription' => 'Membership options and prices.',
            'plans' => $this->content->plans(),
        ]);
    }

    public function trainers(): ViewResponse|Response
    {
        return $this->page('site.trainers', [
            'title' => 'Our trainers',
            'trainers' => $this->content->trainers(),
        ]);
    }

    public function gallery(): ViewResponse|Response
    {
        return $this->page('site.gallery', [
            'title' => 'Gallery',
            'gallery' => $this->content->images(BrandAsset::TYPE_GALLERY),
        ]);
    }

    public function timetable(): ViewResponse|Response
    {
        return $this->page('site.timetable', [
            'title' => 'Class timetable',
            'timetable' => $this->content->timetable(),
        ]);
    }

    public function blog(): ViewResponse|Response
    {
        return $this->page('site.blog', [
            'title' => 'News',
            'posts' => SitePost::query()->visible()->latest('published_at')->paginate(9),
        ]);
    }

    public function post(string $slug): ViewResponse|Response
    {
        // visible() rather than findOrFail on the slug alone: a draft must
        // return 404 to the public, not render because somebody guessed or
        // kept the URL from a preview.
        $post = SitePost::query()->visible()->where('slug', $slug)->firstOrFail();

        return $this->page('site.post', [
            'title' => $post->seo_title ?: $post->title,
            'metaDescription' => $post->seo_description ?: $post->excerpt,
            'ogType' => 'article',
            'ogImage' => $post->image_url,
            'post' => $post,
            'more' => SitePost::query()->visible()->whereKeyNot($post->id)->latest('published_at')->take(3)->get(),
        ]);
    }

    public function contact(): ViewResponse|Response
    {
        return $this->page('site.contact', [
            'title' => 'Contact',
            'plans' => $this->content->plans(),
            'branches' => $this->content->branches(),
            'mapEmbed' => $this->content->setting('website.map_embed'),
        ]);
    }

    /**
     * POST /contact — an enquiry becomes a lead.
     *
     * Throttled at the route. The honeypot below costs nothing and stops the
     * bulk of automated form spam, which is what would otherwise make the
     * leads screen useless within a week.
     */
    public function enquire(Request $request): RedirectResponse
    {
        if (! $this->content->isEnabled()) {
            abort(404);
        }

        // Named innocuously and hidden with CSS. A person never fills it in;
        // a bot that fills every field does.
        if (filled($request->input('website'))) {
            // Answered as though it worked, so the bot has nothing to learn.
            return redirect()->route('site.contact')->with('sent', true);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'membership_plan_id' => ['nullable', 'integer', 'exists:membership_plans,id'],
            'message' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'membership_plan_id' => 'plan',
        ]);

        if (blank($data['telephone'] ?? null) && blank($data['email'] ?? null)) {
            return back()
                ->withInput()
                ->withErrors(['telephone' => 'Leave a phone number or an email address so we can reply.']);
        }

        Lead::create([
            ...$data,
            'branch_id' => $this->content->primaryBranch()?->getKey(),
            'source' => 'WEBSITE',
            'status' => 'NEW',
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('site.contact')->with('sent', true);
    }

    /**
     * Render a page, or 404 while the site is unpublished.
     *
     * The site-wide data — name, logo, navigation — is merged in here rather
     * than shared from the constructor. `View::share()` writes into the view
     * factory, which is a singleton: the values are then bound once and do not
     * follow the data. That showed up as a gallery link that refused to appear
     * until the process restarted, because the navigation had been decided
     * before the first image was uploaded.
     *
     * Merging per render also means the page's own data wins, so a page can
     * override a shared value deliberately.
     *
     * The published check lives here too, so switching the website off cannot
     * accidentally take the API or the admin panel down with it.
     *
     * @param  array<string, mixed>  $data
     */
    private function page(string $view, array $data = []): ViewResponse|Response
    {
        if (! $this->content->isEnabled()) {
            return response()->view('site.disabled', [], 404);
        }

        return view($view, [
            // Inner pages get a banner photograph unless they set their own,
            // so no page is a flat block of colour. Null when the gym has
            // uploaded nothing, and the banner partial copes with that.
            'banner' => $this->content->heroImages()->first(),
            ...$this->content->shared(),
            ...$data,
        ]);
    }
}

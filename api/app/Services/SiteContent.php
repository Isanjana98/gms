<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BrandAsset;
use App\Models\MembershipPlan;
use App\Models\Setting;
use App\Models\Testimonial;
use App\Models\TimetableEntry;
use App\Models\Trainer;
use Illuminate\Support\Collection;

/**
 * Everything the public website needs, assembled in one place.
 *
 * The guiding rule: **anything the system already knows is read live, never
 * retyped.** Prices come from membership plans, the team from trainers, the
 * address from branches, the name and logo from branding. Change a price in
 * the admin panel and the website is already correct, because there is no
 * second copy of it to forget.
 *
 * Only what the system cannot know — the marketing copy, which photographs to
 * show, the opening hours — is stored as editable content, and that lives in
 * the existing settings table under the `website` group rather than in a new
 * one of its own.
 */
class SiteContent
{
    /** The editable copy, with the defaults a brand-new site starts from. */
    public const DEFAULTS = [
        'website.enabled' => '0',
        'website.hero_headline' => 'Train properly. Get results.',
        'website.hero_subtext' => 'Modern equipment, qualified trainers and a membership that fits how you actually train.',
        'website.hero_cta_label' => 'Join today',
        'website.about_title' => 'About us',
        'website.about_body' => 'Tell visitors who you are, how long you have been open, and what makes training here different. A few honest sentences beat a page of adjectives.',
        'website.facilities' => '[]',
        'website.opening_hours' => '[]',
        'website.social' => '{}',
        'website.contact_note' => 'Come in for a look around — no appointment needed.',
        'website.seo_title' => '',
        'website.seo_description' => '',
        'website.map_embed' => '',
        'website.show_pricing' => '1',
        'website.show_trainers' => '1',
        'website.show_gallery' => '1',
        'website.show_blog' => '1',
        'website.show_timetable' => '1',
        'website.show_testimonials' => '1',
    ];

    /**
     * Is the site published?
     *
     * Off by default, so a fresh installation does not put placeholder copy on
     * the open internet under the gym's name before anyone has written
     * anything.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->setting('website.enabled', false);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $value = Setting::get($key, null);

        return $value === null || $value === '' ? $default : $value;
    }

    /** @return array<string, mixed> */
    public function decoded(string $key): array
    {
        $value = $this->setting($key);

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The name, tagline and logo, shared with the sign-in screen.
     *
     * @return array<string, mixed>
     */
    public function branding(): array
    {
        $logo = BrandAsset::query()->active()->ofType(BrandAsset::TYPE_LOGO)->first();

        return [
            'gym_name' => Setting::get('branding.gym_name', config('app.name')),
            'tagline' => Setting::get('branding.tagline', ''),
            'logo_url' => $logo?->url,
        ];
    }

    /** The gym's own photographs, for the hero and the gallery. */
    public function images(string $type): Collection
    {
        return BrandAsset::query()->active()->ofType($type)->get();
    }

    /**
     * Hero imagery, falling back to the sign-in pictures.
     *
     * A gym that has already uploaded photographs for the sign-in screen
     * should not have to upload them a second time to have a website.
     */
    public function heroImages(): Collection
    {
        $hero = $this->images(BrandAsset::TYPE_HERO);

        return $hero->isNotEmpty() ? $hero : $this->images(BrandAsset::TYPE_LOGIN);
    }

    /** Live from the plans the gym actually sells. */
    public function plans(): Collection
    {
        return MembershipPlan::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get();
    }

    /** Live from the trainer records. */
    public function trainers(): Collection
    {
        return Trainer::query()
            ->where('status', 'ACTIVE')
            ->orderBy('full_name')
            ->get();
    }

    public function testimonials(): Collection
    {
        return Testimonial::query()->active()->get();
    }

    /**
     * The timetable grouped by day, Monday first.
     *
     * @return Collection<int, Collection<int, TimetableEntry>>
     */
    public function timetable(): Collection
    {
        return TimetableEntry::query()
            ->with('trainer:id,full_name')
            ->active()
            ->get()
            ->groupBy('day_of_week');
    }

    /** Where to find the gym, live from the branch records. */
    public function branches(): Collection
    {
        return Branch::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function primaryBranch(): ?Branch
    {
        return $this->branches()->first();
    }

    /**
     * Which sections to render.
     *
     * A gym with no trainers on file and no photographs should get a tidy
     * short page rather than three empty headings, so a section is shown only
     * when it is switched on *and* has something in it.
     *
     * @return array<string, bool>
     */
    public function sections(): array
    {
        return [
            'pricing' => (bool) $this->setting('website.show_pricing', true) && $this->plans()->isNotEmpty(),
            'trainers' => (bool) $this->setting('website.show_trainers', true) && $this->trainers()->isNotEmpty(),
            'gallery' => (bool) $this->setting('website.show_gallery', true) && $this->images(BrandAsset::TYPE_GALLERY)->isNotEmpty(),
            'timetable' => (bool) $this->setting('website.show_timetable', true) && TimetableEntry::query()->active()->exists(),
            'testimonials' => (bool) $this->setting('website.show_testimonials', true) && $this->testimonials()->isNotEmpty(),
            'blog' => (bool) $this->setting('website.show_blog', true),
        ];
    }

    /**
     * The headline with its last word marked for the accent colour.
     *
     * Returned as HTML, so it is escaped **here**, word by word after the
     * split — the split is on whitespace, so no word can carry markup across
     * the boundary. The view prints it unescaped and must not be handed
     * anything that has not been through this.
     */
    public function headline(): string
    {
        $text = trim($this->copy()['hero_headline']);

        if ($text === '') {
            return '';
        }

        $words = preg_split('/\s+/', $text) ?: [];
        $last = array_pop($words);

        $escaped = array_map(fn (string $word) => e($word), $words);
        $accent = '<em>'.e((string) $last).'</em>';

        return $escaped === [] ? $accent : implode(' ', $escaped).' '.$accent;
    }

    /**
     * The figures on the front page.
     *
     * All derived from real records, so they cannot become a lie that nobody
     * remembers to update. Member numbers are deliberately **not** among them:
     * how many people train here is the gym's business, and publishing a
     * figure that falls is worse than publishing none.
     *
     * A zero is omitted rather than shown — "0 trainers" is an advertisement
     * against yourself.
     *
     * @return array<int, array{value:int, label:string, suffix?:string}>
     */
    public function stats(): array
    {
        $stats = [];

        if (($trainers = $this->trainers()->count()) > 0) {
            $stats[] = ['value' => $trainers, 'label' => 'Qualified trainers'];
        }

        if (($classes = TimetableEntry::query()->active()->count()) > 0) {
            $stats[] = ['value' => $classes, 'label' => 'Classes each week'];
        }

        if (($plans = $this->plans()->count()) > 0) {
            $stats[] = ['value' => $plans, 'label' => 'Ways to join'];
        }

        return $stats;
    }

    /**
     * Everything the shared layout needs, on every page.
     *
     * @return array<string, mixed>
     */
    public function shared(): array
    {
        return [
            'branding' => $this->branding(),
            'sections' => $this->sections(),
            'branch' => $this->primaryBranch(),
            'social' => $this->decoded('website.social'),
            'openingHours' => $this->decoded('website.opening_hours'),
            'seoTitle' => $this->setting('website.seo_title'),
            'seoDescription' => $this->setting('website.seo_description'),
            'copy' => $this->copy(),
        ];
    }

    /**
     * The editable text, resolved once and handed to the views.
     *
     * Views do not reach into the Setting model themselves. Besides being the
     * wrong place for it, a `json` setting already comes back decoded, so a
     * template casting one to a string — which is what a template naturally
     * does — is an "Array to string conversion" that only shows up when
     * somebody happens to fill that field in.
     *
     * @return array<string, string>
     */
    public function copy(): array
    {
        $keys = [
            'hero_headline', 'hero_subtext', 'hero_cta_label',
            'about_title', 'about_body', 'contact_note',
        ];

        $copy = [];

        foreach ($keys as $key) {
            $value = $this->setting("website.{$key}", self::DEFAULTS["website.{$key}"] ?? '');
            $copy[$key] = is_scalar($value) ? (string) $value : '';
        }

        return $copy;
    }
}

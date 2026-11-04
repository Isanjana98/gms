<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SitePost;
use App\Models\Testimonial;
use App\Models\TimetableEntry;
use App\Services\SiteContent;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The website's editable content, from the admin panel.
 *
 * Only the copy the system cannot already know lives here. Prices, trainers,
 * the address and the gym's name are read live from their own tables by the
 * public site, so there is nothing to duplicate and nothing to keep in step.
 */
class WebsiteController extends Controller
{
    public function __construct(private readonly SiteContent $content) {}

    /** Fields that hold structured data rather than a line of text. */
    private const JSON_KEYS = ['facilities', 'opening_hours', 'social'];

    private const BOOLEAN_KEYS = [
        'enabled', 'show_pricing', 'show_trainers', 'show_gallery',
        'show_blog', 'show_timetable', 'show_testimonials',
    ];

    /** GET /api/website - everything the Website screen edits. */
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WEBSITE_MANAGE), 403);

        $content = [];

        foreach (array_keys(SiteContent::DEFAULTS) as $key) {
            $short = str_replace('website.', '', $key);
            $value = Setting::get($key);

            $content[$short] = match (true) {
                in_array($short, self::BOOLEAN_KEYS, true) => (bool) $value,
                in_array($short, self::JSON_KEYS, true) => $this->content->decoded($key),
                default => (string) ($value ?? ''),
            };
        }

        return response()->json([
            'content' => $content,
            // So the screen can say "your site is live at …" rather than
            // leaving somebody to guess the address.
            'url' => url('/'),
            'counts' => [
                'posts' => SitePost::query()->count(),
                'published_posts' => SitePost::query()->visible()->count(),
                'testimonials' => Testimonial::query()->count(),
                'timetable' => TimetableEntry::query()->count(),
            ],
        ]);
    }

    /** PUT /api/website */
    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WEBSITE_MANAGE), 403);

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'hero_headline' => ['sometimes', 'string', 'max:150'],
            'hero_subtext' => ['sometimes', 'string', 'max:500'],
            'hero_cta_label' => ['sometimes', 'string', 'max:50'],
            'about_title' => ['sometimes', 'string', 'max:150'],
            'about_body' => ['sometimes', 'string', 'max:8000'],
            'contact_note' => ['sometimes', 'string', 'max:500'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:70'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:200'],
            // Restricted to a URL so the field cannot be used to inject an
            // arbitrary iframe into a page on our own origin.
            'map_embed' => ['sometimes', 'nullable', 'url', 'max:1000'],

            'facilities' => ['sometimes', 'array', 'max:12'],
            'facilities.*.title' => ['required', 'string', 'max:100'],
            'facilities.*.description' => ['nullable', 'string', 'max:300'],

            'opening_hours' => ['sometimes', 'array', 'max:10'],
            'opening_hours.*.days' => ['required', 'string', 'max:60'],
            'opening_hours.*.hours' => ['required', 'string', 'max:60'],

            'social' => ['sometimes', 'array'],
            'social.*' => ['nullable', 'url', 'max:300'],

            'show_pricing' => ['sometimes', 'boolean'],
            'show_trainers' => ['sometimes', 'boolean'],
            'show_gallery' => ['sometimes', 'boolean'],
            'show_blog' => ['sometimes', 'boolean'],
            'show_timetable' => ['sometimes', 'boolean'],
            'show_testimonials' => ['sometimes', 'boolean'],
        ]);

        foreach ($data as $short => $value) {
            $type = match (true) {
                in_array($short, self::BOOLEAN_KEYS, true) => 'boolean',
                in_array($short, self::JSON_KEYS, true) => 'json',
                default => 'string',
            };

            Setting::set("website.{$short}", $value ?? '', null, $type);
        }

        return $this->ok(
            array_key_exists('enabled', $data)
                ? ($data['enabled'] ? 'The website is now live.' : 'The website has been taken offline.')
                : 'Website content saved.',
        );
    }

    // ---------------------------------------------------------------- posts

    /** GET /api/website/posts */
    public function posts(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WEBSITE_MANAGE), 403);

        $posts = SitePost::query()
            ->with('author:id,name')
            ->latest('created_at')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => $posts->getCollection()->map(fn (SitePost $post) => $this->postPayload($post))->all(),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /** POST /api/website/posts */
    public function storePost(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WEBSITE_MANAGE), 403);

        $data = $request->validate($this->postRules());

        $post = SitePost::create([
            ...$data,
            'slug' => SitePost::uniqueSlug($data['slug'] ?? $data['title']),
            'author_id' => $request->user()->getKey(),
            'published_at' => $this->publishedAt($data),
        ]);

        return response()->json([
            'message' => "\"{$post->title}\" saved.",
            'data' => $this->postPayload($post),
        ], 201);
    }

    /** PUT /api/website/posts/{post} */
    public function updatePost(Request $request, SitePost $post): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WEBSITE_MANAGE), 403);

        $data = $request->validate($this->postRules($post->id));

        $post->update([
            ...$data,
            'slug' => SitePost::uniqueSlug($data['slug'] ?? $data['title'], $post->id),
            'published_at' => $this->publishedAt($data, $post),
        ]);

        return response()->json([
            'message' => "\"{$post->title}\" saved.",
            'data' => $this->postPayload($post->refresh()),
        ]);
    }

    /** DELETE /api/website/posts/{post} */
    public function destroyPost(Request $request, SitePost $post): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WEBSITE_MANAGE), 403);

        $title = $post->title;
        $post->delete();

        return $this->ok("\"{$title}\" deleted.");
    }

    // --------------------------------------------------------- testimonials

    /** GET /api/website/testimonials */
    public function testimonials(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WEBSITE_MANAGE), 403);

        return response()->json([
            'data' => Testimonial::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (Testimonial $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'role' => $t->role,
                'quote' => $t->quote,
                'rating' => $t->rating,
                'is_active' => $t->is_active,
                'sort_order' => $t->sort_order,
            ])->all(),
        ]);
    }

    /** POST /api/website/testimonials */
    public function storeTestimonial(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WEBSITE_MANAGE), 403);

        $testimonial = Testimonial::create($request->validate($this->testimonialRules()));

        return $this->ok("Testimonial from {$testimonial->name} added.", ['id' => $testimonial->id], 201);
    }

    /** PUT /api/website/testimonials/{testimonial} */
    public function updateTestimonial(Request $request, Testimonial $testimonial): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WEBSITE_MANAGE), 403);

        $testimonial->update($request->validate($this->testimonialRules()));

        return $this->ok('Testimonial saved.');
    }

    /** DELETE /api/website/testimonials/{testimonial} */
    public function destroyTestimonial(Request $request, Testimonial $testimonial): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WEBSITE_MANAGE), 403);

        $testimonial->delete();

        return $this->ok('Testimonial deleted.');
    }

    // ------------------------------------------------------------ timetable

    /** GET /api/website/timetable */
    public function timetable(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WEBSITE_MANAGE), 403);

        return response()->json([
            'data' => TimetableEntry::query()
                ->with('trainer:id,full_name')
                ->orderBy('day_of_week')
                ->orderBy('start_time')
                ->get()
                ->map(fn (TimetableEntry $entry) => [
                    'id' => $entry->id,
                    'class_name' => $entry->class_name,
                    'description' => $entry->description,
                    'day_of_week' => $entry->day_of_week,
                    'day_name' => $entry->day_name,
                    // Trimmed to HH:MM: a time input refuses a value with
                    // seconds on it and silently shows blank instead.
                    'start_time' => substr((string) $entry->start_time, 0, 5),
                    'end_time' => substr((string) $entry->end_time, 0, 5),
                    'room' => $entry->room,
                    'trainer_id' => $entry->trainer_id,
                    'instructor' => $entry->instructor,
                    'is_active' => $entry->is_active,
                ])->all(),
            'days' => TimetableEntry::DAYS,
        ]);
    }

    /** POST /api/website/timetable */
    public function storeTimetableEntry(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WEBSITE_MANAGE), 403);

        $entry = TimetableEntry::create([
            ...$request->validate($this->timetableRules()),
            'branch_id' => $this->defaultBranchId($request),
        ]);

        return $this->ok("{$entry->class_name} added to the timetable.", ['id' => $entry->id], 201);
    }

    /** PUT /api/website/timetable/{entry} */
    public function updateTimetableEntry(Request $request, TimetableEntry $entry): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WEBSITE_MANAGE), 403);

        $entry->update($request->validate($this->timetableRules()));

        return $this->ok('Timetable saved.');
    }

    /** DELETE /api/website/timetable/{entry} */
    public function destroyTimetableEntry(Request $request, TimetableEntry $entry): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::WEBSITE_MANAGE), 403);

        $name = $entry->class_name;
        $entry->delete();

        return $this->ok("{$name} removed from the timetable.");
    }

    // ---------------------------------------------------------------- rules

    /** @return array<string, mixed> */
    private function postRules(?int $ignoreId = null): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9\-]+$/', Rule::unique('site_posts', 'slug')->ignore($ignoreId)],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:50000'],
            'image_path' => ['nullable', 'string', 'max:512'],
            'is_published' => ['nullable', 'boolean'],
            'published_at' => ['nullable', 'date'],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, mixed> */
    private function testimonialRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
            'quote' => ['required', 'string', 'max:1000'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, mixed> */
    private function timetableRules(): array
    {
        return [
            'class_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'day_of_week' => ['required', 'integer', 'min:1', 'max:7'],
            'start_time' => ['required', 'date_format:H:i'],
            // A class that ends before it starts is a typo, and it renders as
            // "6:00pm – 5:00pm" on the public timetable if nobody checks.
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'room' => ['nullable', 'string', 'max:255'],
            'trainer_id' => ['nullable', 'integer', 'exists:trainers,id'],
            'instructor_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * When a post went out.
     *
     * Stamped on first publish and kept afterwards, so unpublishing to fix a
     * typo does not silently move an article to the top of the list when it
     * goes back up.
     *
     * @param  array<string, mixed>  $data
     */
    private function publishedAt(array $data, ?SitePost $existing = null): ?string
    {
        if (filled($data['published_at'] ?? null)) {
            return $data['published_at'];
        }

        if ($existing?->published_at) {
            return $existing->published_at->toDateTimeString();
        }

        return ($data['is_published'] ?? false) ? now()->toDateTimeString() : null;
    }

    /** @return array<string, mixed> */
    private function postPayload(SitePost $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            'body' => $post->body,
            'image_path' => $post->image_path,
            'image_url' => $post->image_url,
            'is_published' => $post->is_published,
            'published_at' => $post->published_at?->toIso8601String(),
            'seo_title' => $post->seo_title,
            'seo_description' => $post->seo_description,
            'author' => $post->author?->name,
            'url' => url('/blog/'.$post->slug),
        ];
    }
}

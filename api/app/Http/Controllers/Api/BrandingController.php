<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandAsset;
use App\Support\GymSettings;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The gym's name, tagline, logo and sign-in imagery.
 *
 * `show()` is deliberately public: the sign-in screen has to render the gym's
 * identity before anyone has a token, and refusing it would leave the first
 * screen a visitor sees looking like nobody's software. It returns only what
 * is already on display there — a name, a tagline and some image URLs.
 *
 * Everything that changes those requires the branding permission.
 */
class BrandingController extends Controller
{
    private const MIMES = 'jpg,jpeg,png,webp,svg';

    private const MAX_KB = 5120; // 5 MB

    /**
     * GET /api/branding — public.
     *
     * Only ever the fields the sign-in screen already shows. No counts, no
     * addresses, nothing about members: an unauthenticated endpoint should
     * return what an unauthenticated visitor can already see.
     */
    public function show(GymSettings $settings): JsonResponse
    {
        $logo = BrandAsset::query()->ofType(BrandAsset::TYPE_LOGO)->active()->first();

        $backgrounds = BrandAsset::query()
            ->ofType(BrandAsset::TYPE_LOGIN)
            ->active()
            ->get()
            ->map(fn (BrandAsset $asset) => ['url' => $asset->url, 'caption' => $asset->caption]);

        return response()->json([
            'gym_name' => $settings->get('branding.gym_name', null, config('app.name')),
            'tagline' => $settings->get('branding.tagline', null, 'Every member, every visit, in one place.'),
            'logo_url' => $logo?->url,
            'login_images' => $backgrounds->values(),
        ]);
    }

    /** PUT /api/branding — the gym's name and tagline. */
    public function update(Request $request, GymSettings $settings): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::BRANDING_MANAGE), 403);

        $data = $request->validate([
            'gym_name' => ['sometimes', 'string', 'max:100'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        if (array_key_exists('gym_name', $data)) {
            $settings->set('branding.gym_name', $data['gym_name']);
        }

        if (array_key_exists('tagline', $data)) {
            $settings->set('branding.tagline', $data['tagline'] ?? '');
        }

        return $this->ok('Branding updated.', ['data' => $this->show($settings)->getData(true)]);
    }

    /**
     * Which image types this caller may touch.
     *
     * The logo and the sign-in screen belong to whoever runs the gym; the
     * website's hero and gallery belong to whoever writes the marketing. They
     * are frequently not the same person, and holding one permission should
     * not quietly grant the other — so the split is by image type rather than
     * by endpoint.
     *
     * @return array<int, string>
     */
    private function manageableTypes(Request $request): array
    {
        $types = [];

        if ($request->user()->can(Permissions::BRANDING_MANAGE)) {
            $types[] = BrandAsset::TYPE_LOGO;
            $types[] = BrandAsset::TYPE_LOGIN;
        }

        if ($request->user()->can(Permissions::WEBSITE_MANAGE)) {
            $types[] = BrandAsset::TYPE_HERO;
            $types[] = BrandAsset::TYPE_GALLERY;
        }

        abort_if($types === [], 403);

        return $types;
    }

    /** GET /api/branding/assets — everything, including what is switched off. */
    public function index(Request $request): JsonResponse
    {
        $assets = BrandAsset::query()
            ->with('uploadedBy:id,name')
            ->whereIn('type', $this->manageableTypes($request))
            ->orderBy('type')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (BrandAsset $asset) => $this->payload($asset));

        return response()->json([
            'data' => $assets,
            'limits' => ['max_kb' => self::MAX_KB, 'formats' => explode(',', self::MIMES)],
        ]);
    }

    /**
     * POST /api/branding/assets
     *
     * A gym has one logo, so uploading a new one retires the old rather than
     * leaving two active and letting the query decide. Sign-in images are a
     * set and simply accumulate.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'mimes:'.self::MIMES, 'max:'.self::MAX_KB],
            // Also the website's hero and gallery images: same upload path,
            // same public disk, same validation. Three near-identical
            // endpoints would only give three places for the size limit to
            // drift apart. Which types this caller may use is decided below.
            'type' => ['required', Rule::in($this->manageableTypes($request))],
            'caption' => ['nullable', 'string', 'max:200'],
        ]);

        $file = $request->file('image');

        $asset = DB::transaction(function () use ($request, $data, $file) {
            if ($data['type'] === BrandAsset::TYPE_LOGO) {
                BrandAsset::query()
                    ->where('type', BrandAsset::TYPE_LOGO)
                    ->get()
                    ->each(function (BrandAsset $old) {
                        $old->deleteFile();
                        $old->delete();
                    });
            }

            $nextOrder = (int) BrandAsset::query()->where('type', $data['type'])->max('sort_order') + 1;

            return BrandAsset::create([
                'type' => $data['type'],
                // A generated filename: the original is kept for display, but
                // what lands on a public disk should not be caller-controlled.
                'path' => $file->store('branding', BrandAsset::DISK),
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
                'caption' => $data['caption'] ?? null,
                'sort_order' => $nextOrder,
                'is_active' => true,
                'uploaded_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'message' => $data['type'] === BrandAsset::TYPE_LOGO ? 'Logo updated.' : 'Image added.',
            'data' => $this->payload($asset),
        ], 201);
    }

    /** PUT /api/branding/assets/{asset} — caption, order, on/off. */
    public function updateAsset(Request $request, BrandAsset $asset): JsonResponse
    {
        abort_unless(in_array($asset->type, $this->manageableTypes($request), true), 403);

        $data = $request->validate([
            'caption' => ['sometimes', 'nullable', 'string', 'max:200'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $asset->update($data);

        return $this->ok('Image updated.', ['data' => $this->payload($asset->fresh())]);
    }

    /**
     * DELETE /api/branding/assets/{asset}
     *
     * A hard delete, unlike member documents: a decorative image is not
     * evidence, and leaving orphaned files on a public disk is worse than
     * losing one.
     */
    public function destroy(Request $request, BrandAsset $asset): JsonResponse
    {
        abort_unless(in_array($asset->type, $this->manageableTypes($request), true), 403);

        $asset->deleteFile();
        $asset->delete();

        return $this->ok('Image removed.');
    }

    /** @return array<string, mixed> */
    private function payload(BrandAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'type' => $asset->type,
            'url' => $asset->url,
            'original_filename' => $asset->original_filename,
            'size_label' => $asset->size_label,
            'caption' => $asset->caption,
            'sort_order' => $asset->sort_order,
            'is_active' => $asset->is_active,
            'uploaded_by' => $asset->uploadedBy?->name,
            'created_at' => $asset->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Member agreements, ID copies and medical clearances.
 *
 * Files are never served from a public URL. They are written to the private
 * disk and streamed back through this controller, so every read passes the
 * same permission check as everything else - a signed agreement carries a
 * signature and usually an ID number.
 */
class MemberDocumentController extends Controller
{
    private const TYPES = ['AGREEMENT', 'ID_PROOF', 'MEDICAL', 'WAIVER', 'PHOTO_CONSENT', 'OTHER'];

    /**
     * Accepted uploads.
     *
     * An allow-list, not a deny-list: an agreement is a PDF, a photograph or a
     * scan, and nothing else needs to be accepted. The extension check and the
     * MIME check are both present because either alone is forgeable.
     */
    private const MIMES = 'pdf,jpg,jpeg,png,webp,doc,docx';

    private const MAX_KB = 10240; // 10 MB

    /** GET /api/members/{member}/documents */
    public function index(Request $request, Member $member): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::DOCUMENTS_VIEW), 403);
        $this->assertSameBranch($request, $member);

        $documents = $member->documents()
            ->with('uploadedBy:id,name')
            ->when($request->filled('type'), fn ($q) => $q->ofType($request->string('type')->toString()))
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $documents->map(fn (MemberDocument $doc) => $this->payload($doc)),
            'types' => self::TYPES,
        ]);
    }

    /**
     * POST /api/members/{member}/documents
     *
     * Replacing a document keeps the previous one: `replaces_id` links the new
     * row to the old, and the old is soft-deleted rather than destroyed. A gym
     * asked which version a member signed needs to be able to answer.
     */
    public function store(Request $request, Member $member): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::DOCUMENTS_MANAGE), 403);
        $this->assertSameBranch($request, $member);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:'.self::MIMES, 'max:'.self::MAX_KB],
            'type' => ['required', Rule::in(self::TYPES)],
            'title' => ['nullable', 'string', 'max:255'],
            'signed_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:signed_on'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'replaces_id' => ['nullable', 'integer', 'exists:member_documents,id'],
        ]);

        $file = $request->file('file');

        $document = DB::transaction(function () use ($member, $request, $data, $file) {
            $previous = isset($data['replaces_id'])
                ? MemberDocument::where('member_id', $member->getKey())->find($data['replaces_id'])
                : null;

            // store() generates a random filename, so the original name cannot
            // be used to guess a path, and a member calling their file
            // "../../.env" achieves nothing.
            $path = $file->store("members/{$member->getKey()}/documents", MemberDocument::DISK);

            $document = MemberDocument::create([
                'member_id' => $member->getKey(),
                'replaces_id' => $previous?->getKey(),
                'type' => $data['type'],
                'title' => $data['title'] ?? $this->defaultTitle($data['type'], $file->getClientOriginalName()),
                'original_filename' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
                'signed_on' => $data['signed_on'] ?? null,
                'expires_on' => $data['expires_on'] ?? null,
                'notes' => $data['notes'] ?? null,
                'uploaded_by' => $request->user()->id,
            ]);

            // The superseded document is hidden but its file is kept.
            $previous?->delete();

            return $document;
        });

        return response()->json([
            'message' => "{$document->title} uploaded.",
            'data' => $this->payload($document->load('uploadedBy:id,name')),
        ], 201);
    }

    /**
     * GET /api/members/{member}/documents/{document}
     *
     * Streams the file. Inline for anything a browser can display, so
     * reception can glance at an agreement without downloading it first.
     */
    public function show(Request $request, Member $member, MemberDocument $document): StreamedResponse
    {
        abort_unless($request->user()->can(Permissions::DOCUMENTS_VIEW), 403);
        $this->assertSameBranch($request, $member);

        // Route model binding resolves the document independently of the
        // member, so the relationship has to be checked: otherwise any
        // document id could be read through any member's URL.
        abort_unless($document->member_id === $member->getKey(), 404);

        abort_unless(
            Storage::disk(MemberDocument::DISK)->exists($document->path),
            404,
            'This file is no longer on disk.',
        );

        $inline = str_starts_with($document->mime_type, 'image/') || $document->mime_type === 'application/pdf';

        return Storage::disk(MemberDocument::DISK)->response(
            $document->path,
            $document->original_filename,
            ['Content-Type' => $document->mime_type],
            $inline ? 'inline' : 'attachment',
        );
    }

    /** PUT /api/members/{member}/documents/{document} - edit the details, not the file. */
    public function update(Request $request, Member $member, MemberDocument $document): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::DOCUMENTS_MANAGE), 403);
        $this->assertSameBranch($request, $member);
        abort_unless($document->member_id === $member->getKey(), 404);

        $data = $request->validate([
            'type' => ['sometimes', Rule::in(self::TYPES)],
            'title' => ['sometimes', 'string', 'max:255'],
            'signed_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:signed_on'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $document->update($data);

        return response()->json([
            'message' => 'Document details updated.',
            'data' => $this->payload($document->fresh()->load('uploadedBy:id,name')),
        ]);
    }

    /**
     * DELETE /api/members/{member}/documents/{document}
     *
     * Soft delete, and the file stays on disk. Deleting an agreement outright
     * is the one thing a gym must not be able to do by accident.
     */
    public function destroy(Request $request, Member $member, MemberDocument $document): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::DOCUMENTS_MANAGE), 403);
        $this->assertSameBranch($request, $member);
        abort_unless($document->member_id === $member->getKey(), 404);

        $document->delete();

        return $this->ok("{$document->title} removed. The file is retained and can be restored by an administrator.");
    }

    /** @return array<string, mixed> */
    private function payload(MemberDocument $document): array
    {
        return [
            'id' => $document->id,
            'member_id' => $document->member_id,
            'replaces_id' => $document->replaces_id,
            'type' => $document->type,
            'title' => $document->title,
            'original_filename' => $document->original_filename,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'size_label' => $document->size_label,
            'signed_on' => $document->signed_on?->toDateString(),
            'expires_on' => $document->expires_on?->toDateString(),
            'is_expired' => $document->is_expired,
            'notes' => $document->notes,
            'uploaded_by' => $document->uploadedBy?->name,
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }

    private function defaultTitle(string $type, string $filename): string
    {
        return match ($type) {
            'AGREEMENT' => 'Membership agreement',
            'ID_PROOF' => 'Identity document',
            'MEDICAL' => 'Medical clearance',
            'WAIVER' => 'Liability waiver',
            'PHOTO_CONSENT' => 'Photography consent',
            default => pathinfo($filename, PATHINFO_FILENAME),
        };
    }

    /** SRS 22 - a branch-bound user stays inside their own branch. */
    private function assertSameBranch(Request $request, Member $member): void
    {
        $user = $request->user();

        abort_if(
            $user && ! $user->isMultiBranch() && $member->branch_id !== $user->branch_id,
            403,
            'This member belongs to another branch.',
        );
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\MemberDocument;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Member agreements, ID copies and profile photos. */
class MemberDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    #[Test]
    public function an_agreement_can_be_uploaded_against_a_member(): void
    {
        $member = $this->member();

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/members/{$member->id}/documents", [
                'file' => UploadedFile::fake()->create('signed-agreement.pdf', 200, 'application/pdf'),
                'type' => 'AGREEMENT',
                'signed_on' => today()->toDateString(),
                'expires_on' => today()->addYear()->toDateString(),
            ])
            ->assertCreated();

        $response->assertJsonPath('data.type', 'AGREEMENT');
        $response->assertJsonPath('data.original_filename', 'signed-agreement.pdf');
        // A title is chosen from the type when none is given.
        $response->assertJsonPath('data.title', 'Membership agreement');

        $document = MemberDocument::first();
        Storage::disk('local')->assertExists($document->path);
    }

    #[Test]
    public function documents_are_written_to_the_private_disk_not_the_public_one(): void
    {
        // The whole point: an agreement carries a signature and an ID number
        // and must not be reachable from a public URL.
        $member = $this->member();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/members/{$member->id}/documents", [
                'file' => UploadedFile::fake()->create('agreement.pdf', 50, 'application/pdf'),
                'type' => 'AGREEMENT',
            ])
            ->assertCreated();

        $document = MemberDocument::first();

        Storage::disk('local')->assertExists($document->path);
        Storage::disk('public')->assertMissing($document->path);
    }

    #[Test]
    public function the_storage_path_is_never_returned_by_the_api(): void
    {
        $member = $this->member();

        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/members/{$member->id}/documents", [
            'file' => UploadedFile::fake()->create('agreement.pdf', 50, 'application/pdf'),
            'type' => 'AGREEMENT',
        ]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/members/{$member->id}/documents")
            ->assertOk();

        $this->assertArrayNotHasKey('path', $response->json('data.0'));
    }

    #[Test]
    public function a_document_can_be_downloaded_by_someone_who_may_see_it(): void
    {
        $member = $this->member();

        $id = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/members/{$member->id}/documents", [
                'file' => UploadedFile::fake()->create('agreement.pdf', 50, 'application/pdf'),
                'type' => 'AGREEMENT',
            ])
            ->json('data.id');

        $this->actingAs($this->admin(), 'sanctum')
            ->get("/api/members/{$member->id}/documents/{$id}")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    #[Test]
    public function a_document_cannot_be_read_through_a_different_members_url(): void
    {
        // Route model binding resolves the document on its own, so without an
        // explicit ownership check any document id would be readable through
        // any member's URL.
        $owner = $this->member(['full_name' => 'Owner']);
        $other = $this->member(['full_name' => 'Someone else']);

        $id = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/members/{$owner->id}/documents", [
                'file' => UploadedFile::fake()->create('private.pdf', 50, 'application/pdf'),
                'type' => 'AGREEMENT',
            ])
            ->json('data.id');

        $this->actingAs($this->admin(), 'sanctum')
            ->get("/api/members/{$other->id}/documents/{$id}")
            ->assertNotFound();
    }

    #[Test]
    public function a_trainer_cannot_read_member_documents(): void
    {
        // A trainer needs a member's programme, not their signed agreement.
        $member = $this->member();

        $this->actingAs($this->userWithRole(Permissions::ROLE_TRAINER), 'sanctum')
            ->getJson("/api/members/{$member->id}/documents")
            ->assertForbidden();
    }

    #[Test]
    public function an_accountant_cannot_read_member_documents(): void
    {
        $member = $this->member();

        $this->actingAs($this->userWithRole(Permissions::ROLE_ACCOUNTANT), 'sanctum')
            ->getJson("/api/members/{$member->id}/documents")
            ->assertForbidden();
    }

    #[Test]
    public function reception_can_upload_and_read_documents(): void
    {
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);
        $member = $this->member();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/members/{$member->id}/documents", [
                'file' => UploadedFile::fake()->create('agreement.pdf', 50, 'application/pdf'),
                'type' => 'AGREEMENT',
            ])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/members/{$member->id}/documents")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function an_executable_upload_is_refused(): void
    {
        $member = $this->member();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/members/{$member->id}/documents", [
                'file' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
                'type' => 'OTHER',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    #[Test]
    public function an_oversized_upload_is_refused(): void
    {
        $member = $this->member();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/members/{$member->id}/documents", [
                'file' => UploadedFile::fake()->create('huge.pdf', 20480, 'application/pdf'),
                'type' => 'AGREEMENT',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    #[Test]
    public function replacing_a_document_keeps_the_previous_version(): void
    {
        // A gym asked "what did they actually sign in March?" has to be able
        // to answer it.
        $member = $this->member();
        $admin = $this->admin();

        $first = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/members/{$member->id}/documents", [
                'file' => UploadedFile::fake()->create('agreement-v1.pdf', 50, 'application/pdf'),
                'type' => 'AGREEMENT',
            ])
            ->json('data.id');

        $second = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/members/{$member->id}/documents", [
                'file' => UploadedFile::fake()->create('agreement-v2.pdf', 50, 'application/pdf'),
                'type' => 'AGREEMENT',
                'replaces_id' => $first,
            ])
            ->assertCreated()
            ->json('data.id');

        // Only the current version is listed...
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/members/{$member->id}/documents")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second);

        // ...but the old row and its file both survive.
        $this->assertSoftDeleted('member_documents', ['id' => $first]);
        $old = MemberDocument::withTrashed()->find($first);
        Storage::disk('local')->assertExists($old->path);
        $this->assertSame($first, MemberDocument::find($second)->replaces_id);
    }

    #[Test]
    public function removing_a_document_keeps_the_file_on_disk(): void
    {
        $member = $this->member();
        $admin = $this->admin();

        $id = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/members/{$member->id}/documents", [
                'file' => UploadedFile::fake()->create('agreement.pdf', 50, 'application/pdf'),
                'type' => 'AGREEMENT',
            ])
            ->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/members/{$member->id}/documents/{$id}")
            ->assertOk();

        $this->assertSoftDeleted('member_documents', ['id' => $id]);
        Storage::disk('local')->assertExists(MemberDocument::withTrashed()->find($id)->path);
    }

    #[Test]
    public function a_branch_bound_user_cannot_reach_another_branchs_documents(): void
    {
        $other = Branch::create(['code' => 'OTHER', 'name' => 'Other Branch']);
        $user = $this->userWithRole(Permissions::ROLE_RECEPTIONIST);

        $foreign = Member::create([
            'branch_id' => $other->id,
            'membership_number' => 'GM-OTHER-9',
            'full_name' => 'Other branch member',
            'join_date' => today(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/members/{$foreign->id}/documents")
            ->assertForbidden();
    }

    /* ------------------------------------------------------------ Photos */

    #[Test]
    public function a_profile_photo_can_be_uploaded_and_replaced(): void
    {
        $member = $this->member();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->post("/api/members/{$member->id}/photo", ['photo' => UploadedFile::fake()->image('first.jpg')])
            ->assertOk();

        $first = $member->refresh()->photo_path;
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);

        $this->actingAs($admin, 'sanctum')
            ->post("/api/members/{$member->id}/photo", ['photo' => UploadedFile::fake()->image('second.jpg')])
            ->assertOk();

        $second = $member->refresh()->photo_path;

        $this->assertNotSame($first, $second);
        // The old file is removed rather than left to accumulate.
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    #[Test]
    public function a_profile_photo_can_be_removed(): void
    {
        $member = $this->member();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->post("/api/members/{$member->id}/photo", ['photo' => UploadedFile::fake()->image('photo.jpg')]);

        $path = $member->refresh()->photo_path;

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/members/{$member->id}/photo")
            ->assertOk();

        $this->assertNull($member->refresh()->photo_path);
        Storage::disk('public')->assertMissing($path);
    }

    #[Test]
    public function a_non_image_cannot_be_uploaded_as_a_profile_photo(): void
    {
        $member = $this->member();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/members/{$member->id}/photo", [
                'photo' => UploadedFile::fake()->create('script.php', 10, 'application/x-php'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');
    }

    #[Test]
    public function a_trainer_cannot_change_a_members_photo(): void
    {
        $member = $this->member();

        $this->actingAs($this->userWithRole(Permissions::ROLE_TRAINER), 'sanctum')
            ->postJson("/api/members/{$member->id}/photo", ['photo' => UploadedFile::fake()->image('x.jpg')])
            ->assertForbidden();
    }
}

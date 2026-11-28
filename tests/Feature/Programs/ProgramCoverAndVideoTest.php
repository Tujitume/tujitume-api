<?php

namespace Tests\Feature\Programs;

use App\Models\Auth\OrganizationUserRole;
use App\Models\Auth\Role;
use App\Models\Auth\User;
use App\Models\Programs\Program;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** The program cover image and intro video: set by the owner's team, shown to applicants only on listed programs. */
class ProgramCoverAndVideoTest extends ProgramTestCase
{
    private function url(string $suffix = ''): string
    {
        return '/api/v1/programs/programs/'.$this->program->id.$suffix;
    }

    private function teamMember(string $role): User
    {
        $person = User::factory()->create(['user_type_id' => 4, 'organization_id' => $this->organization->id]);
        OrganizationUserRole::create([
            'organization_id' => $this->organization->id, 'user_id' => $person->id,
            'role_id' => Role::firstOrCreate(['name' => $role], ['access_types' => []])->id, 'status' => 'active', 'accepted_at' => now(),
        ]);

        return $person;
    }

    public function test_the_owner_uploads_replaces_and_removes_the_cover(): void
    {
        $this->actingAsOrgOwner()->post($this->url('/cover'), ['cover_image' => UploadedFile::fake()->image('cover.jpg', 1200, 600)], ['Accept' => 'application/json'])
            ->assertOk();
        $first = $this->program->fresh()->cover_image;
        $this->assertStringStartsWith(Program::COVER_DIRECTORY.'/', $first);
        Storage::disk('s3')->assertExists($first);
        $this->assertNotNull($this->program->fresh()->cover_image_url);

        $this->actingAsOrgOwner()->post($this->url('/cover'), ['cover_image' => UploadedFile::fake()->image('cover.webp', 800, 400)], ['Accept' => 'application/json'])
            ->assertOk();
        $second = $this->program->fresh()->cover_image;
        $this->assertNotSame($first, $second);
        Storage::disk('s3')->assertMissing($first);

        $this->actingAsOrgOwner()->postJson($this->url('/cover/remove'))->assertOk();
        $this->assertNull($this->program->fresh()->cover_image);
        $this->assertNull($this->program->fresh()->cover_image_url);
        Storage::disk('s3')->assertMissing($second);
    }

    public function test_the_cover_must_be_a_small_jpg_png_or_webp_image(): void
    {
        $this->actingAsOrgOwner()->post($this->url('/cover'), ['cover_image' => UploadedFile::fake()->create('brief.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('cover_image');
        $this->actingAsOrgOwner()->post($this->url('/cover'), ['cover_image' => UploadedFile::fake()->image('big.png')->size(6000)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('cover_image');
        $this->assertNull($this->program->fresh()->cover_image);
    }

    public function test_the_intro_video_accepts_youtube_vimeo_and_direct_files_only(): void
    {
        foreach (['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://youtu.be/dQw4w9WgXcQ', 'https://vimeo.com/76979871', 'https://cdn.example.org/intro.mp4'] as $link) {
            $this->actingAsOrgOwner()->postJson($this->url('/intro-video'), ['intro_video_url' => $link])->assertOk();
            $this->assertSame($link, $this->program->fresh()->intro_video_url);
        }

        foreach (['https://example.org/page', 'not a link', 'ftp://cdn.example.org/intro.mp4'] as $link) {
            $this->actingAsOrgOwner()->postJson($this->url('/intro-video'), ['intro_video_url' => $link])
                ->assertStatus(422)->assertJsonValidationErrors('intro_video_url');
        }

        $this->actingAsOrgOwner()->postJson($this->url('/intro-video/remove'))->assertOk();
        $this->assertNull($this->program->fresh()->intro_video_url);
    }

    public function test_the_update_endpoint_takes_the_cover_and_video_too(): void
    {
        $this->actingAsOrgOwner()->post($this->url(), [
            'cover_image' => UploadedFile::fake()->image('cover.png', 900, 450),
            'intro_video_url' => 'https://vimeo.com/76979871',
        ], ['Accept' => 'application/json'])->assertOk();

        $fresh = $this->program->fresh();
        $this->assertNotNull($fresh->cover_image);
        $this->assertSame('https://vimeo.com/76979871', $fresh->intro_video_url);

        $this->actingAsOrgOwner()->postJson($this->url(), ['remove_cover_image' => true, 'intro_video_url' => null])->assertOk();
        $fresh = $this->program->fresh();
        $this->assertNull($fresh->cover_image);
        $this->assertNull($fresh->intro_video_url);
    }

    public function test_editors_may_change_the_media_viewers_and_outsiders_may_not(): void
    {
        $editor = $this->teamMember('editor');
        $this->actingAs($editor, 'sanctum')->post($this->url('/cover'), ['cover_image' => UploadedFile::fake()->image('c.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->actingAs($editor, 'sanctum')->postJson($this->url('/cover/remove'))->assertOk();
        $this->actingAs($editor, 'sanctum')->postJson($this->url('/intro-video'), ['intro_video_url' => 'https://youtu.be/abc'])->assertOk();

        $viewer = $this->teamMember('viewer');
        $this->actingAs($viewer, 'sanctum')->post($this->url('/cover'), ['cover_image' => UploadedFile::fake()->image('c.jpg')], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->postJson($this->url('/intro-video/remove'))->assertForbidden();

        $this->actingAsApplicant()->postJson($this->url('/intro-video'), ['intro_video_url' => 'https://youtu.be/xyz'])->assertForbidden();
        $this->assertSame('https://youtu.be/abc', $this->program->fresh()->intro_video_url);
    }

    public function test_applicants_see_the_cover_and_video_of_listed_programs_but_not_drafts(): void
    {
        Storage::disk('s3')->put('images/programs/covers/seen.jpg', 'x');
        $this->program->forceFill(['cover_image' => 'images/programs/covers/seen.jpg', 'intro_video_url' => 'https://youtu.be/abc'])->save();
        $draft = Program::factory()->create([
            'user_id' => $this->orgUser->id, 'status' => 'draft', 'visible' => 1, 'cover_image' => 'images/programs/covers/hidden.jpg',
        ]);

        $list = $this->actingAsApplicant()->getJson('/api/v1/programs/public-programs?per_page=50')->assertOk();
        $programs = collect($list->json('data.programs') ?? $list->json('data.items') ?? $list->json('data'));
        $listed = $programs->firstWhere('id', $this->program->id);
        $this->assertNotNull($listed);
        $this->assertNotEmpty($listed['cover_image_url']);
        $this->assertSame('https://youtu.be/abc', $listed['intro_video_url']);
        $this->assertNull($programs->firstWhere('id', $draft->id));

        $detail = $this->actingAsApplicant()->getJson('/api/v1/programs/get_program/'.$this->program->id)->assertOk();
        $this->assertNotEmpty($detail->json('data.program.cover_image_url') ?? $detail->json('program.cover_image_url'));
        $this->actingAsApplicant()->getJson('/api/v1/programs/get_program/'.$draft->id)->assertNotFound();

        $public = $this->getJson('/api/v1/public/programs/'.$this->program->public_id)->assertOk();
        $this->assertStringContainsString('seen.jpg', $public->getContent());
        $this->get('/program-preview/'.$this->program->public_id)->assertOk()->assertSee('seen.jpg', false);
        $this->getJson('/api/v1/public/programs/'.$draft->public_id)->assertNotFound();

        $owner = $this->actingAsOrgOwner()->getJson('/api/v1/programs/programs?per_page=50')->assertOk();
        $this->assertStringContainsString('cover_image_url', $owner->getContent());
    }

    public function test_a_new_program_gets_round_1_and_may_carry_a_cover_and_video(): void
    {
        $industryId = DB::table('program_industries')->insertGetId(['name' => 'Agriculture', 'url' => 'agriculture', 'created_at' => now(), 'updated_at' => now()]);

        $created = $this->actingAsOrgOwner()->post('/api/v1/programs/create-program', [
            'program_title' => 'Cover test',
            'program_industry_id' => $industryId,
            'total_program_amount' => 100000,
            'funding_per_business' => 10000,
            'application_start_date' => now()->toDateString(),
            'application_deadline' => now()->addDays(30)->toDateString(),
            'program_type' => 'single_round',
            'cover_image' => UploadedFile::fake()->image('cover.jpg', 1200, 600),
            'intro_video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ], ['Accept' => 'application/json'])->assertOk();

        $data = $created->json('data') ?? $created->json();
        $program = Program::findOrFail($data['program']['id']);
        $this->assertNotNull($program->cover_image);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $program->intro_video_url);
        $this->assertSame('Round 1', $program->rounds()->first()->round_name);
    }
}

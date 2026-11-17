<?php

namespace Tests\Feature\ProgramImport;

use App\Models\Auth\User;
use App\Models\Organizations\Organization;
use App\Models\ProgramIndustry;
use App\Modules\ProgramImport\Models\ImportBatch;
use App\Modules\ProgramImport\Pipeline\BatchStager;
use App\Modules\ProgramImport\Pipeline\BatchValidator;
use App\Modules\ProgramImport\Pipeline\CommitRunner;
use App\Modules\ProgramImport\Pipeline\ReadinessService;
use App\Modules\ProgramImport\ProgramImportServiceProvider;
use App\Modules\ProgramImport\Silent\OutboundGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

abstract class ImportTestCase extends TestCase
{
    use RefreshDatabase;

    /** API tests run the queue inline (sync); service-level tests fake it to prove nothing is dispatched. */
    protected bool $fakeQueue = true;

    public User $orgUser;

    public Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        if ($this->fakeQueue) {
            Queue::fake();
        }
        Http::fake();
        Event::fake([\App\Events\NewNotification::class]);
        // AI is off by default in tests: AI paths are exercised with scripted fake clients, never a live provider.
        config(['program_imports.enabled' => true, 'program_imports.disk' => 'local', 'program_imports.ai.enabled' => false]);
        $this->app->register(ProgramImportServiceProvider::class, true);
        $this->artisan('migrate', ['--path' => 'app/Modules/ProgramImport/database/migrations'])->assertExitCode(0);
        OutboundGuard::flush();

        $this->orgUser = User::factory()->create(['user_type_id' => 4]);
        $this->org = Organization::factory()->create(['owner_user_id' => $this->orgUser->id]);
        $this->orgUser->update(['organization_id' => $this->org->id]);
        ProgramIndustry::firstOrCreate(['name' => 'Agriculture']);
    }

    /** @param array<string, list<array<string,mixed>>> $sheets sheet name => rows (header = keys of the first row) */
    public function xlsx(array $sheets): string
    {
        $book = new Spreadsheet;
        $i = 0;
        foreach ($sheets as $name => $rows) {
            $ws = $i++ === 0 ? $book->getActiveSheet() : $book->createSheet();
            $ws->setTitle($name);
            $keys = array_values(array_unique(array_merge(...array_map('array_keys', $rows))));
            $ws->fromArray(array_merge([$keys], array_map(fn ($r) => array_map(fn ($k) => $r[$k] ?? null, $keys), $rows)), null, 'A1', true);
        }
        $path = tempnam(sys_get_temp_dir(), 'pi').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    public function newBatch(string $startPoint, string $localFile, array $options = [], ?int $programId = null, ?string $type = null): ImportBatch
    {
        $ext = pathinfo($localFile, PATHINFO_EXTENSION);
        $path = 'program-imports/'.$this->org->id.'/'.uniqid().'.'.$ext;
        Storage::disk('local')->put($path, file_get_contents($localFile));

        return ImportBatch::create([
            'organization_id' => $this->org->id, 'user_id' => $this->orgUser->id, 'program_id' => $programId,
            'start_point' => $type ? null : $startPoint, 'type' => $type ?? $startPoint, 'source' => 'file',
            'status' => 'uploaded', 'file_path' => $path, 'options' => $options, 'original_filename' => basename($localFile),
        ]);
    }

    /** parse -> analyze -> validate -> readiness; returns the readiness result. */
    public function prepare(ImportBatch $b): array
    {
        $s = new BatchStager;
        $s->parse($b->refresh());
        $this->assertNotSame('failed', $b->refresh()->status, json_encode($b->error_summary));
        $s->analyze($b, BatchStager::stagedQuestions($b), BatchStager::stagedFields($b));
        (new BatchValidator)->run($b->refresh());

        return (new ReadinessService)->run($b->refresh());
    }

    public function commit(ImportBatch $b): ImportBatch
    {
        $b->update(['consent_confirmed_at' => now()]);
        (new CommitRunner)->run($b->refresh());
        if (getenv('PI_DEBUG')) { fwrite(STDERR, json_encode(\App\Modules\ProgramImport\Models\ImportRow::where('batch_id', $b->id)->whereIn('status', ['failed','invalid'])->get(['sheet','row_number','status','errors'])->toArray()) . "\n"); }

        return $b->refresh();
    }

    public function programRow(array $o = []): array
    {
        return $o + ['program_ref' => 'P1', 'program_title' => 'Youth Fund', 'total_program_amount' => 'KES 400,000', 'funding_per_business' => 100000,
            'disbursement_type' => 'supplier', 'program_type' => 'multi_round', 'total_rounds' => 2, 'program_industry' => 'Agriculture', 'currency' => 'KES',
            'application_start_date' => '2026-01-01', 'application_deadline' => '2026-02-01'];
    }

    public function roundRows(): array
    {
        return [
            ['program_ref' => 'P1', 'round_number' => 1, 'round_name' => 'Application', 'open_date' => '2026-01-01', 'close_date' => '2026-02-01', 'review_period_end' => '2026-02-10'],
            ['program_ref' => 'P1', 'round_number' => 2, 'round_name' => 'Pitch', 'open_date' => '2026-03-01', 'close_date' => '2026-04-01', 'review_period_end' => '2026-04-10',
                'rubric_mode' => 'weighted', 'scoring_criteria' => 'Impact:60:10; Team:40:10', 'advancement_mode' => 'manual'],
        ];
    }

    public function appRows(int $round = 2): array
    {
        return [
            ['application_ref' => 'A1', 'program_ref' => 'P1', 'round_number' => $round, 'startup_name' => 'Agro One', 'contact_person_name' => 'Amina Ali', 'email' => 'amina@example.com', 'sector' => 'Agri', 'headquarters_location' => 'Nairobi', 'total_amount_requested' => 100000],
            ['application_ref' => 'A2', 'program_ref' => 'P1', 'round_number' => $round, 'startup_name' => 'Agro Two', 'contact_person_name' => 'Bo Kim', 'email' => 'bo@example.com', 'sector' => 'Agri', 'headquarters_location' => 'Kisumu', 'total_amount_requested' => 80000],
        ];
    }

    /** Silent mode proof: an import sends nothing and starts no AI/payment work. */
    public function assertSilent(): void
    {
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->fakeQueue && Queue::assertNothingPushed();
        Http::assertNothingSent();
        Event::assertNotDispatched(\App\Events\NewNotification::class);
        $this->assertSame(0, \App\Models\Communication\Notifications::count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('application_ai_reviews')->count());
    }

    public function po(): static
    {
        return $this->actingAs($this->orgUser, 'sanctum');
    }
}

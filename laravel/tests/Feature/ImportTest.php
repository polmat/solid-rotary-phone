<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Models\ImportJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_upload_is_accepted(): void
    {
        Queue::fake();
        Storage::fake('local');

        $csv = UploadedFile::fake()->createWithContent(
            'users.csv',
            "name,email,password\nJohn Doe,john@example.com,secret"
        );

        $response = $this->postJson('/api/imports', ['file' => $csv]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['id', 'status']);
        $this->assertEquals('pending', $response->json('status'));
        $this->assertDatabaseHas('import_jobs', ['filename' => 'users.csv']);
    }

    /**
     * Known-bug test: one bad row currently rolls back the whole import and
     * marks it failed. Expected to FAIL until the import skips and reports
     * invalid rows individually.
     */
    public function test_import_with_an_invalid_row_still_imports_valid_rows(): void
    {
        // Keep the upload from running the job; it is run explicitly below.
        Queue::fake();
        Storage::fake('local');

        $csv = UploadedFile::fake()->createWithContent(
            'users.csv',
            "name,email,password\n"
            . "Alice Valid,alice@example.com,secret\n"
            . "Alice Duplicate,alice@example.com,secret\n"
            . "Carol Valid,carol@example.com,secret\n"
        );

        $this->postJson('/api/imports', ['file' => $csv])->assertStatus(202);

        $import = ImportJob::firstOrFail();

        // Run the queued job synchronously. (dispatchSync() would be swallowed by
        // Queue::fake(), so call the job directly and mimic the queue worker by
        // invoking failed() if it throws.)
        $job = new ProcessImportJob($import);
        try {
            $job->handle();
        } catch (\Throwable $e) {
            $job->failed($e);
        }

        $this->assertDatabaseHas('users', ['email' => 'alice@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'carol@example.com']);

        $import->refresh();
        $this->assertNotEquals(ImportStatus::Failed, $import->status);

        $response = $this->getJson("/api/imports/{$import->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('processed_rows', 3);
        $response->assertJsonPath('failed_rows', 1);

        // The duplicate is the 2nd data row of the CSV.
        $response->assertJsonCount(1, 'errors');
        $response->assertJsonPath('errors.0.row', 2);
        $this->assertNotEmpty($response->json('errors.0.message'));
    }

    public function test_upload_requires_a_file(): void
    {
        $response = $this->postJson('/api/imports', []);

        $response->assertStatus(422);
    }

    public function test_import_status_can_be_retrieved(): void
    {
        $job = ImportJob::create([
            'filename'       => 'users.csv',
            'status'         => 'completed',
            'total_rows'     => 10,
            'processed_rows' => 10,
        ]);

        $response = $this->getJson("/api/imports/{$job->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'filename'       => 'users.csv',
            'status'         => 'completed',
            'total_rows'     => 10,
            'processed_rows' => 10,
        ]);
    }

    public function test_import_returns_404_for_unknown_id(): void
    {
        $response = $this->getJson('/api/imports/99999');

        $response->assertStatus(404);
    }
}

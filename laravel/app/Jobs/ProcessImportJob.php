<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\ImportJob;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProcessImportJob implements ShouldQueue
{
    use Queueable;

    private const PROGRESS_INTERVAL = 100;

    public int $tries = 1;

    public function __construct(public ImportJob $importJob) {}

    /**
     * Only one worker may process a given import at a time. Overlapping
     * duplicates are discarded rather than released, since tries is 1.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->importJob->id))->dontRelease()];
    }

    public function handle(): void
    {
        // Guard against reruns: re-read the status in case it changed since
        // the job was queued, and never reprocess a finished import.
        $this->importJob->refresh();

        if (in_array($this->importJob->status, [ImportStatus::Completed, ImportStatus::CompletedWithErrors], true)) {
            return;
        }

        $this->importJob->update(['status' => ImportStatus::Processing]);

        $path = Storage::path('imports/' . $this->importJob->filename);

        if (! file_exists($path)) {
            $this->importJob->update([
                'status'        => ImportStatus::Failed,
                'error_message' => 'File not found.',
            ]);
            return;
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        $processed = 0;
        $failed = 0;
        $errors = [];

        // Stream the file one row at a time; each row is validated and saved
        // on its own, so a bad row never affects the others.
        while (($row = fgetcsv($handle)) !== false) {
            // Skip blank lines (fgetcsv returns [null]).
            if ($row === [null]) {
                continue;
            }

            $processed++;

            try {
                $this->importRow($header, $row);
            } catch (ValidationException $e) {
                $failed++;
                $errors[] = ['row' => $processed, 'message' => implode(' ', $e->validator->errors()->all())];
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['row' => $processed, 'message' => $e->getMessage()];
            }

            if ($processed % self::PROGRESS_INTERVAL === 0) {
                $this->importJob->update([
                    'processed_rows' => $processed,
                    'failed_rows'    => $failed,
                    'errors'         => $errors ?: null,
                ]);
            }
        }

        fclose($handle);

        $this->importJob->update([
            'status'         => $failed > 0 ? ImportStatus::CompletedWithErrors : ImportStatus::Completed,
            'total_rows'     => $processed,
            'processed_rows' => $processed,
            'failed_rows'    => $failed,
            'errors'         => $errors ?: null,
        ]);
    }

    /**
     * Validate and save a single CSV row (autocommit, no shared transaction).
     *
     * @throws ValidationException
     */
    private function importRow(array $header, array $row): void
    {
        // Pad/trim so ragged rows still map onto the header.
        $data = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));

        $validated = Validator::make($data, [
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'password' => 'nullable|string',
        ])->validate();

        User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => bcrypt($validated['password'] ?? 'password'),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->importJob->update([
            'status'        => ImportStatus::Failed,
            'error_message' => $e->getMessage(),
        ]);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->integer('failed_rows')->default(0)->after('processed_rows');
            $table->json('errors')->nullable()->after('error_message');
            // Fixed list on purpose: adding a status to App\Enums\ImportStatus
            // requires a new migration to update this column.
            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'completed_with_errors',
                'failed',
            ])->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->dropColumn(['failed_rows', 'errors']);
            $table->string('status')->default('pending')->change();
        });
    }
};

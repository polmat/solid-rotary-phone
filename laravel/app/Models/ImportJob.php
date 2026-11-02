<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Model;

class ImportJob extends Model
{
    protected $fillable = [
        'filename',
        'status',
        'total_rows',
        'processed_rows',
        'failed_rows',
        'error_message',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'errors' => 'array',
        ];
    }
}

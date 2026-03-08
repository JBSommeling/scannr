<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Persists the configuration and results of a queued website scan.
 *
 * @property string $id
 * @property string $url
 * @property array $config
 * @property string $status
 * @property string|null $results
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 *
 * TODO: Add model pruning (Prunable trait) to clean up old scan results.
 */
class ScanResult extends Model
{
    use HasUlids;

    protected $fillable = [
        'url',
        'config',
        'status',
        'results',
        'error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}

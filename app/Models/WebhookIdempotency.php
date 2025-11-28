<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookIdempotency extends Model
{
    protected $table = 'webhook_idempotency';

    protected $fillable = [
        'idempotency_key',
        'processed_at',
        'payload_hash',
        'result_state',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}

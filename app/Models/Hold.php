<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Carbon\Carbon;

class Hold extends Model
{
    protected $fillable = [
        'product_id',
        'qty',
        'expires_at',
        'used_at',
        'status',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'qty' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && !$this->isExpired();
    }

    public function markAsExpired(): bool
    {
        return $this->update(['status' => 'expired']);
    }

    public function markAsUsed(): bool
    {
        return $this->update([
            'status' => 'used',
            'used_at' => now(),
        ]);
    }
}

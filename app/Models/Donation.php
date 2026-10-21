<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Donation extends Model
{
    public const NET_AMOUNT_SQL = 'CASE WHEN refunded_amount >= amount THEN 0 ELSE amount - refunded_amount END';

    protected $fillable = [
        'user_id', 'campaign_id', 'amount', 'method', 'message', 'anonymous', 'status', 'paid_at',
        'order_id', 'snap_token', 'snap_redirect_url', 'gateway_transaction_id', 'gateway_status',
        'gateway_fraud_status', 'payment_type', 'expires_at', 'last_checked_at', 'refunded_amount',
        'payment_channel', 'admin_fee',
    ];

    protected $hidden = ['snap_token', 'snap_redirect_url'];

    protected $casts = [
        'amount' => 'integer', 'refunded_amount' => 'integer', 'anonymous' => 'boolean',
        'admin_fee' => 'integer',
        'paid_at' => 'datetime', 'expires_at' => 'datetime', 'last_checked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function scopeFunded(Builder $query): Builder
    {
        return $query->whereIn('status', ['paid', 'partially_refunded']);
    }

    public function getNetAmountAttribute(): int
    {
        return in_array($this->status, ['paid', 'partially_refunded'], true)
            ? max(0, $this->amount - $this->refunded_amount) : 0;
    }

    public function getTotalAmountAttribute(): int
    {
        return $this->amount + (int) $this->admin_fee;
    }

    public function getPaymentChannelLabelAttribute(): string
    {
        $method = config('payments.methods.'.$this->method, []);

        return $method['channels'][$this->payment_channel]['label'] ?? $method['label'] ?? $this->method;
    }

    public function getSimulatorUrlAttribute(): ?string
    {
        return config('payments.methods.'.$this->method.'.channels.'.$this->payment_channel.'.simulator_url');
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'paid' => 'Berhasil', 'pending' => 'Menunggu pembayaran', 'expired' => 'Kedaluwarsa',
            'cancelled' => 'Dibatalkan', 'failed' => 'Gagal', 'refunded' => 'Dikembalikan',
            'partially_refunded' => 'Dikembalikan sebagian', default => $this->status,
        };
    }
}

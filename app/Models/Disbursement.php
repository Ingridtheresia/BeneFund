<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Disbursement extends Model
{
    public const RESERVED_STATUSES = ['requested', 'approved'];

    public const STATUS_LABELS = [
        'requested' => 'Diajukan', 'approved' => 'Disetujui',
        'transferred' => 'Transfer dilaporkan', 'received' => 'Diterima penerima',
        'rejected' => 'Ditolak', 'cancelled' => 'Dibatalkan',
    ];

    protected $guarded = ['id'];

    protected $hidden = [
        'request_key', 'recipient_account_name', 'recipient_account_number',
        'proof_path', 'transfer_reference', 'reason',
    ];

    protected $casts = [
        'amount' => 'integer', 'campaign_id' => 'integer', 'requested_by' => 'integer',
        'recipient_user_id' => 'integer', 'expense_id' => 'integer',
        'recipient_account_name' => 'encrypted', 'recipient_account_number' => 'encrypted',
        'requested_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime',
        'transferred_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime',
        'rejected_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function events()
    {
        return $this->hasMany(DisbursementEvent::class)->oldest('id');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getReferenceAttribute(): string
    {
        return $this->public_id;
    }
}

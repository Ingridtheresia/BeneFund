<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class DisbursementEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['note', 'actor_id'];

    protected $casts = ['created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Riwayat penyaluran tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Riwayat penyaluran tidak dapat dihapus.'));
    }

    public function disbursement()
    {
        return $this->belongsTo(Disbursement::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return Disbursement::STATUS_LABELS[$this->status] ?? $this->status;
    }
}

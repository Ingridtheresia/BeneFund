<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Campaign extends Model
{
    public const CATEGORIES = ['Bencana', 'Pendidikan', 'Kesehatan', 'Masjid', 'Panti Asuhan', 'Sosial', 'Lingkungan'];

    protected $fillable = ['user_id', 'title', 'category', 'location', 'description', 'target', 'deadline', 'image_url', 'status'];

    protected $casts = ['deadline' => 'immutable_date', 'target' => 'integer', 'user_id' => 'integer'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function donations()
    {
        return $this->hasMany(Donation::class);
    }

    public function updates()
    {
        return $this->hasMany(CampaignUpdate::class)->latest()->orderByDesc('id');
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class)->latest('spent_at')->orderByDesc('id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopeWithFundingTotals(Builder $query): Builder
    {
        return $query
            ->withSum(['donations as raised_total' => fn (Builder $donations) => $donations->whereIn('status', ['paid', 'partially_refunded'])], DB::raw(Donation::NET_AMOUNT_SQL))
            ->withSum('expenses as spent_total', 'amount');
    }

    public function getRaisedAttribute(): int
    {
        return array_key_exists('raised_total', $this->attributes)
            ? (int) $this->attributes['raised_total']
            : (int) $this->donations()->whereIn('status', ['paid', 'partially_refunded'])->sum(DB::raw(Donation::NET_AMOUNT_SQL));
    }

    public function getSpentAttribute(): int
    {
        return array_key_exists('spent_total', $this->attributes)
            ? (int) $this->attributes['spent_total']
            : (int) $this->expenses()->sum('amount');
    }

    public function getAvailableAttribute(): int
    {
        return $this->raised - $this->spent;
    }

    public function getPercentAttribute(): int
    {
        return $this->target > 0 ? min(100, (int) round($this->raised / $this->target * 100)) : 0;
    }

    public function getIsOpenAttribute(): bool
    {
        return $this->status === 'approved' && $this->deadline?->endOfDay()->isFuture();
    }
}

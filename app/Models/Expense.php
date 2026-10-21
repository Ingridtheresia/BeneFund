<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = ['campaign_id', 'title', 'amount', 'description', 'spent_at'];

    protected $casts = ['amount' => 'integer', 'spent_at' => 'immutable_date'];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }
}

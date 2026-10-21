<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Donation extends Model {
 protected $fillable=['user_id','campaign_id','amount','method','message','anonymous','status','paid_at'];
 protected $casts=['anonymous'=>'boolean','paid_at'=>'datetime'];
 public function user(){return $this->belongsTo(User::class);}
 public function campaign(){return $this->belongsTo(Campaign::class);}
}

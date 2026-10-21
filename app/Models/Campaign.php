<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Campaign extends Model {
 protected $fillable=['user_id','title','category','location','description','target','deadline','image_url','status'];
 protected $casts=['deadline'=>'date'];
 public function user(){return $this->belongsTo(User::class);}
 public function donations(){return $this->hasMany(Donation::class);}
 public function updates(){return $this->hasMany(CampaignUpdate::class)->latest();}
 public function expenses(){return $this->hasMany(Expense::class)->latest();}
 public function getRaisedAttribute(){return (int)$this->donations()->where('status','paid')->sum('amount');}
 public function getPercentAttribute(){return $this->target>0?min(100,round($this->raised/$this->target*100)):0;}
}

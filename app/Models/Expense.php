<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Expense extends Model {protected $fillable=["campaign_id", "title", "amount", "description", "spent_at"];}

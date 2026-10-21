<?php
namespace App\Http\Controllers;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Expense;
use App\Models\CampaignUpdate;
use Illuminate\Http\Request;
class SiteController extends Controller {
 public function home(){return view('home',['campaigns'=>Campaign::where('status','approved')->latest()->take(6)->get(),'total'=>Donation::where('status','paid')->sum('amount'),'donors'=>Donation::where('status','paid')->distinct('user_id')->count('user_id')]);}
 public function campaigns(Request $r){$q=Campaign::where('status','approved');if($r->filled('q'))$q->where('title','like','%'.$r->q.'%');if($r->filled('category'))$q->where('category',$r->category);return view('campaigns',['campaigns'=>$q->latest()->paginate(9)]);}
 public function show(Campaign $campaign){abort_unless($campaign->status==='approved'||(auth()->check()&&(auth()->id()===$campaign->user_id||auth()->user()->role==='admin')),404);return view('campaign.show',compact('campaign'));}
 public function about(){return view('simple',['title'=>'Tentang BeneFund','description'=>'BeneFund menghubungkan kebaikan dengan kebutuhan nyata melalui campaign sosial dan laporan penggunaan dana yang dapat diperiksa publik.']);}
 public function transparency(){return view('transparency',['campaigns'=>Campaign::where('status','approved')->get(),'raised'=>Donation::where('status','paid')->sum('amount'),'spent'=>Expense::sum('amount')]);}
 public function news(){return view('news',['updates'=>CampaignUpdate::with('campaign')->latest()->paginate(12)]);}
 public function topDonors(){return view('top',['donors'=>Donation::with('user')->where('status','paid')->where('anonymous',false)->selectRaw('user_id, SUM(amount) as total, COUNT(*) as frequency')->groupBy('user_id')->orderByDesc('total')->limit(20)->get()]);}
}

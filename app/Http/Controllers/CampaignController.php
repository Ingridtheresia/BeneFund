<?php
namespace App\Http\Controllers;
use App\Models\Campaign;
use Illuminate\Http\Request;
class CampaignController extends Controller {
 private function authorizeOwner(Campaign $c){abort_unless(auth()->user()->role==='admin'||$c->user_id===auth()->id(),403);}
 private function fields(Request $r){return $r->validate(['title'=>'required|string|max:150','category'=>'required|in:Bencana,Pendidikan,Kesehatan,Masjid,Panti Asuhan,Sosial,Lingkungan','location'=>'nullable|string|max:150','description'=>'required|string|min:30','target'=>'required|integer|min:100000','deadline'=>'required|date|after:today','image_url'=>'nullable|url|max:1000']);}
 public function index(){return view('manage.index',['campaigns'=>auth()->user()->role==='admin'?Campaign::latest()->get():auth()->user()->campaigns()->latest()->get()]);}
 public function create(){return view('manage.form',['campaign'=>new Campaign]);}
 public function store(Request $r){Campaign::create($this->fields($r)+['user_id'=>auth()->id(),'status'=>'pending']);return redirect()->route('manage.campaigns')->with('ok','Campaign menunggu verifikasi admin.');}
 public function edit(Campaign $campaign){$this->authorizeOwner($campaign);return view('manage.form',compact('campaign'));}
 public function update(Request $r,Campaign $campaign){$this->authorizeOwner($campaign);$campaign->update($this->fields($r)+['status'=>'pending']);return redirect()->route('manage.campaigns')->with('ok','Perubahan menunggu verifikasi.');}
 public function postUpdate(Request $r,Campaign $campaign){$this->authorizeOwner($campaign);$v=$r->validate(['title'=>'required|max:150','body'=>'required|max:5000']);$campaign->updates()->create($v);return back()->with('ok','Update berhasil diterbitkan.');}
 public function expense(Request $r,Campaign $campaign){$this->authorizeOwner($campaign);$v=$r->validate(['title'=>'required|max:150','amount'=>'required|integer|min:1','description'=>'nullable|max:2000','spent_at'=>'required|date|before_or_equal:today']);$raised=$campaign->raised;$spent=$campaign->expenses()->sum('amount');if($v['amount']>$raised-$spent)return back()->withErrors(['amount'=>'Pengeluaran melebihi sisa dana yang tersedia.']);$campaign->expenses()->create($v);return back()->with('ok','Pengeluaran dicatat.');}
}

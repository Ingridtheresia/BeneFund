<?php
namespace App\Http\Controllers;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\User;
use Illuminate\Http\Request;
class DashboardController extends Controller {
 public function index(){return view('dashboard',['donations'=>auth()->user()->donations()->with('campaign')->latest()->take(8)->get(),'campaigns'=>auth()->user()->campaigns()->latest()->get()]);}
 public function profile(){return view('profile');}
 public function updateProfile(Request $r){$v=$r->validate(['name'=>'required|string|max:100','email'=>'required|email|unique:users,email,'.auth()->id()]);auth()->user()->update($v);return back()->with('ok','Profil tersimpan.');}
 public function favorite(Campaign $campaign){auth()->user()->favorites()->toggle($campaign->id);return back();}
 public function favorites(){return view('campaigns',['campaigns'=>auth()->user()->favorites()->where('status','approved')->paginate(9)]);}
 public function admin(){return view('admin.index',['pending'=>Campaign::where('status','pending')->latest()->get(),'total'=>Donation::where('status','paid')->sum('amount'),'users'=>User::count(),'campaignCount'=>Campaign::count()]);}
 public function approve(Campaign $campaign){$campaign->update(['status'=>'approved']);return back()->with('ok','Campaign disetujui.');}
 public function reject(Campaign $campaign){$campaign->update(['status'=>'rejected']);return back()->with('ok','Campaign ditolak.');}
 public function users(){return view('admin.users',['users'=>User::latest()->paginate(20)]);}
 public function transactions(){return view('donations.history',['donations'=>Donation::with('campaign','user')->latest()->paginate(20)]);}
}

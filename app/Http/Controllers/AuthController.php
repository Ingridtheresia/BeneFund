<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
class AuthController extends Controller {
 public function loginForm(){return view('auth.login');}
 public function registerForm(){return view('auth.register');}
 public function login(Request $r){$v=$r->validate(['email'=>'required|email','password'=>'required']);if(Auth::attempt($v)){$r->session()->regenerate();return redirect()->route('dashboard');}return back()->withErrors(['email'=>'Email atau password salah.']);}
 public function register(Request $r){$v=$r->validate(['name'=>'required|string|max:100','email'=>'required|email|unique:users','password'=>['required','confirmed',Password::min(8)],'role'=>'required|in:donor,fundraiser']);$u=User::create($v);Auth::login($u);$r->session()->regenerate();return redirect()->route('dashboard');}
 public function logout(Request $r){Auth::logout();$r->session()->invalidate();$r->session()->regenerateToken();return redirect('/');}
}

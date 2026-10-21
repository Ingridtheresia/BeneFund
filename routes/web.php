<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\DonationController;
use App\Http\Controllers\DashboardController;
Route::get('/',[SiteController::class,'home'])->name('home');
Route::get('/campaigns',[SiteController::class,'campaigns'])->name('campaigns');
Route::get('/campaigns/{campaign}',[SiteController::class,'show'])->name('campaign.show');
Route::get('/about',[SiteController::class,'about'])->name('about');
Route::get('/transparency',[SiteController::class,'transparency'])->name('transparency');
Route::get('/news',[SiteController::class,'news'])->name('news');
Route::get('/top-donors',[SiteController::class,'topDonors'])->name('top');
Route::get('/login',[AuthController::class,'loginForm'])->name('login');
Route::post('/login',[AuthController::class,'login'])->name('login.submit');
Route::get('/register',[AuthController::class,'registerForm'])->name('register');
Route::post('/register',[AuthController::class,'register'])->name('register.submit');
Route::post('/logout',[AuthController::class,'logout'])->middleware('auth')->name('logout');
Route::middleware('auth')->group(function(){
 Route::get('/dashboard',[DashboardController::class,'index'])->name('dashboard');
 Route::get('/profile',[DashboardController::class,'profile'])->name('profile');
 Route::post('/profile',[DashboardController::class,'updateProfile'])->name('profile.update');
 Route::get('/donations',[DonationController::class,'history'])->name('donations.history');
 Route::post('/campaigns/{campaign}/donate',[DonationController::class,'store'])->name('donations.store');
 Route::get('/payments/{donation}',[DonationController::class,'payment'])->name('payments.show');
 Route::post('/payments/{donation}/confirm',[DonationController::class,'confirm'])->name('payments.confirm');
 Route::get('/certificates/{donation}',[DonationController::class,'certificate'])->name('certificates.show');
 Route::post('/campaigns/{campaign}/favorite',[DashboardController::class,'favorite'])->name('favorite');
 Route::get('/favorites',[DashboardController::class,'favorites'])->name('favorites');
 Route::middleware('role:fundraiser,admin')->group(function(){
  Route::get('/manage/campaigns',[CampaignController::class,'index'])->name('manage.campaigns');
  Route::get('/manage/campaigns/create',[CampaignController::class,'create'])->name('manage.create');
  Route::post('/manage/campaigns',[CampaignController::class,'store'])->name('manage.store');
  Route::get('/manage/campaigns/{campaign}/edit',[CampaignController::class,'edit'])->name('manage.edit');
  Route::put('/manage/campaigns/{campaign}',[CampaignController::class,'update'])->name('manage.update');
  Route::post('/manage/campaigns/{campaign}/updates',[CampaignController::class,'postUpdate'])->name('manage.updates');
  Route::post('/manage/campaigns/{campaign}/expenses',[CampaignController::class,'expense'])->name('manage.expenses');
 });
 Route::middleware('role:admin')->group(function(){
  Route::get('/admin',[DashboardController::class,'admin'])->name('admin');
  Route::post('/admin/campaigns/{campaign}/approve',[DashboardController::class,'approve'])->name('admin.approve');
  Route::post('/admin/campaigns/{campaign}/reject',[DashboardController::class,'reject'])->name('admin.reject');
  Route::get('/admin/users',[DashboardController::class,'users'])->name('admin.users');
  Route::get('/admin/transactions',[DashboardController::class,'transactions'])->name('admin.transactions');
 });
});

<?php

// Isolated browser-test data. Never run this fixture against the application's database.
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || config('database.default') !== 'sqlite'
    || ! str_starts_with(basename(config('database.connections.sqlite.database')), 'browser-')) {
    throw new RuntimeException('Browser fixtures require an isolated browser-*.sqlite testing database.');
}

Artisan::call('migrate', ['--force' => true]);
$owner = User::create(['name' => 'Penggalang Uji', 'email' => 'owner@browser.test', 'password' => 'BrowserTest123!', 'role' => 'fundraiser']);
$donor = User::create(['name' => 'Donatur Uji', 'email' => 'donor@browser.test', 'password' => 'BrowserTest123!', 'role' => 'donor']);
$campaign = Campaign::create([
    'user_id' => $owner->id, 'title' => 'Bantuan pendidikan untuk masa depan', 'category' => 'Pendidikan',
    'description' => 'Campaign pengujian tampilan untuk memastikan pilihan pembayaran dan notifikasi berfungsi.',
    'target' => 1000000, 'deadline' => now()->addMonth(), 'status' => 'approved',
]);
$payment = Donation::create([
    'user_id' => $donor->id, 'campaign_id' => $campaign->id, 'amount' => 100000,
    'method' => 'bank', 'payment_channel' => 'bni_va', 'admin_fee' => 4500, 'status' => 'pending',
]);
foreach (range(1, 17) as $index) {
    $donor->notify(new ActivityNotification([
        'kind' => 'test.activity', 'title' => 'Pembaruan aktivitas '.$index,
        'message' => 'Notifikasi untuk memeriksa tampilan, pagination, dan penanda dibaca.',
        'url' => route('payments.show', $payment),
    ]));
}
echo json_encode(['campaign' => $campaign->id, 'payment' => $payment->id], JSON_THROW_ON_ERROR);

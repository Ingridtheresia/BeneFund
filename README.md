# BeneFund — Laravel 12/13 application source

This repository is an **application layer** for a fresh Laravel project (not a vendor-inclusive installation).

## Windows installation
1. Install PHP 8.2+, Composer, MySQL, Node.js.
2. `composer create-project laravel/laravel benefund`
3. Copy the contents of this archive into the new `benefund` folder (replace files when asked).
4. `cd benefund`
5. `copy .env.example .env` if .env does not exist, then set `DB_CONNECTION=mysql`, `DB_DATABASE=benefund`, `DB_USERNAME=root`, `DB_PASSWORD=...`; create database `benefund` in MySQL.
6. `php artisan key:generate`
7. `php artisan migrate --seed`
8. `php artisan serve`

Demo accounts (password: `Password123!`): admin@benefund.test, fundraiser@benefund.test, donor@benefund.test.

## Scope and limitations
This is a functional local academic demo. Payment is explicitly simulated: pressing Confirm marks a pending transaction successful; there is no real QRIS, Midtrans integration, or bank transfer. Certificate is a browser-printable HTML page (Save as PDF in browser). Chart.js is loaded from CDN. Progress refreshes when pages are reloaded, not via WebSocket. No actual funds are moved. No production deployment, automated tests, or external payment integration is included. For production, implement verified payment webhooks, KYC, anti-fraud, receipt storage, and secure disbursement workflows.

Role authorization is enforced on server. Donations are summed only when paid; ledger reports track disbursements separately. Demo data is seeded. Passwords are hashed by Laravel.

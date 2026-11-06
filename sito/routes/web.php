<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ExportDownloadController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/membership', [MembershipController::class, 'show'])->name('membership.show');

// Selettore della lingua: resta in sessione e, per chi ha un account, in users.locale.
Route::get('/language/{locale}', function (string $locale) {
    session(['locale' => $locale]);
    auth()->user()?->update(['locale' => $locale]);

    return back();
})->whereIn('locale', SetLocale::LOCALES)->name('locale');

// Accesso dei clienti con codice via email (i limiti veri sono in LoginCodeService).
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'send'])->middleware('throttle:10,1')->name('login.send');
Route::get('/login/code', [LoginController::class, 'code'])->name('login.code');
Route::post('/login/code', [LoginController::class, 'verify'])->middleware('throttle:10,1')->name('login.verify');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

Route::get('/tickets', [ShopController::class, 'index'])->name('shop.index');

Route::middleware('auth')->group(function () {
    Route::post('/cart', [ShopController::class, 'reserve'])->middleware('throttle:10,1')->name('shop.reserve');
    Route::post('/orders/{order}/checkout', [ShopController::class, 'checkout'])->middleware('throttle:10,1')->whereUuid('order')->name('shop.checkout');

    Route::get('/account', [AccountController::class, 'index'])->name('account.index');
    Route::get('/account/orders/{order}', [AccountController::class, 'order'])->whereUuid('order')->name('account.orders.show');
    Route::get('/account/tickets/{ticket}/qr', [AccountController::class, 'qr'])->whereUuid('ticket')->name('account.tickets.qr');

    Route::post('/membership', [MembershipController::class, 'start'])->name('membership.start');
    Route::get('/membership/success', [MembershipController::class, 'success'])->name('membership.success');
    Route::post('/membership/portal', [MembershipController::class, 'portal'])->name('membership.portal');

    // Export generati in coda (pannello, pagina Export): solo lo staff che li ha chiesti.
    Route::get('/admin/exports/{file}', ExportDownloadController::class)
        ->where('file', '[a-z]+-\d{8}-\d{6}-[a-z0-9]{8}\.csv')->name('exports.download');
});

// Firmato da Stripe, escluso dal CSRF in bootstrap/app.php.
Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

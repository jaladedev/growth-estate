<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdminSupportController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\KycImageController;
use App\Http\Controllers\LandController;
use App\Http\Controllers\MonnifyWebhookController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\PinController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WithdrawalController;
use Illuminate\Support\Facades\Route;


// ─────────────────────────────────────────────────────────────────────────────
// PUBLIC (no auth)
// ─────────────────────────────────────────────────────────────────────────────

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

Route::post('/email/verify/code',         [AuthController::class, 'verifyEmailCode']);
Route::post('/email/resend-verification', [AuthController::class, 'resendVerification']);

Route::post('/password/reset/code',   [AuthController::class, 'sendPasswordResetCode']);
Route::post('/password/reset/verify', [AuthController::class, 'verifyPasswordResetCode']);
Route::post('/password/reset',        [AuthController::class, 'resetPassword']);

Route::post('/referrals/validate', [ReferralController::class, 'validateCode']);

Route::get('/land', [LandController::class, 'index']); // public land listing

Route::get('/support/faqs',          [SupportController::class, 'faqs']);
Route::post('/support/tickets/guest', [SupportController::class, 'storeGuestTicket']);

// ─────────────────────────────────────────────────────────────────────────────
// PAYMENT WEBHOOKS (no auth — verified by HMAC signature inside controller)
// ─────────────────────────────────────────────────────────────────────────────

Route::post('/paystack/webhook', [DepositController::class,    'handlePaystackWebhook']);
Route::post('/monnify/webhook',  [WithdrawalController::class, 'handleMonnifyWebhook']);

// ─────────────────────────────────────────────────────────────────────────────
// AUTHENTICATED (JWT required, account must not be suspended)
// ─────────────────────────────────────────────────────────────────────────────

Route::middleware(['jwt.auth', 'suspended'])->group(function () {

    // Auth lifecycle
    Route::get('/me',         [AuthController::class, 'me']);
    Route::post('/refresh',   [AuthController::class, 'refresh']);
    Route::post('/logout',    [AuthController::class, 'logout']);

    // Deposit verification (no email-verified requirement)
    Route::get('/deposit/verify/{reference}', [DepositController::class, 'verify']);

    // ─────────────────────────────────────────────────────────────────────
    // EMAIL VERIFIED
    // ─────────────────────────────────────────────────────────────────────
    Route::middleware('verified')->group(function () {

        // Profile & account
        Route::get('/me',              [ProfileController::class, 'me']);
        Route::put('/user/bank-details', [ProfileController::class, 'updateBankDetails']);
        Route::get('/user/stats',      [ProfileController::class, 'stats']);
        Route::get('/user/lands',      [ProfileController::class, 'lands']);
        Route::post('/user/change-password', [AuthController::class, 'changePassword']);

        // Transaction PIN  (rate-limited for forgot/verify via throttle.sensitive)
        Route::post('/pin/set',         [PinController::class, 'set']);
        Route::post('/pin/update',      [PinController::class, 'update']);
        Route::post('/pin/forgot',      [PinController::class, 'forgot'])->middleware('throttle.sensitive');
        Route::post('/pin/verify-code', [PinController::class, 'verifyCode'])->middleware('throttle.sensitive');
        Route::post('/pin/reset',       [PinController::class, 'reset'])->middleware('throttle.sensitive');

        // Transactions
        Route::get('/transactions/user', [TransactionController::class, 'userTransactions']);

        // Lands (authenticated view)
        Route::get('/lands',             [LandController::class, 'indexAuth']);
        Route::get('/lands/map',         [LandController::class, 'mapIndex']);
        Route::get('/lands/{land}',      [LandController::class, 'show']);
        Route::get('/lands/{land}/units',[LandController::class, 'units']);

        // Purchases & sales  (transaction PIN middleware applied per-route)
        Route::post('/lands/{land}/purchase', [PurchaseController::class, 'purchase'])->middleware('check.pin');
        Route::post('/lands/{land}/sell',     [PurchaseController::class, 'sellUnits'])->middleware('check.pin');

        // Portfolio
        Route::prefix('portfolio')->group(function () {
            Route::get('/summary',        [PortfolioController::class, 'summary']);
            Route::get('/chart',          [PortfolioController::class, 'chart']);
            Route::get('/performance',    [PortfolioController::class, 'performance']);
            Route::get('/allocation',     [PortfolioController::class, 'allocation']);
            Route::get('/asset/{land}',   [PortfolioController::class, 'asset']);
        });

        // Deposits
        Route::post('/deposit',           [DepositController::class, 'initiate']);
        Route::get('/paystack/banks',     [DepositController::class, 'banks']);
        Route::post('/paystack/resolve-account', [DepositController::class, 'resolveAccount']);

        // Withdrawals  (transaction PIN required)
        Route::post('/withdraw',                      [WithdrawalController::class, 'requestWithdrawal'])->middleware('check.pin');
        Route::get('/withdrawals/{reference}',        [WithdrawalController::class, 'show']);

        // KYC
        Route::get('/kyc/status',                     [KycController::class, 'status']);
        Route::post('/kyc/submit',                    [KycController::class, 'submit']);
        Route::get('/kyc/{id}/image/{imageType}',     [KycImageController::class, 'show'])->middleware('throttle:30,1');

        // Referrals
        Route::prefix('referrals')->group(function () {
            Route::get('/dashboard',              [ReferralController::class, 'dashboard']);
            Route::get('/rewards',                [ReferralController::class, 'rewards']);
            Route::post('/rewards/{id}/claim',    [ReferralController::class, 'claimReward']);
        });

        // Support (authenticated)
        Route::post('/support/chat',                          [SupportController::class, 'chat'])->middleware('throttle:20,10');
        Route::get('/support/tickets',                        [SupportController::class, 'indexTickets']);
        Route::post('/support/tickets',                       [SupportController::class, 'storeTicket']);
        Route::get('/support/tickets/{ticket}',               [SupportController::class, 'showTicket']);
        Route::post('/support/tickets/{ticket}/reply',        [SupportController::class, 'replyTicket']);

        // Notifications
        Route::prefix('notifications')->group(function () {
            Route::get('/',              [NotificationController::class, 'index']);
            Route::get('/unread',        [NotificationController::class, 'unread']);
            Route::post('/read',         [NotificationController::class, 'markAllRead']);
            Route::post('/{id}/read',    [NotificationController::class, 'markRead']);
        });

        // ─────────────────────────────────────────────────────────────────
        // ADMIN
        // ─────────────────────────────────────────────────────────────────
        Route::middleware('admin')->prefix('admin')->group(function () {

            // Lands
            Route::get('/lands',                           [LandController::class, 'adminIndex']);
            Route::post('/lands',                          [LandController::class, 'store']);
            Route::post('/lands/{land}',                   [LandController::class, 'update']);
            Route::patch('/lands/{land}/price',            [LandController::class, 'updatePrice']);
            Route::patch('/lands/{land}/availability',     [LandController::class, 'toggleAvailability']);

            // KYC
            Route::get('/kyc',                             [KycController::class, 'adminIndex']);
            Route::get('/kyc/{id}',                        [KycController::class, 'adminShow']);
            Route::post('/kyc/{id}/approve',               [KycController::class, 'adminApprove']);
            Route::post('/kyc/{id}/reject',                [KycController::class, 'adminReject']);
            Route::post('/kyc/{id}/resubmit',              [KycController::class, 'adminRequestResubmit']);

            // Referrals
            Route::get('/referrals',                       [ReferralController::class, 'adminIndex']);
            Route::get('/referrals/stats',                 [ReferralController::class, 'adminStats']);

            // Withdrawals
            Route::post('/withdrawals/retry',              [WithdrawalController::class, 'retryPendingWithdrawals']);

            // Users
            Route::patch('/users/{user}/suspend',          [AdminUserController::class, 'suspend']);
            Route::patch('/users/{user}/unsuspend',        [AdminUserController::class, 'unsuspend']);

            // Support tickets
            Route::prefix('support/tickets')->group(function () {
                Route::get('/',                           [AdminSupportController::class, 'index']);
                Route::get('/{ticket}',                   [AdminSupportController::class, 'show']);
                Route::post('/{ticket}/reply',            [AdminSupportController::class, 'reply']);
                Route::patch('/{ticket}/status',          [AdminSupportController::class, 'updateStatus']);
                Route::delete('/{ticket}',                [AdminSupportController::class, 'destroy']);
            });
        });
    });
});
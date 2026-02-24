<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LandController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WithdrawalController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\MonnifyWebhookController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\ReferralController;
use App\Http\Middleware\CheckTransactionPin;
use App\Http\Middleware\AdminMiddleware;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

// Auth
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Email verification
Route::post('/email/verify/code', [AuthController::class, 'verifyEmailCode']);
Route::post('/email/resend-verification', [AuthController::class, 'resendVerificationEmail']);

// Password reset
Route::prefix('password')->group(function () {
    Route::post('/reset/code',   [AuthController::class, 'sendPasswordResetCode']);
    Route::post('/reset/verify', [AuthController::class, 'verifyResetCode']);
    Route::post('/reset',        [AuthController::class, 'resetPassword']);
});

// Public referral code validation (for registration page)
Route::post('/referrals/validate', [ReferralController::class, 'validateCode']);

// Public land listing
Route::get('/land', [LandController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Webhooks — no auth, no throttle, signature-verified internally
|--------------------------------------------------------------------------
*/
Route::post('/paystack/webhook', [PaystackWebhookController::class, 'handle'])
    ->withoutMiddleware(['auth:api', 'throttle']);

Route::post('/monnify/webhook', [MonnifyWebhookController::class, 'handle'])
    ->withoutMiddleware(['auth:api', 'throttle']);

/*
|--------------------------------------------------------------------------
| Protected Routes (JWT required)
|--------------------------------------------------------------------------
*/
Route::middleware('jwt.auth')->group(function () {

    // Session
    Route::get('/me',       [AuthController::class, 'me']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    Route::get('/deposit/verify/{reference}', [DepositController::class, 'verifyDeposit']);

    /*
    |--------------------------------------------------------------------------
    | Verified email required for all routes below
    |--------------------------------------------------------------------------
    */
    Route::middleware('verified')->group(function () {

        // Auth
        Route::post('/logout',              [AuthController::class, 'logout']);
        Route::post('/user/change-password', [AuthController::class, 'changePassword']);

        /*
        |--------------------------------------------------------------------------
        | KYC
        |--------------------------------------------------------------------------
        */
        Route::prefix('kyc')->group(function () {
            Route::get('/status',  [KycController::class, 'status']);
            Route::post('/submit', [KycController::class, 'submit']);

            // Authenticated image endpoint from security fixes.
            // Returns a 5-minute signed URL for a KYC document.
            // Enforces owner-or-admin check inside the controller.
            Route::get('/{id}/image/{imageType}', [KycController::class, 'getImageUrl'])
                ->name('kyc.image')
                ->where('imageType', 'id_front|id_back|selfie');
        });

        /*
        |--------------------------------------------------------------------------
        | Referrals
        |--------------------------------------------------------------------------
        */
        Route::prefix('referrals')->group(function () {
            Route::get('/dashboard',              [ReferralController::class, 'dashboard']);
            Route::get('/rewards',                [ReferralController::class, 'availableRewards']);
            Route::post('/rewards/{rewardId}/claim', [ReferralController::class, 'claimReward']);
        });

        /*
        |--------------------------------------------------------------------------
        | Lands
        |--------------------------------------------------------------------------
        */
        Route::prefix('lands')->group(function () {

            // FIX 4: /lands/map was nested as /lands/lands/map due to
            // being inside the 'lands' prefix group. Fixed the path.
            Route::get('/map', [LandController::class, 'mapIndex']);

            // Listings & detail
            Route::get('/',     [LandController::class, 'index']);
            Route::get('/{id}', [LandController::class, 'show']);

            // User actions — require transaction PIN
            Route::post('/{id}/purchase', [PurchaseController::class, 'purchase'])
                ->middleware(CheckTransactionPin::class);

            Route::post('/{id}/sell', [PurchaseController::class, 'sellUnits'])
                ->middleware(CheckTransactionPin::class);

            Route::get('/{id}/units', [UserController::class, 'getUserUnitsForLand']);
        });

        /*
        |--------------------------------------------------------------------------
        | User
        |--------------------------------------------------------------------------
        */
        Route::prefix('user')->group(function () {
            Route::get('/lands',  [UserController::class, 'getAllUserLands']);
            Route::get('/stats',  [UserController::class, 'getUserStats']);
            Route::put('/bank-details', [UserController::class, 'updateBankDetails']);
        });

        Route::get('/transactions/user', [UserController::class, 'getUserTransactions']);

        /*
        |--------------------------------------------------------------------------
        | Portfolio
        |--------------------------------------------------------------------------
        */
        Route::prefix('portfolio')->group(function () {
            Route::get('/summary',      [PortfolioController::class, 'summary']);
            Route::get('/chart',        [PortfolioController::class, 'chart']);
            Route::get('/performance',  [PortfolioController::class, 'performance']);
            Route::get('/allocation',   [PortfolioController::class, 'allocation']);
            Route::get('/asset/{land}', [PortfolioController::class, 'asset']);
        });

        /*
        |--------------------------------------------------------------------------
        | Transaction PIN (for logged-in users managing their PIN)
        |--------------------------------------------------------------------------
        */
            Route::prefix('pin')->group(function () {
            Route::post('/set',    [UserController::class, 'setTransactionPin']);
            Route::post('/update', [UserController::class, 'updateTransactionPin']);
            Route::post('/verify-code', [UserController::class, 'verifyPinResetCode']);
            Route::post('/forgot',      [UserController::class, 'sendPinResetCode']);
            Route::post('/reset',       [UserController::class, 'resetTransactionPin']);
            // forgot/verify-code/reset are public (above) — user is logged out when they need them
        });

        /*
        |--------------------------------------------------------------------------
        | Deposits & Withdrawals
        |--------------------------------------------------------------------------
        */
        Route::post('/deposit', [DepositController::class, 'initiateDeposit']);

        Route::post('/withdraw', [WithdrawalController::class, 'requestWithdrawal'])
            ->middleware(CheckTransactionPin::class);

        Route::get('/withdrawals/{reference}', [WithdrawalController::class, 'getWithdrawalStatus']);

        /*
        |--------------------------------------------------------------------------
        | Bank & Paystack Helpers
        |--------------------------------------------------------------------------
        */
        Route::get('/paystack/banks',            [UserController::class, 'getBanks']);
        Route::post('/paystack/resolve-account', [UserController::class, 'resolveAccount']);

        /*
        |--------------------------------------------------------------------------
        | Notifications
        |--------------------------------------------------------------------------
        */
        Route::prefix('notifications')->group(function () {
            Route::get('/',          [NotificationController::class, 'getNotifications']);
            Route::get('/unread',    [NotificationController::class, 'getUnreadNotifications']);
            Route::post('/read',     [NotificationController::class, 'markAllAsRead']);
            Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        });

    }); // end verified

    /*
    |--------------------------------------------------------------------------
    | Admin Routes (JWT + verified + admin)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['verified', AdminMiddleware::class])->prefix('admin')->group(function () {

        // Lands
        Route::prefix('lands')->group(function () {
            Route::get('/',              [LandController::class, 'adminIndex']);
            Route::post('/',             [LandController::class, 'store']);
            Route::post('/{id}',         [LandController::class, 'update']);
            Route::patch('/{id}/disable', [LandController::class, 'disable']);
            Route::patch('/{id}/enable',  [LandController::class, 'enable']);
            Route::patch('/{land}/price', [LandController::class, 'updatePrice']);
        });

        // KYC management
        Route::prefix('kyc')->group(function () {
            Route::get('/',               [KycController::class, 'adminIndex']);
            Route::get('/{id}',           [KycController::class, 'adminShow']);
            Route::post('/{id}/approve',  [KycController::class, 'approve']);
            Route::post('/{id}/reject',   [KycController::class, 'reject']);
            Route::post('/{id}/resubmit', [KycController::class, 'requestResubmit']);
        });

        // Referrals
        Route::prefix('referrals')->group(function () {
            Route::get('/',      [ReferralController::class, 'adminIndex']);
            Route::get('/stats', [ReferralController::class, 'adminStats']);
        });

        Route::post('/withdrawals/retry', [WithdrawalController::class, 'retryPendingWithdrawals']);
    });

}); 
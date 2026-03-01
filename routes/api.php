<?php

use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\KycImageController;
use App\Http\Controllers\LandController;
use App\Http\Controllers\MonnifyWebhookController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WithdrawalController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\CheckTransactionPin;
use App\Http\Middleware\EnsureUserIsNotSuspended;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public / Unauthenticated Routes
|--------------------------------------------------------------------------
*/

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

Route::post('/email/verify/code',         [AuthController::class, 'verifyEmailCode'])
    ->middleware('throttle.sensitive');
Route::post('/email/resend-verification', [AuthController::class, 'resendVerificationEmail'])
    ->middleware('throttle.sensitive');

Route::prefix('password')->group(function () {
    Route::post('/reset/code',   [AuthController::class, 'sendPasswordResetCode'])->middleware('throttle.sensitive');
    Route::post('/reset/verify', [AuthController::class, 'verifyResetCode'])->middleware('throttle.sensitive');
    Route::post('/reset',        [AuthController::class, 'resetPassword'])->middleware('throttle.sensitive');
});

// Public referral code validation
Route::post('/referrals/validate', [ReferralController::class, 'validateCode']);

// Public land listing
Route::get('/land', [LandController::class, 'index']);

// FAQs
Route::get('/support/faqs', [SupportController::class, 'faqs']);

Route::post('/support/tickets/guest', [SupportController::class, 'storeGuestTicket']);

/*
|--------------------------------------------------------------------------
| Webhooks — signature-verified internally, bypasses auth + throttle
|--------------------------------------------------------------------------
*/
Route::post('/paystack/webhook', [PaystackWebhookController::class, 'handle'])
    ->withoutMiddleware(['auth:api', 'throttle']);
Route::post('/monnify/webhook', [MonnifyWebhookController::class, 'handle'])
    ->withoutMiddleware(['auth:api', 'throttle']);

/*
|--------------------------------------------------------------------------
| Protected Routes — JWT required + suspension check
|--------------------------------------------------------------------------
*/
Route::middleware(['jwt.auth', EnsureUserIsNotSuspended::class])->group(function () {

    Route::get('/me',       [AuthController::class, 'me']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    Route::get('/deposit/verify/{reference}', [DepositController::class, 'verifyDeposit']);

    /*
    |--------------------------------------------------------------------------
    | Verified email required
    |--------------------------------------------------------------------------
    */
    Route::middleware('verified')->group(function () {

        Route::post('/logout',               [AuthController::class, 'logout']);
        Route::post('/user/change-password', [AuthController::class, 'changePassword']);

        Route::prefix('support')->group(function () {
            Route::post('/chat',                   [SupportController::class, 'chat']);
            Route::get('/tickets',                 [SupportController::class, 'indexTickets']);
            Route::post('/tickets',                [SupportController::class, 'storeTicket']);
            Route::get('/tickets/{ticket}',        [SupportController::class, 'showTicket']);
            Route::post('/tickets/{ticket}/reply', [SupportController::class, 'replyTicket']);
        });

        /*
        | KYC
        */
        Route::prefix('kyc')->group(function () {
            Route::get('/status',  [KycController::class, 'status']);
            Route::post('/submit', [KycController::class, 'submit']);
            Route::get('/{id}/image/{imageType}', [KycImageController::class, 'show'])
                ->name('kyc.image')
                ->where('imageType', 'id_front|id_back|selfie');
        });

        /*
        | Referrals
        */
        Route::prefix('referrals')->group(function () {
            Route::get('/dashboard',                 [ReferralController::class, 'dashboard']);
            Route::get('/rewards',                   [ReferralController::class, 'availableRewards']);
            Route::post('/rewards/{rewardId}/claim', [ReferralController::class, 'claimReward']);
        });

        /*
        | Lands
        */
        Route::prefix('lands')->group(function () {
            Route::get('/map',      [LandController::class, 'mapIndex']);
            Route::get('/',         [LandController::class, 'index']);
            Route::get('/{id}',     [LandController::class, 'show']);
            Route::get('/{id}/units', [UserController::class, 'getUserUnitsForLand']);

            Route::post('/{id}/purchase', [PurchaseController::class, 'purchase'])
                ->middleware(CheckTransactionPin::class);
            Route::post('/{id}/sell', [PurchaseController::class, 'sellUnits'])
                ->middleware(CheckTransactionPin::class);
        });

        /*
        | User
        */
        Route::prefix('user')->group(function () {
            Route::get('/lands',        [UserController::class, 'getAllUserLands']);
            Route::get('/stats',        [UserController::class, 'getUserStats']);
            Route::put('/bank-details', [UserController::class, 'updateBankDetails']);
        });

        Route::get('/transactions/user', [UserController::class, 'getUserTransactions']);

        /*
        | Portfolio
        */
        Route::prefix('portfolio')->group(function () {
            Route::get('/summary',      [PortfolioController::class, 'summary']);
            Route::get('/chart',        [PortfolioController::class, 'chart']);
            Route::get('/performance',  [PortfolioController::class, 'performance']);
            Route::get('/allocation',   [PortfolioController::class, 'allocation']);
            Route::get('/asset/{land}', [PortfolioController::class, 'asset']);
        });

        /*
        | Transaction PIN — sensitive endpoints rate limited
        */
        Route::prefix('pin')->group(function () {
            Route::post('/set',    [UserController::class, 'setTransactionPin']);
            Route::post('/update', [UserController::class, 'updateTransactionPin']);

            Route::post('/forgot',      [UserController::class, 'sendPinResetCode'])->middleware('throttle.sensitive');
            Route::post('/verify-code', [UserController::class, 'verifyPinResetCode'])->middleware('throttle.sensitive');
            Route::post('/reset',       [UserController::class, 'resetTransactionPin'])->middleware('throttle.sensitive');
        });

        /*
        | Deposits & Withdrawals
        */
        Route::post('/deposit', [DepositController::class, 'initiateDeposit']);

        Route::post('/withdraw', [WithdrawalController::class, 'requestWithdrawal'])
            ->middleware(CheckTransactionPin::class);
        Route::get('/withdrawals/{reference}', [WithdrawalController::class, 'getWithdrawalStatus']);

        /*
        | Bank Helpers
        */
        Route::get('/paystack/banks',            [UserController::class, 'getBanks']);
        Route::post('/paystack/resolve-account', [UserController::class, 'resolveAccount']);

        /*
        | Notifications
        */
        Route::prefix('notifications')->group(function () {
            Route::get('/',           [NotificationController::class, 'getNotifications']);
            Route::get('/unread',     [NotificationController::class, 'getUnreadNotifications']);
            Route::post('/read',      [NotificationController::class, 'markAllAsRead']);
            Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        });

    }); // end verified

    /*
    |--------------------------------------------------------------------------
    | Admin Routes
    |--------------------------------------------------------------------------
    */
    Route::middleware(['verified', AdminMiddleware::class])->prefix('admin')->group(function () {

        Route::prefix('lands')->group(function () {
            Route::get('/',               [LandController::class, 'adminIndex']);
            Route::post('/',              [LandController::class, 'store']);
            Route::post('/{id}',          [LandController::class, 'update']);
            Route::patch('/{id}/disable', [LandController::class, 'disable']);
            Route::patch('/{id}/enable',  [LandController::class, 'enable']);
            Route::patch('/{land}/price', [LandController::class, 'updatePrice']);
        });

        Route::prefix('kyc')->group(function () {
            Route::get('/',               [KycController::class, 'adminIndex']);
            Route::get('/{id}',           [KycController::class, 'adminShow']);
            Route::post('/{id}/approve',  [KycController::class, 'approve']);
            Route::post('/{id}/reject',   [KycController::class, 'reject']);
            Route::post('/{id}/resubmit', [KycController::class, 'requestResubmit']);
            Route::get('/{id}/image/{imageType}', [KycImageController::class, 'show'])
                ->where('imageType', 'id_front|id_back|selfie');
        });

        Route::prefix('referrals')->group(function () {
            Route::get('/',      [ReferralController::class, 'adminIndex']);
            Route::get('/stats', [ReferralController::class, 'adminStats']);
        });

        Route::post('/withdrawals/retry', [WithdrawalController::class, 'retryPendingWithdrawals']);

        Route::prefix('users')->group(function () {
            Route::patch('/{user}/suspend',   [AdminUserController::class, 'suspend']);
            Route::patch('/{user}/unsuspend', [AdminUserController::class, 'unsuspend']);
        });
    });
});
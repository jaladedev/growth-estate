<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LandController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WithdrawalController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaystackWebhookController; 

use App\Http\Middleware\CheckTransactionPin;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

// Registration & Login
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Email verification
Route::post('/email/verify/code', [AuthController::class, 'verifyEmailCode']);
Route::post('/email/resend-verification', [AuthController::class, 'resendVerificationEmail']);

// Password reset (via code)
Route::prefix('password')->group(function () {
    Route::post('/reset/code', [AuthController::class, 'sendPasswordResetCode']);
    Route::post('/reset/verify', [AuthController::class, 'verifyResetCode']);
    Route::post('/reset', [AuthController::class, 'resetPassword']);
});

// Public lands listing
Route::get('/land', [LandController::class, 'index']);

// Deposit callback (signed)
Route::get('/deposit/callback', [DepositController::class, 'handleDepositCallback'])
    ->name('deposit.callback');

// Paystack withdrawal webhook (Public)
Route::post('/paystack/webhook', [PaystackWebhookController::class, 'handle']);


/*
|--------------------------------------------------------------------------
| Protected Routes (JWT Required)
|--------------------------------------------------------------------------
*/
Route::middleware('jwt.auth')->group(function () {

    // Logged-in user endpoints
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    /*
    |--------------------------------------------------------------------------
    | Email-Verified User Routes
    |--------------------------------------------------------------------------
    */
    Route::middleware('verified')->group(function () {

        // Change password
        Route::post('/user/change-password', [AuthController::class, 'changePassword']);

        // Logout
        Route::post('/logout', [AuthController::class, 'logout']);

        /*
        |--------------------------------------------------------------------------
        | Land Management
        |--------------------------------------------------------------------------
        */
        Route::prefix('lands')->group(function () {
            Route::get('/', [LandController::class, 'index']);
            Route::get('/{id}', [LandController::class, 'show']);
            Route::post('/', [LandController::class, 'store']);

            Route::post('/{id}/purchase', [PurchaseController::class, 'purchase'])
                ->middleware([CheckTransactionPin::class]);

            Route::post('/{id}/sell', [PurchaseController::class, 'sellUnits'])
                ->middleware([CheckTransactionPin::class]);

            Route::get('/{id}/units', [UserController::class, 'getUserUnitsForLand']);
        });

        // User land & stats
        Route::get('/user/lands', [UserController::class, 'getAllUserLands']);
        Route::get('/user/stats', [UserController::class, 'getUserStats']);
        Route::get('/transactions/user', [UserController::class, 'getUserTransactions']);

        /*
        |--------------------------------------------------------------------------
        | Transaction PIN
        |--------------------------------------------------------------------------
        */
        Route::post('/pin/forgot', [UserController::class, 'sendPinResetCode']);
        Route::post('/pin/verify-code', [UserController::class, 'verifyPinResetCode']);
        Route::post('/pin/reset', [UserController::class, 'resetTransactionPin']);
        Route::post('/pin/set', [UserController::class, 'setTransactionPin']);
        Route::post('/pin/update', [UserController::class, 'updateTransactionPin']);

        /*
        |--------------------------------------------------------------------------
        | Deposits & Withdrawals
        |--------------------------------------------------------------------------
        */
        Route::post('/deposit', [DepositController::class, 'initiateDeposit']);
        Route::post('/withdraw', [WithdrawalController::class, 'requestWithdrawal'])
            ->middleware([CheckTransactionPin::class]);

        Route::get('/withdrawals/{reference}', [WithdrawalController::class, 'getWithdrawalStatus']);
        Route::get('/withdrawal/retry', [WithdrawalController::class, 'retryPendingWithdrawals']);

        /*
        |--------------------------------------------------------------------------
        | Bank Details + Paystack Helpers
        |--------------------------------------------------------------------------
        */
        Route::put('/user/bank-details', [UserController::class, 'updateBankDetails']);
        Route::get('/paystack/banks', [UserController::class, 'getBanks']);
        Route::post('/paystack/resolve-account', [UserController::class, 'resolveAccount']);

        /*
        |--------------------------------------------------------------------------
        | Notifications
        |--------------------------------------------------------------------------
        */
        Route::get('/notifications', [NotificationController::class, 'getNotifications']);
        Route::get('/notifications/unread', [NotificationController::class, 'getUnreadNotifications']);
        Route::post('/notifications/read', [NotificationController::class, 'markAllAsRead']);
    });
});

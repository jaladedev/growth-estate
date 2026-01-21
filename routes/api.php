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
use App\Http\Controllers\MonnifyWebhookController;
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
    Route::post('/reset/code', [AuthController::class, 'sendPasswordResetCode']);
    Route::post('/reset/verify', [AuthController::class, 'verifyResetCode']);
    Route::post('/reset', [AuthController::class, 'resetPassword']);
});

// Public lands
Route::get('/land', [LandController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Paystack
|--------------------------------------------------------------------------
*/

// deposit webhook 
Route::post('/paystack/webhook', [PaystackWebhookController::class, 'handle'])
    ->withoutMiddleware(['auth:api', 'throttle']);
Route::post('/monnify/webhook', [MonnifyWebhookController::class, 'handle'])
   ->withoutMiddleware(['auth:api', 'throttle']);

// Paystack redirect (frontend only)
// Route::get('/deposit/callback', [DepositController::class, 'handleDepositCallback']);

// Frontend deposit status check
Route::get('/deposit/verify/{reference}', [DepositController::class, 'verifyDeposit']);


/*
|--------------------------------------------------------------------------
| Protected Routes (JWT)
|--------------------------------------------------------------------------
*/
Route::middleware('jwt.auth')->group(function () {

    // Session
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    /*
    |--------------------------------------------------------------------------
    | Verified Users
    |--------------------------------------------------------------------------
    */
    Route::middleware('verified')->group(function () {

        // Auth
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/user/change-password', [AuthController::class, 'changePassword']);

       Route::prefix('lands')->group(function () {

        /*
        |--------------------------------------------------------------------------
        | ADMIN ROUTES 
        |--------------------------------------------------------------------------
        */
            Route::get('/admin/show', [LandController::class, 'adminIndex'])
                ->middleware(AdminMiddleware::class);

            Route::post('/admin/create', [LandController::class, 'store'])
                ->middleware(AdminMiddleware::class);

            Route::post('/admin/{id}', [LandController::class, 'update'])
                ->middleware(AdminMiddleware::class);

            Route::patch('/admin/{id}/disable', [LandController::class, 'disable'])
                ->middleware(AdminMiddleware::class);

            Route::patch('/admin/{id}/enable', [LandController::class, 'enable'])
                ->middleware(AdminMiddleware::class);

            /*
            |--------------------------------------------------------------------------
            | USER ACTIONS
            |--------------------------------------------------------------------------
            */
            Route::post('/{id}/purchase', [PurchaseController::class, 'purchase'])
                ->middleware(CheckTransactionPin::class);

            Route::post('/{id}/sell', [PurchaseController::class, 'sellUnits'])
                ->middleware(CheckTransactionPin::class);

            Route::get('/{id}/units', [UserController::class, 'getUserUnitsForLand']);

            /*
            |--------------------------------------------------------------------------
            | VERIFIED USER
            |--------------------------------------------------------------------------
            */
            Route::get('/', [LandController::class, 'index']);
            Route::get('/lands/map', [LandController::class, 'mapIndex']);
            Route::get('/{id}', [LandController::class, 'show']);
        });


        // User
        Route::get('/user/lands', [UserController::class, 'getAllUserLands']);
        Route::get('/user/stats', [UserController::class, 'getUserStats']);
        Route::get('/transactions/user', [UserController::class, 'getUserTransactions']);

        Route::get('/portfolio/summary', [PortfolioController::class, 'summary']);
        Route::get('/portfolio/chart', [PortfolioController::class, 'chart']);
        Route::get('/portfolio/performance', [PortfolioController::class, 'performance']);
        Route::get('/portfolio/allocation', [PortfolioController::class, 'allocation']);
        Route::get('/portfolio/asset/{land}', [PortfolioController::class, 'asset']);
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
            ->middleware(CheckTransactionPin::class);

        Route::get('/withdrawals/{reference}', [WithdrawalController::class, 'getWithdrawalStatus']);
        Route::get('/withdrawal/retry', [WithdrawalController::class, 'retryPendingWithdrawals']);

        /*
        |--------------------------------------------------------------------------
        | Bank & Paystack Helpers
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

        /*
        |--------------------------------------------------------------------------
        | ADMIN ROUTES (JWT + Admin Role Required)
        |--------------------------------------------------------------------------
        */

        // Route::prefix('admin')
        //     ->middleware(['jwt.auth', AdminMiddleware::class])
        //     ->group(function () {

        //         // Dashboard Stats
        //         Route::get('/dashboard/stats', [AdminDashboardController::class, 'stats']);
        //         Route::get('/messages', [ContactController::class, 'index']);
        //         Route::get('/messages/{id}', [ContactController::class, 'show']);

});

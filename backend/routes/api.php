<?php

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CreatorController;
use App\Http\Controllers\Api\V1\CreatorDashboardController;
use App\Http\Controllers\Api\V1\CreatorRequestController;
use App\Http\Controllers\Api\V1\InfluencerController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\RequestController;
use App\Http\Controllers\Api\V1\ShortlistController;
use App\Http\Controllers\Api\V1\SocialAuthController;
use App\Http\Controllers\Api\V1\TeamController;
use Illuminate\Support\Facades\Route;

/*
| All routes here are served under /api/v1 (see bootstrap/app.php).
|
| Layers, outermost first:
|   auth:sanctum  -> who is calling
|   verified      -> e-mail confirmed
|   tenant        -> which company; turns on tenant scoping
|   permission:x  -> what they may do
*/

// ---- Public ----
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('register-creator', [AuthController::class, 'registerCreator']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);
    Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')->whereNumber('id')->name('verification.verify');

    // Continue with Google / Facebook (existing accounts only).
    Route::get('oauth/providers', [SocialAuthController::class, 'providers']);
    Route::post('oauth/exchange', [SocialAuthController::class, 'exchange']);
    Route::get('oauth/{provider}', [SocialAuthController::class, 'redirect'])->whereIn('provider', ['google', 'facebook']);
    Route::get('oauth/{provider}/callback', [SocialAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'facebook'])->name('oauth.callback');
});

Route::middleware('throttle:auth')->group(function () {
    Route::get('invitations/{token}', [InvitationController::class, 'show']);
    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept']);
});

// ---- Signed in (e-mail may still be unverified) ----
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('email/verification-notification', [AuthController::class, 'resendVerification'])
            ->middleware('throttle:6,1');
        Route::put('password', [AuthController::class, 'changePassword']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
        Route::get('sessions', [AuthController::class, 'sessions']);
        Route::delete('sessions/{id}', [AuthController::class, 'revokeSession'])->whereNumber('id');
    });

    // ---- In-app notifications (any signed-in, verified person) ----
    Route::middleware('verified')->prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('read-all', [NotificationController::class, 'readAll']);
        Route::post('{id}/read', [NotificationController::class, 'read'])->whereUuid('id');
    });

    // ---- Company workspace ----
    Route::middleware(['verified', 'tenant'])->group(function () {
        Route::get('company', [CompanyController::class, 'show']);
        Route::put('company', [CompanyController::class, 'update'])->middleware('permission:company.manage');

        Route::get('team/members', [TeamController::class, 'index']);
        Route::get('team/roles', [TeamController::class, 'roles']);

        Route::middleware('permission:team.manage')->group(function () {
            Route::put('team/members/{userId}', [TeamController::class, 'update'])->whereNumber('userId');
            Route::delete('team/members/{userId}', [TeamController::class, 'destroy'])->whereNumber('userId');
            Route::get('team/invitations', [InvitationController::class, 'index']);
            Route::post('team/invitations', [InvitationController::class, 'store']);
            Route::delete('team/invitations/{id}', [InvitationController::class, 'destroy'])->whereNumber('id');
        });

        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');

        // Brand features open once the platform team has approved the company.
        Route::middleware('company.approved')->group(function () {
            // Influencer discovery
            Route::middleware('permission:influencer.search')->group(function () {
                Route::get('influencers', [InfluencerController::class, 'index']);
                Route::get('influencers/filters', [InfluencerController::class, 'filters']);
            });
            Route::middleware('permission:influencer.view')->group(function () {
                Route::get('influencers/compare', [InfluencerController::class, 'compare']);
                Route::get('influencers/{id}', [InfluencerController::class, 'show'])->whereNumber('id');
                Route::get('shortlists', [ShortlistController::class, 'index']);
                Route::get('shortlists/{id}', [ShortlistController::class, 'show'])->whereNumber('id');
            });

            Route::middleware('permission:shortlist.manage')->group(function () {
                Route::post('shortlists', [ShortlistController::class, 'store']);
                Route::put('shortlists/{id}', [ShortlistController::class, 'update'])->whereNumber('id');
                Route::delete('shortlists/{id}', [ShortlistController::class, 'destroy'])->whereNumber('id');
                Route::post('shortlists/{id}/influencers', [ShortlistController::class, 'addInfluencers'])->whereNumber('id');
                Route::put('shortlists/{id}/influencers/{influencerId}', [ShortlistController::class, 'updateInfluencer'])
                    ->whereNumber(['id', 'influencerId']);
                Route::delete('shortlists/{id}/influencers/{influencerId}', [ShortlistController::class, 'removeInfluencer'])
                    ->whereNumber(['id', 'influencerId']);
            });

            // Requests to creators
            Route::middleware('permission:request.manage')->group(function () {
                Route::get('requests', [RequestController::class, 'index']);
                Route::post('requests', [RequestController::class, 'store'])->middleware('throttle:20,60'); // no spamming creators
                Route::delete('requests/{id}', [RequestController::class, 'withdraw'])->whereNumber('id');
            });
        });
    });

    // ---- Creator workspace (creators manage only their own profile) ----
    Route::prefix('creator')->middleware(['verified', 'creator'])->group(function () {
        Route::get('dashboard', [CreatorDashboardController::class, 'show']);
        Route::get('profile', [CreatorController::class, 'show']);
        Route::put('profile', [CreatorController::class, 'update']);
        Route::put('listing', [CreatorController::class, 'setListing']);
        Route::post('accounts', [CreatorController::class, 'storeAccount']);
        Route::put('accounts/{id}', [CreatorController::class, 'updateAccount'])->whereNumber('id');
        Route::delete('accounts/{id}', [CreatorController::class, 'destroyAccount'])->whereNumber('id');
        Route::post('packages', [CreatorController::class, 'storePackage']);
        Route::put('packages/{id}', [CreatorController::class, 'updatePackage'])->whereNumber('id');
        Route::delete('packages/{id}', [CreatorController::class, 'destroyPackage'])->whereNumber('id');

        // Requests from brands
        Route::get('requests', [CreatorRequestController::class, 'index']);
        Route::patch('requests/{id}', [CreatorRequestController::class, 'respond'])->whereNumber('id');
    });

    // ---- Platform administration ----
    Route::prefix('admin')->middleware('super_admin')->group(function () {
        Route::get('overview', [AdminController::class, 'overview']);
        Route::get('companies', [AdminController::class, 'companies']);
        Route::patch('companies/{id}', [AdminController::class, 'updateCompany'])->whereNumber('id');
        Route::post('companies/{id}/review', [AdminController::class, 'reviewCompany'])->whereNumber('id');
        Route::get('creators', [AdminController::class, 'creatorList']);
        Route::post('creators/{id}/review', [AdminController::class, 'reviewCreator'])->whereNumber('id');
        Route::get('users', [AdminController::class, 'users']);
        Route::post('users/bulk-delete', [AdminController::class, 'bulkDeleteUsers']);
        Route::post('companies/bulk-delete', [AdminController::class, 'bulkDeleteCompanies']);
        Route::post('creators/bulk-delete', [AdminController::class, 'bulkDeleteCreators']);
        Route::get('audit-logs', [AdminController::class, 'auditLogs']);
    });
});

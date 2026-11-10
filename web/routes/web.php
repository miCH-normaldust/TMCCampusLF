<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\ClaimListController;
use App\Http\Controllers\Admin\ClaimReviewController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FeedbackController;
use App\Http\Controllers\Admin\HandoverController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\PostRetrievalRequestController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SightingReviewController;
use App\Http\Controllers\Admin\SupportConversationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Middleware\TrackAdminRouteUsage;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');
Route::get('/admin/login', [AdminAuthController::class, 'create'])->name('admin.login');
Route::post('/admin/login', [AdminAuthController::class, 'store'])->middleware('throttle:5,1')->name('admin.login.store');
Route::get('/admin/forgot-password', [AdminAuthController::class, 'forgotPassword'])->name('admin.password.request');
Route::post('/admin/forgot-password', [AdminAuthController::class, 'sendPasswordResetCode'])->middleware('throttle:3,1')->name('admin.password.email');
Route::post('/admin/forgot-password/reset', [AdminAuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('admin.password.update');

Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'role:admin', TrackAdminRouteUsage::class])->group(function (): void {
    Route::post('/logout', [AdminAuthController::class, 'destroy'])->name('logout');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/community-feed', [DashboardController::class, 'communityFeed'])->name('community-feed.index');
    Route::get('/community-feed/posts/{post}', [DashboardController::class, 'communityPost'])->name('community-feed.show');
    Route::post('/community-feed/posts/{post}/moderate', [ModerationController::class, 'moderatePost'])->name('community-feed.moderate');
    Route::get('/claims', [ClaimListController::class, 'index'])->name('claims.index');
    Route::get('/claims/{claim}', [ClaimReviewController::class, 'show'])->name('claims.show');
    Route::post('/claims/{claim}', [ClaimReviewController::class, 'decide'])->name('claims.decide');
    Route::get('/handovers', [HandoverController::class, 'index'])->name('handovers.index');
    Route::get('/handovers/{handover}/manage', [HandoverController::class, 'manage'])->name('handovers.manage');
    Route::post('/handovers/{handover}/schedule', [HandoverController::class, 'schedule'])->name('handovers.schedule');
    Route::post('/handovers/{handover}/reschedule', [HandoverController::class, 'decideReschedule'])->name('handovers.reschedule');
    Route::post('/handovers/{handover}/complete', [HandoverController::class, 'complete'])->name('handovers.complete');
    Route::get('/handovers/{handover}/receipt', [HandoverController::class, 'receipt'])->name('handovers.receipt');
    Route::get('/handovers/history', [HandoverController::class, 'history'])->name('handovers.history');
    Route::get('/handovers/history/{handover}', [HandoverController::class, 'historyShow'])->name('handovers.history.show');
    Route::post('/retrieval-requests/{retrievalRequest}', [PostRetrievalRequestController::class, 'decide'])->name('retrieval-requests.decide');
    Route::post('/notifications/{notification}/read', [AdminNotificationController::class, 'markRead'])->name('notifications.read');
    Route::get('/reports', [ModerationController::class, 'index'])->name('reports.index');
    Route::post('/reports/{report}', [ModerationController::class, 'decide'])->name('reports.decide');
    Route::get('/feedback', [FeedbackController::class, 'index'])->name('feedback.index');
    Route::patch('/feedback/{feedback}', [FeedbackController::class, 'update'])->name('feedback.update');
    Route::get('/chats', [SupportConversationController::class, 'index'])->name('chats.index');
    Route::post('/chats/{conversation}/messages', [SupportConversationController::class, 'send'])->name('chats.send');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::post('/users/{user}/moderate', [UserController::class, 'moderate'])->name('users.moderate');
    Route::post('/users/{user}/restrictions/{restriction}/lift', [UserController::class, 'liftRestriction'])->name('users.restrictions.lift');
    Route::patch('/users/{user}/status', [UserController::class, 'setStatus'])->name('users.status');
    Route::post('/masterlist/import', [UserController::class, 'import'])->name('masterlist.import');
    Route::get('/masterlist/confirm', fn () => redirect()->route('admin.users.index')->withErrors([
        'masterlist' => 'Masterlist confirmation must be submitted from the preview page. Upload and preview the CSV, then use its Confirm import button.',
    ]));
    Route::post('/masterlist/confirm', [UserController::class, 'confirmImport'])->name('masterlist.confirm');
    Route::get('/sightings', [SightingReviewController::class, 'index'])->name('sightings.index');
    Route::post('/sightings/{sighting}', [SightingReviewController::class, 'decide'])->name('sightings.decide');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/catalog', [SettingsController::class, 'createCatalog'])->name('catalog.store');
    Route::patch('/catalog/{kind}/{id}', [SettingsController::class, 'setCatalogStatus'])->name('catalog.status');
    Route::post('/settings/handover-locations', [SettingsController::class, 'createHandoverLocation'])->name('settings.handover-locations.store');
    Route::patch('/settings/handover-locations/{location}/status', [SettingsController::class, 'setHandoverLocationStatus'])->name('settings.handover-locations.status');
    Route::get('/activity', [ActivityController::class, 'index'])->name('activity.index');
    Route::get('/activity/export', [ActivityController::class, 'export'])->name('activity.export');
    Route::get('/audit', [ActivityController::class, 'audit'])->name('audit.index');
});

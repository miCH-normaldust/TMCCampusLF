<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\ClaimListController;
use App\Http\Controllers\Admin\ClaimReviewController;
use App\Http\Controllers\Admin\CommunityFeedController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FeedbackController;
use App\Http\Controllers\Admin\HandoverController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SightingReviewController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');
Route::get('/admin/login', [AdminAuthController::class, 'create'])->name('admin.login');
Route::post('/admin/login', [AdminAuthController::class, 'store'])->middleware('throttle:5,1')->name('admin.login.store');

Route::prefix('admin')->name('admin.')->middleware(['auth:admin', 'role:admin'])->group(function (): void {
    Route::post('/logout', [AdminAuthController::class, 'destroy'])->name('logout');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/community-feed', [CommunityFeedController::class, 'index'])->name('community-feed.index');
    Route::get('/claims', [ClaimListController::class, 'index'])->name('claims.index');
    Route::get('/claims/{claim}', [ClaimReviewController::class, 'show'])->name('claims.show');
    Route::post('/claims/{claim}', [ClaimReviewController::class, 'decide'])->name('claims.decide');
    Route::get('/handovers', [HandoverController::class, 'index'])->name('handovers.index');
    Route::post('/handovers/verify', [HandoverController::class, 'verify'])->name('handovers.verify');
    Route::post('/notifications/{notification}/read', [AdminNotificationController::class, 'markRead'])->name('notifications.read');
    Route::get('/reports', [ModerationController::class, 'index'])->name('reports.index');
    Route::post('/reports/{report}', [ModerationController::class, 'decide'])->name('reports.decide');
    Route::get('/feedback', [FeedbackController::class, 'index'])->name('feedback.index');
    Route::patch('/feedback/{feedback}', [FeedbackController::class, 'update'])->name('feedback.update');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::patch('/users/{user}/status', [UserController::class, 'setStatus'])->name('users.status');
    Route::post('/masterlist/import', [UserController::class, 'import'])->name('masterlist.import');
    Route::post('/masterlist/confirm', [UserController::class, 'confirmImport'])->name('masterlist.confirm');
    Route::get('/sightings', [SightingReviewController::class, 'index'])->name('sightings.index');
    Route::post('/sightings/{sighting}', [SightingReviewController::class, 'decide'])->name('sightings.decide');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/catalog', [SettingsController::class, 'createCatalog'])->name('catalog.store');
    Route::patch('/catalog/{kind}/{id}', [SettingsController::class, 'setCatalogStatus'])->name('catalog.status');
    Route::post('/meetups', [SettingsController::class, 'createMeetup'])->name('meetups.store');
    Route::patch('/meetups/{location}', [SettingsController::class, 'setMeetupStatus'])->name('meetups.status');
    Route::get('/activity', [ActivityController::class, 'index'])->name('activity.index');
    Route::get('/activity/export', [ActivityController::class, 'export'])->name('activity.export');
    Route::get('/audit', [ActivityController::class, 'audit'])->name('audit.index');
});

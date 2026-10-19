<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\InteractionController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PostController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/captcha', [AuthController::class, 'captcha'])->middleware('throttle:10,1');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:5,1');
    Route::post('/resend-otp', [AuthController::class, 'resendOtp'])->middleware('throttle:3,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::get('/catalog', [CatalogController::class, 'index']);
    Route::post('/posts/similar', [PostController::class, 'similar'])->middleware('throttle:15,1');
    Route::get('/posts', [PostController::class, 'index']);
    Route::get('/posts/{post}', [PostController::class, 'show']);
    Route::get('/posts/{post}/comments', [InteractionController::class, 'comments']);

    Route::middleware(['auth:sanctum', 'role:user'])->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/profile', [AuthController::class, 'profile']);
        Route::get('/me', [AuthController::class, 'profile']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::put('/password', [AuthController::class, 'changePassword']);
        Route::post('/device-token', [DeviceTokenController::class, 'store']);
        Route::delete('/device-token', [DeviceTokenController::class, 'destroy']);
        Route::post('/feedback', [FeedbackController::class, 'store'])->middleware('throttle:5,1');
        Route::post('/posts', [PostController::class, 'store'])->middleware('throttle:10,1');
        Route::put('/posts/{post}', [PostController::class, 'update']);
        Route::patch('/posts/{post}/keep-active', [PostController::class, 'keepActive']);
        Route::delete('/posts/{post}', [PostController::class, 'destroy']);
        Route::get('/posts/{post}/matches', [PostController::class, 'matches']);
        Route::post('/posts/{post}/claims', [ClaimController::class, 'store'])->middleware('throttle:5,1');
        Route::post('/posts/{post}/comments', [InteractionController::class, 'comment']);
        Route::delete('/comments/{comment}', [InteractionController::class, 'deleteComment']);
        Route::post('/posts/{post}/reactions', [InteractionController::class, 'react']);
        Route::post('/posts/{post}/reports', [InteractionController::class, 'report'])->middleware('throttle:5,1');
        Route::post('/posts/{post}/sightings', [InteractionController::class, 'sighting']);
        Route::get('/posts/{post}/sightings', [InteractionController::class, 'sightings']);
        Route::patch('/sightings/{sighting}', [InteractionController::class, 'decideSighting']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::get('/meetup-locations', [ConversationController::class, 'locations']);
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
        Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'send'])->middleware('throttle:30,1');
        Route::patch('/conversations/{conversation}/location', [ConversationController::class, 'chooseLocation']);
        Route::post('/conversations/{conversation}/handover', [ConversationController::class, 'createHandover']);
        Route::post('/handovers/{handover}/confirm', [ConversationController::class, 'confirmHandover']);
        Route::get('/handovers', [ConversationController::class, 'handovers']);
        Route::post('/sightings/{sighting}/handover', [ConversationController::class, 'startSightingHandover']);
    });
});

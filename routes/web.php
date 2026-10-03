<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\ClientProfileController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/admin', function () {
    return Inertia::render('Admin/Dashboard');
})->middleware(['auth', 'role:admin'])->name('admin.dashboard');

Route::middleware(['auth', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/activity-log', [ActivityLogController::class, 'index'])->name('admin.activity-log');
});

Route::middleware(['auth', 'role:client'])->group(function () {
    Route::get('/client-profile', [ClientProfileController::class, 'edit'])->name('client-profile.edit');
    Route::patch('/client-profile', [ClientProfileController::class, 'update'])->name('client-profile.update');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';

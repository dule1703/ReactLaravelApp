<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\PriceController;
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

    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    Route::get('/clients/{clientProfile}', [ClientController::class, 'edit'])->name('clients.edit');
    Route::patch('/clients/{clientProfile}', [ClientController::class, 'update'])->name('clients.update');
    Route::delete('/clients/{clientProfile}', [ClientController::class, 'destroy'])->name('clients.destroy');
    Route::post('/clients/{clientProfile}/reveal', [ClientController::class, 'reveal'])
        ->middleware('throttle:20,1')->name('clients.reveal');
    Route::delete('/clients/{clientProfile}/jmbg', [ClientController::class, 'destroyJmbg'])->name('clients.jmbg.destroy');

    Route::get('/prices', [PriceController::class, 'index'])->name('prices.index');
    Route::patch('/prices/versions/{version}', [PriceController::class, 'updateVersion'])->name('prices.versions.update');
    Route::patch('/prices/equipment/{trimEquipment}', [PriceController::class, 'updateEquipment'])->name('prices.equipment.update');
    Route::patch('/prices/vat', [PriceController::class, 'updateVat'])->name('prices.vat.update');
    Route::post('/prices/bulk/preview', [PriceController::class, 'bulkPreview'])->name('prices.bulk.preview');
    Route::post('/prices/bulk/apply', [PriceController::class, 'bulkApply'])->name('prices.bulk.apply');
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

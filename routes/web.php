<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\Catalog\CarModelController;
use App\Http\Controllers\Admin\Catalog\CategoryController;
use App\Http\Controllers\Admin\Catalog\EngineController;
use App\Http\Controllers\Admin\Catalog\TransmissionController;
use App\Http\Controllers\Admin\Catalog\TrimController;
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

    Route::redirect('/catalog', '/admin/catalog/models');
    Route::prefix('catalog')->name('catalog.')->group(function () {
        Route::get('/models', [CarModelController::class, 'index'])->name('models.index');
        Route::post('/models', [CarModelController::class, 'store'])->name('models.store');
        Route::patch('/models/{carModel}', [CarModelController::class, 'update'])->name('models.update');
        Route::patch('/models/{carModel}/active', [CarModelController::class, 'active'])->name('models.active');
        Route::delete('/models/{carModel}', [CarModelController::class, 'destroy'])->name('models.destroy');

        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::patch('/categories/{category}/active', [CategoryController::class, 'active'])->name('categories.active');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        Route::get('/trims', [TrimController::class, 'index'])->name('trims.index');
        Route::post('/trims', [TrimController::class, 'store'])->name('trims.store');
        Route::patch('/trims/{trim}', [TrimController::class, 'update'])->name('trims.update');
        Route::patch('/trims/{trim}/active', [TrimController::class, 'active'])->name('trims.active');
        Route::delete('/trims/{trim}', [TrimController::class, 'destroy'])->name('trims.destroy');

        Route::get('/engines', [EngineController::class, 'index'])->name('engines.index');
        Route::post('/engines', [EngineController::class, 'store'])->name('engines.store');
        Route::patch('/engines/{engine}', [EngineController::class, 'update'])->name('engines.update');
        Route::patch('/engines/{engine}/active', [EngineController::class, 'active'])->name('engines.active');
        Route::delete('/engines/{engine}', [EngineController::class, 'destroy'])->name('engines.destroy');

        Route::get('/transmissions', [TransmissionController::class, 'index'])->name('transmissions.index');
        Route::post('/transmissions', [TransmissionController::class, 'store'])->name('transmissions.store');
        Route::patch('/transmissions/{transmission}', [TransmissionController::class, 'update'])->name('transmissions.update');
        Route::patch('/transmissions/{transmission}/active', [TransmissionController::class, 'active'])->name('transmissions.active');
        Route::delete('/transmissions/{transmission}', [TransmissionController::class, 'destroy'])->name('transmissions.destroy');
    });
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

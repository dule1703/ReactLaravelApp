<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\Catalog\CarModelController;
use App\Http\Controllers\Admin\Catalog\CategoryController;
use App\Http\Controllers\Admin\Catalog\EngineController;
use App\Http\Controllers\Admin\Catalog\EquipmentItemController;
use App\Http\Controllers\Admin\Catalog\MatrixController;
use App\Http\Controllers\Admin\Catalog\OptionGroupController;
use App\Http\Controllers\Admin\Catalog\TransmissionController;
use App\Http\Controllers\Admin\Catalog\TrimController;
use App\Http\Controllers\Admin\Catalog\VersionController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\IssuerProfileController;
use App\Http\Controllers\Admin\PriceController;
use App\Http\Controllers\ClientDashboardController;
use App\Http\Controllers\ClientProfileController;
use App\Http\Controllers\OfferCatalogController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\OfferPdfController;
use App\Http\Controllers\OfferStatusController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
});

Route::get('/dashboard', [ClientDashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('dashboard');

Route::get('/admin', [DashboardController::class, 'index'])->middleware(['auth', 'role:admin'])->name('admin.dashboard');

Route::middleware(['auth', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/activity-log', [ActivityLogController::class, 'index'])->name('admin.activity-log');

    Route::get('/issuer', [IssuerProfileController::class, 'edit'])->name('issuer.edit');
    Route::patch('/issuer', [IssuerProfileController::class, 'update'])->name('issuer.update');

    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    // The salon flow (4.5c). /clients/create goes BEFORE /clients/{clientProfile}.
    Route::get('/clients/create', [ClientController::class, 'create'])->name('clients.create');
    Route::post('/clients', [ClientController::class, 'store'])->middleware('throttle:client-create')->name('clients.store');
    Route::get('/clients/{clientProfile}', [ClientController::class, 'edit'])->name('clients.edit');
    Route::patch('/clients/{clientProfile}', [ClientController::class, 'update'])->name('clients.update');
    Route::delete('/clients/{clientProfile}', [ClientController::class, 'destroy'])->name('clients.destroy');
    Route::post('/clients/{clientProfile}/reveal', [ClientController::class, 'reveal'])
        ->middleware('throttle:client-reveal')->name('clients.reveal');
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
        Route::get('/versions', [VersionController::class, 'index'])->name('versions.index');
        Route::post('/versions', [VersionController::class, 'store'])->name('versions.store');
        Route::patch('/versions/{version}/active', [VersionController::class, 'active'])->name('versions.active');
        Route::delete('/versions/{version}', [VersionController::class, 'destroy'])->name('versions.destroy');

        Route::get('/equipment', [EquipmentItemController::class, 'index'])->name('equipment.index');
        Route::post('/equipment', [EquipmentItemController::class, 'store'])->name('equipment.store');
        Route::patch('/equipment/{equipmentItem}', [EquipmentItemController::class, 'update'])->name('equipment.update');
        Route::patch('/equipment/{equipmentItem}/active', [EquipmentItemController::class, 'active'])->name('equipment.active');
        Route::delete('/equipment/{equipmentItem}', [EquipmentItemController::class, 'destroy'])->name('equipment.destroy');

        Route::get('/option-groups', [OptionGroupController::class, 'index'])->name('option-groups.index');
        Route::post('/option-groups', [OptionGroupController::class, 'store'])->name('option-groups.store');
        Route::patch('/option-groups/{optionGroup}', [OptionGroupController::class, 'update'])->name('option-groups.update');
        Route::patch('/option-groups/{optionGroup}/active', [OptionGroupController::class, 'active'])->name('option-groups.active');
        Route::delete('/option-groups/{optionGroup}', [OptionGroupController::class, 'destroy'])->name('option-groups.destroy');

        Route::get('/matrix', [MatrixController::class, 'index'])->name('matrix.index');
        Route::put('/matrix/cell', [MatrixController::class, 'cell'])->name('matrix.cell');
        Route::delete('/matrix/group', [MatrixController::class, 'group'])->name('matrix.group');

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
    Route::patch('/client-profile', [ClientProfileController::class, 'update'])->middleware('throttle:writes')->name('client-profile.update');
});

// Offer configurator (4.5b client, 4.5d admin on behalf of a client). Signed-in users only: every
// action authorizes through OfferPolicy (create: admin and client; chooseClient: admin only).
// Keep /offers/new and /offers/catalog/* before any /offers/{offer} route (that one is numeric).
Route::middleware('auth')->group(function () {
    Route::get('/offers/new', [OfferController::class, 'create'])->name('offers.create');
    Route::post('/offers', [OfferController::class, 'store'])->middleware('throttle:offers-store')->name('offers.store');
    Route::prefix('offers/catalog')->name('offers.catalog.')->middleware('throttle:catalog-read')->group(function () {
        Route::get('/models/{carModel}/versions', [OfferCatalogController::class, 'versions'])->whereNumber('carModel')->name('versions');
        Route::get('/versions/{version}', [OfferCatalogController::class, 'version'])->whereNumber('version')->name('version');
        Route::get('/clients', [OfferCatalogController::class, 'clients'])->name('clients');
    });
});

// Offers of both roles (4.6): the list is narrowed by role, the Policy decides who may open one.
// Registered after /offers/new; the parameter is numeric so 'new' and 'abc' never match.
Route::middleware('auth')->group(function () {
    Route::get('/offers', [OfferController::class, 'index'])->name('offers.index');
    Route::get('/offers/{offer}', [OfferController::class, 'show'])->whereNumber('offer')->name('offers.show');
    Route::patch('/offers/{offer}/note', [OfferController::class, 'updateNote'])->whereNumber('offer')->middleware('throttle:writes')->name('offers.note.update');
    Route::get('/offers/{offer}/pdf', OfferPdfController::class)->whereNumber('offer')->middleware('throttle:pdf')->name('offers.pdf');
    Route::post('/offers/{offer}/withdraw', [OfferStatusController::class, 'withdraw'])->whereNumber('offer')->middleware('throttle:writes')->name('offers.withdraw');
    Route::post('/offers/{offer}/withdrawal/revert', [OfferStatusController::class, 'revertWithdrawal'])->whereNumber('offer')->middleware('throttle:writes')->name('offers.withdrawal.revert');
    Route::delete('/offers/{offer}', [OfferStatusController::class, 'destroy'])->whereNumber('offer')->middleware('throttle:writes')->name('offers.destroy');
    // withTrashed: the only route that opens a deleted offer (to restore it); the Policy keeps it admin-only.
    Route::post('/offers/{offer}/restore', [OfferStatusController::class, 'restore'])->whereNumber('offer')->withTrashed()->middleware('throttle:writes')->name('offers.restore');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('throttle:writes')->name('profile.update');
});

require __DIR__.'/auth.php';

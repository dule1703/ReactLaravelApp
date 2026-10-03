<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Enums\DriveType;
use App\Enums\TransmissionType;
use App\Http\Requests\Admin\Catalog\SetActiveRequest;
use App\Http\Requests\Admin\Catalog\TransmissionRequest;
use App\Models\Transmission;
use App\Models\Version;
use App\Support\Like;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TransmissionController extends CatalogController
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Transmission::class);

        $term = $this->term($request);

        $transmissions = Transmission::query()
            ->withCount('versions')
            ->when($term !== '', fn ($query) => $query->whereRaw("name like ? escape '!'", [Like::contains($term)]))
            ->orderBy('name')->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Transmission $transmission) => [
                'id' => $transmission->id,
                'name' => $transmission->name,
                'type' => $transmission->type->value,
                'drive' => $transmission->drive->value,
                'is_active' => $transmission->is_active,
                'versions_count' => $transmission->versions_count,
                'available_versions' => Version::available()->where('versions.transmission_id', $transmission->id)->count(),
            ]);

        return Inertia::render('Admin/Catalog/Transmissions', [
            'items' => $transmissions,
            'filters' => ['q' => $term],
            'types' => array_map(fn (TransmissionType $type) => $type->value, TransmissionType::cases()),
            'drives' => array_map(fn (DriveType $drive) => $drive->value, DriveType::cases()),
        ]);
    }

    public function store(TransmissionRequest $request): RedirectResponse
    {
        Transmission::create($request->validated() + ['is_active' => true]);

        return back()->with('success', __('Added.'));
    }

    public function update(TransmissionRequest $request, Transmission $transmission): RedirectResponse
    {
        $transmission->update($request->validated());

        return back()->with('success', __('Saved.'));
    }

    public function active(SetActiveRequest $request, Transmission $transmission): RedirectResponse
    {
        return $this->setActive($request, $transmission);
    }

    public function destroy(Transmission $transmission): RedirectResponse
    {
        return $this->deleteIfUnused($transmission, 'transmission', ['versions' => $transmission->versions()->count()]);
    }
}

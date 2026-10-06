<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'nav' => fn () => $this->navigation($request),
            // Flash data lives for exactly one request: it is shown once and then gone.
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * Navigation per role. An item whose route does not exist yet is sent as "soon"
     * (disabled); it activates by itself once the route is registered, no layout change needed.
     *
     * @return list<array{key: string, label: string, href: ?string, pattern: string, soon: bool}>
     */
    private function navigation(Request $request): array
    {
        $user = $request->user();

        if (! $user) {
            return [];
        }

        $items = $user->isAdmin()
            ? [
                ['admin', 'Administration', 'admin.dashboard', 'admin.dashboard'],
                ['activity-log', 'Activity log', 'admin.activity-log', 'admin.activity-log'],
                ['clients', 'Clients', 'clients.index', 'clients.*'],
                ['catalog', 'Catalog', 'catalog.models.index', 'catalog.*'],
                ['prices', 'Prices', 'prices.index', 'prices.*'],
                ['new-offer', 'New offer', 'offers.create', 'offers.create'],
                ['offers', 'Offers', 'offers.index', 'offers.*'],
            ]
            : [
                ['dashboard', 'Dashboard', 'dashboard', 'dashboard'],
                ['profile', 'My profile', 'client-profile.edit', 'client-profile.*'],
                ['new-offer', 'New offer', 'offers.create', 'offers.create'],
                ['offers', 'Offers', 'offers.index', 'offers.*'],
            ];

        return array_map(fn (array $item) => [
            'key' => $item[0],
            'label' => __($item[1]),
            'href' => Route::has($item[2]) ? route($item[2], absolute: false) : null,
            'pattern' => $item[3],
            'soon' => ! Route::has($item[2]),
        ], $items);
    }
}

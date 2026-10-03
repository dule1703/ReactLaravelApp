<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Catalog\SetActiveRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Shared behaviour of the catalog CRUD screens. Rows are never removed while something depends
 * on them (offers rely on the catalog): the screen deactivates instead, and a delete is refused
 * with the dependency counts. Everything is written through the models (activity log).
 */
abstract class CatalogController extends Controller
{
    protected const PER_PAGE = 25;

    protected function term(Request $request): string
    {
        return mb_substr(trim((string) $request->query('q', '')), 0, 100);
    }

    protected function setActive(SetActiveRequest $request, Model $model): RedirectResponse
    {
        Gate::authorize('update', $model);

        $model->update(['is_active' => $request->boolean('is_active')]);

        return back()->with('success', __('Status changed.'));
    }

    /**
     * Delete only a row without dependents. The counts are checked BEFORE deleting; the
     * foreign key RESTRICT (QueryException) is the safety net for a race.
     *
     * @param  array<string, int>  $dependencies  dependency key (see "dependency.*" translations) => count
     * @param  (callable(Model): mixed)|null  $beforeDelete  runs in the same transaction as the delete
     *                                                       (e.g. detaching links that do not block it)
     * @param  (callable(): mixed)|null  $afterDelete  runs only after the delete committed (e.g. removing a file)
     */
    protected function deleteIfUnused(
        Model $model,
        string $entityKey,
        array $dependencies,
        ?callable $beforeDelete = null,
        ?callable $afterDelete = null,
    ): RedirectResponse {
        Gate::authorize('delete', $model);

        $used = array_filter($dependencies);

        if ($used !== []) {
            return back()->with('error', $this->blockedMessage($entityKey, $used));
        }

        try {
            DB::transaction(function () use ($model, $beforeDelete) {
                if ($beforeDelete !== null) {
                    $beforeDelete($model);
                }

                $model->delete();
            });
        } catch (QueryException) {
            return back()->with('error', $this->blockedMessage($entityKey, []));
        }

        if ($afterDelete !== null) {
            $afterDelete();
        }

        return back()->with('success', __('Deleted.'));
    }

    /**
     * @param  array<string, int>  $used
     */
    private function blockedMessage(string $entityKey, array $used): string
    {
        $details = collect($used)
            ->map(fn (int $count, string $key) => __("dependency.$key").': '.$count)
            ->implode(', ');

        $entity = __("catalog.entity.$entityKey");

        return $details === ''
            ? __(':entity has dependent rows. Deactivate it instead of deleting.', ['entity' => $entity])
            : __(':entity has dependent rows (:details). Deactivate it instead of deleting.', ['entity' => $entity, 'details' => $details]);
    }
}

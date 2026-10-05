<?php

namespace Tests\Concerns;

use App\Models\ActivityLog;
use App\Models\CarModel;
use App\Models\Category;
use App\Models\Engine;
use App\Models\EquipmentItem;
use App\Models\OptionGroup;
use App\Models\Setting;
use App\Models\Transmission;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\Version;
use Illuminate\Support\Facades\DB;

trait UsesRealCatalogFiles
{
    /**
     * @return array<string, mixed>
     */
    protected function sample(): array
    {
        return require base_path('tests/fixtures/real_catalog_sample.php');
    }

    /**
     * Point the seeder and the validate command at a catalog given as an array.
     *
     * @param  array<string, mixed>  $catalog
     */
    protected function useCatalog(array $catalog): string
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog').'.php';
        file_put_contents($path, '<?php return '.var_export($catalog, true).';');
        config(['catalog.real_catalog_path' => $path]);

        return $path;
    }

    /**
     * @return array<string, int>
     */
    protected function catalogCounts(): array
    {
        return [
            'category' => Category::count(),
            'option_group' => OptionGroup::count(),
            'car_model' => CarModel::count(),
            'trim' => Trim::count(),
            'engine' => Engine::count(),
            'transmission' => Transmission::count(),
            'version' => Version::count(),
            'equipment_item' => EquipmentItem::count(),
            'trim_equipment' => TrimEquipment::count(),
        ];
    }

    protected function assertNothingWritten(): void
    {
        $this->assertSame(array_fill_keys(array_keys($this->catalogCounts()), 0), $this->catalogCounts());
        $this->assertSame(0, DB::table('car_model_category')->count());
        $this->assertNull(Setting::where('key', 'catalog_real_seeded_at')->first());
        $this->assertSame(0, ActivityLog::where('action', 'catalog.real_seeded')->count());
    }
}

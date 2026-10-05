<?php

namespace Tests\Concerns;

use App\Enums\EquipmentCategory;
use App\Models\CarModel;
use App\Models\Engine;
use App\Models\EquipmentItem;
use App\Models\OptionGroup;
use App\Models\Transmission;
use App\Models\Trim;
use App\Models\TrimEquipment;
use App\Models\User;
use App\Models\Version;

/**
 * A small catalog for the offer tests: one available version (2,500,000 cents net) with a plain
 * extra, a paint group (standard "Bela", surcharge "Metalik") and a standard item.
 */
trait BuildsOfferCatalog
{
    protected Version $version;

    protected EquipmentItem $climatronic;

    protected EquipmentItem $metallic;

    protected EquipmentItem $white;

    protected EquipmentItem $alarm;

    protected function buildCatalog(): void
    {
        $trim = Trim::factory()->create([
            'car_model_id' => CarModel::factory()->create(['name' => 'Octavia']),
            'name' => 'Style',
        ]);
        $this->version = Version::factory()->create([
            'trim_id' => $trim->id,
            'engine_id' => Engine::factory()->create(),
            'transmission_id' => Transmission::factory()->create(),
            'base_price_cents' => 2_500_000,
        ]);

        $this->climatronic = $this->extra(150_000, null, 'Climatronic');
        $this->alarm = EquipmentItem::factory()->create(['name' => 'Alarm', 'category' => EquipmentCategory::Safety]);
        TrimEquipment::factory()->create(['trim_id' => $trim->id, 'equipment_item_id' => $this->alarm->id]);

        $paint = OptionGroup::factory()->create(['name' => 'Boja karoserije', 'category' => EquipmentCategory::Exterior, 'uses_swatch' => true]);
        $this->white = EquipmentItem::factory()->create(['group_id' => $paint->id, 'category' => $paint->category, 'name' => 'Bela', 'swatch_hex' => '#FFFFFF']);
        TrimEquipment::factory()->create(['trim_id' => $trim->id, 'equipment_item_id' => $this->white->id]);
        $this->metallic = $this->extra(120_000, $paint, 'Metalik', ['swatch_hex' => '#8A8D8F']);
    }

    protected function extra(int $price, ?OptionGroup $group = null, string $name = 'Oprema', array $attributes = []): EquipmentItem
    {
        $item = EquipmentItem::factory()->create(array_merge(
            ['name' => $name],
            $group ? ['group_id' => $group->id, 'category' => $group->category] : ['category' => EquipmentCategory::Comfort],
            $attributes,
        ));

        TrimEquipment::factory()->optional($price)->create(['trim_id' => $this->version->trim_id, 'equipment_item_id' => $item->id]);

        return $item;
    }

    /** A client with a complete profile (what an offer needs). */
    protected function clientWithProfile(array $profile = []): User
    {
        $user = User::factory()->client()->create();
        $user->profile()->forceFill(array_merge([
            'full_name' => 'Petar Petrović', 'address' => 'Knez Mihailova 1',
            'postal_code' => '11000', 'city' => 'Beograd', 'country' => 'RS',
        ], $profile))->save();

        return $user->fresh();
    }
}

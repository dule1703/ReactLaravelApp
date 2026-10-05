<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\Catalog\EquipmentItemImageRequest;
use App\Models\ActivityLog;
use App\Models\EquipmentItem;
use App\Models\User;
use App\Services\EquipmentItemImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Tests\Concerns\MakesImages;
use Tests\TestCase;

/**
 * No screen yet (3.10): the service and the validation of the equipment item image.
 */
class EquipmentItemImageTest extends TestCase
{
    use MakesImages, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function service(): EquipmentItemImages
    {
        return app(EquipmentItemImages::class);
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        return Storage::disk('public')->allFiles('catalog/equipment');
    }

    // --- the service ---

    public function test_a_valid_image_is_stored_with_a_random_name_and_the_detected_extension(): void
    {
        $item = EquipmentItem::factory()->create();

        // PNG bytes under a misleading client name: the name is never used.
        $this->service()->save($item, $this->upload('my photo (1).jpg', $this->pngBytes()));

        $path = $item->fresh()->image_path;
        $this->assertMatchesRegularExpression('#^catalog/equipment/[A-Za-z0-9]{40}\.png$#', $path);
        $this->assertStringNotContainsString('photo', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringEndsWith('/storage/'.$path, $item->fresh()->imageUrl());
    }

    public function test_jpeg_and_webp_are_stored_with_their_own_extension(): void
    {
        foreach ([[$this->jpegBytes(), 'jpg'], [$this->webpBytes(), 'webp']] as [$bytes, $extension]) {
            $item = EquipmentItem::factory()->create();

            $this->service()->save($item, $this->upload('x.png', $bytes));

            $this->assertMatchesRegularExpression("#\\.$extension$#", $item->fresh()->image_path);
        }
    }

    public function test_replacing_stores_the_new_file_and_deletes_the_old_one_after_the_write(): void
    {
        $item = EquipmentItem::factory()->create();
        $this->service()->save($item, $this->upload('a.png', $this->pngBytes()));
        $old = $item->fresh()->image_path;

        $this->service()->save($item->fresh(), $this->upload('b.jpg', $this->jpegBytes()));

        $new = $item->fresh()->image_path;
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
        $this->assertSame([$new], $this->files());
    }

    public function test_when_the_write_fails_the_new_file_is_removed_and_the_old_image_stays(): void
    {
        $item = EquipmentItem::factory()->create();
        $this->service()->save($item, $this->upload('a.png', $this->pngBytes()));
        $old = $item->fresh()->image_path;

        EquipmentItem::updating(function () {
            throw new RuntimeException('database write failed');
        });

        try {
            $this->service()->save($item->fresh(), $this->upload('b.png', $this->pngBytes()));
            $this->fail('The failure must propagate.');
        } catch (RuntimeException) {
            $this->assertSame($old, $item->fresh()->image_path);
        }

        Storage::disk('public')->assertExists($old);
        $this->assertSame([$old], $this->files());
    }

    public function test_removing_deletes_the_file_and_clears_the_path(): void
    {
        $item = EquipmentItem::factory()->create();
        $this->service()->save($item, $this->upload('a.png', $this->pngBytes()));
        $path = $item->fresh()->image_path;

        $this->service()->save($item->fresh(), null, true);

        $this->assertNull($item->fresh()->image_path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame([], $this->files());
    }

    public function test_without_a_file_or_removal_nothing_changes(): void
    {
        $item = EquipmentItem::factory()->create();
        $this->service()->save($item, $this->upload('a.png', $this->pngBytes()));
        $path = $item->fresh()->image_path;

        $this->service()->save($item->fresh());

        $this->assertSame($path, $item->fresh()->image_path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_deleting_an_item_deletes_its_file_after_the_delete(): void
    {
        $item = EquipmentItem::factory()->create();
        $this->service()->save($item, $this->upload('a.png', $this->pngBytes()));
        $path = $item->fresh()->image_path;

        $this->service()->delete($item->fresh());

        $this->assertNull(EquipmentItem::find($item->id));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_failed_delete_keeps_the_file(): void
    {
        $item = EquipmentItem::factory()->create();
        $this->service()->save($item, $this->upload('a.png', $this->pngBytes()));
        $path = $item->fresh()->image_path;

        EquipmentItem::deleting(function () {
            throw new RuntimeException('delete failed');
        });

        try {
            $this->service()->delete($item->fresh());
            $this->fail('The failure must propagate.');
        } catch (RuntimeException) {
            $this->assertNotNull($item->fresh());
        }

        Storage::disk('public')->assertExists($path);
    }

    public function test_the_image_path_change_is_logged_as_a_path(): void
    {
        $item = EquipmentItem::factory()->create();
        $this->service()->save($item, $this->upload('a.png', $this->pngBytes()));

        $log = ActivityLog::where('action', 'equipment_item.updated')->where('subject_id', $item->id)->sole();
        $this->assertNull($log->changes['image_path']['old']);
        $this->assertSame($item->fresh()->image_path, $log->changes['image_path']['new']);
    }

    // --- validation ---

    /**
     * @param  array<string, mixed>  $data
     */
    private function validator(array $data): \Illuminate\Validation\Validator
    {
        $request = new EquipmentItemImageRequest;

        return Validator::make($data, $request->rules(), $request->messages());
    }

    public function test_valid_images_pass(): void
    {
        foreach ([$this->pngBytes(), $this->jpegBytes(), $this->webpBytes()] as $bytes) {
            $this->assertTrue($this->validator(['image' => $this->upload('x.png', $bytes)])->passes());
        }
        $this->assertTrue($this->validator(['image' => $this->upload('min.png', $this->pngBytes(400, 250))])->passes());
        $this->assertTrue($this->validator(['image' => $this->upload('max.png', $this->pngHeaderOnly(4000, 4000))])->passes());
        $this->assertTrue($this->validator([])->passes());
    }

    public function test_unsupported_oversized_or_misleading_files_are_rejected(): void
    {
        $rejected = [
            'svg named svg' => $this->upload('logo.svg', $this->svgBytes()),
            'svg named png' => $this->upload('logo.png', $this->svgBytes()),
            'php named jpg' => $this->upload('evil.jpg', '<?php echo 1; ?>'),
            'php extension' => $this->upload('shell.php', $this->pngBytes()),
            'text' => $this->upload('notes.png', 'just some text'),
            'over 2 MB' => $this->upload('big.png', $this->pngBytes().str_repeat("\0", 3 * 1024 * 1024)),
            'too small' => $this->upload('small.png', $this->pngBytes(100, 100)),
            'too large' => $this->upload('huge.png', $this->pngHeaderOnly(5000, 5000)),
        ];

        foreach ($rejected as $label => $file) {
            $this->assertTrue($this->validator(['image' => $file])->fails(), $label);
        }

        $validator = $this->validator(['image' => $rejected['over 2 MB']]);
        $this->assertSame(['Slika je veća od dozvoljenih 2 MB.'], $validator->errors()->get('image'));
    }

    public function test_the_swatch_must_be_a_hex_color(): void
    {
        foreach (['#C62828', '#abcdef', '#000000', null] as $good) {
            $this->assertTrue($this->validator(['swatch_hex' => $good])->passes(), (string) $good);
        }
        foreach (['#FFF', 'FFFFFF', '#GGGGGG', '#FFFFFFF', 'red', ' #FFFFFF'] as $bad) {
            $this->assertTrue($this->validator(['swatch_hex' => $bad])->fails(), $bad);
        }
    }

    public function test_only_admins_may_use_the_request(): void
    {
        $asRequest = fn (User $user) => tap(new EquipmentItemImageRequest, fn ($request) => $request->setUserResolver(fn () => $user));

        $this->assertTrue($asRequest(User::factory()->admin()->create())->authorize());
        $this->assertFalse($asRequest(User::factory()->client()->create())->authorize());
    }
}

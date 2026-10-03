<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CarModel;
use App\Models\Category;
use App\Models\Trim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Concerns\MakesImages;
use Tests\TestCase;

class CarModelImageTest extends TestCase
{
    use MakesImages, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        return Storage::disk('public')->allFiles('catalog/models');
    }

    private function store(array $data = []): TestResponse
    {
        return $this->actingAs($this->admin)->post('/admin/catalog/models', array_merge(['name' => 'Octavia'], $data));
    }

    private function update(CarModel $model, array $data = []): TestResponse
    {
        return $this->actingAs($this->admin)->post("/admin/catalog/models/{$model->id}", array_merge(['_method' => 'PATCH', 'name' => $model->name], $data));
    }

    // --- accepted files ---

    public function test_png_jpeg_and_webp_are_stored_with_a_random_name_and_the_detected_extension(): void
    {
        $cases = [
            'png' => [$this->pngBytes(), 'png'],
            'jpeg' => [$this->jpegBytes(), 'jpg'],
            'webp' => [$this->webpBytes(), 'webp'],
        ];

        foreach ($cases as $label => [$bytes, $extension]) {
            $this->store(['name' => "Model $label", 'image' => $this->upload("photo-$label.png", $bytes)])
                ->assertSessionHasNoErrors();

            $path = CarModel::where('name', "Model $label")->sole()->image_path;

            $this->assertMatchesRegularExpression("#^catalog/models/[A-Za-z0-9]{40}\\.$extension$#", $path, $label);
            Storage::disk('public')->assertExists($path);
            $this->assertStringNotContainsString("photo-$label", $path);
        }

        $this->assertCount(3, $this->files());
    }

    public function test_the_name_and_extension_the_client_sends_are_never_used(): void
    {
        // Real PNG bytes under misleading client names: always a random name with the DETECTED extension.
        foreach (['holiday.JPEG', 'photo.gif', '../../traversal.png', 'no extension', 'my photo (1).webp'] as $index => $clientName) {
            $this->store(['name' => "Model $index", 'image' => $this->upload($clientName, $this->pngBytes())])
                ->assertSessionHasNoErrors();

            $path = CarModel::where('name', "Model $index")->sole()->image_path;

            $this->assertMatchesRegularExpression('#^catalog/models/[A-Za-z0-9]{40}\.png$#', $path, $clientName);
            $this->assertStringNotContainsString('traversal', $path);
            $this->assertStringNotContainsString('photo', $path);
        }

        // Laravel also refuses any upload whose client name ends in a PHP extension.
        $this->store(['name' => 'Shell', 'image' => $this->upload('shell.php', $this->pngBytes())])
            ->assertSessionHasErrors('image');
        $this->assertSame(0, CarModel::where('name', 'Shell')->count());
    }

    public function test_a_model_can_be_created_with_an_image_and_categories_in_one_request(): void
    {
        $category = Category::factory()->create();

        $this->store([
            'image' => $this->upload('a.png', $this->pngBytes()),
            'sync_categories' => 1,
            'category_ids' => [$category->id],
        ])->assertSessionHasNoErrors();

        $model = CarModel::sole();
        $this->assertNotNull($model->image_path);
        $this->assertSame([$category->id], $model->categories()->pluck('categories.id')->all());
    }

    // --- rejected files ---

    public function test_unsupported_oversized_or_misleading_files_are_rejected(): void
    {
        $rejected = [
            'svg named svg' => $this->upload('logo.svg', $this->svgBytes()),
            'svg named png' => $this->upload('logo.png', $this->svgBytes()),
            'php named jpg' => $this->upload('evil.jpg', '<?php echo "owned"; ?>'),
            'html named webp' => $this->upload('page.webp', '<html><script>alert(1)</script></html>'),
            'gif' => $this->upload('anim.gif', "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;"),
            'text' => $this->upload('notes.png', 'just some text'),
            'over 2 MB' => $this->upload('big.png', $this->pngBytes().str_repeat("\0", 3 * 1024 * 1024)),
            'too small' => $this->upload('small.png', $this->pngBytes(100, 100)),
            'narrow' => $this->upload('narrow.png', $this->pngBytes(399, 300)),
            'too large' => $this->upload('huge.png', $this->pngHeaderOnly(5000, 5000)),
        ];

        foreach ($rejected as $label => $file) {
            $this->store(['name' => $label, 'image' => $file])->assertSessionHasErrors('image');
        }

        $this->assertSame(0, CarModel::count());
        $this->assertSame([], $this->files());
    }

    public function test_the_size_error_message_is_in_serbian(): void
    {
        $this->store(['image' => $this->upload('big.png', $this->pngBytes().str_repeat("\0", 3 * 1024 * 1024))])
            ->assertSessionHasErrors(['image' => 'Slika je veća od dozvoljenih 2 MB.']);
    }

    public function test_the_boundary_sizes_are_accepted(): void
    {
        $this->store(['name' => 'Min', 'image' => $this->upload('a.png', $this->pngBytes(400, 250))])->assertSessionHasNoErrors();
        $this->store(['name' => 'Max', 'image' => $this->upload('b.png', $this->pngHeaderOnly(4000, 4000))])->assertSessionHasNoErrors();

        $this->assertSame(2, CarModel::whereNotNull('image_path')->count());
    }

    public function test_a_body_above_post_max_size_becomes_a_message_next_to_the_image_field(): void
    {
        // ValidatePostSize compares CONTENT_LENGTH with post_max_size and throws a 413.
        $response = $this->actingAs($this->admin)->call('POST', '/admin/catalog/models', ['name' => 'X'], [], [], [
            'CONTENT_LENGTH' => 999_999_999,
            'HTTP_REFERER' => url('/admin/catalog/models'),
        ]);

        $response->assertRedirect('/admin/catalog/models')
            ->assertSessionHasErrors(['image' => 'Slika je veća od dozvoljenih 2 MB.']);
        $this->assertSame(0, CarModel::count());
    }

    public function test_a_413_on_other_pages_is_still_an_error_page(): void
    {
        $this->actingAs($this->admin)->call('POST', '/admin/catalog/categories', ['name' => 'X'], [], [], [
            'CONTENT_LENGTH' => 999_999_999,
        ])->assertStatus(413);
    }

    // --- replace / remove ---

    public function test_replacing_the_image_stores_the_new_file_and_deletes_the_old_one_after_the_save(): void
    {
        $this->store(['image' => $this->upload('a.png', $this->pngBytes())]);
        $model = CarModel::sole();
        $old = $model->image_path;

        $this->update($model, ['image' => $this->upload('b.jpg', $this->jpegBytes())])->assertSessionHasNoErrors();

        $new = $model->fresh()->image_path;
        $this->assertNotSame($old, $new);
        $this->assertMatchesRegularExpression('#\.jpg$#', $new);
        Storage::disk('public')->assertExists($new);
        Storage::disk('public')->assertMissing($old);
        $this->assertSame([$new], $this->files());
    }

    public function test_when_the_save_fails_the_new_file_is_removed_and_the_old_image_stays(): void
    {
        $this->store(['image' => $this->upload('a.png', $this->pngBytes())]);
        $model = CarModel::sole();
        $old = $model->image_path;

        CarModel::updating(function () {
            throw new RuntimeException('database write failed');
        });

        $this->update($model, ['name' => 'Renamed', 'image' => $this->upload('b.png', $this->pngBytes())])->assertStatus(500);

        $this->assertSame($old, $model->fresh()->image_path);
        $this->assertSame('Octavia', $model->fresh()->name);
        Storage::disk('public')->assertExists($old);
        $this->assertSame([$old], $this->files());
    }

    public function test_removing_the_image_deletes_the_file(): void
    {
        $this->store(['image' => $this->upload('a.png', $this->pngBytes())]);
        $model = CarModel::sole();
        $path = $model->image_path;

        $this->update($model, ['remove_image' => 1])->assertSessionHasNoErrors();

        $this->assertNull($model->fresh()->image_path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame([], $this->files());
    }

    public function test_saving_without_a_file_keeps_the_image(): void
    {
        $this->store(['image' => $this->upload('a.png', $this->pngBytes())]);
        $model = CarModel::sole();
        $path = $model->image_path;

        $this->update($model, ['name' => 'Octavia RS'])->assertSessionHasNoErrors();

        $this->assertSame($path, $model->fresh()->image_path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_an_invalid_replacement_keeps_the_old_image(): void
    {
        $this->store(['image' => $this->upload('a.png', $this->pngBytes())]);
        $model = CarModel::sole();
        $path = $model->image_path;

        $this->update($model, ['image' => $this->upload('x.png', $this->svgBytes())])->assertSessionHasErrors('image');

        $this->assertSame($path, $model->fresh()->image_path);
        $this->assertSame([$path], $this->files());
    }

    public function test_the_image_path_change_is_logged_as_a_path(): void
    {
        $this->store(['image' => $this->upload('a.png', $this->pngBytes())]);
        $model = CarModel::sole();
        $old = $model->image_path;

        $this->update($model, ['image' => $this->upload('b.png', $this->pngBytes())]);

        $log = ActivityLog::where('action', 'car_model.updated')->where('subject_id', $model->id)->sole();
        $this->assertSame($old, $log->changes['image_path']['old']);
        $this->assertSame($model->fresh()->image_path, $log->changes['image_path']['new']);
        $this->assertArrayNotHasKey('redacted', $log->changes['image_path']);
    }

    // --- deleting a model ---

    public function test_deleting_a_model_removes_its_image_file(): void
    {
        $this->store(['image' => $this->upload('a.png', $this->pngBytes())]);
        $model = CarModel::sole();
        $path = $model->image_path;

        $this->actingAs($this->admin)->delete("/admin/catalog/models/{$model->id}")->assertSessionHas('success', 'Obrisano.');

        $this->assertNull(CarModel::find($model->id));
        Storage::disk('public')->assertMissing($path);
        $this->assertSame([], $this->files());
    }

    public function test_a_blocked_delete_leaves_the_image(): void
    {
        $this->store(['image' => $this->upload('a.png', $this->pngBytes())]);
        $model = CarModel::sole();
        Trim::factory()->for($model)->create();

        $this->actingAs($this->admin)->delete("/admin/catalog/models/{$model->id}")->assertSessionHas('error');

        Storage::disk('public')->assertExists($model->fresh()->image_path);
    }

    // --- display ---

    public function test_the_list_has_the_image_url_or_null(): void
    {
        $this->store(['name' => 'With image', 'image' => $this->upload('a.png', $this->pngBytes())]);
        $this->store(['name' => 'Without image']);
        $path = CarModel::where('name', 'With image')->sole()->image_path;

        $this->actingAs($this->admin)->get('/admin/catalog/models')->assertInertia(fn (Assert $page) => $page
            ->where('items.data.0.name', 'With image')
            ->where('items.data.0.image_url', fn ($url) => str_ends_with($url, '/storage/'.$path))
            ->where('items.data.1.image_url', null));
    }

    public function test_the_fallback_silhouette_is_an_original_svg_without_a_logo(): void
    {
        $file = public_path('images/catalog/car-placeholder.svg');

        $this->assertFileExists($file);
        $svg = file_get_contents($file);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString('<image', $svg);
        $this->assertStringNotContainsString('base64', $svg);
        $this->assertStringNotContainsStringIgnoringCase('skoda', $svg);
        $this->assertStringNotContainsStringIgnoringCase('škoda', $svg);
    }

    public function test_the_repository_ignores_uploaded_files(): void
    {
        // Uploads live in storage/app/public (git-ignored), never in the repository.
        $gitignore = file_get_contents(base_path('.gitignore'));
        $this->assertTrue(
            str_contains($gitignore, '/storage') || file_exists(storage_path('app/public/.gitignore')),
            'storage/app/public must not be tracked',
        );
    }
}

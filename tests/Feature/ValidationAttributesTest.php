<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Every field a Form Request validates must have an EXPLICIT Serbian name, so a validation message
 * never shows a raw field name ("full_name"). The name comes from attributes() of the Request or
 * from 'attributes' in lang/sr_Latn/validation.php (the value exists and is not the key itself).
 * Dynamic fields (targets.*, expected.availability) are checked by their first segment.
 *
 * A new field fails this test until it gets a name: add it to validation.php 'attributes' (shared
 * fields) or to attributes() of the Request.
 */
class ValidationAttributesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fields whose messages the Request translates as a whole through messages(), or that are
     * set by the application and never typed by a person, so a field name never reaches the user.
     * Key: request class (short name) => fields.
     *
     * @var array<string, list<string>>
     */
    private const EXCEPTIONS = [
        // Matrix requests: the client sends the cell state it saw; every failure maps to a message in messages().
        'MatrixCellRequest' => ['expected', 'previous', 'expected_standard_item_id', 'availability', 'mode'],
        'MatrixGroupRequest' => ['expected', 'confirm'],
    ];

    /** @return list<class-string<FormRequest>> */
    private function requestClasses(): array
    {
        $classes = [];

        foreach ((new Finder)->files()->in(app_path('Http/Requests'))->name('*.php') as $file) {
            $relative = str_replace(['/', '\\', '.php'], ['\\', '\\', ''], $file->getRelativePathname());
            $class = 'App\\Http\\Requests\\'.$relative;

            if (class_exists($class) && is_subclass_of($class, FormRequest::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** @return array<string, mixed> */
    private function rulesOf(string $class, bool $admin): array
    {
        /** @var FormRequest $request */
        $request = $class::createFrom(Request::create('/x', 'POST', ['type' => 'company']));
        $user = $admin ? User::factory()->admin()->create() : User::factory()->client()->create();
        $request->setUserResolver(fn () => $user);
        $request->setRouteResolver(fn () => new class
        {
            public function parameter(string $name, mixed $default = null): mixed
            {
                return $default;
            }

            public function __call(string $name, array $arguments): mixed
            {
                return null;
            }
        });

        return $request->rules();
    }

    public function test_every_validated_field_has_an_explicit_serbian_name(): void
    {
        $global = trans('validation.attributes');
        $missing = [];

        foreach ($this->requestClasses() as $class) {
            $short = (new ReflectionClass($class))->getShortName();
            $own = method_exists($class, 'attributes') ? $class::createFrom(Request::create('/x'))->attributes() : [];
            $fields = [];

            foreach ([true, false] as $admin) {
                foreach (array_keys($this->rulesOf($class, $admin)) as $key) {
                    $fields[$key] = true;
                }
            }

            foreach (array_keys($fields) as $key) {
                $first = explode('.', $key)[0];

                if (in_array($first, self::EXCEPTIONS[$short] ?? [], true)) {
                    continue;
                }

                $named = false;
                foreach (array_unique([$key, $first]) as $candidate) {
                    $name = $own[$candidate] ?? $global[$candidate] ?? null;
                    $named = $named || (is_string($name) && $name !== '' && $name !== $candidate);
                }

                if (! $named) {
                    $missing[] = "$short: $key";
                }
            }
        }

        $this->assertSame([], $missing, "Fields without a Serbian name:\n".implode("\n", $missing));
    }
}

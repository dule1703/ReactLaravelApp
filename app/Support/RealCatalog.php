<?php

namespace App\Support;

use App\Enums\DriveType;
use App\Enums\EquipmentCategory;
use App\Enums\FuelType;
use App\Enums\OptionSelection;
use App\Enums\TransmissionType;
use DateTimeImmutable;
use RuntimeException;

/**
 * Loading and validation of the hand-prepared real catalog file (database/seeders/data/real_catalog.php;
 * the format is documented in that file). Validation is pure and reports EVERY problem with a
 * path to it, so nothing is written to the database before the file is fully correct.
 * Messages are operator output for the console, in Serbian.
 */
class RealCatalog
{
    /** The lists of the file. An empty frame has all of them empty. */
    private const LISTS = ['categories', 'groups', 'models', 'engines', 'transmissions', 'versions', 'equipment'];

    /**
     * @return array<string, mixed>
     */
    public static function load(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Fajl sa realnim podacima ne postoji: $path");
        }

        $data = require $path;

        if (! is_array($data)) {
            throw new RuntimeException("Fajl $path mora da vrati niz.");
        }

        return $data;
    }

    /** Directory for the private real catalog file: ignored by git, inside the repository folder. */
    public const PRIVATE_DIRECTORY = 'database/seeders/data/private';

    /**
     * Where a catalog file lives relative to the (public) repository:
     *  - 'private': inside database/seeders/data/private/ (ignored by git),
     *  - 'repository': inside the repository folder anywhere else (could be committed),
     *  - 'outside': outside the repository (temp files, the server's shared/ folder).
     *
     * Paths are compared after realpath(), so symbolic links are resolved.
     */
    public static function location(string $path): string
    {
        $file = realpath($path);

        if ($file === false) {
            return 'outside';
        }

        $private = realpath(base_path(self::PRIVATE_DIRECTORY));
        $base = realpath(base_path());

        if ($private !== false && self::isInside($file, $private)) {
            return 'private';
        }

        return $base !== false && self::isInside($file, $base) ? 'repository' : 'outside';
    }

    /**
     * Whether $path is inside $directory (a sibling such as "private-evil" is NOT inside "private").
     * Case-insensitive on Windows.
     */
    public static function isInside(string $path, string $directory): bool
    {
        $normalize = function (string $value): string {
            $value = str_replace('\\', '/', $value);

            return rtrim(PHP_OS_FAMILY === 'Windows' ? strtolower($value) : $value, '/').'/';
        };

        return str_starts_with($normalize($path), $normalize($directory));
    }

    /**
     * What to do with a file that sits inside the repository outside the private directory.
     */
    public static function locationAdvice(): string
    {
        return 'Repozitorijum je JAVAN i realni podaci ne smeju u git. Premestite fajl u '.self::PRIVATE_DIRECTORY.'/real_catalog.php '
            .'(ignoriše se u gitu) ili van repozitorijuma (na serveru deploy/<okruženje>/shared/real_catalog.php) '
            .'i podesite CATALOG_REAL_PATH u .env.';
    }

    /**
     * An empty frame: nothing to load. It is valid and the seeder does nothing.
     *
     * @param  array<string, mixed>  $catalog
     */
    public static function isEmpty(array $catalog): bool
    {
        foreach (self::LISTS as $list) {
            if (! empty($catalog[$list])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array<string, int> number of entries per list
     */
    public static function counts(array $catalog): array
    {
        $counts = [];

        foreach (self::LISTS as $list) {
            $counts[$list] = is_array($catalog[$list] ?? null) ? count($catalog[$list]) : 0;
        }

        $counts['equipment_entries'] = 0;
        foreach ($catalog['equipment'] ?? [] as $item) {
            foreach ($item['models'] ?? [] as $trims) {
                $counts['equipment_entries'] += is_array($trims) ? count($trims) : 0;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return list<string> error messages ("path: message"); empty when the file is valid
     */
    public static function validate(array $catalog): array
    {
        if (self::isEmpty($catalog)) {
            return [];
        }

        $errors = [];
        $add = function (string $path, string $message) use (&$errors) {
            $errors[] = "$path: $message";
        };

        foreach (self::LISTS as $list) {
            if (! isset($catalog[$list]) || ! is_array($catalog[$list])) {
                $add($list, 'nedostaje ili nije lista');
                $catalog[$list] = [];
            }
        }

        self::meta($catalog['meta'] ?? null, $add);
        $categories = self::categories($catalog['categories'], $add);
        $groups = self::groups($catalog['groups'], $add);
        $trims = self::models($catalog['models'], $categories, $add);
        $engines = self::engines($catalog['engines'], $add);
        $transmissions = self::transmissions($catalog['transmissions'], $add);
        self::versions($catalog['versions'], $trims, $engines, $transmissions, $add);
        self::equipment($catalog['equipment'], $trims, $groups, $add);

        return $errors;
    }

    private static function lower(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private static function text(mixed $value, int $max): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= $max && ! preg_match('/\p{C}/u', $value);
    }

    private static function price(mixed $value, int $min): bool
    {
        return is_int($value) && $value >= $min && $value <= Money::MAX_PRICE_CENTS;
    }

    /**
     * @param  callable(string, string): void  $add
     */
    private static function meta(mixed $meta, callable $add): void
    {
        if (! is_array($meta)) {
            $add('meta', 'nedostaje');

            return;
        }

        if (! self::text($meta['source'] ?? null, 255)) {
            $add('meta.source', 'mora biti neprazan tekst (odakle su podaci pročitani)');
        }

        $date = is_string($meta['read_on'] ?? null) ? DateTimeImmutable::createFromFormat('!Y-m-d', $meta['read_on']) : false;
        if ($date === false || $date->format('Y-m-d') !== $meta['read_on']) {
            $add('meta.read_on', 'mora biti datum u obliku YYYY-MM-DD');
        }

        $rate = $meta['vat_rate_bp'] ?? null;
        if (! is_int($rate) || $rate < Vat::RATE_MIN_BP || $rate > Vat::RATE_MAX_BP) {
            $add('meta.vat_rate_bp', 'mora biti ceo broj od 0 do 10000 (2000 = 20%)');
        }
    }

    /**
     * @param  array<int, mixed>  $list
     * @param  callable(string, string): void  $add
     * @return array<string, string> lowercase name => name
     */
    private static function categories(array $list, callable $add): array
    {
        $known = [];

        foreach ($list as $i => $name) {
            if (! self::text($name, 100)) {
                $add("categories[$i]", 'naziv mora biti neprazan tekst do 100 znakova');
            } elseif (isset($known[self::lower($name)])) {
                $add("categories[$i]", "kategorija '$name' je duplirana");
            } else {
                $known[self::lower($name)] = $name;
            }
        }

        return $known;
    }

    /**
     * @param  array<int, mixed>  $list
     * @param  callable(string, string): void  $add
     * @return array<string, array{name: string, selection: string, category: string, swatch: bool}> lowercase name => group
     */
    private static function groups(array $list, callable $add): array
    {
        $known = [];

        foreach ($list as $i => $group) {
            if (! is_array($group)) {
                $add("groups[$i]", 'mora biti niz');

                continue;
            }

            $name = $group['name'] ?? null;
            if (! self::text($name, 100)) {
                $add("groups[$i].name", 'naziv mora biti neprazan tekst do 100 znakova');
                $name = null;
            } elseif (isset($known[self::lower($name)])) {
                $add("groups[$i].name", "grupa '$name' je duplirana (nazivi su jedinstveni bez razlike u velikim/malim slovima)");
                $name = null;
            }

            $selection = OptionSelection::tryFrom((string) ($group['selection'] ?? ''));
            if ($selection === null) {
                $add("groups[$i].selection", "nepoznat izbor '".self::show($group['selection'] ?? null)."' (dozvoljeno: ".implode('|', array_column(OptionSelection::cases(), 'value')).')');
            }

            $category = EquipmentCategory::tryFrom((string) ($group['category'] ?? ''));
            if ($category === null) {
                $add("groups[$i].category", "nepoznata kategorija opreme '".self::show($group['category'] ?? null)."' (dozvoljeno: ".implode('|', array_column(EquipmentCategory::cases(), 'value')).')');
            }

            if (array_key_exists('swatch', $group) && ! is_bool($group['swatch'])) {
                $add("groups[$i].swatch", 'mora biti true ili false (grupa sa uzorcima boja)');
            }

            if ($name !== null) {
                // Known even when half-broken, so its equipment is not reported a second time.
                $known[self::lower($name)] = [
                    'name' => $name,
                    'selection' => $selection?->value ?? 'multiple',
                    'category' => $category?->value ?? '',
                    'swatch' => ($group['swatch'] ?? false) === true,
                ];
            }
        }

        return $known;
    }

    /**
     * @param  array<int, mixed>  $list
     * @param  array<string, string>  $categories
     * @param  callable(string, string): void  $add
     * @return array<string, array<string, true>> model slug => lowercase trim name => true
     */
    private static function models(array $list, array $categories, callable $add): array
    {
        $trims = [];
        $names = [];

        foreach ($list as $i => $model) {
            if (! is_array($model)) {
                $add("models[$i]", 'mora biti niz');

                continue;
            }

            $slug = $model['slug'] ?? null;
            if (! is_string($slug) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 100) {
                $add("models[$i].slug", 'mora biti oznaka od malih slova, cifara i crtica (npr. octavia)');
                $slug = null;
            } elseif (isset($trims[$slug])) {
                $add("models[$i].slug", "oznaka '$slug' je duplirana");
            }

            if (! self::text($model['name'] ?? null, 100)) {
                $add("models[$i].name", 'naziv mora biti neprazan tekst do 100 znakova');
            } elseif (isset($names[self::lower($model['name'])])) {
                $add("models[$i].name", "naziv '{$model['name']}' je dupliran");
            } else {
                $names[self::lower($model['name'])] = true;
            }

            if (! is_array($model['categories'] ?? null)) {
                $add("models[$i].categories", 'mora biti lista naziva kategorija');
            } else {
                foreach ($model['categories'] as $c => $category) {
                    if (! is_string($category) || ! isset($categories[self::lower($category)])) {
                        $add("models[$i].categories[$c]", 'nepoznata kategorija '.(is_string($category) ? "'$category'" : '(nije tekst)').' (mora biti navedena u listi categories)');
                    }
                }
            }

            $known = [];
            if (! is_array($model['trims'] ?? null) || $model['trims'] === []) {
                $add("models[$i].trims", 'mora biti neprazna lista paketa');
            } else {
                foreach ($model['trims'] as $t => $trim) {
                    if (! self::text($trim, 100)) {
                        $add("models[$i].trims[$t]", 'naziv paketa mora biti neprazan tekst do 100 znakova');
                    } elseif (isset($known[self::lower($trim)])) {
                        $add("models[$i].trims[$t]", "paket '$trim' je dupliran u modelu");
                    } else {
                        $known[self::lower($trim)] = true;
                    }
                }
            }

            if ($slug !== null && ! isset($trims[$slug])) {
                $trims[$slug] = $known;
            }
        }

        return $trims;
    }

    /**
     * @param  array<int, mixed>  $list
     * @param  callable(string, string): void  $add
     * @return array<string, true> engine keys
     */
    private static function engines(array $list, callable $add): array
    {
        $keys = [];
        $seen = [];

        foreach ($list as $i => $engine) {
            if (! is_array($engine)) {
                $add("engines[$i]", 'mora biti niz');

                continue;
            }

            $key = self::key($engine['key'] ?? null);
            if ($key === null) {
                $add("engines[$i].key", 'mora biti ključ od malih slova, cifara i donjih crta (npr. tsi10_70)');
            } elseif (isset($keys[$key])) {
                $add("engines[$i].key", "ključ '$key' je dupliran");
            } else {
                $keys[$key] = true;
            }

            $fuel = FuelType::tryFrom((string) ($engine['fuel_type'] ?? ''));
            if ($fuel === null) {
                $add("engines[$i].fuel_type", "nepoznato gorivo '".self::show($engine['fuel_type'] ?? null)."' (dozvoljeno: ".implode('|', array_column(FuelType::cases(), 'value')).')');
            }

            $power = $engine['power_kw'] ?? null;
            if (! is_int($power) || $power < 20 || $power > 1000) {
                $add("engines[$i].power_kw", 'mora biti ceo broj od 20 do 1000');
            }

            if (! self::text($engine['name'] ?? null, 100)) {
                $add("engines[$i].name", 'naziv mora biti neprazan tekst do 100 znakova');
            } elseif ($fuel !== null && is_int($power)) {
                $signature = self::lower($engine['name']).'|'.$fuel->value.'|'.$power;
                if (isset($seen[$signature])) {
                    $add("engines[$i]", "motor '{$engine['name']}' sa istim gorivom i snagom je već naveden");
                }
                $seen[$signature] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  array<int, mixed>  $list
     * @param  callable(string, string): void  $add
     * @return array<string, true> transmission keys
     */
    private static function transmissions(array $list, callable $add): array
    {
        $keys = [];
        $seen = [];

        foreach ($list as $i => $transmission) {
            if (! is_array($transmission)) {
                $add("transmissions[$i]", 'mora biti niz');

                continue;
            }

            $key = self::key($transmission['key'] ?? null);
            if ($key === null) {
                $add("transmissions[$i].key", 'mora biti ključ od malih slova, cifara i donjih crta (npr. dsg7)');
            } elseif (isset($keys[$key])) {
                $add("transmissions[$i].key", "ključ '$key' je dupliran");
            } else {
                $keys[$key] = true;
            }

            $type = TransmissionType::tryFrom((string) ($transmission['type'] ?? ''));
            if ($type === null) {
                $add("transmissions[$i].type", "nepoznat tip menjača '".self::show($transmission['type'] ?? null)."' (dozvoljeno: ".implode('|', array_column(TransmissionType::cases(), 'value')).')');
            }

            $drive = DriveType::tryFrom((string) ($transmission['drive'] ?? ''));
            if ($drive === null) {
                $add("transmissions[$i].drive", "nepoznat pogon '".self::show($transmission['drive'] ?? null)."' (dozvoljeno: ".implode('|', array_column(DriveType::cases(), 'value')).')');
            }

            if (! self::text($transmission['name'] ?? null, 100)) {
                $add("transmissions[$i].name", 'naziv mora biti neprazan tekst do 100 znakova');
            } elseif ($type !== null && $drive !== null) {
                $signature = self::lower($transmission['name']).'|'.$type->value.'|'.$drive->value;
                if (isset($seen[$signature])) {
                    $add("transmissions[$i]", "menjač '{$transmission['name']}' sa istim tipom i pogonom je već naveden");
                }
                $seen[$signature] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  array<int, mixed>  $list
     * @param  array<string, array<string, true>>  $trims
     * @param  array<string, true>  $engines
     * @param  array<string, true>  $transmissions
     * @param  callable(string, string): void  $add
     */
    private static function versions(array $list, array $trims, array $engines, array $transmissions, callable $add): void
    {
        $seen = [];

        foreach ($list as $i => $version) {
            if (! is_array($version)) {
                $add("versions[$i]", 'mora biti niz');

                continue;
            }

            $model = $version['model'] ?? null;
            $trim = $version['trim'] ?? null;
            $engine = $version['engine'] ?? null;
            $transmission = $version['transmission'] ?? null;
            $valid = true;

            if (! is_string($model) || ! isset($trims[$model])) {
                $add("versions[$i]", "nepoznat model '".self::show($model)."'");
                $valid = false;
            } elseif (! is_string($trim) || ! isset($trims[$model][self::lower($trim)])) {
                $add("versions[$i]", "nepoznat paket '".self::show($trim)."' u modelu '$model'");
                $valid = false;
            }
            if (! is_string($engine) || ! isset($engines[$engine])) {
                $add("versions[$i]", "nepoznat motor '".self::show($engine)."'");
                $valid = false;
            }
            if (! is_string($transmission) || ! isset($transmissions[$transmission])) {
                $add("versions[$i]", "nepoznat menjač '".self::show($transmission)."'");
                $valid = false;
            }

            if (! self::price($version['gross_cents'] ?? null, 1)) {
                $add("versions[$i].gross_cents", 'cena mora biti ceo broj centi veći od 0 (bruto, kako piše u konfiguratoru)');
            }

            if ($valid) {
                $signature = implode('|', [$model, self::lower($trim), $engine, $transmission]);
                if (isset($seen[$signature])) {
                    $add("versions[$i]", "verzija $model / $trim / $engine / $transmission je dupla (već je navedena u versions[{$seen[$signature]}])");
                } else {
                    $seen[$signature] = $i;
                }
            }
        }
    }

    /**
     * @param  array<int, mixed>  $list
     * @param  array<string, array<string, true>>  $trims
     * @param  array<string, array{name: string, selection: string, category: string, swatch: bool}>  $groups
     * @param  callable(string, string): void  $add
     */
    private static function equipment(array $list, array $trims, array $groups, callable $add): void
    {
        $names = [];
        // group (lowercase name) => 'model / trim' => entries of that group on that trim
        $lines = [];

        foreach ($list as $i => $item) {
            if (! is_array($item)) {
                $add("equipment[$i]", 'mora biti niz');

                continue;
            }

            if (! self::text($item['name'] ?? null, 150)) {
                $add("equipment[$i].name", 'naziv mora biti neprazan tekst do 150 znakova');
            } elseif (isset($names[self::lower($item['name'])])) {
                $add("equipment[$i]", "naziv '{$item['name']}' već postoji u equipment[{$names[self::lower($item['name'])]}] (nazivi su jedinstveni bez razlike u velikim/malim slovima)");
            } else {
                $names[self::lower($item['name'])] = $i;
            }

            if (EquipmentCategory::tryFrom((string) ($item['category'] ?? '')) === null) {
                $add("equipment[$i].category", "nepoznata kategorija opreme '".self::show($item['category'] ?? null)."' (dozvoljeno: ".implode('|', array_column(EquipmentCategory::cases(), 'value')).')');
            }

            $groupKey = null;
            if (($item['group'] ?? null) !== null) {
                if (! is_string($item['group']) || ! isset($groups[self::lower($item['group'])])) {
                    $add("equipment[$i].group", "nepoznata grupa '".self::show($item['group'])."' (mora biti navedena u listi groups)");
                } else {
                    $groupKey = self::lower($item['group']);
                    $category = $item['category'] ?? null;

                    if (is_string($category) && EquipmentCategory::tryFrom($category) !== null && $category !== $groups[$groupKey]['category']) {
                        $add("equipment[$i].category", "kategorija '$category' se ne slaže sa kategorijom grupe '{$groups[$groupKey]['name']}' ({$groups[$groupKey]['category']})");
                    }
                }
            }

            if (($item['swatch_hex'] ?? null) !== null) {
                if (! is_string($item['swatch_hex']) || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $item['swatch_hex'])) {
                    $add("equipment[$i].swatch_hex", 'mora biti boja u obliku #RRGGBB');
                } elseif ($groupKey === null || ! $groups[$groupKey]['swatch']) {
                    $add("equipment[$i].swatch_hex", "dozvoljeno samo za stavke grupe sa uzorcima boja (u groups: 'swatch' => true)");
                }
            }

            if (! is_array($item['models'] ?? null)) {
                $add("equipment[$i].models", 'mora biti niz model => [paket => \'S\' | [\'O\', cena]]');

                continue;
            }

            foreach ($item['models'] as $model => $byTrim) {
                if (! isset($trims[$model])) {
                    $add("equipment[$i].models.$model", 'nepoznat model');

                    continue;
                }
                if (! is_array($byTrim)) {
                    $add("equipment[$i].models.$model", "mora biti niz paket => 'S' | ['O', cena]");

                    continue;
                }

                foreach ($byTrim as $trim => $entry) {
                    $path = "equipment[$i].models.$model.$trim";

                    if (! isset($trims[$model][self::lower((string) $trim)])) {
                        $add($path, "nepoznat paket '$trim' u modelu '$model'");
                    } elseif ($entry === 'S') {
                        if ($groupKey !== null && is_string($item['name'] ?? null)) {
                            $lines[$groupKey]["$model / $trim"][] = ['item' => $item['name'], 'availability' => 'standard', 'price' => null];
                        }
                    } elseif (is_array($entry) && ($entry[0] ?? null) === 'S') {
                        $add($path, 'standardna oprema ne sme imati cenu (koristite samo \'S\')');
                    } elseif (is_array($entry) && ($entry[0] ?? null) === 'O' && count($entry) === 2) {
                        if (! self::price($entry[1] ?? null, 0)) {
                            $add($path, 'cena dodatne opreme mora biti ceo broj centi 0 ili veći (bruto)');
                        } elseif ($groupKey !== null && is_string($item['name'] ?? null)) {
                            $lines[$groupKey]["$model / $trim"][] = ['item' => $item['name'], 'availability' => 'optional', 'price' => $entry[1]];
                        }
                    } else {
                        $add($path, "vrednost mora biti 'S' ili ['O', cena u centima]");
                    }
                }
            }
        }

        self::groupLines($lines, $groups, $add);
    }

    /**
     * A single-choice group on a trim that offers it needs EXACTLY ONE standard item.
     *
     * @param  array<string, array<string, list<array{item: string, availability: string, price: int|null}>>>  $lines
     * @param  array<string, array{name: string, selection: string, category: string, swatch: bool}>  $groups
     * @param  callable(string, string): void  $add
     */
    private static function groupLines(array $lines, array $groups, callable $add): void
    {
        foreach ($lines as $groupKey => $byLine) {
            if (($groups[$groupKey]['selection'] ?? null) !== 'single') {
                continue;
            }

            foreach ($byLine as $line => $entries) {
                foreach (OptionGroupRule::problems($entries) as $problem) {
                    $items = implode(', ', $problem['items']);
                    $path = "groups[{$groups[$groupKey]['name']}] $line";

                    if ($problem['code'] === OptionGroupRule::NO_STANDARD) {
                        $add($path, "nijedna standardna stavka, a mora biti tačno jedna (stavke: $items)");
                    } elseif ($problem['code'] === OptionGroupRule::MANY_STANDARD) {
                        $add($path, "više standardnih stavki ($items), a mora biti tačno jedna");
                    }
                }
            }
        }
    }

    private static function key(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z0-9_]+$/', $value) && strlen($value) <= 50 ? $value : null;
    }

    private static function show(mixed $value): string
    {
        return is_scalar($value) || $value === null ? (string) $value : gettype($value);
    }
}

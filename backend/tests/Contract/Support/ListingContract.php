<?php

namespace Tests\Contract\Support;

use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\UsesListing;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as RouteFacade;
use ReflectionMethod;
use Throwable;

/**
 * Pemeriksa standar listing (BR-001, 07 §3, 10 §3). Mengembalikan daftar pelanggaran agar bisa
 * dipakai untuk route sungguhan maupun route uji di meta-test.
 */
final class ListingContract
{
    public const ROUTE_PATTERN = '/^(admin|api\.v1)\..+\.index$/';

    /**
     * @return Collection<int, Route>
     */
    public static function routes(): Collection
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => is_string($r->getName()) && preg_match(self::ROUTE_PATTERN, $r->getName()) === 1)
            ->values();
    }

    /**
     * Kelas listing dari atribut #[UsesListing] di method controller, atau null.
     *
     * @return class-string<HasListing>|null
     */
    public static function listingClass(Route $route): ?string
    {
        $controller = $route->getControllerClass();
        $method = $route->getActionMethod();

        if ($controller === null || ! method_exists($controller, $method)) {
            return null;
        }

        $attributes = (new ReflectionMethod($controller, $method))->getAttributes(UsesListing::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->listing;
    }

    public static function spec(Route $route): ?ListingSpec
    {
        $class = self::listingClass($route);
        if ($class === null) {
            return null;
        }

        // Sama dengan ListingQuery: route `api/*` memakai opsi mobile (per_page dsb.).
        $spec = app($class)->spec();

        return str_starts_with($route->uri(), 'api/') ? $spec->forApi() : $spec;
    }

    /**
     * @param  list<string>  $catalogKeys  kunci di tests/Fixtures/listing_catalog.php
     * @return list<string>
     */
    public static function violations(Route $route, array $catalogKeys): array
    {
        $name = (string) $route->getName();
        $class = self::listingClass($route);

        if ($class === null) {
            return ["Route {$name} belum memakai standar listing (lihat 07 §3): method controller tanpa #[UsesListing]."];
        }

        if (! is_subclass_of($class, HasListing::class)) {
            return ["Route {$name}: {$class} harus mengimplementasikan HasListing."];
        }

        try {
            $spec = app($class)->spec();
            $array = $spec->toArray();
        } catch (Throwable $e) {
            return ["Route {$name}: spec {$class} gagal dibuat ({$e->getMessage()})."];
        }

        $problems = [];
        if ($spec->textSearch()->isEmpty()) {
            $problems[] = 'tidak ada kolom pencarian';
        }
        if ($array['filters'] === []) {
            $problems[] = 'tidak ada filter';
        }
        if ($array['sorts'] === []) {
            $problems[] = 'tidak ada sort';
        }
        if ($spec->sort(ltrim($array['default_sort'], '-')) === null) {
            $problems[] = "default_sort [{$array['default_sort']}] tidak ada di sorts";
        }
        if ($array['per_page_options'] === []) {
            $problems[] = 'per_page_options kosong';
        }
        if (! in_array($spec->key, $catalogKeys, true)) {
            $problems[] = "kunci [{$spec->key}] belum ada di tests/Fixtures/listing_catalog.php (dan 07 §3.4)";
        }

        return $problems === []
            ? []
            : ["Route {$name} belum memenuhi standar listing (lihat 07 §3): ".implode('; ', $problems).'.'];
    }

    /**
     * Katalog 07 §3.4: kunci listing => nama route.
     *
     * @return array<string, string>
     */
    public static function documentedCatalog(): array
    {
        $markdown = (string) file_get_contents(base_path('../docs/07-UI-SPEC.md'));
        $section = str($markdown)->after('### 3.4 Katalog Listing')->before("\n## ")->toString();

        preg_match_all('/^\|\s*`([a-z_]+)`\s*\|\s*`([a-z0-9._-]+)`\s*\|/m', $section, $matches, PREG_SET_ORDER);

        return collect($matches)->mapWithKeys(fn ($m) => [$m[1] => $m[2]])->all();
    }
}

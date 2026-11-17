<?php

namespace App\Providers;

use App\Domain\Pricing\Lookup\PriceListLookup;
use App\Domain\Pricing\Lookup\PriceListVersionLookup;
use App\Domain\Pricing\Models\PriceList;
use App\Domain\Pricing\Models\PriceListVersion;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Pricing\Policies\PriceListPolicy;
use App\Domain\Pricing\Policies\PriceListVersionPolicy;
use App\Domain\Pricing\Policies\PromotionPolicy;
use App\Support\Lookup\LookupRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** Harga Paket A (T-075 daftar harga berversi; promo T-076). Provider terpisah per modul. */
class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(LookupRegistry::class, function (LookupRegistry $registry) {
            $registry->register('price_lists', PriceListLookup::class);
            $registry->register('price_list_versions', PriceListVersionLookup::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        Gate::policy(PriceList::class, PriceListPolicy::class);
        Gate::policy(PriceListVersion::class, PriceListVersionPolicy::class);
        Gate::policy(Promotion::class, PromotionPolicy::class);
    }
}

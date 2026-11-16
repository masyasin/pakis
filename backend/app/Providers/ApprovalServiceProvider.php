<?php

namespace App\Providers;

use App\Domain\Approval\Events\ApprovalDecided;
use App\Domain\Approval\Events\ApprovalStepActivated;
use App\Domain\Approval\Listeners\SendApprovalNotifications;
use App\Domain\Approval\Lookup\ApprovalDelegateLookup;
use App\Domain\Approval\Models\ApprovalDelegation;
use App\Domain\Approval\Policies\ApprovalDelegationPolicy;
use App\Support\Lookup\LookupRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Mesin approval generik Paket A (T-067, ADR-105). Provider terpisah agar modul ini tidak menyentuh
 * registrasi modul lain.
 */
class ApprovalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(LookupRegistry::class, function (LookupRegistry $registry) {
            $registry->register('approval_delegates', ApprovalDelegateLookup::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        Event::listen(ApprovalStepActivated::class, [SendApprovalNotifications::class, 'stepActivated']);
        Event::listen(ApprovalDecided::class, [SendApprovalNotifications::class, 'decided']);

        Gate::policy(ApprovalDelegation::class, ApprovalDelegationPolicy::class);
    }
}

<?php

use App\Providers\AppServiceProvider;
use App\Providers\AttendanceServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\NotificationServiceProvider;
use App\Providers\OrganizationServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    NotificationServiceProvider::class,
    OrganizationServiceProvider::class,
    AttendanceServiceProvider::class,
];

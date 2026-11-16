<?php

use App\Providers\ApprovalServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AttendanceServiceProvider;
use App\Providers\DeviceServiceProvider;
use App\Providers\EmployeeServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\NotificationServiceProvider;
use App\Providers\OrganizationServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    NotificationServiceProvider::class,
    OrganizationServiceProvider::class,
    AttendanceServiceProvider::class,
    EmployeeServiceProvider::class,
    DeviceServiceProvider::class,
    ApprovalServiceProvider::class,
];

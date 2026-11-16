<?php

use App\Providers\ApprovalServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AttendanceServiceProvider;
use App\Providers\CatalogServiceProvider;
use App\Providers\CustomerServiceProvider;
use App\Providers\DeviceServiceProvider;
use App\Providers\EmployeeServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\InventoryServiceProvider;
use App\Providers\NotificationServiceProvider;
use App\Providers\OrganizationServiceProvider;
use App\Providers\PurchasingServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    NotificationServiceProvider::class,
    OrganizationServiceProvider::class,
    AttendanceServiceProvider::class,
    EmployeeServiceProvider::class,
    DeviceServiceProvider::class,
    ApprovalServiceProvider::class,
    CatalogServiceProvider::class,
    InventoryServiceProvider::class,
    CustomerServiceProvider::class,
    PurchasingServiceProvider::class,
];

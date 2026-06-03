<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use Laravel\Sanctum\Sanctum;
use App\Models\PersonalAccessToken;
use App\Events\ApartmentCapacityIsFull;
use App\Listeners\NotifyTenantsToUploadContract;
use Illuminate\Support\Facades\Event;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Event::listen(
            ApartmentCapacityIsFull::class,
            NotifyTenantsToUploadContract::class
        );
    }
}

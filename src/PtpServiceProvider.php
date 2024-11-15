<?php

namespace Biigle\Modules\Ptp;

use Biigle\Http\Requests\UpdateUserSettings;
use Biigle\Services\Modules;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class PtpServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     * @param  \Biigle\Services\Modules  $modules
     * @param  \Illuminate\Routing\Router  $router
     *
     * @return void
     */
    public function boot(Modules $modules, Router $router)
    {
        $this->loadViewsFrom(__DIR__.'/resources/views', 'ptp');
        // $this->loadMigrationsFrom(__DIR__.'/Database/migrations');

        $this->publishes([
            __DIR__.'/public/assets' => public_path('vendor/ptp'),
        ], 'public');

        $router->group([
            'namespace' => 'Biigle\Modules\Ptp\Http\Controllers',
            'middleware' => 'web',
        ], function ($router) {
            require __DIR__.'/Http/routes.php';
        });

        $modules->register('ptp', [
            'viewMixins' => [
                'volumesSidebar',
                'ptpContainer',
            ],
            'apidoc' => [__DIR__.'/Http/Controllers/Ptp/'],
        ]);

        if (config('ptp.notifications.allow_user_settings')) {
            $modules->registerViewMixin('ptp', 'settings.notifications');
            UpdateUserSettings::addRule('ptp_notifications', 'filled|in:email,web');
        }
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/config/ptp.php', 'ptp');
    }
}

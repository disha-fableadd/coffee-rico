<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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
        // Prevent "Please provide a valid cache path" when views dir is missing on shared hosting
        $viewsPath = storage_path('framework/views');
        if (!is_dir($viewsPath)) {
            @mkdir($viewsPath, 0755, true);
        }
    }
}

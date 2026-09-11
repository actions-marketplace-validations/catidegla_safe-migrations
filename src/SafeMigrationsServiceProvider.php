<?php

declare(strict_types=1);

namespace Catidegla\SafeMigrations;

use Illuminate\Support\ServiceProvider;
use Catidegla\SafeMigrations\Console\LintMigrationsCommand;

class SafeMigrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/safe-migrations.php', 'safe-migrations');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([LintMigrationsCommand::class]);

        $this->publishes([
            __DIR__ . '/../config/safe-migrations.php' => config_path('safe-migrations.php'),
        ], 'safe-migrations-config');
    }
}

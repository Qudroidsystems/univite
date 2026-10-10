<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One rules cache per request (or per test), cleared when rules are saved.
        $this->app->scoped(\App\Support\AcademicRules::class);
    }

    public function boot(): void
    {
        // Render pagination with the Bootstrap 5 theme (matches the Velzon UI).
        Paginator::useBootstrapFive();

        // The institution's name (Institution Profile) replaces APP_NAME everywhere once it is set.
        \App\Support\Institution::flush();
        try {
            if ($name = \App\Models\Setting::where('key', 'institution.name')->value('value')) {
                config(['app.name' => $name]);
            }
        } catch (\Throwable) {
            // no database yet (fresh install / migrations)
        }

        // Super Admin passes every permission check (including future permissions).
        Gate::before(fn ($user) => $user->hasRole('Super Admin') ? true : null);
    }
}

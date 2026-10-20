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
        $this->app->scoped(\App\Services\Api\CourseOwners::class);
        // Scoped-role lookups are cached for the request.
        $this->app->scoped(\App\Services\Access\ScopedAccess::class);
        // "Last changed by" lookups are batched per page.
        $this->app->scoped(\App\Services\Activity\RecordActivities::class);
    }

    public function boot(): void
    {
        // Mobile API: sign-in attempts are limited per account and per address; other calls per person.
        \Illuminate\Support\Facades\RateLimiter::for('mobile-login', fn (\Illuminate\Http\Request $r) => [
            \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('login|' . mb_strtolower((string) $r->input('login')) . '|' . $r->ip()),
            \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('login-ip|' . $r->ip()),
        ]);
        \Illuminate\Support\Facades\RateLimiter::for('mobile-api', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute(180)->by('api|' . ($r->user()?->id ?: $r->ip())));

        // Render pagination with the Bootstrap 5 theme (matches the Velzon UI).
        Paginator::useBootstrapFive();

        // The institution's name (Institution Profile) replaces APP_NAME everywhere once it is set.
        \App\Support\Institution::flush();
        try {
            if ($name = \App\Models\Setting::where('key', 'institution.name')->value('value')) {
                config(['app.name' => $name]);
            }
            // Mail server and sender set under Emails → Settings.
            if (! $this->app->runningUnitTests()) {
                \App\Support\MailSettings::apply();
            }
        } catch (\Throwable) {
            // no database yet (fresh install / migrations)
        }

        // The sidebar and the search box are built from one menu (App\Support\Navigation\Menu).
        \Illuminate\Support\Facades\View::composer('layouts.master', \App\View\Composers\ShellComposer::class);

        // Who created, changed or deleted each record (the "Last changed by" column on tables).
        \Illuminate\Support\Facades\Event::listen(['eloquent.created: *', 'eloquent.updated: *', 'eloquent.deleted: *'], \App\Listeners\RecordModelActivity::class);

        // Super Admin passes every permission check (including future permissions).
        Gate::before(fn ($user) => $user->hasRole('Super Admin') ? true : null);
    }
}

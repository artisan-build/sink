<?php

namespace App\Providers;

use App\Models\User;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\OffboardedSubject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
    }

    private function configureAuthorization(): void
    {
        Gate::define('administer-sink', function (): bool {
            $acting = $this->app->make(ActingPrincipalResolver::class)->resolve();

            if ($acting->wasRefused()) {
                return false;
            }

            if ($acting->delegated) {
                return $acting->role === ConsoleRole::Admin;
            }

            if ($acting->delegatedSessionPresent()) {
                return false;
            }

            $user = $acting->principal;

            if (! $user instanceof User || ! $user->is_admin) {
                return false;
            }

            if (OffboardedSubject::userIsOffboarded((string) $user->getAuthIdentifier())) {
                if (request()->hasSession()) {
                    request()->session()->invalidate();
                }

                return false;
            }

            return true;
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

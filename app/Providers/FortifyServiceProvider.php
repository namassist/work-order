<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Concerns\LogsAuthActivity;
use App\Http\Middleware\EnsureRegistrationIsEnabled;
use App\Models\User;
use App\Support\RegistrationOptions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    use LogsAuthActivity;

    /**
     * Login attempts one IP may make per minute across all emails.
     */
    private const int LOGIN_ATTEMPTS_PER_IP_PER_MINUTE = 30;

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
        $this->configureActions();
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureRegistrationRoutes();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    /**
     * Only active users may log in. Soft-deleted users are never found.
     *
     * A refused inactive user fires no Failed event, so it is logged here.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::where('email', $request->string(Fortify::username()))->first();

            if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
                return null;
            }

            if (! $user->is_active) {
                $this->logFailedLogin($user->email, reason: 'inactive');

                throw ValidationException::withMessages([
                    Fortify::username() => __('Akun Anda telah dinonaktifkan.'),
                ]);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'canRegister' => (bool) config('registration.enabled'),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            ...RegistrationOptions::forRegisterPage(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Fortify's registration routes get the kill switch and, on the form,
     * the named `registration` limiter (AppServiceProvider). Fortify has no
     * limiter setting for registration, so the middleware is added to its
     * routes once every provider has registered them; a route cache keeps it.
     */
    private function configureRegistrationRoutes(): void
    {
        $this->app->booted(function (): void {
            $routes = Route::getRoutes();
            $routes->refreshNameLookups();

            $routes->getByName('register')?->middleware(EnsureRegistrationIsEnabled::class);
            $routes->getByName('register.store')?->middleware([EnsureRegistrationIsEnabled::class, 'throttle:registration']);
        });
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {

        RateLimiter::for('login', function (Request $request): array {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return [
                Limit::perMinute(5)->by($throttleKey),
                // Every failed attempt writes an audit row, so also cap one IP
                // cycling through many emails. Offices share an IP, hence the headroom.
                Limit::perMinute(self::LOGIN_ATTEMPTS_PER_IP_PER_MINUTE)->by($request->ip()),
            ];
        });

    }
}

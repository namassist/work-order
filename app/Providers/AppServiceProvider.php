<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\Department;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Models\WorkOrderComment;
use App\Models\WorkOrderDailyReport;
use App\Policies\ActivityPolicy;
use App\Policies\RolePolicy;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Registrations one IP may submit per minute and per hour.
     */
    private const int REGISTRATIONS_PER_IP_PER_MINUTE = 5;

    private const int REGISTRATIONS_PER_IP_PER_HOUR = 20;

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
        $this->configureDefaults();
        $this->configureMorphMap();
        $this->configureRateLimiting();

        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Activity::class, ActivityPolicy::class);
    }

    /**
     * Short, stable type names for every model stored in a polymorphic column
     * (permission pivots, activity log). Unmapped models throw when used
     * polymorphically, so new ones must be registered here.
     */
    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'company' => Company::class,
            'department' => Department::class,
            'wo-category' => WorkOrderCategory::class,
            'work-order' => WorkOrder::class,
            'wo-comment' => WorkOrderComment::class,
            'wo-daily-report' => WorkOrderDailyReport::class,
            'role' => Role::class,
            'permission' => Permission::class,
            'media' => Media::class,
        ]);
    }

    /**
     * One named limiter per throttled route (group), each counting per user
     * in its own bucket. A bare throttle:N,1 keys only on the user, so every
     * route using one would share a single counter. Fortify's `login`
     * limiter lives in FortifyServiceProvider.
     */
    protected function configureRateLimiting(): void
    {
        $perUserPerMinute = fn (int $attempts): Closure => fn (Request $request): Limit => Limit::perMinute($attempts)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('password-update', $perUserPerMinute(6));
        RateLimiter::for('wo-export', $perUserPerMinute(10));
        RateLimiter::for('wo-comment-post', $perUserPerMinute(10));
        RateLimiter::for('wo-comment-change', $perUserPerMinute(10));
        RateLimiter::for('wo-comment-upload', $perUserPerMinute(20));
        RateLimiter::for('attachment-upload', $perUserPerMinute(30));
        RateLimiter::for('wo-invoice', $perUserPerMinute(10));
        RateLimiter::for('wo-daily-report', $perUserPerMinute(10));

        // Public, so per IP. Offices share an IP, hence the hourly headroom.
        RateLimiter::for('registration', fn (Request $request): array => [
            Limit::perMinute(self::REGISTRATIONS_PER_IP_PER_MINUTE)->by('minute|'.$request->ip()),
            Limit::perHour(self::REGISTRATIONS_PER_IP_PER_HOUR)->by('hour|'.$request->ip()),
        ]);
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

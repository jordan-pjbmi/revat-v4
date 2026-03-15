<?php

namespace App\Providers;

use App\Auth\DeactivationCheckUserProvider;
use App\Events\OrganizationSwitched;
use App\Http\Middleware\EnsureOrganization;
use App\Http\Middleware\EnsureWorkspace;
use App\Models\Admin;
use App\Models\CampaignEmail;
use App\Models\CampaignEmailClick;
use App\Models\ConversionSale;
use App\Models\Effort;
use App\Models\Initiative;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Program;
use App\Models\User;
use App\Models\Workspace;
use App\Observers\WorkspaceObserver;
use App\Services\Integrations\ConnectorRegistry;
use App\Services\PlanEnforcement\PlanEnforcementService;
use App\Services\Transformation\CampaignEmailClickTransformer;
use App\Services\Transformation\CampaignEmailTransformer;
use App\Services\Transformation\ConversionSaleTransformer;
use App\Services\Transformation\TransformerRegistry;
use App\Services\WorkspaceContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Livewire\Livewire;
use Livewire\Volt\Volt;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(WorkspaceContext::class);
        $this->app->singleton(PlanEnforcementService::class);
        $this->app->singleton(ConnectorRegistry::class);
        $this->app->singleton(TransformerRegistry::class, function ($app) {
            $registry = new TransformerRegistry;
            $registry->register('campaign_emails', $app->make(CampaignEmailTransformer::class));
            $registry->register('campaign_email_clicks', $app->make(CampaignEmailClickTransformer::class));
            $registry->register('conversion_sales', $app->make(ConversionSaleTransformer::class));

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Workspace::observe(WorkspaceObserver::class);

        Relation::morphMap([
            'organization' => Organization::class,
            'workspace' => Workspace::class,
            'user' => User::class,
            'admin' => Admin::class,
            'plan' => Plan::class,
            'invitation' => Invitation::class,
            'program' => Program::class,
            'initiative' => Initiative::class,
            'effort' => Effort::class,
            'campaign_email' => CampaignEmail::class,
            'campaign_email_click' => CampaignEmailClick::class,
            'conversion_sale' => ConversionSale::class,
        ]);

        Auth::provider('deactivation-check', function ($app, array $config) {
            return new DeactivationCheckUserProvider($app['hash'], $config['model']);
        });

        Cashier::useCustomerModel(Organization::class);
        Cashier::keepPastDueSubscriptionsActive();

        Gate::define('access-admin-tools', function ($user) {
            $adminEmails = env('ADMIN_EMAILS', '');
            if (empty($adminEmails)) {
                return false;
            }
            $emails = array_filter(
                array_map('trim', explode(',', $adminEmails)),
                fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL)
            );

            return in_array(strtolower($user->email), array_map('strtolower', $emails));
        });

        Gate::define('viewPulse', function ($user = null) {
            return $user && Gate::forUser($user)->allows('access-admin-tools');
        });

        // Register middleware that must run on Livewire update requests.
        // Without this, permission checks (can:integrate) fail because
        // the organization middleware (which sets Spatie's team ID) hasn't run.
        Livewire::addPersistentMiddleware([
            EnsureOrganization::class,
            EnsureWorkspace::class,
        ]);

        // Mount Volt component directories
        Volt::mount([
            resource_path('views/pages'),
        ]);

        // Clear workspace context when organization switches
        Event::listen(OrganizationSwitched::class, function () {
            app(WorkspaceContext::class)->clearWorkspace();
        });
    }
}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name', 'Revat') }}</title>
    <link rel="icon" href="{{ asset('favicon.png') }}" type="image/png">
    <link rel="preload" href="{{ Vite::asset('resources/fonts/plus-jakarta-sans-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ Vite::asset('resources/fonts/ibm-plex-mono-400.woff2') }}" as="font" type="font/woff2" crossorigin>
    @fluxAppearance
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800">
    <x-support.impersonation-banner />
    <flux:sidebar collapsible="mobile" sticky class="bg-zinc-50 dark:bg-zinc-900 border-e border-zinc-200 dark:border-zinc-700">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        <flux:sidebar.brand>
            <x-logo height="h-7" />
        </flux:sidebar.brand>

        <flux:sidebar.nav>
            <flux:sidebar.item icon="home" href="{{ route('dashboard') }}" :current="request()->routeIs('dashboard')" data-testid="nav-dashboard">Dashboard</flux:sidebar.item>
            <flux:sidebar.group expandable heading="Reports" icon="chart-bar" :expanded="request()->routeIs('reports*')">
                <flux:sidebar.item href="{{ route('reports') }}" :current="request()->routeIs('reports')" data-testid="nav-reports">Overview</flux:sidebar.item>
                <flux:sidebar.item href="{{ route('reports.campaign-revenue') }}" :current="request()->routeIs('reports.campaign-revenue')" data-testid="nav-reports-campaign-revenue">Campaign Revenue</flux:sidebar.item>
            </flux:sidebar.group>
            <flux:sidebar.group expandable heading="Campaigns" icon="megaphone" :expanded="request()->routeIs('campaigns.*')">
                <flux:sidebar.item href="{{ route('campaigns.emails') }}" :current="request()->routeIs('campaigns.emails')" data-testid="nav-campaigns-emails">Emails</flux:sidebar.item>
                <flux:sidebar.item href="{{ route('campaigns.email-clicks') }}" :current="request()->routeIs('campaigns.email-clicks')" data-testid="nav-campaigns-email-clicks">Email Clicks</flux:sidebar.item>
            </flux:sidebar.group>
            <flux:sidebar.group expandable heading="Conversions" icon="banknotes" :expanded="request()->routeIs('conversions.*')">
                <flux:sidebar.item href="{{ route('conversions.sales') }}" :current="request()->routeIs('conversions.sales')" data-testid="nav-conversions-sales">Sales</flux:sidebar.item>
            </flux:sidebar.group>
            <flux:sidebar.group expandable heading="Attribution" icon="arrow-path" :expanded="request()->routeIs('attribution.*')">
                <flux:sidebar.item href="{{ route('attribution.programs') }}" :current="request()->routeIs('attribution.programs')" data-testid="nav-attribution-programs">Programs</flux:sidebar.item>
                <flux:sidebar.item href="{{ route('attribution.initiatives') }}" :current="request()->routeIs('attribution.initiatives')" data-testid="nav-attribution-initiatives">Initiatives</flux:sidebar.item>
                <flux:sidebar.item href="{{ route('attribution.efforts') }}" :current="request()->routeIs('attribution.efforts')" data-testid="nav-attribution-efforts">Efforts</flux:sidebar.item>
                <flux:sidebar.item href="{{ route('attribution.connectors') }}" :current="request()->routeIs('attribution.connectors')" data-testid="nav-attribution-connectors">Connectors</flux:sidebar.item>
                <flux:sidebar.item href="{{ route('attribution.stats') }}" :current="request()->routeIs('attribution.stats')" data-testid="nav-attribution-stats">Stats</flux:sidebar.item>
            </flux:sidebar.group>
            <flux:sidebar.item icon="puzzle-piece" href="{{ route('integrations') }}" :current="request()->routeIs('integrations')" data-testid="nav-integrations">Integrations</flux:sidebar.item>
            <flux:sidebar.item icon="cog-6-tooth" href="{{ route('settings.profile') }}" :current="request()->routeIs('settings.*')" data-testid="nav-settings">Settings</flux:sidebar.item>
        </flux:sidebar.nav>

        <flux:spacer />

        @auth
        <flux:sidebar.profile>
            <flux:dropdown position="top" align="start" data-testid="sidebar-user-menu">
                <flux:button variant="ghost" class="w-full justify-start">
                    <div class="flex items-center gap-2">
                        <x-user-avatar :user="auth()->user()" size="size-6" />
                        <span class="text-sm font-medium truncate">{{ auth()->user()->name }}</span>
                    </div>
                </flux:button>

                <flux:menu>
                    <flux:menu.item icon="user-circle" href="{{ route('settings.profile') }}">Profile</flux:menu.item>
                    <flux:menu.item icon="cog-6-tooth" href="{{ route('settings.profile') }}">Settings</flux:menu.item>
                    <flux:separator />
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <flux:menu.item icon="arrow-right-start-on-rectangle" type="submit">Sign out</flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar.profile>
        @endauth
    </flux:sidebar>

    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        <flux:profile avatar="https://unavatar.io/github/placeholder" />
    </flux:header>

    {{-- Desktop Header --}}
    <flux:header class="hidden lg:flex items-center border-b border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 px-6 h-14">
        @auth
            @php
                $user = auth()->user();
                $currentOrg = $user->currentOrganization;
                $orgs = $user->organizations;
            @endphp

            {{-- Organization Switcher --}}
            <flux:dropdown data-testid="org-switcher">
                <flux:button variant="ghost" class="flex items-center gap-2">
                    <div class="size-6 rounded bg-gradient-to-br from-blue-500 to-violet-500 flex items-center justify-center text-white text-xs font-bold">
                        {{ $currentOrg ? strtoupper(substr($currentOrg->name, 0, 1)) : '?' }}
                    </div>
                    <span class="text-sm font-medium">{{ $currentOrg?->name ?? 'Select Organization' }}</span>
                    <flux:icon.chevron-down class="size-4" />
                </flux:button>

                <flux:menu>
                    @foreach ($orgs as $org)
                        <form method="POST" action="{{ route('switch-organization', $org) }}">
                            @csrf
                            <flux:menu.item type="submit">{{ $org->name }}</flux:menu.item>
                        </form>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            <span class="text-zinc-300 dark:text-zinc-600 mx-2">/</span>

            {{-- Workspace Switcher --}}
            @php
                $workspace = app(\App\Services\WorkspaceContext::class)->getWorkspace();
                $accessibleWorkspaceIds = $currentOrg ? $user->accessibleWorkspaceIds($currentOrg) : collect();
                $workspaces = $accessibleWorkspaceIds->isNotEmpty()
                    ? \App\Models\Workspace::whereIn('id', $accessibleWorkspaceIds)->get()
                    : collect();
            @endphp

            <flux:dropdown data-testid="workspace-switcher">
                <flux:button variant="ghost" class="flex items-center gap-2">
                    <span class="text-sm font-medium">{{ $workspace?->name ?? 'Select Workspace' }}</span>
                    <flux:icon.chevron-down class="size-4" />
                </flux:button>

                <flux:menu>
                    @foreach ($workspaces as $ws)
                        <form method="POST" action="{{ route('switch-workspace', $ws) }}">
                            @csrf
                            <flux:menu.item type="submit">{{ $ws->name }}</flux:menu.item>
                        </form>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
        @endauth

        <flux:spacer />

        <div class="flex items-center gap-1" data-testid="header-actions">
            <flux:button variant="ghost" icon="bell" data-testid="notifications" />

            {{-- Appearance Toggle (Light / Dark / System) --}}
            <div x-data="{
                mode: localStorage.getItem('flux.appearance') || 'system',
                set(value) {
                    this.mode = value;
                    $flux.appearance = value;
                }
            }" class="flex items-center" data-testid="appearance-toggle">
                <flux:dropdown>
                    <flux:button variant="ghost" icon-trailing="chevron-down" size="sm">
                        <template x-if="mode === 'light'"><flux:icon.sun class="size-4" /></template>
                        <template x-if="mode === 'dark'"><flux:icon.moon class="size-4" /></template>
                        <template x-if="mode === 'system'"><flux:icon.computer-desktop class="size-4" /></template>
                    </flux:button>

                    <flux:menu>
                        <flux:menu.item icon="sun" x-on:click="set('light')" x-bind:class="mode === 'light' && 'font-semibold'">Light</flux:menu.item>
                        <flux:menu.item icon="moon" x-on:click="set('dark')" x-bind:class="mode === 'dark' && 'font-semibold'">Dark</flux:menu.item>
                        <flux:menu.item icon="computer-desktop" x-on:click="set('system')" x-bind:class="mode === 'system' && 'font-semibold'">System</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            </div>
        </div>
    </flux:header>

    <flux:main>
        {{ $slot }}
    </flux:main>

    @fluxScripts
</body>
</html>

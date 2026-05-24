<nav x-data="{ open: false }" class="sticky top-0 z-50 border-b border-slate-200 bg-white/90 backdrop-blur-xl">
    {{-- Primary Navigation Menu --}}
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 justify-between">
            <div class="flex">
                {{-- Logo --}}
                <div class="flex shrink-0 items-center">
                    <a href="{{ route('dashboard') }}" class="flex items-center gap-3">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-900 text-sm font-bold text-white">
                            PV
                        </span>

                        <div class="hidden sm:block">
                            <p class="text-sm font-bold leading-4 text-slate-950">
                                Product Vault
                            </p>
                            <p class="text-xs leading-4 text-slate-500">
                                Archivio prodotto
                            </p>
                        </div>
                    </a>
                </div>

                {{-- Desktop Navigation Links --}}
                <div class="hidden space-x-7 sm:-my-px sm:ms-10 sm:flex">
                    <x-nav-link href="{{ route('dashboard') }}" :active="request()->routeIs('dashboard')">
                        Dashboard
                    </x-nav-link>

                    <x-nav-link href="{{ url('/documents') }}" :active="request()->is('documents')">
                        Documenti
                    </x-nav-link>

                    <x-nav-link href="{{ url('/products') }}" :active="request()->is('products')">
                        Prodotti
                    </x-nav-link>

                    <x-nav-link href="{{ url('/warranties') }}" :active="request()->is('warranties')">
                        Garanzie
                    </x-nav-link>

                    <x-nav-link href="{{ url('/reviews') }}" :active="request()->is('reviews')">
                        Revisioni
                    </x-nav-link>
                </div>
            </div>

            <div class="hidden sm:flex sm:items-center sm:gap-4 sm:ms-6">
                {{-- Account attivo --}}
                <div class="hidden rounded-2xl bg-slate-50 px-4 py-2 lg:block">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">
                        Account attivo
                    </p>

                    <p class="text-sm font-semibold leading-5 text-slate-900">
                        Personale {{ Auth::user()->name }}
                    </p>
                </div>

                {{-- Azione principale --}}
                <a
                    href="{{ url('/documents/create') }}"
                    class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-700"
                >
                    Carica documento
                </a>

                {{-- Settings Dropdown --}}
                <div class="relative">
                    <x-dropdown align="right" width="60">
                        <x-slot name="trigger">
                            @if (Laravel\Jetstream\Jetstream::managesProfilePhotos())
                                <button class="flex rounded-full border-2 border-transparent text-sm transition focus:border-slate-300 focus:outline-none">
                                    <img
                                        class="h-9 w-9 rounded-full object-cover"
                                        src="{{ Auth::user()->profile_photo_url }}"
                                        alt="{{ Auth::user()->name }}"
                                    />
                                </button>
                            @else
                                <span class="inline-flex rounded-md">
                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium leading-4 text-slate-600 transition hover:bg-slate-50 hover:text-slate-900 focus:outline-none"
                                    >
                                        {{ Auth::user()->name }}

                                        <svg class="ms-2 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                        </svg>
                                    </button>
                                </span>
                            @endif
                        </x-slot>

                        <x-slot name="content">
                            {{-- Account Management --}}
                            <div class="block px-4 py-2 text-xs text-slate-400">
                                Account
                            </div>

                            <x-dropdown-link href="{{ route('profile.show') }}">
                                Profilo
                            </x-dropdown-link>

                            @if (Laravel\Jetstream\Jetstream::hasApiFeatures())
                                <x-dropdown-link href="{{ route('api-tokens.index') }}">
                                    API Token
                                </x-dropdown-link>
                            @endif

                            {{-- Team Management --}}
                            @if (Laravel\Jetstream\Jetstream::hasTeamFeatures())
                                <div class="border-t border-slate-200"></div>

                                <div class="block px-4 py-2 text-xs text-slate-400">
                                    Team
                                </div>

                                <div class="px-4 pb-2">
                                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">
                                        Team attivo
                                    </p>

                                    <p class="mt-1 truncate text-sm font-semibold text-slate-900">
                                        {{ Auth::user()->currentTeam->name }}
                                    </p>
                                </div>

                                <x-dropdown-link href="{{ route('teams.show', Auth::user()->currentTeam->id) }}">
                                    Impostazioni team
                                </x-dropdown-link>

                                @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
                                    <x-dropdown-link href="{{ route('teams.create') }}">
                                        Crea nuovo team
                                    </x-dropdown-link>
                                @endcan

                                @if (Auth::user()->allTeams()->count() > 1)
                                    <div class="border-t border-slate-200"></div>

                                    <div class="block px-4 py-2 text-xs text-slate-400">
                                        Cambia team
                                    </div>

                                    @foreach (Auth::user()->allTeams() as $team)
                                        <x-switchable-team :team="$team" />
                                    @endforeach
                                @endif
                            @endif

                            <div class="border-t border-slate-200"></div>

                            {{-- Authentication --}}
                            <form method="POST" action="{{ route('logout') }}" x-data>
                                @csrf

                                <x-dropdown-link href="{{ route('logout') }}"
                                    @click.prevent="$root.submit();">
                                    Esci
                                </x-dropdown-link>
                            </form>
                        </x-slot>
                    </x-dropdown>
                </div>
            </div>

            {{-- Hamburger --}}
            <div class="-me-2 flex items-center sm:hidden">
                <button
                    @click="open = ! open"
                    class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white p-2 text-slate-500 transition hover:bg-slate-50 hover:text-slate-700 focus:outline-none"
                >
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path
                            :class="{'hidden': open, 'inline-flex': ! open }"
                            class="inline-flex"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16"
                        />

                        <path
                            :class="{'hidden': ! open, 'inline-flex': open }"
                            class="hidden"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"
                        />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    {{-- Responsive Navigation Menu --}}
    <div :class="{'block': open, 'hidden': ! open}" class="hidden border-t border-slate-200 bg-white sm:hidden">
        <div class="space-y-1 px-4 pb-3 pt-3">
            <x-responsive-nav-link href="{{ route('dashboard') }}" :active="request()->routeIs('dashboard')">
                Dashboard
            </x-responsive-nav-link>

            <x-responsive-nav-link href="{{ url('/documents/create') }}" :active="request()->is('documents/create')">
                Carica documento
            </x-responsive-nav-link>

            <x-responsive-nav-link href="{{ url('/documents') }}" :active="request()->is('documents')">
                Documenti
            </x-responsive-nav-link>

            <x-responsive-nav-link href="{{ url('/products') }}" :active="request()->is('products')">
                Prodotti
            </x-responsive-nav-link>

            <x-responsive-nav-link href="{{ url('/warranties') }}" :active="request()->is('warranties')">
                Garanzie
            </x-responsive-nav-link>

            <x-responsive-nav-link href="{{ url('/reviews') }}" :active="request()->is('reviews')">
                Revisioni
            </x-responsive-nav-link>
        </div>

        {{-- Responsive Settings Options --}}
        <div class="border-t border-slate-200 pb-1 pt-4">
            <div class="px-4">
                <div class="text-base font-medium text-slate-800">
                    {{ Auth::user()->name }}
                </div>

                <div class="text-sm font-medium text-slate-500">
                    {{ Auth::user()->email }}
                </div>

                <div class="mt-3 rounded-2xl bg-slate-50 p-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">
                        Account attivo
                    </p>

                    <p class="text-sm font-semibold text-slate-900">
                        Personale {{ Auth::user()->name }}
                    </p>
                </div>
            </div>

            <div class="mt-3 space-y-1 px-4">
                <x-responsive-nav-link href="{{ route('profile.show') }}" :active="request()->routeIs('profile.show')">
                    Profilo
                </x-responsive-nav-link>

                @if (Laravel\Jetstream\Jetstream::hasApiFeatures())
                    <x-responsive-nav-link href="{{ route('api-tokens.index') }}" :active="request()->routeIs('api-tokens.index')">
                        API Token
                    </x-responsive-nav-link>
                @endif

                {{-- Team Management --}}
                @if (Laravel\Jetstream\Jetstream::hasTeamFeatures())
                    <div class="border-t border-slate-200"></div>

                    <div class="block px-4 py-2 text-xs text-slate-400">
                        Team
                    </div>

                    <div class="px-4 py-2">
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-400">
                            Team attivo
                        </p>

                        <p class="mt-1 text-sm font-semibold text-slate-900">
                            {{ Auth::user()->currentTeam->name }}
                        </p>
                    </div>

                    <x-responsive-nav-link href="{{ route('teams.show', Auth::user()->currentTeam->id) }}" :active="request()->routeIs('teams.show')">
                        Impostazioni team
                    </x-responsive-nav-link>

                    @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
                        <x-responsive-nav-link href="{{ route('teams.create') }}" :active="request()->routeIs('teams.create')">
                            Crea nuovo team
                        </x-responsive-nav-link>
                    @endcan

                    @if (Auth::user()->allTeams()->count() > 1)
                        <div class="border-t border-slate-200"></div>

                        <div class="block px-4 py-2 text-xs text-slate-400">
                            Cambia team
                        </div>

                        @foreach (Auth::user()->allTeams() as $team)
                            <x-switchable-team :team="$team" component="responsive-nav-link" />
                        @endforeach
                    @endif
                @endif

                {{-- Authentication --}}
                <form method="POST" action="{{ route('logout') }}" x-data>
                    @csrf

                    <x-responsive-nav-link href="{{ route('logout') }}"
                        @click.prevent="$root.submit();">
                        Esci
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
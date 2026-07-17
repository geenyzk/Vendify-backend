<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Vendify makes airtime, data, bill payments and wallet services feel instant and secure.">

        <title>Vendify</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
            <style>
                /*! tailwindcss v4.0.7 | MIT License | https://tailwindcss.com */@layer theme{:root,:host{--font-sans:'Instrument Sans',ui-sans-serif,system-ui,sans-serif,"Apple Color Emoji","Segoe UI Emoji","Segoe UI Symbol","Noto Color Emoji";--font-serif:ui-serif,Georgia,Cambria,"Times New Roman",Times,serif;--font-mono:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;--color-red-50:oklch(.971 .013 17.38);--color-red-100:oklch(.936 .032 17.717);--color-red-200:oklch(.885 .062 18.334);--color-red-300:oklch(.808 .114 19.571);--color-red-400:oklch(.704 .191 22.216);--color-red-500:oklch(.637 .237 25.331);--color-red-600:oklch(.577 .245 27.325);--color-red-700:oklch(.505 .213 27.518);--color-red-800:oklch(.444 .177 26.899);--color-red-900:oklch(.396 .141 25.723);--color-red-950:oklch(.258 .092 26.042);--color-orange-50:oklch(.98 .016 73.684);--color-orange-100:oklch(.954 .038 75.164);--color-orange-200:oklch(.901 .076 70.697);--color-orange-300:oklch(.837 .128 66.29);--color-orange-400:oklch(.75 .183 55.934);--color-orange-500:oklch(.705 .213 47.604);--color-orange-600:oklch(.646 .222 41.116);--color-orange-700:oklch(.553 .195 38.402);--color-orange-800:oklch(.47 .157 37.304);--color-orange-900:oklch(.408 .123 38.172);--color-orange-950:oklch(.266 .079 36.259);--color-amber-50:oklch(.987 .022 95.277);--color-amber-100:oklch(.962 .059 95.617);--color-amber-200:oklch(.924 .12 95.746);--color-amber-300:oklch(.879 .169 91.605);--color-amber-400:oklch(.828 .189 84.429);--color-amber-500:oklch(.769 .188 70.08);--color-amber-600:oklch(.666 .179 58.318);--color-amber-700:oklch(.555 .163 48.998);--color-amber-800:oklch(.473 .137 46.201);--color-amber-900:oklch(.414 .112 45.904);--color-amber-950:oklch(.279 .077 45.635);--color-yellow-50:oklch(.987 .026 102.212);--color-yellow-100:oklch(.973 .071 103.193);--color-yellow-200:oklch(.945 .129 101.54);--color-yellow-300:oklch(.905 .182 98.111);--color-yellow-400:oklch(.852 .199 91.936);-- [truncated]
            </style>
        @endif
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
        <div class="mx-auto flex min-h-screen max-w-7xl flex-col px-4 py-4 sm:px-6 lg:px-8">
            <header class="mb-8 flex items-center justify-between rounded-full border border-slate-200 bg-white/90 px-4 py-3 shadow-sm backdrop-blur sm:px-6">
                <a href="{{ url('/') }}" class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-white p-0.5"><img src="{{ asset('images/vendify-logo.png') }}" alt="Vendify" class="h-full w-full object-contain"></span>
                    <span class="flex flex-col">
                        <span class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-900">Vendify</span>
                        <span class="text-sm text-slate-500">Secure digital services</span>
                    </span>
                </a>

                @if (Route::has('login'))
                    <nav class="flex items-center gap-2 sm:gap-3">
                        @auth
                            <a href="{{ url('/dashboard') }}" class="rounded-full bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 hover:ring-2 hover:ring-orange-200">
                                Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="rounded-full border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50">
                                Sign in
                            </a>

                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="rounded-full bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800 hover:ring-2 hover:ring-orange-200">
                                    Create account
                                </a>
                            @endif
                        @endauth
                    </nav>
                @endif
            </header>

            <main class="flex-1">
                <section class="grid gap-8 lg:grid-cols-[1.1fr_0.9fr]">
                    <div class="rounded-[2rem] border border-slate-200 bg-white p-8 shadow-sm sm:p-10 lg:p-12">
                        <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-sm font-medium text-slate-700">
                            <span class="h-1.5 w-1.5 rounded-full" style="background:#ff7a1a;box-shadow:0 0 8px rgba(255,122,26,.28)"></span>
                            Trusted operations • Fast onboarding
                        </span>
                        <h1 class="mt-5 text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl">
                            A clear, dependable experience for modern digital payments.
                        </h1>
                        <p class="mt-4 max-w-2xl text-lg leading-8 text-slate-600">
                            Manage services, support customers, and move faster with a polished control center designed for clarity and confidence.
                        </p>

                        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                            @auth
                                <a href="{{ url('/dashboard') }}" class="inline-flex items-center justify-center rounded-full bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">
                                    Open dashboard
                                </a>
                            @else
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-full bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-700">
                                        Get started
                                    </a>
                                @endif
                                @if (Route::has('login'))
                                    <a href="{{ route('login') }}" class="inline-flex items-center justify-center rounded-full border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-50">
                                        Sign in
                                    </a>
                                @endif
                            @endauth
                        </div>

                        <dl class="mt-10 grid gap-4 sm:grid-cols-3">
                            <div class="rounded-2xl bg-slate-50 p-4">
                                <dt class="text-2xl font-semibold text-slate-900">24/7</dt>
                                <dd class="mt-1 text-sm text-slate-600">Always-on operations</dd>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-4">
                                <dt class="text-2xl font-semibold text-slate-900">99.9%</dt>
                                <dd class="mt-1 text-sm text-slate-600">Reliable delivery</dd>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-4">
                                <dt class="text-2xl font-semibold text-slate-900">Fast</dt>
                                <dd class="mt-1 text-sm text-slate-600">Simple workflows</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-[2rem] border border-slate-200 bg-slate-900 p-8 text-white shadow-sm sm:p-10">
                        <div class="rounded-[1.5rem] border border-white/10 bg-white/10 p-6 backdrop-blur">
                            <p class="text-sm font-medium uppercase tracking-[0.25em] text-slate-300">Overview</p>
                            <div class="mt-5 space-y-4">
                                <div class="rounded-2xl bg-white p-4 text-slate-900">
                                    <div class="flex items-center justify-between gap-3">
                                        <div>
                                            <p class="text-sm text-slate-500">Today's volume</p>
                                            <p class="mt-1 text-2xl font-semibold">$24,800</p>
                                        </div>
                                        <span class="rounded-full bg-emerald-50 px-3 py-1 text-sm font-medium text-emerald-700">+12%</span>
                                    </div>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="rounded-2xl border border-white/10 bg-slate-800/70 p-4">
                                        <p class="text-sm text-slate-300">Active services</p>
                                        <p class="mt-2 text-2xl font-semibold">128</p>
                                    </div>
                                    <div class="rounded-2xl border border-white/10 bg-slate-800/70 p-4">
                                        <p class="text-sm text-slate-300">Pending reviews</p>
                                        <p class="mt-2 text-2xl font-semibold">18</p>
                                    </div>
                                </div>

                                <div class="rounded-2xl border border-white/10 bg-slate-800/70 p-4">
                                    <div class="flex items-center justify-between text-sm text-slate-300">
                                        <span>Customer satisfaction</span>
                                        <span class="font-semibold text-white">4.9/5</span>
                                    </div>
                                    <div class="mt-3 h-2 rounded-full bg-slate-700">
                                        <div class="h-2 w-[92%] rounded-full bg-emerald-400"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="mt-8 grid gap-6 lg:grid-cols-[0.95fr_1.05fr]">
                    <div class="rounded-[2rem] border border-slate-200 bg-white p-8 shadow-sm sm:p-10">
                        <h2 class="text-xl font-semibold text-slate-900">Built for dependable daily work</h2>
                        <ul class="mt-6 space-y-4">
                            <li class="flex gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-900 text-sm font-semibold text-white">01</span>
                                <div>
                                    <p class="font-medium text-slate-900">Clean workflow</p>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">Everything stays easy to scan, with clear hierarchy and minimal friction.</p>
                                </div>
                            </li>
                            <li class="flex gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-900 text-sm font-semibold text-white">02</span>
                                <div>
                                    <p class="font-medium text-slate-900">Professional visuals</p>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">Refined spacing, muted tones, and strong contrast keep the experience polished.</p>
                                </div>
                            </li>
                            <li class="flex gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-900 text-sm font-semibold text-white">03</span>
                                <div>
                                    <p class="font-medium text-slate-900">Accessible by default</p>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">High readability, thoughtful focus states, and responsive layouts for every device.</p>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <div class="rounded-[2rem] border border-slate-200 bg-slate-50 p-8 shadow-sm sm:p-10">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <p class="text-sm font-medium text-slate-500">Ready to move</p>
                                <h2 class="mt-1 text-xl font-semibold text-slate-900">A polished experience for teams and clients</h2>
                            </div>
                        </div>

                        <div class="mt-8 grid gap-4 sm:grid-cols-2">
                            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                <p class="text-sm font-medium text-slate-900">Consistent UI</p>
                                <p class="mt-2 text-sm leading-6 text-slate-600">Shared components and spacing make using the platform feel calm and predictable.</p>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                <p class="text-sm font-medium text-slate-900">Clear actions</p>
                                <p class="mt-2 text-sm leading-6 text-slate-600">Primary tasks are obvious, with calm feedback for success and errors.</p>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                <p class="text-sm font-medium text-slate-900">Responsive design</p>
                                <p class="mt-2 text-sm leading-6 text-slate-600">The layout adapts gracefully from mobile to large desktop screens.</p>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                <p class="text-sm font-medium text-slate-900">Modern finish</p>
                                <p class="mt-2 text-sm leading-6 text-slate-600">Subtle visuals, sharp typography, and clean structure keep the experience premium.</p>
                            </div>
                        </div>
                    </div>
                </section>
            </main>

            <footer class="mt-8 flex flex-col gap-3 border-t border-slate-200 pt-6 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                <p>© {{ date('Y') }} Vendify. Focused on clarity and reliability.</p>
                @if (Route::has('login'))
                    <div class="flex flex-wrap gap-4">
                        <a href="{{ route('login') }}" class="transition hover:text-slate-900">Support</a>
                        <a href="{{ route('register') }}" class="transition hover:text-slate-900">Create account</a>
                    </div>
                @endif
            </footer>
        </div>
    </body>
</html>

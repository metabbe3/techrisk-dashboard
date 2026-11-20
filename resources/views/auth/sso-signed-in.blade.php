{{-- ponytail: duplicates layouts.public's ~20-line shell — that layout is a
     Livewire slot layout, not a blade component; extract a shared shell if a
     third public page ever appears. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/tr-logo.svg') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @filamentStyles
    @livewireStyles
</head>
<body class="bg-gray-50 dark:bg-gray-950">
    <div class="min-h-screen flex items-center justify-center px-6">
        <div class="max-w-md w-full rounded-2xl bg-white dark:bg-gray-900 shadow-sm border border-gray-200 dark:border-gray-700 p-8 text-center">
            <h1 class="text-xl font-bold text-gray-900 dark:text-white">You're signed in</h1>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                Signed in as <span class="font-medium text-gray-800 dark:text-gray-100">{{ auth()->user()?->name }}</span> ({{ auth()->user()?->email }}).
            </p>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Open the investigation form link from your email — you no longer need to sign in separately. If your link has expired or was revoked, ask the person who requested the investigation to re-send it.
            </p>
        </div>
    </div>
    @livewireScripts
    @filamentScripts
</body>
</html>

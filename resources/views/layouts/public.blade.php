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
    <style>
        [x-cloak] { display: none !important; }
    </style>
    @livewireStyles
</head>
<body>
    {{ $slot }}
    @livewireScripts
    @filamentScripts
</body>
</html>

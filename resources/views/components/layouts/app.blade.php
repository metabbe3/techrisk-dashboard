<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Tech Risk Dashboard')</title>
    @livewireStyles
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; }
    </style>
</head>
<body class="bg-gray-100">
    {{ $slot }}
    @livewireScripts
</body>
</html>

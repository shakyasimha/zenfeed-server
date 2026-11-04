<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Server Status</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,600&display=swap" rel="stylesheet" />

    <!-- Styles / Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-[#111827] text-white font-['Figtree'] antialiased h-screen flex items-center justify-center">
    <div class="max-w-xl mx-auto p-6 bg-[#1f2937] border border-gray-800 rounded-lg shadow-2xl text-center">
        <div class="inline-block px-3 py-1 mb-4 text-xs font-semibold tracking-wider text-red-400 uppercase bg-red-500/10 rounded-full border border-red-500/20">
            HTTP/1.1 200 OK
        </div>
        <h1 class="text-3xl font-bold tracking-tight text-white mb-2">
            Server Alive
        </h1>
        <p class="text-gray-400 text-sm">
            Backend services are operating normally and accepting connections.
        </p>
    </div>
</body>

</html>
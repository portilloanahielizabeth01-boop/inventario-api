<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inventario multicanal</title>
    @vite(['resources/css/app.css', 'resources/js/inventario.js'])
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen">
    <main class="max-w-5xl mx-auto p-4 sm:p-8 space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold">Sistema de Inventario Multicanal</h1>
                <p class="text-sm text-slate-500">REST + GraphQL + WebSockets (Laravel Reverb)</p>
            </div>
            <span id="estado-ws" class="text-xs font-medium px-3 py-1 rounded-full bg-amber-100 text-amber-800">
                WebSocket: conectando…
            </span>
        </header>

        <section class="bg-white rounded-xl shadow-sm p-4 sm:p-6">
            <h2 class="font-semibold mb-3">Nuevo producto</h2>
            <form id="form-crear" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <input name="nombre" placeholder="Nombre" required maxlength="100"
                       class="border rounded-lg px-3 py-2">
                <input name="precio" type="number" step="0.01" min="0" placeholder="Precio" required
                       class="border rounded-lg px-3 py-2">
                <input name="stock" type="number" min="0" placeholder="Stock" required
                       class="border rounded-lg px-3 py-2">
                <div class="flex gap-2">
                    <button type="submit" data-canal="rest"
                            class="flex-1 bg-indigo-600 text-white rounded-lg px-3 py-2 hover:bg-indigo-700">REST</button>
                    <button type="submit" data-canal="graphql"
                            class="flex-1 bg-pink-600 text-white rounded-lg px-3 py-2 hover:bg-pink-700">GraphQL</button>
                </div>
            </form>
            <p id="error-crear" class="text-sm text-red-600 mt-2"></p>
        </section>

        <section class="bg-white rounded-xl shadow-sm p-4 sm:p-6 overflow-x-auto">
            <h2 class="font-semibold mb-3">Productos</h2>
            <table class="w-full text-sm">
                <thead class="text-left text-slate-500 border-b">
                    <tr>
                        <th class="py-2">ID</th>
                        <th>Nombre</th>
                        <th class="text-right">Precio</th>
                        <th class="text-center">Stock</th>
                        <th class="text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody id="tabla-productos"></tbody>
            </table>
        </section>

        <section class="bg-white rounded-xl shadow-sm p-4 sm:p-6">
            <h2 class="font-semibold mb-3">Eventos recibidos en vivo (canal <code>inventario</code>)</h2>
            <ul id="log-eventos" class="text-xs font-mono space-y-1 max-h-64 overflow-y-auto"></ul>
        </section>
    </main>
</body>
</html>

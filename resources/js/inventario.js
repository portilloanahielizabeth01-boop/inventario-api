import './echo';

const tabla = document.getElementById('tabla-productos');
const log = document.getElementById('log-eventos');
const estadoWs = document.getElementById('estado-ws');
const form = document.getElementById('form-crear');
const errorCrear = document.getElementById('error-crear');

const productos = new Map();

const formatoPrecio = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' });

const headers = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
};

function escapar(texto) {
    const div = document.createElement('div');
    div.textContent = texto;
    return div.innerHTML;
}

function renderizar() {
    const filas = [...productos.values()].sort((a, b) => a.id - b.id);

    tabla.innerHTML = filas.map((p) => `
        <tr class="border-b last:border-0" data-id="${p.id}">
            <td class="py-2 text-slate-500">${p.id}</td>
            <td>${escapar(p.nombre)}</td>
            <td class="text-right">${formatoPrecio.format(p.precio)}</td>
            <td class="text-center font-semibold" data-stock>${p.stock}</td>
            <td class="text-right whitespace-nowrap">
                <button data-accion="restar" class="px-2 py-1 rounded bg-slate-200 hover:bg-slate-300">−1</button>
                <button data-accion="sumar" class="px-2 py-1 rounded bg-slate-200 hover:bg-slate-300">+1</button>
                <button data-accion="eliminar" class="px-2 py-1 rounded bg-red-100 text-red-700 hover:bg-red-200">Eliminar</button>
            </td>
        </tr>
    `).join('');
}

function resaltar(id) {
    const fila = tabla.querySelector(`tr[data-id="${id}"]`);

    if (fila) {
        fila.classList.add('bg-yellow-100');
        setTimeout(() => fila.classList.remove('bg-yellow-100'), 1200);
    }
}

function normalizar(p) {
    return { id: Number(p.id), nombre: p.nombre, precio: Number(p.precio), stock: Number(p.stock) };
}

async function cargarProductos() {
    const respuesta = await fetch('/api/productos', { headers });
    const datos = await respuesta.json();

    productos.clear();
    datos.forEach((p) => productos.set(Number(p.id), normalizar(p)));
    renderizar();
}

/* ---------- Acciones vía REST ---------- */

async function cambiarStock(id, stock) {
    await fetch(`/api/productos/${id}`, {
        method: 'PATCH',
        headers,
        body: JSON.stringify({ stock }),
    });
    // No actualizamos la tabla acá: la actualización llega por WebSocket,
    // igual que en el resto de los navegadores conectados.
}

async function eliminar(id) {
    await fetch(`/api/productos/${id}`, { method: 'DELETE', headers });
}

tabla.addEventListener('click', (e) => {
    const boton = e.target.closest('button[data-accion]');

    if (!boton) {
        return;
    }

    const id = Number(boton.closest('tr').dataset.id);
    const producto = productos.get(id);

    if (boton.dataset.accion === 'sumar') {
        cambiarStock(id, producto.stock + 1);
    } else if (boton.dataset.accion === 'restar' && producto.stock > 0) {
        cambiarStock(id, producto.stock - 1);
    } else if (boton.dataset.accion === 'eliminar') {
        eliminar(id);
    }
});

/* ---------- Crear: REST o GraphQL ---------- */

async function crearPorRest(datos) {
    const respuesta = await fetch('/api/productos', {
        method: 'POST',
        headers,
        body: JSON.stringify(datos),
    });
    const cuerpo = await respuesta.json();

    if (!respuesta.ok) {
        throw new Error(Object.values(cuerpo.errors ?? {}).flat().join(' ') || cuerpo.message);
    }
}

async function crearPorGraphql(datos) {
    const respuesta = await fetch('/graphql', {
        method: 'POST',
        headers,
        body: JSON.stringify({
            query: `mutation Crear($nombre: String!, $precio: Float!, $stock: Int!) {
                crearProducto(nombre: $nombre, precio: $precio, stock: $stock) { id nombre precio stock }
            }`,
            variables: datos,
        }),
    });
    const cuerpo = await respuesta.json();

    if (cuerpo.errors) {
        const validacion = cuerpo.errors[0].extensions?.validation;
        throw new Error(validacion ? Object.values(validacion).flat().join(' ') : cuerpo.errors[0].message);
    }
}

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    errorCrear.textContent = '';

    const fd = new FormData(form);
    const datos = {
        nombre: fd.get('nombre'),
        precio: Number(fd.get('precio')),
        stock: Number(fd.get('stock')),
    };

    try {
        if (e.submitter?.dataset.canal === 'graphql') {
            await crearPorGraphql(datos);
        } else {
            await crearPorRest(datos);
        }
        form.reset();
    } catch (error) {
        errorCrear.textContent = error.message;
    }
});

/* ---------- WebSocket: Laravel Echo + Reverb ---------- */

function registrarEvento(evento) {
    const item = document.createElement('li');
    const hora = new Date().toLocaleTimeString();
    item.textContent = `[${hora}] ${evento.accion}: #${evento.producto.id} ${evento.producto.nombre} (stock ${evento.producto.stock})`;
    log.prepend(item);
}

window.Echo.channel('inventario')
    .listen('.producto.actualizado', (evento) => {
        console.log('Producto actualizado:', evento);
        registrarEvento(evento);

        if (evento.accion === 'eliminado') {
            productos.delete(evento.producto.id);
        } else {
            productos.set(evento.producto.id, normalizar(evento.producto));
        }

        renderizar();
        resaltar(evento.producto.id);
    });

const conexion = window.Echo.connector.pusher.connection;

conexion.bind('state_change', ({ current }) => {
    const conectado = current === 'connected';
    estadoWs.textContent = `WebSocket: ${conectado ? 'conectado' : current}`;
    estadoWs.className = `text-xs font-medium px-3 py-1 rounded-full ${
        conectado ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'
    }`;
});

cargarProductos();

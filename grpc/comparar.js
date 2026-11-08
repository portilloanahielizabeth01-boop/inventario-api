/**
 * Prueba comparativa REST vs GraphQL vs gRPC vs WebSocket.
 *
 * Requiere en ejecución: php artisan serve, php artisan reverb:start,
 * php artisan queue:work y node server.js.
 *
 * Uso:  node comparar.js [repeticiones]
 */
import { GRPC_DIRECCION, LARAVEL_URL, grpc, inventario } from './config.js';
import { readFileSync } from 'node:fs';

const N = Number(process.argv[2] ?? 50);
const ID = 1;
const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
const cliente = new inventario.ProductoService(GRPC_DIRECCION, grpc.credentials.createInsecure());
const serializarRespuesta = inventario.ProductoService.service.ObtenerProducto.responseSerialize;

/* ---------- utilidades ---------- */

async function medir(fn) {
    await fn(); // calentamiento
    const tiempos = [];
    let bytes = 0;

    for (let i = 0; i < N; i++) {
        const inicio = performance.now();
        bytes = await fn();
        tiempos.push(performance.now() - inicio);
    }

    tiempos.sort((a, b) => a - b);

    return {
        bytes,
        promedio: tiempos.reduce((a, b) => a + b, 0) / N,
        mediana: tiempos[Math.floor(N / 2)],
    };
}

async function rest(ruta, opciones = {}) {
    const r = await fetch(`${LARAVEL_URL}/api${ruta}`, { headers, ...opciones });
    const texto = await r.text();

    return { status: r.status, bytes: Buffer.byteLength(texto), json: texto ? JSON.parse(texto) : null };
}

async function graphql(query, variables = {}) {
    const r = await fetch(`${LARAVEL_URL}/graphql`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ query, variables }),
    });
    const texto = await r.text();

    return { bytes: Buffer.byteLength(texto), json: JSON.parse(texto) };
}

function grpcObtener(id) {
    return new Promise((resolve, reject) => {
        cliente.ObtenerProducto({ id }, (e, p) => (e ? reject(e) : resolve(p)));
    });
}

function grpcCrear(datos) {
    return new Promise((resolve, reject) => {
        cliente.CrearProducto(datos, (e, p) => (e ? reject(e) : resolve(p)));
    });
}

/* ---------- WebSocket (protocolo Pusher que habla Reverb) ---------- */

function leerEnv(nombre) {
    const env = readFileSync(new URL('../.env', import.meta.url), 'utf8');
    const linea = env.split(/\r?\n/).find((l) => l.startsWith(`${nombre}=`));

    return linea?.slice(nombre.length + 1).replace(/^"|"$/g, '');
}

function conectarReverb() {
    const host = leerEnv('REVERB_HOST') ?? 'localhost';
    const puerto = leerEnv('REVERB_PORT') ?? '8080';
    const clave = leerEnv('REVERB_APP_KEY');
    const ws = new WebSocket(`ws://${host}:${puerto}/app/${clave}?protocol=7&client=js&version=8.4.0`);
    const esperando = [];

    ws.addEventListener('message', (m) => {
        const mensaje = JSON.parse(m.data);

        if (mensaje.event === 'producto.actualizado') {
            const datos = JSON.parse(mensaje.data);

            // Solo interesan los cambios del producto medido (se ignoran los de creación/eliminación).
            if (datos.producto.id === ID && datos.accion === 'actualizado') {
                esperando.shift()?.({ datos, bytes: Buffer.byteLength(m.data) });
            }
        }
    });

    return new Promise((resolve, reject) => {
        ws.addEventListener('error', () => reject(new Error('No se pudo conectar a Reverb')));
        ws.addEventListener('message', function suscribir(m) {
            const mensaje = JSON.parse(m.data);

            if (mensaje.event === 'pusher:connection_established') {
                ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: 'inventario' } }));
            }

            if (mensaje.event === 'pusher_internal:subscription_succeeded') {
                ws.removeEventListener('message', suscribir);
                resolve({ ws, siguienteEvento: () => new Promise((r) => esperando.push(r)) });
            }
        });
    });
}

/* ---------- pruebas ---------- */

const fmt = (r) => `${r.bytes} B | prom ${r.promedio.toFixed(2)} ms | mediana ${r.mediana.toFixed(2)} ms`;

console.log(`\nRepeticiones por prueba: ${N}  (Laravel: ${LARAVEL_URL}, gRPC: ${GRPC_DIRECCION})\n`);

console.log('== Obtener un producto (ID 1) ==');
const restUno = await medir(async () => (await rest(`/productos/${ID}`)).bytes);
console.log(`REST     GET /api/productos/${ID}: ${fmt(restUno)}`);

const gqlUno = await medir(async () => (await graphql(`{ producto(id: ${ID}) { id nombre precio stock } }`)).bytes);
console.log(`GraphQL  producto(id) {id nombre precio stock}: ${fmt(gqlUno)}`);

const grpcUno = await medir(async () => serializarRespuesta(await grpcObtener(ID)).length);
console.log(`gRPC     ObtenerProducto(id): ${fmt(grpcUno)}`);

console.log('\n== Seleccionar campos (solo nombre y precio) ==');
const gqlCampos = await medir(async () => (await graphql(`{ producto(id: ${ID}) { nombre precio } }`)).bytes);
console.log(`GraphQL  producto(id) {nombre precio}: ${fmt(gqlCampos)}`);

console.log('\n== Listado completo ==');
const restLista = await medir(async () => (await rest('/productos')).bytes);
console.log(`REST     GET /api/productos: ${fmt(restLista)}`);
const gqlListaMin = await medir(async () => (await graphql('{ productos { nombre precio } }')).bytes);
console.log(`GraphQL  productos {nombre precio}: ${fmt(gqlListaMin)}`);
const gqlListaFull = await medir(async () => (await graphql('{ productos { id nombre precio stock } }')).bytes);
console.log(`GraphQL  productos {id nombre precio stock}: ${fmt(gqlListaFull)}`);

console.log('\n== Crear producto (1 vez por tecnología; luego se elimina) ==');
const creados = [];

let t = performance.now();
const rCreado = await rest('/productos', {
    method: 'POST',
    body: JSON.stringify({ nombre: 'Prueba REST', precio: 1000, stock: 1 }),
});
console.log(`REST     POST /api/productos -> ${rCreado.status}: ${rCreado.bytes} B | ${(performance.now() - t).toFixed(2)} ms`);
creados.push(rCreado.json.id);

t = performance.now();
const gCreado = await graphql(
    'mutation { crearProducto(nombre: "Prueba GraphQL", precio: 1000, stock: 1) { id nombre precio stock } }',
);
console.log(`GraphQL  crearProducto: ${gCreado.bytes} B | ${(performance.now() - t).toFixed(2)} ms`);
creados.push(Number(gCreado.json.data.crearProducto.id));

t = performance.now();
const pCreado = await grpcCrear({ nombre: 'Prueba gRPC', precio: 1000, stock: 1 });
console.log(`gRPC     CrearProducto: ${serializarRespuesta(pCreado).length} B | ${(performance.now() - t).toFixed(2)} ms`);
creados.push(pCreado.id);

console.log('\n== Recibir cambios en vivo (WebSocket / Reverb) ==');
try {
    const { ws, siguienteEvento } = await conectarReverb();
    const latencias = [];
    let bytesEvento = 0;
    const original = (await rest(`/productos/${ID}`)).json.stock;

    for (let i = 0; i < 5; i++) {
        const evento = siguienteEvento();
        const inicio = performance.now();
        await rest(`/productos/${ID}`, { method: 'PATCH', body: JSON.stringify({ stock: original + i + 1 }) });
        const { bytes } = await evento;
        latencias.push(performance.now() - inicio);
        bytesEvento = bytes;
    }

    await rest(`/productos/${ID}`, { method: 'PATCH', body: JSON.stringify({ stock: original }) });
    const prom = latencias.reduce((a, b) => a + b, 0) / latencias.length;
    console.log(`WebSocket evento producto.actualizado: ${bytesEvento} B | latencia PATCH→evento prom ${prom.toFixed(2)} ms (min ${Math.min(...latencias).toFixed(2)}, max ${Math.max(...latencias).toFixed(2)})`);
    ws.close();
} catch (error) {
    console.log(`WebSocket: ${error.message}`);
}

for (const id of creados) {
    await rest(`/productos/${id}`, { method: 'DELETE' });
}

cliente.close();
console.log('\nProductos de prueba eliminados.');

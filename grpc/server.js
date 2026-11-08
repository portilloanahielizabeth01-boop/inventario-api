/**
 * Servidor gRPC (microservicio en Node.js).
 *
 * Laravel no actúa como servidor gRPC: este proceso independiente recibe las
 * llamadas gRPC y consulta Laravel a través de la API interna protegida
 * (/api/interno/...), que usa el mismo ProductoService que REST y GraphQL.
 */
import { GRPC_DIRECCION, INTERNAL_API_TOKEN, LARAVEL_URL, grpc, inventario } from './config.js';

if (!INTERNAL_API_TOKEN) {
    console.error('Falta INTERNAL_API_TOKEN (en el .env de Laravel o como variable de entorno).');
    process.exit(1);
}

async function llamarLaravel(ruta, opciones = {}) {
    const respuesta = await fetch(`${LARAVEL_URL}/api/interno${ruta}`, {
        ...opciones,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Internal-Token': INTERNAL_API_TOKEN,
        },
    });

    return { status: respuesta.status, cuerpo: await respuesta.json() };
}

function aRespuesta(p) {
    return { id: Number(p.id), nombre: p.nombre, precio: Number(p.precio), stock: Number(p.stock) };
}

/** Traduce los códigos HTTP de Laravel a códigos de estado gRPC. */
function errorGrpc(status, cuerpo) {
    const codigos = {
        401: grpc.status.UNAUTHENTICATED,
        404: grpc.status.NOT_FOUND,
        422: grpc.status.INVALID_ARGUMENT,
    };
    const detalle = cuerpo?.errors ? Object.values(cuerpo.errors).flat().join(' ') : cuerpo?.message;

    return { code: codigos[status] ?? grpc.status.INTERNAL, details: detalle ?? `HTTP ${status}` };
}

const implementacion = {
    async ObtenerProducto(call, callback) {
        try {
            const { status, cuerpo } = await llamarLaravel(`/productos/${call.request.id}`);

            if (status !== 200) {
                return callback(errorGrpc(status, cuerpo));
            }

            callback(null, aRespuesta(cuerpo));
        } catch (error) {
            callback({ code: grpc.status.UNAVAILABLE, details: `Laravel no responde: ${error.message}` });
        }
    },

    async CrearProducto(call, callback) {
        try {
            const { status, cuerpo } = await llamarLaravel('/productos', {
                method: 'POST',
                body: JSON.stringify(call.request),
            });

            if (status !== 201) {
                return callback(errorGrpc(status, cuerpo));
            }

            callback(null, aRespuesta(cuerpo));
        } catch (error) {
            callback({ code: grpc.status.UNAVAILABLE, details: `Laravel no responde: ${error.message}` });
        }
    },
};

const servidor = new grpc.Server();
servidor.addService(inventario.ProductoService.service, implementacion);

servidor.bindAsync(GRPC_DIRECCION, grpc.ServerCredentials.createInsecure(), (error, puerto) => {
    if (error) {
        console.error(error);
        process.exit(1);
    }

    console.log(`Servidor gRPC escuchando en ${GRPC_DIRECCION} (puerto ${puerto}) -> Laravel en ${LARAVEL_URL}`);
});

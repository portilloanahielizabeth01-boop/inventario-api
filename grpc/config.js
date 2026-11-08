import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import grpc from '@grpc/grpc-js';
import protoLoader from '@grpc/proto-loader';

const aqui = dirname(fileURLToPath(import.meta.url));

/**
 * Lee una variable del entorno o, si no existe, del .env de Laravel (carpeta superior),
 * para no duplicar el token de la API interna.
 */
function leerVariable(nombre, porDefecto = '') {
    if (process.env[nombre]) {
        return process.env[nombre];
    }

    try {
        const env = readFileSync(join(aqui, '..', '.env'), 'utf8');
        const linea = env.split(/\r?\n/).find((l) => l.startsWith(`${nombre}=`));

        if (linea) {
            return linea.slice(nombre.length + 1).replace(/^"|"$/g, '');
        }
    } catch {
        // Sin .env: se usa el valor por defecto.
    }

    return porDefecto;
}

export const LARAVEL_URL = leerVariable('LARAVEL_URL', 'http://127.0.0.1:8000');
export const INTERNAL_API_TOKEN = leerVariable('INTERNAL_API_TOKEN');
export const GRPC_DIRECCION = leerVariable('GRPC_DIRECCION', '127.0.0.1:50051');

const definicion = protoLoader.loadSync(join(aqui, 'producto.proto'), {
    keepCase: true,
    longs: Number,
    defaults: true,
});

export const inventario = grpc.loadPackageDefinition(definicion).inventario;
export { grpc };

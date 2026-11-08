/**
 * Cliente gRPC: solicita el producto con ID 1 (o el ID pasado por argumento).
 *
 * Uso:  node client.js [id]
 */
import { GRPC_DIRECCION, grpc, inventario } from './config.js';

const id = Number(process.argv[2] ?? 1);
const cliente = new inventario.ProductoService(GRPC_DIRECCION, grpc.credentials.createInsecure());

const inicio = performance.now();

cliente.ObtenerProducto({ id }, (error, producto) => {
    const ms = (performance.now() - inicio).toFixed(2);

    if (error) {
        console.error(`Error gRPC (${grpc.status[error.code]}): ${error.details}`);
        process.exit(1);
    }

    const bytes = inventario.ProductoService.service.ObtenerProducto.responseSerialize(producto).length;

    console.log('Producto recibido por gRPC:', producto);
    console.log(`Tiempo: ${ms} ms | Tamaño del mensaje protobuf: ${bytes} bytes`);
    cliente.close();
});

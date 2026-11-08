# Sistema de Inventario Multicanal

Trabajo práctico de la **Tecnicatura Universitaria en Programación (UTN - FRRe)**.

Es una aplicación en Laravel que gestiona productos y expone sus datos por cuatro canales de comunicación distintos (**REST**, **GraphQL**, **WebSockets** y **gRPC**), para poder comparar cómo funciona cada uno, cómo se estructura y cómo rinde.

## Tecnologías usadas

| Tecnología | Para qué |
|---|---|
| Laravel 13 / PHP 8.4 | Backend principal |
| SQLite | Base de datos |
| Lighthouse | Servidor GraphQL |
| Laravel Reverb + Laravel Echo | WebSockets (actualizaciones en tiempo real) |
| Node.js + `@grpc/grpc-js` | Microservicio gRPC |
| Vite + Tailwind CSS | Frontend de prueba |
| PHPUnit | Tests |

## Cómo está armado

Toda la lógica del inventario (validaciones y reglas) está centralizada en **`ProductoService`**. Así REST, GraphQL y gRPC usan exactamente las mismas reglas y no hay código repetido.

```
Navegador / Postman ──► REST  (/api/productos)  ──┐
Navegador / Postman ──► GraphQL (/graphql)      ──┼──► ProductoService ──► Base de datos
Microservicio gRPC  ──► API interna (con token) ──┘          │
                                                             ▼
                                              Evento ProductoActualizado
                                                             │ (cola)
                                                             ▼
                                         Reverb (WebSocket) ──► todos los navegadores conectados
```

El servicio gRPC corre como un proceso aparte en Node.js. Laravel no hace de servidor gRPC: el microservicio recibe las llamadas gRPC y le pide los datos a Laravel a través de una API interna protegida con un token (`X-Internal-Token`).

### Archivos principales

```
app/
├── Services/ProductoService.php                   → reglas del inventario
├── Http/Controllers/Api/ProductoController.php    → API REST
├── Http/Controllers/Api/InternoProductoController.php → API interna para gRPC
├── Http/Middleware/VerificarTokenInterno.php      → protege la API interna
├── GraphQL/Mutations/                             → mutaciones GraphQL
├── Events/ProductoActualizado.php                 → evento que se emite por WebSocket
└── Models/Producto.php
graphql/schema.graphql                             → esquema GraphQL
resources/views/inventario.blade.php               → página de prueba
resources/js/inventario.js                         → lógica del frontend + Echo
grpc/
├── producto.proto                                 → contrato gRPC
├── server.js                                      → servidor gRPC
├── client.js                                      → cliente gRPC
└── comparar.js                                    → script de mediciones
tests/Feature/                                     → tests automáticos
```

---

## Instalación

Requisitos: PHP 8.3 o superior, Composer, Node.js 20 o superior y npm.

```bash
composer install
npm install
npm run build

cd grpc
npm install
cd ..

cp .env.example .env        # si no existe
php artisan key:generate
php artisan migrate --seed  # crea las tablas y carga 5 productos de ejemplo
```

En el `.env`, completar el token de la API interna con cualquier cadena larga y aleatoria:

```env
INTERNAL_API_TOKEN=un_token_largo_y_secreto
```

Para generar uno: `php -r "echo bin2hex(random_bytes(24));"`

## Cómo ejecutarlo

Hay que abrir **4 terminales**, una para cada proceso:

```bash
# 1. Servidor web de Laravel
php artisan serve

# 2. Servidor de WebSockets
php artisan reverb:start

# 3. Worker de la cola (procesa los eventos de broadcast)
php artisan queue:work --sleep=0.1

# 4. Microservicio gRPC
cd grpc
node server.js
```

Después, abrir **http://127.0.0.1:8000** en el navegador.

> Usamos `--sleep=0.1` en el worker porque con el valor por defecto (3 segundos) los eventos tardaban casi 3 s en llegar a los navegadores.

---

## Uso de cada canal

### REST

| Método | Ruta | Descripción | Respuesta |
|---|---|---|---|
| GET | `/api/productos` | Lista todos los productos | 200 |
| POST | `/api/productos` | Crea un producto | 201 / 422 |
| GET | `/api/productos/{id}` | Muestra un producto | 200 / 404 |
| PUT/PATCH | `/api/productos/{id}` | Modifica un producto | 200 / 404 / 422 |
| DELETE | `/api/productos/{id}` | Elimina un producto | 204 / 404 |

Ejemplo en Postman:

```http
POST http://127.0.0.1:8000/api/productos
Content-Type: application/json
Accept: application/json

{
  "nombre": "Mouse Gamer",
  "precio": 18500,
  "stock": 20
}
```

**Validaciones:**
- `nombre`: obligatorio, texto, hasta 100 caracteres.
- `precio`: obligatorio, numérico, mayor o igual a 0.
- `stock`: obligatorio, entero, mayor o igual a 0.

En PUT/PATCH se puede mandar solo el campo que se quiere cambiar, por ejemplo `{"stock": 5}`.

### GraphQL

Todas las consultas van por `POST http://127.0.0.1:8000/graphql`.

```json
{ "query": "{ productos { nombre precio } }" }
```

```json
{ "query": "{ producto(id: 1) { id nombre precio stock } }" }
```

```json
{ "query": "mutation { crearProducto(nombre: \"Webcam HD\", precio: 31200, stock: 15) { id nombre } }" }
```

```json
{ "query": "mutation { actualizarStock(id: 1, stock: 25) { id stock } }" }
```

La mutación `crearProducto` usa las **mismas validaciones** que REST. Si los datos son inválidos, los errores vienen en `errors[0].extensions.validation`.

### WebSockets (Reverb)

Cada vez que se crea, modifica o elimina un producto, se emite el evento `producto.actualizado` por el canal público `inventario`:

```js
Echo.channel('inventario')
    .listen('.producto.actualizado', (evento) => {
        console.log('Producto actualizado:', evento);
    });
```

Ejemplo de lo que llega:

```json
{
  "accion": "actualizado",
  "producto": { "id": 1, "nombre": "Mouse Gamer", "precio": 18500, "stock": 21 },
  "fecha": "2026-11-08T00:15:44+00:00"
}
```

**Prueba con dos navegadores:**
1. Abrir http://127.0.0.1:8000 en dos navegadores.
2. Verificar que diga "WebSocket: conectado".
3. Tocar +1 o −1 en uno de los dos.
4. El otro se actualiza solo, sin recargar la página.

> Para la práctica usamos un canal público. En producción habría que usar canales privados con autorización.

### gRPC

El contrato está en [grpc/producto.proto](grpc/producto.proto). Con el servidor gRPC corriendo, desde la carpeta `grpc`:

```bash
node client.js 1
```

Salida:

```
Producto recibido por gRPC: { id: 1, nombre: 'Mouse Gamer', precio: 18500, stock: 20 }
Tiempo: 267.41 ms | Tamaño del mensaje protobuf: 26 bytes
```

> La primera llamada tarda más porque tiene que abrir la conexión. Con la conexión ya abierta, el tiempo baja a unos 50 ms (ver la tabla comparativa).

Además de `ObtenerProducto`, agregamos `CrearProducto` para poder completar la fila de "Crear producto" en la tabla comparativa.

---

## Tabla comparativa

Las mediciones se tomaron con `node grpc/comparar.js 30` (30 repeticiones por prueba) en Windows 11, con `php artisan serve` y SQLite.

| Prueba | REST | GraphQL | gRPC | WebSocket |
|---|---|---|---|---|
| **Obtener un producto** | `GET /api/productos/1` | `query { producto(id:1) {...} }` | `ObtenerProducto({id:1})` | No aplica (solo recibe) |
| **Seleccionar campos** | No, siempre trae todo | Sí, se piden solo los campos necesarios | No, el mensaje es fijo | No, lo define el servidor |
| **Crear producto** | `POST` → 201 (146 B) | `crearProducto` (87 B) | `CrearProducto` (26 B) | Avisa la creación a todos |
| **Recibir cambios en vivo** | No (habría que hacer polling) | No (necesitaría subscriptions) | No (necesitaría streaming) | **Sí** |
| **Tamaño de respuesta** | 148 B | 81 B (61 B con 2 campos) | **26 B** | 214 B por evento |
| **Tiempo de respuesta** | ≈ 41 ms | ≈ 58 ms | ≈ 50 ms | ≈ 480 ms (PATCH → evento) |

**Conclusiones:**
- **REST** fue el más rápido porque es el camino más directo: ruta → controlador → base de datos.
- **GraphQL** tarda un poco más porque tiene que interpretar la consulta en cada petición, pero sus respuestas son mucho más chicas. El listado completo pesa 759 B en REST y 242 B en GraphQL pidiendo solo nombre y precio.
- **gRPC** manda el mensaje más chico porque usa formato binario (Protocol Buffers). En nuestro caso no es el más rápido porque el microservicio le pregunta a Laravel por HTTP, así que se suma un salto extra.
- **WebSocket** es el único que avisa los cambios en tiempo real. Casi toda la demora la pone la cola, no Reverb. Con `ShouldBroadcastNow` el envío sería inmediato.

El análisis completo y las respuestas a las preguntas del trabajo están en [INFORME.md](INFORME.md).

---

## Tests

```bash
php artisan test
```

Son 19 tests que cubren:
- el CRUD REST, con validaciones y códigos HTTP;
- las consultas y mutaciones GraphQL, incluido que validan igual que REST;
- que se emita el evento al crear, modificar o eliminar;
- la protección con token de la API interna.

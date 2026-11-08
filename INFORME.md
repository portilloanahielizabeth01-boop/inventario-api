# Trabajo práctico: Sistema de Inventario Multicanal

Aplicación Laravel 13 que gestiona productos y los expone por **REST**, **GraphQL** (Lighthouse), **WebSockets** (Laravel Reverb + Echo) y **gRPC** (un microservicio en Node.js). Las cuatro interfaces comparten la misma lógica: la clase `App\Services\ProductoService`.

## 1. Arquitectura

```
                      ┌───────────────────────── Laravel (php artisan serve :8000) ─────────────────────────┐
 Navegador / Postman ─┤ REST     /api/productos ............ ProductoController ─┐                            │
                      │ GraphQL  /graphql .................. Lighthouse + resolvers├─► ProductoService ─► SQLite│
 Microservicio gRPC ──┤ API interna /api/interno/* (token) . InternoProductoController┘        │                │
   (Node :50051)      └───────────────────────────────────────────────────────────────────────┼────────────────┘
        ▲                                                                                     │ evento ProductoActualizado
  Cliente gRPC                                                   cola (queue:work) ◄──────────┘
                                                                        │
                                                          Reverb (WebSocket :8080) ──► navegadores con Laravel Echo
```

| Archivo | Función |
|---|---|
| `app/Services/ProductoService.php` | Reglas de validación y de negocio. Emite `ProductoActualizado` **después** de guardar el cambio. |
| `app/Http/Controllers/Api/ProductoController.php` | REST: `index`, `store`, `show`, `update`, `destroy`. |
| `graphql/schema.graphql` y `app/GraphQL/Mutations/*` | Queries `productos` y `producto(id)`. Mutaciones `crearProducto` y `actualizarStock`. |
| `app/Events/ProductoActualizado.php` | Evento `ShouldBroadcast` en el canal público `inventario`, con el nombre `producto.actualizado`. |
| `resources/views/inventario.blade.php` y `resources/js/inventario.js` | Página web con Echo: lista, crea (por REST o GraphQL), cambia el stock y muestra los eventos en vivo. |
| `app/Http/Controllers/Api/InternoProductoController.php` y `app/Http/Middleware/VerificarTokenInterno.php` | API interna protegida con el header `X-Internal-Token` que consume el microservicio gRPC. |
| `grpc/producto.proto`, `grpc/server.js`, `grpc/client.js` | Contrato, servidor y cliente gRPC. |
| `grpc/comparar.js` | Script que toma las mediciones de la tabla comparativa. |
| `tests/Feature/*` | 19 tests: REST, GraphQL, eventos y API interna. |

### Códigos HTTP de la API REST

| Operación | Éxito | Errores |
|---|---|---|
| `GET /api/productos` | 200 | – |
| `POST /api/productos` | 201 Created | 422 (validación) |
| `GET /api/productos/{id}` | 200 | 404 |
| `PUT/PATCH /api/productos/{id}` | 200 | 404, 422 |
| `DELETE /api/productos/{id}` | 204 No Content | 404 |

Validaciones: `nombre` es obligatorio, texto de hasta 100 caracteres. `precio` es obligatorio, numérico y ≥ 0. `stock` es obligatorio, entero y ≥ 0. En `update` cada campo es opcional (`sometimes`), así que se puede enviar solo `{"stock": 5}`.

## 2. Cómo ejecutarlo

```bash
composer install && npm install && npm run build
cd grpc && npm install && cd ..
php artisan migrate --seed          # crea la tabla y carga 5 productos de ejemplo
```

Abrir una terminal para cada proceso:

```bash
php artisan serve
php artisan reverb:start
php artisan queue:work              # recomendado: php artisan queue:work --sleep=0.1 (ver sección 4)
cd grpc && node server.js
```

* Web (Etapa 4): abrir http://127.0.0.1:8000 en **dos navegadores**. Al tocar +1 o −1 en uno, el otro actualiza la tabla y el registro de eventos sin recargar la página.
* Cliente gRPC (Etapa 5): `cd grpc && node client.js 1`.
* Comparativa: `cd grpc && node comparar.js 30`.
* Tests: `php artisan test`.

### Ejemplos de prueba (Postman o curl)

```http
POST http://127.0.0.1:8000/api/productos
Content-Type: application/json
Accept: application/json

{ "nombre": "Mouse Gamer", "precio": 18500, "stock": 20 }
```

```http
POST http://127.0.0.1:8000/graphql
Content-Type: application/json

{ "query": "{ productos { nombre precio } }" }
{ "query": "{ productos { id nombre precio stock } }" }
{ "query": "mutation { crearProducto(nombre: \"Webcam\", precio: 31200, stock: 15) { id nombre } }" }
```

Si la mutación recibe datos inválidos, devuelve los mismos mensajes que REST dentro de `errors[0].extensions.validation`, porque las dos interfaces validan con `ProductoService`.

## 3. Comparación del tamaño de respuesta: REST vs GraphQL

| Consulta | Bytes |
|---|---|
| REST `GET /api/productos/1` | **148 B** |
| GraphQL `producto(id:1) { id nombre precio stock }` | **81 B** |
| GraphQL `producto(id:1) { nombre precio }` | **61 B** |
| REST `GET /api/productos` (5 productos) | **759 B** |
| GraphQL `productos { id nombre precio stock }` | **341 B** |
| GraphQL `productos { nombre precio }` | **242 B** |

**Por qué son diferentes:**

* REST devuelve la representación completa que define el servidor, con todas las columnas del modelo (incluidas `created_at` y `updated_at`). El cliente no puede elegir los campos, y eso produce *over-fetching*.
* En GraphQL el cliente indica exactamente qué campos quiere, y el servidor devuelve solo esos. Con `{ nombre precio }` el listado pesa un 68 % menos que en REST.
* Los tipos del esquema también influyen: GraphQL serializa `precio` como `Float` (`18500`), mientras que REST lo envía como el string decimal `"18500.00"`.
* GraphQL agrega una envoltura (`{"data":{...}}`). Por eso, si se piden todos los campos, la diferencia se achica. En REST se podría reducir el tamaño con *API Resources* o *sparse fieldsets*, pero el servidor tendría que implementarlo para cada endpoint.

## 4. Actividad comparativa (resultados propios)

Entorno: Windows 11, PHP 8.4 con `php artisan serve` (servidor de desarrollo de un solo hilo), SQLite y Node 24. Se tomaron 30 repeticiones por prueba (después de una de calentamiento) con `grpc/comparar.js`.

| Prueba | REST | GraphQL | gRPC | WebSocket |
|---|---|---|---|---|
| **Obtener un producto** | `GET /api/productos/1`. Simple y cacheable por HTTP. | `query { producto(id:1) {...} }` con `POST /graphql`. | `ObtenerProducto({id:1})`, con tipos definidos en el `.proto`. | No aplica: no es un modelo de petición/respuesta. Solo recibe lo que el servidor publica. |
| **Seleccionar campos** | No se puede: siempre llegan todos los campos. | Sí, es su punto fuerte (`{ nombre precio }`). | No: el mensaje tiene campos fijos. Solo se podría cambiar el contrato o usar `FieldMask`. | No: el servidor decide el payload en `broadcastWith()`. |
| **Crear producto** | `POST /api/productos` → 201 (146 B). | Mutación `crearProducto` (87 B), con las mismas validaciones. | `CrearProducto` (26 B). El microservicio llama a la API interna. | No se usa para crear. Notifica la creación a todos los clientes (`accion: "creado"`). |
| **Recibir cambios en vivo** | No. Habría que hacer *polling*. | No con queries o mutaciones (requeriría *subscriptions*). | No en una llamada unaria (requeriría *server streaming*). | **Sí.** Todos los navegadores reciben `producto.actualizado` sin recargar. |
| **Tamaño de respuesta** | 148 B (1 producto) y 759 B (listado) | 81 B (4 campos), 61 B (2 campos) y 341 B / 242 B (listado) | **26 B** (protobuf binario) | 214 B por evento (JSON en el frame) |
| **Tiempo de respuesta** | ≈ 41 ms (mediana) | ≈ 58 ms | ≈ 50 ms (incluye el salto gRPC → HTTP → Laravel) | ≈ 480 ms de PATCH a evento con `queue:work --sleep=0.1`. Con `queue:work` por defecto: ≈ 2,8 s |

**Interpretación de los tiempos:**

* **REST** es el más rápido acá porque es el camino más directo: ruta → controlador → Eloquent.
* **GraphQL** tarda unos 15 ms más porque Lighthouse tiene que parsear, validar y ejecutar la consulta contra el esquema en cada petición.
* **gRPC** envía el mensaje más chico (26 B frente a 148 B), pero en este diseño el microservicio Node consulta Laravel por HTTP. Por eso su tiempo es el de REST más el salto gRPC. Si el servidor gRPC accediera directamente a los datos, sería el más rápido gracias al binario protobuf y a HTTP/2 con conexiones persistentes.
* **WebSocket**: casi todo el tiempo es la **cola**. `ShouldBroadcast` encola el evento y, por defecto, `queue:work` revisa la cola de la base de datos cada 3 s. Por eso medimos unos 2,8 s, y con `--sleep=0.1` bajó a unos 0,48 s. Para que la entrega sea inmediata se puede usar `ShouldBroadcastNow` (sin cola) o una cola en Redis. Una vez publicado, Reverb entrega el evento a todos los clientes en milisegundos y sin que ellos pregunten.

## 5. Preguntas para el informe final

**1. ¿Por qué REST utiliza diferentes métodos HTTP mientras GraphQL suele utilizar un único endpoint?**
REST modela el sistema como **recursos** identificados por URLs (`/api/productos/1`), y usa la semántica de los verbos HTTP para indicar la operación: GET lee, POST crea, PUT/PATCH modifica y DELETE elimina. Así aprovecha la infraestructura de HTTP: códigos de estado, caché de GET, idempotencia de PUT y DELETE, logs y proxies. GraphQL, en cambio, es un **lenguaje de consulta**: la operación (query o mutation) y los datos pedidos viajan dentro del cuerpo de la petición, así que alcanza con un solo endpoint (`POST /graphql`) que interpreta la consulta contra un esquema tipado. Como contrapartida, se pierde la caché HTTP nativa y casi todas las respuestas son 200, incluso cuando hay errores, que se informan dentro de `errors`.

**2. ¿Qué ventajas tiene solicitar solamente determinados campos en GraphQL?**
Se evita el *over-fetching*: en nuestras pruebas, el listado bajó de 759 B a 242 B. Esto implica menos ancho de banda (importante en móviles), menos serialización y respuestas adaptadas a cada pantalla sin crear endpoints nuevos. También evita el *under-fetching*, porque se pueden traer datos relacionados en una sola petición. Además, el backend puede evolucionar agregando campos sin romper a los clientes existentes, y el esquema documenta exactamente qué se puede pedir.

**3. ¿Por qué gRPC utiliza archivos `.proto`?**
El `.proto` es el **contrato** entre cliente y servidor, independiente del lenguaje. Define los servicios, los métodos (`rpc ObtenerProducto`) y los mensajes con tipos estrictos y números de campo. A partir de él se genera (o se carga, como hicimos con `@grpc/proto-loader`) el código de serialización en cada lenguaje: el servidor podría estar en Node y el cliente en Python o Go. Los números de campo permiten codificar los mensajes en binario con **Protocol Buffers** (26 B frente a 148 B de JSON) y facilitan la compatibilidad hacia atrás cuando el contrato cambia.

**4. ¿Qué ventaja ofrecen los WebSockets cuando varios usuarios modifican un inventario?**
Mantienen una conexión **bidireccional y persistente**, de modo que el servidor puede *empujar* los cambios en el momento en que ocurren. Si un usuario vende o modifica stock, los demás ven el valor actualizado sin recargar ni hacer *polling*, como comprobamos con dos navegadores. Esto reduce los conflictos (por ejemplo, vender un producto que ya no tiene stock porque la pantalla estaba desactualizada), evita miles de peticiones de consulta innecesarias y mejora la experiencia de trabajo colaborativo.

**5. ¿Qué tecnologías utilizarían para un sistema de gestión de ventas con aplicación web y móvil? Justificar.**
* **REST** como API principal para las operaciones CRUD y transaccionales (ventas, cobros, altas). Es simple, estándar, fácil de asegurar (Sanctum o OAuth), cacheable y lo consume cualquier cliente.
* **GraphQL** (opcional) para las pantallas de la app móvil y los dashboards. Permite pedir solo los campos necesarios y combinar datos en una sola petición, lo que ahorra datos móviles.
* **WebSockets (Reverb)** con canales **privados y autorizados** para el stock, los pedidos y las notificaciones en tiempo real entre sucursales o vendedores.
* **gRPC** solo en la comunicación interna entre microservicios (por ejemplo, facturación o logística), donde importan el rendimiento y los contratos estrictos. No conviene para el navegador, porque los browsers no hablan gRPC nativo y necesitarían gRPC-Web con un proxy.

**6. ¿Qué inconvenientes podrían aparecer al implementar todas estas tecnologías en un mismo proyecto?**
* **Mayor complejidad operativa:** en esta práctica ya hacen falta cuatro procesos (`serve`, `reverb:start`, `queue:work` y el servidor gRPC) y dos runtimes (PHP y Node). Cada uno hay que desplegarlo, monitorearlo y escalarlo.
* **Duplicación e inconsistencia de reglas** si no se centraliza la lógica. Lo resolvimos con `ProductoService`, pero exige disciplina.
* **Múltiples contratos que mantener sincronizados:** rutas REST, esquema GraphQL y `.proto`. Un cambio en el modelo obliga a tocar los tres.
* **Seguridad más amplia:** hay más superficie de ataque y cada canal requiere su propia autenticación (tokens, autorización de canales privados, token de la API interna).
* **Pruebas y depuración más difíciles:** errores asíncronos (colas, eventos), latencias que se suman (como el salto gRPC → REST) y herramientas distintas para cada tecnología.
* **Curva de aprendizaje** para el equipo y más dependencias externas que actualizar.

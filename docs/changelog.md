---
layout: default
title: Historial de versiones
nav_order: 99
---

## 1.2.0 · 2025-10-21

- Implementa un historial persistente de generación mediante `GenerationHistoryManager`, configurable vía `blueprintx.history.enabled` y `blueprintx.history.path`, con respaldo automático de hashes, tamaños y contenidos anteriores.
- Añade el comando `php artisan blueprintx:rollback` para revertir corridas completas por ID de ejecución o corrida, con opciones `--dry-run` y `--force` y una vista previa detallada de acciones.
- `php artisan blueprintx:generate` ahora registra cada archivo escrito o sobrescrito, reutiliza el ID de ejecución en el scaffolding de autenticación y publica advertencias cuando el historial no puede almacenarse.
- El generador API actualiza `routes/api.php` insertando `use` y rutas `Route::apiResource` sin duplicados, normaliza URIs al cambiar el blueprint y respeta `api.middleware`.
- El parser YAML acepta `api.base_path` o `api.basePath` y el generador de base de datos reconoce el tipo `id`, genera `$table->id()` y restablece seeders por módulo para mantener el historial limpio.
- El `OutputWriter` conserva checksums y copias anteriores para permitir restauraciones, mientras que la infraestructura evita reescrituras cuando un archivo no cambia byte a byte.
- Se documenta la hoja de ruta de la UI en `docs/ui-development-roadmap.md` para coordinar el desarrollo backend/frontend.

## 1.1.1 · 2025-10-12

- Añade una guía detallada para instalar Laravel Sanctum, requisito fundamental para habilitar la autenticación en el scaffolding generado.
- Explica los pasos necesarios para la configuración de Sanctum, incluyendo la publicación de archivos de configuración, migraciones y ajustes recomendados en el middleware.
- Facilita la integración de Sanctum en proyectos existentes, asegurando compatibilidad con las nuevas funcionalidades de autenticación.

## 1.1.0 · 2025-10-10

- Añade scaffolding de autenticación con Laravel Sanctum, incluyendo controlador REST, requests validadas y rutas `login/register/logout/me` preconfiguradas.
- La entidad `User` ahora soporta contraseñas hasheadas, tokens personales y atributos estándar (`email_verified_at`, `remember_token`).
- Seeders y factories generan un usuario administrador de ejemplo (`admin@example.com` / `password`) para facilitar las pruebas de login.
- La colección Postman integra endpoints de autenticación actualizados y variables para gestionar credenciales semilla durante el registro.

## 1.0.2 · 2025-10-15

- Añade un generador de colecciones Postman que reutiliza la especificación OpenAPI para construir peticiones con cabeceras, parámetros y cuerpos de ejemplo.
- Incorpora banderas CLI (`--with-postman` / `--without-postman`) y configuración dedicada (`features.postman` y `paths.postman`).
- Documenta la nueva ruta de salida y las opciones de Postman en la guía de configuración y referencia de comandos.

## 1.0.1 · 2025-10-08

- Corrige la generación de métodos `belongsTo` para entidades con múltiples claves foráneas hacia el mismo modelo destino.
- Se añaden pruebas unitarias que cubren la regresión en la capa de dominio.
- Alinea la generación de migraciones con identificadores declarados en los blueprints, respetando columnas UUID/ULID y ajustando las claves foráneas correspondientes.
- Los modelos de dominio establecen `public $incrementing` y `$keyType` cuando el identificador no es auto-incremental.
- Se incorporan pruebas unitarias y se actualizan snapshots para asegurar el nuevo comportamiento.
- Los repositorios generados se registran automáticamente en `AppServiceProvider`, eliminando la necesidad de enlazarlos manualmente.
- Se genera automáticamente la clase base `App\Application\Shared\Filters\QueryFilter` para habilitar filtros reutilizables en las consultas.
- Los controladores y recursos API ahora interpretan `api.resources.includes`, soportando relaciones `belongsTo`, `hasOne`, colecciones y alias declarados en el blueprint.

## 1.0.0 · 2025-09-30

- Publicación inicial de BlueprintX con soporte para generación de capas dominio, aplicación, infraestructura, API, base de datos, pruebas y documentación OpenAPI.

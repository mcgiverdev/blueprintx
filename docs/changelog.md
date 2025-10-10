---
layout: default
title: Historial de versiones
nav_order: 99
---

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

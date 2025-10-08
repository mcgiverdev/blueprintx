---
layout: default
title: Historial de versiones
nav_order: 99
---

## 1.0.2 · 2025-10-09

- Alinea la generación de migraciones con identificadores declarados en los blueprints, respetando columnas UUID/ULID y ajustando las claves foráneas correspondientes.
- Los modelos de dominio establecen `public $incrementing` y `$keyType` cuando el identificador no es auto-incremental.
- Se incorporan pruebas unitarias y se actualizan snapshots para asegurar el nuevo comportamiento.
- Los repositorios generados se registran automáticamente en `AppServiceProvider`, eliminando la necesidad de enlazarlos manualmente.
- Se genera automáticamente la clase base `App\Application\Shared\Filters\QueryFilter` para habilitar filtros reutilizables en las consultas.

## 1.0.1 · 2025-10-08

- Corrige la generación de métodos `belongsTo` para entidades con múltiples claves foráneas hacia el mismo modelo destino.
- Se añaden pruebas unitarias que cubren la regresión en la capa de dominio.

## 1.0.0 · 2025-09-30

- Publicación inicial de BlueprintX con soporte para generación de capas dominio, aplicación, infraestructura, API, base de datos, pruebas y documentación OpenAPI.

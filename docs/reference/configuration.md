---
layout: default
title: Configuración
nav_order: 3
parent: Referencia
---

Comprende cómo BlueprintX se integra con tu aplicación Laravel y qué opciones puedes ajustar en `config/blueprintx.php`.

## Publicación inicial

BlueprintX incluye dos grupos de publicación para que copies los archivos configurables al proyecto:

```bash
php artisan vendor:publish --provider="BlueprintX\BlueprintXServiceProvider" --tag=blueprintx-config
php artisan vendor:publish --provider="BlueprintX\BlueprintXServiceProvider" --tag=blueprintx-templates
```

- `blueprintx-config` copia `config/blueprintx.php` para personalizar rutas, drivers y banderas.
- `blueprintx-templates` replica las plantillas Twig en `resources/vendor/blueprintx/templates` para que puedas modificarlas sin tocar el paquete.

## `paths`

| Clave | Predeterminado | Descripción |
|-------|----------------|-------------|
| `blueprints` | `base_path('blueprints')` | Directorio raíz donde se buscarán los YAML. Debe existir antes de ejecutar los comandos. |
| `templates` | `resource_path('vendor/blueprintx/templates')` | Ruta opcional con plantillas sobrescritas. Si no existe, se usan las provistas por el paquete. |
| `output` | `base_path()` | Directorio base donde se escribirán los archivos generados. |
| `api` | `app/Http/Controllers/Api` | Carpeta relativa para controladores HTTP. |
| `api_requests` | `app/Http/Requests/Api` | Carpeta relativa para Form Requests. |
| `api_resources` | `app/Http/Resources` | Carpeta relativa para `JsonResource`. |

## Arquitecturas y generadores

- `default_architecture` define el driver que se usará cuando el blueprint no lo establezca explícitamente. Por defecto: `hexagonal`.
- `architectures` es un mapa de alias ⇒ clase de driver. Cada driver decide qué plantillas usar y cómo orquestar las capas.
- `generators` lista las clases registradas en el `GenerationPipeline`. Puedes desactivar una capa eliminando o comentando su entrada.

## Motor Twig

La sección `twig` permite ajustar el caché y la recarga automática de plantillas:

```php
'twig' => [
    'cache' => storage_path('framework/cache/blueprintx/twig'),
    'debug' => false,
    'auto_reload' => true,
],
```

Establece `cache` en `false` para desactivar el caché durante el desarrollo.

## Características de API

La sección `features.api` controla cómo se generan controladores, Form Requests y recursos JSON:

- `form_requests.enabled`: desactiva la creación de Form Requests si no los necesitas.
- `form_requests.namespace` y `path`: ajustan el namespace y la carpeta donde se guardan.
- `form_requests.authorize_by_default`: define si los Form Requests incluyen `authorize()` retornando `true` automáticamente.
- `resources.enabled`: alterna la generación de `JsonResource`.
- `resources.namespace`, `resources.path`: personalizan su ubicación.
- `resources.preserve_query`: mantiene parámetros de consulta al paginar.
- `controller_traits`: mapea traits que se adjuntan a los controladores (por defecto `HandlesDomainExceptions` y `FormatsPagination`).
- `optimistic_locking`: agrupa banderas para controlar el bloqueo optimista (cabeceras, columnas, wildcard `*`). Cada bandera puede sobrescribirse vía variables de entorno `BLUEPRINTX_API_OPTIMISTIC_LOCK_*`.

## Características de documentación

La sección `features.openapi` habilita la generación de documentación OpenAPI cuando ejecutas `blueprintx:generate`:

| Clave | Tipo | Descripción |
|-------|------|-------------|
| `enabled` | bool | Genera archivos OpenAPI junto con el resto de capas. |
| `validate` | bool | Ejecuta validación automática del documento. |
| `validation_mode` | string | `official` (usa la CLI oficial) o `schema` (usa el `schema_path`). |
| `schema_path` | `string\|null` | Ruta a un JSON Schema alternativo para validar el resultado. |

Estas opciones admiten variables de entorno: `BLUEPRINTX_OPENAPI_VALIDATION_MODE` y `BLUEPRINTX_OPENAPI_SCHEMA`.

## Generación de pruebas

`TestsLayerGenerator` utiliza la configuración de recursos y bloqueo optimista para producir pruebas funcionales coherentes. Mantén `features.api.*` actualizada para que las pruebas reflejen tu API real.

## Sobrescritura de plantillas

1. Publica las plantillas con `--tag=blueprintx-templates`.
2. Edita cualquier `.twig` en `resources/vendor/blueprintx/templates`.
3. BlueprintX buscará primero en este directorio antes de usar las plantillas empaquetadas, respetando la misma estructura de carpetas.

## Buenas prácticas

- Versiona los archivos publicados para detectar diferencias con nuevas versiones del paquete.
- Crea variables de entorno específicas por entorno (`.env.testing`, `.env.production`) cuando modifiques cabeceras o rutas de salida.
- Revisa la [referencia de comandos](cli.html) para combinar estas opciones con banderas CLI como `--only` u `--architecture`.

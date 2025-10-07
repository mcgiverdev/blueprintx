---
layout: default
title: Comandos CLI
nav_order: 2
parent: Referencia
---

La instalación de BlueprintX registra tres comandos Artisan que cubren descubrimiento, validación y generación. Todos leen la ruta de blueprints desde `config('blueprintx.paths.blueprints')` y retornan código de salida `0` en casos exitosos.

## `blueprintx:list`

Lista los blueprints disponibles y muestra información básica.

```bash
php artisan blueprintx:list --module=hr --entity=employee
```

| Opción | Descripción |
|--------|-------------|
| `--module=` | Filtra por módulo (`hr`, `crm`, etc.). |
| `--entity=` | Filtra por entidad (nombre del archivo sin extensión). |
| `--json` | Devuelve un payload con `blueprints` y `errors` listo para scripts. |

La salida por defecto es una tabla con módulo, entidad, arquitectura y ruta relativa. Si algún archivo contiene errores de parseo, el comando los lista en consola y finaliza con estado `1`.

## `blueprintx:validate`

Valida uno o todos los blueprints y reporta errores o advertencias.

```bash
php artisan blueprintx:validate           # todos los módulos
php artisan blueprintx:validate hr        # sólo el módulo hr
php artisan blueprintx:validate --json    # salida JSON
```

- El argumento `module` es opcional; si se omite, analizará el árbol completo.
- Cada mensaje incluye `code`, `message` y `path` (cuando aplica).
- La salida JSON devuelve un arreglo de objetos con `status`, `errors` y `warnings` por archivo.
- El comando resume el número total de errores y warnings; retorna `1` cuando al menos un blueprint falla la validación.

## `blueprintx:generate`

Genera código para los blueprints seleccionados.

```bash
php artisan blueprintx:generate              # todo el catálogo
php artisan blueprintx:generate hr employee  # módulo + entidad
php artisan blueprintx:generate --dry-run    # previsualiza cambios
```

### Argumentos y opciones destacadas

| Bandera | Uso |
|---------|-----|
| `module`, `entity` | Argumentos posicionales para filtrar blueprints. |
| `--module=`, `--entity=` | Alternativa en forma de opción. |
| `--only=domain,api` | Limita las capas generadas. Acepta cualquier clave registrada en `config('blueprintx.generators')`. |
| `--dry-run` | Muestra los archivos que se generarían sin escribir en disco. |
| `--force` | Sobrescribe archivos existentes. |
| `--with-openapi` | Fuerza la generación de documentación OpenAPI sin importar la configuración global. |
| `--without-openapi` | Desactiva OpenAPI aunque esté habilitado en la configuración. |
| `--validate-openapi` | Obliga a validar el documento generado incluso si `features.openapi.validate` es `false`. |
| `--skip-openapi-validation` | Omite la validación (útil offline). |
| `--architecture=` | Sobrescribe el driver declarado en el blueprint. |

### Resumen de salida

Al finalizar imprime un resumen con archivos escritos, sobrescritos, omitidos, previsualizados, warnings y errores. El comando retorna `0` cuando no hay errores críticos.

> Combina las banderas con la configuración documentada en [Configuración](configuration.html) para controlar directorios de salida, namespace de Form Requests, recursos JSON y bloqueo optimista.

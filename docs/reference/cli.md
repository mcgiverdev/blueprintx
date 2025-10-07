---
layout: default
title: Comandos CLI
nav_order: 2
parent: Referencia
---

## `blueprintx:list`

Lista los blueprints disponibles y muestra información básica.

```bash
php artisan blueprintx:list --module=hr --entity=employee
```

| Opción | Descripción |
|--------|-------------|
| `--module=` | Filtra por módulo. |
| `--entity=` | Filtra por entidad. |
| `--json` | Devuelve la salida en formato JSON. |

La salida por defecto es una tabla con módulo, entidad, arquitectura y ruta relativa.

## `blueprintx:validate`

Valida uno o todos los blueprints y reporta errores o advertencias.

```bash
php artisan blueprintx:validate           # todos los módulos
php artisan blueprintx:validate hr        # sólo el módulo hr
php artisan blueprintx:validate --json    # salida JSON
```

- Analiza recursivamente `config('blueprintx.paths.blueprints')`.
- Cada mensaje incluye código, descripción y ruta YAML cuando aplica.
- Finaliza con código de salida `1` si se detectan errores.

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
| `--only=domain,api` | Limita las capas generadas (separa por comas). |
| `--dry-run` | Muestra los archivos que se generarían sin escribir en disco. |
| `--force` | Sobrescribe archivos existentes. |
| `--with-openapi` | Fuerza la generación de documentación OpenAPI. |
| `--without-openapi` | Omite la generación de OpenAPI, incluso si está habilitada en configuración. |
| `--validate-openapi` | Valida el archivo OpenAPI resultante. |
| `--skip-openapi-validation` | Deshabilita la validación, útil en entornos offline. |
| `--architecture=` | Sobrescribe el driver declarado en el blueprint. |

### Resumen de salida

Al finalizar imprime un resumen con archivos escritos, sobrescritos, omitidos, previsualizados, warnings y errores. El comando retorna `0` cuando no hay errores críticos.

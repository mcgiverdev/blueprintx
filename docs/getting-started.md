---
layout: default
title: Inicio rápido
nav_order: 2
---

## Requisitos previos

- PHP 8.2 o superior con extensiones `json`, `mbstring`, `openssl` y `pdo`.
- Laravel 11.x o 12.x con Artisan disponible.
- Composer 2.5 o superior.
- Directorio `blueprints/` en la raíz del proyecto para almacenar los YAML.

## Instalación

```bash
composer require mcgiverdev/blueprintx:^1.0
```

Opcionalmente publica la configuración para personalizar rutas y características:

```bash
php artisan vendor:publish --provider="BlueprintX\BlueprintXServiceProvider" --tag=blueprintx-config
```

Esto creará `config/blueprintx.php` con rutas y banderas de características (OpenAPI, optimistc locking, traits de controladores, etc.).

## Configuración mínima

Asegúrate de que `config/blueprintx.paths.blueprints` apunte al directorio donde guardarás los YAML. Por defecto es `base_path('blueprints')`.

```php
// config/blueprintx.php
'paths' => [
    'blueprints' => base_path('blueprints'),
    'api' => 'app/Http/Controllers/Api',
    'api_requests' => 'app/Http/Requests/Api',
],
```

## Primer blueprint

Crea `blueprints/hr/employee.yaml` con un contenido básico:

```yaml
entity: Employee
module: hr
options:
  timestamps: true
fields:
  first_name:
    type: string
    rules: required|max:120
  email:
    type: string
    rules: required|email|unique:employees,email
api:
  resource: /hr/employees
  endpoints:
    index: {}
    store: {}
```

## Validación

Antes de generar código ejecuta la validación para detectar errores de sintaxis o reglas:

```bash
php artisan blueprintx:validate
```

## Generación

Genera artefactos para todos los blueprints o filtrando por módulo/entidad:

```bash
# Generación completa
php artisan blueprintx:generate

# Generar únicamente el módulo HR
php artisan blueprintx:generate hr

# Forzar reescritura y generar documentación OpenAPI
php artisan blueprintx:generate --force --with-openapi
```

La salida destacará archivos nuevos, sobrescritos y warnings. Si estás probando, ejecuta `--dry-run` para previsualizar sin escribir en disco.

## Próximos pasos

- Revisa la [referencia de blueprints](reference/blueprint-format.html) para aprovechar todas las secciones.
- Consulta la [referencia de comandos](reference/cli.html) para automatizar flujos de validación y despliegue.
- Sigue la [guía de trabajo recomendada](guides/workflow.html) para integrar BlueprintX en tus pipelines.

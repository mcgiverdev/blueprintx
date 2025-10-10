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

Si planeas sobreescribir plantillas, publica también los recursos Twig:

```bash
php artisan vendor:publish --provider="BlueprintX\BlueprintXServiceProvider" --tag=blueprintx-templates
```

Los archivos quedarán en `resources/vendor/blueprintx/templates` y tendrán prioridad sobre los que trae el paquete.

### Habilitar Laravel Sanctum

BlueprintX genera APIs aseguradas con `auth:sanctum`. Asegúrate de instalar y configurar Sanctum en tu proyecto:

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

En Laravel 11/12 con bootstrap minimal agrega el guard `sanctum` en `config/auth.php` si aún no existe:

```php
'guards' => [
  'web' => [
    'driver' => 'session',
    'provider' => 'users',
  ],
  'sanctum' => [
    'driver' => 'sanctum',
    'provider' => 'users',
  ],
],
```

Y en `bootstrap/app.php` añade el middleware stateful al grupo `api` (solo cuando Sanctum esté disponible):

```php
->withMiddleware(function (Middleware $middleware): void {
  if (class_exists('Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful')) {
    $middleware->appendToGroup('api', [
      'Laravel\\Sanctum\\Http\\Middleware\\EnsureFrontendRequestsAreStateful',
    ]);
  }
})
```

Esto habilita la autenticación Bearer esperada por los controladores y la colección Postman generada.

#### Usuarios semilla y autenticación

Al ejecutar `php artisan migrate:fresh --seed`, BlueprintX registra un usuario administrador de ejemplo (`admin@example.com` / `password`).
Los endpoints REST `/auth/login`, `/auth/register`, `/auth/logout` y `/auth/me` quedan disponibles de inmediato y la colección Postman exportada incluye las peticiones necesarias para trabajar con tokens Sanctum.

El flujo recomendado es:
- Hacer login con el admin semilla para obtener el token Sanctum.
- Consumir el resto de endpoints pasando el header `Authorization: Bearer {{bearer_token}}`.

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

Agrega `--json` cuando necesites integrar los resultados en pipelines automáticos.

Para consultar el inventario de blueprints disponible usa:

```bash
php artisan blueprintx:list --module=hr
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

Cuando `features.openapi.enabled` esté activo en la configuración, el comando generará y validará automáticamente el documento OpenAPI (puedes forzar esta acción con `--with-openapi` o omitirla con `--without-openapi`).

## Próximos pasos

- Revisa la [referencia de blueprints](reference/blueprint-format.html) para aprovechar todas las secciones.
- Consulta la [referencia de comandos](reference/cli.html) para automatizar flujos de validación y despliegue.
- Ajusta la [configuración](reference/configuration.html) para adaptar rutas de salida, namespaces y generación de documentación.
- Sigue la [guía de trabajo recomendada](guides/workflow.html) para integrar BlueprintX en tus pipelines.

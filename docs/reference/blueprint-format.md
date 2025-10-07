---
layout: default
title: Lenguaje de blueprints
nav_order: 1
parent: Referencia
---

## Estructura general

Cada archivo YAML describe una entidad y los artefactos que deben generarse.

```yaml
entity: Employee        # Nombre de la entidad en PascalCase
module: hr              # Agrupa blueprints por dominio
architecture: hexagonal # Opcional. Usa el valor por defecto si se omite
options:                # Configuración transversal
authorize: true
fields:                 # Campos y validaciones
  first_name:
    type: string
    rules: required|max:120
relations:              # Relaciones Eloquent
  belongsTo:
    department:
      relation: App\Domain\Hr\Models\Department
      foreign_key: department_id
api:                     # API REST
  resource: /hr/employees
  endpoints:
    index: {}
    toggle-active:
      method: patch
      action: ToggleActive
search:                  # Búsquedas declarativas
  filters:
    - name
errors:                  # Errores de dominio y códigos HTTP
  employee_inactive:
    code: domain.employee.inactive
    status: 409
    message: El empleado está inactivo.
```

## Metadatos

| Clave        | Tipo      | Requerido | Descripción |
|--------------|-----------|-----------|-------------|
| `entity`     | string    | Sí        | Nombre canon de la entidad generada. |
| `module`     | string    | Sí        | Módulo lógico (carpeta dentro de `blueprints/`). |
| `architecture` | string | No        | Forza un driver registrado en `config/blueprintx.architectures`. |

## `options`

Configura banderas globales para la entidad.

| Clave | Tipo | Predeterminado | Descripción |
|-------|------|----------------|-------------|
| `timestamps` | bool | `true` | Añade timestamps a modelos y migraciones. |
| `softDeletes` | bool | `false` | Activa `SoftDeletes`. |
| `authorize` | bool | `true` | Inserta llamadas `authorize` en FormRequests. |
| `optimistic_locking` | objeto | `null` | Configura bloqueo optimista (`strategy`, `header`, `response_header`). |
| `includes` | array | `[]` | Recursos relacionados a incluir en respuestas API. |

## `fields`

Cada campo admite:

| Clave     | Tipo   | Obligatorio | Notas |
|-----------|--------|-------------|-------|
| `type`    | string | Sí          | Tipos soportados: `string`, `integer`, `decimal`, `boolean`, `uuid`, `date`, `datetime`, `json`. |
| `rules`   | string | Sí          | Reglas de validación de Laravel separadas por `\|`. |
| `default` | mixed  | No          | Valor por defecto. |
| `precision`, `scale` | int | No          | Requeridos cuando el tipo es `decimal`. |
| `nullable` | bool  | No          | Marca el campo como opcional en migraciones y reglas. |

## `relations`

Las relaciones se agrupan por tipo. BlueprintX soporta los siguientes bloques:

| Bloque      | Descripción |
|-------------|-------------|
| `belongsTo` | Define relaciones `belongsTo`. |
| `hasOne`    | Crea relaciones `hasOne`. |
| `hasMany`   | Crea relaciones `hasMany`. |
| `morphOne`, `morphMany`, `morphToMany` | Relaciones polimórficas. |

Cada entrada requiere `relation` (clase destino) y puede definir `foreign_key`, `local_key`, `rules` y banderas `nullable`.

## `api`

Controla los artefactos HTTP.

| Clave | Tipo | Descripción |
|-------|------|-------------|
| `resource` | string | Prefijo REST (ej. `/hr/employees`). |
| `controller` | string | Sobrescribe el nombre del controlador. |
| `policies` | objeto | Asigna métodos de política personalizados. |
| `endpoints` | object | Declara endpoints CRUD y extendidos. |
| `resources.includes` | array | Relaciones a cargar en respuestas. |

### Endpoints soportados

- `index`, `store`, `show`, `update`, `destroy` (CRUD básico).
- `patch` personalizados con `method`, `action`, `field`.
- `search` con `filters` y `order`.
- `stats` con `by` para agregaciones simples.

## `search`

Define filtros y ordenamientos reutilizados por el generador de consultas.

```yaml
search:
  filters:
    - name
    - department_id
  sort:
    - field: created_at
      direction: desc
```

## `errors`

Describe errores de dominio que BlueprintX reflejará en respuestas JSON y excepciones.

| Clave | Tipo | Descripción |
|-------|------|-------------|
| `code` | string | Identificador estable (ej. `domain.employee.inactive`). |
| `status` | int | Código HTTP asociado. |
| `message` | string | Mensaje traducible. |

## `docs`

Si la característica de documentación está habilitada, la sección `docs` permite personalizar metadatos y ejemplos en OpenAPI.

```yaml
docs:
  summary: Gestión de empleados
  description: Operaciones CRUD para empleados
  tags:
    - hr
```

> Para revisar las validaciones completas consulta el JSON Schema en `packages/blueprintx/resources/schema/blueprint.schema.json`.

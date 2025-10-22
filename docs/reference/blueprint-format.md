---
layout: default
title: Lenguaje de blueprints
nav_order: 1
parent: Referencia
---
BlueprintX utiliza archivos YAML para describir entidades, sus relaciones y las capas que deben generarse. A continuación se resume la estructura exacta validada por `resources/schema/blueprint.schema.json`.

```yaml
entity: Employee
module: hr
table: employees
architecture: hexagonal
fields:
  - name: first_name
    type: string
    rules: required|max:120
  - name: email
    type: string
    rules: required|email|unique:employees,email
relations:
  - type: belongsTo
    target: Department
    field: department_id
    rules: required|exists:departments,id
options:
  timestamps: true
  softDeletes: true
api:
  base_path: /hr/employees
  middleware: [auth:sanctum]
  endpoints:
    - type: crud
    - type: patch
      name: toggle-active
      field: active
  resources:
    includes:
      - department
docs:
  description: Operaciones CRUD para empleados
  tags: [hr]
errors:
  employee_inactive:
    message: El empleado está inactivo.
    code: domain.employee.inactive
    status: 409
metadata:
  owner: Team HR
  version: 1.0
```

## Cabecera

| Clave | Obligatorio | Descripción |
|-------|-------------|-------------|
| `entity` | Sí | Nombre en PascalCase de la entidad. |
| `module` | Sí | Directorio lógico dentro de `blueprints/` (lowercase, puede incluir submódulos con `/`). |
| `table` | Sí | Nombre de la tabla a utilizar en migraciones y modelos. |
| `architecture` | No | Sobrescribe el driver declarado en `config/blueprintx.architectures`. |

BlueprintX calcula la ruta del archivo a partir de `module` y `entity`, pero el campo `path` también puede rellenarse al generar bluprints programáticamente.

### Tenancy (`tenancy`)

Declara explícitamente cómo debe comportarse el módulo frente a contextos multi-tenant. Si se omite, BlueprintX infiere `mode: central` basándose en la convención de carpetas (`central/`, `tenant/`, `shared/`).

```yaml
tenancy:
  mode: tenant       # central | tenant | shared
  storage: both      # central | tenant | both
  connection: tenant # nombre de la conexión Laravel a usar
  routing_scope: tenant # central | tenant | both (agrupa rutas generadas)
  seed_scope: central   # central | tenant | both (seeders / fixtures)
```

| Clave | Opciones | Descripción |
|-------|----------|-------------|
| `mode` | `central`, `tenant`, `shared` | Indica el contexto principal del módulo para habilitar plantillas, scopes y políticas adecuadas. `shared` se reserva para módulos híbridos. |
| `storage` | `central`, `tenant`, `both` | Define dónde deben escribirse migraciones y modelos generados. `both` crea artefactos duplicados central/tenant cuando las plantillas lo soportan. |
| `connection` | string | Sobrescribe la conexión Laravel por defecto (`mysql`, `tenant`, etc.). Útil cuando el driver requiere conexiones dedicadas. |
| `routing_scope` | `central`, `tenant`, `both` | Controla en qué grupos de rutas se registrarán los endpoints generados. |
| `seed_scope` | `central`, `tenant`, `both` | Determina qué seeders y datos de prueba producir. |

> Consejo: usa `tenancy` sólo cuando necesites anular la convención de carpetas o generar artefactos híbridos (`both`).

## Campos (`fields`)

La sección `fields` es un arreglo de objetos con esta forma:

| Clave | Obligatorio | Descripción |
|-------|-------------|-------------|
| `name` | Sí | Nombre snake_case. |
| `type` | Sí | Uno de: `string`, `text`, `integer`, `bigInteger`, `decimal`, `float`, `boolean`, `date`, `datetime`, `json`, `uuid`. |
| `rules` | No | Reglas de validación de Laravel separadas por `\|`. |
| `default` | No | Valor por defecto aplicado en migración y modelo. |
| `precision`, `scale` | No | Requeridos cuando `type` es `decimal`. |
| `nullable` | No | Si es `true`, marca la columna como nullable y ajusta las reglas. |

> Consejo: usa `string` para columnas cortas y `text` para descripciones largas.

## Relaciones (`relations`)

`relations` es un arreglo donde cada objeto describe una relación Eloquent:

| Clave | Obligatorio | Descripción |
|-------|-------------|-------------|
| `type` | Sí | `belongsTo`, `hasOne`, `hasMany` o `belongsToMany`. |
| `target` | Sí | Entidad destino en PascalCase. |
| `field` | Sí | Columna o pivot utilizada. |
| `rules` | No | Reglas adicionales aplicadas al campo en formularios. |

BlueprintX utiliza estos datos para crear métodos en los modelos, llaves foráneas en migraciones y ayudas en recursos API.

## Opciones (`options`)

| Clave | Tipo | Predeterminado | Descripción |
|-------|------|----------------|-------------|
| `timestamps` | bool | `true` | Incluye columnas `created_at` y `updated_at`. |
| `softDeletes` | bool | `false` | Agrega `deleted_at` y el trait `SoftDeletes`. |
| `audited` | bool | `false` | Habilita ganchos para auditorías (si la arquitectura lo soporta). |
| `versioned` | bool | `false` | Indica que la entidad mantiene versiones (útil con bloqueo optimista). |

## API (`api`)

Controla la generación de controladores, rutas implícitas y `JsonResource`.

- `base_path`: prefijo REST (ej. `/crm/contacts`).
- `middleware`: listado de middleware que se inyectará en el controlador.
- `endpoints`: arreglo de definiciones con la siguiente forma:

| Propiedad | Descripción |
|-----------|-------------|
| `type` | `crud`, `patch`, `search`, `stats`, `restore`, `bulk` o `custom`. |
| `name` | Identificador del endpoint (para `patch`, `custom` o adicionales). |
| `method` | Método HTTP a usar (por defecto se infiere). |
| `path` | Segmento adicional en la ruta cuando necesitas algo distinto a lo autogenerado. |
| `field` | Campo afectado (requerido en `patch`). |
| `fields` | Lista de campos a exponer (útil en `search`). |
| `by` | Campo de agregación para `stats`. |

Los recursos pueden incluir relaciones mediante `api.resources.includes`:

- **Entrada rápida**: usa strings (`contacts`) para cargar la relación con el mismo nombre.
- **Entrada avanzada**: declara objetos con `relation`, `alias` y `resource` (FQCN opcional) cuando necesites renombrar la propiedad o apuntar a un recurso distinto.
- **Relaciones soportadas**: `belongsTo`, `hasOne`, `hasMany` y `belongsToMany`. BlueprintX infiere si debe usar un `Resource` o una `Collection` según el tipo de relación.
- **Cargas seguras**: sólo se incluirán relaciones declaradas en la sección `relations`; cualquier nombre no reconocido se ignora durante la generación.

Los controladores generados aplican `with` automáticamente y los `JsonResource` exponen la colección o recurso individual respetando alias y clases personalizadas.

## Documentación (`docs`)

Disponible cuando `features.openapi.enabled = true`:

- `description`: texto largo para OpenAPI.
- `tags`: arreglo de etiquetas.
- `examples`: bloque opcional con ejemplos `create`, `update` o `patch` que aparecerán en los request bodies.

## Errores (`errors`)

Define códigos de error de dominio que se propagarán a los controladores, pruebas y documentación.

- Usa una clave por error (`employee_inactive`).
- El valor puede ser una cadena rápida o un objeto con `message`, `code`, `status`, `extends` y `description`.
- `extends` permite reutilizar errores declarados en otros blueprints.

## Metadatos (`metadata`)

Campo libre para adjuntar información adicional que tus pipelines personalizados puedan leer (dueño, etiquetas, versión, etc.). No impacta la generación estándar.

## Validación

La combinación de los validadores de esquema y semánticos asegura que tus archivos cumplan con la estructura anterior. Ejecuta `php artisan blueprintx:validate` para obtener mensajes detallados por archivo. El JSON Schema se encuentra en `packages/blueprintx/resources/schema/blueprint.schema.json`.

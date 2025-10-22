# Plan de adopción de tenancy en BlueprintX

Estado general: **Pendiente**

> Actualiza la columna *Estado* a medida que completes los ítems. Usa commits separados por cada hito relevante y marca la fecha.

| Nº | Fase | Alcance principal | Entregables | Estado |
| --- | --- | --- | --- | --- |
| 1 | Descubrimiento | Analizar necesidades de tenancy, revisar configuración actual, definir opciones (`central`, `tenant`, `shared`) y decisiones de carpeta vs bandera | Documento de alcance, lista de supuestos y riesgos | ⧗ En progreso - 2025-10-21 |
| 2 | Esquema & Validación | Extender `blueprint.schema.json`, `openapi-minimal.schema.json`, y `DefaultBlueprintValidator` para aceptar `tenancy.mode`; definir defaults basados en convención de carpetas | Actualizaciones de esquema y validadores + pruebas unitarias | ☐ Pendiente |
| 3 | Kernel & Configuración | Propagar el modo de tenancy en `GenerationPipeline`, `Blueprint` y config `blueprintx.features.tenancy`; añadir toggles y documentación de configuración | Código kernel actualizado, pruebas unitarias, docs de configuración | ☐ Pendiente |
| 4 | Capa Dominio & Aplicación | Ajustar generadores domain/application para incluir campos tenant, scopes y dependencias; actualizar snapshots correspondientes | Nuevos templates, snapshots y pruebas verdes | ☐ Pendiente |
| 5 | Infraestructura & API | Actualizar repositorios, migraciones, controladores, recursos y rutas para respetar el contexto tenant/central; incluir middleware/validación | Código generado actualizado, pruebas y snapshots | ☐ Pendiente |
| 6 | Pruebas & Documentación | Refrescar plantilla de tests, guías de uso, ejemplos de blueprints tenancy; preparar guía de migración | Documentación final, ejemplos, suite PHPUnit verde | ☐ Pendiente |

## Reglas de trabajo

- Cada fase se implementa en commits independientes. Utiliza mensajes claros (`feat:`, `docs:`, `test:`) y referencia esta tabla.
- Antes de iniciar cada fase, crea subtareas si es necesario y actualiza el documento con fechas y responsables.
- Tras completar una fase, cambia el estado a `☑ Completado - AAAA-MM-DD` (por ejemplo) y documenta brevemente el resultado.

## Seguimiento adicional

- Mantener un `CHANGELOG` específico si se requieren breaking changes.
- Preparar tareas posteriores para soporte multi-tenant en UI o integraciones externas.

## Fase 1 · Descubrimiento (2025-10-21)

### Objetivos

- Definir el alcance exacto del soporte tenancy (central, tenant, shared) dentro de BlueprintX.
- Identificar puntos de integración necesarios en blueprint, validadores, pipeline y generadores.
- Enumerar riesgos y supuestos antes de modificar el código.

### Alcance actual

- Blueprint YAML no declara tenancy; se infiere solamente por estructura de carpetas.
- `blueprint.schema.json` y `DefaultBlueprintValidator` no contemplan claves de tenancy.
- Los generadores (dominio, aplicación, infraestructura, API, tests) no aplican scopes ni columnas específicas de tenant.
- No existe configuración en `config/blueprintx.php` relacionada a tenancy ni feature flags.

### Supuestos iniciales

- Los proyectos que adopten tenancy usarán Laravel Sanctum u otra capa de autenticación ya existente.
- La tabla `tenants` (o equivalente) existe en las implementaciones y provee una llave foránea estándar (`tenant_id`).
- El modo tenancy debe ser configurable por blueprint individual, permitiendo combinaciones central/tenant/shared dentro del mismo módulo.
- Se mantendrá compatibilidad retroactiva: los blueprints existentes sin flag explícito continuarán funcionando como `central`.
- BlueprintX ofrecerá integración de referencia con `stancl/tenancy` (detectada automáticamente o habilitada vía configuración) y permitirá extender a otras librerías mediante hooks propios del proyecto.

### Riesgos identificados

- **Compatibilidad de plantillas**: snapshots existentes deberán actualizarse; riesgo de romper proyectos si el flag inicia sin defaults claros.
- **Sobrecarga de configuración**: si la bandera es obligatoria, se incrementa el trabajo para usuarios; se mitigará con defaults y convención de directorios.
- **Consistencia entre capas**: si una capa omite aplicar el scope tenant, se generan fugas de datos; requerirá pruebas cruzadas.
- **OpenAPI/Postman**: la inclusión de headers o parámetros de tenant puede alterar especificaciones ya generadas.

### Integración objetivo con `stancl/tenancy`

- **Detección**: al ejecutar `blueprintx:generate`, comprobar la presencia de `stancl/tenancy` en `composer.json`. Si está instalado, habilitar helpers específicos (headers, middleware, traits).
- **Configuración**: exponer una opción `features.tenancy` en `config/blueprintx.php` con los campos `driver` (`stancl`, `custom`), `auto_detect` y `middleware_alias`.
- **Stubs**: preparar plantillas opcionales para `TenantAware` traits, middleware de inyección de tenant y tests de integración. Se incluirán solo cuando el blueprint marque `tenancy.mode = 'tenant'`.
- **Puntos de extensión**: documentar hooks para que proyectos puedan registrar drivers custom (por ejemplo `spatie/laravel-multitenancy`) reutilizando la misma API.

#### Flujo de detección y activación

1. El `DriverManager` revisa `composer.lock` y `config/blueprintx.features.tenancy.auto_detect`.
   - Si `auto_detect = true` y `stancl/tenancy` está presente, activa el driver `stancl` implícitamente.
   - Si `auto_detect = false`, respeta el valor de `features.tenancy.driver`.
2. El `Blueprint` expone `getTenancyMode()` tomando la prioridad: flag explícito en YAML → convención de carpeta → `central` por defecto.
3. `GenerationPipeline` inyecta el `TenancyContext` a cada generador. Este contexto incluye `mode`, `driver`, `shouldGenerateTenantArtifacts` y `middleware_alias`.
4. Los generadores deciden si aplicar scopes, columnas o middleware según el `TenancyContext` sin duplicar lógica por capa.

#### Cambios propuestos en configuración

```php
'features' => [
    'tenancy' => [
        'driver' => env('BLUEPRINTX_TENANCY_DRIVER', 'none'), // none, stancl, custom
        'auto_detect' => env('BLUEPRINTX_TENANCY_AUTO_DETECT', true),
        'middleware_alias' => env('BLUEPRINTX_TENANCY_MIDDLEWARE', 'tenancy'),
        'namespace_overrides' => [
            'middleware' => null, // permite redefinir el namespace usado en los stubs
            'traits' => null,
        ],
    ],
],
```

- Se documentará que `driver = 'none'` ignora el paquete aunque esté instalado.
- Los proyectos que requieran otro driver pueden establecer `driver = 'custom'` y registrar hooks en el service provider del proyecto.

#### Stubs y plantillas condicionadas

- `DomainLayerGenerator`: añade el trait `TenantAwareEntity` y la columna `tenant_id` cuando `mode = tenant`.
- `ApplicationLayerGenerator`: incorpora un helper `resolveTenant()` y actualiza comandos/queries para recibir el tenant actual.
- `InfrastructureLayerGenerator`: genera repositorios con scope global `byTenant` y migraciones con llave foránea `tenant_id`.
- `ApiLayerGenerator`: agrega middleware `{middleware_alias}` y headers sugeridos (`X-Tenant`) para endpoints tenant-aware.
- `TestsLayerGenerator`: introduce escenarios duplicados `central` vs `tenant` y helpers para sembrar tenants de prueba.
- Los stubs vivirán bajo `resources/templates/hexagonal/tenancy/` y solo se copian cuando `TenancyContext::shouldGenerateTenantArtifacts()` sea verdadero.

#### Hooks para drivers personalizados

- Exponer una interfaz `Contracts\TenancyDriver` con métodos `register(Container $app)`, `provideMiddlewareAlias()` y `augmentBlueprint(Blueprint $blueprint)`.
- Permitir registrar drivers adicionales en `config/blueprintx.php['features']['tenancy']['drivers']` (mapa alias ⇒ clase).
- Documentar ejemplos de integración con `spatie/laravel-multitenancy` mostrando cómo reemplazar middleware y factories de tenant.
- Añadir evento `TenancyDriverDetected` para que el proyecto pueda reaccionar (por ejemplo, cargando bindings adicionales o validaciones).

### Próximos pasos

1. ☑ Completado - 2025-10-21: Documentar decisiones de convención vs bandera en la guía (`docs/guides/workflow.md#6-convenciones-de-tenancy`).
2. ☑ Completado - 2025-10-21: Detallar el mecanismo de integración base con `stancl/tenancy` (secciones de detección, configuración, stubs y hooks).
3. ☑ Completado - 2025-10-21: Preparar historias de usuario para cada capa (`Historias de usuario por capa`).
4. ☑ Completado - 2025-10-21: Revisar impacto en comandos `blueprintx:generate` y `blueprintx:rollback` (`Impacto esperado en comandos`).
5. ⧗ Pendiente: Entrevistar a usuarios actuales (1-2 proyectos) para validar requerimientos de tenancy y priorizar entregables.

#### Plan de entrevistas

| Proyecto / equipo | Contexto tenancy | Objetivo de la sesión | Responsable | Estado |
| --- | --- | --- | --- | --- |
| Proyecto A (Core Banking) | Usa `stancl/tenancy` con múltiples bases de datos | Validar necesidades de migraciones tenant-aware y políticas de rollback | @infra-lead | ⧗ Pendiente (coordinar agenda semana 43) |
| Proyecto B (SaaS RH) | Convivencia central + tenant en misma base | Alinear expectativas de pruebas y duplicación de snapshots | @qa-lead | ⧗ Pendiente (enviar invitación 2025-10-22) |

- Preparar guion con temas: configuración actual, pain points, expectativas de generación de código, pruebas y documentación.
- Registrar resultados y decisiones en sección nueva “Hallazgos de entrevistas” dentro de este documento.
- Ajustar roadmap de fases posteriores según aprendizajes.

### Historias de usuario preliminares


#### Historias de usuario por capa

##### Dominio

###### Historia (Dominio)

Como desarrollador, quiero que las entidades y agregados creados en modo `tenant` incluyan la columna `tenant_id`, scopes `forTenant()` y validaciones de unicidad filtradas por tenant.

###### Criterios de aceptación (Dominio)

| Criterio | Resultado esperado |
| --- | --- |
| Columnas tenant-aware | Las plantillas de entidad generan `tenant_id` como `Uuid` o `foreignUuid` según configuración del proyecto. |
| Scope reutilizable | Se incluye un scope global o trait `TenantAwareEntity` que aplica automáticamente `tenant_id` en las consultas. |
| Validaciones específicas | Las pruebas generadas para entidades incluyen un caso de validación que falla cuando falta `tenant_id`. |

##### Aplicación

###### Historia (Aplicación)

Como desarrollador de aplicación, necesito que comandos, queries y handlers reciban el tenant activo desde el contexto para evitar pasar IDs manualmente.

###### Criterios de aceptación (Aplicación)

| Criterio | Resultado esperado |
| --- | --- |
| Entrada obligatoria | Los comandos generados aceptan un `TenantContext` o valor `tenantId` obligatorio cuando el blueprint está en modo `tenant`. |
| Resolución centralizada | Los handlers incluyen un helper `resolveTenant()` que utiliza el driver configurado (`stancl` por defecto). |
| Filtros automáticos | Los filtros `QueryFilter` aplican el tenant actual sin que el desarrollador modifique cada módulo. |

##### Infraestructura

###### Historia (Infraestructura)

Como desarrollador de infraestructura, quiero que repositorios, migraciones y factories sean conscientes del tenant para no filtrar datos manualmente.

###### Criterios de aceptación (Infraestructura)

| Criterio | Resultado esperado |
| --- | --- |
| Migraciones | Las migraciones generadas crean llaves foráneas `tenant_id` y añaden índices compuestos con las columnas críticas. |
| Repositorios | Los repositorios incluyen un método `scopeTenant()` reutilizable y lo aplican en operaciones estándar (`find`, `paginate`). |
| Factories | Las factories de prueba aceptan un `tenant_id` opcional y crean uno nuevo cuando no se proporcione. |

##### API

###### Historia (API)

Como desarrollador de API, necesito que controladores y recursos respeten el contexto tenant y expongan los headers esperados.

###### Criterios de aceptación (API)

| Criterio | Resultado esperado |
| --- | --- |
| Middleware | Los controladores tenant-aware usan el middleware configurado (`tenancy` por defecto) y validan el header `X-Tenant`. |
| Validación | Los Form Requests añaden reglas `exists:tenants,id` para el campo `tenant_id` cuando corresponda. |
| Respuesta | Los recursos JSON incluyen `tenant_id` solo en respuestas internas y lo omiten en modos `central`. |

##### Tests

###### Historia (Tests)

Como QA, quiero que las pruebas generadas cubran ambos contextos para prevenir regresiones entre modos `central` y `tenant`.

###### Criterios de aceptación (Tests)

| Criterio | Resultado esperado |
| --- | --- |
| Escenarios | Los feature tests generan escenarios duplicados (`withoutTenancy`, `withTenancy`). |
| Helpers | Se incluyen helpers para crear tenants en `setUp()` y limpiar aislamiento entre pruebas. |
| Snapshots | Las snapshots diferencian los sufijos `Central` y `Tenant` para facilitar el mantenimiento. |

#### Impacto esperado en comandos

- `blueprintx:generate`
  - Detectará el modo tenancy por blueprint y registrará `tenancy_mode` en `.blueprintx/history.json` para auditorías.
  - Guardará en el historial los artefactos tenant-aware generados (middleware, traits, tests) para que `rollback` los revierta con exactitud.
  - Añadirá la bandera `--tenancy=auto|central|tenant|shared` para forzar un modo puntual; validará contra `features.tenancy.driver` y mostrará un warning si el driver global es `none`.
  - Emitirá un aviso cuando se detecte `stancl/tenancy` pero `auto_detect=false` para que el usuario revise la configuración antes de continuar.
- `blueprintx:rollback`
  - Filtrará las entradas por `tenancy_mode` al preparar la reversión, evitando eliminar archivos de otro contexto.
  - Borrará únicamente los artefactos asociados al driver activo (`stancl` por defecto) para proteger personalizaciones centrales.
  - Presentará un resumen previo con los archivos tenant-aware a eliminar y pedirá confirmación si hay migraciones compartidas.
- Historial y telemetría
  - Sumará `tenant_driver` al historial para medir adopción de `stancl` frente a drivers personalizados.
  - Recomendaremos versionar `.blueprintx/history.json` para asegurar rollbacks coherentes entre entornos.

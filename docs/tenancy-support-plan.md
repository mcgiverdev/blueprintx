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

### Próximos pasos

1. Documentar decisiones de convención vs bandera y comunicarlas en la guía (pendiente).
2. Detallar el mecanismo de integración base con `stancl/tenancy` (detección, stubs y configuración) y posibles puntos de extensión para otras librerías.
3. Preparar historias de usuario para cada capa (dominio, aplicación, infraestructura, API, tests) antes de la Fase 2.
4. Revisar impacto en comandos `blueprintx:generate` y `blueprintx:rollback` respecto al historial.

### Historias de usuario preliminares

- **Como desarrollador de dominio**, quiero que las entidades generadas incluyan automáticamente el campo `tenant_id` y scopes globales cuando el blueprint sea multi-tenant para evitar olvidos.
- **Como desarrollador de aplicación**, necesito que los comandos/queries reciban el tenant actual o lo resuelvan vía contexto para mantener la lógica de negocio aislada.
- **Como desarrollador de infraestructura**, deseo que los repositorios aplican filtros tenant-aware y generen migraciones con llaves foráneas a `tenants`.
- **Como desarrollador de API**, quiero que los controladores y recursos verifiquen el tenant y respondan con datos aislados, incluyendo headers requeridos por `stancl/tenancy`.
- **Como QA**, necesito pruebas y snapshots que cubran escenarios central y tenant para garantizar que los cambios no mezclan datos.

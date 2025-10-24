# Plan de correcciones para compatibilidad tenancy

## Bloqueos críticos

- [x] Alias correctos en `app/Providers/AppServiceProvider.php`: la generación ahora asigna aliases únicos (Central/Tenant/Shared) para interfaces/repositorios duplicados y actualiza los `bind()` sin romper el instalador tenancy.
- Ajustar imports en `routes/api.php`: usar alias únicos (ej. `CentralUserController`, `TenantUserController`) y aplicar middlewares correctos según contexto.
- Generar configuración de guardias tenancy en `config/auth.php`: definir guard `tenant`, proveedor dedicado y broker de contraseñas cuando existan blueprints con `tenancy.mode = tenant`.
- Revisar flujo de instalación `stancl/tenancy`: evitar re-ejecución de `php artisan migrate:fresh` por defecto o solicitar confirmación; capturar errores antes de abortar el comando principal.

## Ajustes en generadores API y rutas

- `packages/blueprintx/src/Generators/ApiLayerGenerator.php`: inyectar automáticamente middleware `tenancy` solo para endpoints `tenancy.mode = tenant`; no aplicarlo a contextos centrales. Añadir soporte para cabecera `X-Tenant` al construir controladores (helper para leer encabezado y pasarlo a capa Application cuando corresponda).
- `packages/blueprintx/src/Generators/ApiLayerGenerator.php`: al generar `routes/api.php`, agrupar rutas por contexto con `Route::prefix()` y alias únicos para evitar colisiones; respetar `api.middleware` definido en el blueprint.
- Asegurar que las rutas centrales (`mode: central`) usen `auth:sanctum` + habilidades configuradas, pero sin obligar cabecera tenant.

## Mejoras en modelos, repositorios y filtros

- `packages/blueprintx/src/Generators/DomainLayerGenerator.php`: aplicar trait `App\Domain\Shared\Concerns\TenantAwareEntity` a entidades con campo `tenant_id`; crear trait únicamente una vez y garantizar su `use` en el modelo generado.
- Generar relaciones con namespaces correctos: cuando el blueprint apunta a entidades de otro contexto, resolver el namespace absoluto (p.ej. `App\Domain\Shared\Hr\Models\JobPosition`).
- `packages/blueprintx/src/Generators/InfrastructureLayerGenerator.php`: inyectar scoping por tenant (ej. `$builder->where('tenant_id', $tenantId)`) aprovechando un helper que lea el header o el contexto provisto por Stancl.
- `packages/blueprintx/src/Generators/ApplicationLayerGenerator.php`: propagar `tenantId` en comandos/queries si la entidad es tenant-aware.

## Configuración y plantillas de blueprint

- Actualizar plantillas YAML en `blueprints/central/*` para que el middleware `tenant` solo se agregue cuando `tenancy.mode = tenant`; central debe usar `auth:sanctum` y roles apropiados.
- Añadir validadores que impidan duplicar middleware contradictorios (ej. `tenancy` en contextos centrales).
- Revisar plantilla `resources/vendor/blueprintx/templates/domain/model.twig` para incluir `use TenantAwareEntity;` cuando corresponda.

## Provisionamiento y automatizaciones

- Generar esqueleto de servicio `TenantProvisioner` (Application + Infra) cuando exista blueprint `central/tenancy/tenants.yaml`, incluyendo eventos y jobs listados en `tenancy-flow.md`.
- Incluir comandos artisan para ejecutar provisioning y sincronización de catálogos shared.
- Documentar pasos automáticos en README tenant (nuevo doc en `docs/tenancy/README.md`).

## Documentación y colecciones

- Asegurar que Postman/OpenAPI agreguen ejemplos de login central y tenant (tokens reutilizados en variables); añadir test scripts para guardar bearer token y tenant slug.
- Validar que OpenAPI marque la cabecera `X-Tenant` como obligatoria solo en rutas `mode: tenant`.

## QA y pruebas automáticas

- Añadir feature tests: creación de tenant, provisioning simulado, login guard `tenant`, CRUD de empleados scoped por tenant.
- `composer test`: incluir suites separadas central vs tenant y mocks para Stancl Tenancy.
- Documentar en `packages/blueprintx/docs/tenancy-flow.md` los nuevos comandos y cualquier cambio en el proceso.

# Flujo propuesto para compatibilidad tenancy

Este documento describe el flujo completo que debe cubrir la plataforma BlueprintX cuando se desea habilitar generación multi-inquilino (tenancy) y mantener compatibilidad con escenarios puramente centrales. El objetivo es asegurar que todos los blueprints, comandos y recursos (Postman incluido) funcionen de extremo a extremo.

## 1. Contextos involucrados

1. **Central**
   - Ejecuta el panel administrativo en el dominio raíz (`generadorbackend.test`).
   - Gestiona compañías (`central/hr/company.yaml`), tenants (`central/tenancy/tenants.yaml`) y usuarios operativos (`central/auth/user.yaml`).
   - Registra la información necesaria para provisionar cada tenant: dominio, base de datos, plan, estado y responsable (`owner`).

2. **Shared**
   - Define catálogos y recursos compartidos entre central y tenants (por ejemplo `shared/hr/job_position.yaml`).
   - Se persisten tanto en base central como en las bases tenant (sincronizadas vía jobs/eventos tras la creación de cada tenant).

3. **Tenant**
   - Cada inquilino corre bajo un subdominio (`{slug}.generadorbackend.test`).
   - Se ejecuta con su propia conexión, base de datos y guardias (`tenant`).
   - Gestiona usuarios finales (`tenant/auth/user.yaml`) y recursos de negocio (por ejemplo `tenant/hr/employee.yaml`).

## 2. Flujo general

1. **Stage 0: Preparación de blueprints**
   - Validar todos los YAML (`php artisan blueprintx:validate docs/examples`).
   - Confirmar que `tenancy.mode` y las reglas de acceso están correctas por contexto (central, shared, tenant).

2. **Stage 1: Generación (central)**
   - Ejecutar `php artisan blueprintx:generate --path=docs/examples/central --with-postman --with-openapi`.
   - Aplicar migraciones en la base central (usuarios, compañías, tenants, catálogos compartidos iniciales).
   - Seed inicial: super admin central, planes válidos, catálogos compartidos base.

3. **Stage 2: Provisionamiento de tenant**
   - Desde el plano central, un usuario con rol `super-admin` crea un tenant (`POST /tenancy/tenants`).
   - Validaciones:
     - Dominio: debe coincidirm con `{slug}.generadorbackend.test`.
     - `default_database` único.
     - Estado inicial `pending` → transición a `provisioning` durante el proceso.
   - El backend debe lanzar el workflow de provisionamiento: crea base de datos, migraciones tenant, seeds, sincroniza catálogos shared (`job_positions`), registra dominios en Stancl, genera usuario admin inicial del tenant (guardado en central).
   - Al finalizar, marcar el tenant como `active` y registrar `onboarded_at`.

4. **Stage 3: Autenticación tenant**
   - El nuevo admin de tenant recibe credenciales y autentica mediante `POST /auth/tenant/login` (colección Postman generada).
   - Se emite token con guard `tenant` y habilidad `tenant.manage-*` para operar dentro del tenant.

5. **Stage 4: Operaciones tenant**
   - Con el token y pasando el header `X-Tenant` (o resolviendo por dominio), se ejecutan los endpoints generados:
     - `POST /hr/people/employees`
     - `GET /hr/people/employees?filter[status]=active`
     - Acciones masivas (`POST /hr/people/employees/bulk`)
   - Las rutas usan middleware `tenancy` + `auth:tenant` + abilities específicas.

6. **Stage 5: Recursos compartidos**
   - A partir del seeding inicial, cada tenant puede leer catálogos compartidos (`/hr/catalog/job-positions`).
   - Cuando los administradores centrales actualicen catálogos, se debe emitir un job/evento que re-sincronice los tenants activos.

7. **Stage 6: Gestión continua**
   - Central puede suspender (`PATCH /tenancy/tenants/{id}` cambiando `status`) o eliminar (soft delete) inquilinos.
   - El comando `blueprintx:rollback` tiene historial ordenado (gracias al timestamp + sequence) para revertir generados recientes.

## 3. Postman y documentación

1. Generar colecciones con `--with-postman` en ambos contextos (central y tenant). La colección central debe incluir pasos:
   - Autenticación super admin (`POST /auth/login`).
   - Crear tenant.
   - Consultar estado y catálogos compartidos.

2. La colección de tenant debe cubrir:
   - Inicio de sesión con guard `tenant`.
   - Tests CRUD de usuarios tenant.
   - Operaciones de empleados (`tenant/hr/employee.yaml`).

3. Variables de entorno relevantes:
   - `central_base_url = https://generadorbackend.test`
   - `tenant_base_url = https://demo.generadorbackend.test`
   - `central_token`, `tenant_token`, `tenant_slug`, `tenant_domain`, `tenant_db_name`.

4. Documentar en OpenAPI (`--with-openapi` + `--validate-openapi`) para ambos contextos.

## 4. Testing

1. Ejecutar `composer test` en el paquete para garantizar que todos los comandos funcionan (incluye rollback y pipeline).
2. Añadir escenarios E2E durante la provisión del tenant:
   - Feature test: crear tenant central → simular provisioning → login tenant → CRUD employees → assert DB tenant.
   - Feature test: validar que el dominio inválido o duplicado retorna error.

## 5. Plan de tareas (resumen operativo)

1. **Validación y generación**
   - Run `blueprintx:validate` y luego `blueprintx:generate` por contexto.
   - Comprobar migraciones en central y tenant DBs.

2. **Provisionamiento**
   - Implementar job/servicio de `TenantProvisioner` que consume los campos `domain`, `default_database`, `plan` y crea la infraestructura.
   - Registrar dominios en Stancl Tenancy (`domains` table) y seeds iniciales.

3. **Autenticación**
   - Asegurar guards `central` vs `tenant` configurados.
   - Generar tokens (Sanctum) y exponer endpoints de login/logout.

4. **Postman / Documentación**
   - Revisar colecciones generadas, agregar ejemplos manuales si falta login inicial.
   - Documentar flujos en README/tenancy.md (o reusar este documento).

5. **QA**
   - Ejecutar tests automatizados.
   - Probar colecciones Postman siguiendo el flujo central → tenant.
   - Confirmar que `blueprintx:rollback` pueda revertir generados si algo falla.

---

Este flujo asegura que la aplicación soporte entornos centrales tradicionales y, adicionalmente, genere todos los artefactos necesarios para trabajar con tenants: dominios, bases, usuarios, recursos de negocio y catálogos compartidos.

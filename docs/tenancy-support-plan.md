# Plan de adopción de tenancy en BlueprintX

Estado general: **Pendiente**

> Actualiza la columna *Estado* a medida que completes los ítems. Usa commits separados por cada hito relevante y marca la fecha.

| Nº | Fase | Alcance principal | Entregables | Estado |
| --- | --- | --- | --- | --- |
| 1 | Descubrimiento | Analizar necesidades de tenancy, revisar configuración actual, definir opciones (`central`, `tenant`, `shared`) y decisiones de carpeta vs bandera | Documento de alcance, lista de supuestos y riesgos | ☐ Pendiente |
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

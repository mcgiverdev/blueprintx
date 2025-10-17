# Plan de iteraciones BlueprintX UI

Este plan coordina el desarrollo incremental del paquete unificado (`packages/blueprintx-ui`) con sus módulos backend (`packages/blueprintx-ui/backend`) y frontend (`packages/blueprintx-ui/frontend`). Ambas capas evolucionan en paralelo para ofrecer entregables utilizables en cada iteración.

## Alcance por librería

### Backend UI (`packages/blueprintx-ui/backend`)

- Exponer endpoints REST enfocados en la exploración de blueprints generados.
- Persistir catálogos y preferencias ligeras en SQLite con migraciones empaquetadas.
- Publicar configuración y rutas reutilizables para proyectos Laravel 11/12.
- Facilitar pruebas unitarias aisladas mediante repositorios contractuales.

### Frontend UI (`packages/blueprintx-ui/frontend`)

- Entregar un dashboard operativo para navegar módulos y entidades.
- Consumir servicios REST del backend especializado con manejo de sesión Sanctum.
- Ofrecer componentes reutilizables para listas, formularios y vistas de detalle.
- Incluir pruebas unitarias y de contrato mínimas para validar los flujos críticos.

## Iteraciones planificadas

### Iteración 0 · Andamiaje (1 semana)

- Backend: exponer `/projects` (GET/POST) contra SQLite, registrar ServiceProvider y publicar config.
- Frontend: bootstrap con Vite + Vue + Pinia, layout base y navegación en blanco.
- Entregable: demo navegable que lista proyectos estáticos (`mock`) conectada al endpoint real.

### Iteración 1 · Dashboard navegable (1 semana)

- Backend: enriquecer respuesta de proyectos (metadatos, conteos), sincronizar desde `blueprints/` y probar repositorio SQLite.
- Frontend: implementar `BlueprintGrid`, store Pinia con carga real, botón de sincronización y vista de detalle con metadatos.
- QA: pruebas contractuales ligeras (Pest/Vitest) y script de seed para datos demo.
- Entregable: dashboard capaz de listar y abrir proyectos con datos reales persistidos.

### Iteración 2 · Gestión avanzada (2 semanas)

- Backend: endpoints para actualizar/eliminar proyectos, logging de auditoría y filtros.
- Frontend: formularios reactivos para alta/edición, filtrado client-side y feedback de estado.
- QA: flujos end-to-end con Playwright/Cypress contra sqlite en memoria.
- Entregable: CRUD completo con validación y sincronización en caliente del listado.

### Iteración 3 · Observabilidad y empaquetado (1-2 semanas)

- Backend: eventos de dominio para sincronizar datasets y broadcasting opcional.
- Frontend: panel de métricas, manejo de errores global y documentación de componentes.
- DevOps: pipelines de publicación (`packagist`/`npm`), versionado semántico y changelog.
- Entregable: release candidata certificada con documentación lista para adopción interna.

## Reglas de coordinación

- Las iteraciones cortas (1 semana) priorizan entregables visibles; cualquier alcance adicional se pasa a la siguiente.
- Toda API nueva en el backend se documenta con OpenAPI y se sincroniza con contratos de pruebas en el frontend.
- Se mantiene un backlog conjunto en `docs/backlog.md` con referencias cruzadas entre tareas de ambos paquetes.

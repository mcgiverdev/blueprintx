---
layout: default
title: BlueprintX
nav_order: 1
---

BlueprintX es un generador de código para proyectos Laravel que parte de archivos YAML para producir capas de dominio, aplicación, infraestructura, API y documentación. El enfoque prioriza la arquitectura hexagonal, la consistencia de APIs REST y la validación anticipada de reglas.

## Características clave

- **Entradas declarativas**: describe entidades, relaciones, endpoints, búsquedas y errores de dominio en un YAML legible.
- **Capas coordinadas**: genera modelos, servicios de aplicación, controladores HTTP, recursos API, pruebas y documentación OpenAPI desde una única fuente.
- **Validación rigurosa**: ejecuta validaciones sintácticas y semánticas antes de escribir archivos para reducir errores en tiempo de generación.
- **Personalización flexible**: publica la configuración y las plantillas Twig para ajustar rutas de salida, namespaces, traits de controladores y bloqueo optimista.
- **Integración Laravel nativa**: registra comandos Artisan (`blueprintx:list`, `validate`, `generate`), servicio singleton para parseo YAML y un pipeline de generación extensible.

## Cómo usar esta documentación

1. Lee la guía de [inicio rápido](getting-started.html) para instalar y configurar BlueprintX en tu proyecto.
2. Consulta la [referencia del lenguaje de blueprints](reference/blueprint-format.html) para conocer cada sección YAML disponible.
3. Explora la [referencia de comandos](reference/cli.html) para automatizar flujos de generación y validación.
4. Ajusta la [configuración](reference/configuration.html) para adaptar BlueprintX a tus convenciones.
5. Revisa las [guías prácticas](guides/workflow.html) con recomendaciones sobre validaciones, generación y pruebas.

## Capas generadas

BlueprintX utiliza el `GenerationPipeline` para ejecutar los generadores registrados en `config('blueprintx.generators')`.

- **Dominio**: modelos Eloquent, factories y contratos alineados con la arquitectura hexagonal.
- **Aplicación**: servicios y casos de uso organizados por módulo.
- **Infraestructura**: repositorios, mapeadores y servicios auxiliares.
- **API**: controladores, Form Requests, recursos JSON y manejo de errores con traits incluidos.
- **Base de datos**: migraciones y seeders opcionales.
- **Pruebas**: escenarios de validación API, incluyendo bloqueo optimista cuando corresponde.
- **Documentación**: documento OpenAPI (si `features.openapi.enabled` está activo) con ejemplos derivados del blueprint.

## Recursos

- Repositorio: [github.com/mcgiverdev/blueprintx](https://github.com/mcgiverdev/blueprintx)
- Publicación en Packagist: [`mcgiverdev/blueprintx`](https://packagist.org/packages/mcgiverdev/blueprintx)
- Licencia: MIT

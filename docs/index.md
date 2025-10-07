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
- **Personalización flexible**: permite forzar arquitecturas, limitar capas generadas o integrar plantillas Twig propias.
- **Integración Laravel nativa**: se instala como paquete Composer, expone comandos Artisan y publica configuración opcional.

## Cómo usar esta documentación

1. Lee la guía de [inicio rápido](getting-started.html) para instalar y configurar BlueprintX en tu proyecto.
2. Consulta la [referencia del lenguaje de blueprints](reference/blueprint-format.html) para conocer cada sección YAML disponible.
3. Explora la [referencia de comandos](reference/cli.html) para automatizar flujos de generación y validación.
4. Revisa las [guías prácticas](guides/workflow.html) con recomendaciones sobre validaciones, generación y pruebas.

## Recursos

- Repositorio: [github.com/mcgiverdev/blueprintx](https://github.com/mcgiverdev/blueprintx)
- Publicación en Packagist: [`mcgiverdev/blueprintx`](https://packagist.org/packages/mcgiverdev/blueprintx)
- Licencia: MIT

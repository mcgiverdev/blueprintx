---
layout: default
title: Flujo de trabajo recomendado
nav_order: 1
parent: Guías
---

## 1. Modelado con briefs

1. Recopila requisitos funcionales y de dominio.
2. Usa una plantilla con contexto del módulo, entidades, reglas de validación, relaciones y API.
3. Convierte el brief en YAML siguiendo la [referencia del lenguaje](../reference/blueprint-format.html).
4. Aprovecha `metadata` para documentar dueños, nivel de criticidad o versión de negocio.

## 2. Validación continua

- Ejecuta `php artisan blueprintx:validate` como parte de los commits o pipelines.
- Trata los warnings como deuda técnica; suelen señalar reglas incompletas o relaciones ambiguas.
- Integra la validación en CI para evitar merges con blueprints inconsistentes.
- Usa `php artisan blueprintx:list --json` para alimentar dashboards o pipelines que necesiten conocer el inventario vigente.

## 3. Generación segura

- En entornos locales prueba primero con `--dry-run` para revisar qué archivos se verán afectados.
- Usa `--only=` cuando quieras regenerar únicamente determinadas capas (por ejemplo, `--only=api,docs`).
- Habilita `--with-openapi` en la generación para mantener la documentación sincronizada.
- Después de generar, ejecuta la suite de pruebas para garantizar que el código resultante respeta las expectativas.
- Cuando ajustes plantillas publicadas, incluye tests de snapshot en `packages/blueprintx/tests` para cubrir las nuevas variantes.

## 4. Gestión de versiones

- Etiqueta los blueprints junto con su código generado; esto facilita revertir o auditar cambios.
- Documenta cualquier modificación manual sobre archivos generados para decidir si conviene ajustarlos en la plantilla.
- Mantén un `CHANGELOG.md` del paquete detallando mejoras, breaking changes y scripts de migración necesarios.
- Versiona también el contenido de `config/blueprintx.php` y `resources/vendor/blueprintx/templates` para detectar desviaciones entre entornos.

## 5. Buenas prácticas adicionales

- Centraliza constantes (códigos de error, textos) en el blueprint para evitar duplicación en el código.
- Define métricas o verificaciones posteriores (por ejemplo, smoke tests de endpoints) para detectar divergencias tempranas.
- Cuando agregues nuevas características al generador, incluye casos de prueba y snapshots en `packages/blueprintx/tests`.
- Revisa periódicamente la [referencia de configuración](../reference/configuration.html) para sincronizar flags como `optimistic_locking` o namespaces personalizados con tu arquitectura.

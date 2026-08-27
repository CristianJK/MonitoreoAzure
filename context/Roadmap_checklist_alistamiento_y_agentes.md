---
id: roadmap-checklist-alistamiento-agentes
tags:
  - work
  - IA
  - Azure
  - roadmap
status: continuación de "Seguimiento Azure Devops.md" y "Despliegue y seguimiento pasos a produccion.md"
audience: humano + IA (agente de desarrollo / Copilot Studio)
---

# Roadmap de Continuación: Checklist de Alistamiento por Work Item + Agentes IA

> Este documento continúa `Seguimiento Azure Devops.md` (arquitectura/estado actual) y
> `Despliegue y seguimiento pasos a produccion.md` (guía inicial de agentes). Su propósito
> es ser leído tanto por una persona como por un agente de IA (Copilot Studio, Ollama/OpenCode,
> o cualquier LLM que orqueste el flujo), por lo que cada sección incluye una definición
> estructurada (JSON) además de la explicación en prosa.

---

## FASE 6.5: Configuración de la Base de Datos

*La aplicación hoy solo consulta Azure DevOps (sin persistencia propia). Todo lo de las Fases
7 en adelante (checklist, fechas, aprobaciones) necesita almacenamiento propio, porque Azure
no expone estos campos.*

### Paso 10.1: Elegir motor — Postgres, no SQLite

Aunque el volumen de datos es bajo, **Postgres** es la opción recomendada sobre SQLite por
concurrencia: van a escribir en la misma tabla al menos tres actores en paralelo (webhook de
Azure, agente IA, humano desde el dashboard), y SQLite serializa los writes a nivel de archivo
completo. Postgres también da soporte nativo a `jsonb` (usado en `evidence`) y a la extensión
`pgvector` si más adelante el agente necesita hacer RAG sobre documentos. El esfuerzo de setup
con Laravel es el mismo que con SQLite (una variable en `.env`).

### Paso 10.2: Esquema inicial

```sql
-- lo que la app agrega sobre cada Work Item de Azure (Azure sigue siendo la fuente de verdad
-- para título, descripción, estado, etc. — aquí solo va lo que Azure no tiene)
work_item_metadata (
  id, azure_work_item_id unique, work_item_type,
  estimated_delivery_date, possible_pap_date,
  ready_to_deploy boolean default false,
  created_at, updated_at
)

-- catálogo de ítems de checklist posibles (por tipo de work item si aplica)
checklist_definitions (
  id, code, label, blocking boolean, applies_to_type nullable
)

-- estado real de cada ítem por work item
checklist_status (
  id, work_item_metadata_id, checklist_definition_id,
  status,          -- pending | in_progress | done | failed | skipped
  checked_by,      -- 'system' | 'agent:code-reviewer' | user_id
  checked_at, evidence jsonb
)

-- aprobaciones pendientes solicitadas por agentes (human-in-the-loop)
agent_approvals (
  id, work_item_metadata_id, action_code, status default 'Pendiente',
  requested_by, resolved_by nullable, created_at, resolved_at
)

-- solo metadata de documentos; el archivo binario queda en disco del servidor
documents (
  id, work_item_metadata_id nullable, document_type_id,
  storage_path, mime_type, size_bytes, checksum, created_by, created_at
)
document_types (id, code, label)
```

Índice obligatorio desde la primera migración: `checklist_status(work_item_metadata_id,
checklist_definition_id)`, porque esta tabla va a ser la más consultada del flujo (se lee cada
vez que se calcula `ready_to_deploy`).

### Paso 10.3: Migraciones Laravel

1. `php artisan make:migration create_work_item_metadata_table` y así para cada tabla de
   arriba (6 migraciones en total).
2. Modelos Eloquent estándar, con la relación `WorkItemMetadata hasMany ChecklistStatus`.
3. Seeder para `checklist_definitions` con el catálogo de la Fase 7 (Paso 11), para no
   hardcodear los ítems en el código.

---

## FASE 7: Modelo de Datos del Checklist de Alistamiento

*Esto es el núcleo de lo que falta: cada Work Item necesita una checklist propia, con estado
por ítem, no solo un flag general de "listo/no listo".*

### Paso 11: Definir el catálogo de ítems de checklist

A partir de lo ya mencionado en los dos documentos (validación de estándares, PR vinculado,
minutograma, pruebas unitarias, manuales técnicos), el catálogo mínimo sugerido es:

| Código | Ítem | Quién lo marca | Bloqueante para pasar a producción |
|---|---|---|---|
| `branch_created` | Rama creada y vinculada al Work Item | Automático (webhook de Azure) | Sí |
| `pr_linked` | Pull Request vinculado (GUID/ArtifactLink) | Automático | Sí |
| `code_review_ai` | Revisión de código IA aprobada (estándares Laravel/Vue) | Agente IA | Sí |
| `unit_tests_passed` | Pruebas unitarias ejecutadas y en verde | Automático (pipeline) | Sí |
| `technical_doc` | Manual técnico / documentación generada | Agente IA o manual | No (advertencia) |
| `security_check` | Validación de seguridad del diff | Agente IA | Sí |
| `wo_request` | Solicitud de WO generada (plantilla RSSO) | Manual (usuario) | Sí |
| `manual_approval` | Aprobación manual en el dashboard (humano) | Humano | Sí |
| `pipeline_ready` | Pipeline de despliegue SSH configurado y en verde | Automático | Sí |
| `minutograma` | Minutograma (.xlsx) generado y adjunto al Work Item/Release | Automático (post-aprobación) | No (se genera al final) |

> Este catálogo se guarda como configuración (tabla `checklist_definitions`), no hardcodeado,
> para poder agregar/quitar ítems por tipo de Work Item (Bug, Feature, Hotfix pueden requerir
> checklists distintas).

### Paso 12: Esquema de datos (para que un LLM pueda leerlo/generarlo)

```json
{
  "work_item_id": 1234,
  "estimated_delivery_date": "2026-09-05",
  "possible_pap_date": "2026-09-10",
  "checklist": [
    {
      "code": "branch_created",
      "status": "done",
      "blocking": true,
      "checked_by": "system",
      "checked_at": "2026-08-20T14:32:00Z",
      "evidence": { "branch": "feature/1234-oferta-neutral", "commit_count": 5 }
    },
    {
      "code": "code_review_ai",
      "status": "pending",
      "blocking": true,
      "checked_by": "agent:code-reviewer",
      "checked_at": null,
      "evidence": null
    }
  ],
  "ready_to_deploy": false
}
```

`status` acepta: `pending`, `in_progress`, `done`, `failed`, `skipped` (solo para ítems no
bloqueantes). `ready_to_deploy` se calcula en backend: `true` solo si todos los ítems con
`blocking: true` están en `done`.

### Paso 13: Endpoint de completitud

Crear `GET /api/work-items/{id}/checklist` (devuelve el JSON de arriba) y
`POST /api/work-items/{id}/checklist/{code}` para marcar un ítem (uso tanto de humanos desde
el dashboard como de agentes IA vía Copilot Studio Action).

La regla de negocio central: **el botón/endpoint de "Aprobar despliegue" (Paso 5 del doc
anterior) debe validar `ready_to_deploy == true` antes de disparar la aprobación manual del
pipeline.** Si algún ítem bloqueante falta, el endpoint responde 422 con la lista de ítems
pendientes, para que tanto el humano como el agente sepan qué falta.

---

## FASE 8: Calendario y Fechas

### Paso 14: Vista de calendario

1. Frontend: vista de calendario (mensual/semanal) que lea `estimated_delivery_date` y
   `possible_pap_date` de cada Work Item.
2. Backend: exponer `GET /api/calendar?from=&to=` devolviendo Work Items con esas fechas para
   el rango pedido.
3. Código de color sugerido: verde = `ready_to_deploy: true`, amarillo = fecha PAP dentro de 3
   días con ítems bloqueantes pendientes, rojo = fecha PAP vencida sin completar checklist.

---

## FASE 9: Agentes IA — puntos de control concretos

*El documento anterior ya define la arquitectura general del agente (Copilot Studio + acciones
+ webhook de aprobación). Aquí se detalla **qué revisa cada agente y con qué checklist item se
conecta**, para que el flujo humano-en-el-loop sea explícito.*

### Paso 15: Mapear acciones del agente a ítems del checklist

| Acción del agente (Copilot Studio) | Ítem del checklist que actualiza | Trigger |
|---|---|---|
| `RevisarCodigo` | `code_review_ai`, `security_check` | Webhook de PR creado (Paso 8 del doc anterior) |
| `GenerarDocumentacion` | `technical_doc` | Al aprobarse `code_review_ai` |
| `SolicitarDespliegue` | Crea entrada en `agent-approval` con estado `Pendiente` | Cuando `ready_to_deploy == true` |
| `GenerarMinutograma` | `minutograma` | Al aprobarse el despliegue (Paso 10 del doc anterior) |

### Paso 16: Contrato de la acción `RevisarCodigo` (para que el agente sepa qué responder)

```json
{
  "work_item_id": 1234,
  "pr_id": 5678,
  "verdict": "approved" | "rejected" | "needs_changes",
  "findings": [
    { "severity": "high", "rule": "no-raw-sql", "file": "app/Http/Controllers/X.php", "line": 42 }
  ],
  "checklist_updates": {
    "code_review_ai": "done" | "failed",
    "security_check": "done" | "failed"
  }
}
```

Si `verdict` es `rejected`, el backend usa el PAT para comentar el PR (ya descrito en el Paso
8 del documento anterior) y **no** marca los ítems como `done`.

### Paso 17: Human-in-the-loop explícito

Cada acción del agente que sea irreversible o cueste dinero/tiempo de producción
(`SolicitarDespliegue` en particular) debe:
1. Quedar en estado `Pendiente` en la tabla de aprobaciones (ya definido en Paso 4 del doc
   anterior).
2. Nunca marcar `manual_approval` como `done` — ese ítem solo lo puede marcar un humano desde
   el dashboard (Paso 5 del doc anterior, botones Aprobar/Rechazar).
3. Registrar `checked_by` con el usuario real (no `system` ni `agent:*`) cuando se trate de
   `manual_approval` y `wo_request`.

---

## FASE 10: Preguntas abiertas (a resolver antes de implementar)

1. ¿Las checklists son iguales para todos los tipos de Work Item (Bug/Feature/Hotfix) o cada
   tipo tiene su propio catálogo de ítems bloqueantes?
2. ¿`code_review_ai` corre sobre el diff completo o solo sobre los archivos tocados desde el
   último review fallido (para no re-analizar todo en cada push)?
3. ¿El agente de Copilot Studio llama directamente al endpoint `POST
   /api/work-items/{id}/checklist/{code}`, o pasa siempre por Ollama/OpenCode local primero
   como capa de validación adicional antes de tocar producción?
4. ¿La plantilla de WO (RSSO) se marca como `done` automáticamente cuando el usuario copia el
   texto generado, o requiere confirmación manual explícita de que ya se radicó en el portal?

---

## Orden de implementación sugerido

1. Paso 11–13 (modelo de checklist + endpoint de completitud) — es el bloqueo real para todo
   lo demás.
2. Paso 15–16 (contrato del agente `RevisarCodigo`) — reutiliza el webhook de PR que ya existe.
3. Paso 14 (calendario) — depende de que los Work Items ya tengan las fechas y el checklist.
4. Paso 17 (endurecer human-in-the-loop) — repaso de seguridad antes de conectar el agente a
   producción real.

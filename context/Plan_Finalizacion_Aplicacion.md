---
id: plan-finalizacion-aplicacion
tags:
  - work
  - IA
  - Azure
  - roadmap
  - plan
status: plan de ejecución para terminar la aplicación (continúa "Plan_Implementacion_Faltantes.md")
audience: humano + IA (agente de desarrollo)
fecha: 2026-10-26
---

# Plan de Finalización de la Aplicación — MonitoreoAzure

> Plan por fases, paso a paso, para cerrar las funcionalidades pendientes:
> comentarios en work items, cambio de estado, CRUD y registro de alistamiento,
> ajuste del dashboard (filtros + estilos) y el botón de agente IA que revisa el PR
> y los commits del día para comentar automáticamente.
>
> Documentos relacionados: `Seguimiento Azure Devops.md`,
> `Despliegue y seguimiento pasos a produccion.md`,
> `Roadmap_checklist_alistamiento_y_agentes.md`, `Plan_Implementacion_Faltantes.md`.

---

## Decisiones confirmadas (2026-10-26)

| # | Tema | Decisión |
|---|---|---|
| 1 | "Estado" en el CRUD de alistamiento | Es el **estado del work item** (New, To Do, In Progress, In Review, Done, Removed). Cada item del alistamiento queda asociado a un estado: es lo que debe cumplirse en esa etapa. El avance de cada item en cada WI se sigue registrando aparte (`pending` / `done` …). |
| 2 | Documentos (manual técnico, minutograma…) | **Solo se guarda el link de SharePoint**. No se suben archivos al servidor. Se quita el tipo de dato `file` y la tabla `document_types`. |
| 3 | Botones de IA | Se implementan **los dos**: "Run Azure Agent" (un WI) y "Daily Comment Automation" (lote), más la **ejecución programada diaria**. |
| 4 | Aprobación | El comentario del agente **siempre pasa por revisión** del usuario. Si el WI **no tiene commits hoy**, se abre un **modal para escribir el comentario manualmente**. |
| 5 | Proveedor de IA | **Microsoft Copilot Studio** vía Direct Line. La plantilla sin IA queda solo como respaldo si Copilot no responde. |
| 6 | Botón "New Item" | **Se elimina**. |
| 7 | Usuario | El filtro por defecto es **@Me** (dueño del PAT), con **búsqueda por usuario** para ver los WIs de otra persona. |

---

## Estado de implementación (2026-10-26)

| Fase | Estado | Notas |
|---|---|---|
| 0. Base técnica | ✅ | `config/azure.php`, `config/agents.php`, WIQL escapado, `Http` de Laravel, `VITE_API_BASE_URL`, interceptor de errores, `AppModal`, toasts. |
| 1. Comentarios | ✅ | `POST /api/work-items/{id}/comments` (markdown → HTML). HTML de Azure sanitizado con DOMPurify. |
| 2. Cambio de estado | ✅ | Estados leídos de Azure por tipo; confirmación para Done/Removed y advertencia de bloqueantes pendientes. |
| 3. Tablas | ✅ | Migraciones editadas (la base no tenía migraciones ejecutadas), `document_types` eliminada, nueva `agent_runs`, seeder con los 7 items. |
| 4. CRUD alistamiento | ✅ | Vista `/alistamiento` agrupada por estado; reordenar con flechas (sin arrastrar). |
| 5. Alistamiento por WI | ✅ | Panel lateral agrupado por estado + modal de valor según tipo de dato; items automáticos de PR/rama/pipeline. |
| 6. Dashboard | ✅ | Filtros (estado, tipo, búsqueda) en la URL, tarjetas como filtro rápido, buscador de usuarios, "New Item" eliminado, insignia de alistamiento. |
| 7. Estilos | ✅ | Tokens de tipografía definidos, `.btn`/`.field`, sidebar con rutas y menú móvil, usuario real, solo la fuente *outlined* de iconos. |
| 8. Agente | ✅ / ⏳ | Flujo completo (individual, lote, programado, bandeja de revisión, comentario manual sin commits). **Pendiente:** crear el agente en Copilot Studio y poner `COPILOT_DIRECTLINE_SECRET` en `.env`; mientras tanto se usa la plantilla y la propuesta indica "Generado sin IA". |
| 9. Calidad | ✅ / ⏳ | 27 tests (PHPUnit, Azure simulado), Pint, `pnpm build`, Postman actualizado. **Pendiente:** autenticación (Sanctum) y despliegue con worker de colas + cron. |

Valores por defecto aplicados a las preguntas abiertas (se pueden cambiar): bloqueantes **solo advierten**; los estados se leen de Azure (el proceso es **Scrum**, no existe *In Review*); ejecución programada **lunes a viernes 17:30 `America/Bogota`** (`AGENT_SCHEDULE_TIME`, `AGENT_SCHEDULE_ENABLED`); comentarios a nombre del dueño del PAT.

---

## 0. Diagnóstico del estado actual (revisado en el código)

### Backend (`azure-dashboard-api/`)

| Área | Estado | Observación |
|---|---|---|
| `AzureDevOpsService` | Funcional | Lee WI, repos, ramas, PRs, agentes; enlaza rama/PR. **No** crea comentarios ni cambia estado. |
| WIQL `assigned_to` | Riesgo | El valor se interpola sin escapar (`'{$assignedTo}'`) → inyección WIQL / errores con comillas. |
| Config Azure | Incompleto | `AZURE_DEVOPS_ORG/PROJECT/PAT` se leen con `env()` directo y no están en `.env.example`. Con `config:cache` dejarían de funcionar. |
| Modelos Eloquent | Faltan | Solo existe `User`. No hay modelos para las tablas de alistamiento. |
| Autenticación | No usada | Sanctum instalado, rutas abiertas. |
| Tests | Solo ejemplos | — |

### Tablas existentes (migraciones `2026_08_27_*`) y lo que falta

| Tabla | Columnas actuales | Problema para lo que se pide |
|---|---|---|
| `checklist_definitions` | `code`, `label`, `blocking`, `applies_to_type` | **Falta** tipo de dato (`data_type`), estado del WI al que pertenece (`work_item_state`), orden (`sort_order`), descripción/ayuda, opciones para listas. |
| `checklist_status` | `work_item_metadata_id`, `checklist_definition_id`, `status`, `checked_by`, `checked_at` | **Falta la columna para guardar el valor** (`value`), notas, y un índice único (WI + definición) para no duplicar. |
| `work_item_metadata` | `azure_work_item_id` (string, unique), `work_item_type`, fechas, `ready_to_deploy` | OK. Se crea bajo demanda (`firstOrCreate`) al abrir un WI. |
| `document_types` | `code`, `label` | Sin relación con nada. **Se elimina** (los documentos son links de SharePoint). |
| `agent_approvals` | `action_code`, `status` (`Pendiente`), `requested_by`, `resolved_by` | **Falta** `payload` (json con el comentario propuesto) y `result` (respuesta de Azure). Se reutiliza para aprobar comentarios del agente. |
| — | — | **Falta** tabla `agent_runs` para registrar ejecuciones del agente y evitar comentar dos veces el mismo día. |

### Frontend (`azure-dashboard-web/`)

| Área | Estado |
|---|---|
| `WorkItemView` – textarea de comentario | Solo maqueta: botón "Guardar" sin acción; adjuntar/mencionar sin acción. |
| `WorkItemView` – select de estado | Lista **hardcodeada** (`New, To Do, In Progress, In Review, Done, Removed`) que mezcla estados de distintos tipos; no tiene `@change`. |
| `WorkItemView` – "Production Readiness" | 6 items **hardcodeados** (Solicitud de Rama, PR, Manual Técnico, Pruebas Unitarias, Minutograma, Validación de Estándares, Pipeline). |
| `WorkItemView` – comentarios | Se renderizan con `v-html` sin sanitizar (riesgo XSS). |
| `WorkItemView` – botón "Run Azure Agent" | Sin acción. |
| `DashboardView` – "Filter" y "New Item" | Sin acción. |
| `DashboardView` – "Daily Comment Automation" | Sin acción. |
| `DashboardView` – badge de estado | Siempre azul, no depende del estado. |
| `DashboardView` – `aria-label="... {{ item.id }}"` | Interpolación inválida en atributo (se ve literal). |
| Estilos | Clases usadas pero **no definidas** en `@theme` de `style.css`: `font-body-sm`, `text-body-sm`, `font-label-caps`, `text-label-caps`, `font-label-mono`, `text-label-mono`, `font-headline-lg`, `text-headline-lg`, `animate-spin-slow`. No tienen efecto. |
| `apiClient.ts` | `baseURL` hardcodeado a IP. |
| Navegación | Sidebar con `href="#"`, usuario "Cristian" hardcodeado, sin navegación en móvil. |
| Restos de plantilla | `HelloWorld.vue`, `assets/hero.png`, `index.html` con `lang="en"` y título genérico, `console.log` en el dashboard. |

---

## Orden de ejecución y dependencias

```
Fase 0 (base técnica)
   ├─► Fase 1 (comentarios) ─────────────┐
   ├─► Fase 2 (cambio de estado)          │
   ├─► Fase 3 (tablas alistamiento)       │
   │      └─► Fase 4 (CRUD definiciones)  │
   │             └─► Fase 5 (alistamiento por WI + modal)
   ├─► Fase 6 (dashboard: filtros)        │
   └─► Fase 7 (estilos globales)          │
                                          ▼
                     Fase 8 (agente IA: PR + commits del día → comentario)
                                          ▼
                     Fase 9 (calidad, pruebas y despliegue)
```

- Fase 8 depende de la Fase 1 (publicar comentarios) y reutiliza `agent_approvals`.
- Fases 1, 2, 3, 6 y 7 pueden hacerse en paralelo después de la Fase 0.

---

## Fase 0 — Base técnica (prerrequisito)

**Objetivo:** dejar la base estable antes de agregar funcionalidad.

### Backend
1. Crear `config/azure.php`:
   ```php
   return [
       'org'     => env('AZURE_DEVOPS_ORG'),
       'project' => env('AZURE_DEVOPS_PROJECT'),
       'pat'     => env('AZURE_DEVOPS_PAT'),
       'api_version' => '7.1',
   ];
   ```
   y en `AzureDevOpsService` reemplazar `env(...)` por `config('azure.*')`.
2. Agregar las variables a `.env.example` (sin valores).
3. Revisar los **scopes del PAT** (necesarios para todo el plan):
   - Work Items: **Read & Write** (comentarios y cambio de estado).
   - Code: **Read** (PRs, commits).
   - Build: **Read** (estado del pipeline, opcional).
   - Agent Pools: **Read** (listado de agentes existente).
   - Project and Team: **Read** (búsqueda de usuarios, Fase 6).
4. Escapar valores en WIQL: helper `escapeWiql(string $v): string` que duplique comillas simples (`'` → `''`), y aplicarlo en `buildActiveWorkItemsQuery` y `countWorkItemsByState`.
5. Extraer el bloque repetido de manejo de errores (`method_exists($e,'getResponse')...`) a un método privado `formatError(\Throwable $e, string $prefix): array`.
6. Crear los modelos Eloquent (en la Fase 3 se completan sus columnas):
   `WorkItemMetadata`, `ChecklistDefinition`, `ChecklistStatus` (`$table = 'checklist_status'`), `AgentApproval`, `DocumentType`.

### Frontend
7. `apiClient.ts`: `baseURL: import.meta.env.VITE_API_URL`, y crear `.env.example` con `VITE_API_URL=http://localhost:8001/api` (`.env` ya está en `.gitignore`).
8. Agregar un interceptor de respuesta en `apiClient` que normalice el mensaje de error (`error.response?.data?.message`).
9. Crear un componente reutilizable `AppModal.vue` (overlay, título, slot, cerrar con Esc/click fuera, foco atrapado). `LinkBranchModal` y los modales nuevos lo usarán.
10. Crear un sistema de notificaciones simple (`useToast` + `AppToast.vue`) para mostrar éxito/error de las acciones (comentario guardado, estado cambiado, etc.).

**Criterio de aceptación:** la app funciona igual que hoy, sin URLs ni credenciales hardcodeadas.

---

## Fase 1 — Comentarios en los work items

**Objetivo:** que el textarea de `WorkItemView` publique el comentario en Azure DevOps.

### Backend
1. `AzureDevOpsService::addWorkItemComment(int $workItemId, string $text): array`
   - `POST wit/workItems/{id}/comments?api-version=7.1-preview.4`
   - Body: `{ "text": "<texto>" }`. El texto se guarda como HTML; convertir saltos de línea a `<br>` y escapar el HTML del usuario (`e()` / `htmlspecialchars`) antes de enviarlo.
   - Verificar si el tenant acepta `&format=markdown` en esa versión; si funciona, usar markdown (útil para el agente de la Fase 8).
2. Ruta: `POST /api/work-items/{id}/comments` → `AzureWorkItemController::addComment`.
   - Validación: `text` `required|string|max:10000`.
   - Respuesta `201`: `{ data: { id, text, createdBy, createdDate } }`.
3. Corregir la versión de lectura: `getWorkItemComments` usa `7.1-preview.3` → usar la misma versión `7.1-preview.4` para leer y escribir.

### Frontend
4. `workItemService.addComment(id, text)`.
5. En `WorkItemView`:
   - `v-model` en el textarea, botón "Guardar" deshabilitado si está vacío o enviando.
   - Atajo `Ctrl+Enter` para enviar.
   - Al guardar: agregar el comentario devuelto al inicio de la lista (sin recargar todo), limpiar el textarea y mostrar un toast.
   - Ocultar (o quitar) los botones "Adjuntar archivo" y "Mencionar" hasta implementarlos (las menciones requieren el formato `<a href="#" data-vss-mention="version:2.0,{userId}">` y búsqueda de identidades; queda fuera de alcance).
   - El avatar con "C" hardcodeado debe usar las iniciales del usuario actual (ver Fase 7, paso 8).
6. **Seguridad:** sanitizar el HTML de los comentarios antes de `v-html` con `dompurify` (`pnpm add dompurify`). Aplicarlo también a `description`.

**Criterio de aceptación:** el comentario aparece en la vista y en Azure DevOps con autor = dueño del PAT.

> Nota: todos los comentarios y cambios se hacen con la identidad del **dueño del PAT**. Si varias personas usan el dashboard, todas comentan como esa persona. Si eso no sirve, se necesita OAuth/Entra ID por usuario (fuera de este plan; ver Preguntas abiertas).

---

## Fase 2 — El select de estado cambia el estado del work item

**Objetivo:** que el select muestre los estados válidos del tipo de WI y actualice Azure al cambiar.

### Backend
1. `AzureDevOpsService::getWorkItemTypeStates(string $type): array`
   - `GET wit/workitemtypes/{type}/states?api-version=7.1` → devuelve `name`, `color`, `category` (`Proposed`, `InProgress`, `Resolved`, `Completed`, `Removed`).
   - Cachear 24h (`Cache::remember("azure.states.{$type}", ...)`): no cambian casi nunca.
2. Ruta: `GET /api/work-item-types/{type}/states`.
3. `AzureDevOpsService::updateWorkItemState(int $id, string $state): array`
   - `PATCH wit/workitems/{id}?api-version=7.1`, `Content-Type: application/json-patch+json`
   - Body: `[{ "op": "add", "path": "/fields/System.State", "value": "<estado>" }]`
4. Ruta: `PATCH /api/work-items/{id}/state` con `{ state }`.
   - Validar que `state` esté en la lista de estados del tipo (usar el cache del paso 1).
   - Si Azure rechaza por reglas del proceso (por ejemplo, un campo requerido al pasar a Done), devolver `422` con el mensaje de Azure.
5. Incluir `states` (o `category` del estado actual) en la respuesta de `show` para evitar una segunda llamada.

### Frontend
6. `workItemService.getStates(type)` y `workItemService.updateState(id, state)`.
7. En `WorkItemView`:
   - Generar las `<option>` a partir de los estados devueltos (no hardcodeados).
   - `@change`: actualización optimista → llamar a la API → si falla, revertir al estado anterior y mostrar el error.
   - Pedir confirmación (modal) antes de pasar a estados de categoría `Completed` o `Removed`.
   - Deshabilitar el select mientras se guarda.
   - Usar el `color` / `category` de Azure para los colores del badge, en vez de comparar strings (`'done'`, `'in progress'`…).
8. Agregar un `<label for="work-item-state">` (accesibilidad).

**Criterio de aceptación:** al cambiar el select, el estado se ve en Azure DevOps y se refleja en el historial del WI.

---

## Fase 3 — Modelo de datos del alistamiento (ajuste de tablas)

**Objetivo:** que las tablas soporten nombre, tipo de dato, estado y el valor por work item.

> **Decisión:** si la base de datos solo tiene datos de desarrollo, se pueden **editar las migraciones existentes** y ejecutar `php artisan migrate:fresh --seed`. Si ya hay datos que conservar, crear **migraciones nuevas** de tipo `alter`. Abajo está el esquema final esperado.

### 3.1 `checklist_definitions` (catálogo de items del alistamiento)

| Columna | Tipo | Nota |
|---|---|---|
| `id` | bigint PK | |
| `code` | string unique | slug, ej. `manual_tecnico` |
| `label` | string | **Nombre** visible |
| `description` | text null | ayuda / qué se espera |
| `data_type` | string | **Tipo de dato**: `text`, `textarea`, `number`, `date`, `boolean`, `url`, `sharepoint_link`, `select` |
| `options` | json null | opciones si `data_type = select` |
| `work_item_state` | string | **Estado** del WI al que pertenece el item: `New`, `To Do`, `In Progress`, `In Review`, `Done`, `Removed`. Se guarda como string porque los estados válidos dependen del tipo de WI y se leen de Azure (Fase 2) |
| `blocking` | boolean default false | si es obligatorio para **salir** de su estado (ver Fase 5, paso 9) y para `ready_to_deploy` |
| `is_required` | boolean default true | |
| `applies_to_type` | string null | `Bug`, `Product Backlog Item`, `Task`… null = todos |
| `source` | string default `manual` | `manual` o automático: `azure_branch`, `azure_pr`, `azure_pipeline` |
| `sort_order` | int default 0 | orden dentro de su estado |
| `timestamps`, `softDeletes` | | borrar sin perder historial: una definición eliminada deja de aparecer en los WIs, pero los valores ya registrados se conservan |
| índice | `index(work_item_state, sort_order)` | |

### 3.2 `checklist_status` (valor del item por work item)

| Columna | Tipo | Nota |
|---|---|---|
| `work_item_metadata_id` | FK | existente |
| `checklist_definition_id` | FK | existente — cambiar `cascadeOnDelete` → `restrictOnDelete` (se usa soft delete) |
| `status` | string | `pending`, `in_progress`, `done`, `failed`, `skipped` (existente) |
| **`value`** | text null | **NUEVO**: el valor ingresado (se interpreta según `data_type`; para `sharepoint_link` es la URL) |
| `notes` | text null | NUEVO: comentario opcional del usuario |
| `checked_by`, `checked_at` | | existentes |
| índice | `unique(work_item_metadata_id, checklist_definition_id)` | NUEVO |

### 3.3 `document_types`
- **Eliminarla** (migración que hace `dropIfExists`, o borrar la migración si se hace `migrate:fresh`). Los documentos se registran como `sharepoint_link`.

### 3.4 `agent_approvals` (agregar)
- `payload` json — comentario propuesto, PR, commits evaluados.
- `result` json null — respuesta de Azure al publicar.
- Usar estados `Pendiente`, `Aprobado`, `Rechazado`, `Publicado`, `Error`.

### 3.5 Nueva tabla `agent_runs`
| Columna | Tipo |
|---|---|
| `id` | PK |
| `azure_work_item_id` | string |
| `run_date` | date |
| `trigger` | string (`manual`, `scheduled`) |
| `status` | string (`ok`, `skipped`, `error`) |
| `summary` | json (PRs, commits encontrados) |
| `azure_comment_id` | string null |
| `error` | text null |
| índice | `unique(azure_work_item_id, run_date, trigger)` para **no comentar dos veces el mismo día** |

### 3.6 Modelos y seeders
- Modelos con `$fillable`, `$casts` (`options` → array, `blocking` → bool, `summary` → array) y relaciones:
  `WorkItemMetadata hasMany ChecklistStatus`, `ChecklistStatus belongsTo ChecklistDefinition`, etc.
- Enum PHP `App\Enums\ChecklistDataType` con los valores de `data_type` (para validación y para exponerlo al frontend).
- Crear `ChecklistDefinitionSeeder` con los items que hoy están hardcodeados en la vista (el estado asignado es una **propuesta** para ajustar desde el CRUD):

| code | label | data_type | work_item_state | source |
|---|---|---|---|---|
| `solicitud_rama` | Solicitud de Rama | `text` (número WO) | To Do | manual |
| `pull_request` | Pull Request Asociado | `url` | In Progress | `azure_pr` |
| `pruebas_unitarias` | Pruebas Unitarias | `boolean` | In Progress | manual |
| `manual_tecnico` | Manual Técnico | `sharepoint_link` | In Review | manual |
| `minutograma` | Minutograma | `sharepoint_link` | In Review | manual |
| `validacion_estandares` | Validación de Estándares | `boolean` | In Review | manual |
| `estado_pipeline` | Estado del Pipeline | `text` | Done | `azure_pipeline` |

**Criterio de aceptación:** `php artisan migrate:fresh --seed` crea todo sin errores.

---

## Fase 4 — CRUD del listado de alistamiento (definiciones)

**Objetivo:** administrar desde la UI los items del alistamiento: **nombre**, **tipo de dato** y **estado del WI** al que pertenecen.

### Backend
1. `ChecklistDefinitionController` (`apiResource`):
   - `GET /api/checklist-definitions?state=In%20Review&type=Bug` — listado ordenado por estado y `sort_order`.
   - `POST /api/checklist-definitions`
   - `GET /api/checklist-definitions/{id}`
   - `PUT /api/checklist-definitions/{id}`
   - `DELETE /api/checklist-definitions/{id}` → soft delete.
   - `PATCH /api/checklist-definitions/reorder` con `[{id, sort_order}]`.
   - `GET /api/checklist-definitions/data-types` → lista de tipos de dato con su etiqueta (desde el enum) para el select del formulario.
2. `StoreChecklistDefinitionRequest` / `UpdateChecklistDefinitionRequest`:
   - `label` required|max:150
   - `code` generado con `Str::slug($label, '_')` si no viene; unique
   - `data_type` `Rule::enum(ChecklistDataType::class)`
   - `options` `required_if:data_type,select|array|min:1`
   - `work_item_state` required|string, validado contra los estados de Azure (Fase 2): los del `applies_to_type` si viene, o la unión de los estados de todos los tipos si es null
   - `blocking`, `is_required` boolean
   - `applies_to_type` nullable|string
3. `ChecklistDefinitionResource` para dar formato a la respuesta.
4. No permitir cambiar `data_type` si ya existen valores guardados para esa definición (o avisar) para no corromper datos.

### Frontend
5. Ruta `/alistamiento` → `ChecklistDefinitionsView.vue` y entrada en el sidebar ("Alistamiento", icono `fact_check`).
6. Listado **agrupado por estado** (una sección por estado, en el orden del flujo: New → To Do → In Progress → In Review → Done → Removed), con columnas: Nombre, Tipo de dato, Aplica a, Bloqueante, Acciones (editar, eliminar). Filtro por estado y por tipo de WI.
7. Modal `ChecklistDefinitionFormModal.vue` (crear/editar):
   - Nombre, descripción, **tipo de dato** (select), opciones (editor de lista, solo si es `select`), aplica a tipo de WI, **estado** (select con los estados de Azure del tipo elegido), bloqueante, requerido.
8. Confirmación antes de eliminar (avisar si ya hay WIs con valores registrados).
9. `checklistService.ts` con los tipos `ChecklistDefinition` y `DataType`.
10. (Opcional) Reordenar arrastrando dentro de cada estado.

**Criterio de aceptación:** crear, editar y eliminar definiciones desde la UI con su nombre, tipo de dato y estado; los cambios persisten.

---

## Fase 5 — Alistamiento asociado a cada work item + modal de valor

**Objetivo:** reemplazar la sección "Production Readiness" hardcodeada por los items reales; al hacer click en uno se abre un modal para registrar el valor.

### Backend
1. `GET /api/work-items/{id}/checklist`
   - `WorkItemMetadata::firstOrCreate(['azure_work_item_id' => $id], ['work_item_type' => $type])`.
   - Devuelve las definiciones que aplican al tipo del WI, **agrupadas por `work_item_state`**, cada una con su `checklist_status` si existe (left join). Una definición eliminada (soft delete) que ya tiene valor también se devuelve, marcada como histórica.
   - Marca cuál grupo corresponde al **estado actual** del WI (`is_current_state: true`).
   - Para items con `source` automático, calcular el valor en el momento:
     - `azure_pr`: buscar relaciones `ArtifactLink` con `PullRequestId` en el WI.
     - `azure_branch`: relaciones con `Git/Ref`.
     - `azure_pipeline`: `GET build/builds?branchName=refs/heads/{rama}&$top=1&api-version=7.1`.
   - Incluir un resumen: `{ total, done, blocking_pending, ready_to_deploy }`.
2. `PUT /api/work-items/{id}/checklist/{definitionId}` con `{ value, status?, notes? }`
   - Validar `value` según el `data_type` de la definición (`number` → numeric, `date` → date, `url` → url, `boolean` → boolean, `select` → `in:options`, `sharepoint_link` → url `https` cuyo host termine en `.sharepoint.com`).
   - Si hay valor y no viene `status` → `done`. `checked_by` = usuario (o `system`), `checked_at` = now.
   - Recalcular `work_item_metadata.ready_to_deploy` = todos los `blocking` en `done`.
3. No se suben archivos: los documentos son links de SharePoint. El backend **no** valida que el link sea accesible (requeriría permisos de Microsoft Graph); solo valida el formato.
4. (Opcional) Publicar un comentario en el WI cuando un item bloqueante pase a `done` (reutiliza la Fase 1).

### Frontend
5. `WorkItemChecklist.vue`: lista en el sidebar del WI generada con los datos de la API:
   - Secciones por estado del WI; la del **estado actual** expandida y resaltada, las anteriores colapsadas con su progreso, las siguientes en gris (se pueden completar por adelantado).
   - Icono según avance: `check_circle` (done, verde), `radio_button_unchecked` (pending), `warning` (failed), `remove_circle` (skipped).
   - Subtexto: valor resumido (fecha formateada, "Sí/No", link). Para `sharepoint_link` mostrar "Abrir en SharePoint" (`target="_blank" rel="noopener"`).
   - Barra de progreso: `done/total` y badge "Listo para producción" si `ready_to_deploy`.
   - Todo el item es clickeable (`button`, accesible con teclado), no solo el icono `add_comment` que hoy aparece en hover.
6. `ChecklistValueModal.vue`: al hacer click en un item:
   - Muestra nombre, descripción y el **input según `data_type`**:
     `text` → input · `textarea` → textarea · `number` → input number · `date` → input date · `boolean` → switch Sí/No · `url` → input url · `sharepoint_link` → input url con validación de dominio SharePoint y botón "Abrir" para probar el link · `select` → select con `options`.
   - Select de estado (`pending`, `in_progress`, `done`, `failed`, `skipped`) y notas.
   - Guardar → `PUT`, actualizar el item en la lista, toast.
   - Items automáticos (`source != manual`): modal solo de lectura con link a Azure (PR / pipeline).
7. Eliminar los 7 bloques hardcodeados de `WorkItemView.vue`.
8. (Opcional) En el dashboard, mostrar una columna o badge "Alistamiento x/y" por WI (`GET /api/work-items/checklist-summary?ids=1,2,3` para hacer una sola llamada).
9. **Integración con el cambio de estado (Fase 2):** al cambiar el select de estado, si quedan items `blocking` pendientes del estado actual, mostrar un modal de advertencia con la lista ("Faltan: Manual Técnico, Minutograma") y permitir continuar o cancelar. Por ahora es advertencia, no bloqueo (ver Preguntas abiertas).

**Criterio de aceptación:** al abrir un WI se ven los items agrupados por estado con el estado actual resaltado; al registrar un valor desde el modal persiste y el progreso se actualiza.

---

## Fase 6 — Dashboard (raíz): filtros y botones

**Objetivo:** que los botones de la vista raíz funcionen.

### Backend
1. Extender `GET /api/work-items` con filtros: `state[]`, `type[]`, `assigned_to` (ya existe, **default `@Me`**), `search` (título o ID), `iteration` (opcional).
   - Construir el WIQL con valores **escapados** (Fase 0) y validar `state`/`type` contra listas permitidas.
   - `assigned_to`: `@Me` → `= @Me`; un usuario → usar su **`uniqueName`** (correo), que es más confiable que el nombre visible: `[System.AssignedTo] = 'persona@empresa.com'`.
   - `search` numérico → `[System.Id] = n`; texto → `[System.Title] CONTAINS 'texto'`.
2. Cachear el `summary` (3 consultas WIQL extra en cada cambio de página) por 60s por combinación de filtros.
3. `GET /api/work-item-types` → tipos del proyecto (`wit/workitemtypes`) para poblar el filtro.
4. **Búsqueda de usuarios:** `GET /api/users?search=texto`
   - Fuente recomendada: miembros de los equipos del proyecto: `GET {org}/_apis/projects/{project}/teams?api-version=7.1` y, por cada equipo, `GET {org}/_apis/projects/{project}/teams/{teamId}/members?api-version=7.1`. Unificar por `uniqueName` y cachear 1 h.
   - Filtrar en el backend por `displayName` o `uniqueName` y devolver `[{ displayName, uniqueName, imageUrl }]` (máx. 20).
   - Requiere que el PAT tenga el scope **Project and Team: Read** (agregarlo a la lista de la Fase 0).

### Frontend
4. Botón **Filter** → panel desplegable (o fila de filtros) con:
   - Estado (multiselección), Tipo (multiselección), búsqueda por ID/título.
   - **Asignado a**: por defecto "Yo (@Me)". Componente `UserSearchSelect.vue` con autocompletado (debounce 300 ms → `GET /api/users?search=`) para elegir otra persona, y botón para volver a "Yo". Mostrar en el encabezado de la tabla de quién son los WIs ("Work Items de Ana Pérez").
   - Botones "Aplicar" y "Limpiar"; contador de filtros activos en el botón.
   - Guardar los filtros en la query string (`?state=Done&type=Bug`) con `router.replace` para poder compartir y conservar al volver desde el detalle.
   - Al cambiar filtros → `currentPage = 1`.
5. Las tarjetas **Done / In Progress / To Do** funcionan como filtros rápidos al hacer click.
6. **Eliminar el botón "New Item"** del encabezado del dashboard.
7. Botón **Daily Comment Automation**: se conecta en la Fase 8 (ejecución en lote). Actúa sobre los WIs del usuario filtrado (por defecto @Me).
8. Badge de estado en la tabla: usar el mismo helper de colores que el detalle (extraer a `composables/useWorkItemStyles.ts` → `stateClasses`, `typeIcon`, `typeColor`). Hoy está duplicado entre `DashboardView` y `WorkItemView`.
9. Corregir `aria-label="Link branch to work item {{ item.id }}"` → `:aria-label="`Vincular rama al work item ${item.id}`"`. Además el botón abre el modal de **Pull Request**, así que el título "Vincular Rama" y el icono deben corresponder.
10. Quitar el `console.log(response)`.
11. Estado vacío: si no hay resultados, mostrar un mensaje con botón "Limpiar filtros".
12. La tabla de 12 columnas no se adapta a móvil: en `< md` mostrar cada WI como tarjeta.

**Criterio de aceptación:** filtrar por estado/tipo/búsqueda funciona; la paginación respeta los filtros; los filtros se conservan al volver del detalle.

---

## Fase 7 — Revisión de estilos y limpieza visual

1. **Definir los tokens de tipografía que faltan** en `@theme` de `src/style.css` (Tailwind v4 genera las utilidades a partir de estas variables):
   ```css
   @theme {
     --font-sans: "Inter", "Segoe UI", system-ui, sans-serif;
     --font-mono: "JetBrains Mono", ui-monospace, monospace;
     --font-body-sm: var(--font-sans);
     --font-label-caps: var(--font-sans);
     --font-label-mono: var(--font-mono);
     --font-headline-lg: var(--font-sans);
     --text-body-sm: 0.8125rem;
     --text-body-sm--line-height: 1.25rem;
     --text-label-caps: 0.6875rem;
     --text-label-caps--letter-spacing: 0.06em;
     --text-label-mono: 0.75rem;
     --text-headline-lg: 2rem;
     --text-headline-lg--line-height: 2.5rem;
     --animate-spin-slow: spin 3s linear infinite;
   }
   ```
   (Si se usa Inter/JetBrains Mono, cargarlas con `@fontsource` o Google Fonts.)
2. Unificar la escala: hoy se mezclan `text-sm`, `text-xs`, `text-[11px]`, `text-[10px]` y `text-body-sm`. Elegir los tokens y reemplazar los valores arbitrarios.
3. Unificar botones: crear `AppButton.vue` (variantes `primary`, `secondary`, `ghost`, `danger`; tamaños `sm`, `md`; estado `loading`). Hoy cada botón repite ~6 clases distintas.
4. Componentes base reutilizables: `AppModal` (Fase 0), `AppSelect`, `AppBadge` (estado), `AppEmptyState`, `AppSpinner`.
5. Sidebar:
   - Reemplazar `<a href="#">` por `<router-link>` con clase activa (`Overview`, `Alistamiento`, `Agentes`).
   - Menú hamburguesa en móvil (hoy el sidebar está `hidden` en `< md` y no hay alternativa).
6. Breadcrumb del detalle: "Boards" no es clickeable y no aporta; mostrar `Dashboard › #ID`.
7. `WorkItemView`: el sidebar usa `bg-surface-container-highest` y los items `bg-surface-variant` (mismo color `#2d363e`) → los items no se distinguen. Ajustar contraste.
8. Usuario actual: endpoint `GET /api/me` que consulte `https://vssps.dev.azure.com/{org}/_apis/connectionData` (o `_apis/profile/profiles/me`) para mostrar el nombre/iniciales reales del dueño del PAT en sidebar, navbar y caja de comentario.
9. Limpieza: borrar `HelloWorld.vue`, `assets/hero.png`, `vite.svg`, `vue.svg` si no se usan; `index.html` → `lang="es"` y `<title>Monitoreo Azure</title>`.
10. Accesibilidad: contraste AA de `text-on-surface-variant` sobre los fondos, `focus-visible` en items clickeables, `role="dialog"` + `aria-modal` en modales.

**Criterio de aceptación:** `pnpm build` sin errores; todas las clases usadas en las plantillas tienen efecto; navegación usable en móvil.

---

## Fase 8 — Agente IA: revisar PR + commits del día → comentario automático

### 8.1 Qué hace el botón (comportamiento esperado)

Se implementan **los tres modos**:

- **"Run Azure Agent"** (detalle de un WI) → ejecuta el flujo para **ese** WI.
- **"Daily Comment Automation"** (dashboard) → ejecuta el flujo en lote para los WIs activos del usuario filtrado (por defecto @Me).
- **Ejecución programada diaria** → corre el lote solo, a una hora fija, y deja las propuestas **pendientes de revisión**.

**Regla general: nada se publica sin revisión del usuario.**

Flujo por work item:

```
1. Leer relaciones del WI  ──►  PRs enlazados (ArtifactLink PullRequestId)
                               └► si no hay PR: ramas enlazadas (ArtifactLink Git/Ref)
2. Por cada PR/rama          ──►  commits con fecha >= hoy 00:00 (zona horaria local)
3. Por cada commit           ──►  mensaje, autor, hora, archivos cambiados (sin contenido de código)
4a. HAY commits hoy          ──►  Copilot Studio redacta el comentario → propuesta en agent_approvals (Pendiente)
                                   → modal de revisión (editar / publicar / regenerar / descartar)
4b. NO hay commits hoy        ──►  agent_approvals con tipo "manual_required" (sin texto)
                                   → modal para que el usuario escriba el comentario a mano
5. Usuario publica           ──►  POST comentario en Azure (Fase 1)  ──►  agent_runs status ok
```

> **Recomendación:** que la IA **solo redacte el texto**. La recolección de datos (PR, commits) la hace Laravel de forma determinista con la API de Azure DevOps. Así el resultado es reproducible, barato, y la IA no necesita acceso directo a Azure ni a la red interna.

### 8.2 APIs de Azure DevOps necesarias

| Paso | Endpoint (REST 7.1) |
|---|---|
| Relaciones del WI | `GET wit/workitems/{id}?$expand=relations` (ya se usa con `$expand=all`) |
| Datos del PR | `GET git/repositories/{repoId}/pullrequests/{prId}` |
| Commits del PR | `GET git/repositories/{repoId}/pullRequests/{prId}/commits` (filtrar por `author.date` del día) |
| Commits de una rama desde hoy | `GET git/repositories/{repoId}/commits?searchCriteria.itemVersion.version={rama}&searchCriteria.itemVersion.versionType=branch&searchCriteria.fromDate={ISO8601}` |
| Archivos de un commit | `GET git/repositories/{repoId}/commits/{commitId}/changes` |
| Estado de pipeline (opcional) | `GET build/builds?branchName=refs/heads/{rama}&$top=1` |
| Publicar comentario | `POST wit/workItems/{id}/comments?api-version=7.1-preview.4` (Fase 1) |

**Parseo de URLs de artefactos** (punto delicado):
- PR: `vstfs:///Git/PullRequestId/{projectId}%2F{repoId}%2F{prId}` — Azure guarda los separadores **codificados como `%2F`**. Hacer `urldecode` y luego `explode('/')`.
- Rama: `vstfs:///Git/Ref/{projectId}%2F{repoId}%2FGB{rama}` — quitar el prefijo `GB` y decodificar la rama.
- ⚠️ `getActivePullRequests()` y `linkBranchToWorkItem()` hoy construyen el link con `/` en vez de `%2F`, y la rama usa el **nombre** del proyecto en vez del GUID. Verificar en la UI de Azure DevOps que esos links aparezcan como "Pull Request"/"Branch" reales en la sección *Development* del WI; si no, corregir el formato. El parser debe aceptar ambos formatos.

**Zona horaria:** "hoy" debe calcularse en la zona del equipo (`APP_TIMEZONE`, ej. `America/Bogota`) y convertirse a UTC para `fromDate`; Azure devuelve fechas en UTC.

### 8.3 Arquitectura en Laravel

```
app/
├── Services/
│   ├── AzureDevOpsService.php          (+ getPullRequest, getPullRequestCommits,
│   │                                      getBranchCommitsSince, getCommitChanges,
│   │                                      parseArtifactLinks)
│   └── Agents/
│       ├── DailyProgressCollector.php   recolecta PR + commits del día → DTO
│       ├── CommentGenerator.php         interfaz: generate(DailyProgress): string
│       ├── CopilotStudioCommentGenerator.php  generador principal (Direct Line)
│       ├── DirectLineClient.php         conversación, envío y polling de actividades
│       └── TemplateCommentGenerator.php respaldo sin IA (plantilla markdown) si Copilot falla
├── Jobs/RunDailyCommentAgent.php        un job por WI (cola database)
└── Http/Controllers/Api/AgentRunController.php
```

- `config/agents.php`: `comment_generator` (`copilot_studio` por defecto, `template` para desarrollo sin licencia), `timeout`, `schedule_time`, `timezone`. Binding de la interfaz en `AppServiceProvider`.
- **Fallback:** si Copilot Studio falla o se demora más de N segundos (ej. 30 s), usar `TemplateCommentGenerator`; la propuesta se marca "generada sin IA" para que el usuario lo sepa al revisar.
- Prefijo del comentario: `🤖 Resumen automático del día (DD/MM/AAAA)`. En los comentarios escritos a mano desde el modal "sin commits" no se pone el prefijo 🤖.
- `agent_approvals.action_code`: `daily_comment` (con propuesta) o `daily_comment_manual` (sin commits, requiere texto manual).

Endpoints:
- `POST /api/work-items/{id}/agent/daily-comment` → recolección + generación. Devuelve `{ approval_id, has_commits, proposal, context }`. **Nunca publica.**
- `POST /api/agent-approvals/{id}/approve` con `{ text }` (el texto final, editado o escrito a mano) → publica en Azure, `status = Publicado`.
- `POST /api/agent-approvals/{id}/reject`.
- `POST /api/agent-approvals/{id}/regenerate` → vuelve a pedir el texto a Copilot.
- `GET /api/agent-approvals?status=Pendiente` → bandeja de propuestas pendientes (las del lote y las programadas).
- `POST /api/agent/daily-comment/batch` con `{ assigned_to }` → despacha un `RunDailyCommentAgent` por cada WI activo; devuelve el `batch_id`.
- `GET /api/agent-runs?batch_id=...` / `?date=YYYY-MM-DD` → progreso y resultados del lote.

Ejecución programada:
- `routes/console.php`: `Schedule::job(new DispatchDailyComments('@Me'))->weekdays()->at(config('agents.schedule_time'))->timezone(config('agents.timezone'));`
- Requiere en el servidor: cron `* * * * * php artisan schedule:run` + `php artisan queue:work` (supervisor/systemd).
- Solo genera propuestas en `Pendiente` (no publica). El dashboard muestra un badge "N comentarios por revisar" que abre la bandeja.
- Las propuestas pendientes de días anteriores se marcan como vencidas (no se publica un "resumen de hoy" con fecha de ayer sin avisar).

Idempotencia: antes de publicar, verificar `agent_runs` (`unique(wi, fecha, trigger)`) para no duplicar el comentario si se presiona el botón dos veces o si el lote y el programado coinciden; si ya hay un comentario publicado hoy, avisar en el modal.

### 8.4 Frontend
1. "Run Azure Agent" → spinner ("Copilot está revisando el PR…") → según la respuesta:
   - **Con commits** → `AgentCommentReviewModal.vue`:
     - Resumen de datos usados (PR, rama, n.º de commits con hora y mensaje, archivos).
     - Texto propuesto **editable** (textarea + vista previa markdown).
     - Botones: "Publicar comentario", "Regenerar", "Descartar".
   - **Sin commits hoy** → `ManualCommentModal.vue`:
     - Aviso "No se encontraron commits hoy en el PR/rama asociados" (o "El WI no tiene PR ni rama asociados").
     - Textarea vacío para escribir el comentario a mano → "Publicar" / "Cancelar".
2. "Daily Comment Automation" → modal de confirmación ("Se analizarán N work items de @Me") → progreso por WI (polling a `/api/agent-runs`) → **bandeja de revisión** (`AgentReviewInbox.vue`) con dos pestañas:
   - "Con propuesta": revisar/editar y publicar una por una (o seleccionar varias y publicar sin editar).
   - "Sin commits": cada WI abre el modal de comentario manual; se puede omitir.
3. La misma bandeja muestra las propuestas generadas por la **ejecución programada**.
4. Vista opcional `/agentes` con el historial de `agent_runs`.

### 8.5 Viabilidad con Microsoft Copilot

**Conclusión: es viable**, con condiciones. Hay tres caminos dentro del ecosistema Microsoft:

| Opción | Cómo se integra | Requisitos | Pros | Contras |
|---|---|---|---|---|
| **A. Copilot Studio** (agente propio) | Laravel conversa con el agente por **Direct Line API 3.0** (`https://directline.botframework.com/v3/directline/...`): crear conversación → enviar actividad con los datos del día → leer la respuesta | Licencia de **Copilot Studio** en el tenant (facturación por mensajes / créditos de Copilot), un entorno de Power Platform, el agente publicado en el canal *Web/Direct Line* con **secreto** de Direct Line, y la autenticación del agente configurada para canal de servicio (sin login de usuario) | Es lo que se pidió; el prompt y las instrucciones se editan sin desplegar código; puede integrarse luego con Teams | Conversación asíncrona (hay que hacer polling de actividades, latencia de segundos); costo por mensaje; si el agente debe llamar **él mismo** a Azure DevOps o a esta API, la API tiene que ser accesible desde internet (hoy está en una IP privada) o usar el conector de Azure DevOps de Power Platform |
| **B. Azure OpenAI** (Azure AI Foundry) | `POST {endpoint}/openai/deployments/{deployment}/chat/completions` con `api-key` desde Laravel | Suscripción de Azure, recurso Azure OpenAI y un modelo desplegado (ej. un GPT-4o-mini o similar) | La integración más simple (una llamada HTTP síncrona), costo por tokens muy bajo para este volumen, datos dentro del tenant de Azure | No es "Copilot" como producto; el prompt vive en el código/config |
| **C. Microsoft 365 Copilot** (el Copilot de Office/Teams) | — | Licencia por usuario | — | **No apto**: no está pensado para invocarse desde un backend propio para generar texto; descartar |

Alternativa fuera de Microsoft ya mencionada en otros documentos: **Ollama local** (gratuito, sin enviar datos fuera), útil para desarrollo.

**Decisión: opción A — Copilot Studio.** Orden de implementación:
1. Implementar el flujo completo con `TemplateCommentGenerator` (recolección, bandeja, revisión, publicación). Así se prueba todo sin depender de la licencia.
2. Implementar `DirectLineClient` + `CopilotStudioCommentGenerator`, enviando al agente el contexto ya recolectado en JSON (el agente **no** necesita acceso a Azure DevOps ni a la red interna).
3. Dejar la plantilla como respaldo automático.

Flujo Direct Line desde Laravel:
```
POST {DIRECTLINE_URL}/tokens/generate            (Authorization: Bearer {secreto}) → token
POST {DIRECTLINE_URL}/conversations              (Bearer token)                    → conversationId
POST {DIRECTLINE_URL}/conversations/{id}/activities
     { "type": "message", "from": { "id": "monitoreo-azure" }, "text": "<JSON del día>" }
GET  {DIRECTLINE_URL}/conversations/{id}/activities?watermark=...   (polling cada 1 s hasta la respuesta del bot o timeout)
```
Una conversación nueva por WI evita que el contexto de un WI contamine el de otro.

**Lo que se necesita para implementar la opción A (Copilot Studio):**
- [ ] Confirmar que el tenant/organización tiene licencia de Copilot Studio (o capacidad de créditos/mensajes) y quién la administra.
- [ ] Acceso a un entorno de Power Platform con permiso para crear agentes.
- [ ] Crear el agente "Resumen diario de Work Items" con instrucciones del tipo:
      *"Recibirás un JSON con un work item, su PR y los commits de hoy. Redacta en español un comentario breve (máx. 8 líneas) en markdown con: avance del día, archivos/áreas tocadas y próximos pasos si se deducen de los mensajes. No inventes información que no esté en el JSON."*
- [ ] Desactivar los temas genéricos (saludo, escalamiento) para que responda solo con el comentario.
- [ ] Configurar seguridad del canal Web → copiar el **secreto de Direct Line** → `.env`: `COPILOT_DIRECTLINE_SECRET=`, `COPILOT_DIRECTLINE_URL=https://directline.botframework.com/v3/directline`.
- [ ] Si la región/tenant usa un endpoint regional de Direct Line, obtenerlo del portal.
- [ ] El servidor de Laravel necesita **salida a internet** (HTTPS) hacia `directline.botframework.com`.

> La opción B (Azure OpenAI) queda solo como contingencia si la licencia de Copilot Studio no se aprueba. La interfaz `CommentGenerator` permite cambiarla sin tocar el resto del flujo.

**Datos y seguridad:**
- Enviar al modelo solo **metadatos** (título del WI, mensajes de commit, rutas de archivos, título del PR); **no** enviar código fuente ni diffs salvo que se apruebe explícitamente.
- Los comentarios se publican como el dueño del PAT, con el prefijo 🤖 para que se distingan de los escritos a mano.
- Registrar en `agent_runs` qué datos se enviaron (auditoría).

**Criterio de aceptación:**
- WI con PR y commits del día → se muestra la propuesta de Copilot; al publicar, el comentario aparece en Azure DevOps una sola vez.
- WI sin commits hoy → se abre el modal de comentario manual.
- El lote y la ejecución programada dejan todo en la bandeja; nada se publica sin revisión.

---

## Fase 9 — Calidad, pruebas y despliegue

1. **Tests backend** (PHPUnit, `php artisan test`):
   - `Http::fake()` / mock de Guzzle para `AzureDevOpsService` (convertir el cliente a inyectable o usar el facade `Http` de Laravel, que facilita los fakes).
   - Feature tests: comentarios, cambio de estado (incluye error 422 de Azure), CRUD de definiciones, guardar valor del checklist con validación por `data_type`, `ready_to_deploy`, idempotencia de `agent_runs`.
   - Unit tests: escape de WIQL, parser de artifact links (ambos formatos), cálculo de "hoy" en zona horaria.
2. **Tests frontend** (opcional): Vitest + Vue Test Utils para `ChecklistValueModal` (input por tipo de dato) y filtros.
3. `vendor/bin/pint` y `pnpm build` (type-check) sin errores antes de cada merge.
4. **Autenticación** (recomendado antes de exponer la app fuera de la red): Sanctum SPA o al menos un token por `.env`, porque la API puede escribir en Azure DevOps con el PAT.
5. Despliegue:
   - Backend: `composer install --no-dev`, `php artisan config:cache route:cache`, `migrate --force`, worker de colas y cron del scheduler (Fase 8).
   - Frontend: `pnpm build` con `VITE_API_URL` del entorno; servir `dist/` con fallback a `index.html` (por `createWebHistory`).
   - Base de datos: migrar de SQLite a **Postgres** como recomienda `Roadmap_checklist_alistamiento_y_agentes.md` (escrituras concurrentes del agente, webhooks y usuarios).
6. Actualizar la colección de Postman (`azure-dashboard-api.postman_collection.json`) con los endpoints nuevos.

---

## Resumen de endpoints nuevos

| Método | Ruta | Fase |
|---|---|---|
| POST | `/api/work-items/{id}/comments` | 1 |
| GET | `/api/work-item-types` | 6 |
| GET | `/api/work-item-types/{type}/states` | 2 |
| PATCH | `/api/work-items/{id}/state` | 2 |
| GET/POST/PUT/DELETE | `/api/checklist-definitions[/{id}]` | 4 |
| PATCH | `/api/checklist-definitions/reorder` | 4 |
| GET | `/api/checklist-definitions/data-types` | 4 |
| GET | `/api/work-items/{id}/checklist` | 5 |
| PUT | `/api/work-items/{id}/checklist/{definitionId}` | 5 |
| GET | `/api/users?search=` | 6 |
| GET | `/api/me` | 7 |
| POST | `/api/work-items/{id}/agent/daily-comment` | 8 |
| GET | `/api/agent-approvals?status=Pendiente` | 8 |
| POST | `/api/agent-approvals/{id}/approve` · `/reject` · `/regenerate` | 8 |
| POST | `/api/agent/daily-comment/batch` | 8 |
| GET | `/api/agent-runs` | 8 |

---

## Preguntas abiertas (las respondidas están en "Decisiones confirmadas")

1. **Estado y alistamiento:** se interpretó que cada item del alistamiento pertenece a un estado del WI (ej. "Minutograma" se exige en *In Review*). ¿Es correcto? ¿Al cambiar de estado con items bloqueantes pendientes solo se **advierte** (propuesta actual) o se **impide** el cambio?
2. **Proceso de Azure DevOps:** los 6 estados (New, To Do, In Progress, In Review, Done, Removed) no son todos del mismo tipo de WI en los procesos estándar (*In Review* suele ser un estado personalizado). ¿El proyecto usa un proceso heredado con esos estados? El plan los lee dinámicamente de Azure, pero hay que confirmarlo para el seeder.
3. **Hora de la ejecución programada:** ¿a qué hora y qué días? (propuesta: lunes a viernes 17:30, `America/Bogota`).
4. **Copilot Studio:** ¿quién administra el entorno de Power Platform y puede crear el agente y entregar el secreto de Direct Line? ¿El servidor donde corre Laravel tiene salida a internet?
5. **Base de datos:** ¿hay datos en las tablas de alistamiento que conservar, o se puede hacer `migrate:fresh`?
6. **Multiusuario:** se pueden buscar WIs de otros usuarios, pero los comentarios y cambios de estado salen a nombre del **dueño del PAT**. ¿Es aceptable?

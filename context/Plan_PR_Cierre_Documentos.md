# Plan: PRs de work items cerrados, comentario de cierre y documentos de SharePoint

> Estado: **implementado** (2026-11-04). Links existentes: botón "Sincronizar links" en el alistamiento del WI.
> Fecha: 2026-11-04

## 0. Diagnóstico (código actual)

| Punto | Qué pasa hoy | Dónde |
|---|---|---|
| Enlazar PR | El modal solo lista PRs **activos** (`searchCriteria.status=active`). Un WI en Done casi siempre tiene su PR ya *completed*, así que no aparece y no se puede enlazar. | `AzureDevOpsService::getActivePullRequests()`, `LinkBranchModal.vue` |
| Detectar el PR | No hay sugerencias: el usuario tiene que saber repo + PR. Aunque el WI tenga la rama enlazada, no se busca el PR de esa rama. | — |
| Comentarios | El agente solo procesa WIs abiertos (`getOpenWorkItemIds` excluye Done/Closed/Removed) y solo mira commits **del día**. Un WI que pasó a Done sin comentarios no recibe nada. | `AgentCommentService::startBatch()`, `DailyProgressCollector` |
| Datos del PR | `getPullRequest()` no devuelve `closedDate`, `closedBy` ni la fecha de merge. | `AzureDevOpsService::getPullRequest()` |
| Documentos | Los `sharepoint_link` del alistamiento solo se guardan en Postgres (`checklist_status.value`); en Azure DevOps no queda rastro. | `WorkItemChecklistController::update()` |

---

## 1. PRs: poder enlazar PRs cerrados y detectar los que faltan

### Backend
1. `AzureDevOpsService::getPullRequests(string $repositoryId, string $status = 'active', ?string $search = null, int $top = 50)`
   - `searchCriteria.status` = `active | completed | abandoned | all`.
   - Para `completed/all` ordenar por `closedDate` desc y limitar con `$top` (los repos con historia larga devuelven miles).
   - `search` filtra en PHP por título, rama origen o id (la API no tiene búsqueda por texto).
   - Mantener `getActivePullRequests()` como alias para no romper nada.
2. `GET /repositories/{repositoryId}/pull-requests?status=all&search=` (validar `status` con `in:`).
3. **Sugerencias** `GET /work-items/{id}/pull-request-suggestions`, en este orden de confianza:
   1. PRs cuyo `sourceRefName` es una **rama enlazada** al WI (`searchCriteria.sourceRefName=refs/heads/{rama}`, `status=all`) → coincidencia exacta.
   2. PRs del repo de la rama cuyo título o rama contienen `#{id}`, `AB#{id}` o `/{id}-` / `-{id}`.
   - Excluir los PRs ya enlazados (`parseArtifactLinks`). Devolver `match_reason` para mostrarlo.
4. `getPullRequest()` agrega `closedDate`, `closedBy`, `mergeStatus` y `completionDate` (= `closedDate` si `status=completed`).
5. Enlazar sigue igual (`POST /work-items/{id}/link-pr`, ya protegido por `work-item.owner` + `audit`): Azure acepta el ArtifactLink de un PR completado igual que de uno activo.

### Frontend
1. `LinkBranchModal.vue`: selector de estado del PR (Activos / Completados / Todos) + buscador; mostrar estado y fecha de cierre en cada opción.
2. Bloque "PRs sugeridos" arriba del modal con botón **Enlazar** por sugerencia (un click).
3. `WorkItemView.vue`: si el estado es de categoría *Completed* y no hay PR enlazado → aviso "Este work item está cerrado y no tiene PR asociado" con acceso directo al modal.
4. Dashboard (opcional): filtro rápido "Cerrados sin PR / sin comentario de cierre" para encontrar el rezago. Requiere leer relaciones de cada WI de la página (ya vienen en `getWorkItemsDetails` si se pide `$expand=relations`) → se calcula solo sobre la página visible para no multiplicar llamadas.

### Tests
- `searchCriteria.status` correcto según el parámetro; filtro `search`.
- Sugerencias: match por rama enlazada, por `AB#id`, y exclusión de PRs ya enlazados.

---

## 2. Comentario de cierre (manual, solo cuando el WI está en Done)

### Caso que resuelve
Work items que ya están en Done, **sin comentarios y sin PR asociado**. El usuario los revisa uno a uno y, desde el detalle del WI, genera los comentarios de cierre. **No hay procesamiento masivo**: ni comando, ni lote diario, ni programación.

### Flujo (desde el detalle del WI)
1. El botón **"Generar comentario de cierre"** solo aparece si el estado pertenece a la categoría **`Completed`** de su tipo (se lee de `getWorkItemTypeStates()`; en Scrum es `Done`, no se hardcodea).
2. Se abre un modal que **pregunta el repositorio y el/los PR**:
   - Arriba, los PRs ya enlazados al WI (si los hay) y las sugerencias de la sección 1.
   - Selector de repositorio → lista de PRs **completados** del repo con buscador (título, rama, id).
   - Se pueden marcar varios PRs.
3. Al confirmar, por cada PR elegido:
   - Si no está enlazado al WI, se enlaza (`link-pr`, ya protegido por `work-item.owner` + `audit`).
   - Se genera **una propuesta de comentario por PR** (`closure_comment`) con fechas + resumen técnico.
4. Las propuestas quedan en `Pendiente` y se revisan / editan / aprueban como las del agente diario (modal de revisión y bandeja `/agentes`). **Nada se publica sin aprobar.**
5. Si el WI no tiene PR y el usuario no elige ninguno, no se genera nada: el modal exige seleccionar al menos un PR.

### Contenido del comentario (uno por PR)
```
**🤖 Resumen de cierre — PR !123 Título del PR**

**Fechas**
- Work item: creado 02/10/2026 · inicio 05/10/2026 · cerrado 20/10/2026
- PR: creado 08/10/2026 · completado 19/10/2026 (`feature/123-x` → `develop`, repo `api`)
- Commits: 14, del 06/10/2026 al 18/10/2026

**Resumen técnico**
- (viñetas generadas por Copilot Studio a partir de los commits y archivos del PR)

**Archivos / áreas modificadas:** `app/...`, `src/...` y 12 más
```

Fuentes de las fechas:
| Dato | Origen en Azure |
|---|---|
| Creado | `System.CreatedDate` |
| Inicio | `Microsoft.VSTS.Common.ActivatedDate` (si no existe: primera revisión que pasa a categoría *InProgress* en `updates`) |
| Cierre | `Microsoft.VSTS.Common.ClosedDate` (si no existe: revisión que pasa a categoría *Completed*) |
| PR | `creationDate`, `closedDate`, ramas, repositorio |
| Commits | todos los commits del PR (sin filtro de fecha, sin merges) |
| Archivos | cambios de la última iteración del PR (`pullRequests/{id}/iterations/{n}/changes`): el diff total del PR |

Las **fechas las arma la app**; la IA solo redacta el resumen técnico (evita fechas inventadas).

### Backend
1. `AzureDevOpsService`
   - `getPullRequestCommits($repo, $prId)` (todos, reutilizando el filtro de merges de `filterCommitsSince`).
   - `getPullRequestChangedFiles($repo, $prId, $limit)` vía iteraciones.
   - `getPullRequest()` agrega `closedDate`.
2. `app/Services/Agents/ClosureSummaryCollector.php` → DTO `ClosureSummary` (work item + fechas + **un** PR + commits + archivos). Determinista, sin IA.
3. `CommentGenerator`: agregar `generateClosure(ClosureSummary): string`.
   - `CopilotStudioCommentGenerator`: prompt propio ("resumen técnico de lo realizado en el PR, máx. 10 viñetas, no inventes…").
   - `TemplateCommentGenerator`: fallback con la lista de commits.
4. `AgentApproval::ACTION_CLOSURE_COMMENT = 'closure_comment'`; el payload guarda `repositoryId` + `pullRequestId`.
5. `AgentCommentService`
   - `proposeClosure(int $workItemId, array $pullRequests, User $owner)`: valida estado *Completed* (si no → 422 `not_completed`), que haya al menos un PR (422 `pull_request_required`), enlaza los que falten y crea una propuesta por PR. Reemplaza una propuesta de cierre pendiente **del mismo PR**, no las de otros PRs.
   - `approve()`: el cierre se publica **una vez por WI + PR** (si ya existe → 409 `already_published`, salvo `force`). No aplica la regla de "una vez por día".
   - `expireStale()` **no** vence las propuestas de cierre.
   - `regenerate()` acepta `closure_comment`.
   - `startBatch()` y la programación **no cambian** (siguen solo con WIs abiertos).
6. Ruta `POST /work-items/{id}/agent/closure-comment` (body: `pull_requests: [{repositoryId, pullRequestId}]`) dentro del grupo con `work-item.owner` + `audit`.

### Frontend
- `ClosureCommentModal.vue`: repositorio → PRs completados (multi-selección), PRs enlazados y sugerencias arriba.
- `WorkItemView.vue`: botón "Generar comentario de cierre" solo en categoría *Completed*; al terminar abre la revisión de las propuestas creadas.
- `agentService.ts`: tipos de `closure_comment` y su `context`.
- `AgentInboxView.vue` / `AgentCommentReviewModal.vue`: etiqueta "Cierre · PR !123" y las fechas.

### Tests
- WI en Done + 2 PRs elegidos → 2 propuestas `closure_comment`, se enlazan los que faltaban.
- WI no Done → 422 `not_completed`. Sin PRs → 422 `pull_request_required`.
- No publica dos veces el cierre del mismo PR; no vence en `expireStale()`.
- Copilot falla → plantilla. `AuthorizationTest` cubre la ruta nueva.

---

## 3. Documentos de SharePoint en el work item: factibilidad

El PAT de Azure DevOps **no da acceso a SharePoint**. Para subir el archivo real hay que descargarlo de SharePoint, y eso requiere Microsoft Graph con otra credencial. Hay tres caminos:

| Opción | Cómo queda en Azure | Requisitos | Factibilidad |
|---|---|---|---|
| **A. Hyperlink en el WI** (elegida) | Pestaña *Links* del WI: "Manual Técnico → https://empresa.sharepoint.com/…" | Solo el PAT actual (Work Items Read & Write) | **Alta**, se hace ya |
| **B. Adjunto copiado desde SharePoint** | Pestaña *Attachments* con el `.docx` | App registrada en Entra ID con permiso Graph (`Sites.Selected` o `Files.Read.All`) aprobada por TI, o login OAuth del usuario con Microsoft | **Media/baja**: depende de TI |
| **C. Subir el archivo desde la app** | *Attachments* + se conserva el link de SharePoint en el alistamiento | Solo el PAT actual; el archivo pasa por Laravel en streaming hacia Azure (no se guarda en el servidor) | **Alta**, pero cambia la decisión #2 de `Plan_Finalizacion_Aplicacion.md` ("no se suben archivos") |

### Opción A (detalle)
- Al guardar un valor `sharepoint_link` en `WorkItemChecklistController::update()` (ya pasa por `work-item.owner` + `audit`):
  `PATCH wit/workitems/{id}` con `{"op":"add","path":"/relations/-","value":{"rel":"Hyperlink","url":"<link>","attributes":{"comment":"<nombre del item del alistamiento>"}}}`.
- Si el valor cambia: quitar el hyperlink anterior (`op: remove`, `path: /relations/{índice}`, buscando el índice por URL) y agregar el nuevo. Si se borra el valor, quitarlo.
- Si Azure responde "Relation already exists" → tratar como éxito (igual que con los PRs).
- Si falla el enlace en Azure, el valor se guarda igual y se devuelve una advertencia (no bloquear el alistamiento).
- Ventajas: el documento sigue siendo uno solo en SharePoint (versiones y permisos de SharePoint), sin límites de tamaño.

### Opción B/C (detalle técnico, si se necesita el archivo físico)
1. `POST _apis/wit/attachments?fileName=<nombre>&api-version=7.1` con el binario (`application/octet-stream`) → devuelve `url`.
   Archivos grandes: subida por partes (`uploadType=Chunked`). Verificar el límite de tamaño de adjuntos de la organización.
2. `PATCH wit/workitems/{id}` con `{"rel":"AttachedFile","url":"<url>","attributes":{"comment":"…"}}`.
3. B agrega antes: `GET https://graph.microsoft.com/v1.0/shares/{shareId}/driveItem/content` (el `shareId` se arma desde el link de SharePoint en base64url). Config nueva `config/sharepoint.php` (tenant, client id/secret), como ya propone `Propuesta_Generador_Documentos.md`.
- Desventaja de B y C: el adjunto es una **copia** que queda desactualizada si el documento cambia en SharePoint.

---

## 4. Decisiones tomadas (2026-11-04)

1. **Documentos:** opción **A** (Hyperlink en el WI). B y C quedan descartadas por ahora.
2. **Cierre:** manual desde el detalle del WI, solo en categoría *Completed*; se pregunta repositorio y PR; **un comentario por PR**.
3. **Sin PR:** se exige elegir el PR antes de generar.
4. **Rezago histórico:** no se procesa automáticamente; el usuario revisa y genera uno a uno.

5. **Links ya guardados:** botón "Sincronizar links" en el alistamiento del WI (`POST /work-items/{id}/checklist/sync-links`).

## 5. Orden sugerido

| Fase | Entregable |
|---|---|
| 1 | PRs completados + buscador + sugerencias en el modal de enlace |
| 2 | Comentario de cierre manual (collector, generador, ruta, modal, revisión) |
| 3 | Documentos: Hyperlink de SharePoint en el WI |

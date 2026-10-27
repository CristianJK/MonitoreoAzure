---
id: propuesta-generador-documentos
tags:
  - work
  - IA
  - Azure
  - documentacion
  - propuesta
status: propuesta (sin implementar)
audience: humano + IA (agente de desarrollo)
fecha: 2026-10-26
---

# Propuesta — Generador de documentos (Manual Técnico y Pruebas Unitarias)

> Agregar a MonitoreoAzure un segundo agente de Copilot Studio que redacte la documentación
> técnica de una feature a partir del PR/commits, siguiendo las plantillas de `docs-hub`, y
> que el resultado quede publicado en SharePoint con su link visible en el work item.
>
> Documentos relacionados: `Plan_Finalizacion_Aplicacion.md` (agente de comentarios diarios,
> misma mecánica de aprobación), `Despliegue y seguimiento pasos a produccion.md`.

---

## 1. Idea central

`docs-hub` ya resuelve **qué** se redacta (plantillas + reglas) y **cómo** se renderiza
(Pandoc + Word COM). Lo que falta es el disparador, la orquestación y la publicación. La
propuesta reparte responsabilidades así:

| Pieza | Responsable | Por qué ahí |
|---|---|---|
| Recolectar commits/diff del PR | Laravel (`DocumentContextCollector`) | Ya tenemos `AzureDevOpsService` y `parseArtifactLinks()`; sin IA, determinista |
| Redactar el `.md` | **Agente Copilot Studio nuevo** ("Agente Documentador") | Es la parte que requiere IA; se reutiliza `DirectLineClient` |
| Validar el `.md` | Laravel (`DocumentValidator`) | Estructura, front matter, `{{...}}` sin reemplazar |
| Revisión humana | Dashboard (bandeja `/documentos`) | Misma regla que el agente de comentarios: **nada se publica sin aprobar** |
| Render `.docx` / `.pdf` | Worker Windows (`docs-hub/scripts/render.ps1`) | Word COM no corre en Linux/Sail |
| Subir a SharePoint | Laravel (Microsoft Graph) | Credenciales de app en config, no en el worker |
| Guardar el link | Laravel → `work_item_documents` + comentario en el WI | Decisión 2 del plan: solo se guarda el link, no archivos |

Importante: **Copilot Studio solo redacta Markdown.** No ejecuta Pandoc ni Word; eso lo hace
el worker. El agente no tiene acceso al repo: Laravel le entrega el contexto ya recortado.

---

## 2. Flujo propuesto

```
Dashboard: botón "Generar documentación" en el detalle del WI (o PR completado)
        │  POST /api/work-items/{id}/documents  {type, feature_number, menu_path?}
        ▼
Laravel: DocumentContextCollector
        │  commits + diff base...feature del PR vinculado (límites de tamaño)
        ▼
Job GenerateDocumentDraft  ──► DocumentGenerator (interfaz)
        │                         ├─ CopilotStudioDocumentGenerator (Direct Line)
        │                         └─ TemplateDocumentGenerator (esqueleto con [POR CONFIRMAR])
        ▼
DocumentValidator → document_drafts (estado: Borrador)
        ▼
Usuario revisa/edita el .md en /documentos  →  Aprobar
        ▼
Job RenderDocument → Worker Windows (cola) → .docx + .pdf
        ▼
Job PublishDocument → Microsoft Graph → SharePoint (webUrl)
        ▼
work_item_documents (link) + comentario en el WI/PR con los enlaces y pendientes
```

Estados de un borrador: `Generando → Borrador → Aprobado → Renderizando → Publicado`
(más `Error` con mensaje y reintento). Mismo patrón que `agent_approvals` / `agent_runs`.

---

## 3. El agente de Copilot Studio

Crear un agente **separado** del de comentarios (otro bot, otro secret de Direct Line): el
prompt, el tamaño de contexto y la salida esperada son muy distintos.

### 3.1 Configuración sugerida

- **Instrucciones (system prompt):** pegar el contenido de `GENERAR_DOCUMENTACION.md` (reglas
  de redacción, tono, estructura) y la sección de cada plantilla.
- **Conocimiento (Knowledge):** subir las plantillas de `docs-automation/templates/*.md` y
  `ListDocs.md` como fuentes. Alternativa más controlable: que Laravel envíe la plantilla en
  cada petición (ver 3.2) y el agente no dependa de su base de conocimiento.
- **Sin acciones/herramientas externas:** el agente recibe texto y devuelve texto. Menos
  superficie, más fácil de probar.
- **Autenticación:** "Sin autenticación" + canal Direct Line con secret, igual que el agente
  actual.

### 3.2 Contrato de entrada/salida

Direct Line limita el tamaño del mensaje, y un diff completo puede ser enorme. Recomendación:
**enviar el contexto en varios mensajes** (o resumido por archivo) y una instrucción final.

Entrada (JSON dentro del texto del mensaje):

```json
{
  "tipo_documento": "manual-tecnico",
  "feature": { "numero": "430803", "titulo": "..." },
  "fecha": "26/10/2026",
  "plantilla": "<markdown de la plantilla>",
  "commits": [{ "sha": "abc1234", "mensaje": "...", "autor": "..." }],
  "archivos": [
    { "ruta": "app/Services/X.php", "estado": "modificado", "diff": "<recortado>" }
  ],
  "datos_conocidos": { "ruta_menu": null }
}
```

Salida exigida: **solo el Markdown final**, con front matter YAML:

```yaml
---
documento: manual-tecnico        # o pruebas-unitarias
procedimiento: "Feature 430803 – Título"
fecha: "26/10/2026"              # solo pruebas unitarias
---
```

Reglas en el prompt: no inventar datos; lo que no se deduzca del diff va como
`[POR CONFIRMAR: ...]`; las capturas como `[INSERTAR CAPTURA: ...]`; no dejar `{{...}}`.

### 3.3 Cómo se integra en Laravel

Reutilizar la mecánica existente en `app/Services/Agents/`:

- `DirectLineClient` ya habla con Direct Line; parametrizarlo con el secret del agente
  documentador (`config('agents.documents.copilot_studio.directline_secret')`).
- Nueva interfaz `DocumentGenerator` con dos implementaciones, igual que `CommentGenerator`:
  - `CopilotStudioDocumentGenerator`
  - `TemplateDocumentGenerator` (respaldo: esqueleto con todo `[POR CONFIRMAR]`, marcado
    como "generado sin IA")
- Nuevo bloque en `config/agents.php`:

```php
'documents' => [
    'generator' => env('AGENT_DOCUMENT_GENERATOR', 'copilot_studio'),
    'max_diff_chars' => 60000,
    'max_files' => 40,
    'copilot_studio' => [
        'directline_url' => env('COPILOT_DOCS_DIRECTLINE_URL', 'https://directline.botframework.com/v3/directline'),
        'directline_secret' => env('COPILOT_DOCS_DIRECTLINE_SECRET'),
        'timeout' => (int) env('COPILOT_DOCS_TIMEOUT', 120), // documentos largos
    ],
],
```

El timeout debe ser mayor que el del comentario diario (30 s) porque un manual es largo; la
generación corre siempre en cola, nunca en la petición HTTP.

---

## 4. Render: la parte que no cabe en Sail

`render.ps1` depende de Word COM → necesita Windows. Opciones, de menor a mayor esfuerzo:

| Opción | Cómo | Cuándo elegirla |
|---|---|---|
| **A. Render manual asistido (MVP)** | La app guarda el `.md` aprobado y ofrece "Descargar .md"; el usuario corre `render.ps1` en su máquina y sube el resultado | Para validar el agente antes de invertir en infraestructura |
| **B. Worker Windows con polling** | Servicio/tarea en un Windows con Word+Pandoc que consulta `GET /api/documents/pending-render`, renderiza y sube con `POST /api/documents/{id}/artifacts` | Opción recomendada para producción |
| **C. Agente self-hosted de Azure Pipelines** | Pipeline que ejecuta `render.ps1` y publica el artefacto | Si ya existe un agente Windows con Word |
| **D. LibreOffice + Pandoc en contenedor** | Render en Linux | Solo si se acepta cambiar el formato corporativo; requiere validar el resultado |

Para B: el worker se autentica con un token propio (no el PAT), procesa **un render a la vez**
y reutiliza las salvaguardas ya descritas en `docs-hub` (`DisplayAlerts = 0`, limpieza de
`~$*.docx`, control de procesos `WINWORD.EXE`). El worker hace *pull*, así no hay que exponer
el Windows hacia internet.

---

## 5. Publicación en SharePoint

Microsoft Graph con credenciales de aplicación (Entra ID) y permiso `Sites.Selected` sobre el
sitio destino. Configuración nueva en `config/sharepoint.php` (nunca `env()` fuera de config):

```
SHAREPOINT_TENANT_ID, SHAREPOINT_CLIENT_ID, SHAREPOINT_CLIENT_SECRET,
SHAREPOINT_SITE_ID, SHAREPOINT_DRIVE_ID, SHAREPOINT_BASE_PATH=Documentacion/Features
```

Servicio `SharePointService` siguiendo la convención del proyecto: **los métodos públicos no
lanzan excepciones**, devuelven `['error' => msg, 'status' => http]`.

- Ruta estable: `Documentacion/Features/Feature_<n>/<nombre>.docx|.pdf|.md`.
- Archivos > 4 MB: `createUploadSession`.
- Versionado: si ya existe, crear `V2`, `V3`… ; nunca sobrescribir un documento aprobado.
- Guardar el `.md` junto al `.docx` para poder regenerar o editar después.
- Devolver `webUrl` y guardarlo en `work_item_documents`.

---

## 6. Modelo de datos (Postgres)

```
document_drafts
  id, work_item_id, tipo (manual-tecnico | pruebas-unitarias), feature_number,
  version, estado, markdown (text), generado_con_ia (bool),
  pendientes (json: marcadores [POR CONFIRMAR]/[INSERTAR CAPTURA] detectados),
  contexto_hash (sha del diff usado), error, approved_by, approved_at, timestamps

work_item_documents          -- solo links, coherente con la decisión 2 del plan
  id, work_item_id, draft_id, tipo, version, formato (docx|pdf|md),
  sharepoint_url, published_at
```

`contexto_hash` da **idempotencia**: si el PR se actualiza y el hash cambia, se ofrece
"regenerar como V2"; si no cambió, no se vuelve a llamar a la IA.

---

## 7. API y UI

### Endpoints

| Método | Ruta | Uso |
|---|---|---|
| POST | `/api/work-items/{id}/documents` | Encola la generación de un borrador |
| GET | `/api/work-items/{id}/documents` | Borradores y documentos publicados del WI |
| GET | `/api/documents?estado=Borrador` | Bandeja de revisión |
| PUT | `/api/documents/{id}` | Editar el markdown antes de aprobar |
| POST | `/api/documents/{id}/approve` | Aprueba → encola render + publicación |
| POST | `/api/documents/{id}/retry` | Reintenta un paso en `Error` |
| GET | `/api/documents/pending-render` | Cola para el worker (token propio) |
| POST | `/api/documents/{id}/artifacts` | El worker entrega `.docx`/`.pdf` |

Los controladores usan `$this->azureError($result)` para errores de Azure/Graph, igual que el
resto de la API.

### Frontend

- Botón **"Generar documentación"** en `/work-items/:id` (elige tipo; para Pruebas Unitarias
  pide la **ruta del menú**, que no está en el código).
- Nueva vista `/documentos` (bandeja, como `/agentes`): editor de markdown con vista previa
  (render con `useSanitize` antes de `v-html`), lista de `[POR CONFIRMAR]` pendientes,
  botón Aprobar.
- En el detalle del WI: sección "Documentos" con los links de SharePoint por versión.
- Servicio `documentService.ts` con tipos que reflejen los recursos de la API.

---

## 8. Decisiones abiertas

1. **¿Dónde está el diff?** Hoy `DailyProgressCollector` ya trae commits de PRs/ramas; falta
   confirmar si basta con ese contenido o hay que pedir el diff por archivo a Azure (endpoint
   `diffs/commits`) y definir los límites de recorte.
2. **¿Número de feature?** Derivarlo del nombre de rama, título del PR o WI padre; si falla,
   `[POR CONFIRMAR]`. Definir la convención que se va a exigir.
3. **¿Quién aprueba?** ¿Basta con el dueño del WI o se exige un revisor distinto?
4. **¿Disparador automático?** Empezar solo con botón manual. Un Service Hook de PR
   (`updated`) puede venir después, con cuidado de no regenerar en cada push.
5. **Worker Windows:** ¿hay máquina con Word licenciado disponible? Define si se va a la
   opción A o B de la sección 4.
6. **Capturas:** el sistema no puede tomarlas. Se propone dejarlas como marcadores y que se
   completen en el `.docx` ya publicado (SharePoint conserva el historial de versiones).
7. **Copilot Studio y datos del código:** confirmar que está permitido enviar fragmentos de
   diff al servicio según la política de la organización.
8. **Secretos:** `COPILOT_DOCS_DIRECTLINE_SECRET` y credenciales de Graph en variables
   protegidas / Key Vault; nunca en el repo.

---

## 9. Fases sugeridas

| Fase | Entregable | Valida |
|---|---|---|
| 1 | Agente Copilot + `DocumentContextCollector` + `DocumentGenerator` + `DocumentValidator`; el `.md` se descarga desde la app (render manual, opción A) | Calidad de la redacción con 3–5 features reales |
| 2 | Tablas, endpoints, bandeja `/documentos`, edición y aprobación | Flujo de revisión |
| 3 | Worker Windows (opción B) y render automático | Formato `.docx`/`.pdf` idéntico al de `docs-hub` |
| 4 | `SharePointService`, versionado y links en el WI | Publicación sin intervención manual |
| 5 | Comentario en el WI/PR con enlaces y pendientes; reintentos, alertas, retención | Cierre del ciclo y operación |

Recomendación: **terminar la fase 1 antes de invertir en el worker o en SharePoint.** La
incógnita real es si el agente redacta documentos aceptables con solo el diff; todo lo demás
es plomería conocida.

---

## 10. Pruebas

Siguiendo la regla del proyecto, los tests del backend **nunca** llaman a servicios externos:
`Http::preventStrayRequests()` + `Http::fake()` para Direct Line, Graph y Azure DevOps.

- `DocumentValidatorTest`: front matter válido, `{{...}}` sin reemplazar, marcadores detectados.
- `DocumentGeneratorTest`: fallback a plantilla si Copilot falla (igual que `CommentGenerator`).
- `DocumentApprovalTest`: no se publica sin aprobar; no se sobrescribe una versión aprobada.
- `SharePointServiceTest`: ruta, versionado y sesión de carga con respuestas simuladas.

---
id: plan-implementacion-faltantes
tags:
  - work
  - IA
  - Azure
  - roadmap
  - plan
status: plan de acción basado en "Roadmap_checklist_alistamiento_y_agentes.md"
audience: humano + IA (agente de desarrollo)
---

# Plan de Implementación: Pasos Faltantes

> Este documento continuationa los tres documentos de contexto existentes y define
> concretamente qué falta por implementar, con un orden de ejecución y dependencias.

---

## Estado Actual del Proyecto

### Backend (Laravel API - `azure-dashboard-api/`)
- **Implementado:**
  - Consulta de Work Items de Azure DevOps vía WIQL (paginado, con resumen por estado)
  - Listado de Repositorios, Branches y Pull Requests activos
  - Listado de Agentes de build
  - Vinculación de Branch a Work Item (endpoint funcional)
  - Vinculación de Pull Request a Work Item (endpoint funcional)
  - Docker Compose con MySQL, Redis, Meilisearch, Mailpit, Selenium
  - Laravel Sanctum instalado (pero no utilizado efectivamente)

- **Faltante:**
  - Base de datos propia (checklists, metadatos, aprobaciones, documentos)
  - Migraciones y modelos Eloquent para el dominio
  - Endpoints de checklist y calendario
  - Autenticación real en la API
  - Tests unitarios y de feature
  - Capa de persistencia/cache

### Frontend (Vue 3 - `azure-dashboard-web/`)
- **Implementado:**
  - Dashboard con lista de Work Items y paginación
  - Tarjetas de resumen (Done, In Progress, To Do)
  - Modal de vinculación de Pull Requests
  - Layout con sidebar y navbar
  - Estilos con Tailwind CSS v4 y tema oscuro

- **Faltante:**
  - Vue Router (navegación entre vistas)
  - Pinia (gestión de estado global)
  - Vista de detalle de Work Item con checklist
  - Vista de calendario
  - Autenticación/login
  - Filtros funcionales (botón existe pero no hace nada)
  - Tests

### Skills Instaladas
| Backend (8 skills) | Frontend (11 skills) |
|---|---|
| laravel-specialist | vue |
| php-pro | vue-best-practices |
| laravel-patterns | vue-debug-guides |
| vite | vite |
| tailwind-css-patterns | tailwind-css-patterns |
| accessibility | accessibility |
| frontend-design | frontend-design |
| seo | seo |
| — | typescript-advanced-types |
| — | nodejs-backend-patterns* |
| — | nodejs-best-practices* |

> *Las skills de Node.js son irrelevantes para un proyecto Vue puro y deben eliminarse.

---

## FASE 1: Base de Datos y Modelo de Datos

> Corresponde a los Pasos 10.1, 10.2, 10.3, 11 y 12 del Roadmap.
> **Esta es la fase bloqueante para todo lo demás.**

### 1.1 Configurar PostgreSQL
- Cambiar `DB_CONNECTION=mysql` por `DB_CONNECTION=pgsql` en `.env`
- Actualizar `compose.yaml`: reemplazar servicio `mysql` por PostgreSQL
- Crear archivo `.env.example` completo con todas las variables de Azure DevOps y base de datos

**Archivos a modificar:**
- `azure-dashboard-api/.env`
- `azure-dashboard-api/.env.example`
- `azure-dashboard-api/compose.yaml`

### 1.2 Crear Migraciones Laravel (6 tablas)

```sql
-- Tabla 1: Metadatos adicionales de Work Items
work_item_metadata (
  id                  bigint primary key auto_increment,
  azure_work_item_id  bigint unique not null,
  work_item_type      varchar(50),
  estimated_delivery_date  date nullable,
  possible_pap_date        date nullable,
  ready_to_deploy     boolean default false,
  created_at          timestamp,
  updated_at          timestamp
)

-- Tabla 2: Catálogo de ítems de checklist
checklist_definitions (
  id                  bigint primary key auto_increment,
  code                varchar(50) unique not null,
  label               varchar(255) not null,
  blocking            boolean default true,
  applies_to_type     varchar(50) nullable,
  created_at          timestamp,
  updated_at          timestamp
)

-- Tabla 3: Estado de cada ítem por Work Item
checklist_status (
  id                        bigint primary key auto_increment,
  work_item_metadata_id     bigint foreign key references work_item_metadata,
  checklist_definition_id   bigint foreign key references checklist_definitions,
  status                    enum('pending','in_progress','done','failed','skipped'),
  checked_by                varchar(100),
  checked_at                timestamp nullable,
  evidence                  json nullable,
  created_at                timestamp,
  updated_at                timestamp,
  unique(work_item_metadata_id, checklist_definition_id)
)

-- Tabla 4: Aprobaciones de agentes IA
agent_approvals (
  id                      bigint primary key auto_increment,
  work_item_metadata_id   bigint foreign key references work_item_metadata,
  action_code             varchar(100) not null,
  status                  varchar(20) default 'Pendiente',
  requested_by            varchar(100),
  resolved_by             varchar(100) nullable,
  created_at              timestamp,
  resolved_at             timestamp nullable
)

-- Tabla 5: Documentos adjuntos
documents (
  id                      bigint primary key auto_increment,
  work_item_metadata_id   bigint nullable foreign key references work_item_metadata,
  document_type_id        bigint foreign key references document_types,
  storage_path            varchar(500) not null,
  mime_type               varchar(100),
  size_bytes              bigint,
  checksum                varchar(255),
  created_by              varchar(100),
  created_at              timestamp
)

-- Tabla 6: Tipos de documento
document_types (
  id        bigint primary key auto_increment,
  code      varchar(50) unique not null,
  label     varchar(255) not null
)
```

**Índice obligatorio:** `checklist_status(work_item_metadata_id, checklist_definition_id)`

**Archivos a crear:**
- `azure-dashboard-api/database/migrations/xxxx_create_work_item_metadata_table.php`
- `azure-dashboard-api/database/migrations/xxxx_create_checklist_definitions_table.php`
- `azure-dashboard-api/database/migrations/xxxx_create_checklist_status_table.php`
- `azure-dashboard-api/database/migrations/xxxx_create_agent_approvals_table.php`
- `azure-dashboard-api/database/migrations/xxxx_create_documents_table.php`
- `azure-dashboard-api/database/migrations/xxxx_create_document_types_table.php`

### 1.3 Crear Modelos Eloquent

**Archivos a crear en `app/Models/`:**
- `WorkItemMetadata.php` - hasMany ChecklistStatus, hasMany AgentApprovals, hasMany Documents
- `ChecklistDefinition.php` - hasMany ChecklistStatus
- `ChecklistStatus.php` - belongsTo WorkItemMetadata, belongsTo ChecklistDefinition
- `AgentApproval.php` - belongsTo WorkItemMetadata
- `Document.php` - belongsTo WorkItemMetadata, belongsTo DocumentType
- `DocumentType.php` - hasMany Documents

### 1.4 Seeder para Checklist Definitions

**Archivo a crear:** `azure-dashboard-api/database/seeders/ChecklistDefinitionSeeder.php`

```php
// Catálogo mínimo del Paso 11 del Roadmap:
['code' => 'branch_created',     'label' => 'Rama creada y vinculada',           'blocking' => true],
['code' => 'pr_linked',          'label' => 'Pull Request vinculado',             'blocking' => true],
['code' => 'code_review_ai',     'label' => 'Revisión de código IA aprobada',     'blocking' => true],
['code' => 'unit_tests_passed',  'label' => 'Pruebas unitarias en verde',         'blocking' => true],
['code' => 'technical_doc',      'label' => 'Manual técnico generado',            'blocking' => false],
['code' => 'security_check',     'label' => 'Validación de seguridad aprobada',   'blocking' => true],
['code' => 'wo_request',         'label' => 'Solicitud de WO generada',           'blocking' => true],
['code' => 'manual_approval',    'label' => 'Aprobación manual en dashboard',     'blocking' => true],
['code' => 'pipeline_ready',     'label' => 'Pipeline de despliegue configurado', 'blocking' => true],
['code' => 'minutograma',        'label' => 'Minutograma generado',               'blocking' => false],
```

---

## FASE 2: Endpoints del Checklist (Paso 13)

### 2.1 Controller y Rutas

**Archivos a crear/modificar:**
- `azure-dashboard-api/app/Http/Controllers/ChecklistController.php`
- `azure-dashboard-api/routes/api.php` (agregar rutas)

```php
// Rutas a agregar:
Route::get('/work-items/{id}/checklist', [ChecklistController::class, 'show']);
Route::post('/work-items/{id}/checklist/{code}', [ChecklistController::class, 'update']);
```

### 2.2 Lógica de Negocio

**Archivo a crear:** `azure-dashboard-api/app/Services/ChecklistService.php`

Responsabilidades:
- `getChecklistForWorkItem($workItemId)` - Devolver checklist completo con estado
- `updateChecklistItem($workItemId, $code, $status, $checkedBy)` - Marcar un ítem
- `calculateReadyToDeploy($workItemId)` - Calcular si está listo para desplegar
- `getPendingItems($workItemId)` - Listar ítems pendientes

### 2.3 Form Request Validation

**Archivos a crear:**
- `azure-dashboard-api/app/Http/Requests/UpdateChecklistRequest.php`

### 2.4 API Resources

**Archivos a crear:**
- `azure-dashboard-api/app/Http/Resources/ChecklistResource.php`
- `azure-dashboard-api/app/Http/Resources/ChecklistItemResource.php`

### 2.5 Reglas de Negocio
- `manual_approval` solo puede ser marcado por humanos (no por `agent:*`)
- `ready_to_deploy` = true solo si todos los ítems con `blocking: true` están en `done`
- Si falta un ítem bloqueante, responder 422 con la lista de pendientes

---

## FASE 3: Vista de Checklist en Frontend

### 3.1 Instalar Dependencias

```bash
cd azure-dashboard-web
pnpm add vue-router pinia
```

**Archivos a crear:**
- `azure-dashboard-web/src/router/index.ts`
- `azure-dashboard-web/src/stores/workItems.ts`

### 3.2 Configurar Router

```typescript
// Rutas:
'/' or '/dashboard'  → DashboardView
'/work-items/:id'    → WorkItemDetailView
'/calendar'          → CalendarView (FASE 4)
'/approvals'         → ApprovalsView (FASE 5)
```

### 3.3 Crear WorkItemDetailView

**Archivos a crear:**
- `azure-dashboard-web/src/views/WorkItemDetailView.vue`
- `azure-dashboard-web/src/components/ChecklistItem.vue`
- `azure-dashboard-web/src/components/ChecklistProgress.vue`
- `azure-dashboard-web/src/services/checklistService.ts`
- `azure-dashboard-web/src/types/checklist.ts`

Contenido:
- Información detallada del Work Item (título, estado, asignado, fechas)
- Lista de checklist con toggle para marcar cada ítem
- Indicador visual de `ready_to_deploy` (verde/rojo)
- Barra de progreso (% de completitud)
- Botón "Solicitar Despliegue" (habilitado solo si ready_to_deploy=true)

### 3.4 Actualizar DashboardView

**Archivo a modificar:** `azure-dashboard-web/src/views/DashboardView.vue`

- Hacer clickeable cada fila → navega a `/work-items/:id`
- Agregar columna de estado del checklist (icono verde/rojo)

### 3.5 Actualizar Sidebar

**Archivo a modificar:** `azure-dashboard-web/src/App.vue`

- Agregar navegación: Dashboard, Calendario, Aprobaciones
- Implementar menú hamburguesa para móvil

---

## FASE 4: Calendario (Paso 14 del Roadmap)

### 4.1 Backend - Endpoint de Calendario

**Archivo a modificar:** `azure-dashboard-api/routes/api.php`

```php
Route::get('/calendar', [CalendarController::class, 'index']);
```

**Archivo a crear:** `azure-dashboard-api/app/Http/Controllers/CalendarController.php`

- Recibe parámetros `from` y `to` (fechas)
- Devuelve Work Items con `estimated_delivery_date` y `possible_pap_date` en el rango
- Incluye estado del checklist y `ready_to_deploy`

### 4.2 Frontend - CalendarView

**Archivos a crear:**
- `azure-dashboard-web/src/views/CalendarView.vue`
- `azure-dashboard-web/src/services/calendarService.ts`

**Dependencia a instalar:**
```bash
pnpm add @fullcalendar/core @fullcalendar/daygrid @fullcalendar/vue3
```

- Vista mensual/semanal con eventos de Work Items
- Colores: verde = ready_to_deploy, amarillo = PAP en 3 días, rojo = PAP vencido
- Click en evento → navega a detalle del Work Item

---

## FASE 5: Seguridad y Autenticación (Crítico)

### 5.1 Backend

**Archivos a modificar:**
- `azure-dashboard-api/routes/api.php` - Agregar middleware `auth:sanctum`
- `azure-dashboard-api/app/Services/AzureDevOpsService.php`
  - Sanear parámetro `$assignedTo` en queries WIQL (riesgo de inyección SQL)
  - Migrar de `env()` a `config()` en el Service
- `azure-dashboard-api/bootstrap/app.php` - Configurar rate limiting

**Archivos a crear:**
- `azure-dashboard-api/app/Http/Controllers/AuthController.php`
- `azure-dashboard-api/app/Http/Requests/LoginRequest.php`

### 5.2 Frontend

**Archivos a crear:**
- `azure-dashboard-web/src/views/LoginView.vue`
- `azure-dashboard-web/src/services/authService.ts`
- `azure-dashboard-web/src/middleware/auth.ts`

**Archivos a modificar:**
- `azure-dashboard-web/src/services/apiClient.ts` - Agregar interceptor de token
- `azure-dashboard-web/src/router/index.ts` - Agregar guard de rutas

### 5.3 Variables de Entorno

**Archivo a crear/actualizar:** `azure-dashboard-api/.env.example`

```
AZURE_DEVOPS_PAT=
AZURE_DEVOPS_ORG=
AZURE_DEVOPS_PROJECT=
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=laravel
DB_USERNAME=sail
DB_PASSWORD=password
```

---

## FASE 6: Limpieza de Código y Calidad

### 6.1 Backend (usando skills laravel-specialist, php-pro, laravel-patterns)

**Archivos a modificar:**
- `azure-dashboard-api/app/Services/AzureDevOpsService.php`
  - Agregar `declare(strict_types=1)`
  - Separar en clases más pequeñas (WorkItemService, RepositoryService, AgentService)
  - Agregar PHPDoc blocks
  - Eliminar mensajes en español, estandarizar a inglés

**Archivos a crear:**
- `azure-dashboard-api/app/Http/Resources/WorkItemResource.php`
- `azure-dashboard-api/app/Http/Resources/RepositoryResource.php`
- `azure-dashboard-api/app/Http/Resources/AgentResource.php`

### 6.2 Frontend (usando skills vue, vue-best-practices, typescript-advanced-types)

**Archivos a eliminar:**
- `azure-dashboard-web/src/components/HelloWorld.vue` (código muerto)

**Archivos a modificar:**
- `azure-dashboard-web/src/services/apiClient.ts`
  - Usar `import.meta.env.VITE_API_BASE_URL` en vez de hardcodear la URL
- `azure-dashboard-web/src/services/repositoryService.ts`
  - Eliminar métodos no usados: `getBranches()`, `linkBranch()`
  - Eliminar interfaz `Branch` no utilizada
- `azure-dashboard-web/src/views/DashboardView.vue`
  - Corregir color `bg-success` que no existe en el theme (definir en style.css)
- `azure-dashboard-web/src/style.css`
  - Agregar color `--color-success` al bloque `@theme`

**Archivos a crear:**
- `azure-dashboard-web/src/types/index.ts` (centralizar tipos)

### 6.3 Eliminar Skills Irrelevantes

**Archivos a eliminar:**
- `azure-dashboard-web/.agents/skills/nodejs-backend-patterns/`
- `azure-dashboard-web/.agents/skills/nodejs-best-practices/`
- Actualizar `azure-dashboard-web/skills-lock.json` removiendo esas entradas

---

## FASE 7: Tests

### 7.1 Backend

**Archivos a crear:**
- `azure-dashboard-api/tests/Feature/ChecklistTest.php`
- `azure-dashboard-api/tests/Feature/WorkItemTest.php`
- `azure-dashboard-api/tests/Unit/ChecklistServiceTest.php`
- `azure-dashboard-api/tests/Unit/AzureDevOpsServiceTest.php`

### 7.2 Frontend

**Dependencia a instalar:**
```bash
pnpm add -D vitest @vue/test-utils @testing-library/vue
```

**Archivos a crear:**
- `azure-dashboard-web/vitest.config.ts`
- `azure-dashboard-web/src/components/__tests__/CardsWorkItem.test.ts`
- `azure-dashboard-web/src/components/__tests__/AppPagination.test.ts`
- `azure-dashboard-web/src/components/__tests__/LinkBranchModal.test.ts`

---

## Orden de Ejecución y Dependencias

```
FASE 1 (Base de Datos) ─────────────┐
                                      ├──→ FASE 2 (Endpoints Checklist) ──→ FASE 3 (Frontend Checklist)
FASE 5 (Seguridad) ──── paralelo ────┘                                      │
                                                                             ├──→ FASE 4 (Calendario)
FASE 6 (Limpieza) ───── paralelo ───────────────────────────────────────────┘
                                                                             │
FASE 7 (Tests) ──────── después de cada fase ───────────────────────────────┘
```

| Paso | Fase | Tiempo Estimado | Dependencias |
|------|------|-----------------|--------------|
| 1 | FASE 1.1 Configurar PostgreSQL | 30 min | Ninguna |
| 2 | FASE 1.2 Crear 6 Migraciones | 2 horas | Paso 1 |
| 3 | FASE 1.3 Crear 6 Modelos | 1 hora | Paso 2 |
| 4 | FASE 1.4 Seeder Checklist | 30 min | Paso 2 |
| 5 | FASE 2.1-2.5 Endpoints Checklist | 3 horas | Paso 3 |
| 6 | FASE 3.1 Instalar dependencias | 15 min | Ninguna |
| 7 | FASE 3.2-3.5 Frontend Checklist | 4 horas | Paso 5, 6 |
| 8 | FASE 5.1 Backend Seguridad | 2 horas | Paralelo |
| 9 | FASE 5.2-5.3 Frontend Auth | 3 horas | Paso 8 |
| 10 | FASE 6 Limpieza | 2 horas | Paralelo |
| 11 | FASE 4 Calendario | 4 horas | Paso 5, 7 |
| 12 | FASE 7 Tests | 4 horas | Después de cada fase |

**Tiempo total estimado: ~26 horas**

---

## Preguntas Abiertas (del Roadmap - Fase 10)

Antes de implementar, definir:

1. ¿Las checklists son iguales para todos los tipos de Work Item (Bug/Feature/Hotfix) o cada tipo tiene su propio catálogo?
2. ¿`code_review_ai` corre sobre el diff completo o solo sobre los archivos tocados desde el último review fallido?
3. ¿El agente de Copilot Studio llama directamente al endpoint `POST /api/work-items/{id}/checklist/{code}` o pasa por validación intermedia?
4. ¿La plantilla de WO se marca como `done` automáticamente al copiar el texto o requiere confirmación manual?

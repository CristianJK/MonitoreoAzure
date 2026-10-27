# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

MonitoreoAzure is a dashboard for tracking Azure DevOps work items and the deployment flow (branch → PR → readiness checklist → production), with an AI agent that drafts daily progress comments. Code, comments, commit messages and UI text are in Spanish.

- `azure-dashboard-api/` — Laravel 13 (PHP ^8.4 via Sail) JSON API that proxies Azure DevOps REST API 7.1 and stores the readiness checklist and agent proposals in Postgres
- `azure-dashboard-web/` — Vue 3 + TypeScript + Vite SPA (Tailwind CSS v4, vue-router, axios, dompurify), managed with **pnpm**
- `context/` — Spanish roadmap/design docs for humans and AI agents, plus a Postman collection. `Plan_Finalizacion_Aplicacion.md` is the current plan (decisions taken and remaining open questions).

## Commands

The host PHP (8.3) is too old for the dependencies: run backend commands inside the Sail container (`azure-dashboard-api-laravel.test-1`, API published on port 8001, Postgres on 5434).

```bash
# Backend (from azure-dashboard-api/)
./vendor/bin/sail up -d
docker exec azure-dashboard-api-laravel.test-1 php artisan test                    # all tests (uses the `testing` Postgres DB)
docker exec azure-dashboard-api-laravel.test-1 php artisan test --filter=ChecklistTest
docker exec azure-dashboard-api-laravel.test-1 vendor/bin/pint <files>            # git isn't available in the container, so --dirty fails
docker exec azure-dashboard-api-laravel.test-1 php artisan migrate --seed
docker exec azure-dashboard-api-laravel.test-1 php artisan queue:work              # needed for the agent batch / scheduled run
docker exec azure-dashboard-api-laravel.test-1 php artisan schedule:work           # local scheduler (prod: cron `schedule:run`)
docker exec azure-dashboard-api-laravel.test-1 php artisan agents:daily-comments <email>   # queue today's proposals for a user (with their PAT)
docker exec azure-dashboard-api-laravel.test-1 php artisan users:revoke <email>          # close sessions and drop the stored PAT

# Frontend (from azure-dashboard-web/)
pnpm install
pnpm dev        # Vite dev server (port 5173)
pnpm build      # vue-tsc -b type-check + vite build — the only type/lint check
```

The frontend has no test suite. Backend tests must never hit Azure: `tests/TestCase.php` sets fake config and calls `Http::preventStrayRequests()`, and provides `fakeWorkItemTypes()` / `workItemResponse()` (assigned to Ana by default) / `identity()` / `basicAuth()` helpers for `Http::fake`, plus `actingAsAzureUser()` (Ana, admin) for authenticated requests.

## Architecture

### Backend

- **Security** (`context/Plan_Seguridad.md`, option B): users log in with their personal Azure DevOps PAT (`POST /api/login`, the only public route), validated against `connectionData` plus one read per scope, stored encrypted in `users.azure_pat`, with a Sanctum SPA session cookie. There is no global PAT. Every other `api/*` route is behind `auth:sanctum` + `azure.pat` (an Azure 401 becomes 401 `azure_pat_invalid` and closes the session); `AuthorizationTest` fails if a route is added without it. Writes on a work item go through `work-item.owner` (`WorkItemOwnership`: `System.AssignedTo` id or uniqueName must match the user, checked live in Azure, 403 `not_owner`) and `audit` (`audit_logs`). Checklist catalog writes require the `manage-checklist` gate (`AUTH_ADMINS`). Agent approvals/runs belong to `owner_user_id` / `user_id`.
- **`app/Services/AzureDevOpsService.php`** — all Azure DevOps calls, using Laravel's `Http` client with PAT basic auth. The container binds it to `forUser()` with the authenticated user's PAT; queue jobs and commands must resolve services inside `$user->actAs(fn () => ...)` so they run with that user's PAT. `client()` is project-scoped (`{org}/{project}/_apis/`), `orgClient()` organization-scoped (agents, teams, connectionData). Pass a non-default API version as the argument (`client('7.1-preview.4')` for comments) — `withQueryParameters` merges recursively, so overriding `api-version` later sends it twice.
- **Error convention:** public service methods never throw; they return `['error' => msg, 'status' => azureHttpStatus]`. Controllers return `$this->azureError($result)` (base `Controller`), which maps Azure 400 → 422.
- **WIQL:** always build queries through `buildWorkItemsQuery()` / `escapeWiql()`; user input is interpolated into WIQL strings. Pagination slices the WIQL id list (WIQL has no `$skip`).
- **Comments** are sent as HTML converted from markdown (`Str::markdown` with `html_input=escape`), for both user text and agent output.
- **Artifact links:** `parseArtifactLinks()` reads PR/branch links from work item relations and accepts both `/` and `%2F` separators (Azure stores `%2F`; this app creates links with `/`).
- **Config:** `config/azure.php` (org, project, admins) and `config/agents.php` (generator, timezone `America/Bogota`, schedule time, Copilot Direct Line secret). Never read `env()` outside config files.
- **Checklist (alistamiento):** `checklist_definitions` is the catalog (name, `data_type` enum `App\Enums\ChecklistDataType`, `work_item_state` = the Azure state/stage the item belongs to, `blocking`, `source`). Values per work item live in `checklist_status` keyed by `work_item_metadata` (created lazily with `WorkItemMetadata::forWorkItem()`). `WorkItemChecklistService` groups items by state in the order Azure returns the type's states, and computes `source != manual` items (PR, branch, pipeline) from Azure on every read. Definitions are soft-deleted so values are kept.
- **Daily comment agent** (`app/Services/Agents/`): `DailyProgressCollector` gathers today's commits (excluding merges) from linked PRs, or linked branches when there is no PR, with no AI involved → `CommentGenerator` writes the text (`CopilotStudioCommentGenerator` via Direct Line, falling back to `TemplateCommentGenerator` on any failure) → `AgentCommentService` stores an `agent_approvals` row in `Pendiente`. **Nothing is published until `approve()`**, which also prevents publishing twice on the same day unless forced. No commits → `daily_comment_manual` (the user writes the text). Batches and the weekday schedule (`routes/console.php`) dispatch one `RunDailyCommentAgent` job per open work item and track progress in `agent_runs`.
- The work item process is **Scrum** (Task: To Do → In Progress → Done → Removed). There is no "In Review" state, so never hardcode states; read them from `/api/work-item-types`.

### Frontend

- `src/services/apiClient.ts` — axios instance with `withCredentials`/`withXSRFToken` (fetches `/sanctum/csrf-cookie` before the first write); `baseURL` comes from `VITE_API_BASE_URL` (`.env`). A 401 sends the user to `/login` (handler registered in `main.ts`). A response interceptor puts the API's message (or the first validation error) into `error.message`; use `errorMessage()`, `errorCode()` and `validationErrors()` from that module.
- Array query params must use `paramsSerializer: { indexes: false }` with an unbracketed key so Laravel receives `state[]=…`.
- Each domain has a service module exporting types that mirror the API (`workItemService`, `checklistService`, `agentService`, `userService`). Keep them in sync with the controllers/resources.
- Routes: `/login` (PAT form; the only `meta.public` route, `router.beforeEach` requires a session everywhere else), `/` dashboard (filters live in the query string), `/work-items/:id`, `/alistamiento` (checklist CRUD, admin only), `/agentes` (review inbox, batch progress via `?batch=`, scheduled-run toggle). UI permissions (`permissions.can_edit`, `is_mine`, `isAdmin`) only hide controls; the API enforces them.
- Shared state and helpers live in `src/composables/`: `useSession` (current user + pending approvals count), `useToast`, `useWorkItemStyles` (state badge colors by Azure category, icons, dates), `useSanitize` (DOMPurify; always use it before `v-html` with Azure HTML).
- Modals use `AppModal.vue` (teleported, focus trap, Esc). Buttons and inputs use the `.btn*` / `.field*` component classes and the design tokens in `src/style.css` (`@theme`); any new `text-*`/`font-*`/color token must be defined there or Tailwind silently drops it.

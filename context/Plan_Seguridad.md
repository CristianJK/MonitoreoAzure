---
id: plan-seguridad
tags:
  - work
  - Azure
  - seguridad
  - plan
status: implementado con la opción B (PAT personal) — ver "Decisiones confirmadas"
audience: humano + IA (agente de desarrollo)
fecha: 2026-10-26
---

# Plan de Seguridad — Inicio de sesión y permisos por work item

> Objetivo: que nadie use el dashboard sin iniciar sesión **con su propio usuario**, y que cada
> persona solo pueda **crear y modificar sus work items** (los asignados a ella). Los work items
> de otras personas se pueden **ver, pero no modificar**.
>
> Regla de oro: **el backend es la autoridad**. El frontend solo oculta o deshabilita botones;
> toda restricción se valida en Laravel antes de llamar a Azure DevOps.
>
> Documentos relacionados: `Plan_Finalizacion_Aplicacion.md` (Fase 9 dejó pendiente la autenticación).

---

## Decisiones confirmadas (2026-10-26)

| # | Tema | Decisión |
|---|---|---|
| 1 | Cómo se inicia sesión | **Opción B: PAT personal** (Anexo B). No se usa Entra ID. |
| 2 | Qué es un WI "propio" | Solo `System.AssignedTo` = usuario de la sesión (por id de identidad o uniqueName). Sin asignar = de nadie. |
| 3 | Administrador del catálogo de alistamiento | Solo la cuenta del dueño del proyecto (`AUTH_ADMINS`). |
| 4 | Credencial de la ejecución programada y de los jobs | **El PAT de cada usuario.** No hay PAT global en `.env`. El PAT se conserva al cerrar sesión solo si el usuario tiene activa la ejecución programada. |

### Estado de implementación

| Fase | Estado | Notas |
|---|---|---|
| 1. Login y sesión | ✅ | `POST /api/login {pat}` → `connectionData` + una lectura por scope (Project and Team, Work Items, Code, Build) → `users.azure_pat` cifrado → sesión de Sanctum. Si Azure responde 401 con el PAT del usuario, `LogoutOnInvalidPat` cierra la sesión. |
| 2. Servicio por usuario | ✅ | `forUser()` / `withPat()`; jobs y comandos con `User::actAs()`; resumen del dashboard en cache por usuario. |
| 3. Propiedad de WI | ✅ | `WorkItemOwnership` (sin cache), `work-item.owner`, gate `manage-checklist`, `audit_logs`, `permissions.can_edit` / `is_mine`. |
| 4. Agente por usuario | ✅ | `owner_user_id`, `resolved_by_user_id`, `agent_runs.user_id`; el programado corre solo para usuarios con `agent_schedule_enabled` y PAT guardado, con `@Me` de su PAT. |
| 5. Frontend | ✅ | Formulario de PAT, guard del router, 401 → login, CSRF, modo solo lectura, catálogo solo admin, cerrar sesión, interruptor del programado en `/agentes`. |
| 6. Endurecimiento | ✅ / ⏳ | CORS con credenciales, rate limits (`login` 5/min), `SecurityHeaders`, `dontFlash(['pat'])`, validación de rama/repositorio, `users:revoke`. **Pendiente en producción:** HTTPS, `SESSION_SECURE_COOKIE=true`, `APP_DEBUG=false`, CSP en el servidor de la SPA. |
| 7. Pruebas | ✅ | 55 tests (`AuthTest`, `AuthorizationTest` + existentes autenticados). Falta prueba manual con dos cuentas y actualizar Postman. |

## Decisiones propuestas (a confirmar)

| # | Tema | Propuesta |
|---|---|---|
| 1 | Cómo se inicia sesión | **Microsoft Entra ID** (la misma cuenta con la que se entra a Azure DevOps), con OAuth 2.0 *authorization code* + PKCE. Alternativa si Entra no es viable: **login con PAT personal** (ver Anexo B). |
| 2 | Qué es un WI "propio" | `System.AssignedTo` = usuario de la sesión, comparando el **id de identidad** de Azure (no el nombre visible). Un WI **sin asignar no es propio**. `System.CreatedBy` no da permisos. |
| 3 | Identidad en Azure | Las llamadas de un usuario se hacen **con su token** → los comentarios y cambios de estado quedan **a su nombre** y Azure aplica además sus propios permisos. El PAT del `.env` pasa a ser **de servicio y solo lectura**, usado únicamente por los jobs en segundo plano. |
| 4 | Catálogo de alistamiento (`/alistamiento`) | Lectura para todos; crear/editar/borrar/reordenar solo **administradores** (`is_admin`, lista `AUTH_ADMINS` en `.env`). |
| 5 | Agente de comentario diario | Cada usuario ve, aprueba y rechaza **solo sus propuestas**. El lote y la ejecución programada corren **por usuario** sobre sus propios WIs. |
| 6 | Sesión | **Sanctum SPA** (cookie de sesión `httpOnly` + CSRF). No se guardan tokens en `localStorage`. Duración 8 h, cierre por inactividad 120 min. |

### Comparativa de métodos de login

| Opción | Cómo funciona | A favor | En contra |
|---|---|---|---|
| **A. Entra ID (recomendada)** | Botón "Iniciar sesión con Microsoft" → Entra → callback en Laravel con un token delegado de Azure DevOps | Sin contraseñas propias; MFA y políticas de la empresa; los comentarios salen a nombre de cada persona; revocación central (si sale de la empresa, pierde acceso) | Requiere registrar una app en Entra (y quizá consentimiento de un admin del tenant); la organización de Azure DevOps debe estar conectada a Entra; **Entra exige HTTPS** en la URL de retorno (salvo `localhost`) |
| B. PAT personal | Cada usuario pega su PAT en el login; se valida contra `connectionData` y se guarda cifrado | No requiere admin del tenant ni registrar apps; rápido de implementar | El usuario debe crear y renovar su PAT (máx. 1 año) con los scopes correctos; un PAT filtrado da acceso hasta que se revoque |
| C. Usuario/contraseña local | Tabla `users` con contraseña | Simple | **Descartada:** no prueba que la persona sea quien dice en Azure, hay que gestionar contraseñas y todo seguiría publicándose como el dueño del PAT |

---

## 0. Diagnóstico de seguridad actual (revisado en el código)

| Área | Hallazgo | Dónde |
|---|---|---|
| Autenticación | **Ninguna.** Todas las rutas están abiertas: cualquiera que alcance `:8001` puede comentar, cambiar estados, enlazar ramas/PRs y aprobar comentarios del agente. | `routes/api.php` |
| Identidad | Todo se hace con **un solo PAT con escritura**; todos los comentarios salen a nombre de su dueño. `checked_by` y `resolved_by` guardan siempre ese mismo nombre. | `AzureDevOpsService::request()`, `WorkItemChecklistController::update`, `AgentCommentController::userName` |
| `@Me` | Se asume que `@Me` es el dueño del PAT (filtro del dashboard, lote, ejecución programada). | `AzureWorkItemController::index`, `AgentCommentService::startBatch`, `routes/console.php` |
| Cache global de usuario | `azure.current_user` se guarda una sola vez para todos. | `AzureDevOpsService::getCurrentUser` |
| Permisos | No existe ninguna verificación de propiedad del WI. | — |
| Catálogo de alistamiento | CRUD abierto a cualquiera. | `ChecklistDefinitionController` |
| Bandeja del agente | Lista y cuenta las propuestas de **todos**; cualquiera puede aprobar cualquier propuesta. | `AgentCommentController::index/pendingCount/approve` |
| CORS | No existe `config/cors.php` → se usan los valores por defecto de Laravel (cualquier origen). | `config/` |
| Restos | Ruta `/api/user` de la plantilla; `link-branch` y `link-pr` sin `whereNumber`. | `routes/api.php` |
| Transporte | HTTP plano sobre la IP de Tailscale (`http://100.112.88.114:8001`). | `azure-dashboard-web/.env` |
| Límite de peticiones | No hay *rate limiting*. | — |
| Ya resuelto | WIQL escapado, HTML de Azure sanitizado con DOMPurify, markdown con `html_input=escape`, `Http::preventStrayRequests()` en tests. | — |

---

## Arquitectura objetivo

```
Navegador (SPA)                Laravel (API)                         Microsoft / Azure DevOps
───────────────                ─────────────                         ────────────────────────
/login ──"Entrar"──────────►  GET /api/auth/login  ──redirect──────►  login.microsoftonline.com
                                                                        (usuario + MFA)
                              GET /api/auth/callback ◄──code─────────
                                 ├─ canjea code → access + refresh token
                                 ├─ GET connectionData (con el token)  ─►  identidad Azure DevOps (id)
                                 ├─ upsert users (tokens cifrados)
                                 └─ Auth::login + regenera sesión
◄──── cookie de sesión ───────
/  (dashboard) ──────────────► auth:sanctum
                                 ├─ EnsureAzureToken (refresca si vence)
                                 ├─ AzureDevOpsService::forUser($user)  ─►  Bearer <token del usuario>
                                 └─ escrituras: work-item.owner
                                      └─ WorkItemOwnership: AssignedTo.id === users.azure_id ?
                                           sí → continúa · no → 403 not_owner (sin llamar a Azure)

Jobs (cola / programado) ────► AzureDevOpsService::service()  ─────►  PAT de servicio (solo lectura)
```

---

## Fase 0 — Prerrequisitos (infraestructura, sin código)

1. **Verificar** que la organización de Azure DevOps está conectada a Entra ID
   (*Organization settings → Microsoft Entra*). Si está respaldada por cuentas Microsoft personales,
   la opción A no aplica → usar el Anexo B.
2. **Registrar la app en Entra** (*App registrations → New*):
   - Un solo tenant. Plataforma **Web**. Redirect URI: `https://<host>/api/auth/callback`.
   - Permisos delegados: `openid`, `profile`, `email`, `offline_access` y **Azure DevOps →
     `user_impersonation`** (recurso `499b84ac-1321-427f-aa17-267ca6975798`).
   - Crear un *client secret* (anotar vencimiento) y pedir **consentimiento de administrador** si el
     tenant lo exige.
3. **HTTPS:** publicar SPA y API bajo **un mismo host** con certificado, por ejemplo con
   `tailscale serve` / `tailscale cert` (`https://<maquina>.<tailnet>.ts.net`, SPA en `/` y API en
   `/api`) o un proxy (Caddy/nginx). Mismo origen ⇒ cookies de primera parte y sin problemas de CORS.
4. **PAT de servicio nuevo, solo lectura:** Work Items (Read), Code (Read), Build (Read),
   Project and Team (Read), Agent Pools (Read). **Revocar el PAT actual con escritura.**
5. Variables nuevas en `.env` / `.env.example` (sin valores en el ejemplo):
   ```
   AZURE_AD_TENANT_ID=
   AZURE_AD_CLIENT_ID=
   AZURE_AD_CLIENT_SECRET=
   AZURE_AD_REDIRECT_URI=https://<host>/api/auth/callback
   AUTH_ADMINS=correo1@empresa.com,correo2@empresa.com
   FRONTEND_URL=https://<host>
   SANCTUM_STATEFUL_DOMAINS=<host>
   SESSION_DOMAIN=<host>
   SESSION_SECURE_COOKIE=true
   SESSION_ENCRYPT=true
   SESSION_LIFETIME=120
   ```

---

## Fase 1 — Backend: usuarios, login y sesión

1. **Migración** sobre `users` (la tabla existe pero no se usa):
   `azure_id` (uuid, único — id de identidad de Azure DevOps), `unique_name` (correo, único),
   `display_name`, `is_admin` (bool), `azure_access_token` (text), `azure_refresh_token` (text),
   `azure_token_expires_at`, `agent_schedule_enabled` (bool, default false), `last_login_at`;
   `password` pasa a `nullable`.
2. **Modelo `User`:** casts `encrypted` para los dos tokens, `#[Hidden]` para tokens y password.
3. **Config:** en `config/azure.php` agregar `oauth` (tenant, client id/secret, redirect, scopes) y
   `admins` (lista desde `AUTH_ADMINS`). Nunca leer `env()` fuera de `config/`.
4. **`App\Services\Auth\EntraIdOAuthService`** con el cliente `Http` de Laravel (mismo estilo que
   `AzureDevOpsService`, fácil de simular con `Http::fake`):
   - `authorizationUrl(string $state, string $codeChallenge): string`
   - `exchangeCode(string $code, string $verifier): array` → tokens
   - `refresh(User $user): bool`
   - Mismo contrato de errores: nunca lanza, devuelve `['error' => ..., 'status' => ...]`.
5. **`AuthController`** (rutas públicas, con `throttle:login`):
   - `GET /api/auth/login?redirect=/ruta` → genera `state` y PKCE en la sesión y redirige a Entra.
     `redirect` solo acepta rutas relativas (evita *open redirect*).
   - `GET /api/auth/callback` → valida `state`; canjea el código; llama `connectionData` **con el
     token del usuario** para obtener `authenticatedUser.id` (es el mismo id que trae
     `System.AssignedTo.id`); si falla ⇒ no tiene acceso a la organización ⇒ redirige a
     `/login?error=sin_acceso`. Si todo va bien: `upsert` del usuario (`is_admin` según `AUTH_ADMINS`),
     `Auth::login()`, `session()->regenerate()` y redirige al `FRONTEND_URL` + `redirect`.
   - `POST /api/auth/logout` → `Auth::logout()`, invalida la sesión, regenera el token CSRF y borra
     los tokens guardados.
6. **Sanctum SPA:** `$middleware->statefulApi()` en `bootstrap/app.php`.
7. **Rutas:** todo `routes/api.php` dentro de `Route::middleware(['auth:sanctum', 'azure.token'])`;
   eliminar `/api/user`; `/api/me` devuelve el usuario de la base (`id`, `display_name`,
   `unique_name`, `is_admin`), ya no el dueño del PAT.
8. **Middleware `EnsureAzureToken`** (alias `azure.token`): si el token vence en < 5 min lo refresca;
   si el refresco falla (contraseña cambiada, usuario retirado de la organización) cierra la sesión y
   responde **401 con `code: azure_session_expired`**.

---

## Fase 2 — `AzureDevOpsService` por usuario

1. El servicio deja de leer el PAT en el constructor y recibe una credencial:
   - `AzureDevOpsService::forUser(User $user)` → `withToken($accessToken)` (Bearer).
   - `AzureDevOpsService::service()` → `withBasicAuth('', config('azure.pat'))` (jobs, solo lectura).
2. Registro en el contenedor (`AppServiceProvider`): en una petición autenticada se resuelve
   `forUser(auth()->user())`; en consola/cola se resuelve `service()`. Así los controladores
   existentes no cambian su inyección.
3. **Cache:** tipos, estados y miembros de equipos siguen globales (no dependen del usuario).
   `azure.current_user` **se elimina** (el usuario sale de la base).
4. **`@Me`:** con el token del usuario sigue funcionando en peticiones web. En jobs **siempre** se pasa
   el `unique_name` explícito, nunca `@Me` (con el PAT de servicio `@Me` sería la cuenta de servicio).

---

## Fase 3 — Autorización por work item (núcleo del requisito)

1. **`App\Services\WorkItemOwnership`:**
   - `assigneeOf(int $id): ?array` → `System.AssignedTo` (`id`, `uniqueName`), cache de 60 s por WI,
     se invalida después de cada escritura sobre ese WI.
   - `isOwner(User $user, int $id): bool` → `assignee.id === $user->azure_id`; si Azure no trae `id`,
     compara `uniqueName` en minúsculas. Sin asignar ⇒ `false`. Error al consultar Azure ⇒ `false`
     (falla cerrada).
2. **Gate** `edit-work-item` en `AppServiceProvider` usando `WorkItemOwnership`. Mensaje:
   *"Solo puedes modificar work items asignados a ti."*, respuesta **403 con `code: not_owner`**.
3. **Middleware `EnsureWorkItemOwner`** (alias `work-item.owner`) que lee el parámetro `{id}` de la ruta
   y aplica el gate **antes** de cualquier llamada de escritura a Azure.
4. **Matriz de permisos:**

   | Endpoint | Regla |
   |---|---|
   | `GET /work-items`, `/work-items/{id}`, `/work-items/{id}/checklist`, `checklist-summary` | Autenticado (cualquier WI) |
   | `GET /work-item-types*`, `/users`, `/repositories*`, `/agents` | Autenticado |
   | `POST /work-items/{id}/comments` | **Dueño** |
   | `PATCH /work-items/{id}/state` | **Dueño** |
   | `POST /work-items/{id}/link-branch`, `/link-pr` | **Dueño** (+ agregar `whereNumber('id')`) |
   | `PUT /work-items/{id}/checklist/{definition}` | **Dueño** |
   | `POST /work-items/{id}/agent/daily-comment` | **Dueño** |
   | `POST /agent/daily-comment/batch` | Autenticado; **solo sus propios WIs** (se ignora/rechaza `assigned_to` de otra persona) |
   | `GET /agent-approvals`, `pending-count`, `GET /agent-runs` | Autenticado; **filtrado a lo suyo** |
   | `POST /agent-approvals/{approval}/approve·reject·regenerate` | Dueño de la propuesta **y** sigue siendo dueño del WI hoy (`AgentApprovalPolicy`) |
   | `GET /checklist-definitions*` | Autenticado |
   | `POST/PUT/PATCH/DELETE /checklist-definitions*` | **Admin** (gate `manage-checklist`) |
   | Crear work items (hoy no existe endpoint) | Si se agrega: `System.AssignedTo` se fuerza al usuario de la sesión; no se permite asignar a otra persona |

5. **Propietario en las tablas del agente** (migración):
   - `agent_approvals`: `owner_user_id` (FK `users`, nullable), `owner_azure_id`, `resolved_by_user_id`.
   - `agent_runs`: `user_id` (FK `users`, nullable).
6. **Exponer permisos al frontend** (solo para la UI, el backend vuelve a validar):
   - `GET /work-items/{id}` agrega `permissions: { can_edit: bool }`.
   - `GET /work-items` agrega `is_mine` por fila (incluir `System.AssignedTo.id` al formatear).
   - `GET /work-items/{id}/checklist` agrega `can_edit`.
7. **Trazabilidad:** `checked_by` / `resolved_by` guardan el `display_name` del usuario de la sesión
   (más `*_user_id` donde se agregó la columna).
8. **Auditoría:** tabla `audit_logs` (`user_id`, `action`, `work_item_id`, `result` ok/denied/error,
   `ip`, `user_agent`, `meta` json, `created_at`). Se registra cada escritura y cada 403 `not_owner`.
   Nunca se guardan tokens ni el texto completo del comentario.
9. *(Opcional, defensa extra)* En el cambio de estado enviar en el JSON Patch una operación
   `test` sobre `/rev` con la revisión leída al validar la propiedad: si el WI cambió (p. ej. se
   reasignó) entre la validación y la escritura, Azure rechaza el cambio.

---

## Fase 4 — Agente y tareas programadas

1. `propose()` recibe el `User`; la propuesta guarda `owner_user_id` / `owner_azure_id`.
2. `approve()` publica con `AzureDevOpsService::forUser($user)` ⇒ el comentario sale **a nombre del
   usuario** que aprueba (que es el dueño del WI).
3. `startBatch(User $user, ...)` usa `$user->unique_name`; `RunDailyCommentAgent` y
   `DispatchDailyComments` llevan `user_id`.
4. **Ejecución programada** (`routes/console.php`): recorre los usuarios con
   `agent_schedule_enabled = true` y encola un lote por cada uno, leyendo con el PAT de servicio
   (solo lectura). Las propuestas quedan en la bandeja de cada usuario; **nada se publica sin que el
   usuario apruebe con su sesión**.
5. `agents:daily-comments` pasa a recibir `{email}` en lugar de `--assigned=@Me`.
6. Propuestas antiguas sin dueño: comando `agents:backfill-owners` que lee `System.AssignedTo` de cada
   WI y completa `owner_*`; las `Pendiente` que no se puedan asignar pasan a `Vencido`.

---

## Fase 5 — Frontend

1. **`apiClient.ts`:** `withCredentials: true`, `withXSRFToken: true`; `baseURL` = `/api` (mismo origen).
   Interceptor:
   - **401** → limpia la sesión y redirige a `/login?redirect=<ruta actual>` (si `code` es
     `azure_session_expired`, mostrar "Tu sesión de Microsoft expiró").
   - **403 `not_owner`** → toast con el mensaje de la API.
2. **`authService.ts`:** `login(redirect)` (navega a `/api/auth/login?redirect=...`), `logout()`, `me()`.
   Antes del primer `POST`, pedir `/sanctum/csrf-cookie`.
3. **`LoginView.vue`** en `/login` (`meta.public`): botón "Iniciar sesión con Microsoft" y mensaje
   según `?error=` (`sin_acceso`, `cancelado`, `expirado`). Usa `.btn*` y los tokens de `style.css`.
4. **Router:** `beforeEach` → `await loadCurrentUser()`; sin usuario y ruta no pública ⇒ `/login`;
   usuario en `/login` ⇒ dashboard. `meta.requiresAdmin` para las acciones del catálogo.
5. **`useSession`:** `currentUser` viene de `/api/me` (incluye `is_admin`); agrega `logout()` e
   `isAdmin`; el contador de pendientes ya es solo del usuario.
6. **Modo solo lectura:**
   - `WorkItemView`: si `!permissions.can_edit` se ocultan el formulario de comentario, el selector de
     estado, enlazar rama/PR, "Run Azure Agent" y la edición del alistamiento; aviso
     *"Solo lectura — asignado a {nombre}"*.
   - `DashboardView`: "Daily Comment Automation" solo cuando se ven los WIs propios; indicador
     "Solo lectura" al ver los de otra persona; `UserSearchSelect` muestra el nombre real en vez de
     "Yo (@Me)".
   - `ChecklistDefinitionsView`: botones de crear/editar/borrar/reordenar solo para admin.
   - Sidebar: nombre del usuario y botón **Cerrar sesión**.

---

## Fase 6 — Endurecimiento

1. **CORS** (solo si SPA y API quedan en orígenes distintos): publicar `config/cors.php` con
   `allowed_origins = [FRONTEND_URL]`, `supports_credentials = true`, paths `api/*` y
   `sanctum/csrf-cookie`.
2. **Rate limiting** (`RateLimiter::for` en `AppServiceProvider`): `login` 10/min por IP; escrituras
   60/min por usuario; lote del agente 5/min por usuario.
3. **Sesión:** `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, `http_only`, `same_site=lax`;
   regenerar al iniciar sesión e invalidar al cerrarla.
4. **Cabeceras** (servidor web de la SPA + middleware en la API): `Strict-Transport-Security`,
   `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin` y una
   CSP: `default-src 'self'; img-src 'self' data: https://dev.azure.com https://*.visualstudio.com; frame-ancestors 'none'`.
5. **Producción:** `APP_DEBUG=false`; los mensajes `azureError` no incluyen cabeceras ni tokens; nunca
   registrar tokens en logs.
6. **Validación de entrada:** `whereNumber` en todas las rutas `{id}`; `repository_id` como uuid;
   `branch_name` con patrón de nombre de rama Git.
7. **Revocación:** comando `users:revoke {email}` que borra los tokens y las sesiones del usuario.
8. **Dependencias:** `composer audit` y `pnpm audit` antes de cada despliegue.

---

## Fase 7 — Pruebas

1. **`TestCase`:** helper `actingAsAzureUser(array $attrs = [])` (crea `User` con `azure_id` y usa
   `Sanctum::actingAs`), y `workItemResponse()` acepta `System.AssignedTo` con `id`. Simular
   `login.microsoftonline.com/*` con `Http::fake`. Actualizar los 27 tests existentes para autenticarse.
2. **`AuthTest`:** la redirección lleva `state` y `code_challenge`; callback con `state` inválido ⇒
   error; callback válido crea el usuario e inicia sesión; `connectionData` falla ⇒ no hay sesión;
   `redirect` absoluto se ignora; logout invalida la sesión.
3. **Todas las rutas protegidas:** un test recorre `Route::getRoutes()` bajo `api/` (excepto
   `api/auth/*`) y verifica **401** sin sesión. Así, una ruta nueva sin `auth` rompe la suite.
4. **`WorkItemOwnershipTest`** (por cada endpoint de escritura): dueño ⇒ OK; otra persona ⇒ **403
   `not_owner`** y `Http::assertNotSent` a la URL de escritura de Azure; WI sin asignar ⇒ 403; lectura
   de WI ajeno ⇒ 200 con `can_edit: false`.
5. **Agente:** la bandeja y el contador solo muestran lo propio; aprobar una propuesta ajena ⇒ 403;
   al aprobar se envía `Authorization: Bearer <token del usuario>`; el lote ignora `assigned_to` ajeno;
   el programado encola solo usuarios con `agent_schedule_enabled`.
6. **Admin:** escribir en `checklist-definitions` sin ser admin ⇒ 403; leer ⇒ 200.
7. **Token:** token vencido ⇒ se refresca y continúa; refresco fallido ⇒ 401 `azure_session_expired`.
8. **Prueba manual** con dos cuentas reales: A comenta su WI (sale a su nombre en Azure), A abre el
   WI de B (solo lectura) y fuerza un `POST` con curl/Postman (⇒ 403). Actualizar la colección Postman.

---

## Fase 8 — Despliegue

1. Fase 0 completa (app en Entra, HTTPS, PAT de servicio de solo lectura).
2. `php artisan migrate`, `agents:backfill-owners`.
3. Desplegar backend y frontend **juntos** (el frontend nuevo depende de la sesión).
4. Revocar el PAT antiguo con escritura.
5. Primer inicio de sesión de cada usuario; activar `agent_schedule_enabled` para quien lo quiera.
6. Actualizar `CLAUDE.md` (sección *Architecture*: credenciales por usuario, `work-item.owner`, PAT de
   servicio solo lectura) y el estado en `Plan_Finalizacion_Aplicacion.md`.

---

## Orden de ejecución y dependencias

```
Fase 0 (infra: Entra, HTTPS, PAT lectura)
   └─► Fase 1 (usuarios + login + sesión)
          └─► Fase 2 (AzureDevOpsService por usuario)
                 ├─► Fase 3 (propiedad de WI + admin + auditoría) ─┐
                 └─► Fase 4 (agente por usuario) ◄─────────────────┘
                              └─► Fase 5 (frontend: login, guard, solo lectura)
                                     └─► Fase 6 (endurecimiento)
Fase 7 (pruebas): se escribe junto con cada fase, no al final.
Fase 8 (despliegue).
```

- Las fases 1–3 se pueden desarrollar en local con `http://localhost` (Entra acepta `localhost` como
  redirect URI sin HTTPS) mientras se prepara el HTTPS de la Fase 0.

---

## Anexo B — Variante con PAT personal (si Entra no es viable)

Cambia solo lo siguiente; el resto del plan (Fases 3–8) es igual:

- **Fase 0:** sin registro en Entra. Cada usuario crea su PAT con: Work Items (Read & Write),
  Code (Read), Build (Read), Project and Team (Read).
- **Fase 1:** `POST /api/auth/login { pat }` (con `throttle:login`) → `connectionData` con ese PAT para
  obtener la identidad + una lectura de prueba para validar scopes → se guarda el PAT **cifrado**
  (`azure_pat`, cast `encrypted`) → `Auth::login()`. `LoginView` muestra un campo de contraseña para el
  PAT con un enlace a la página de tokens de Azure DevOps y la lista de scopes.
- **Fase 2:** `forUser()` usa `withBasicAuth('', $user->azure_pat)`. No hay refresco: si Azure responde
  401 ⇒ 401 `azure_session_expired` y se pide un PAT nuevo.
- Avisar en la UI cuando falten menos de 15 días para que venza el PAT (si el usuario indica la fecha).

---

## Preguntas abiertas

1. **Método de login:** ¿Entra ID (A) o PAT personal (B)?
2. ¿La organización de Azure DevOps está conectada a Entra ID? ¿Quién puede registrar la app y dar
   el consentimiento de administrador?
3. **"Propio":** ¿solo `AssignedTo`, o también los WIs creados por la persona (`CreatedBy`)? ¿Las Tasks
   hijas de una Story asignada a la persona cuentan como suyas?
4. **Admins del catálogo de alistamiento:** ¿quiénes son?
5. ¿Alguien (líder) debe poder ver la bandeja del agente de otras personas, aunque sea en solo lectura?
6. **HTTPS / host:** ¿`tailscale serve` en la máquina actual, o un servidor con dominio propio?
7. **Crear work items:** hoy no hay endpoint (se quitó "New Item"). ¿Se va a agregar creación de
   Tasks? Si es así, se crean siempre asignadas a quien las crea.

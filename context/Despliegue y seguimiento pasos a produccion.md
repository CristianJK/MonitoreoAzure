---
id: 2608241044-Sin título
tags:
  - work
  - IA
  - Azure
---
# Guía Paso a Paso: Desarrollo de Plataforma de Agentes IA para Azure DevOps

Este es el plan de acción estructurado desde cero. Está pensado para aprovechar un stack Full Stack (PHP, Laravel, Vue.js) para el panel de control, y Microsoft Copilot Studio para la capa de Inteligencia Artificial.

---

## FASE 1: Preparación del Terreno y Autenticación (Configuración)
*Lo primero es asegurar que tus sistemas puedan hablar entre sí.*

### Paso 1: Generar un Personal Access Token (PAT) en Azure DevOps
1. Ve a tu Azure DevOps > User Settings (icono de engranaje/usuario arriba a la derecha) > **Personal Access Tokens**.
2. Crea un nuevo token con permisos de **Read & Write** para: `Work Items`, `Code`, `Build`, y `Release`.
3. Guarda este PAT en un lugar seguro (lo usará tu backend para consultar datos).



### Paso 2: Crear el Proyecto Base del Dashboard (Backend)
Necesitas un intermediario entre Azure DevOps, los agentes y el frontend. 
1. Inicia un nuevo proyecto en tu entorno local (una API en PHP, puedes usar un framework como Laravel para agilizar el enrutamiento).
2. Crea un archivo `.env` y guarda ahí tu PAT de Azure DevOps y la URL de tu organización (`https://dev.azure.com/TU_ORG/TU_PROYECTO`).
3. Instala un cliente HTTP (como Guzzle en PHP) para hacer peticiones a la API REST de Azure DevOps.

---

## FASE 2: Desarrollo del Backend y Trazabilidad (El Core)
*Aquí programarás la lógica que extrae la información de Azure.*

### Paso 3: Desarrollar el Endpoint de Trazabilidad
Crea un controlador en tu backend (`AzureDevOpsController.php`) con una ruta que haga lo siguiente:
1. **Listar Work Items:** Consulta el endpoint `_apis/wit/workitems`.
2. **Obtener Relaciones:** Para cada Work Item, consulta sus enlaces (`$expand=relations`) para ver si tiene ramas asociadas (`Branch`), Commits, o Pull Requests.
3. Devuelve esta información estructurada en formato JSON para que el frontend la consuma.

### Paso 4: Preparar Webhooks para el Agente (El "Human-in-the-Loop")
1. Crea un endpoint en tu backend llamado `/api/agent-approval`.
2. Este endpoint recibirá las peticiones de los agentes de IA cuando quieran ejecutar una acción crítica (como aprovar un despliegue SSH).
3. La lógica debe guardar esta petición en una base de datos local (MySQL/PostgreSQL) con estado `Pendiente`.

---

## FASE 3: Desarrollo del Dashboard Centralizado (Frontend)
*La interfaz donde auditarás todo.*

### Paso 5: Crear la Interfaz de Usuario
1. Inicia un proyecto de frontend (usando Vue.js, React o Astro).
2. **Vista de Trazabilidad:** Consume el JSON del Paso 3 y crea una tabla o un diagrama de árbol. Debes poder ver:
   - ID del Work Item y Estado.
   - Si tiene una rama creada (ícono verde/rojo).
   - Lista de Commits asociados y sus mensajes.
3. **Vista de Auditoría de Agentes:** Crea una pantalla que consulte la base de datos del Paso 4. 
   - Mostrará las acciones pendientes del agente (ej. *"El agente solicita desplegar el Work Item #1234 al servidor Linux"*).
   - Agrega botones de **Aprobar** y **Rechazar**. Al hacer clic en Aprobar, el backend continuará la acción.

---

## FASE 4: Creación de los Agentes en Copilot Studio
*Dándole vida a la IA en tu entorno de Microsoft 365.*

### Paso 6: Configurar el Agente
1. Entra a **Microsoft Copilot Studio** y crea un "Nuevo Agente".
2. En las instrucciones (System Prompt), indícale: *"Eres un asistente de DevOps. Cuando se te pida desplegar o auditar, debes enviar los datos al sistema de aprobación antes de continuar."*
*(Opcional: Para pruebas locales antes de llevarlo a Copilot, puedes orquestar flujos de prueba usando un modelo local en Ollama).*

### Paso 7: Crear las "Acciones" (Actions) del Agente
1. En Copilot Studio, añade una Acción que conecte con tu API PHP (la que creaste en la Fase 1 y 2). 
2. Necesitarás exponer tu API local a internet (usando algo como Ngrok o desplegándola en un servidor de desarrollo) para que Copilot pueda alcanzarla.
3. Define las acciones: `RevisarCodigo`, `SolicitarDespliegue`, `GenerarMinutograma`.

---

## FASE 5: Automatización y Pipelines en Azure
*Conectando el código con los despliegues SSH.*

### Paso 8: Integrar el Agente en las Pull Requests
1. En Azure DevOps, ve a **Project Settings > Service Hooks**.
2. Configura un Webhook que se dispare cuando se cree un **Pull Request**. Este webhook apuntará a tu backend.
3. Tu backend recibirá el aviso, extraerá el código cambiado (diff) usando la API de Azure, y se lo enviará a la IA para que haga la **revisión de código y validación de seguridad**. Si falla, tu API debe usar el PAT de Azure para comentar en el PR rechazándolo.

### Paso 9: Configurar el Pipeline de Despliegue SSH
1. En tu repositorio, asegura que tu `azure-pipelines.yml` tenga la tarea de copia y ejecución por SSH (`SSH@0` y `CopyFilesOverSSH@0`).
2. Configura este pipeline para que dependa de una **Aprobación Manual** (esto se configura en los Environments de Azure Pipelines, pero tu backend será quien lo apruebe mediante la API una vez tú le des al botón "Aprobar" en tu dashboard de Vue.js).

---

## FASE 6: Generación de Artefactos (Excel y Docs)
*El toque final para la documentación.*

### Paso 10: Generador de Minutogramas
1. En tu backend PHP, integra una librería (como `PhpSpreadsheet` o llama a un script de Python usando `pandas`/`openpyxl` si prefieres).
2. Cuando el despliegue es aprobado, el backend extrae todos los Commits y Work Items de ese Release.
3. El script formatea esos datos en un archivo `.xlsx` (el minutograma).
4. Usando la API de Azure DevOps, tu backend sube ese Excel como un "Attachment" al Work Item correspondiente o al historial del Release.
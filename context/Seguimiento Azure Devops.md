---
id: 2607080859-Seguimiento Azure Devops
tags:
  - work
  - Azure
task_id:
---
# Resumen Técnico y Arquitectura: Azure DevOps Dashboard & Automation Hub

## 1. Visión General de la Aplicación

La aplicación es un **Hub centralizado de gestión y automatización** diseñado para interactuar de forma nativa con **Azure DevOps**, optimizando el ciclo de vida del desarrollo de software (SDLC). Su objetivo principal es unificar en una sola interfaz el monitoreo de Work Items, la vinculación y auditoría de Pull Requests, la validación de estándares de código, y la sincronización con los procesos operativos internos de la compañía (como solicitudes de WO y ejecución de pipelines) la validación de los documentos como manuales tecnicos, minutogramas(excel), pruebas unitarias.
Cada work item debe tener un listado de alistamiento para no olvidar ningun paso en el flujo del alistamiento al paso a produccion, tambien debe tener una fecha estimada de entrega y una fecha de posible paso a PAP, se debe tener un calendario donde se va a visualizar los cambios programdos, 

## 2. Stack Tecnológico

- **Backend:** Laravel 13 (Ejecutándose en entorno local mediante Laravel Sail / PHP 8.x).
    
- **Frontend:** Vue 3 (Composition API, TypeScript) + Tailwind CSS v4 (`@tailwindcss/postcss`).
    
- **Comunicación y Redes:** Conectividad local y remota segura mediante Tailscale e IP dinámica del servidor.
    
- **Integraciones Externas:** Azure DevOps REST API (v7.1) utilizando enlaces de artefactos basados en identificadores globales nativos (`ArtifactLink` con GUIDs).
    

## 3. Estado Actual (Progreso Logrado)

- **Conectividad y CORS:** Configuración avanzada del núcleo de Laravel 13 (`bootstrap/app.php`) y del cliente Axios dinámico en el frontend para soportar entornos de red locales y remotos (Tailscale).
    
- **Sincronización de Work Items:** Listado en tiempo real de tareas activas de Azure DevOps, con soporte para filtrado por tipos y estados.
    
- **Vinculación Robusta de Pull Requests (PRs):**
    
    - Se implementó el estándar oficial de Microsoft utilizando URLs internas basadas en GUIDs (`vstfs:///Git/PullRequestId/{projectGuid}/{repoGuid}/{prId}`) con barras normales (`/`), evitando errores de lectura (_"Branch/PR link could not be read"_).
        
    - Manejo inteligente de excepciones (`Relation already exists`) para evitar fallos cuando un PR ya se encuentra vinculado.
        
- **Detalles y Comentarios:** Endpoints y vistas preliminares para consultar la información detallada de los Work Items y enviar/sistematizar comentarios directamente hacia Azure DevOps.
    

## 4. Próximos Pasos y Pasos Futuros

1. **Módulo de Auditoría de Código con IA:**
    
    - Extraer el _diff_ de los archivos modificados en el Pull Request utilizando las iteraciones de la API de Azure.
        
    - Conectar esta información con modelos de inteligencia artificial locales (Ollama / OpenCode) para auditar si el código cumple con los estándares de arquitectura (Laravel/Vue) y auto-generar la documentación técnica.
        
2. **Automatización de Pipelines y Despliegues:**
    
    - Lanzamiento y monitoreo del estado de pipelines de CI/CD directamente desde el panel de control de la aplicación.
        
3. **Módulo de Solicitud de WO (Integración Mi Asistencia 360 / RSSO):**
    
    - _Análisis de integración:_ El portal corporativo (`[https://miasistencia360-rsso.claro.com.co/rsso/start](https://miasistencia360-rsso.claro.com.co/rsso/start)`) opera bajo estrictas políticas de Autenticación Única (SSO) y redes internas de la compañía, por lo que no expone una API REST pública ni abierta para automatizar peticiones programáticas de forma externa.
        
    - _Solución Arquitectónica:_ Se implementará un **Generador de Plantillas y Textos Inteligente** dentro de la aplicación. Este módulo tomará los datos de la tarea (ID del Work Item, título, repositorio y rama) y autocompletará una plantilla estructurada lista para copiar y pegar en el portal de asistencia, asegurando cumplimiento y estandarización sin fricciones técnicas.
        

## 5. Plantilla Estándar para Solicitudes de WO (RSSO)

_Esta plantilla se integrará en el generador de texto de la aplicación para copiar y pegar rápidamente en el portal corporativo:_

### Creación de ramas.

![[tickets_template#^creacion-rama]]

### Validación de estandares.
![[tickets_template#^validacion-rama]]

### Ejecución de PipeLine.

![[tickets_template#^Ejecucion-pipeline]]

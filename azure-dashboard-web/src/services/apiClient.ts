import axios, { AxiosError, type InternalAxiosRequestConfig } from 'axios';

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8001/api';

// La sesión viaja en una cookie httpOnly de Sanctum: el PAT nunca se guarda en el navegador
export const apiClient = axios.create({
    baseURL: API_BASE_URL,
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json'
    }
});

export interface ApiErrorBody {
    message?: string;
    code?: string | null;
    errors?: Record<string, string[]>;
}

// Antes de la primera escritura se pide la cookie XSRF-TOKEN de Sanctum (fuera de /api)
const csrfCookieUrl = `${new URL(API_BASE_URL, window.location.origin).origin}/sanctum/csrf-cookie`;
let csrfRequest: Promise<unknown> | null = null;
const hasCsrfCookie = () => document.cookie.split('; ').some((c) => c.startsWith('XSRF-TOKEN='));

const ensureCsrfCookie = () => {
    if (hasCsrfCookie()) return Promise.resolve();
    csrfRequest ??= axios.get(csrfCookieUrl, { withCredentials: true })
        .finally(() => { csrfRequest = null; });
    return csrfRequest;
};

apiClient.interceptors.request.use(async (config: InternalAxiosRequestConfig) => {
    if (config.method && config.method.toLowerCase() !== 'get') await ensureCsrfCookie();
    return config;
});

// Sin sesión o PAT rechazado por Azure: el router decide a dónde ir (se registra en main.ts)
let unauthorizedHandler: ((code: string | null) => void) | null = null;
export const onUnauthorized = (handler: (code: string | null) => void) => {
    unauthorizedHandler = handler;
};

// Normaliza el mensaje de error para mostrarlo directamente en la UI
apiClient.interceptors.response.use(
    (response) => response,
    (error: AxiosError<ApiErrorBody>) => {
        const data = error.response?.data;
        const firstValidationError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
        error.message = firstValidationError
            || data?.message
            || (error.response ? error.message : 'No se pudo conectar con la API. Verifica que Laravel esté corriendo.');

        if (error.response?.status === 401) unauthorizedHandler?.(data?.code ?? null);
        return Promise.reject(error);
    }
);

export const errorMessage = (error: unknown, fallback = 'Ocurrió un error inesperado'): string =>
    error instanceof Error && error.message ? error.message : fallback;

export const errorCode = (error: unknown): string | null =>
    (error as AxiosError<ApiErrorBody>)?.response?.data?.code ?? null;

export const validationErrors = (error: unknown): Record<string, string[]> =>
    (error as AxiosError<ApiErrorBody>)?.response?.data?.errors ?? {};

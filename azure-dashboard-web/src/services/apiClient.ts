import axios, { AxiosError } from 'axios';

export const apiClient = axios.create({
    baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:8001/api',
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

// Normaliza el mensaje de error para mostrarlo directamente en la UI
apiClient.interceptors.response.use(
    (response) => response,
    (error: AxiosError<ApiErrorBody>) => {
        const data = error.response?.data;
        const firstValidationError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
        error.message = firstValidationError
            || data?.message
            || (error.response ? error.message : 'No se pudo conectar con la API. Verifica que Laravel esté corriendo.');
        return Promise.reject(error);
    }
);

export const errorMessage = (error: unknown, fallback = 'Ocurrió un error inesperado'): string =>
    error instanceof Error && error.message ? error.message : fallback;

export const errorCode = (error: unknown): string | null =>
    (error as AxiosError<ApiErrorBody>)?.response?.data?.code ?? null;

export const validationErrors = (error: unknown): Record<string, string[]> =>
    (error as AxiosError<ApiErrorBody>)?.response?.data?.errors ?? {};

import { apiClient } from './apiClient';

export interface AzureUser {
    displayName: string;
    uniqueName: string;
    imageUrl?: string | null;
}

export interface CurrentUser {
    id: string;
    displayName: string;
    uniqueName: string;
    isAdmin: boolean;
    agentScheduleEnabled: boolean;
}

export const userService = {
    async search(search: string): Promise<AzureUser[]> {
        const response = await apiClient.get<{ data: AzureUser[] }>('/users', { params: { search } });
        return response.data.data;
    },

    async me(): Promise<CurrentUser> {
        const response = await apiClient.get<{ data: CurrentUser }>('/me');
        return response.data.data;
    },

    async updatePreferences(values: { agent_schedule_enabled: boolean }): Promise<CurrentUser> {
        const response = await apiClient.patch<{ data: CurrentUser }>('/me', values);
        return response.data.data;
    },
};

export const authService = {
    /** El backend valida el PAT contra Azure DevOps y abre la sesión. */
    async login(pat: string): Promise<CurrentUser> {
        const response = await apiClient.post<{ data: CurrentUser }>('/login', { pat });
        return response.data.data;
    },

    async logout(): Promise<void> {
        await apiClient.post('/logout');
    },
};

import { apiClient } from './apiClient';

export interface AzureUser {
    displayName: string;
    uniqueName: string;
    imageUrl?: string | null;
}

export interface CurrentUser {
    id: string | null;
    displayName: string;
    uniqueName: string | null;
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
};

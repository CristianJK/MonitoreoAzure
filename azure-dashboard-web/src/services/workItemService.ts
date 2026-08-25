import { apiClient } from './apiClient';

export interface WorkItem {
    id: number;
    title: string;
    state: string;
    type: string;
    assigned_to: string;
}

export const workItemService = {
    async getActiveWorkItems(): Promise<WorkItem[]> {
        const response = await apiClient.get<WorkItem[]>('/work-items');
        return response.data;
    }
};
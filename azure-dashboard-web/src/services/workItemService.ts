import { apiClient } from './apiClient';

export interface WorkItem {
    id: number;
    title: string;
    state: string;
    type: string;
    assigned_to: string;
}

export interface PaginatedWorkItems<T = WorkItem> {
    total: number;
    totalPages: number;
    page: number;
    limit: number;
    data: T[];
    summary: {
        done: number;
        inProgress: number;
        toDo: number;
    };
}

export const workItemService = {
    async getActiveWorkItems(page = 1, limit = 10): Promise<PaginatedWorkItems<WorkItem>> {
        const response = await apiClient.get<PaginatedWorkItems>('/work-items', {
            params: {
                page,
                limit
            }
        });
        return response.data;
    }
};
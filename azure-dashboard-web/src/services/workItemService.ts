import { apiClient } from './apiClient';

export interface WorkItem {
    id: number;
    title: string;
    state: string;
    type: string;
    assigned_to: string;
    assigned_to_obj?: any;
    description?: string;
    created_by?: any;
    created_date?: string;
    changed_by?: any;
    changed_date?: string;
    area_path?: string;
    iteration_path?: string;
    comments?: any[];
    history?: any[];
    relations?: any[];
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
    },

    async getWorkItemDetails(id: number): Promise<WorkItem> {
        const response = await apiClient.get<{ data: WorkItem }>(`/work-items/${id}`);
        return response.data.data;
    }
};
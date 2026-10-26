import { apiClient } from './apiClient';

export interface AzureIdentity {
    displayName?: string;
    uniqueName?: string;
    imageUrl?: string;
}

export interface WorkItemComment {
    id: number;
    text: string;
    createdBy?: AzureIdentity;
    createdDate?: string;
}

export interface LinkedPullRequest {
    id: number;
    repositoryId: string;
    title?: string;
    status?: 'active' | 'completed' | 'abandoned' | string;
    isDraft?: boolean;
    creationDate?: string | null;
    repository?: string;
    sourceBranch?: string;
    targetBranch?: string;
    createdBy?: string | null;
    url?: string;
    /** Presente cuando el PR está enlazado pero no se pudo consultar en Azure */
    error?: string;
}

export interface WorkItem {
    id: number;
    title: string;
    state: string;
    type: string;
    assigned_to: string;
    assigned_to_obj?: AzureIdentity | null;
    description?: string;
    created_by?: AzureIdentity | null;
    created_date?: string;
    changed_by?: AzureIdentity | null;
    changed_date?: string;
    area_path?: string;
    iteration_path?: string;
    comments?: WorkItemComment[];
    history?: any[];
    relations?: any[];
    pull_requests?: LinkedPullRequest[];
}

export interface WorkItemState {
    name: string;
    color: string;
    category: 'Proposed' | 'InProgress' | 'Resolved' | 'Completed' | 'Removed' | string;
}

export interface WorkItemType {
    name: string;
    states: WorkItemState[];
}

export interface WorkItemFilters {
    assignedTo?: string;
    states?: string[];
    types?: string[];
    search?: string;
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
    async getActiveWorkItems(page = 1, limit = 10, filters: WorkItemFilters = {}): Promise<PaginatedWorkItems<WorkItem>> {
        const response = await apiClient.get<PaginatedWorkItems>('/work-items', {
            params: {
                page,
                limit,
                assigned_to: filters.assignedTo || '@Me',
                state: filters.states?.length ? filters.states : undefined,
                type: filters.types?.length ? filters.types : undefined,
                search: filters.search || undefined,
            },
            // Laravel espera state[]=A&state[]=B
            paramsSerializer: { indexes: false },
        });
        return response.data;
    },

    async getWorkItemDetails(id: number): Promise<WorkItem> {
        const response = await apiClient.get<{ data: WorkItem }>(`/work-items/${id}`);
        return response.data.data;
    },

    async addComment(id: number, text: string): Promise<WorkItemComment> {
        const response = await apiClient.post<{ data: WorkItemComment }>(`/work-items/${id}/comments`, { text });
        return response.data.data;
    },

    async updateState(id: number, type: string, state: string): Promise<{ id: number; state: string; changed_date: string | null }> {
        const response = await apiClient.patch(`/work-items/${id}/state`, { type, state });
        return response.data.data;
    },

    async getWorkItemTypes(): Promise<WorkItemType[]> {
        const response = await apiClient.get<{ data: WorkItemType[] }>('/work-item-types');
        return response.data.data;
    },

    async getStates(type: string): Promise<WorkItemState[]> {
        const response = await apiClient.get<{ data: WorkItemState[] }>(`/work-item-types/${encodeURIComponent(type)}/states`);
        return response.data.data;
    },
};

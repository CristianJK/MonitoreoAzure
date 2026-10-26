import { apiClient } from './apiClient';

export type DataType = 'text' | 'textarea' | 'number' | 'date' | 'boolean' | 'url' | 'sharepoint_link' | 'select';
export type ChecklistSource = 'manual' | 'azure_branch' | 'azure_pr' | 'azure_pipeline';
export type ChecklistItemStatus = 'pending' | 'in_progress' | 'done' | 'failed' | 'skipped';

export interface Option<T extends string = string> {
    value: T;
    label: string;
}

export interface ChecklistDefinition {
    id: number;
    code: string;
    label: string;
    description: string | null;
    data_type: DataType;
    data_type_label: string;
    options: string[];
    work_item_state: string;
    blocking: boolean;
    is_required: boolean;
    applies_to_type: string | null;
    source: ChecklistSource;
    source_label: string;
    sort_order: number;
    values_count?: number;
}

export type ChecklistDefinitionInput = Omit<ChecklistDefinition, 'id' | 'code' | 'data_type_label' | 'source_label' | 'values_count'> & { code?: string };

export interface ChecklistItem {
    definition_id: number;
    code: string;
    label: string;
    description: string | null;
    data_type: DataType;
    options: string[];
    work_item_state: string;
    blocking: boolean;
    is_required: boolean;
    source: ChecklistSource;
    is_automatic: boolean;
    is_deleted: boolean;
    status: ChecklistItemStatus;
    value: string | null;
    notes: string | null;
    checked_by: string | null;
    checked_at: string | null;
    links: { label: string; url: string }[];
}

export interface ChecklistGroup {
    state: string;
    category: string | null;
    color: string | null;
    is_current: boolean;
    order: number | null;
    items: ChecklistItem[];
}

export interface ChecklistSummary {
    total: number;
    done: number;
    blocking_pending: number;
    ready_to_deploy: boolean;
}

export interface WorkItemChecklist {
    work_item: { id: number; type: string; state: string };
    states: { name: string; color: string; category: string }[];
    groups: ChecklistGroup[];
    summary: ChecklistSummary;
}

export const STATUS_LABELS: Record<ChecklistItemStatus, string> = {
    pending: 'Pendiente',
    in_progress: 'En progreso',
    done: 'Completado',
    failed: 'Fallido',
    skipped: 'No aplica',
};

export const checklistService = {
    async getDefinitions(): Promise<ChecklistDefinition[]> {
        const response = await apiClient.get<{ data: ChecklistDefinition[] }>('/checklist-definitions');
        return response.data.data;
    },

    async getDataTypes(): Promise<{ data_types: Option<DataType>[]; sources: Option<ChecklistSource>[] }> {
        const response = await apiClient.get('/checklist-definitions/data-types');
        return response.data.data;
    },

    async createDefinition(input: ChecklistDefinitionInput): Promise<ChecklistDefinition> {
        const response = await apiClient.post<{ data: ChecklistDefinition }>('/checklist-definitions', input);
        return response.data.data;
    },

    async updateDefinition(id: number, input: ChecklistDefinitionInput): Promise<ChecklistDefinition> {
        const response = await apiClient.put<{ data: ChecklistDefinition }>(`/checklist-definitions/${id}`, input);
        return response.data.data;
    },

    async deleteDefinition(id: number): Promise<void> {
        await apiClient.delete(`/checklist-definitions/${id}`);
    },

    async reorder(items: { id: number; sort_order: number }[]): Promise<void> {
        await apiClient.patch('/checklist-definitions/reorder', { items });
    },

    async getWorkItemChecklist(workItemId: number): Promise<WorkItemChecklist> {
        const response = await apiClient.get<{ data: WorkItemChecklist }>(`/work-items/${workItemId}/checklist`);
        return response.data.data;
    },

    async saveValue(
        workItemId: number,
        definitionId: number,
        payload: { value: string | boolean | null; status?: ChecklistItemStatus | null; notes?: string | null },
    ): Promise<{ item: ChecklistItem; summary: ChecklistSummary }> {
        const response = await apiClient.put(`/work-items/${workItemId}/checklist/${definitionId}`, payload);
        return response.data.data;
    },

    async getSummaries(ids: number[]): Promise<Record<string, ChecklistSummary>> {
        if (!ids.length) return {};
        const response = await apiClient.get('/work-items/checklist-summary', {
            params: { ids },
            paramsSerializer: { indexes: false },
        });
        return response.data.data;
    },
};

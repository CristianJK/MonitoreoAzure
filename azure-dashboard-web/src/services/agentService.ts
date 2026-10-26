import { apiClient } from './apiClient';

export interface DailyCommit {
    commitId: string;
    message: string;
    author: string;
    date: string;
    time: string;
    repository: string;
    files: string[];
}

export interface DailyContext {
    work_item: { id: number; title: string; type: string; state: string };
    date: string;
    pull_requests: { id: number; title: string; status: string; repository: string; sourceBranch: string; targetBranch: string; url: string }[];
    branches: { repository: string; name: string }[];
    commits: DailyCommit[];
    warnings: string[];
}

export type ApprovalStatus = 'Pendiente' | 'Publicado' | 'Rechazado' | 'Reemplazado' | 'Vencido';

export interface AgentApproval {
    id: number;
    work_item_id: number;
    work_item: DailyContext['work_item'] | null;
    action_code: 'daily_comment' | 'daily_comment_manual';
    has_commits: boolean;
    status: ApprovalStatus;
    requested_by: 'manual' | 'batch' | 'scheduled';
    resolved_by: string | null;
    date: string | null;
    proposal: string | null;
    final_text: string | null;
    generator: string | null;
    fallback_reason: string | null;
    context: DailyContext | null;
    created_at: string;
    resolved_at: string | null;
}

export interface AgentRun {
    id: number;
    azure_work_item_id: string;
    run_date: string;
    trigger: string;
    batch_id: string | null;
    status: 'queued' | 'proposal' | 'manual_required' | 'error';
    summary: { title: string; pull_requests: number; branches: number; commits: number; generator: string | null } | null;
    agent_approval_id: number | null;
    azure_comment_id: string | null;
    error: string | null;
}

export const agentService = {
    async propose(workItemId: number): Promise<AgentApproval> {
        const response = await apiClient.post<{ data: AgentApproval }>(`/work-items/${workItemId}/agent/daily-comment`, null, {
            timeout: 120_000,
        });
        return response.data.data;
    },

    async getApprovals(status?: ApprovalStatus): Promise<AgentApproval[]> {
        const response = await apiClient.get<{ data: AgentApproval[] }>('/agent-approvals', { params: { status } });
        return response.data.data;
    },

    async pendingCount(): Promise<number> {
        const response = await apiClient.get<{ data: { count: number } }>('/agent-approvals/pending-count');
        return response.data.data.count;
    },

    async approve(id: number, text: string, force = false): Promise<AgentApproval> {
        const response = await apiClient.post<{ data: AgentApproval }>(`/agent-approvals/${id}/approve`, { text, force });
        return response.data.data;
    },

    async reject(id: number): Promise<AgentApproval> {
        const response = await apiClient.post<{ data: AgentApproval }>(`/agent-approvals/${id}/reject`);
        return response.data.data;
    },

    async regenerate(id: number): Promise<AgentApproval> {
        const response = await apiClient.post<{ data: AgentApproval }>(`/agent-approvals/${id}/regenerate`, null, {
            timeout: 120_000,
        });
        return response.data.data;
    },

    async startBatch(assignedTo = '@Me'): Promise<{ batch_id: string; count: number }> {
        const response = await apiClient.post('/agent/daily-comment/batch', { assigned_to: assignedTo });
        return response.data.data;
    },

    async getRuns(batchId: string): Promise<AgentRun[]> {
        const response = await apiClient.get<{ data: AgentRun[] }>('/agent-runs', { params: { batch_id: batchId } });
        return response.data.data;
    },
};

import { apiClient } from './apiClient';

export interface Repository {
    id: string;
    name: string;
    default_branch: string;
}

export interface Branch {
    name: string;
    objectId: string;
}

export type PullRequestStatus = 'active' | 'completed' | 'abandoned' | 'all';

export interface PullRequest {
    pullRequestId: number;
    title: string;
    status: Exclude<PullRequestStatus, 'all'> | string;
    repositoryId: string;
    repository: string;
    sourceRefName: string;
    targetRefName: string;
    createdBy: string;
    creationDate: string | null;
    closedDate: string | null;
    artifactId: string;
}

export interface PullRequestSuggestion extends PullRequest {
    matchReason: string;
}

export const repositoryService = {
    async getRepositories(): Promise<Repository[]> {
        const response = await apiClient.get<Repository[]>('/repositories');
        return response.data;
    },

    async getBranches(repositoryId: string): Promise<Branch[]> {
        const response = await apiClient.get<Branch[]>(`/repositories/${repositoryId}/branches`);
        return response.data;
    },

    async linkBranch(workItemId: number, repositoryId: string, branchName: string): Promise<any> {
        const response = await apiClient.post(`/work-items/${workItemId}/link-branch`, {
            repository_id: repositoryId,
            branch_name: branchName
        });
        return response.data;
    },

    async getPullRequests(repositoryId: string, status: PullRequestStatus = 'active', search?: string): Promise<PullRequest[]> {
        const response = await apiClient.get<PullRequest[]>(`/repositories/${repositoryId}/pull-requests`, {
            params: { status, search: search || undefined },
        });
        return response.data;
    },

    /** PRs que probablemente corresponden al WI y aún no están enlazados */
    async getPullRequestSuggestions(workItemId: number): Promise<PullRequestSuggestion[]> {
        const response = await apiClient.get<{ data: PullRequestSuggestion[] }>(`/work-items/${workItemId}/pull-request-suggestions`);
        return response.data.data;
    },

    async linkPullRequest(workItemId: number, artifactId: string): Promise<any> {
        const response = await apiClient.post(`/work-items/${workItemId}/link-pr`, {
            artifact_id: artifactId
        });
        return response.data;
    },

    /** Quita el enlace del PR en el WI (el PR no se modifica en Azure) */
    async unlinkPullRequest(workItemId: number, repositoryId: string, pullRequestId: number): Promise<{ removed: boolean }> {
        const response = await apiClient.delete<{ data: { removed: boolean } }>(
            `/work-items/${workItemId}/link-pr/${encodeURIComponent(repositoryId)}/${pullRequestId}`,
        );
        return response.data.data;
    }
};

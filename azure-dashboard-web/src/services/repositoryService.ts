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

    async linkPullRequest(workItemId: number, repositoryId: string, pullRequestId: number): Promise<any> {
    const response = await apiClient.post(`/work-items/${workItemId}/link-pr`, {
        repository_id: repositoryId,
        pull_request_id: pullRequestId
    });
    return response.data;
}
};
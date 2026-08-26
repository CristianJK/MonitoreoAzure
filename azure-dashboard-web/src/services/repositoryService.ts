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

export interface PullRequest {
    pullRequestId: number;
    title: string;
    sourceRefName: string;
    targetRefName: string;
    createdBy: string;
    repository_id: string;
    artifactId: string;
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

    async getPullRequests(repositoryId: string): Promise<PullRequest[]> {
        const response = await apiClient.get<PullRequest[]>(`/repositories/${repositoryId}/pull-requests`);
        return response.data;
    },

    async linkPullRequest(workItemId: number, artifactId: string): Promise<any> {
    const response = await apiClient.post(`/work-items/${workItemId}/link-pr`, {
        artifact_id: artifactId
    });
    return response.data;
}
};
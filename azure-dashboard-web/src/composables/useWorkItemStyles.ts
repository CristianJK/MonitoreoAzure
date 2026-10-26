// Categorías de estado de Azure DevOps (Proposed, InProgress, Resolved, Completed, Removed).
// Para la tabla del dashboard solo se conoce el nombre del estado, por eso se
// mapean los nombres de los procesos estándar a su categoría.
const CATEGORY_BY_STATE: Record<string, string> = {
    'new': 'Proposed',
    'to do': 'Proposed',
    'approved': 'Proposed',
    'design': 'Proposed',
    'requested': 'Proposed',
    'in progress': 'InProgress',
    'committed': 'InProgress',
    'active': 'InProgress',
    'open': 'InProgress',
    'resolved': 'Resolved',
    'done': 'Completed',
    'closed': 'Completed',
    'removed': 'Removed',
};

export const stateCategory = (state: string | undefined, category?: string | null): string =>
    category || CATEGORY_BY_STATE[(state || '').toLowerCase()] || 'Proposed';

export const stateBadgeClasses = (state: string | undefined, category?: string | null) => {
    switch (stateCategory(state, category)) {
        case 'Completed':
            return { badge: 'bg-success/15 text-success border-success/30', dot: 'bg-success' };
        case 'InProgress':
        case 'Resolved':
            return { badge: 'bg-primary-container/20 text-primary border-primary/30', dot: 'bg-primary' };
        case 'Removed':
            return { badge: 'bg-error/10 text-error border-error/30', dot: 'bg-error' };
        default:
            return { badge: 'bg-surface-variant text-on-surface-variant border-outline-variant', dot: 'bg-on-surface-variant' };
    }
};

export const typeIcon = (type: string | undefined) => {
    const t = (type || '').toLowerCase();
    if (t.includes('bug')) return 'bug_report';
    if (t.includes('product') || t.includes('feature') || t.includes('epic')) return 'inventory_2';
    return 'task';
};

export const typeColor = (type: string | undefined) => {
    const t = (type || '').toLowerCase();
    if (t.includes('bug')) return 'text-error';
    if (t.includes('product') || t.includes('feature') || t.includes('epic')) return 'text-secondary';
    return 'text-primary';
};

export const initials = (name: string | undefined | null) => {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return name.substring(0, 2).toUpperCase();
};

export const formatDate = (dateString: string | undefined | null, withTime = true) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return dateString;
    return date.toLocaleString('es-CO', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
    });
};

import DOMPurify from 'dompurify';

// Los enlaces del HTML de Azure se abren en otra pestaña
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A') {
        node.setAttribute('target', '_blank');
        node.setAttribute('rel', 'noopener noreferrer');
    }
});

export const sanitizeHtml = (html: string | undefined | null): string =>
    DOMPurify.sanitize(html || '', { FORBID_TAGS: ['style', 'form', 'input'], FORBID_ATTR: ['style'] });

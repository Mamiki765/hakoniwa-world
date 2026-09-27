function escapeHtml(value: string): string {
    return value.replace(/[&<>"']/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[character]!);
}

/** Render only the two annotations used by Trial stories; all other text stays escaped. */
export function renderUndergroundStory(value: string): string {
    const markup = /《《([^》\n]+)》》|\|([^《\n]+)《([^》\n]+)》/g;
    let html = '';
    let position = 0;
    for (const match of value.matchAll(markup)) {
        const index = match.index ?? position;
        html += escapeHtml(value.slice(position, index));
        html += match[1] !== undefined
            ? `<em class="underground-story-emphasis">${escapeHtml(match[1])}</em>`
            : `<ruby>${escapeHtml(match[2] ?? '')}<rt>${escapeHtml(match[3] ?? '')}</rt></ruby>`;
        position = index + match[0].length;
    }
    return html + escapeHtml(value.slice(position));
}

import { describe, expect, it } from 'vitest';
import { renderUndergroundStory } from './undergroundStoryMarkup';

describe('Trial story annotations', () => {
    it('renders emphasis and ruby while escaping names and unrecognized HTML', () => {
        const container = document.createElement('div');
        container.innerHTML = renderUndergroundStory('《《魔王の娘》》と|悪魔《サキュバス》 <img src=x onerror=alert(1)>');

        expect(container.querySelector('em')?.textContent).toBe('魔王の娘');
        const ruby = container.querySelector('ruby');
        expect(ruby?.querySelector('rt')?.textContent).toBe('サキュバス');
        expect(ruby?.textContent).toBe('悪魔サキュバス');
        expect(container.querySelector('img')).toBeNull();
        expect(container.textContent).toContain('<img src=x onerror=alert(1)>');
    });
});

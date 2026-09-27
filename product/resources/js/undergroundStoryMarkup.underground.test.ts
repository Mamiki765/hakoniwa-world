import { describe, expect, it } from 'vitest';
import { renderUndergroundStory } from './undergroundStoryMarkup';

describe('Trial story annotations', () => {
    it('renders emphasis and ruby while escaping names and unrecognized HTML', () => {
        expect(renderUndergroundStory('《《魔王の娘》》と|悪魔《サキュバス》 <img src=x onerror=alert(1)>'))
            .toBe('<em class="underground-story-emphasis">魔王の娘</em>と<ruby>悪魔<rt>サキュバス</rt></ruby> &lt;img src=x onerror=alert(1)&gt;');
    });
});

// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { initAdminDocs } from '../admin-docs';

describe('Admin guides', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('does nothing outside a guide', () => {
        document.body.innerHTML = '<pre><code>ls</code></pre>';
        expect(initAdminDocs()).toBeNull();
        expect(document.querySelector('.doc-copy')).toBeNull();
    });

    it('adds a Copy button that copies the command', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } });
        document.body.innerHTML = `
            <div data-doc>
                <details data-doc-toc open><nav><a href="#build" data-doc-link="build">Build</a></nav></details>
                <div class="doc-prose"><h2 id="build">Build</h2><pre><code>./gradlew bundleRelease\n</code></pre></div>
            </div>`;
        const result = initAdminDocs();

        const button = document.querySelector('.doc-copy');
        expect(button.textContent).toBe('Copy');
        button.click();
        await Promise.resolve();
        await Promise.resolve();
        expect(writeText).toHaveBeenCalledWith('./gradlew bundleRelease');
        expect(button.textContent).toBe('Copied');
        expect(result.headings).toHaveLength(1);
    });
});

import { describe, expect, test } from 'bun:test';
import { renderMarkdown, renderMarkdownInline } from './markdown';

describe('Markdown rendering', () => {
	test('renders formatting, lists and line breaks', () => {
		const html = renderMarkdown('**strong**\nnext\n\n- one\n- two');
		expect(html).toContain('<strong>strong</strong>');
		expect(html).toContain('<br>');
		expect(html).toContain('<li>one</li>');
	});
	test('escapes raw HTML and rejects unsafe links and images', () => {
		const html = renderMarkdown('<script>alert(1)</script> [bad](javascript:alert(1)) ![x](https://example.com/a.png)');
		expect(html).not.toContain('<script>');
		expect(html).not.toContain('<img');
		expect(html).not.toContain('href="javascript:');
	});
	test('does not put links inside linked cards', () => {
		expect(renderMarkdownInline('[link](https://example.com) **bold**')).not.toContain('<a ');
		expect(renderMarkdownInline('**bold**')).toContain('<strong>bold</strong>');
	});
});

import MarkdownIt from 'markdown-it';

// Raw HTML is escaped and markdown-it rejects unsafe URL schemes by default.
const parser = new MarkdownIt({ html: false, breaks: true, linkify: false });
parser.disable('image');

export function renderMarkdown(value: string): string {
	return parser.render(value);
}

// Card links cannot contain another link; keep their formatted text non-interactive.
const inlineParser = new MarkdownIt({ html: false, breaks: true, linkify: false });
inlineParser.disable(['image', 'link']);
export function renderMarkdownInline(value: string): string {
	return inlineParser.renderInline(value);
}

import MarkdownIt from 'markdown-it'
import hljs from 'highlight.js/lib/common'

/** The class of the placeholders MarkdownView replaces with the drawn diagrams */
export const MERMAID_CLASS = 'mermaid-diagram'

const markdown: MarkdownIt = new MarkdownIt({
  // Raw HTML stays text, and markdown-it refuses javascript: links : the pages are written by every member
  // and by agents, then shown to the others
  html: false,
  linkify: true,
  highlight: (code, language) => (language && hljs.getLanguage(language) ? hljs.highlight(code, { language, ignoreIllegals: true }).value : ''),
})

const renderFence = markdown.renderer.rules.fence!
markdown.renderer.rules.fence = (tokens, index, options, env, renderer) => {
  const token = tokens[index]!
  return token.info.trim() === 'mermaid'
    ? `<div class="${MERMAID_CLASS}">${markdown.utils.escapeHtml(token.content)}</div>`
    : renderFence(tokens, index, options, env, renderer)
}

// A link out of Tower opens beside it : leaving would lose a page being edited
markdown.renderer.rules.link_open = (tokens, index, options, _env, renderer) => {
  const token = tokens[index]!
  if (/^https?:\/\//i.test(token.attrGet('href') ?? '')) {
    token.attrSet('target', '_blank')
    token.attrSet('rel', 'noopener noreferrer')
  }
  return renderer.renderToken(tokens, index, options)
}

export function renderMarkdown(source: string): string {
  return markdown.render(source)
}

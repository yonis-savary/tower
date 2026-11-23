// Shift+Tab also undoes an indentation pasted as spaces, up to the width of one tab
const SPACES_PER_TAB = 4

export function indentLines(block: string): string {
  return block
    .split('\n')
    .map((line) => (line === '' ? line : `\t${line}`))
    .join('\n')
}

export function dedentLines(block: string): string {
  return block
    .split('\n')
    .map((line) => (line.startsWith('\t') ? line.slice(1) : line.replace(new RegExp(`^ {1,${SPACES_PER_TAB}}`), '')))
    .join('\n')
}

export const MCP_SERVER_NAME = 'tower'

export function mcpUrl(): string {
  return `${window.location.origin}/mcp`
}

export function claudeCodeCommand(url: string, token: string): string {
  return `claude mcp add --transport http ${MCP_SERVER_NAME} ${url} --header "Authorization: Bearer ${token}"`
}

export function mcpJsonConfig(url: string, token: string): string {
  const config = { mcpServers: { [MCP_SERVER_NAME]: { type: 'http', url, headers: { Authorization: `Bearer ${token}` } } } }
  return JSON.stringify(config, null, 2)
}

// Claude Desktop only launches local programs from its configuration file : mcp-remote relays to the HTTP server
export function claudeDesktopConfig(url: string, token: string): string {
  const config = {
    mcpServers: {
      [MCP_SERVER_NAME]: {
        command: 'npx',
        // No space in the argument : some clients split arguments on spaces, mcp-remote advises the token in env
        args: ['-y', 'mcp-remote', url, '--header', 'Authorization:${TOWER_AUTH}'],
        env: { TOWER_AUTH: `Bearer ${token}` },
      },
    },
  }
  return JSON.stringify(config, null, 2)
}

# Agent guidelines

This application is built on the Cube framework. Before writing code, read
`vendor/yonis-savary/cube/ai/FRAMEWORK.md` and follow it. The full documentation is in
`vendor/yonis-savary/cube/docs/`, and the framework source in `vendor/yonis-savary/cube/src/`.

@vendor/yonis-savary/cube/ai/FRAMEWORK.md

## Project rules

### Every feature has an MCP side

Tower is also an MCP server (`/mcp`, see `ARCHITECTURE.md` § Serveur MCP) : agents use the same
routes as the frontend. When you add or change a feature, decide in the same change what an agent
can do with it :

- **Expose a route as a tool** with `#[McpTool('snake_case_name')]`, and `write: true` when it
  changes data or sends events : a write tool is only listed for a write token on a workspace
  open to writing. Nothing else is needed, the schema comes from the slugs and the `Request` rules.
- **Write the `#[Endpoint]` description for an agent too** : what it returns, how to narrow it.
- **Keep answers small** : a list that can grow takes filters or pages (`Param` rules), so that an
  agent does not have to read the whole table. A file answers with its own content type and a size limit :
  an image reaches the agent as an image.
- **Never expose** secrets and access management (keys, webhook secrets, integration credentials,
  members, API tokens), nor deletions.
- Add the tool to the table of `Frontend/src/views/docs/{fr,en}/DocsAgents.vue`, and a case to
  `Tests/Integration/McpTest.php` when the call is not a plain JSON route.
- A new source of activity also has its place in the history origins and the home circuit
  (`HomeFlow`).

### Helpers for recurring expressions

A short expression repeated across the project becomes a namespaced function in `App/Helpers/`
(loaded at boot by Cube), rather than a private method copied into each class. A helper that uses a
component takes it as a nullable last parameter, like any method outside a constructor.

- `App\currentWorkspace()` / `App\currentWorkspaceId()` : the workspace the logged user, or the
  MCP token, acts on.

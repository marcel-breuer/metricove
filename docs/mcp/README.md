# MCP server guide

Metricove ships a [Model Context Protocol](https://modelcontextprotocol.io) server so AI agents can read and, with human approval, change household data. It is a thin layer over the same domain services, policies and module gates as the web UI and the [REST API](../api/README.md). There is no parallel business logic and no privilege shortcut.

- Endpoint: `POST /api/mcp` (stateless Streamable HTTP, JSON-RPC). `OPTIONS` answers CORS preflight.
- Authentication: a user-managed bearer token (`mtv_...`). OAuth discovery is not provided.
- Catalogue: see the [tool catalogue](tools.md). Use `tools/list` for what your token can currently do.

## Create a token

1. Sign in, open **Profile**, then **MCP access tokens**.
2. Choose a name, the scopes the agent needs and an expiry (1 to 365 days, or none).
3. Copy the secret immediately. It is shown once and only its SHA-256 hash is stored.

Scopes are `{module}:{view|create|edit|manage}` plus `core:profile:view` and `core:reminders:view`. Offer the minimum: read-only scopes for reporting agents, `create` or `edit` only where the agent should propose changes. Revoke a token at any time in the same place.

## Client setup

Use your installation's address in place of `https://metricove.example`. Store the token in an environment variable or secret manager, not in a file that is committed.

Claude Code:

```sh
claude mcp add --transport http metricove https://metricove.example/api/mcp \
  --header "Authorization: Bearer $METRICOVE_MCP_TOKEN"
```

Claude Desktop, Cursor, VS Code and other clients that read an `mcpServers` JSON file:

```json
{
  "mcpServers": {
    "metricove": {
      "type": "http",
      "url": "https://metricove.example/api/mcp",
      "headers": { "Authorization": "Bearer ${METRICOVE_MCP_TOKEN}" }
    }
  }
}
```

Clients that only speak stdio can use a bridge such as `mcp-remote`:

```sh
npx mcp-remote https://metricove.example/api/mcp --header "Authorization: Bearer ${METRICOVE_MCP_TOKEN}"
```

Check the connection with any client's tool listing, or by hand:

```sh
curl -s https://metricove.example/api/mcp \
  -H "Authorization: Bearer $METRICOVE_MCP_TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
```

The exact protocol headers depend on the MCP revision your client negotiates; the bundled PHP SDK defines what the server accepts. Browser-based clients on another origin need the origin listed in `MCP_ALLOWED_ORIGINS` (comma-separated). Host validation follows `APP_URL`.

## Permission model

- A token never grants access on its own. Every call recomputes the owner's active state, enabled modules, member capabilities and per-record visibility.
- Tools appear in `tools/list` only when the token holds the required scopes and the owner still has the matching grants. Disabling a module or revoking a grant removes the tools immediately.
- Private records stay private, including from administrators, unless a resource-level grant allows access. Tools return only what the token owner may see.
- Network access restrictions (allowed IP ranges) apply to MCP like any other API request.

## Write approval flow

Writes never apply directly.

1. **Preview.** The agent calls a `preview_*` tool. The server validates the typed arguments, rechecks access and visibility, and stores an encrypted preview that expires after 10 minutes.
2. **Approval.** The token owner reviews the complete proposed change in Metricove (Profile, MCP access) and approves or rejects it. The agent cannot approve.
3. **Completion.** The agent calls `complete_approved_mcp_change` (or `complete_approved_utility_reading`) with the `preview_id` and a fresh UUID `idempotency_key`. Retrying with the same key returns the original result; a different key cannot replay it.
4. **Audit.** Previews and executions are written to the household audit log with token id and module, without payload content such as note text.

Records that members can edit concurrently use `expected_version`; a stale version fails at completion. Access is re-verified at preview, approval and completion.

## Safety

- No raw SQL, no arbitrary commands: only typed, registered operations with strict schemas.
- Rate limit: 60 requests per minute per client IP.
- Responses never include credentials. Receipt and invoice files, meter photos and attachments are not retrievable. Tool descriptions contain no secrets or household data.
- Token secrets and action payloads are not logged.
- Treat everything an agent reads as untrusted input for that agent: stored notes or descriptions can contain text that tries to steer it. The approval step exists so a human sees every change.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `401` with `WWW-Authenticate: Bearer` | Missing, revoked, expired or malformed token, or the owner is inactive |
| A tool is missing from `tools/list` | Token lacks the scope, module disabled, or the owner lost the capability |
| Completion says the change could not be completed | Not approved yet, expired (10 minutes), stale `expected_version`, or access changed |
| `429` | Rate limit reached; back off |
| CORS or host errors in a browser client | Add the origin to `MCP_ALLOWED_ORIGINS` and check `APP_URL` |

More detail on module gating and the coverage matrix is in the [module contract](../modules/README.md#mcp-external-access).

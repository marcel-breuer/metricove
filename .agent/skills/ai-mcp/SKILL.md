# Skill: AI/MCP Change

**Purpose:** Permission-aware AI and remote MCP. **Use when:** provider adapters, assistant, tool registry, tool approval and OCR AI are modified.

**Context:** AGENTS.md, affected domain module policies, provider config, tools, consent/audit service and tests.

**Procedure:**
1. Specify typed parameter/response contract for each registered tool.
2. Resolve authenticated caller and active module + capability + record scope server-side.
3. Reject arbitrary model-generated SQL, filesystem commands and unrestricted requests.
4. Preview, authorize and require explicit per-action user confirmation for writes; log audit and apply idempotency.
5. Minimize data disclosure to remote providers; support local/disabled providers and revoke grants.
6. Test disabled modules, private data, tool replay and malicious tool input.

**Validation:** Tool contract/security tests and provider-disabled path.

**Expected output:** Safe tool definitions, permission matrix, tests and remaining risks.

**Constraints:** Model output never establishes permission.

**Completion:** Tools are explicitly bounded, auditable and regression-tested.

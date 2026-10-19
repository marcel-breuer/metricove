# Security Policy

Metricove is in pre-release development; there is no supported production release yet.

**Report security vulnerabilities privately**, preferably via GitHub's private vulnerability reporting feature when enabled. Do not include exploit details, tokens or personal data in public issues. If private vulnerability reporting is unavailable, request a private contact channel through GitHub without disclosing sensitive details.

Security changes require tests for setup and role races, the final active administrator, single-use email-bound invitations, resource ownership, disabled modules, import/upload validation and AI/MCP authorization. Runtime secrets are passed using private environment variables, never committed. No public source document may contain instance-specific server names.

Security support and reporting contacts will be finalized before the first stable release.

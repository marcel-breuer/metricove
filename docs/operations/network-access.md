# Network access restrictions

An active household administrator can set a household-wide IP allowlist under **General settings → Network access**. Enter one IPv4 or IPv6 address or CIDR network per line; commas are also accepted. Entries are normalized when saved. Up to 100 entries are supported.

An empty list leaves the installation available from any IP address. A non-empty list restricts API requests, including sign-in, invitations and MCP, to matching addresses. The API health endpoint remains available for monitoring. Static web assets may still be served, but the application does not show household pages or load household data when the address is outside the allowlist.

The settings page shows the client address observed by the API. Include that address or a network containing it before saving, or connect from an allowed network before changing the restriction later. Removing every entry disables the restriction.

## Reverse proxies

By default, Metricove uses the peer address observed by the gateway and ignores `X-Forwarded-For`. This is safe for direct connections and prevents clients from choosing their own address with a forged header.

When a reverse proxy sits in front of the Compose gateway, add only the proxy addresses or CIDR networks that you control to the comma-separated `TRUSTED_PROXIES` environment variable. The proxy must set or append the client's address in `X-Forwarded-For`. Metricove walks that chain from the nearest proxy and selects the first address that is not itself a trusted proxy. Do not configure `*` or a network that includes untrusted client devices.

Example for a deployment whose reverse proxy connects from a single private address:

```dotenv
TRUSTED_PROXIES=10.20.0.5
```

After changing the environment, recreate the API service so Laravel receives the updated configuration:

```sh
docker compose up -d --force-recreate api
```

An incorrectly configured trusted-proxy list can make the displayed address refer to a proxy or leave it undetermined. Verify the observed client address from the admin settings page before enabling the allowlist.

## Audit and privacy

Changes to the allowlist are recorded in the household audit log with the enabled state and entry count. The configured IP addresses and networks are not copied into audit details.

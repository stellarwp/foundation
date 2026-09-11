# Foundation Documentation

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

The source for the Foundation documentation site.

## Local Development

Use Node.js 24 and install the locked npm dependencies:

```shell
nvm install
npm ci
npm run dev
```

Astro prints the local documentation URL after the development server starts.

Create a production build with:

```shell
npm run build
```

Preview the production build with Cloudflare Pages locally:

```shell
npm run preview:cloudflare
```

Wrangler serves the site at `http://localhost:8788`. Press `t` in that terminal
to create a temporary public Cloudflare Tunnel URL for sharing the preview.
The tunnel is publicly accessible and must not be used for production.

## Deployment

Documentation previews deploy from same-repository pull requests in
`stellarwp/foundation`. Production deploys only when a stable release tag reaches
the read-only `stellarwp/foundation-docs` split repository.

Both workflows use a Direct Upload Cloudflare Pages project named
`foundation-docs` whose production branch is `production`. The Cloudflare API
token requires `Account > Cloudflare Pages > Edit`. The
`CLOUDFLARE_DEPLOY_ACCOUNT_ID` and `CLOUDFLARE_DEPLOY_TOKEN` organization Actions secrets
must be available to both repositories.

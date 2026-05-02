# Nour Docs Site

Static documentation site for the Nour framework. Single-page,
hash-routed, no build step — open `index.html` in a browser and it
works.

## Local preview

Any static server works. With Python:

```bash
cd site
python -m http.server 8080
# → http://localhost:8080
```

With PHP:

```bash
cd site
php -S localhost:8080
```

With Node:

```bash
npx --yes serve site
```

## File layout

```
site/
├── index.html       # shell — header, sidebar, content, right TOC
├── styles.css       # light + dark theme
├── app.js           # markdown loader, hash router, TOC builder
├── favicon.svg      # the ن mark
├── docs/            # rendered content (synced from /docs at deploy time)
│   ├── README.md
│   ├── 01-getting-started.md
│   ├── 02-configuration.md
│   └── ...
└── README.md        # this file
```

`docs/` here is a copy of the repo's top-level `docs/` folder.
The GitHub Actions workflow at
`.github/workflows/deploy-docs.yml` rsyncs them on every push so
editing `.md` files in `docs/` automatically updates the live
site.

## Deploying to GitHub Pages

The repo ships with a workflow that:

1. Triggers on push to `main` whenever `site/**` or `docs/**`
   changes.
2. Syncs `docs/` → `site/docs/` (so `docs/*.md` is the single
   source of truth).
3. Uploads `site/` as the Pages artifact.
4. Deploys via `actions/deploy-pages@v4`.

### One-time GitHub setup

1. **Push the repo** to GitHub if it isn't already.
2. **Enable Pages** — Settings → Pages → "Source: GitHub Actions".
3. **Push a commit** that touches `site/` or `docs/`. The workflow
   runs, the deploy URL appears in Settings → Pages
   (`https://<user>.github.io/<repo>/`).

After the first successful deploy, future pushes auto-deploy.

### Custom domain

Add a `site/CNAME` file containing the domain (one line, e.g.
`docs.example.com`). Configure the DNS A/CNAME records per the
[GitHub docs](https://docs.github.com/en/pages/configuring-a-custom-domain-for-your-github-pages-site).

## Deploying elsewhere

Any static host works. Just upload the contents of `site/` after
ensuring `docs/` is in sync:

```bash
# Sync docs first
rsync -av --delete docs/ site/docs/

# Upload site/ to your host (S3, Netlify, Vercel, plain Apache, ...)
```

## Editing the content

Source markdown lives in `docs/*.md`. Edit there; commit; push;
the GitHub Actions workflow handles the rest. Section structure:

| File | Topic |
|---|---|
| `docs/README.md` | Introduction, when to use, when not |
| `docs/01-getting-started.md` | Install + first project |
| `docs/02-configuration.md` | Every key in setup.json + sitting.json |
| `docs/03-routing.md` | FilesMap.json + handlers + Router |
| `docs/04-middleware.md` | Pipeline + 4 built-ins + custom |
| `docs/05-events.md` | Dispatcher + lifecycle events |
| `docs/06-validation.md` | Validator + 16 rules + extend() |
| `docs/07-databases.md` | MySQL/Postgres/Redis pools + helpers |
| `docs/08-websocket.md` | Handshake events + dispatch + rooms |
| `docs/09-webhooks-and-timers.md` | Webhooks.json + Timers.json |
| `docs/10-plugins.md` | ProviderInterface + PluginLoader |
| `docs/11-cli.md` | bin/nour + 11 commands + migrations |
| `docs/12-deployment.md` | Production Docker + nginx + tuning |

To add a new page:

1. Write `docs/13-my-topic.md`.
2. Add an entry to the `PAGES` and `TITLES` maps in
   `site/app.js`.
3. Add a `<li>` in the right `nav-section` of `site/index.html`.
4. Commit + push — the workflow rebuilds.

## How it works

`app.js` is a 200-line single-page client:

1. Hash router (`#/01-getting-started` → fetch `docs/01-getting-started.md`).
2. Renders markdown via [marked.js](https://marked.js.org) (loaded from CDN).
3. Highlights code via [Prism.js](https://prismjs.com) (loaded from CDN).
4. Builds an "On this page" TOC from `<h2>` / `<h3>` headings.
5. Tracks the active section via `IntersectionObserver`.
6. Persists theme preference in `localStorage`.

No build step, no npm dependencies in this folder — Marked and
Prism are pulled at runtime from jsDelivr. Apps that need offline
rendering can self-host those by replacing the `<script src="…">`
URLs in `index.html`.

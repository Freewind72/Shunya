# dsh-msapi

English | [中文](README.zh.md)

Embeds the [Msapi](https://msapi.bmwy72.top) music player into DeepSeek Harness. Fill in two
fields on **Settings → Music Player** — a site URL and an API key — and the player docks onto the
current window. No extra browser tab, no window switching, no separate player to babysit.

```
┌─ Music Player ────────────────────────────────────────┐
│  Site URL                                             │
│  ┌─────────────────────────────────────────────────┐  │
│  │ https://msapi.bmwy72.top                        │  │
│  └─────────────────────────────────────────────────┘  │
│  Leave empty to use the official site                 │
│                                                       │
│  API key                                              │
│  ┌─────────────────────────────────────────────────┐  │
│  │ ••••••••••••••••••••••••                        │  │
│  └─────────────────────────────────────────────────┘  │
│  Stored · fingerprint a1b2c3d4 · 32 chars             │
│                                                       │
│  ● Connected · 3 playlists · 128 songs · 182ms        │
│                            [Save] [Test] [Turn off]   │
└───────────────────────────────────────────────────────┘
```

---

## Contents

- [What it does](#what-it-does)
- [Install](#install)
- [Configuration](#configuration)
- [Where state lives](#where-state-lives)
- [How it works](#how-it-works)
- [Security](#security)
- [Known limits](#known-limits)
- [Troubleshooting](#troubleshooting)
- [Development](#development)
- [License](#license)

## What it does

- Adds a **Music Player** tab to the Settings sidebar. That page has exactly **two inputs** — a
  site URL and an API key — plus buttons and one status line.
- An empty URL means the official site, `https://msapi.bmwy72.top`.
- Saving probes the site once and reports, on one line, whether the gateway answered, whether the
  key was accepted, and how many playlists and songs the account holds.
- With a valid key and the player switched on, the Msapi embed script is injected and the player
  appears at the bottom right, just above the composer.
- The player's own immersive mode fills **the viewport this plugin hands it**, not the whole screen.
- Volume, playback mode, the last song and the resume position survive a DSH restart — which they
  do not do on their own inside the desktop shell. See [Where state lives](#where-state-lives).

## Install

The project lives at **<https://github.com/Freewind72/dsh-msapi>**.

The DSH Desktop `desktop` profile installs plugins through the GUI only — the CLI refuses it:

1. Open **Settings → Plugins**.
2. Choose **install by package**, and give it:

   ```
   github:Freewind72/dsh-msapi
   ```

3. Enable it, then reload the page (`Ctrl+R`).

`github:owner/repo` is the shorthand the plugin manager hands to pnpm, so it fetches the repository
directly — no npm publish needed. A published package name would work the same way.

Working on a local checkout instead? Use **install from a local directory** and point it at your
clone; the trade-off is that you have to run `pnpm install` yourself if you ever add a dependency.

A restart of DSH — not just a page reload — is required after changing any file in `lib/`, because
the host reads and caches the client bundle at startup.

## Configuration

| Field | Meaning |
|---|---|
| **Site URL** | The site **root**, e.g. `https://msapi.bmwy72.top`. Empty = official site, also used when the field is left blank. A full endpoint such as `https://x.top/admin/api/api.php` is trimmed back to its root automatically. |
| **API key** | The key from the Msapi admin **Keys** page. After saving, the browser only ever sees a fingerprint. |

The status line reports one of:

- `Connected · 3 playlists · 128 songs · 182ms`
- `The key is not valid` / `The key is disabled` / `The gateway received no key`
- `Cannot reach the server`

**Save** writes the settings and probes once. **Test** probes the values currently in the fields
without saving them. **Turn on / Turn off** controls whether the player is mounted at all, so you
can keep a key configured without a player on screen.

### Self-hosting

Prefer not to depend on the official site? [Shunya](https://github.com/Freewind72/Shunya) is the
companion self-hosted server — run your own Msapi instance and point the plugin at
`http://127.0.0.1:port`. Loopback and private addresses are allowed; the probe only refuses
link-local targets such as cloud metadata endpoints (see [Security](#security)).

## Where state lives

```
$DSH_HOME/dsh-msapi/config.json      (mode 0600)
```

One file only:

```jsonc
{
  "schemaVersion": 1,
  "url": "",                                  // as typed; "" means the official site
  "key": "…",                                 // never sent back to the browser
  "gatewayPath": "/admin/api/api.php",        // the path that last answered
  "enabled": true,
  "updatedAt": "2026-11-11T09:33:49.745Z",    // when the file was last written
  "lastProbe": { "…": "…" },                  // so the page can show a status line at load
  "playerState": {                            // the emulated cookie jar
    "mapi_cookie_consent": "granted",
    "mapi_state_dsh-msapi": "{\"v\":2,…}"
  }
}
```

Writes are atomic (`.tmp` → rename), so a crash mid-write leaves the previous file intact. A file
that fails to parse is renamed to `config.json.corrupt-<timestamp>` and defaults take over — a
half-written file must never wedge the plugin.

Deliberately **not** used for storage:

- **`cordis.patch.yml`** — DSH treats that document as shareable. A secret does not belong there.
- **`localStorage`** — scoped to an origin, and an origin includes the port.
- **Cookies** — see below.

### Why the player's memory cannot use cookies

The player keeps everything in `document.cookie`: `core/state.js` writes `mapi_state_<instance>`,
and `core/auth.js` reads `mapi_cookie_consent` before it will boot at all.

**Cookies do not work on the `dsh-app://` scheme the desktop shell serves the UI from.** Assignment
is silently ignored and the getter always returns `""` — no error, no warning. A diagnostics report
from a live page settled it: `href: "dsh-app://app/"`, an empty jar, the player running happily.

So the jar is emulated. The plugin replaces the player's own cookie seam:

```js
MP._.setCookie / getCookie / delCookie   ← redirected to an in-memory jar
```

Every part of the player (`state.js`, `auth.js`, `theme.js`) already goes through those three, so
redirecting them is enough and the player's code is untouched. The durable copy lives in
`config.json` under `playerState`:

- before booting the player the page calls `GET /dsh-msapi/state` and refills the jar, so
  `loadState` sees what looks like a cookie it just wrote itself;
- afterwards it pushes changes on a 4s tick and on `pagehide`, and **only when the jar is dirty** —
  the player rewrites its cookie as often as twice a second, so a naive timer would flood the host;
- the browser sends its whole jar, and the host **replaces** rather than merges, so a cookie the
  player deleted actually disappears.

#### The consent cookie is the master switch

`api.php` boots as `verifyKey → showConsentBanner(callback) → get-config`. An unanswered cookie
banner therefore does not merely switch persistence off — it **stops the player from loading at
all**, playlists included.

With the bridge in place the banner reads the emulated jar, which already says `granted`, so it
never renders. If one was on screen before the bridge landed, the 4s tick accepts it on the user's
behalf. Only ever *accept*: silently answering "deny" would be worse than leaving the question
open.

## How it works

The interesting part is the viewport, because the player was written to own the whole screen.

### The viewport

The player's host element is moved into a container this plugin owns. That container sets
`transform`, which makes it the **containing block** for every `position: fixed` descendant — so
the player's idea of "the screen" becomes this box, and the plugin decides its size.
`isolation: isolate` keeps the player's `z-index: 2147483647` from climbing over DSH's dialogs.

### Clearing the caption bar

DSH Desktop draws minimise / maximise / close *inside* the window, so the container must not run to
the very top.

The clearance is **measured in JavaScript**, not hardcoded. DSH's `lib/preload-app.cjs` writes:

```js
root.dataset.windowsTitlebar = "";
root.style.setProperty("--dsh-windows-titlebar-height", "40px");
```

The plugin reads that inline property, falls back to the marker attribute (Windows 40px, macOS
48px, fullscreen 0), and writes the result as an **inline** `--dms-top-clear` on the container. An
earlier version derived it purely in CSS; when the selector missed, the entire `clip-path` was
dropped and the player sat underneath the caption buttons.

`clip-path`, not a `top` inset: an inset only moves where the box starts, while a dragged player
writes absolute coordinates and would still paint over the caption bar. Clipping removes that strip
from painting *and* hit-testing without changing any child coordinate, so the player's own drag
maths stays correct.

Two things sit inside that clipped strip and need shifting down by the same amount, or they would
be invisible:

- the player's toast layer, pinned at `top: 16px` — otherwise "the key is not valid" never shows;
- the immersive layer's close button, which lives in a bar at `top: 0`.

### The immersive layer

That layer is inside a `attachShadow({mode: "closed"})` tree, which no outside selector can reach.
What *is* reachable is the shadow root the player keeps on itself (`MP._hostRoot`), so the plugin
appends a `<style>` there, moves the whole layer below the clearance, and re-applies the skin's own
top padding to the close area so the button lands where its author intended.

### The lyric strip

The strip is attached to the host page's `body` (the skins use a closed shadow root, so core keeps
it outside) and core sizes it as `span.scrollWidth + 42`. That 42 is 40px of horizontal padding plus
a 2px breath, and it only equals the pill's *total* width under `box-sizing: border-box` — which
Msapi's own stylesheet sets globally (`assets/css/pc.css`: `*, *::before, *::after`).

Embedded in DSH the strip inherits no such reset, so the same `width` becomes a *content* width and
40px of padding lands on top: the pill paints `scrollWidth + 82` wide against `scrollWidth + 42` of
text. That surplus is the gap after the words, and the skewed ratio is what reads as "deformed".
The plugin pins the strip back to `border-box` and leaves the skin's padding alone.

### Moving the player area

A 10px handle sits on the viewport's top edge and turns blue on hover. Dragging it moves the whole
player area down, out of the way of anything covering it; drag it back to the minimum and it sits
just below the caption bar. The handle follows the clearance automatically and moves to the very
top in fullscreen. The position is remembered for the session only.

## Security

**The key appears in the page as a `<script src>` query parameter.**

That is the Msapi embedding contract — the official snippet is
`<script src="https://site/api.php?key=YOUR_KEY"></script>`, and the player needs the key to call
its own API. What this plugin does protect:

- the key lives only in the local `config.json` (mode 0600);
- the Settings page never echoes it — only the first 8 hex characters of its SHA-256;
- `GET /dsh-msapi/settings` never returns it to the browser.

The `<script src>` line in the DOM is unavoidable, and any same-origin script or browser extension
can read it. If that is unacceptable in your environment, do not enable the player there.

Also worth knowing:

- Mutations (`PUT`, `POST`) require the GUI's signed session cookie, the same gate
  `dsh-windows-mcp` uses. A script, or a model with shell access, cannot repoint the player at a
  site of its choosing.
- The probe refuses link-local (`169.254.*`, `fe80:*`) and unique-local (`fc00::/7`) addresses. It
  fetches from the host process, which sits behind no proxy, so an unrestricted URL field would be
  an SSRF primitive against cloud metadata endpoints. Loopback and private ranges stay allowed on
  purpose: a self-hosted Msapi on `http://127.0.0.1:8080` is a supported setup.
- An unexpected 500 returns `internal error`, not the underlying message, which could carry a
  filesystem path. The detail goes to the host log.

## Known limits

- **Domain authorization**: when Msapi binds a key to an allowed domain it judges the request by its
  `Referer` / `Origin`. The page runs on the `dsh-app://` scheme, which normally sends neither, so
  the check is skipped. Should that change and a request be refused as `domain_blocked`, add the
  reported host on the Msapi Domains page.
- **Renamed admin directory**: the gateway is probed as `/admin/api/api.php` then `/api/api.php`,
  and the working path is remembered. The embed script needs no help — `api.php` injects the real
  directory name itself.
- **One site and one key at a time.**
- **Not a general-purpose player**: it embeds Msapi, and inherits whatever Msapi supports.

## Troubleshooting

**The plugin does not appear, and DSH says the package is invalid.**
Almost always a **UTF-8 BOM** at the start of `package.json`. DSH loads it with a bare
`JSON.parse(readFileSync(path, "utf8"))`, and a BOM makes that throw — reported as an invalid
package, with every route 404 and no other clue. Check the first three bytes are not `EF BB BF`:

```powershell
[System.IO.File]::ReadAllBytes('package.json')[0..2] | % { $_.ToString('X2') }
```

**Code changes have no effect, even after a page reload.**
The host reads and caches the client bundle at startup, and the `?rev=` in the bundle URL is derived
from file metadata at that moment. A page reload cannot pick up new code. Restart DSH.

**The player overlaps the caption buttons.**
`--dsh-windows-titlebar-height` was not readable. The plugin falls back to the
`data-windows-titlebar` marker (40px), so this should not happen; if it does, report the value
`document.documentElement.style.getPropertyValue("--dsh-windows-titlebar-height")` returns.

**Volume, mode and resume position are forgotten.**
Check the emulated jar actually holds something:

```powershell
Get-Content "$env:USERPROFILE\.dsh\dsh-msapi\config.json" | Select-String playerState
```

If `playerState` is `null`, the cookie bridge never ran. `GET /dsh-msapi/diagnostics` explains what
the page observed — whether `apply` ran, how many embed scripts were injected, what the jar holds,
and what the last host call returned.

**Nothing plays, but the status line says Connected.**
The key is fine and the gateway answers; the problem is downstream. The player shows its own notice
("no playlists yet", "the CDN is unreachable"), and those notices are what the top-clearance shift
above exists to keep visible.

## Development

```bash
node --check lib/index.js
node --check lib/client.js
```

```
lib/
  index.js          host half: five routes, the auth gate, error shaping
  config.js         config.json I/O, URL normalisation, host allow-list
  msapi.js          one probe against a Msapi gateway
  player-state.js   the emulated cookie jar
  client.js         prebuilt browser bundle (settings page, viewport, patches)
```

`lib/client.js` is a **prebuilt** browser bundle in the `window.__ModuleLoader__.load({...})` form,
hand-written with `React.createElement` and no build step — the host serves built bundles as files,
it does not compile them. Keeping it that way is deliberate: a plugin that needs a build toolchain
is a plugin most people cannot patch.

## License

[MIT](LICENSE). Free to use, study, modify, share — including commercially.

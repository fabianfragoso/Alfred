# Alfred

A personal voice assistant powered by Claude.

## What's here

| Path | What it is |
| --- | --- |
| `public/index.html` | The web app: hands-free voice ("Alfred, …"), spoken replies, timers |
| `.cpanel.yml` | Tells cPanel where to copy the site when you click **Deploy** |

## Using it

1. Open the site in Chrome or Edge (it must be **https** for the microphone).
2. Open **Settings**, paste your Anthropic API key. It is stored only in that browser.
3. Press **Start listening** and say "Alfred, what time is it?"

## Deploying to FastComet (cPanel)

1. cPanel → **Git™ Version Control** → **Create** → clone this repo.
2. Check the path in `.cpanel.yml` matches where you want Alfred served.
3. **Manage** → **Pull or Deploy** → **Update from Remote**, then **Deploy HEAD Commit**.

## Rules

- Never commit API keys, passwords or tokens.
- Alfred must always ask for confirmation before sending any message or email.

## Roadmap

- [x] Web app with wake word
- [x] iPhone Siri Shortcut ("Hey Alfred" via Vocal Shortcuts)
- [ ] Email: read, summarize, draft, send after confirmation
- [ ] Mac mini hub: always-on listening, Wake-on-LAN, smart home

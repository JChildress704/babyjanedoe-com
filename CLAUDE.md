# CLAUDE.md

Wedding website for Justin & Mikaela (April 24, 2027, Knoxville, TN). See `README.md` for structure, Hostinger setup, and the RSVP guest-list format. This file covers what isn't obvious from reading the code.

## Ground rules

- **Pushing to `main` deploys to production immediately** (GitHub Actions → FTP to Hostinger). Commit and push only when asked; after pushing, watch the run (`gh run watch`) and spot-check the live page with `curl`.
- **The GitHub repo is public.** Never commit guest names, passwords, or email addresses. `rsvp-data/`, `rsvp-data-example/config.php`, and `rsvp-data-example/rsvps.json` are gitignored for this reason; the committed template is `rsvp-data-example/config.example.php`. Check `git add --dry-run` before committing anything near those folders.
- No build step, no frameworks, no package installs. Pages are plain HTML with inline CSS/JS; the backend is a single PHP file.

## Pages and shared design

| Path | What it is |
|---|---|
| `index.html` | Save-the-date ("Info" in the nav) |
| `rsvp/index.html` | Guest RSVP flow (password → last-name search → respond) |
| `rsvp/admin/index.html` | Admin dashboard (not in the nav; separate password) |
| `rsvp/api.php` | Backend for both RSVP pages |

There is no shared stylesheet or template: each public page repeats the same base CSS and markup. When adding a page (such as Travel), copy from `rsvp/index.html` and keep these consistent:

- **Palette:** background `#90CDD1`, text `#3B2E4A` (secondary `#4A3D5C`), accent `#F46F30` (hover `#CD4D1A`).
- **Font:** Cormorant Garamond from Google Fonts.
- **Building blocks:** `.std-label` (uppercase, letter-spaced label), `.std-divider` (line–diamond–line).
- **Side photos ("rails"):** `images/image_left.jpg` and `image_right.jpg` are fixed full-height columns on desktop (over 700px wide) and hidden on mobile. The left one uses `object-position: 90% center` to keep the couple, who are on the right of the photo, in frame. Pages in subfolders reference them as `../images/...`.
- **Nav bar** (`.site-nav`): duplicated in every public page. Links are root-relative (`/`, `/rsvp/`), and the current page gets `aria-current="page"`.
- **`[hidden] { display:none !important; }`** must be in every page's CSS. Without it, a class that sets `display` (flex, block) overrides the `hidden` attribute, which once made the RSVP children question show for everyone.

### Adding the Travel page

Travel is currently a disabled placeholder (`<span class="soon">`) in the nav of `index.html` and `rsvp/index.html`. To add it: create `travel/index.html`, and in **every** page's nav replace the span with `<a href="/travel/">Travel</a>`, adding `aria-current="page"` on the Travel page itself. Venue details (Old North Abbey ceremony, Knoxville Museum of Art reception, with addresses) are already on `index.html`.

## RSVP backend notes

- Requires PHP 8.1 or newer (it uses `never`, `str_starts_with`, and `mb_*`).
- Data lives **outside** the web root at `../../rsvp-data/` relative to `rsvp/api.php` (on Hostinger: `domains/babyjanedoe.com/rsvp-data/`), so the FTP deploy never touches it and it can't be downloaded.
- All writes go through `with_locked_data()` (exclusive `flock`); reads use `read_data()` (shared lock). Keep it that way: unlocked reads can see a half-written file.
- Guest emails are sent with PHP `mail()` after the lock is released, from a guest Send/Change RSVP only, never from admin edits.
- Admin saves send the `updated_at` they loaded, and the server refuses with a 409 if it changed. This stops an admin edit from overwriting a guest's fresh RSVP.
- A household's `responded` flag means every guest has an answer. Use it, not `updated_at` (admin edits set `updated_at` too), to tell new households from returning ones.

## Front-end gotchas

- **Browser extensions inject elements** (LastPass broke the admin editor this way). Look up form fields by class or id, never by position among children.
- **Images are cached for 7 days** (`cache-control: max-age=604800` from Hostinger's CDN). After replacing an image, returning visitors keep the old one; use a cache-busting query (`image.jpg?v=2`) if that matters. Hard refresh with Ctrl+Shift+R to check your own view.
- Rail photos display at most 560px wide. Export them at about 1400px wide and keep them under about 500KB (Pillow is installed; `reference/build-og-image.py` shows its use).

## Testing locally

PHP isn't installed on the host; use the `php:8.3-cli` Docker image (already pulled). Docker Desktop can't mount `/tmp`, so mount the repo and copy test data in:

```bash
docker run --rm -d --name rsvp-test -p 8080:8080 -e PHP_CLI_SERVER_WORKERS=4 \
  -v "$PWD":/srv/public_html:ro \
  php:8.3-cli php -d "sendmail_path=sh -c 'cat >> /tmp/mail.log' sendmail" -S 0.0.0.0:8080 -t /srv/public_html
docker exec rsvp-test mkdir -p /srv/rsvp-data
docker cp rsvp-data-example/config.example.php rsvp-test:/srv/rsvp-data/config.php
docker cp <test rsvps.json> rsvp-test:/srv/rsvp-data/rsvps.json
# emails land in: docker exec rsvp-test cat /tmp/mail.log
docker rm -f rsvp-test   # when done
```

- `PHP_CLI_SERVER_WORKERS` is needed for real concurrency; the built-in server is single-threaded otherwise.
- The `sendmail_path` stub must accept the `-f sender` argument `mail()` appends, which is why it uses the `sh -c '…' sendmail` form.
- Static pages (no PHP) can be served with `python3 -m http.server`.

**Screenshots and UI checks:** `google-chrome --headless=new --screenshot` works, but headless Chrome won't go narrower than about 500px. For phone-width shots, load the page in a 390px `<iframe>` inside a wrapper page. For interactive checks (logging in, clicking through), run Chrome with `--remote-debugging-port=9222` and drive it from a small Node script over the DevTools protocol (Node 24 has a built-in `WebSocket`).

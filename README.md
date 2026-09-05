# babyjanedoe.com

Wedding website for Justin & Mikaela — April 24, 2027, Knoxville, TN.

## Structure

- `index.html` — the save-the-date landing page (static HTML/CSS, no build step)
- `images/` — photos used on the site
- `reference/` — design references, not served on the live site
- `.github/workflows/deploy.yml` — deploys `main` to Hostinger over FTP on every push

## Deployment

Pushing to `main` deploys automatically via GitHub Actions to Hostinger's `public_html/`.

One-time setup: in this repo's GitHub Settings → Secrets and variables → Actions, add:

- `FTP_SERVER` — your Hostinger FTP hostname (found in hPanel → Files → FTP Accounts)
- `FTP_USERNAME` — your Hostinger FTP username
- `FTP_PASSWORD` — your Hostinger FTP password

## Roadmap

- [x] Save-the-date landing page
- [ ] RSVP (name lookup against invite list, attending + plus-one)
- [ ] Book registry ("virtual bookshelf")

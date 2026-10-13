# babyjanedoe.com

Wedding website for Justin & Mikaela — April 24, 2027, Knoxville, TN.

## Structure

- `index.html` — the save-the-date landing page (static HTML/CSS, no build step)
- `images/` — photos used on the site
- `reference/` — design references and source assets, not served on the live site (see `reference/README.md`)
- `rsvp/` — RSVP page (`index.html`) and its PHP backend (`api.php`), served at `/rsvp/`
- `rsvp-data-example/` — sample config for the RSVP backend, not deployed (see [RSVP setup](#rsvp-setup)). A local test guest list can live here as `rsvps.json`; it's gitignored so guest names never reach the repo.
- `.github/workflows/deploy.yml` — deploys `main` to Hostinger over FTP on every push

## Deployment

Pushing to `main` deploys automatically via GitHub Actions to Hostinger's `public_html/`.

One-time setup: in this repo's GitHub Settings → Secrets and variables → Actions, add:

- `FTP_SERVER` — your Hostinger FTP hostname (found in hPanel → Files → FTP Accounts)
- `FTP_USERNAME` — your Hostinger FTP username
- `FTP_PASSWORD` — your Hostinger FTP password

## RSVP setup

The RSVP page is password protected with one shared password (printed on the invitations). Guests search by last name, pick their household from the matches, and mark each guest as attending or not, plus children and an optional note. They can come back and change their answer.

The guest list and password are **not in git** and are **not deployed**. They live in an `rsvp-data/` folder next to `public_html/` on the server, so deploys never overwrite responses and the files can't be downloaded from the web:

```
domains/babyjanedoe.com/
├── public_html/        ← deployed site (rsvp/api.php lives here)
└── rsvp-data/          ← create this by hand
    ├── config.php
    └── rsvps.json
```

One-time setup, in hPanel → Files → File Manager:

1. Next to `public_html/`, create a folder named `rsvp-data`.
2. Upload `rsvp-data-example/config.php` into it and change the password.
3. Build the guest list in the [format below](#guest-list-format) and upload it as `rsvp-data/rsvps.json`.

### Guest list format

```json
{
  "households": {
    "h001": {
      "guests": [
        { "first": "Alex", "last": "Smith", "attending": null },
        { "first": "Jordan", "last": "Smith", "attending": null }
      ],
      "max_children": 2,
      "children_attending": 0,
      "responded": false,
      "updated_at": null,
      "notes": ""
    }
  }
}
```

- The household key (`h001`) is an internal ID guests never see. It just has to be unique.
- You fill in `guests` (first/last names) and `max_children` (`0` hides the children question). Guests can't add or rename people.
- The RSVP form sets `attending` (`true`/`false`), `children_attending`, `notes`, `responded`, and `updated_at`.
- Search matches any guest's last name in the household, ignoring capitalization, accents, and punctuation, and each part of a hyphenated name is searchable on its own.

To check responses, download `rsvp-data/rsvps.json` from File Manager. If you edit it by hand while RSVPs are open, download it, edit, and re-upload quickly so you don't overwrite a response that came in meanwhile.

## Roadmap

- [x] Save-the-date landing page
- [x] RSVP (name lookup against invite list, attending + children)
- [ ] Book registry ("virtual bookshelf")

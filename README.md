# babyjanedoe.com

Wedding website for Justin & Mikaela — April 24, 2027, Knoxville, TN.

## Structure

- `index.html` — the save-the-date landing page (static HTML/CSS, no build step)
- `images/` — photos used on the site
- `reference/` — design references and source assets, not served on the live site (see `reference/README.md`)
- `rsvp/` — RSVP page (`index.html`) and its PHP backend (`api.php`), served at `/rsvp/`, plus `weekly-report.php`, a cron script that emails the RSVP CSV (see [Weekly CSV email](#weekly-csv-email))
- `rsvp/admin/` — password-protected admin page for viewing and editing the guest list, served at `/rsvp/admin/`
- `rsvp-data-example/` — config template (`config.example.php`) for the RSVP backend, not deployed (see [RSVP setup](#rsvp-setup)). Your real `config.php` and a local test guest list (`rsvps.json`) can live here too; both are gitignored so passwords and guest names never reach the repo.
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
2. Copy `rsvp-data-example/config.example.php` to `config.php`, fill in the guest password, the admin password, and the notification email address, and upload it into `rsvp-data/`.
3. Upload a guest list as `rsvp-data/rsvps.json`. It can be as small as `{"households": {}}`; then add households from the admin page. To start from a spreadsheet instead, build it in the [format below](#guest-list-format).

### Admin page and email notifications

`/rsvp/admin/` uses its own password (`admin_password` in `config.php`, exact match). It shows totals, a table of every household you can filter by name or response status, and a CSV download. From there you can add, edit, or delete households, including changing names, guest count, children, and anyone's answer. If a guest RSVPs while you have their household open, your save is refused with a "reload first" message instead of overwriting their response.

Each guest RSVP emails a summary with running totals to `notify_email`. Edits made from the admin page don't send emails. Emails go out through Hostinger's PHP mail, sent from `from_email`. Use an address on this domain (it helps to create that mailbox or a forwarder in hPanel → Emails), and if the first ones land in spam, mark them "Not spam".

### Weekly CSV email

Every Sunday at noon Eastern, `rsvp/weekly-report.php` emails `notify_email` the running totals with the guest list attached as `rsvps-YYYY-MM-DD.csv` (the same file as the admin page's Download CSV). It only runs from the command line; a web request gets a 404.

One-time setup, in hPanel → Advanced → Cron Jobs: add a PHP job for `domains/babyjanedoe.com/public_html/rsvp/weekly-report.php` with the schedule `0 * * * 0` (every hour on Sundays). The script sends only during the noon hour in New York, so it stays on time through daylight saving changes whatever the server's time zone is.

To test it on the live site, add a temporary job with `--now` after the path, which sends immediately. Delete that job once the email arrives.

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

Prefer the admin page for changes once RSVPs are open. If you do edit the JSON by hand, download it, edit, and re-upload quickly so you don't overwrite a response that came in meanwhile.

## Roadmap

- [x] Save-the-date landing page
- [x] RSVP (name lookup against invite list, attending + children)
- [x] RSVP admin page and email notifications
- [ ] Book registry ("virtual bookshelf")

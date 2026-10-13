<?php
// Template for rsvp-data/config.php on the server. Copy it to config.php and fill in real values.
// config.php is gitignored: never commit real passwords or email addresses.
return [
    // Shared RSVP password printed on the invitations.
    // Matching ignores capitalization and leading/trailing spaces.
    'password' => 'change-me',

    // Password for the admin page at /rsvp/admin/. Exact match, case-sensitive.
    // Anyone with it can see and edit the whole guest list, so make it long.
    'admin_password' => 'change-me-to-something-long',

    // Where to email each new or changed RSVP. Leave empty to turn emails off.
    'notify_email' => 'you@example.com',

    // Sender address for those emails. Use an address on your own domain,
    // or many mail providers will send them to spam.
    'from_email' => 'rsvp@babyjanedoe.com',
];

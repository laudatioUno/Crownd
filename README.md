# Crownd

**Crownd** is a self-hosted fan vote for the *player of the match*. Spectators scan a QR code, pick one player per team on their phone, and the club sees at a glance who was crowned MVP – live during the vote, on a projector, or shared with the voters when the admin decides.

Works for any team sport; made for clubs that want their own tool on ordinary shared hosting (PHP + MySQL/MariaDB), without platform fees or tracking.

> Version **1.0.0**. A step-by-step guide in German is in [docs/anleitung.md](docs/anleitung.md).

## Features

**Teams, players and templates**
- Teams for your own club and the opponents, each with logo, short name and an optional team color
- Players with name (required), shirt number, position and an optional photo (cropped square, phone photos turned upright); without a photo the initials are shown
- Add players one by one or paste a whole list (`12 Nico Muster`, `Nico Muster, 12`, `Nico Muster`)
- Squad templates per team, e.g. «NLA» or «U17»: the players of a league or level
- Players and teams used in a match are archived instead of deleted, so old results stay complete

**Matches and voting**
- One poll per match: home and away team, an optional template per team, competition, kick-off and venue
- Line-up per match: tick who actually plays, load another template, or add players straight from the match sheet
- One fixed link for every match (e.g. `https://example.ch/mvp`) and a printable QR code poster
- The page shows «not open yet» until an admin starts the vote – from the admin area or with one tap on the voting page itself (typically at half-time)
- Optional automatic end after a number of minutes; only one vote runs at a time
- Mobile-first ballot: one MVP per team on one ballot, photo cards, big thumb-friendly button
- One vote per device and match (random device cookie, stored only as a hash); a generous rate limit per network, a honeypot against bots

**Results**
- Per team the **MVP card** on top – photo with crown, name, number, share of votes – and the ranking of all players with bars below; ties are shown as such
- Updates live while the vote runs; projector view with full-screen mode
- Hidden from voters by default; the admin can share the result per match – then voters see it after voting and on the start page after the end

**Admin**
- Several admin accounts, password login (Argon2id) with lockout after repeated failures
- Club name, logo, color, the name of the award («Player of the Match», «MVP», …) and the texts on the voting page are configurable
- Delete all votes of a match (e.g. after a test run) or reopen a vote closed by mistake

## Requirements

- PHP **8.3 or newer** with `pdo_mysql`, `sodium`, `gd`, `mbstring` (`exif` optional, for upright phone photos)
- MySQL 8.0+ or MariaDB 10.6+ (an empty database)
- HTTPS
- Works on ordinary shared hosting: no root access, no containers, no Composer on the server, no cron job

## Installation

1. Download `crownd-<version>.zip` from the [releases](../../releases). It contains all libraries; nothing is loaded from the internet at runtime.
2. Upload and unpack it on your web space – in the web root or in a sub folder, e.g. `mvp/` for `https://example.ch/mvp`.
3. Either point the (sub)domain's web root to the `public/` folder, or keep the folder as it is: the included `.htaccess` blocks all other folders and forwards requests to `public/` (Apache with `mod_rewrite`).
4. Make `config/` and `storage/` writable for the web server.
5. Open the address in the browser. The installer starts automatically. **Run it right after uploading**, because until it has run anyone could open it.

### nginx

```nginx
location /mvp/ {
    alias /path/to/crownd/public/;
    try_files $uri /mvp/index.php$is_args$args;
}
```

## Updating

Always use the release file `crownd-<version>.zip`; GitHub's "Source code" archives do not contain the libraries (`vendor/`).

Keep `config/config.php` (database access and key) and `storage/uploads/` (logos and photos); everything else comes from the new release. Database updates run automatically at the next admin login.

## Data protection

- Voters stay anonymous: no account, no name, no IP address is stored. A random cookie identifies the device; per match only a hash of it is saved to prevent a second vote.
- IP addresses are only used hashed for rate limits and deleted after 24 hours.
- Player names and photos are shown on the voting page while a vote runs; get the consent of the players (or their parents) before uploading photos.
- Pages are not indexed by search engines; results are visible to admins only unless shared.

## Security

- Strict Content Security Policy: only resources from your own server, no inline scripts or styles.
- CSRF protection on every form, session cookies `HttpOnly` and `SameSite=Lax`, uploads re-encoded with GD.
- `config/`, `src/`, `storage/` and the other folders are not reachable from the web.
- Found a vulnerability? See [SECURITY.md](SECURITY.md).

## Development

```bash
composer install
php -S localhost:8000 -t public public/index.php   # development server
vendor/bin/phpunit                                  # unit tests
bin/build-release.sh                                # builds build/crownd-<version>.zip
```

| Folder | Content |
| --- | --- |
| `public/` | Web root: front controller `index.php`, CSS, JS, fonts |
| `src/` | Application code (`Http`, `Controller`, `Service`, `Repository`, `Domain`, `Security`, `I18n`) |
| `templates/` | Twig templates |
| `lang/` | Texts (German) |
| `migrations/` | Database schema |

Releases: GitHub › Actions › **Release** › «Run workflow» with the version from `src/App.php`.

## License

MIT, see [LICENSE](LICENSE). Fonts: Barlow (SIL Open Font License).

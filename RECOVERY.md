# PCTN Inventory — Backup & Recovery Runbook

A practical recovery procedure for this application as it actually runs
today: a single small shop's copy, most commonly on one Windows PC via
XAMPP (see `README.md`'s setup instructions), or the equivalent local
Docker setup. This is not written for a cloud/managed-hosting deployment,
because this application isn't one.

Keep this file where you can find it even if the PC that runs the app is
unavailable — printed, or saved to a phone/cloud-drive note, not only on
the machine it's describing how to recover.

## What a backup actually contains

Settings → Database Backup (Admin only) downloads a single `.sql` file —
a complete copy of every table in the database (products, stock
movements, sales, customers, debts, payments, business settings, audit
history, user accounts). It does **not** include:

- **`uploads/avatars/`** — user profile photos. These are separate files
  on disk, not database rows (see "Uploads/avatars" below).
- The application code itself — that comes from this GitHub repository
  (or whatever folder copy you deployed from), not from the backup file.

## 1. If the server/PC dies

You need three things to get running again on a replacement machine:
1. The application code (re-download/clone this repository, or restore
   your last saved copy of the `inventory-app` folder).
2. Your most recent `.sql` backup file.
3. Your most recent copy of the `uploads/avatars/` folder, if you want
   existing staff profile photos to keep working (optional — the app
   runs fine without it, affected users just get a blank avatar until
   they re-upload one).

Then follow "Full recovery procedure" below on the new machine.

## 2. If the database becomes corrupted (but the PC itself is fine)

1. Stop using the app (don't let anyone add more sales/stock while you
   work) so nothing is lost between now and your last backup.
2. Follow steps 5–9 of "Full recovery procedure" below, using your most
   recent `.sql` backup, into a freshly created database.

## 3. Obtaining the latest code

- If you have Git installed: `git pull` inside your existing
  `inventory-app` folder, or `git clone` a fresh copy from
  `https://github.com/pichchanthorn/inventory-app`.
- Otherwise: download the repository as a ZIP from GitHub and extract it
  to the same location XAMPP expects (`C:\xampp\htdocs\inventory-app` on
  Windows — see `README.md`).

## 4. Obtaining the backup SQL file

The most recent file you downloaded from Settings → Database Backup
(filename like `pctn-backup-2026-01-15_143000.sql`). See "Where to keep
backups" below for where that should be.

## 5. Creating a clean database

Using phpMyAdmin (bundled with XAMPP) or the `mysql` command line:

```sql
CREATE DATABASE inventory_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Do this even if a database with that name already exists and seems
broken — a backup restore is a full replace (every table is dropped and
recreated), so starting from a genuinely empty, freshly created database
avoids any confusion about what state it was in beforehand.

## 6. Importing the SQL backup

- **phpMyAdmin:** select the new `inventory_db` database → Import tab →
  choose the `.sql` file → Go. This is the same method the app's own
  Backup page already tells you to use.
- **Command line (alternative):**
  ```
  mysql -u root -p inventory_db < pctn-backup-2026-01-15_143000.sql
  ```

Wait for it to finish completely before continuing — a large shop's
history can take a little while. If it reports an error partway through,
stop and don't proceed to step 9 until you understand why (see
"If the import fails" below).

## 7. Restoring `uploads/avatars/` if needed

Copy your saved `uploads/avatars/` folder into the application folder so
it sits at `inventory-app/uploads/avatars/` (alongside the copy that's
already there from the code checkout — this replaces the empty one).
Skip this step if you don't have a saved copy, or don't mind staff
needing to re-upload their profile photos — nothing else in the app
depends on this folder.

## 8. Updating database configuration if necessary

Only needed if the new machine's database uses different connection
details than before (a different MySQL password, a different host).
Edit `config/db.php`'s four values at the top of the file (or set the
`DB_HOST`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` environment
variables, if you're running via Docker) to match. On a fresh XAMPP
install with default settings, the existing defaults in that file
already work unchanged.

## 9. Verifying the recovery actually worked

Don't consider recovery done until you've checked all of these, logged
in as an Admin:

- **Login** — log in with an existing user account and correct password.
  If this works, the `users` table restored correctly.
- **Stock** — open Products (or Stock Reports) and check that a few
  products show the stock quantities you'd expect from before the
  incident, not zero or missing.
- **Sales** — open Stock Reports' transaction log or a specific recent
  sale's receipt (Point of Sale → look up by reference) and confirm the
  line items, total, and cash received look correct.
- **Debts** — open the Customers page and confirm a customer with an
  existing balance still shows the correct amount owed and payment
  history.
- **Invoice/business information** — open Settings and confirm the
  exchange rate and business name/address/phone/email are populated
  correctly (not blank), and that a sale receipt shows the correct
  business header.

If all five check out, recovery is complete.

## If the import fails

The most common cause is trying to import into a database that isn't
empty (e.g. skipping step 5, or a previous partial import). Drop the
database entirely (`DROP DATABASE inventory_db;`) and repeat from step 5
with a genuinely fresh one. If the file itself looks incomplete or
truncated (the import stops partway through, or the file looks
unusually small compared to your shop's actual data), the backup
download itself may not have completed successfully when it was taken —
use an earlier backup file instead, and take a fresh backup as soon as
the app is working again.

## Where to keep backups

- Somewhere **other than** the same physical machine as the live
  database — a synced folder (Google Drive, OneDrive), a USB drive, or
  an external hard drive are all realistic and sufficient at this scale.
- Keep the last 2–3 dated backups rather than just the newest one — if a
  problem isn't noticed immediately, the most recent backup might
  already include it.
- Take a backup at least once a day if the shop makes sales daily —
  more than that isn't necessary at this scale.
- Periodically (e.g. every few months) actually practice this recovery
  procedure into a spare/throwaway database, so you know your backups
  are genuinely usable before the day you actually need one.

## Handling downloaded `.sql` files safely

A backup file is sensitive — it contains every customer's name, phone
number, and debt history, the shop's business information, and staff
login credentials (as scrambled/hashed passwords, never in plain text,
but still not something to leave lying around). Treat it like any other
confidential business document:

- Don't email it to yourself or share it over chat apps without password
  protection.
- Don't upload it to a public file-sharing link.
- Delete old copies you no longer need once a newer backup supersedes
  them, rather than letting them accumulate indefinitely in an easily
  browsable folder.

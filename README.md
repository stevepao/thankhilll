# Gratitude Journal (Plain PHP Scaffold)

Simple mobile-first gratitude journaling app scaffold using plain PHP, PDO, and MySQL.

## What This Includes

- `index.php` (`Today`): write and save a gratitude note
- `notes.php` (`Notes`): list saved notes newest-first
- Shared mobile-first layout and bottom navigation
- `.env`-based config via `vlucas/phpdotenv`
- Lightweight SQL migrations runner (`bin/migrate.php`)

## Requirements

- PHP 8.0+ (PDO MySQL extension enabled)
- Composer
- MySQL database (works with IONOS MySQL)

## Project Structure

- `index.php` - Today page (save note)
- `notes.php` - Notes listing page
- `db.php` - PDO connection + Dotenv loading
- `header.php` / `footer.php` - shared layout + bottom nav
- `styles.css` - mobile-first styles
- `bin/migrate.php` - migration runner
- `migrations/` - SQL migration files

## Setup

1. Install dependencies:

   ```bash
   composer install
   ```

2. Create your env file:

   ```bash
   cp .env.example .env
   ```

3. Edit `.env` with your MySQL credentials:

   ```env
   DB_HOST=localhost
   DB_NAME=your_database_name
   DB_USER=your_mysql_user
   DB_PASS=your_mysql_password
   ```

4. Run migrations:

   ```bash
   php bin/migrate.php
   ```

5. Start a local PHP server:

   ```bash
   php -S localhost:8000
   ```

6. Open `http://localhost:8000` in your browser.

## Migrations

Migrations are raw `.sql` files in `migrations/`, applied in filename order.

- Already-applied files are tracked in the `migrations` table.
- Only new files are applied on each run.

Run migrations anytime:

```bash
php bin/migrate.php
```

### Naming Convention

Use numeric prefixes so ordering stays explicit:

- `001_initial_schema.sql`
- `002_add_notes_index.sql`
- `003_add_notes_updated_at.sql`

Keep each migration focused and append-only (do not edit old migrations after they are applied in shared environments).

## Initialize Git (Recommended)

From the project root:

```bash
git init
git add .
git commit -m "Initial gratitude journal scaffold"
```

Optional next steps:

- Create a remote repo on GitHub
- Add it as `origin`
- Push your first commit

## License

MIT. See `LICENSE`.

## Notes

- `.env` is ignored by git; keep secrets there.
- `vendor/` is also ignored; it is rebuilt with `composer install`.

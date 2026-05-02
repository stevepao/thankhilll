# Archived incremental migrations

SQL files in this folder are the **historical** migrations that were merged into `../001_baseline.sql` for alpha.

They are **not** executed by `bin/migrate.php` (only `../**/*.sql` at the migrations root are picked up).

Keep this archive for archaeology and diffing; new work ships as `002_*.sql` and later beside `001_baseline.sql`.

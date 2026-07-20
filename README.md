# Adminer DuckDB driver

A database driver/plugin that adds [DuckDB](https://duckdb.org) support to
[Adminer](https://www.adminer.org) 5.x, built against Adminer's
[extension/driver API](https://www.adminer.org/en/extension/).

DuckDB is an in-process analytical database (a "SQLite for analytics"). This
driver lets you browse, query, and edit DuckDB database files through the normal
Adminer UI.

## How it works

Adminer's driver API expects three classes in the `Adminer` namespace plus a set
of free functions (see `docs/reference-driver.inc.php` and
`docs/reference-sqlite.inc.php`, kept locally for reference):

| Class / API      | Role |
|------------------|------|
| `Db`             | connection wrapper implementing `SqlDb` (`attach`, `query`, `quote`, `select_db`) |
| `Result`         | result-set wrapper (`fetch_assoc`, `fetch_row`, `fetch_field`, `seek`, `num_rows`) |
| `Driver`         | extends `SqlDriver`; declares types, operators, `select`/`insert`/`update`/`delete`, `support()` |
| free functions   | schema introspection: `tables_list`, `fields`, `indexes`, `foreign_keys`, `table_status`, `alter_table`, … |

Because there is no stable `PDO_DuckDB` in core PHP, the connection is backed by
the pure-PHP FFI binding [`satur.io/duckdb`](https://github.com/satur-io/duckdb-php).
Its `ResultSet` is forward-only and generator-based, so `Result` eagerly
materializes each (LIMIT-bounded) result into arrays to give Adminer the random
access it needs.

DuckDB metadata is read from `information_schema` and DuckDB's own catalog
functions (`duckdb_constraints()`, `duckdb_indexes()`, `duckdb_databases()`,
`duckdb_settings()`). Features DuckDB lacks — triggers, stored routines, a
last-insert-id, `LIMIT` on `UPDATE`/`DELETE` — are reported as unsupported via
`Driver::support()` and stubbed accordingly.

## Requirements

- PHP 8.3+ with the `ffi` extension enabled
- `composer require satur.io/duckdb` (the plain package — **not** `-auto`; see Install)
- the DuckDB C library **v1.5.1** placed in `vendor/satur.io/duckdb/lib/` (see Install)
- Adminer 5.x

## Install

This is a drop-in driver. Adminer globs `adminer-plugins/*.php` at startup and
includes each file, so you only need to place the driver there — its top-level
`add_driver("duckdb", "DuckDB")` call registers it and **DuckDB** appears in the
System dropdown. No `adminer_object()` bootstrap required.

Lay it out next to your `adminer.php`:

```
your-adminer-dir/
├── adminer.php
├── adminer-plugins/          # folder name must be exactly this
│   ├── duckdb-driver.php      # the driver
│   └── duckdb-login.php       # login-form helper (recommended)
└── vendor/                   # from: composer require satur.io/duckdb
```

Steps:

```bash
# in your-adminer-dir/
composer require satur.io/duckdb          # the FFI binding + its autoloader
mkdir -p adminer-plugins
cp /path/to/duckdb-driver.php adminer-plugins/
cp /path/to/duckdb-login.php  adminer-plugins/   # recommended (see Login form)
```

The single-file `adminer.php` has no Composer autoloader of its own, so
`duckdb-driver.php` pulls in `vendor/autoload.php` itself — it looks for it both
next to the plugin (`adminer-plugins/vendor/`) and next to `adminer.php`
(`../vendor/`), so either location for `composer require` works.

> **Do not use `satur.io/duckdb-auto`.** That variant ships a Composer
> install-time plugin that shells out to a `php` subprocess with a relative
> `require 'vendor/autoload.php'`; from Composer's working directory that path
> doesn't resolve, the download aborts, and **`vendor/autoload.php` is never
> generated**. Use plain `satur.io/duckdb` (no install-time plugin) and place the
> native library manually, as below.

### Native DuckDB C library

The binding needs `libduckdb.so` (v1.5.1 — it checks the version at runtime and
rejects a mismatch) plus its FFI header in `vendor/satur.io/duckdb/lib/`. Install
them by hand (Linux x86_64; use `linux-arm64` / `osx-universal` +
`libduckdb.dylib` on other platforms):

```bash
LIBDIR=vendor/satur.io/duckdb/lib
mkdir -p "$LIBDIR"

# the .so — must be DuckDB v1.5.1
curl -L -o /tmp/libduckdb.zip \
  https://github.com/duckdb/duckdb/releases/download/v1.5.1/libduckdb-linux-amd64.zip
unzip -o /tmp/libduckdb.zip libduckdb.so -d "$LIBDIR"
rm /tmp/libduckdb.zip

# the FFI header ships inside the package; prepend the FFI_SCOPE/FFI_LIB defines
cp vendor/satur.io/duckdb/header/linux-amd64/duckdb-ffi.h "$LIBDIR/duckdb-ffi.h"
printf '#define FFI_SCOPE "DUCKDB"\n#define FFI_LIB "%s/libduckdb.so"\n\n%s' \
  "$(realpath "$LIBDIR")" "$(cat "$LIBDIR/duckdb-ffi.h")" > "$LIBDIR/duckdb-ffi.h.tmp" \
  && mv "$LIBDIR/duckdb-ffi.h.tmp" "$LIBDIR/duckdb-ffi.h"
```

Verify independently of Adminer:

```bash
php -r 'require "vendor/autoload.php"; \Saturio\DuckDB\DuckDB::sql("SELECT 42 AS answer")->print();'
```

A table printing `42` means the library is good and the Adminer driver will work.

## Usage

On Adminer's login screen:

- **System**: DuckDB
- **Server**: the database file path, e.g. `/var/data/analytics.duckdb`
  (or `:memory:` for a throwaway in-memory database)
- **Username / Password**: ignored

## Login form

`duckdb-login.php` is a small companion plugin (drop it in `adminer-plugins/`
next to the driver). It's optional but recommended — the driver works without
it, but the login screen is rougher. It does two things:

- **Adapts the login form for DuckDB.** DuckDB is file-based like SQLite, so the
  "Server" field is really a database-file path and username/password are
  ignored. Adminer only hides/relabels those fields for the built-in `sqlite`
  driver (hard-coded in its compiled JavaScript, which no PHP hook can change),
  so this plugin injects a small script that, when **DuckDB** is selected,
  relabels *Server* to *Database file*, adds a helpful placeholder, and disables
  the unused Username/Password inputs.

- **Permits the passwordless login.** DuckDB ignores the password, so you log in
  with a blank one. Adminer otherwise refuses that with *"Adminer does not
  support accessing a database without a password."* The plugin's `login()` hook
  returns `true` for the `duckdb` driver to allow it — and only for that driver,
  so other drivers keep Adminer's normal password requirement.

Without this file you can still connect, but you'll have to ignore the mislabeled
Server field and Adminer will block the empty-password login.

## Read-only mode

By default this driver opens **file-based** DuckDB databases in **read-only**
mode (`access_mode = read_only`). `:memory:` databases are unaffected and remain
fully writable.

Why: Adminer normally runs under the web-server user (e.g. `www-data`), which
usually lacks write permission on a DuckDB file owned by another user. DuckDB's
default read-write open then fails with an opaque *"Cannot open database. Unknown
error"* — because a read-write open also needs to create the write-ahead log and
a lock file next to the database. Opening read-only needs no write access, so the
connection succeeds regardless of file ownership.

Consequence: with read-only mode on, browsing and `SELECT` work, but
`INSERT`/`UPDATE`/`DELETE`, `CREATE`/`ALTER`, and other writes are rejected by
DuckDB against a file database.

### Enabling read-write

If you need to modify data through Adminer, do **both** of the following:

1. **Give the web-server user write access** to the database file *and its
   directory* (DuckDB writes a `.wal` and a lock file alongside the `.db`):

   ```bash
   # example: let www-data write the file and its folder
   sudo chown www-data:www-data /path/to/data.duckdb
   sudo chmod u+rw            /path/to/data.duckdb
   sudo chmod u+rwx           /path/to/            # directory, for WAL + lock
   ```

2. **Drop the read-only config** in `attach()` (in `duckdb-driver.php`). Change:

   ```php
   $cfg = new \Saturio\DuckDB\DB\Configuration();
   $cfg->set('access_mode', 'read_only');
   $this->link = \Saturio\DuckDB\DuckDB::create($path, $cfg);
   ```

   to a plain open:

   ```php
   $this->link = \Saturio\DuckDB\DuckDB::create($path);
   ```

   (or set `$cfg->set('access_mode', 'read_write')` explicitly).

Only one process may hold a DuckDB file open read-write at a time, so make sure
no other connection (a DuckDB CLI, another Adminer tab) has it open.

## Supported

Browsing databases/schemas/tables/views, running SQL, `SELECT` with search &
sort, `INSERT`/`UPDATE`/`DELETE`, `INSERT … ON CONFLICT` upserts, creating and
altering tables and columns, creating/dropping indexes, `EXPLAIN`, export
(dump), and viewing DuckDB settings.

> Writes (everything past `SELECT`) require read-write mode, which is **off by
> default** for file databases — see [Read-only mode](#read-only-mode).

## Not supported

Triggers, stored routines/procedures, foreign-key editing, changing a primary
key after table creation, `LIMIT` on `UPDATE`/`DELETE`, and renaming a database
file — all limitations of DuckDB itself.

## Layout

```
duckdb-driver.php        the driver (self-contained, namespace Adminer) — goes in adminer-plugins/
duckdb-login.php         login-form helper plugin (optional but recommended) — goes in adminer-plugins/
composer.json            dependencies (satur.io/duckdb, ext-ffi)
```

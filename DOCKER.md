# Docker Quick Start

Run Ledger with MySQL in one command:

```bash
git clone https://github.com/ClearanceClarence/Ledger.git
cd Ledger
docker-compose up -d
```

Open **http://localhost:8080/ledger/** in your browser.

## Installer Settings

When the installer asks for database credentials, use:

| Field    | Value              |
|:---------|:-------------------|
| Host     | `db`               |
| Port     | `3306`             |
| Username | `root`             |
| Password | `ledger_root_pass`|

Or use the non-root user:

| Field    | Value          |
|:---------|:---------------|
| Host     | `db`           |
| Port     | `3306`         |
| Username | `ledger`      |
| Password | `ledger_pass` |

## Ports

- **8080** → Ledger web interface
- **3307** → MySQL (for external tools like MySQL Workbench)

## Persistence

Data persists across restarts via Docker volumes:
- `mysql-data` — database files
- `ledger-config` — your config.php
- `ledger-logs` — query logs, favorites, saved queries, ER layouts

## Commands

```bash
# Start
docker-compose up -d

# Stop
docker-compose down

# View logs
docker-compose logs -f ledger

# Rebuild after updating
docker-compose build --no-cache
docker-compose up -d

# Reset everything (deletes all data)
docker-compose down -v
```

## Custom MySQL Password

Edit `docker-compose.yml` and change `MYSQL_ROOT_PASSWORD`, `MYSQL_USER`, and `MYSQL_PASSWORD` before first run. If you've already run it, reset with `docker-compose down -v` first.

## Connecting to an External Database

If you already have MySQL/MariaDB running elsewhere, you don't need the `db` service. Use only the `ledger` service and point the installer at your existing database host.

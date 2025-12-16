# BiblioGenius Hub - Symfony

Optional central directory and discovery service for the BiblioGenius ecosystem.

## Tech Stack

- **Framework**: Symfony 7
- **Language**: PHP 8.3
- **Database**: PostgreSQL
- **Cache**: Redis

## Purpose

This is a **lightweight hub** that provides:

- Library registry (directory of active servers)
- Peer discovery (find libraries by tags)
- Admin monitoring dashboard
- Public catalog aggregation (opt-in)
- Announcement distribution

**Note**: This is NOT a full application. Book management happens in the Rust server.

## Features

- ✅ Library registry
- ✅ Peer discovery API
- ✅ Admin dashboard
- ✅ Public catalog search
- ❌ Book CRUD (use Rust server)
- ❌ User authentication (admin only)

## Getting Started

```bash
# Install dependencies
composer install

# Configure database
cp .env .env.local
# Edit .env.local with your database credentials

# Run migrations
php bin/console doctrine:migrations:migrate

# Start server
symfony server:start
```

## API Endpoints

```
POST /api/registry/register    # Register a server
POST /api/registry/heartbeat   # Update heartbeat
GET  /api/discovery/peers      # Find peers by tags
GET  /api/catalog/search       # Search public catalogs
```

## Admin Dashboard

Access at: `http://localhost:8080/admin`

## 🗺️ Roadmap

| Version | Status | Focus |
|---------|--------|-------|
| **v1.0.0-beta** | ✅ Current | Registry + Discovery |
| v1.0.0 | Q1 2026 | Public catalog aggregation |
| v2.0.0 | Q2-Q3 2026 | Federation support |

## Repository

<https://github.com/bibliogenius/bibliogenius-hub>

# BiblioGenius Hub

> **Canonical repository: [Codeberg](https://codeberg.org/bibliogenius/bibliogenius-hub).** The GitHub copy is a read-only mirror, automatically synced from Codeberg. Please open issues and pull requests on Codeberg.

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![Symfony](https://img.shields.io/badge/symfony-7.0-black)](https://symfony.com)
[![PHP](https://img.shields.io/badge/php-8.3-blue)](https://www.php.net)

**Central discovery and directory service for the BiblioGenius network.**

The Hub is an optional, lightweight service facilitating P2P node discovery across different networks. It does *not* store user library data.

## 🚀 Features

- **Node Directory**: Register and discover usage nodes.
- **Relay Signaling**: NAT traversal assistance (WebRTC signaling).
- **Telemetry**: (Optional) Anonymous usage statistics.

## 📋 Prerequisites

- **PHP**: 8.3+
- **Composer**: Dependency manager
- **PostgreSQL**: Database
- **Redis**: Caching (Optional)

## ⚡ Quick Start

```bash
# Clone repository
git clone https://github.com/bibliogenius/bibliogenius-hub.git
cd bibliogenius-hub

# Install Dependencies
composer install

# Start Local Server
symfony server:start
```

## 🛠️ Configuration

Copy the example environment file:

```bash
cp .env .env.local
```

Edit `.env.local` to configure your database connection:

```ini
DATABASE_URL="postgresql://user:password@127.0.0.1:5432/bibliohub"
```

## 🔗 Related Repositories

- [**bibliogenius**](https://github.com/bibliogenius/bibliogenius): The nodes that connect to this hub.

## 📄 License

This project is licensed under the GNU Affero General Public License v3.0 - see the [LICENSE](LICENSE) file for details.

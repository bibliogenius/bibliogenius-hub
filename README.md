# BiblioGenius Hub

> **Canonical repository: [Codeberg](https://codeberg.org/bibliogenius/bibliogenius-hub).** The GitHub copy is a read-only mirror, automatically synced from Codeberg. Please open issues and pull requests on Codeberg.

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)
[![Symfony](https://img.shields.io/badge/symfony-7.0-black)](https://symfony.com)
[![PHP](https://img.shields.io/badge/php-8.3-blue)](https://www.php.net)

**Optional directory and zero-knowledge relay for the BiblioGenius network.**

The Hub is the only **optional** piece of the ecosystem. You need it for two things, and nothing else:

1. **Public directory**: an opt-in listing so libraries can be discovered (for example, by city) and connect to each other.
2. **Zero-knowledge relay**: blind, store-and-forward encrypted mailboxes that let two devices stay in sync and reachable when they are **not** on the same Wi-Fi (cellular, different network, travel).

It is **blind by design**: it stores and forwards opaque encrypted blobs and never sees your library data, your contacts, or your messages. When devices are on the same LAN they talk directly (mDNS) and the Hub is not involved at all.

## 🚀 Features

- **Library Directory**: Opt-in registration and discovery of public libraries.
- **Encrypted Relay**: Store-and-forward mailboxes for E2EE messages between off-network peers. Ciphertext only.
- **Real-time Delivery**: WebSocket push so relayed messages arrive without polling.
- **Zero-Knowledge**: No access to plaintext library data; the Hub only ever handles encrypted blobs.

## 📋 Prerequisites

- **PHP**: 8.3+
- **Composer**: Dependency manager
- **PostgreSQL**: Database
- **Redis**: Caching (optional)

## ⚡ Quick Start

```bash
# Clone repository
git clone https://codeberg.org/bibliogenius/bibliogenius-hub.git
cd bibliogenius-hub

# Install dependencies
composer install

# Start local server
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

- [**bibliogenius**](https://codeberg.org/bibliogenius/bibliogenius): The library nodes that register with and relay through this Hub.
- [**bibliogenius-app**](https://codeberg.org/bibliogenius/bibliogenius-app): The app users install; it talks to the Hub only for directory and off-network sync.

## 📄 License

This project is licensed under the GNU Affero General Public License v3.0 - see the [LICENSE](LICENSE) file for details.

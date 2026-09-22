# Wavr — Web3 P2P Calling dApp

Decentralized audio calling on Solana. Connect wallet → claim username → call anyone.

## Stack
- Frontend: HTML, CSS, Vanilla JS (PWA)
- Backend: PHP REST API
- Database: Supabase (PostgreSQL)
- Signaling: PHP Ratchet WebSocket server
- Calls: Native WebRTC P2P audio
- Auth: Solana wallet signature (Phantom, Backpack, Solflare)

## Setup

### 1. Supabase
Create a new project at supabase.com. Run this SQL in the editor:

```sql
create table users (
  id uuid primary key default gen_random_uuid(),
  wallet_address text unique not null,
  username text unique,
  avatar_url text,
  created_at timestamp default now(),
  last_seen timestamp
);

create table sessions (
  id uuid primary key default gen_random_uuid(),
  wallet_address text not null,
  token text unique not null,
  expires_at timestamp not null
);
create index on sessions(token);

create table call_logs (
  id uuid primary key default gen_random_uuid(),
  caller_address text not null,
  callee_address text not null,
  status text default 'completed',
  started_at timestamp,
  ended_at timestamp
);
```

**If you already created the tables**, run this to add the avatar column:
```sql
alter table users add column if not exists avatar_url text;
```

### Avatar uploads
Create a writable `uploads/avatars/` directory at the root of your PHP host and make sure your web server can write to it:
```bash
mkdir -p uploads/avatars
chmod 755 uploads/avatars
```

Get your Supabase **Connection String** (Settings → Database → Connection string → URI mode).

### 2. Environment Variables (PHP)
Set on your server or .env:
```
DATABASE_URL=postgres://postgres:[password]@db.[ref].supabase.co:5432/postgres
```

### 3. Signaling Server (VPS required)
```bash
cd /path/to/wavr
composer install
php signaling/server.php
```
Run with supervisor for production. Update the WebSocket URL in js/signaling.js to match your VPS domain.

### 4. Deploy PHP API
Upload to any PHP 8+ host (shared hosting works for the API). Point signaling server to a VPS or dedicated server (Fly.io, Railway, DigitalOcean).

### 5. Update signaling URL
In `js/signaling.js`, line 7 — update port/host to match your signaling server.

## Color Palette
- Background: #0a0a0a
- Accent: #F97316 (Burnt Orange)
- White: #ffffff
- Muted: #6b6b6b

## PWA
Works as installable PWA on iOS and Android. Add to Home Screen from browser.

# Wavr — Web3 P2P Calling dApp

Decentralized audio calling on Solana. Connect wallet → claim username → call anyone.

## Stack
- Frontend: HTML, CSS, Vanilla JS (PWA)
- Backend: PHP REST API
- Database: Supabase (PostgreSQL)
- Signaling: Supabase Realtime (private channels, no server of our own)
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

### 2. Supabase Realtime (signaling)
Calls and rooms signal over Supabase Realtime: there's no WebSocket server to run.
- Running `schema.sql` creates the Realtime access policy and turns on row-level security for every table. **This step is required.** The browser gets your anon key, and without RLS that key could read tables such as `sessions` through Supabase's REST API.
- Recommended: Project Settings → Realtime → turn **off** "Allow public access", so only private (authorized) channels work.

### 3. Environment Variables (PHP)
```
DATABASE_URL=postgres://...            # Supabase → Connect → Session pooler URI
SUPABASE_URL=https://<ref>.supabase.co
SUPABASE_ANON_KEY=...                  # Project Settings → API Keys → anon / publishable key
SUPABASE_JWT_SECRET=...                # Project Settings → JWT Keys → Legacy JWT secret (HS256)
SOLANA_RPC_URL=https://mainnet.helius-rpc.com/?api-key=...
METERED_API_KEY=...                    # TURN relay (optional but recommended)
```
`SUPABASE_JWT_SECRET` signs the short-lived token that lets each wallet listen on its own private channels (`api/realtime.php`). Keep it server-side only.

### 4. Deploy
Any PHP 8+ host with `pdo_pgsql`, `curl` and `sodium`, or use the `Dockerfile` (`start.sh` runs PHP's built-in server behind `router.php`). Only short HTTP requests now, so no always-on process is needed.

## Trench Rooms (token-gated voice rooms)
Live audio rooms per Solana token: only wallets holding the token (optionally at least a minimum amount) can enter.

- **Setup:** re-run `schema.sql`. It adds the `rooms` and `room_members` tables plus 4 starter rooms (BONK, WIF, POPCAT, JUP).
- **Env:** `SOLANA_RPC_URL`: a dedicated RPC (Helius, QuickNode, Triton). The public mainnet RPC rate-limits balance checks quickly.
- **How gating works:** `api/rooms.php?action=join` reads the wallet's on-chain balance; only then does it add the wallet to `room_members`, which is what the Realtime policy checks before letting anyone listen on `room:<id>`. All room messages are sent through `api/signal.php`, which only relays between members.
- **Audio** is a P2P mesh (`js/room.js`), capped at 10 people per room. Bigger rooms need an SFU (e.g. LiveKit).
- **Market data** (price, 24h change, logo) comes from the DexScreener public API.

## Color Palette
- Background: #0a0a0a
- Accent: #F97316 (Burnt Orange)
- White: #ffffff
- Muted: #6b6b6b

## PWA
Works as installable PWA on iOS and Android. Add to Home Screen from browser.

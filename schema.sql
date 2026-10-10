-- Wavr database schema — run this once in the Supabase SQL editor
-- (Project → SQL Editor → New query → paste → Run)

create table if not exists users (
  id uuid primary key default gen_random_uuid(),
  wallet_address text unique not null,
  username text unique,
  avatar_url text,
  created_at timestamp default now(),
  last_seen timestamp
);

create table if not exists sessions (
  id uuid primary key default gen_random_uuid(),
  wallet_address text not null,
  token text unique not null,
  expires_at timestamp not null
);
create index if not exists sessions_token_idx on sessions(token);

create table if not exists call_logs (
  id uuid primary key default gen_random_uuid(),
  caller_address text not null,
  callee_address text not null,
  status text default 'completed',
  started_at timestamp,
  ended_at timestamp
);


-- ── Trench Rooms: token-gated live audio rooms ──
create table if not exists rooms (
  id              uuid primary key default gen_random_uuid(),
  mint            text not null,
  title           text not null,
  token_symbol    text,
  token_name      text,
  token_image     text,
  min_balance     numeric not null default 0,
  creator_address text not null,
  live_count      int not null default 0,
  created_at      timestamp default now(),
  last_active     timestamp default now()
);
create index if not exists rooms_mint_idx on rooms(mint);
create index if not exists rooms_live_idx on rooms(live_count desc, last_active desc);

-- Short-lived, single-use passes: api/rooms.php issues one after verifying
-- the wallet's on-chain balance, the signaling server consumes it on join.
create table if not exists room_passes (
  token          text primary key,
  room_id        uuid not null references rooms(id) on delete cascade,
  wallet_address text not null,
  expires_at     timestamp not null
);

-- Starter rooms for the biggest Solana community tokens, so the Trench tab
-- isn't empty on day one. Safe to re-run: each is inserted only once.
insert into rooms (mint, title, token_symbol, token_name, min_balance, creator_address)
select v.mint, v.title, v.symbol, v.name, v.min_balance, 'wavr'
from (values
  ('DezXAZ8z7PnrnRJjz3wXBoRgixCa6xjnB7YaB1pPB263', 'BONK Trenches',     'Bonk',   'Bonk',            1000000),
  ('EKpQGSJtjMFqKZ9KQanSqYXRcF8fBopzLHYxdM65zcjm', 'Hat Stays On',      'WIF',    'dogwifhat',       100),
  ('7GCihgDB8fe6KNjn2MYtkzZcRjQy3t9GHdC8uHYmW2hr', 'POPCAT Pop-Off',    'POPCAT', 'POPCAT',          100),
  ('JUPyiwrYJFskUPiHa7hkeR8VUtAeFoSYbKedZNsDvCN',  'Jupiter Planetary', 'JUP',    'Jupiter',         50)
) as v(mint, title, symbol, name, min_balance)
where not exists (select 1 from rooms r where r.mint = v.mint and r.creator_address = 'wavr');

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
  created_at      timestamp default now(),
  last_active     timestamp default now()
);
create index if not exists rooms_mint_idx on rooms(mint);

-- Who is in which room right now. A row is only written by api/rooms.php
-- after the on-chain balance check, and it's what unlocks the room's private
-- Realtime channel (see wavr_can_listen below). One room per wallet.
create table if not exists room_members (
  wallet_address text primary key,
  room_id        uuid not null references rooms(id) on delete cascade,
  muted          boolean not null default true,
  joined_at      timestamptz not null default now(),
  last_seen      timestamptz not null default now(),
  last_chat_at   timestamptz
);
create index if not exists room_members_room_idx on room_members(room_id, last_seen);

-- Leftovers from the old Ratchet signaling server.
drop table if exists room_passes;
alter table rooms drop column if exists live_count;

-- ── Lock down direct access ──
-- The browser now holds the Supabase anon key (for Realtime), which also
-- unlocks Supabase's auto-generated REST API. With RLS on and no policies,
-- that API can't read or write these tables. The PHP API connects as the
-- table owner, which bypasses RLS, so it keeps working.
alter table users        enable row level security;
alter table sessions     enable row level security;
alter table call_logs    enable row level security;
alter table rooms        enable row level security;
alter table room_members enable row level security;

-- ── Realtime authorization ──
-- Clients only ever LISTEN (all sends go through api/signal.php). A wallet
-- may listen on its own inbox, and on a room channel while it's a member.
-- The JWT is minted by api/realtime.php with sub = wallet address.
create or replace function public.wavr_can_listen(topic text)
returns boolean
language sql stable security definer
set search_path = public
as $$
  select case
    when coalesce(auth.jwt()->>'sub', '') = '' then false
    when topic = 'inbox:' || (auth.jwt()->>'sub') then true
    when topic like 'room:%' then exists (
      select 1 from room_members m
      where 'room:' || m.room_id::text = topic
        and m.wallet_address = auth.jwt()->>'sub')
    else false
  end
$$;

drop policy if exists "wavr listen" on realtime.messages;
create policy "wavr listen" on realtime.messages
  for select to authenticated
  using ( public.wavr_can_listen(realtime.topic()) );

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

-- ── Avatar storage ──
-- Public-read bucket for profile photos. Only the PHP API writes to it
-- (with the secret key, set as SUPABASE_SERVICE_KEY), so no write policies.
insert into storage.buckets (id, name, public)
values ('avatars', 'avatars', true)
on conflict (id) do nothing;

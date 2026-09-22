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


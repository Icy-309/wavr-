<?php
// solana.php — on-chain + market-data helpers for Trench Rooms.
// Do NOT add top-level code here — this file is require_once'd by other endpoints.
//
// Configure via env: SOLANA_RPC_URL (default: public mainnet RPC, which is
// heavily rate-limited — use a Helius/QuickNode/Triton URL in production).

const WAVR_ADDR_RE = '/^[1-9A-HJ-NP-Za-km-z]{32,44}$/';

function httpJson($url, $postBody = null){
  $ch = curl_init($url);
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 6,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
  ];
  if($postBody !== null){
    $opts[CURLOPT_POST]       = true;
    $opts[CURLOPT_POSTFIELDS] = json_encode($postBody);
    $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'Accept: application/json'];
  }
  curl_setopt_array($ch, $opts);
  $body = curl_exec($ch);
  $err  = curl_error($ch);
  curl_close($ch);
  if($err || !$body) throw new RuntimeException('HTTP request failed: ' . ($err ?: 'empty body'));
  $json = json_decode($body, true);
  if(!is_array($json)) throw new RuntimeException('Invalid JSON from ' . parse_url($url, PHP_URL_HOST));
  return $json;
}

function solanaRpc($method, $params){
  $url = getenv('SOLANA_RPC_URL') ?: 'https://api.mainnet-beta.solana.com';
  $res = httpJson($url, ['jsonrpc'=>'2.0','id'=>1,'method'=>$method,'params'=>$params]);
  if(isset($res['error'])) throw new RuntimeException('RPC error: ' . ($res['error']['message'] ?? 'unknown'));
  return $res['result'] ?? null;
}

/**
 * Returns true if $mint is an existing SPL token mint (classic or Token-2022).
 */
function isTokenMint($mint){
  $r = solanaRpc('getAccountInfo', [$mint, ['encoding'=>'jsonParsed']]);
  $type = $r['value']['data']['parsed']['type'] ?? null;
  return $type === 'mint';
}

/**
 * Sum of $owner's balance of $mint across all their token accounts (UI units).
 * Filtering by mint covers both the Token and Token-2022 programs.
 */
function getTokenBalance($owner, $mint){
  $r = solanaRpc('getTokenAccountsByOwner', [
    $owner,
    ['mint' => $mint],
    ['encoding' => 'jsonParsed', 'commitment' => 'confirmed'],
  ]);
  $total = 0.0;
  foreach(($r['value'] ?? []) as $acct){
    $amt = $acct['account']['data']['parsed']['info']['tokenAmount']['uiAmountString'] ?? '0';
    $total += (float)$amt;
  }
  return $total;
}

/**
 * Token name/symbol/image + live market stats from DexScreener (no API key).
 * Picks the most liquid pair where this mint is the base token. Returns
 * null if the token has no pairs yet (brand-new / unlisted).
 */
function getTokenMarket($mint){
  return getTokenMarkets([$mint])[$mint] ?? null;
}

/**
 * Batch version — DexScreener accepts up to 30 comma-separated mints per call.
 * Returns [mint => market] for every mint that has at least one pair.
 */
function getTokenMarkets(array $mints){
  $mints = array_slice(array_values(array_unique(array_filter($mints, fn($m) => preg_match(WAVR_ADDR_RE, $m)))), 0, 30);
  if(!$mints) return [];
  try {
    $res = httpJson('https://api.dexscreener.com/latest/dex/tokens/' . implode(',', $mints));
  } catch(\Throwable $e){
    return [];
  }
  $best = [];
  foreach(($res['pairs'] ?? []) as $p){
    if(($p['chainId'] ?? '') !== 'solana') continue;
    $m = $p['baseToken']['address'] ?? '';
    if(!in_array($m, $mints, true)) continue;
    if(!isset($best[$m]) || ($p['liquidity']['usd'] ?? 0) > ($best[$m]['liquidity']['usd'] ?? 0)) $best[$m] = $p;
  }
  return array_map('formatMarket', $best);
}

function formatMarket($best){
  return [
    'name'        => $best['baseToken']['name']   ?? null,
    'symbol'      => $best['baseToken']['symbol'] ?? null,
    'image'       => $best['info']['imageUrl']    ?? null,
    'price_usd'   => isset($best['priceUsd']) ? (float)$best['priceUsd'] : null,
    'market_cap'  => $best['marketCap'] ?? $best['fdv'] ?? null,
    'change_24h'  => $best['priceChange']['h24'] ?? null,
    'change_1h'   => $best['priceChange']['h1']  ?? null,
    'liquidity'   => $best['liquidity']['usd']   ?? null,
    'volume_24h'  => $best['volume']['h24']      ?? null,
    'dex_url'     => $best['url'] ?? null,
  ];
}

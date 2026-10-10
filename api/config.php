<?php
/**
 * Runtime client config, served as JavaScript so the same build works on any
 * host. Set SIGNAL_HOST (e.g. wavr-signal.up.railway.app) to point the pages
 * at the signaling server's public domain; SIGNAL_PORT defaults to 443.
 * Unset → signaling.js falls back to the page's own hostname on port 8080.
 */
header('Content-Type: application/javascript');
header('Cache-Control: no-store');
$host = trim(getenv('SIGNAL_HOST') ?: '');
$port = (int)(getenv('SIGNAL_PORT') ?: 443);
if($host !== ''){
  echo 'window.WAVR_SIGNAL_HOST = ' . json_encode($host) . ";\n";
  echo 'window.WAVR_SIGNAL_PORT = ' . $port . ";\n";
}

<?php
// Router for PHP's built-in server in production (see Dockerfile / start.sh).
// Blocks repo internals (schema, docs, deploy files) from being served.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if(preg_match('#^/(vendor|signaling)(/|$)|/\.|\.(sql|md|lock)$|^/composer\.json$|^/router\.php$|^/start\.sh$|^/Dockerfile$#i', $path)){
  http_response_code(404);
  exit;
}
return false; // let the built-in server handle everything else normally

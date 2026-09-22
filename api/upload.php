<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

if($_SERVER['REQUEST_METHOD']!=='POST'){
  echo json_encode(['error'=>'Method not allowed']); exit;
}

$walletAddr = validateSession();
if(!$walletAddr){ echo json_encode(['error'=>'Unauthorized']); exit; }

// Rate limit: max 5 uploads per wallet per minute using a temp file lock
$rateLockDir = sys_get_temp_dir() . '/wavr_uploads/';
if(!is_dir($rateLockDir)) mkdir($rateLockDir, 0700, true);
$lockFile = $rateLockDir . hash('sha256', $walletAddr) . '.json';

$now     = time();
$window  = 60;   // seconds
$maxUploads = 5;

$history = [];
if(file_exists($lockFile)){
  $history = json_decode(file_get_contents($lockFile), true) ?: [];
}
// Keep only timestamps within the window
$history = array_filter($history, fn($t) => $now - $t < $window);

if(count($history) >= $maxUploads){
  echo json_encode(['error'=>'Too many uploads. Wait a minute and try again.']); exit;
}

$history[] = $now;
file_put_contents($lockFile, json_encode(array_values($history)));

// Receive base64 image from frontend (already resized client-side)
$body   = json_decode(file_get_contents('php://input'), true);
$data   = $body['image'] ?? '';

if(!$data){ echo json_encode(['error'=>'No image data']); exit; }

// Strip data URI prefix: data:image/jpeg;base64,...
if(!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/', $data, $m)){
  echo json_encode(['error'=>'Invalid image format. Use JPEG, PNG or WebP']); exit;
}

$base64 = preg_replace('/^data:image\/\w+;base64,/', '', $data);
$imgBytes = base64_decode($base64, true);
if(!$imgBytes){ echo json_encode(['error'=>'Invalid base64 data']); exit; }

// Max 2MB after decode
if(strlen($imgBytes) > 2 * 1024 * 1024){
  echo json_encode(['error'=>'Image too large. Max 2MB']); exit;
}

// Validate it's actually an image using GD
if(!function_exists('imagecreatefromstring')){
  echo json_encode(['error'=>'Server missing GD extension']); exit;
}

$img = @imagecreatefromstring($imgBytes);
if(!$img){ echo json_encode(['error'=>'Invalid image file']); exit; }

// Resize/crop to square 300x300, preserving transparency before JPEG conversion
$sw   = imagesx($img);
$sh   = imagesy($img);
$size = min($sw, $sh);
$ox   = (int)(($sw - $size) / 2);
$oy   = (int)(($sh - $size) / 2);

$out = imagecreatetruecolor(300, 300);

// Fill with white before compositing (handles PNG transparency)
$white = imagecolorallocate($out, 255, 255, 255);
imagefill($out, 0, 0, $white);

imagecopyresampled($out, $img, 0, 0, $ox, $oy, 300, 300, $size, $size);
imagedestroy($img);

// Save to uploads/avatars/
$uploadDir = __DIR__ . '/../uploads/avatars/';
if(!is_dir($uploadDir)){
  if(!mkdir($uploadDir, 0755, true)){
    echo json_encode(['error'=>'Cannot create upload directory']); exit;
  }
}

// Filename = sha256 of wallet address (no PII in filename)
$filename = hash('sha256', $walletAddr) . '.jpg';
$filepath = $uploadDir . $filename;

$saved = imagejpeg($out, $filepath, 88);
imagedestroy($out);

if(!$saved || !file_exists($filepath)){
  echo json_encode(['error'=>'Failed to save image. Check uploads/avatars/ is writable.']); exit;
}

// Store URL in DB
$avatarUrl = '/uploads/avatars/' . $filename;
$pdo = getDB();
$pdo->prepare("UPDATE users SET avatar_url=? WHERE wallet_address=?")
    ->execute([$avatarUrl, $walletAddr]);

echo json_encode(['success'=>true, 'url'=> $avatarUrl . '?v=' . time()]);

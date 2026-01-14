<?php
// ============================================
// FICHIER 6 : helpers/functions.php
// ============================================

/**
 * Fonctions utilitaires globales
 */

// Echapper du HTML
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Générer un slug
function slugify($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    $text = strtolower($text);
    return empty($text) ? 'n-a' : $text;
}

// Formater un prix
function formatPrice($price) {
    if (CURRENCY_POSITION === 'left') {
        return CURRENCY_SYMBOL . number_format($price, 2, ',', ' ');
    }
    return number_format($price, 2, ',', ' ') . ' ' . CURRENCY_SYMBOL;
}

// Générer une clé de licence
function generateLicenseKey() {
    return sprintf(
        '%s-%s-%s-%s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(4))
    );
}

// Générer un numéro de commande
function generateOrderNumber() {
    return 'MF-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

// Formater une date
function formatDate($date) {
    return date('d/m/Y à H:i', strtotime($date));
}

// Calculer temps relatif
function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $difference = time() - $timestamp;
    
    if ($difference < 60) return 'À l\'instant';
    if ($difference < 3600) return floor($difference / 60) . ' min';
    if ($difference < 86400) return floor($difference / 3600) . ' h';
    if ($difference < 604800) return floor($difference / 86400) . ' j';
    
    return date('d/m/Y', $timestamp);
}

// Tronquer du texte
function truncate($text, $length = 100, $suffix = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . $suffix;
}

// Vérifier si une URL est valide
function isValidUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

// Rediriger avec message flash
function redirectWithMessage($url, $message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header("Location: {$url}");
    exit;
}

// Afficher message flash
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

// Vérifier extension de fichier
function isAllowedFileType($filename, $allowedTypes) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, $allowedTypes);
}

// Générer token CSRF
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Vérifier token CSRF
function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get allowed MIME types configuration
 * Centralized configuration for upload validation
 */
function getAllowedMimeTypes() {
    return [
        'images' => ['image/jpeg', 'image/png', 'image/gif'],
        'documents' => ['application/zip', 'application/pdf', 'text/plain'],
        'all' => ['image/jpeg', 'image/png', 'image/gif', 'application/zip', 'application/pdf', 'text/plain']
    ];
}

/**
 * Get file extension from MIME type
 */
function mimeToExtension($mime) {
    $mimeMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'application/zip' => 'zip',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt'
    ];
    
    return $mimeMap[$mime] ?? null;
}

/**
 * Validate uploaded file
 * @param array $file The uploaded file from $_FILES
 * @param array $allowedMimes Array of allowed MIME types
 * @param int $maxSize Maximum file size in bytes
 * @return array|false Returns array with 'mime' and 'ext' on success, false on failure
 */
function isValidUpload($file, $allowedMimes, $maxSize) {
    // Check if file was uploaded
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return false;
    }
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    // Check file size
    if ($file['size'] > $maxSize || $file['size'] <= 0) {
        return false;
    }
    
    // Detect MIME type using finfo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        error_log("Failed to initialize finfo for file upload validation");
        return false;
    }
    
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if ($mime === false) {
        error_log("Failed to detect MIME type for uploaded file");
        return false;
    }
    
    // Validate MIME type
    if (!in_array($mime, $allowedMimes)) {
        return false;
    }
    
    // Get extension from MIME
    $ext = mimeToExtension($mime);
    if ($ext === null) {
        return false;
    }
    
    return [
        'mime' => $mime,
        'ext' => $ext
    ];
}
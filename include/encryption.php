<?php
define('ENCRYPTION_KEY', 'thisIsA32ByteLongSecretKey123456'); // exactly 32 chars!
define('OFFICE_BASE_URL', (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false) ? 'http://localhost/BUGO-Admin' : 'https://office.bugoportal.site'); // no trailing slash
// define('OFFICE_BASE_URL', 'https://office.bugoportal.site'); // no trailing slash
/* =========
   Crypto
   ========= */
function encrypt($data, $key = ENCRYPTION_KEY) {
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted);
}
function decrypt($data, $key = ENCRYPTION_KEY) {
    $decoded = base64_decode($data);
    if ($decoded === false || strlen($decoded) <= 16) return false;
    $iv = substr($decoded, 0, 16);
    $ciphertext = substr($decoded, 16);
    return openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
}

/* =========
   Helpers
   ========= */
function build_office_url(string $indexFile, string $pageName): string {
    return OFFICE_BASE_URL . '/' . ltrim($indexFile, '/') . '?page=' . urlencode(encrypt($pageName));
}

/* =========
   Encoders
   ========= */
function enc_page(string $pageName): string        { return build_office_url('index_Admin.php',              $pageName); }
function enc_admin(string $pageName): string       { return build_office_url('index_Admin.php',              $pageName); }
function enc_revenue(string $pageName): string     { return build_office_url('index_revenue_staff.php',      $pageName); }
function enc_lupon(string $pageName): string       { return build_office_url('index_lupon.php',              $pageName); }
function enc_captain(string $pageName): string     { return build_office_url('index_captain.php',            $pageName); }
function enc_encoder(string $pageName): string     { return build_office_url('index_barangay_staff.php',     $pageName); }
function enc_multimedia(string $pageName): string  { return build_office_url('index_multimedia.php',         $pageName); }
function enc_brgysec(string $pageName): string     { return build_office_url('index_barangay_secretary.php', $pageName); }
function enc_beso(string $pageName): string        { return build_office_url('index_beso_staff.php',         $pageName); }
function enc_tanod(string $pageName): string        { return build_office_url('index_tanod.php',         $pageName); }

/* =========
   Redirects
   ========= */
function get_redirect_url($key, $isApi = false) {
    require_once 'redirects.php';
    $lookupKey = $isApi ? "{$key}_api" : $key;
    return $redirects[$lookupKey] ?? enc_page('admin_dashboard');
}
function role_redirect(string $roleName): string {
    $role = strtolower($roleName);
    switch (true) {
        case strpos($role, 'admin') !== false:
            return enc_admin('admin_dashboard');
        case strpos($role, 'revenue') !== false:
            return enc_revenue('admin_dashboard');
        case strpos($role, 'lupon') !== false:
            return enc_lupon('admin_dashboard');
        case strpos($role, 'captain') !== false || strpos($role, 'punong barangay') !== false:
            return enc_captain('admin_dashboard');
        case strpos($role, 'staff') !== false || strpos($role, 'encoder') !== false:
            return enc_encoder('admin_dashboard');
        case strpos($role, 'multimedia') !== false:
            return enc_multimedia('admin_dashboard');
        case strpos($role, 'secretary') !== false:
            return enc_brgysec('admin_dashboard');
        case strpos($role, 'beso') !== false:
            return enc_beso('admin_dashboard');
        case strpos($role, 'tanod') !== false:
            return enc_tanod('admin_dashboard');            
        default:
            return enc_admin('admin_dashboard');
    }
}


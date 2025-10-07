<?php 
if (isset($_GET['page'])) {
    $decrypted = decrypt($_GET['page']);
    if ($decrypted !== false) {
        $decryptedPage = $decrypted;
        // echo "<script>console.log('Decrypted page:', '$decryptedPage');</script>";
    } else {
        // echo "<script>console.log('Decryption failed');</script>";
    }
}


function get_role_based_action($pageName) {
    $role = strtolower($_SESSION['Role_Name'] ?? '');

    switch ($role) {
        case 'admin':
            return enc_admin($pageName);
        case 'punong barangay':
            return enc_captain($pageName);
        case 'beso':
            return enc_beso($pageName);
        case 'barangay secretary':
            return enc_brgysec($pageName);
        case 'lupon':
            return enc_lupon($pageName);
        case 'multimedia':
            return enc_multimedia($pageName);
        case 'revenue staff':
            return enc_revenue($pageName);
        case 'encoder':
            return enc_encoder($pageName);
        default:
            return enc_admin('admin_dashboard'); // fallback
    }
}
?>
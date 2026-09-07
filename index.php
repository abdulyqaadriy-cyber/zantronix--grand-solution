<?php
require 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['user']['password'])) {
    unset($_SESSION['user']['password']);
}
if (isset($_SESSION['user']['id'])) {
    $sessionRefresh = $pdo->prepare('SELECT id,name,username,phone,role,permissions,account_system,active FROM users WHERE id=? LIMIT 1');
    $sessionRefresh->execute([(int)$_SESSION['user']['id']]);
    $freshSessionUser = $sessionRefresh->fetch(PDO::FETCH_ASSOC);
    if (!$freshSessionUser || !(int)$freshSessionUser['active']) {
        unset($_SESSION['user'], $_SESSION['login_system']);
    } else {
        $_SESSION['user'] = $freshSessionUser;
    }
}

/* =========================================================
   ROUTING
   ========================================================= */

$page = $_GET['page'] ?? 'dashboard';
$action = $_GET['action'] ?? '';

/* =========================================================
   SYSTEM ISOLATION - EARLY ACTION GUARD
   Super Admin can use everything. Other users must stay
   inside the system(s) explicitly assigned to them.
   ========================================================= */
if (isset($_SESSION['user']) && $action !== '' && strpos($action, 'st_') === 0) {
    $roleForAccess = strtolower(trim($_SESSION['user']['role'] ?? ''));
    $permForAccess = json_decode($_SESSION['user']['permissions'] ?? '{}', true);
    if (!is_array($permForAccess)) $permForAccess = [];
    $isSuperForAccess = in_array($roleForAccess, ['super admin','super_admin','superadmin'], true);
    $accountSystemForAccess = strtolower(trim($_SESSION['user']['account_system'] ?? 'business'));
    $hasSiteForAccess = !empty($permForAccess['site_tracking_system']);

    if (!$isSuperForAccess && $accountSystemForAccess !== 'site' && $accountSystemForAccess !== 'both') {
        http_response_code(403);
        exit('Huna ruhusa ya kutumia Site Tracking System.');
    }
}

/* =========================================================
   LANGUAGE SWITCHER
   ========================================================= */
if (isset($_GET['lang']) && in_array($_GET['lang'], ['sw','en'], true)) {
    $_SESSION['zan_lang'] = $_GET['lang'];
}
$zanLang = $_SESSION['zan_lang'] ?? 'sw';
function zan_t($sw, $en) {
    global $zanLang;
    return $zanLang === 'en' ? $en : $sw;
}

/* =========================================================
   GOOGLE DRIVE - SINGLE FILE INTEGRATION
   ========================================================= */

$googleClientJson = __DIR__ .
    '/client_secret_792384272869-lqqmuk8rbej1j7dsf19lg00v4vhp2fn7.apps.googleusercontent.com.json';

$googleTokenFile = __DIR__ . '/google_drive_token.json';

$googleRedirectUri =
    'http://localhost/Zantronix_v6/zantronix/index.php?page=settings&action=google_callback';

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

function google_client() {
    global $googleClientJson, $googleRedirectUri;

    if (!class_exists('Google_Client')) {
        throw new Exception(
            'Google API PHP Client haipo. Hakikisha vendor/autoload.php ipo.'
        );
    }

    if (!file_exists($googleClientJson)) {
        throw new Exception(
            'Google Client JSON haijapatikana: ' . basename($googleClientJson)
        );
    }

    $client = new Google_Client();
    $client->setApplicationName('Zantronix Invoice System');
    $client->setAuthConfig($googleClientJson);
    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $client->setScopes([
        Google_Service_Drive::DRIVE_FILE
    ]);
    $client->setRedirectUri($googleRedirectUri);

    return $client;
}

function google_token_load() {
    global $googleTokenFile;

    if (!file_exists($googleTokenFile)) {
        return null;
    }

    $raw = @file_get_contents($googleTokenFile);
    if (!$raw) {
        return null;
    }

    $token = json_decode($raw, true);
    return is_array($token) ? $token : null;
}

function google_token_save($token) {
    global $googleTokenFile;

    file_put_contents(
        $googleTokenFile,
        json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function google_drive_service() {
    $client = google_client();

    $token = google_token_load();

    if (!$token && isset($_SESSION['google_access_token'])) {
        $token = $_SESSION['google_access_token'];
    }

    if (!$token) {
        throw new Exception('Google Drive haijaunganishwa.');
    }

    $client->setAccessToken($token);

    if ($client->isAccessTokenExpired()) {
        $refreshToken = $client->getRefreshToken();

        if (!$refreshToken && isset($token['refresh_token'])) {
            $refreshToken = $token['refresh_token'];
        }

        if (!$refreshToken) {
            throw new Exception(
                'Google Drive connection ime-expire. Unganisha Google Drive tena.'
            );
        }

        $newToken =
            $client->fetchAccessTokenWithRefreshToken($refreshToken);

        if (isset($newToken['error'])) {
            throw new Exception(
                'Google token refresh failed: ' .
                ($newToken['error_description'] ?? $newToken['error'])
            );
        }

        if (!isset($newToken['refresh_token'])) {
            $newToken['refresh_token'] = $refreshToken;
        }

        google_token_save($newToken);
        $_SESSION['google_access_token'] = $newToken;
        $client->setAccessToken($newToken);
    } else {
        $_SESSION['google_access_token'] = $token;
    }

    return new Google_Service_Drive($client);
}

function google_backup_folder() {
    $drive = google_drive_service();

    $name = 'Zantronix Backups';

    $q =
        "name = '" . addslashes($name) . "'" .
        " and mimeType = 'application/vnd.google-apps.folder'" .
        " and trashed = false";

    $result = $drive->files->listFiles([
        'q' => $q,
        'spaces' => 'drive',
        'fields' => 'files(id,name)',
        'pageSize' => 10
    ]);

    $files = $result->getFiles();

    if (!empty($files)) {
        return $files[0]->getId();
    }

    $metadata = new Google_Service_Drive_DriveFile([
        'name' => $name,
        'mimeType' => 'application/vnd.google-apps.folder'
    ]);

    $folder = $drive->files->create(
        $metadata,
        ['fields' => 'id,name']
    );

    return $folder->getId();
}

function google_upload_backup($zipFile) {
    $drive = google_drive_service();
    $folderId = google_backup_folder();

    $metadata = new Google_Service_Drive_DriveFile([
        'name' => basename($zipFile),
        'parents' => [$folderId]
    ]);

    $content = @file_get_contents($zipFile);

    if ($content === false) {
        throw new Exception('Backup ZIP haikuweza kusomwa.');
    }

    $file = $drive->files->create(
        $metadata,
        [
            'data' => $content,
            'mimeType' => 'application/zip',
            'uploadType' => 'multipart',
            'fields' => 'id,name,size,createdTime'
        ]
    );

    return $file;
}

function google_list_backups() {
    $drive = google_drive_service();
    $folderId = google_backup_folder();

    $q =
        "'" . $folderId . "' in parents" .
        " and trashed = false" .
        " and mimeType = 'application/zip'";

    $result = $drive->files->listFiles([
        'q' => $q,
        'spaces' => 'drive',
        'orderBy' => 'createdTime desc',
        'pageSize' => 100,
        'fields' => 'files(id,name,size,createdTime,mimeType)'
    ]);

    return $result->getFiles();
}

function google_download_backup($fileId, $destination) {
    $drive = google_drive_service();

    $response = $drive->files->get(
        $fileId,
        ['alt' => 'media']
    );

    $body = $response->getBody();

    $fp = @fopen($destination, 'wb');

    if (!$fp) {
        throw new Exception(
            'Imeshindikana kutengeneza temporary backup file.'
        );
    }

    while (!$body->eof()) {
        $data = $body->read(1024 * 1024);

        if ($data === '') {
            break;
        }

        fwrite($fp, $data);
    }

    fclose($fp);

    if (!file_exists($destination) || filesize($destination) < 10) {
        throw new Exception(
            'Backup haikupakuliwa kutoka Google Drive.'
        );
    }
}

function zan_delete_dir($dir) {
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;

        if (is_dir($path)) {
            zan_delete_dir($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

function zan_copy_dir($source, $destination) {
    if (!is_dir($source)) {
        return;
    }

    if (!is_dir($destination)) {
        mkdir($destination, 0777, true);
    }

    foreach (scandir($source) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $src = $source . DIRECTORY_SEPARATOR . $item;
        $dst = $destination . DIRECTORY_SEPARATOR . $item;

        if (is_dir($src)) {
            zan_copy_dir($src, $dst);
        } else {
            @copy($src, $dst);
        }
    }
}

function zan_create_local_backup($prefix = 'Before_Restore') {
    if (!class_exists('ZipArchive')) {
        throw new Exception(
            'PHP ZipArchive haijawezeshwa.'
        );
    }

    $dbFile = __DIR__ . '/db/zantronix.sqlite';
    $uploads = __DIR__ . '/uploads';
    $backupDir = __DIR__ . '/backups';

    if (!file_exists($dbFile)) {
        throw new Exception('Database ya Zantronix haipatikani.');
    }

    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0777, true);
    }

    $zipFile =
        $backupDir . '/' .
        $prefix . '_' .
        date('Y-m-d_H-i-s') .
        '.zip';

    $zip = new ZipArchive();

    if ($zip->open(
        $zipFile,
        ZipArchive::CREATE | ZipArchive::OVERWRITE
    ) !== true) {
        throw new Exception(
            'Imeshindikana kutengeneza backup.'
        );
    }

    $zip->addFile(
        $dbFile,
        'db/zantronix.sqlite'
    );

    if (is_dir($uploads)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $uploads,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $full = $file->getPathname();

            $relative =
                'uploads/' .
                substr(
                    $full,
                    strlen($uploads) + 1
                );

            $zip->addFile($full, $relative);
        }
    }

    $zip->addFromString(
        'backup_info.txt',
        "Zantronix Invoice System\n" .
        "Created: " . date('Y-m-d H:i:s') . "\n" .
        "Database: SQLite"
    );

    $zip->close();

    return $zipFile;
}

function zan_restore_zip($zipFile) {
    if (!class_exists('ZipArchive')) {
        throw new Exception('PHP ZipArchive haijawezeshwa.');
    }

    $dbFile = __DIR__ . '/db/zantronix.sqlite';
    $uploads = __DIR__ . '/uploads';

    $temp = __DIR__ . '/restore_temp_' . time();

    if (!mkdir($temp, 0777, true)) {
        throw new Exception(
            'Imeshindikana kutengeneza folder ya muda.'
        );
    }

    try {
        $zip = new ZipArchive();

        if ($zip->open($zipFile) !== true) {
            throw new Exception(
                'Backup ZIP haiwezi kufunguliwa.'
            );
        }

        $dbIndex = $zip->locateName(
            'db/zantronix.sqlite'
        );

        if ($dbIndex === false) {
            $zip->close();

            throw new Exception(
                'Backup hii si backup halali ya Zantronix.'
            );
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);

            if (
                strpos($entry, '../') !== false ||
                strpos($entry, '..\\') !== false ||
                str_starts_with($entry, '/') ||
                preg_match('/^[A-Za-z]:[\\\\\/]/', $entry)
            ) {
                $zip->close();

                throw new Exception(
                    'Backup ina file path isiyo salama.'
                );
            }
        }

        if (!$zip->extractTo($temp)) {
            $zip->close();

            throw new Exception(
                'Imeshindikana kufungua backup.'
            );
        }

        $zip->close();

        $restoredDb =
            $temp . '/db/zantronix.sqlite';

        if (!file_exists($restoredDb)) {
            throw new Exception(
                'Database haikupatikana ndani ya backup.'
            );
        }

        $test = new PDO(
            'sqlite:' . $restoredDb
        );

        $test->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        $test->query(
            'SELECT name FROM sqlite_master LIMIT 1'
        );

        $test = null;

        /* Save current system before replacing it. */
        zan_create_local_backup('Before_Restore');

        global $pdo;
        $pdo = null;

        if (!copy($restoredDb, $dbFile)) {
            throw new Exception(
                'Database imeshindikana kurejeshwa.'
            );
        }

        $restoredUploads =
            $temp . '/uploads';

        if (is_dir($restoredUploads)) {
            if (is_dir($uploads)) {
                zan_delete_dir($uploads);
            }

            mkdir($uploads, 0777, true);
            zan_copy_dir(
                $restoredUploads,
                $uploads
            );
        }

    } finally {
        zan_delete_dir($temp);
    }
}

function google_is_connected() {
    return is_array(google_token_load());
}

/* =========================================================
   GOOGLE DRIVE ACTIONS - ALL INSIDE INDEX.PHP
   ========================================================= */

if (
    $page === 'settings' &&
    $action === 'google_connect'
) {
    if (!can_do('settings')) {
        exit('Huna ruhusa ya kutumia Google Drive.');
    }

    try {
        $client = google_client();

        header(
            'Location: ' . $client->createAuthUrl()
        );

        exit;
    } catch (Exception $e) {
        exit(
            'Google Drive error: ' .
            htmlspecialchars($e->getMessage())
        );
    }
}

if (
    $page === 'settings' &&
    $action === 'google_callback'
) {
    if (!can_do('settings')) {
        exit('Huna ruhusa ya kutumia Google Drive.');
    }

    try {
        if (empty($_GET['code'])) {
            throw new Exception(
                'Google haikurudisha authorization code.'
            );
        }

        $client = google_client();

        $token = $client->fetchAccessTokenWithAuthCode(
            $_GET['code']
        );

        if (isset($token['error'])) {
            throw new Exception(
                'Google authorization failed: ' .
                ($token['error_description'] ?? $token['error'])
            );
        }

        google_token_save($token);
        $_SESSION['google_access_token'] = $token;

        header(
            'Location: index.php?page=settings&google_connected=1'
        );

        exit;
    } catch (Exception $e) {
        header(
            'Location: index.php?page=settings&google_error=' .
            urlencode($e->getMessage())
        );

        exit;
    }
}

if (
    $page === 'settings' &&
    $action === 'google_disconnect'
) {
    if (!can_do('settings')) {
        exit('Huna ruhusa ya kutumia Google Drive.');
    }

    global $googleTokenFile;

    @unlink($googleTokenFile);

    unset($_SESSION['google_access_token']);

    header(
        'Location: index.php?page=settings&google_disconnected=1'
    );

    exit;
}

if (
    $page === 'settings' &&
    $action === 'google_backup'
) {
    if (!can_do('settings')) {
        exit('Huna ruhusa ya kufanya backup.');
    }

    try {
        $zipFile = zan_create_local_backup(
            'Zantronix_Backup'
        );

        $uploaded = google_upload_backup(
            $zipFile
        );

        header(
            'Location: index.php?page=settings&google_backup=1&file=' .
            urlencode($uploaded->getName())
        );

        exit;

    } catch (Exception $e) {
        header(
            'Location: index.php?page=settings&google_error=' .
            urlencode($e->getMessage())
        );

        exit;
    }
}

if (
    $page === 'settings' &&
    $action === 'google_restore' &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {
    if (!can_do('settings')) {
        exit('Huna ruhusa ya kufanya restore.');
    }

    try {
        $fileId =
            $_POST['file_id'] ?? '';

        if (!$fileId) {
            throw new Exception(
                'Backup haijachaguliwa.'
            );
        }

        $tempZip =
            __DIR__ .
            '/google_restore_' .
            time() .
            '.zip';

        google_download_backup(
            $fileId,
            $tempZip
        );

        zan_restore_zip(
            $tempZip
        );

        @unlink($tempZip);

        header(
            'Location: index.php?page=settings&google_restored=1'
        );

        exit;

    } catch (Exception $e) {
        if (isset($tempZip)) {
            @unlink($tempZip);
        }

        header(
            'Location: index.php?page=settings&google_error=' .
            urlencode($e->getMessage())
        );

        exit;
    }
}


/* =========================================================
   ZANTRONIX INVOICE SYSTEM
   COMPLETE INDEX.PHP
   ========================================================= */


/* =========================================================
   SAFE DATABASE MIGRATIONS
   ========================================================= */

function column_exists($table, $column) {
    global $pdo;

    $rows = $pdo->query("PRAGMA table_info($table)")->fetchAll();

    foreach ($rows as $r) {
        if ($r['name'] === $column) {
            return true;
        }
    }

    return false;
}

/* Proforma Invoice fields */
if (!column_exists('proforma_invoices', 'valid_until')) {
    $pdo->exec("ALTER TABLE proforma_invoices ADD COLUMN valid_until TEXT DEFAULT NULL");
}
if (!column_exists('proforma_invoices', 'notes')) {
    $pdo->exec("ALTER TABLE proforma_invoices ADD COLUMN notes TEXT DEFAULT NULL");
}
if (!column_exists('proforma_invoices', 'terms')) {
    $pdo->exec("ALTER TABLE proforma_invoices ADD COLUMN terms TEXT DEFAULT NULL");
}

/* Quotation document fields */
if (!column_exists('quotations', 'valid_until')) {
    $pdo->exec("ALTER TABLE quotations ADD COLUMN valid_until TEXT DEFAULT NULL");
}
if (!column_exists('quotations', 'notes')) {
    $pdo->exec("ALTER TABLE quotations ADD COLUMN notes TEXT DEFAULT NULL");
}
if (!column_exists('quotations', 'terms')) {
    $pdo->exec("ALTER TABLE quotations ADD COLUMN terms TEXT DEFAULT NULL");
}

if (!column_exists('invoices', 'subtotal')) {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN subtotal REAL DEFAULT 0");
}

if (!column_exists('invoices', 'vat')) {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN vat REAL DEFAULT 0");
}

if (!column_exists('invoices', 'grand_total')) {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN grand_total REAL DEFAULT 0");
}

/* Invoice discount fields - discount never changes product stock quantity. */
if (!column_exists('invoices', 'discount_type')) {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN discount_type TEXT DEFAULT 'amount'");
}
if (!column_exists('invoices', 'discount_value')) {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN discount_value REAL DEFAULT 0");
}
if (!column_exists('invoices', 'discount_amount')) {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN discount_amount REAL DEFAULT 0");
}

if (!column_exists('users', 'phone')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN phone TEXT DEFAULT ''");
}

/* Payment number + QR code used on printed documents */
if (!column_exists('settings', 'pay_number')) {
    $pdo->exec("ALTER TABLE settings ADD COLUMN pay_number TEXT DEFAULT ''");
}
if (!column_exists('settings', 'pay_qr')) {
    $pdo->exec("ALTER TABLE settings ADD COLUMN pay_qr TEXT DEFAULT ''");
}


/* =========================================================
   SUPPLIERS + BARCODE + PRODUCT SUPPLIER MIGRATIONS
   ========================================================= */

$pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_name TEXT NOT NULL,
    contact_person TEXT DEFAULT '',
    phone TEXT DEFAULT '',
    email TEXT DEFAULT '',
    address TEXT DEFAULT '',
    tin TEXT DEFAULT '',
    vrn TEXT DEFAULT '',
    notes TEXT DEFAULT '',
    created_at TEXT NOT NULL,
    updated_at TEXT DEFAULT ''
)");

if (!column_exists('products', 'barcode')) {
    $pdo->exec("ALTER TABLE products ADD COLUMN barcode TEXT DEFAULT ''");
}
if (!column_exists('products', 'supplier_id')) {
    $pdo->exec("ALTER TABLE products ADD COLUMN supplier_id INTEGER DEFAULT NULL");
}
try {
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_products_barcode ON products(barcode) WHERE barcode IS NOT NULL AND barcode != ''");
} catch (Exception $e) {}

/* =========================================================
   BUSINESS MODULE MIGRATIONS
   ========================================================= */
if (!column_exists('products', 'stock_received_at')) {
    $pdo->exec("ALTER TABLE products ADD COLUMN stock_received_at TEXT DEFAULT ''");
}
try { $pdo->exec("UPDATE products SET stock_received_at=COALESCE(NULLIF(stock_received_at,''),datetime('now'))"); } catch (Exception $e) {}

$pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    expense_date TEXT NOT NULL,
    category TEXT NOT NULL,
    description TEXT DEFAULT '',
    amount REAL NOT NULL DEFAULT 0,
    paid_by TEXT DEFAULT '',
    notes TEXT DEFAULT '',
    created_by INTEGER DEFAULT NULL,
    created_at TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS quotations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    quotation_no TEXT NOT NULL UNIQUE,
    customer_id INTEGER NOT NULL,
    subtotal REAL NOT NULL DEFAULT 0,
    vat REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL DEFAULT 0,
    status TEXT DEFAULT 'Draft',
    prepared_by INTEGER DEFAULT NULL,
    created_at TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS quotation_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    quotation_id INTEGER NOT NULL,
    product_id INTEGER DEFAULT NULL,
    description TEXT NOT NULL,
    qty INTEGER NOT NULL DEFAULT 1,
    price REAL NOT NULL DEFAULT 0,
    subtotal REAL NOT NULL DEFAULT 0
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS proforma_invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    proforma_no TEXT NOT NULL UNIQUE,
    customer_id INTEGER NOT NULL,
    subtotal REAL NOT NULL DEFAULT 0,
    vat REAL NOT NULL DEFAULT 0,
    total REAL NOT NULL DEFAULT 0,
    status TEXT DEFAULT 'Draft',
    prepared_by INTEGER DEFAULT NULL,
    created_at TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS proforma_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    proforma_id INTEGER NOT NULL,
    product_id INTEGER DEFAULT NULL,
    description TEXT NOT NULL,
    qty INTEGER NOT NULL DEFAULT 1,
    price REAL NOT NULL DEFAULT 0,
    subtotal REAL NOT NULL DEFAULT 0
)");

/* =========================================================
   LOGOUT
   ========================================================= */

if (isset($_GET['logout'])) {

    session_destroy();

    header('Location:index.php');

    exit;
}

/* =========================================================
   FORGOT PASSWORD / PASSWORD RESET
   ========================================================= */
if ($page === 'forgot_password') {
    $resetError=''; $resetDone=false;
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $username=trim($_POST['username']??''); $phone=trim($_POST['phone']??'');
        $newPassword=(string)($_POST['new_password']??''); $confirm=(string)($_POST['confirm_password']??'');
        $q=$pdo->prepare('SELECT * FROM users WHERE username=? AND phone=? AND active=1 LIMIT 1'); $q->execute([$username,$phone]); $u=$q->fetch();
        if(!$u) $resetError='Username na namba ya simu havikulingana na mtumiaji hai.';
        elseif(strlen($newPassword)<6) $resetError='Password mpya iwe na angalau herufi 6.';
        elseif($newPassword!==$confirm) $resetError='Password mpya hazifanani.';
        else { $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([hash('sha256',$newPassword),$u['id']]); log_action('Alibadilisha password','Password reset ya username: '.$username); $resetDone=true; }
    }
    ?>
    <!doctype html><html lang="sw"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Forgot Password - Zantronix</title><link rel="stylesheet" href="style.css"><style>body{background:#ffd000!important}.reset-box{max-width:430px;margin:70px auto;background:#fff;padding:28px;border-radius:16px;box-shadow:0 10px 35px rgba(0,0,0,.18)}.reset-box input{width:100%;box-sizing:border-box;margin:7px 0 15px;padding:12px;border:1px solid #ddd;border-radius:8px}.reset-box button{width:100%}
.invoice-row .qty-minus,.invoice-row .qty-plus{min-width:38px;padding:8px 10px}.invoice-row input[name="qty[]"]{max-width:80px;text-align:center}.doc-notes p{margin:6px 0 12px;white-space:normal}.badge{display:inline-block;margin-top:8px;padding:4px 8px;border-radius:999px;font-weight:800;font-size:9px}
</style>
<style>
.st-actions{display:flex;gap:8px;flex-wrap:wrap}.st-actions .btn{min-width:90px}
.st-edit-box{border:1px solid #ddd;border-radius:12px;padding:16px;margin:15px 0;background:#fff}
.st-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin:15px 0}
.st-stat{padding:16px;border:1px solid #ddd;border-radius:12px;background:#fff}
.st-chart-wrap{background:#fff;border:1px solid #ddd;border-radius:12px;padding:18px;margin-top:18px}
.st-bar-row{display:grid;grid-template-columns:150px 1fr 55px;gap:10px;align-items:center;margin:10px 0}
.st-bar-track{height:22px;background:#eee;border-radius:20px;overflow:hidden}.st-bar{height:100%;background:#1f6feb;border-radius:20px}
.st-photo{max-width:130px;max-height:130px;border-radius:10px;border:1px solid #ddd;object-fit:cover}
@media(max-width:700px){.st-bar-row{grid-template-columns:100px 1fr 45px}.grid-form{grid-template-columns:1fr!important}.btn{width:100%}.st-actions .btn{width:auto;flex:1}}
</style>
</head><body><div class="reset-box"><h1>⚡ ZANTRONIX</h1><h2>Forgot Password</h2><?php if($resetError): ?><div class="notice"><?=htmlspecialchars($resetError)?></div><?php endif; ?><?php if($resetDone): ?><div class="notice">✓ Password imebadilishwa. Sasa unaweza kuingia.</div><a class="btn" href="index.php">Back to Login</a><?php else: ?><form method="post" enctype="multipart/form-data"><label>Username<input name="username" required></label><label>Phone Number<input name="phone" required></label><label>New Password<input type="password" name="new_password" minlength="6" required></label><label>Confirm Password<input type="password" name="confirm_password" minlength="6" required></label><button class="btn" type="submit">Reset Password</button></form><br><a class="btn black" href="index.php">Back to Login</a><?php endif; ?></div>
<script>
document.addEventListener('DOMContentLoaded',function(){
 document.querySelectorAll('input[type=file][accept*="image"]').forEach(function(i){
   i.setAttribute('capture','environment');
 });
});
</script>
</body></html><?php exit;
}

/* =========================================================
   LOGIN
   ========================================================= */

/* Make the saved company logo available on the login screen too. */
if (!isset($settings) || !is_array($settings)) {
    try {
        $settings = $pdo->query('SELECT * FROM settings WHERE id=1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $settings = [];
    }
}

if (!isset($_SESSION['user'])) {

    $err = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $s = $pdo->prepare(
            'SELECT * FROM users WHERE username=? AND password=? AND active=1 LIMIT 1'
        );

        $s->execute([
            $_POST['username'] ?? '',
            hash('sha256', $_POST['password'] ?? '')
        ]);

        $u = $s->fetch();

        if ($u && $u['active']) {

            $accountSystem = strtolower(trim($u['account_system'] ?? 'business'));
            $requestedSystem = $accountSystem === 'site' ? 'site' : 'business';
            unset($u['password']);
            $_SESSION['user'] = $u;
            $_SESSION['login_system'] = $requestedSystem;

            log_action(
                'Aliingia kwenye mfumo',
                ''
            );

           // Mfumo huamua destination kwa ruhusa za user.
$loginPerms = json_decode($u['permissions'] ?? '{}', true);
if (!is_array($loginPerms)) {
    $loginPerms = [];
}

$loginRole = strtolower(trim($u['role'] ?? ''));

$isSuperAdmin = in_array(
    $loginRole,
    ['super admin', 'super_admin', 'superadmin'],
    true
);

$isAdmin = in_array(
    $loginRole,
    ['admin', 'msimamizi'],
    true
);

$hasBusiness = $isSuperAdmin
    || (($accountSystem === 'business' || $accountSystem === 'both')
        && ($isAdmin || !empty($loginPerms['business_system'])));

$hasTools = $isSuperAdmin
    || (($accountSystem === 'site' || $accountSystem === 'both')
        && !empty($loginPerms['site_tracking_system']));

            if ($hasBusiness && $hasTools) {

    // Ana ruhusa za mifumo yote miwili.
    header('Location: index.php?page=choose_system');
    exit;

} elseif ($hasTools) {

    // Site Tracking pekee.
    header('Location: index.php?page=site_tracking');
    exit;

} elseif ($hasBusiness) {

    // Business System pekee.
    header('Location: index.php');
    exit;

} else {

    // Hana ruhusa ya mfumo wowote.
    http_response_code(403);
    exit('Huna ruhusa ya kutumia mfumo.');
}
        }

        $err = ($u && !$u['active'])
            ? 'Mtumiaji huyu amezimwa.'
            : 'Jina la mtumiaji au nenosiri si sahihi.';
    }
?>
<!doctype html>
<html lang="sw">

<head>

<meta charset="utf-8">

<meta name="viewport"
      content="width=device-width,initial-scale=1">

<title>Kuingia - Zantronix</title>

<link rel="stylesheet"
      href="style.css">

</head>

<body style="background:#ffd000">

<div style="
    max-width:400px;
    margin:100px auto;
    background:#fff;
    padding:30px;
    border-radius:14px;
">

<?php if (!empty($settings['logo']) && file_exists(__DIR__ . '/' . $settings['logo'])): ?>
<img src="<?=htmlspecialchars($settings['logo'])?>" alt="Logo" style="display:block;max-width:180px;max-height:90px;margin:0 auto 18px;object-fit:contain">
<?php else: ?>
<div style="font-size:28px;font-weight:800;text-align:center;margin-bottom:18px">⚡</div>
<?php endif; ?>

<?php if ($err): ?>

<div class="notice">
    <?=htmlspecialchars($err)?>
</div>

<?php endif; ?>

<form method="post" enctype="multipart/form-data" autocomplete="off">

<label>
Jina la mtumiaji

<input
    class="search"
    style="width:100%;margin:6px 0 14px"
    name="username"
    required
>

</label>

<label>
Nenosiri

<input
    class="search"
    style="width:100%;margin:6px 0 18px"
    type="password"
    name="password"
    autocomplete="off"
    required
>

</label>

<button
    class="btn"
    style="width:100%"
>
INGIA
</button>

</form>

<p style="text-align:center;margin-top:15px"><a href="?page=forgot_password">Forgot Password?</a></p>

</div>

</body>
</html>

<?php
exit;
}


/* =========================================================
   SITE TRACKING SYSTEM - DATABASE & ACTIONS
   ========================================================= */

$pdo->exec("CREATE TABLE IF NOT EXISTS st_technicians (
 id INTEGER PRIMARY KEY AUTOINCREMENT, technician_no TEXT UNIQUE, full_name TEXT NOT NULL,
 phone TEXT, email TEXT, address TEXT, office TEXT, gender TEXT, marital_status TEXT, children_count INTEGER DEFAULT 0, emergency_name TEXT, emergency_phone TEXT,
 position TEXT, specialization TEXT, photo TEXT, status TEXT DEFAULT 'Active',
 user_id INTEGER, username TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS st_sites (
 id INTEGER PRIMARY KEY AUTOINCREMENT, site_name TEXT NOT NULL, customer_name TEXT, location TEXT,
 technician_id INTEGER, start_date TEXT, due_date TEXT, status TEXT DEFAULT 'Pending',
 notes TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS st_site_technicians (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL,
 technician_id INTEGER NOT NULL,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(site_id,technician_id)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS st_tools (
 id INTEGER PRIMARY KEY AUTOINCREMENT, tool_code TEXT UNIQUE, tool_name TEXT NOT NULL, serial_no TEXT,
 quantity INTEGER DEFAULT 1, condition_status TEXT DEFAULT 'Good', status TEXT DEFAULT 'Available',
 notes TEXT, photo TEXT, purchase_date TEXT, value_amount REAL DEFAULT 0,
 storage_location TEXT, registration_notes TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS st_materials (
 id INTEGER PRIMARY KEY AUTOINCREMENT, material_name TEXT NOT NULL, unit TEXT DEFAULT 'pcs',
 stock REAL DEFAULT 0, reorder_level REAL DEFAULT 0, notes TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS st_tool_movements (
 id INTEGER PRIMARY KEY AUTOINCREMENT, tool_id INTEGER NOT NULL, technician_id INTEGER NOT NULL, site_id INTEGER,
 customer_name TEXT, action_type TEXT NOT NULL, due_date TEXT, condition_status TEXT,
 photo TEXT, notes TEXT, technician_confirmed INTEGER DEFAULT 0, manager_approved INTEGER DEFAULT 0,
 technical_approved INTEGER DEFAULT 0, status TEXT DEFAULT 'Submitted', penalty REAL DEFAULT 0,
 created_by TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS st_material_movements (
 id INTEGER PRIMARY KEY AUTOINCREMENT, material_id INTEGER NOT NULL, technician_id INTEGER NOT NULL, site_id INTEGER,
 customer_name TEXT, quantity REAL NOT NULL, movement_type TEXT DEFAULT 'Issued', photo TEXT, reason TEXT,
 technician_confirmed INTEGER DEFAULT 0, manager_approved INTEGER DEFAULT 0, technical_approved INTEGER DEFAULT 0,
 status TEXT DEFAULT 'Submitted', created_by TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS st_site_photos (id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL,technician_id INTEGER,photo TEXT NOT NULL,category TEXT DEFAULT 'Work Progress',caption TEXT,latitude REAL,longitude REAL,accuracy REAL,captured_at TEXT DEFAULT CURRENT_TIMESTAMP,created_by TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE IF NOT EXISTS st_site_tasks (
 id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, task_title TEXT NOT NULL,
 notes TEXT, status TEXT DEFAULT 'Pending', assigned_to INTEGER, completed_by TEXT,
 completed_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS st_daily_updates (
 id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, technician_id INTEGER NOT NULL,
 update_date TEXT NOT NULL, work_done TEXT NOT NULL, blockers TEXT, next_steps TEXT,
 photo TEXT, latitude REAL, longitude REAL, accuracy REAL, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(site_id, technician_id, update_date)
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS st_notifications (
 id INTEGER PRIMARY KEY AUTOINCREMENT, technician_id INTEGER, notification_type TEXT NOT NULL,
 message TEXT NOT NULL, channel TEXT DEFAULT 'whatsapp', status TEXT DEFAULT 'Pending',
 sent_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS st_tool_requests (
 id INTEGER PRIMARY KEY AUTOINCREMENT, technician_id INTEGER NOT NULL, site_id INTEGER NOT NULL,
 tool_id INTEGER NOT NULL, quantity INTEGER DEFAULT 1, reason TEXT, status TEXT DEFAULT 'Pending',
 reviewed_by TEXT, reviewed_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS st_material_requests (
 id INTEGER PRIMARY KEY AUTOINCREMENT, technician_id INTEGER NOT NULL, site_id INTEGER NOT NULL,
 material_id INTEGER NOT NULL, quantity REAL NOT NULL, reason TEXT, status TEXT DEFAULT 'Pending',
 reviewed_by TEXT, reviewed_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS st_material_funds (
 id INTEGER PRIMARY KEY AUTOINCREMENT, technician_id INTEGER NOT NULL, site_id INTEGER NOT NULL,
 amount REAL NOT NULL, currency TEXT DEFAULT 'TZS', purpose TEXT, status TEXT DEFAULT 'Granted',
 receipt_photo TEXT, receipt_notes TEXT, receipt_submitted_at TEXT, receipt_latitude REAL,
 receipt_longitude REAL, receipt_accuracy REAL, granted_by TEXT, granted_at TEXT DEFAULT CURRENT_TIMESTAMP,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

/* Extra columns for old databases. */
function st_add_column($pdo,$table,$column,$definition){
    try {
        $cols=$pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
        foreach($cols as $c){ if(($c['name']??'')===$column) return; }
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    } catch(Throwable $e) {}
}
st_add_column($pdo,'st_technicians','user_id','INTEGER');
st_add_column($pdo,'st_technicians','username','TEXT');
st_add_column($pdo,'st_technicians','office','TEXT');
st_add_column($pdo,'st_technicians','gender','TEXT');
st_add_column($pdo,'st_technicians','marital_status','TEXT');
st_add_column($pdo,'st_technicians','children_count','INTEGER DEFAULT 0');
st_add_column($pdo,'st_tools','photo','TEXT');
st_add_column($pdo,'st_tools','purchase_date','TEXT');
st_add_column($pdo,'st_tools','value_amount','REAL DEFAULT 0');
st_add_column($pdo,'st_tools','storage_location','TEXT');
st_add_column($pdo,'st_tools','registration_notes','TEXT');
st_add_column($pdo,'st_tool_movements','manager_by','TEXT');
st_add_column($pdo,'st_tool_movements','manager_at','TEXT');
st_add_column($pdo,'st_tool_movements','manager_comment','TEXT');
st_add_column($pdo,'st_tool_movements','technical_by','TEXT');
st_add_column($pdo,'st_tool_movements','technical_at','TEXT');
st_add_column($pdo,'st_tool_movements','technical_comment','TEXT');
st_add_column($pdo,'st_tool_movements','return_photo','TEXT');
st_add_column($pdo,'st_tool_movements','penalty_amount','REAL DEFAULT 0');
st_add_column($pdo,'st_tool_movements','penalty_reason','TEXT');
st_add_column($pdo,'st_material_movements','manager_by','TEXT');
st_add_column($pdo,'st_material_movements','manager_at','TEXT');
st_add_column($pdo,'st_material_movements','manager_comment','TEXT');
st_add_column($pdo,'st_material_movements','technical_by','TEXT');
st_add_column($pdo,'st_material_movements','technical_at','TEXT');
st_add_column($pdo,'st_material_movements','technical_comment','TEXT');
st_add_column($pdo,'st_material_movements','received_photo','TEXT');
st_add_column($pdo,'st_sites','completion_notes','TEXT');
st_add_column($pdo,'st_sites','completion_photo','TEXT');
st_add_column($pdo,'st_sites','completion_submitted_at','TEXT');
st_add_column($pdo,'st_sites','completion_submitted_by','TEXT');
st_add_column($pdo,'st_sites','admin_approved','INTEGER DEFAULT 0');
st_add_column($pdo,'st_sites','admin_approved_by','TEXT');
st_add_column($pdo,'st_sites','admin_approved_at','TEXT');
st_add_column($pdo,'st_sites','rejection_reason','TEXT');
st_add_column($pdo,'st_sites','completion_latitude','REAL');
st_add_column($pdo,'st_sites','completion_longitude','REAL');
st_add_column($pdo,'st_sites','completion_accuracy','REAL');
st_add_column($pdo,'st_material_movements','latitude','REAL');
st_add_column($pdo,'st_material_movements','longitude','REAL');
st_add_column($pdo,'st_material_movements','accuracy','REAL');
st_add_column($pdo,'st_material_movements','captured_at','TEXT');
st_add_column($pdo,'st_tool_movements','latitude','REAL');
st_add_column($pdo,'st_tool_movements','longitude','REAL');
st_add_column($pdo,'st_tool_movements','accuracy','REAL');
st_add_column($pdo,'st_tool_movements','captured_at','TEXT');
st_add_column($pdo,'st_material_funds','receipt_photo','TEXT');
st_add_column($pdo,'st_material_funds','receipt_notes','TEXT');
st_add_column($pdo,'st_material_funds','receipt_submitted_at','TEXT');
st_add_column($pdo,'st_material_funds','receipt_latitude','REAL');
st_add_column($pdo,'st_material_funds','receipt_longitude','REAL');
st_add_column($pdo,'st_material_funds','receipt_accuracy','REAL');

/* Existing primary site technicians become members of the team table. */
try {
    $pdo->exec("INSERT OR IGNORE INTO st_site_technicians(site_id,technician_id)
                SELECT id,technician_id FROM st_sites
                WHERE technician_id IS NOT NULL AND technician_id > 0");
} catch(Throwable $e) {}

$pdo->exec("CREATE TABLE IF NOT EXISTS st_audit_log (
 id INTEGER PRIMARY KEY AUTOINCREMENT, entity_type TEXT, entity_id INTEGER, action TEXT,
 details TEXT, performed_by TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS st_site_admins (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, username TEXT, full_name TEXT,
 admin_type TEXT, work_site_id INTEGER, office TEXT, passport_photo TEXT, phone TEXT, email TEXT, permissions TEXT DEFAULT '{}', whatsapp_template TEXT, active INTEGER DEFAULT 1,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
st_add_column($pdo,'st_site_admins','work_site_id','INTEGER');
st_add_column($pdo,'st_site_admins','office','TEXT');
st_add_column($pdo,'st_site_admins','passport_photo','TEXT');
st_add_column($pdo,'st_site_admins','permissions','TEXT DEFAULT \'{}\'');
st_add_column($pdo,'st_site_admins','whatsapp_template','TEXT');

function st_user_role(){ return strtolower(trim($_SESSION['user']['role'] ?? '')); }
function st_system_permissions(){ $p=json_decode($_SESSION['user']['permissions'] ?? '{}',true); return is_array($p)?$p:[]; }
function st_is_super_admin(){ return in_array(st_user_role(),['super admin','super_admin','superadmin'],true); }
function st_has_site_permission(){ return st_is_super_admin() || !empty(st_system_permissions()['site_tracking_system']); }
function st_has_business_permission(){ $r=st_user_role();$p=st_system_permissions();return st_is_super_admin()||in_array($r,['admin','msimamizi'],true)||!empty($p['business_system']); }
function st_site_admin_allowed(){ return st_has_site_permission() && (st_is_manager() || st_is_technical() || st_is_admin()); }
function st_current_user_name(){ return $_SESSION['user']['username'] ?? ($_SESSION['user']['name'] ?? 'User'); }
function st_whatsapp_number($phone){
    $number=preg_replace('/[^0-9]/','',(string)$phone);
    if($number==='')return '';
    if(str_starts_with($number,'00'))$number=substr($number,2);
    if(str_starts_with($number,'0'))$number='255'.substr($number,1);
    if(str_starts_with($number,'255'))return $number;
    return $number;
}
function st_whatsapp_link($phone,$message){
    $number=st_whatsapp_number($phone);
    return $number!==''?'https://wa.me/'.rawurlencode($number).'?text='.rawurlencode($message):'';
}
function st_queue_whatsapp_notification($pdo,$technicianId,$type,$message){
    $q=$pdo->prepare("SELECT id FROM st_notifications WHERE technician_id=? AND notification_type=? AND message=? AND date(created_at)=date('now') LIMIT 1");
    $q->execute([(int)$technicianId,$type,$message]);
    if(!$q->fetch())$pdo->prepare('INSERT INTO st_notifications(technician_id,notification_type,message) VALUES(?,?,?)')->execute([(int)$technicianId,$type,$message]);
}
function st_csrf_token(){
    if(empty($_SESSION['st_csrf_token']))$_SESSION['st_csrf_token']=bin2hex(random_bytes(32));
    return $_SESSION['st_csrf_token'];
}
function st_audit($pdo,$type,$id,$action,$details=''){
    $u=$_SESSION['user']['username'] ?? ($_SESSION['user']['name'] ?? 'System');
    $pdo->prepare("INSERT INTO st_audit_log(entity_type,entity_id,action,details,performed_by) VALUES(?,?,?,?,?)")->execute([$type,$id,$action,$details,$u]);
}
function st_is_manager(){ return st_has_site_permission() && in_array(st_user_role(),['manager','meneja','admin','msimamizi'],true); }
function st_is_technical(){ return st_has_site_permission() && in_array(st_user_role(),['technical manager','technical_manager','technicalmanager','admin','msimamizi'],true); }
function st_is_admin(){ if(st_is_super_admin())return true;if(!st_has_site_permission())return false;return in_array(st_user_role(),['admin','msimamizi','site admin','site_admin','manager','meneja','technical manager','technical_manager','technicalmanager'],true); }
function st_admin_permissions($pdo){
    if(st_is_super_admin())return ['*'=>1];
    $uid=(int)($_SESSION['user']['id']??0);if(!$uid)return [];
    $q=$pdo->prepare('SELECT permissions FROM st_site_admins WHERE user_id=? AND active=1 LIMIT 1');$q->execute([$uid]);$raw=$q->fetchColumn();
    if($raw===false||$raw===null||$raw==='')return ['*'=>1];
    $permissions=json_decode($raw,true);return is_array($permissions)&&$permissions? $permissions : ['*'=>1];
}
function st_admin_can($pdo,$permission){$permissions=st_admin_permissions($pdo);return !empty($permissions['*'])||!empty($permissions[$permission]);}
function st_admin_whatsapp_template($pdo){
    $uid=(int)($_SESSION['user']['id']??0);if(!$uid)return '';
    $q=$pdo->prepare('SELECT whatsapp_template FROM st_site_admins WHERE user_id=? AND active=1 LIMIT 1');$q->execute([$uid]);return trim((string)$q->fetchColumn());
}
function st_admin_message($pdo,$technician,$site,$days,$dueDate,$fallback){
    $template=st_admin_whatsapp_template($pdo)?:$fallback;
    return strtr($template,['{technician}'=>$technician,'{site}'=>$site,'{days}'=>$days,'{due_date}'=>$dueDate]);
}
function st_best_technicians($pdo,$technicians,$startDate,$endDate){
    $results=[];
    foreach($technicians as $technician){
        $id=(int)$technician['id'];
        $q=$pdo->prepare("SELECT COUNT(*) FROM st_sites WHERE technician_id=? AND status='Closed' AND date(COALESCE(admin_approved_at,created_at)) BETWEEN ? AND ?");$q->execute([$id,$startDate,$endDate]);$closed=(int)$q->fetchColumn();
        $q=$pdo->prepare("SELECT COUNT(*) FROM st_sites WHERE technician_id=? AND status='Closed' AND date(COALESCE(admin_approved_at,created_at)) BETWEEN ? AND ? AND (due_date='' OR due_date IS NULL OR date(due_date)>=date(COALESCE(admin_approved_at,created_at)))");$q->execute([$id,$startDate,$endDate]);$onTimeSites=(int)$q->fetchColumn();
        $q=$pdo->prepare('SELECT COUNT(*) FROM st_daily_updates WHERE technician_id=? AND update_date BETWEEN ? AND ?');$q->execute([$id,$startDate,$endDate]);$updates=(int)$q->fetchColumn();
        $q=$pdo->prepare("SELECT COUNT(*) FROM st_site_tasks WHERE assigned_to=? AND status='Completed' AND date(COALESCE(completed_at,created_at)) BETWEEN ? AND ?");$q->execute([$id,$startDate,$endDate]);$tasks=(int)$q->fetchColumn();
        $q=$pdo->prepare('SELECT COUNT(*) FROM st_site_photos WHERE technician_id=? AND date(created_at) BETWEEN ? AND ?');$q->execute([$id,$startDate,$endDate]);$photos=(int)$q->fetchColumn();
        $q=$pdo->prepare("SELECT COUNT(*) FROM st_tool_movements r JOIN st_tool_movements i ON i.tool_id=r.tool_id AND i.technician_id=r.technician_id AND i.action_type='Issued' AND i.id<r.id WHERE r.technician_id=? AND r.action_type='Returned' AND date(r.created_at) BETWEEN ? AND ? AND (i.due_date IS NULL OR i.due_date='' OR date(r.created_at)<=date(i.due_date))");$q->execute([$id,$startDate,$endDate]);$toolsOnTime=(int)$q->fetchColumn();
        $score=min(40,$onTimeSites*10)+min(25,$updates*2)+min(15,$tasks*3)+min(10,$photos)+min(10,$toolsOnTime*2);
        $results[]=['name'=>$technician['full_name'],'office'=>$technician['office']??'','score'=>$score,'closed'=>$closed,'on_time'=>$onTimeSites,'updates'=>$updates,'tasks'=>$tasks,'photos'=>$photos,'tools'=>$toolsOnTime];
    }
    usort($results,function($a,$b){return $b['score']<=>$a['score'];});
    return $results;
}
function st_is_technician(){ return st_has_site_permission() && in_array(st_user_role(),['technician','fundi'],true); }

function st_current_technician($pdo){
    $uid=(int)($_SESSION['user']['id']??0);
    if(!$uid) return null;
    $q=$pdo->prepare("SELECT * FROM st_technicians WHERE user_id=? LIMIT 1");
    $q->execute([$uid]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
function st_upload_photo($field){
    if(empty($_FILES[$field])) return null;
    if($_FILES[$field]['error']!==UPLOAD_ERR_OK) return null;
    $dir=__DIR__.'/uploads/site_tracking';
    if(!is_dir($dir)) @mkdir($dir,0777,true);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
    $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if(!isset($extensions[$mime])) return null;
    $ext=$extensions[$mime];
    $name=uniqid('st_',true).'.'.$ext;
    if(@move_uploaded_file($_FILES[$field]['tmp_name'],$dir.'/'.$name))
        return 'uploads/site_tracking/'.$name;
    return null;
}
function st_flash($msg){ $_SESSION['st_flash']=$msg; }
function st_redirect($page){ header('Location:index.php?page='.$page); exit; }
function st_site_team_ids($pdo,$siteId){
    $q=$pdo->prepare("SELECT technician_id FROM st_site_technicians WHERE site_id=? ORDER BY technician_id");
    $q->execute([$siteId]);
    return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
}
function st_save_site_team($pdo,$siteId,$primaryId,$others){
    $pdo->prepare("DELETE FROM st_site_technicians WHERE site_id=?")->execute([$siteId]);
    $ids=[];
    foreach(array_merge([(int)$primaryId],(array)$others) as $tid){
        $tid=(int)$tid;
        if($tid>0 && !in_array($tid,$ids,true)) $ids[]=$tid;
    }
    foreach($ids as $tid){
        $pdo->prepare("INSERT OR IGNORE INTO st_site_technicians(site_id,technician_id) VALUES(?,?)")
            ->execute([$siteId,$tid]);
    }
}

/* =========================================================
   SITE TRACKING ACTIONS
   ========================================================= */
if(isset($_SESSION['user']) && ($_SERVER['REQUEST_METHOD']??'')==='POST'
   && str_starts_with($_POST['st_action']??'','st_')){
    $a=$_POST['st_action'];
    $created=$_SESSION['user']['username']??$_SESSION['user']['full_name']??'User';

    try{
    if(!hash_equals(st_csrf_token(),(string)($_POST['st_csrf']??'')))throw new Exception('Session security token is invalid. Refresh the page and try again.');
        /* ---------- TECHNICIANS ---------- */
        if($a==='st_save_technician'){
            if(!st_is_admin()) throw new Exception('Only Administrator can save technicians.');
            $id=(int)($_POST['id']??0);
            $photo=st_upload_photo('photo') ?: st_upload_photo('technician_photo');
            $full=trim($_POST['full_name']??'');
            $username=trim($_POST['username']??'');
            $password=(string)($_POST['password']??'');
            $office=trim($_POST['office']??'');
            $gender=trim($_POST['gender']??'');
            $maritalStatus=trim($_POST['marital_status']??'');
            $childrenCount=max(0,(int)($_POST['children_count']??0));
            if($full==='') throw new Exception('Technician name is required.');
            if($office==='') throw new Exception('Office is required.');
            $technicianNo=trim($_POST['technician_no']??'');
            if($technicianNo==='') throw new Exception('Technician number is required.');
            $duplicate=$pdo->prepare('SELECT id FROM st_technicians WHERE technician_no=? AND id<>? LIMIT 1');
            $duplicate->execute([$technicianNo,$id]);
            if($duplicate->fetch()) throw new Exception('Technician number already exists.');

            if(!$id){
                if($username==='' || $password==='') throw new Exception('Username and password are required.');
                $q=$pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1"); $q->execute([$username]);
                if($q->fetch()) throw new Exception('Username already exists.');

                $pdo->beginTransaction();
                try{
                    $perms=json_encode(['site_tracking_system'=>true]);
                    $pdo->prepare("INSERT INTO users(username,password,name,phone,role,active,permissions,account_system)
                                   VALUES(?,?,?,?,?,1,?,?)")
                        ->execute([$username,hash('sha256',$password),$full,trim($_POST['phone']??''),'Technician',$perms,'site']);
                    $uid=(int)$pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO st_technicians
                        (technician_no,full_name,phone,email,address,office,gender,marital_status,children_count,emergency_name,emergency_phone,position,specialization,photo,status,user_id,username)
                        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([
                            $technicianNo,$full,trim($_POST['phone']??''),trim($_POST['email']??''),
                            trim($_POST['address']??''),$office,$gender,$maritalStatus,$childrenCount,trim($_POST['emergency_name']??''),trim($_POST['emergency_phone']??''),
                            trim($_POST['position']??''),trim($_POST['specialization']??''),$photo,
                            $_POST['status']??'Active',$uid,$username
                        ]);
                    $tid=(int)$pdo->lastInsertId();
                    $pdo->commit();
                    st_audit($pdo,'technician',$tid,'TECHNICIAN USER CREATED','Username: '.$username);
                }catch(Throwable $e){
                    if($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }else{
                $q=$pdo->prepare("SELECT * FROM st_technicians WHERE id=?");$q->execute([$id]);$old=$q->fetch(PDO::FETCH_ASSOC);
                if(!$old) throw new Exception('Technician not found.');
                if($username!==''){
                    $q=$pdo->prepare("SELECT id FROM users WHERE username=? AND id<>? LIMIT 1");
                    $q->execute([$username,(int)($old['user_id']??0)]);
                    if($q->fetch()) throw new Exception('Username already exists.');
                }else $username=$old['username']??'';

                $sql="UPDATE st_technicians SET technician_no=?,full_name=?,phone=?,email=?,address=?,office=?,gender=?,marital_status=?,children_count=?,emergency_name=?,emergency_phone=?,
                      position=?,specialization=?,status=?,username=?".($photo?",photo=?":"")." WHERE id=?";
                $v=[
                    $technicianNo,$full,trim($_POST['phone']??''),trim($_POST['email']??''),
                    trim($_POST['address']??''),$office,$gender,$maritalStatus,$childrenCount,trim($_POST['emergency_name']??''),trim($_POST['emergency_phone']??''),
                    trim($_POST['position']??''),trim($_POST['specialization']??''),$_POST['status']??'Active',$username
                ];
                if($photo)$v[]=$photo;
                $v[]=$id;
                $pdo->prepare($sql)->execute($v);

                if(!empty($old['user_id'])){
                    if($password!==''){
                        $pdo->prepare("UPDATE users SET username=?,name=?,phone=?,password=?,active=? WHERE id=?")
                            ->execute([$username,$full,trim($_POST['phone']??''),hash('sha256',$password),($_POST['status']??'Active')==='Active'?1:0,$old['user_id']]);
                    }else{
                        $pdo->prepare("UPDATE users SET username=?,name=?,phone=?,active=? WHERE id=?")
                            ->execute([$username,$full,trim($_POST['phone']??''),($_POST['status']??'Active')==='Active'?1:0,$old['user_id']]);
                    }
                }
                st_audit($pdo,'technician',$id,'TECHNICIAN UPDATED','Username: '.$username);
            }
            st_flash('Technician saved successfully.');st_redirect('st_technicians');
        }

        if($a==='st_delete_technician'){
            if(!st_is_admin()) throw new Exception('Only Administrator can delete technicians.');
            $id=(int)($_POST['id']??0);
            $q=$pdo->prepare("SELECT user_id FROM st_technicians WHERE id=?");$q->execute([$id]);$t=$q->fetch(PDO::FETCH_ASSOC);
            if(!$t) throw new Exception('Technician not found.');
            $pdo->beginTransaction();
            try{
                $pdo->prepare("DELETE FROM st_site_technicians WHERE technician_id=?")->execute([$id]);
                $pdo->prepare("UPDATE st_sites SET technician_id=NULL WHERE technician_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM st_technicians WHERE id=?")->execute([$id]);
                if(!empty($t['user_id'])) $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$t['user_id']]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            st_audit($pdo,'technician',$id,'DELETED');st_flash('Technician deleted.');st_redirect('st_technicians');
        }

        /* ---------- SITES / CUSTOMERS ---------- */
        if($a==='st_assign_site'){
            if(!st_is_manager()&&!st_is_admin()) throw new Exception('Only Manager or Administrator can assign sites.');
            $siteId=(int)($_POST['site_id']??0);
            $technicianId=(int)($_POST['technician_id']??0);
            if($siteId<=0||$technicianId<=0) throw new Exception('Site and technician are required.');

            $q=$pdo->prepare('SELECT site_name,technician_id FROM st_sites WHERE id=? LIMIT 1');
            $q->execute([$siteId]);
            $site=$q->fetch(PDO::FETCH_ASSOC);
            if(!$site) throw new Exception('Site not found.');
            $q=$pdo->prepare("SELECT id,full_name,status FROM st_technicians WHERE id=? LIMIT 1");
            $q->execute([$technicianId]);
            $technician=$q->fetch(PDO::FETCH_ASSOC);
            if(!$technician) throw new Exception('Technician not found.');
            if(($technician['status']??'')!=='Active') throw new Exception('Only an active technician can be assigned.');

            $oldTechnicianId=(int)($site['technician_id']??0);
            $otherTechnicians=array_values(array_filter(
                st_site_team_ids($pdo,$siteId),
                static fn($id)=>(int)$id!==$oldTechnicianId&&(int)$id!==$technicianId
            ));
            $pdo->beginTransaction();
            try{
                $pdo->prepare('UPDATE st_sites SET technician_id=? WHERE id=?')->execute([$technicianId,$siteId]);
                st_save_site_team($pdo,$siteId,$technicianId,$otherTechnicians);
                $pdo->commit();
            }catch(Throwable $e){
                if($pdo->inTransaction())$pdo->rollBack();
                throw $e;
            }
            $details='Site: '.$site['site_name'].' | Technician: '.$technician['full_name'];
            st_audit($pdo,'site',$siteId,$oldTechnicianId&&$oldTechnicianId!==$technicianId?'SITE REASSIGNED':'SITE ASSIGNED',$details);
            st_queue_whatsapp_notification($pdo,$technicianId,'site_assignment','Umepewa site '.$site['site_name'].'.');
            if($oldTechnicianId&&$oldTechnicianId!==$technicianId)
                st_queue_whatsapp_notification($pdo,$oldTechnicianId,'site_reassignment','Site '.$site['site_name'].' imehamishiwa fundi mwingine.');
            st_flash($oldTechnicianId&&$oldTechnicianId!==$technicianId?'Site reassigned successfully.':'Site assigned successfully.');
            st_redirect('st_sites');
        }

        if($a==='st_save_site' || $a==='st_edit_site' || $a==='st_edit_site_save'){
            if(!st_is_admin()) throw new Exception('Only Administrator can save/edit sites.');
            $id=(int)($_POST['id']??0);
            $site=trim($_POST['site_name']??'');
            $customer=trim($_POST['customer_name']??'');
            $location=trim($_POST['location']??'');
            $primary=(int)($_POST['technician_id']??0);
            $others=$_POST['team_technicians']??[];
            if($site==='' || $customer==='') throw new Exception('Site and customer are required.');

            if($id){
                $pdo->prepare("UPDATE st_sites SET site_name=?,customer_name=?,location=?,technician_id=?,start_date=?,due_date=?,status=?,notes=? WHERE id=?")
                    ->execute([$site,$customer,$location,$primary?:null,$_POST['start_date']??'',$_POST['due_date']??'',
                               $_POST['status']??'Pending',trim($_POST['notes']??''),$id]);
                st_save_site_team($pdo,$id,$primary,$others);
                st_audit($pdo,'site',$id,'EDITED');
                st_flash('Site updated successfully.');
            }else{
                $pdo->prepare("INSERT INTO st_sites(site_name,customer_name,location,technician_id,start_date,due_date,status,notes)
                               VALUES(?,?,?,?,?,?,?,?)")
                    ->execute([$site,$customer,$location,$primary?:null,$_POST['start_date']??'',$_POST['due_date']??'',
                               $_POST['status']??'Pending',trim($_POST['notes']??'')]);
                $id=(int)$pdo->lastInsertId();
                st_save_site_team($pdo,$id,$primary,$others);
                st_audit($pdo,'site',$id,'CREATED');
                st_flash('Site saved successfully.');
            }
            st_redirect('st_sites');
        }

        if($a==='st_delete_site'){
            if(!st_is_admin()) throw new Exception('Only Administrator can delete sites.');
            $id=(int)($_POST['id']??0);
            $q=$pdo->prepare("SELECT COUNT(*) FROM st_tool_movements WHERE site_id=?");$q->execute([$id]);
            $toolCount=(int)$q->fetchColumn();
            $q=$pdo->prepare("SELECT COUNT(*) FROM st_material_movements WHERE site_id=?");$q->execute([$id]);
            $matCount=(int)$q->fetchColumn();
            if($toolCount>0 || $matCount>0) throw new Exception('Site cannot be deleted because it has movement history.');
            $pdo->prepare("DELETE FROM st_site_technicians WHERE site_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM st_sites WHERE id=?")->execute([$id]);
            st_audit($pdo,'site',$id,'DELETED');st_flash('Site deleted.');st_redirect('st_sites');
        }

        if($a==='st_save_site_task'){
            if(!st_is_admin())throw new Exception('Only Administrator can create site tasks.');
            $siteId=(int)($_POST['site_id']??0);$title=trim($_POST['task_title']??'');$notes=trim($_POST['notes']??'');$assigned=(int)($_POST['assigned_to']??0);
            if($siteId<=0||$title==='')throw new Exception('Site and task title are required.');
            $q=$pdo->prepare('SELECT id FROM st_sites WHERE id=? LIMIT 1');$q->execute([$siteId]);if(!$q->fetch())throw new Exception('Site not found.');
            $pdo->prepare('INSERT INTO st_site_tasks(site_id,task_title,notes,assigned_to) VALUES(?,?,?,?)')->execute([$siteId,$title,$notes,$assigned?:null]);
            $taskId=(int)$pdo->lastInsertId();st_audit($pdo,'site_task',$taskId,'CREATED','Site: '.$siteId);st_flash('Site task created.');st_redirect('st_sites');
        }

        if($a==='st_toggle_site_task'){
            $taskId=(int)($_POST['id']??0);$q=$pdo->prepare('SELECT * FROM st_site_tasks WHERE id=?');$q->execute([$taskId]);$task=$q->fetch(PDO::FETCH_ASSOC);if(!$task)throw new Exception('Task not found.');
            if(st_is_technician()){$ct=st_current_technician($pdo);$q=$pdo->prepare('SELECT 1 FROM st_site_technicians WHERE site_id=? AND technician_id=?');$q->execute([(int)$task['site_id'],(int)($ct['id']??0)]);if(!$q->fetch())throw new Exception('Task is not assigned to you.');}
            if(!st_is_technician()&&!st_is_admin())throw new Exception('No permission to update this task.');
            $next=$task['status']==='Completed'?'Pending':'Completed';$user=st_current_user_name();
            $pdo->prepare('UPDATE st_site_tasks SET status=?,completed_by=?,completed_at=CASE WHEN ?=\'Completed\' THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id=?')->execute([$next,$next==='Completed'?$user:null,$next,$taskId]);
            st_audit($pdo,'site_task',$taskId,strtoupper($next));st_flash('Task status updated.');st_redirect('st_sites');
        }

        if($a==='st_delete_site_task'){
            if(!st_is_admin())throw new Exception('Only Administrator can delete site tasks.');
            $taskId=(int)($_POST['id']??0);$pdo->prepare('DELETE FROM st_site_tasks WHERE id=?')->execute([$taskId]);st_audit($pdo,'site_task',$taskId,'DELETED');st_flash('Site task deleted.');st_redirect('st_sites');
        }

        /* ---------- TOOLS ---------- */
        if($a==='st_save_tool' || $a==='st_edit_tool' || $a==='st_edit_tool_save'){
            if(!st_is_admin()) throw new Exception('Only Administrator can save/edit tools.');
            $id=(int)($_POST['id']??0);
            $photo=st_upload_photo('tool_photo');
            $data=[
                trim($_POST['tool_code']??''),trim($_POST['tool_name']??''),trim($_POST['serial_no']??''),
                max(1,(int)($_POST['quantity']??1)),$_POST['condition_status']??'Good',
                trim($_POST['storage_location']??''),trim($_POST['notes']??'')
            ];
            if($data[0]==='' || $data[1]==='') throw new Exception('Tool code and name are required.');
            if($id){
                $sql="UPDATE st_tools SET tool_code=?,tool_name=?,serial_no=?,quantity=?,condition_status=?,storage_location=?,notes=?".($photo?",photo=?":"")." WHERE id=?";
                $v=$data;if($photo)$v[]=$photo;$v[]=$id;
                $pdo->prepare($sql)->execute($v);
                st_audit($pdo,'tool',$id,'EDITED');st_flash('Tool updated.');
            }else{
                $pdo->prepare("INSERT INTO st_tools(tool_code,tool_name,serial_no,quantity,condition_status,status,notes,photo,purchase_date,value_amount,storage_location,registration_notes)
                               VALUES(?,?,?,?,?,'Available',?,?,?,?,?,?)")
                    ->execute([
                        $data[0],$data[1],$data[2],$data[3],$data[4],$data[6],$photo,
                        $_POST['purchase_date']??'',(float)($_POST['value_amount']??0),$data[5],trim($_POST['registration_notes']??'')
                    ]);
                $id=(int)$pdo->lastInsertId();st_audit($pdo,'tool',$id,'REGISTERED','Tool registered with photo evidence.');st_flash('Tool saved.');
            }
            st_redirect('st_tools');
        }

        if($a==='st_delete_tool'){
            if(!st_is_admin()) throw new Exception('Only Administrator can delete tools.');
            $id=(int)($_POST['id']??0);
            $q=$pdo->prepare("SELECT COUNT(*) FROM st_tool_movements WHERE tool_id=?");$q->execute([$id]);
            $pdo->prepare("DELETE FROM st_tool_movements WHERE tool_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM st_tools WHERE id=?")->execute([$id]);
            st_audit($pdo,'tool',$id,'DELETED');st_flash('Tool deleted.');st_redirect('st_tools');
        }

        /* ---------- MATERIALS ---------- */
        if($a==='st_save_material'){
            if(!st_is_admin()) throw new Exception('Only Administrator can save materials.');
            $pdo->prepare("INSERT INTO st_materials(material_name,unit,stock,reorder_level,notes) VALUES(?,?,?,?,?)")
                ->execute([trim($_POST['material_name']??''),trim($_POST['unit']??'pcs'),
                           (float)($_POST['stock']??0),(float)($_POST['reorder_level']??0),trim($_POST['notes']??'')]);
            $id=(int)$pdo->lastInsertId();st_audit($pdo,'material',$id,'CREATED');st_flash('Material saved.');st_redirect('st_materials');
        }

        if($a==='st_edit_material' || $a==='st_edit_material_save'){
            if(!st_is_admin()) throw new Exception('Only Administrator can edit materials.');
            $id=(int)($_POST['id']??0);
            $pdo->prepare("UPDATE st_materials SET material_name=?,unit=?,stock=?,reorder_level=?,notes=? WHERE id=?")
                ->execute([trim($_POST['material_name']??''),trim($_POST['unit']??'pcs'),
                           (float)($_POST['stock']??0),(float)($_POST['reorder_level']??0),trim($_POST['notes']??''),$id]);
            st_audit($pdo,'material',$id,'EDITED');st_flash('Material updated.');st_redirect('st_materials');
        }

        if($a==='st_delete_material'){
            if(!st_is_admin()) throw new Exception('Only Administrator can delete materials.');
            $id=(int)($_POST['id']??0);
            $q=$pdo->prepare("SELECT COUNT(*) FROM st_material_movements WHERE material_id=?");$q->execute([$id]);
            $pdo->prepare("DELETE FROM st_material_movements WHERE material_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM st_materials WHERE id=?")->execute([$id]);
            st_audit($pdo,'material',$id,'DELETED');st_flash('Material deleted.');st_redirect('st_materials');
        }

        /* ---------- TOOL MOVEMENTS + GPS ---------- */
        if($a==='st_issue_tool' || $a==='st_return_tool'){
            if($a==='st_issue_tool' && !st_is_manager() && !st_is_admin())throw new Exception('Only Manager or Administrator can issue tools.');
            if($a==='st_return_tool' && !st_is_technician() && !st_is_manager() && !st_is_admin())throw new Exception('Only Technician, Manager or Administrator can return tools.');
            $photo=st_upload_photo('photo'); $type=$a==='st_issue_tool'?'Issued':'Returned';
            $tid=(int)($_POST['technician_id']??0); if(st_is_technician()){ $ct=st_current_technician($pdo);if(!$ct)throw new Exception('Technician account is not linked.');$tid=(int)$ct['id']; }
            if($tid<=0)throw new Exception('Technician is required.'); if(!$photo)throw new Exception($type==='Issued'?'Photo of issued tool is required.':'Return photo is required.');
            $toolId=(int)($_POST['tool_id']??0);$siteId=(int)($_POST['site_id']??0);$lat=($_POST['latitude']??'')!==''?(float)$_POST['latitude']:null;$lng=($_POST['longitude']??'')!==''?(float)$_POST['longitude']:null;$acc=($_POST['accuracy']??'')!==''?(float)$_POST['accuracy']:null;
            if($lat===null||$lng===null)throw new Exception('GPS location is required for tool photo records.');
            if($siteId){$q=$pdo->prepare("SELECT 1 FROM st_site_technicians WHERE site_id=? AND technician_id=? LIMIT 1");$q->execute([$siteId,$tid]);if(!$q->fetch())throw new Exception('Technician is not assigned to the selected site.');}
            if($type==='Issued'){$q=$pdo->prepare("SELECT status FROM st_tools WHERE id=? LIMIT 1");$q->execute([$toolId]);$ts=$q->fetchColumn();if(!$ts)throw new Exception('Tool not found.');if(strtolower((string)$ts)!=='available')throw new Exception('Tool is not available. Current status: '.$ts);}else{$q=$pdo->prepare("SELECT 1 FROM st_tool_movements WHERE tool_id=? AND technician_id=? AND action_type='Issued' AND COALESCE(status,'') NOT IN ('Rejected','Returned') ORDER BY id DESC LIMIT 1");$q->execute([$toolId,$tid]);if(!$q->fetch())throw new Exception('Tool is not currently issued to this technician.');}
            $pdo->prepare("INSERT INTO st_tool_movements(tool_id,technician_id,site_id,customer_name,action_type,due_date,condition_status,photo,return_photo,notes,status,created_by,latitude,longitude,accuracy,captured_at) VALUES(?,?,?,?,?,?,?,?,?,'Submitted',?,?,?,?,?,?)")
                ->execute([$toolId,$tid,$siteId?:null,trim($_POST['customer_name']??''),$type,$_POST['due_date']??null,$_POST['condition_status']??'Good',$photo,($type==='Returned'?$photo:null),trim($_POST['notes']??''),$created,$lat,$lng,$acc,trim($_POST['captured_at']??'')?:null]);
            if($type==='Issued')$pdo->prepare("UPDATE st_tools SET status='Issued' WHERE id=?")->execute([$toolId]);if($type==='Returned')$pdo->prepare("UPDATE st_tools SET status=? WHERE id=?")->execute([($_POST['condition_status']??'Good')==='Missing'?'Missing':'Available',$toolId]);
            st_flash($type.' record submitted with GPS.');st_redirect(st_is_technician()?'st_portal':'st_movements');
        }

        /* ---------- MATERIAL ISSUE BY MANAGER / ADMIN ---------- */
        if($a==='st_issue_material'){
            if(!st_is_manager()&&!st_is_admin())throw new Exception('Only Manager or Administrator can issue material.');
            $mid=(int)($_POST['material_id']??0);$sid=(int)($_POST['site_id']??0);$tid=(int)($_POST['technician_id']??0);$qty=(float)($_POST['quantity']??0);
            if($qty<=0||$mid<=0||$tid<=0||$sid<=0)throw new Exception('Material, quantity, technician and site are required.');
            $q=$pdo->prepare("SELECT stock FROM st_materials WHERE id=? LIMIT 1");$q->execute([$mid]);$m=$q->fetch(PDO::FETCH_ASSOC);if(!$m)throw new Exception('Material not found.');if($qty>(float)$m['stock'])throw new Exception('Insufficient material stock.');
            $q=$pdo->prepare("SELECT status FROM st_sites WHERE id=? LIMIT 1");$q->execute([$sid]);$site=$q->fetch(PDO::FETCH_ASSOC);if(!$site)throw new Exception('Site not found.');if(strtolower((string)$site['status'])==='closed')throw new Exception('Closed site cannot receive material.');
            $q=$pdo->prepare("SELECT 1 FROM st_site_technicians WHERE site_id=? AND technician_id=? LIMIT 1");$q->execute([$sid,$tid]);if(!$q->fetch())throw new Exception('Selected technician is not assigned to this site.');
            $photo=st_upload_photo('photo');$lat=($_POST['latitude']??'')!==''?(float)$_POST['latitude']:null;$lng=($_POST['longitude']??'')!==''?(float)$_POST['longitude']:null;$acc=($_POST['accuracy']??'')!==''?(float)$_POST['accuracy']:null;
            $pdo->beginTransaction();try{$pdo->prepare("UPDATE st_materials SET stock=stock-? WHERE id=? AND stock>=?")->execute([$qty,$mid,$qty]);if($pdo->query('SELECT changes()')->fetchColumn()!=1)throw new Exception('Stock update failed safely.');$pdo->prepare("INSERT INTO st_material_movements(material_id,technician_id,site_id,customer_name,quantity,movement_type,photo,reason,technician_confirmed,manager_approved,technical_approved,status,created_by,manager_by,manager_at,manager_comment,latitude,longitude,accuracy,captured_at) VALUES(?,?,?,?,?,'Issued',?,?,0,1,1,'Approved',?,?,CURRENT_TIMESTAMP,?,?,?,?,?)")->execute([$mid,$tid,$sid,trim($_POST['customer_name']??''),$qty,$photo,trim($_POST['reason']??'Material issued by Manager'),$created,$created,trim($_POST['manager_comment']??''),$lat,$lng,$acc,trim($_POST['captured_at']??'')?:null]);$movementId=(int)$pdo->lastInsertId();$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            st_audit($pdo,'material_movement',$movementId,'MATERIAL ISSUED','Qty: '.$qty.' | Technician: '.$tid.' | Site: '.$sid);st_flash('Material issued to technician successfully.');st_redirect('st_movements');
        }

        if($a==='st_grant_material_fund'){
            if(!st_is_manager()&&!st_is_admin())throw new Exception('Only Manager or Administrator can grant material funds.');
            $sid=(int)($_POST['site_id']??0);$tid=(int)($_POST['technician_id']??0);$amount=(float)($_POST['amount']??0);
            $currency=trim($_POST['currency']??'TZS')?:'TZS';$purpose=trim($_POST['purpose']??'');
            if($sid<=0||$tid<=0||$amount<=0)throw new Exception('Site, technician and amount are required.');
            $q=$pdo->prepare('SELECT site_name FROM st_sites WHERE id=? LIMIT 1');$q->execute([$sid]);$site=$q->fetch(PDO::FETCH_ASSOC);if(!$site)throw new Exception('Site not found.');
            $q=$pdo->prepare('SELECT full_name FROM st_technicians WHERE id=? AND status=\'Active\' LIMIT 1');$q->execute([$tid]);$technician=$q->fetch(PDO::FETCH_ASSOC);if(!$technician)throw new Exception('Active technician not found.');
            $q=$pdo->prepare('SELECT 1 FROM st_site_technicians WHERE site_id=? AND technician_id=? LIMIT 1');$q->execute([$sid,$tid]);if(!$q->fetch())throw new Exception('Technician is not assigned to the selected site.');
            $pdo->prepare('INSERT INTO st_material_funds(technician_id,site_id,amount,currency,purpose,status,granted_by) VALUES(?,?,?,?,?,?,?)')
                ->execute([$tid,$sid,$amount,$currency,$purpose,'Granted',st_current_user_name()]);
            $fundId=(int)$pdo->lastInsertId();
            st_audit($pdo,'material_fund',$fundId,'MATERIAL FUND GRANTED','Amount: '.$amount.' '.$currency.' | Technician: '.$technician['full_name'].' | Site: '.$site['site_name']);
            st_queue_whatsapp_notification($pdo,$tid,'material_fund_granted','Umepewa '.$amount.' '.$currency.' kwa manunuzi ya material ya site '.$site['site_name'].'.');
            st_flash('Material purchase funds granted successfully.');st_redirect('st_movements');
        }

        if($a==='st_submit_material_receipt'){
            if(!st_is_technician())throw new Exception('Only technicians can submit material receipts.');
            $ct=st_current_technician($pdo);if(!$ct)throw new Exception('Technician account is not linked.');
            $fundId=(int)($_POST['fund_id']??0);$notes=trim($_POST['receipt_notes']??'');$photo=st_upload_photo('receipt_photo');
            if($fundId<=0||!$photo)throw new Exception('Receipt photo is required. Use the phone camera to capture it.');
            $lat=($_POST['latitude']??'')!==''?(float)$_POST['latitude']:null;$lng=($_POST['longitude']??'')!==''?(float)$_POST['longitude']:null;$acc=($_POST['accuracy']??'')!==''?(float)$_POST['accuracy']:null;
            if($lat===null||$lng===null)throw new Exception('GPS location is required for the receipt.');
            $q=$pdo->prepare("SELECT id,site_id,amount,currency FROM st_material_funds WHERE id=? AND technician_id=? AND status='Granted' LIMIT 1");$q->execute([$fundId,(int)$ct['id']]);$fund=$q->fetch(PDO::FETCH_ASSOC);if(!$fund)throw new Exception('Material fund not found or already submitted.');
            $pdo->prepare("UPDATE st_material_funds SET status='Receipt Submitted',receipt_photo=?,receipt_notes=?,receipt_submitted_at=CURRENT_TIMESTAMP,receipt_latitude=?,receipt_longitude=?,receipt_accuracy=? WHERE id=? AND technician_id=? AND status='Granted'")
                ->execute([$photo,$notes,$lat,$lng,$acc,$fundId,(int)$ct['id']]);
            st_audit($pdo,'material_fund',$fundId,'MATERIAL RECEIPT SUBMITTED','Amount: '.$fund['amount'].' '.$fund['currency'].' | GPS: '.$lat.','.$lng);
            st_flash('Material purchase receipt submitted successfully.');st_redirect('st_portal');
        }

        if($a==='st_request_tool' || $a==='st_request_material'){
            if(!st_is_technician())throw new Exception('Only technicians can submit requests.');
            $ct=st_current_technician($pdo);if(!$ct)throw new Exception('Technician account is not linked.');
            $siteId=(int)($_POST['site_id']??0);$itemId=(int)($_POST['item_id']??0);$quantity=max(1,(float)($_POST['quantity']??1));$reason=trim($_POST['reason']??'');
            $q=$pdo->prepare('SELECT 1 FROM st_site_technicians WHERE site_id=? AND technician_id=? LIMIT 1');$q->execute([$siteId,(int)$ct['id']]);if(!$q->fetch())throw new Exception('You are not assigned to this site.');
            if($siteId<=0||$itemId<=0)throw new Exception('Site and requested item are required.');
            $table=$a==='st_request_tool'?'st_tool_requests':'st_material_requests';$itemColumn=$a==='st_request_tool'?'tool_id':'material_id';
            $pdo->prepare("INSERT INTO $table(technician_id,site_id,$itemColumn,quantity,reason) VALUES(?,?,?,?,?)")->execute([(int)$ct['id'],$siteId,$itemId,$quantity,$reason]);
            $requestId=(int)$pdo->lastInsertId();st_audit($pdo,$a==='st_request_tool'?'tool_request':'material_request',$requestId,'SUBMITTED','Site: '.$siteId);st_flash('Request submitted to Manager.');st_redirect('st_portal');
        }

        if($a==='st_review_tool_request' || $a==='st_review_material_request'){
            if(!st_is_manager()&&!st_is_admin())throw new Exception('Only Manager or Administrator can review requests.');
            $id=(int)($_POST['id']??0);$approve=($_POST['decision']??'')==='approve';$table=$a==='st_review_tool_request'?'st_tool_requests':'st_material_requests';
            $q=$pdo->prepare("SELECT * FROM $table WHERE id=? AND status='Pending'");$q->execute([$id]);$request=$q->fetch(PDO::FETCH_ASSOC);if(!$request)throw new Exception('Request not found or already reviewed.');
            $status=$approve?'Approved':'Rejected';$reviewer=st_current_user_name();
            if($approve&&$a==='st_review_tool_request'){
                $q=$pdo->prepare('SELECT status FROM st_tools WHERE id=?');$q->execute([(int)$request['tool_id']]);if(strtolower((string)$q->fetchColumn())!=='available')throw new Exception('Requested tool is not available.');
                $pdo->prepare("INSERT INTO st_tool_movements(tool_id,technician_id,site_id,action_type,status,created_by,notes) VALUES(?,?,?,'Issued','Approved',?,?)")->execute([(int)$request['tool_id'],(int)$request['technician_id'],(int)$request['site_id'],$reviewer,'Approved from request #'.$id]);
                $pdo->prepare("UPDATE st_tools SET status='Issued' WHERE id=?")->execute([(int)$request['tool_id']]);
            }
            if($approve&&$a==='st_review_material_request'){
                $q=$pdo->prepare('UPDATE st_materials SET stock=stock-? WHERE id=? AND stock>=?');$q->execute([(float)$request['quantity'],(int)$request['material_id'],(float)$request['quantity']]);if($q->rowCount()!==1)throw new Exception('Insufficient material stock.');
                $pdo->prepare("INSERT INTO st_material_movements(material_id,technician_id,site_id,quantity,movement_type,reason,status,created_by) VALUES(?,?,?,?,'Issued',?,'Approved',?)")->execute([(int)$request['material_id'],(int)$request['technician_id'],(int)$request['site_id'],(float)$request['quantity'],'Approved from request #'.$id,$reviewer]);
            }
            $pdo->prepare("UPDATE $table SET status=?,reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$status,$reviewer,$id]);st_audit($pdo,$a==='st_review_tool_request'?'tool_request':'material_request', $id,strtoupper($status));st_flash('Request '.$status.'.');st_redirect('st_approvals');
        }

        /* ---------- MATERIAL USAGE BY TECHNICIAN ---------- */
        if($a==='st_report_material_usage'){
            if(!st_is_technician())throw new Exception('Only technician can report material usage.');$ct=st_current_technician($pdo);if(!$ct)throw new Exception('Technician account is not linked.');
            $mid=(int)($_POST['material_id']??0);$sid=(int)($_POST['site_id']??0);$qty=(float)($_POST['quantity']??0);if($mid<=0||$sid<=0||$qty<=0)throw new Exception('Material, site and used quantity are required.');
            $q=$pdo->prepare("SELECT 1 FROM st_site_technicians WHERE site_id=? AND technician_id=? LIMIT 1");$q->execute([$sid,(int)$ct['id']]);if(!$q->fetch())throw new Exception('You are not assigned to this site.');
            $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type='Issued' AND status='Approved' THEN quantity ELSE 0 END),0) issued,COALESCE(SUM(CASE WHEN movement_type='Used' AND status IN ('Submitted','Approved') THEN quantity ELSE 0 END),0) used FROM st_material_movements WHERE material_id=? AND technician_id=? AND site_id=?");$q->execute([$mid,(int)$ct['id'],$sid]);$bal=$q->fetch(PDO::FETCH_ASSOC);$remaining=(float)$bal['issued']-(float)$bal['used'];if($qty>$remaining+0.00001)throw new Exception('Used quantity exceeds material issued for this site. Remaining: '.$remaining);
            $photo=st_upload_photo('photo');if(!$photo)throw new Exception('Usage photo is required.');$lat=($_POST['latitude']??'')!==''?(float)$_POST['latitude']:null;$lng=($_POST['longitude']??'')!==''?(float)$_POST['longitude']:null;$acc=($_POST['accuracy']??'')!==''?(float)$_POST['accuracy']:null;if($lat===null||$lng===null)throw new Exception('GPS location is required for material usage.');
            $pdo->prepare("INSERT INTO st_material_movements(material_id,technician_id,site_id,customer_name,quantity,movement_type,photo,reason,status,created_by,latitude,longitude,accuracy,captured_at) VALUES(?,?,?,?,?,'Used',?,?, 'Submitted',?,?,?,?,?)")->execute([$mid,(int)$ct['id'],$sid,trim($_POST['customer_name']??''),$qty,$photo,trim($_POST['reason']??'Material used on site'),$created,$lat,$lng,$acc,trim($_POST['captured_at']??'')?:null]);$movementId=(int)$pdo->lastInsertId();st_audit($pdo,'material_movement',$movementId,'MATERIAL USAGE REPORTED','Qty: '.$qty.' | GPS: '.$lat.','.$lng);st_flash('Material usage report submitted with GPS and photo.');st_redirect('st_portal');
        }

        if($a==='st_save_daily_update'){
            if(!st_is_technician())throw new Exception('Only technicians can submit daily updates.');
            $ct=st_current_technician($pdo);if(!$ct)throw new Exception('Technician account is not linked.');
            $siteId=(int)($_POST['site_id']??0);$date=trim($_POST['update_date']??date('Y-m-d'));$work=trim($_POST['work_done']??'');$blockers=trim($_POST['blockers']??'');$next=trim($_POST['next_steps']??'');
            if($siteId<=0||$work==='')throw new Exception('Site and work completed are required.');
            $q=$pdo->prepare('SELECT 1 FROM st_site_technicians WHERE site_id=? AND technician_id=? LIMIT 1');$q->execute([$siteId,(int)$ct['id']]);if(!$q->fetch())throw new Exception('You are not assigned to this site.');
            $photo=st_upload_photo('photo');if(!$photo)throw new Exception('Daily update photo is required. Use the phone camera.');$lat=($_POST['latitude']??'')!==''?(float)$_POST['latitude']:null;$lng=($_POST['longitude']??'')!==''?(float)$_POST['longitude']:null;$acc=($_POST['accuracy']??'')!==''?(float)$_POST['accuracy']:null;
            $pdo->prepare("INSERT INTO st_daily_updates(site_id,technician_id,update_date,work_done,blockers,next_steps,photo,latitude,longitude,accuracy) VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(site_id,technician_id,update_date) DO UPDATE SET work_done=excluded.work_done,blockers=excluded.blockers,next_steps=excluded.next_steps,photo=COALESCE(excluded.photo,st_daily_updates.photo),latitude=excluded.latitude,longitude=excluded.longitude,accuracy=excluded.accuracy")->execute([$siteId,(int)$ct['id'],$date,$work,$blockers,$next,$photo,$lat,$lng,$acc]);
            st_audit($pdo,'daily_update',$siteId,'DAILY UPDATE SUBMITTED','Date: '.$date);st_flash('Daily update saved.');st_redirect('st_portal');
        }

        /* ---------- SITE PHOTO / GPS EVIDENCE ---------- */
        if($a==='st_add_site_photo'){
            if(!st_is_technician())throw new Exception('Only technician can submit site photos.');$ct=st_current_technician($pdo);if(!$ct)throw new Exception('Technician account is not linked.');$sid=(int)($_POST['site_id']??0);$category=trim($_POST['category']??'Work Progress');$caption=trim($_POST['caption']??'');$lat=($_POST['latitude']??'')!==''?(float)$_POST['latitude']:null;$lng=($_POST['longitude']??'')!==''?(float)$_POST['longitude']:null;$acc=($_POST['accuracy']??'')!==''?(float)$_POST['accuracy']:null;if($sid<=0||$lat===null||$lng===null)throw new Exception('Site and GPS location are required.');$q=$pdo->prepare("SELECT s.id FROM st_sites s JOIN st_site_technicians st ON st.site_id=s.id WHERE s.id=? AND st.technician_id=? LIMIT 1");$q->execute([$sid,(int)$ct['id']]);if(!$q->fetch())throw new Exception('You are not assigned to this site.');$photo=st_upload_photo('photo');if(!$photo)throw new Exception('Site photo is required.');$pdo->prepare("INSERT INTO st_site_photos(site_id,technician_id,photo,category,caption,latitude,longitude,accuracy,captured_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)")->execute([$sid,(int)$ct['id'],$photo,$category,$caption,$lat,$lng,$acc,trim($_POST['captured_at']??'')?:null,$created]);$pid=(int)$pdo->lastInsertId();st_audit($pdo,'site_photo',$pid,'SITE PHOTO SUBMITTED','Site: '.$sid.' | '.$category.' | GPS: '.$lat.','.$lng);st_flash('Site photo saved with GPS location.');st_redirect('st_portal');
        }

        /* ---------- APPROVALS ---------- */
        if($a==='st_approve_tool' || $a==='st_approve_material'){
            $id=(int)$_POST['id'];$stage=$_POST['stage']??'';
            if($stage==='manager' && !st_is_manager())throw new Exception('Only Manager can approve this stage.');
            if($stage==='technical' && !st_is_technical())throw new Exception('Only Technical Manager can approve this stage.');
            if(!in_array($stage,['manager','technical'],true))throw new Exception('Invalid approval stage.');

            $table=$a==='st_approve_tool'?'st_tool_movements':'st_material_movements';
            $u=st_current_user_name();
            if($stage==='manager'){
                $pdo->prepare("UPDATE $table SET manager_approved=1,manager_by=?,manager_at=CURRENT_TIMESTAMP,manager_comment=? WHERE id=?")
                    ->execute([$u,trim($_POST['comment']??''),$id]);
            }else{
                $pdo->prepare("UPDATE $table SET technical_approved=1,technical_by=?,technical_at=CURRENT_TIMESTAMP,technical_comment=? WHERE id=?")
                    ->execute([$u,trim($_POST['comment']??''),$id]);
            }

            $q=$pdo->prepare("SELECT * FROM $table WHERE id=?");$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new Exception('Record not found.');

            if((int)$row['manager_approved']===1 && (int)$row['technical_approved']===1){
                if($a==='st_approve_material'){
                    /* Re-check stock at final approval, then deduct exactly once. */
                    $q=$pdo->prepare("SELECT stock FROM st_materials WHERE id=?");$q->execute([(int)$row['material_id']]);$m=$q->fetch(PDO::FETCH_ASSOC);
                    if(!$m || (float)$row['quantity']>(float)$m['stock']){
                        $pdo->prepare("UPDATE st_material_movements SET status='Rejected' WHERE id=?")->execute([$id]);
                        throw new Exception('Stock is no longer sufficient. Material request rejected.');
                    }
                    $pdo->prepare("UPDATE st_materials SET stock=stock-? WHERE id=?")
                        ->execute([(float)$row['quantity'],(int)$row['material_id']]);
                }
                $pdo->prepare("UPDATE $table SET status='Approved' WHERE id=?")->execute([$id]);
            }else{
                $pdo->prepare("UPDATE $table SET status='Pending Approval' WHERE id=?")->execute([$id]);
            }

            st_audit($pdo,$a==='st_approve_material'?'material_movement':'tool_movement',$id,strtoupper($stage).' APPROVED',trim($_POST['comment']??''));
            st_flash('Approval saved.');st_redirect('st_approvals');
        }

        if($a==='st_return_for_correction' || $a==='st_reject_record'){
            $id=(int)$_POST['id'];$kind=$_POST['kind']??'tool';
            $table=$kind==='material'?'st_material_movements':'st_tool_movements';
            if(!st_is_manager() && !st_is_technical() && !st_is_admin())throw new Exception('No permission.');
            $status=$a==='st_return_for_correction'?'Returned for Correction':'Rejected';
            $pdo->prepare("UPDATE $table SET status=? WHERE id=?")->execute([$status,$id]);
            st_audit($pdo,$kind.'_movement',$id,strtoupper($status),trim($_POST['comment']??''));
            st_flash('Record updated.');st_redirect('st_approvals');
        }

        /* ---------- SITE ADMIN ---------- */
        if($a==='st_save_site_admin' || $a==='st_edit_site_admin'){
            if(!st_is_admin())throw new Exception('Only Administrator can manage site administrators.');
            $id=(int)($_POST['id']??0); $type=$_POST['admin_type']??'Manager';
            $office=trim($_POST['office']??'');
            $adminPermissions=[];foreach(['dashboard','technicians','sites','tools','materials','photos','movements','approvals','reports','daily_reports','activity','administration'] as $permission){if(isset($_POST['perm_'.$permission]))$adminPermissions[$permission]=1;}
            $whatsappTemplate=trim($_POST['whatsapp_template']??'');
            $passportPhoto=st_upload_photo('passport_photo');
            $username=trim($_POST['username']??''); $full=trim($_POST['full_name']??''); $password=(string)($_POST['password']??'');
            if($username===''||$full==='') throw new Exception('Username and full name are required.');
            if(!in_array($type,['Manager','Technical Manager'],true))throw new Exception('Invalid administrator type.');
            if($office==='')throw new Exception('Office is required.');
            if(!$id){
                if($password==='') throw new Exception('Password is required.');
                if(!$passportPhoto)throw new Exception('Passport-size photo is required.');
                $q=$pdo->prepare('SELECT id FROM users WHERE username=? LIMIT 1');$q->execute([$username]);if($q->fetch())throw new Exception('Username already exists.');
                $pdo->prepare("INSERT INTO users(username,password,name,phone,role,active,permissions,account_system) VALUES(?,?,?,?,?,1,?,?)")
                    ->execute([$username,hash('sha256',$password),$full,trim($_POST['phone']??''),$type,json_encode(['site_tracking_system'=>true]),'site']);
                $uid=(int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO st_site_admins(user_id,username,full_name,admin_type,office,passport_photo,phone,email,permissions,whatsapp_template,active) VALUES(?,?,?,?,?,?,?,?,?,?,1)")
                    ->execute([$uid,$username,$full,$type,$office,$passportPhoto,trim($_POST['phone']??''),trim($_POST['email']??''),json_encode($adminPermissions),$whatsappTemplate]);
            }else{
                $q=$pdo->prepare('SELECT user_id FROM st_site_admins WHERE id=?');$q->execute([$id]);$old=$q->fetch(PDO::FETCH_ASSOC);if(!$old)throw new Exception('Administrator not found.');
                $q=$pdo->prepare('SELECT id FROM users WHERE username=? AND id<>? LIMIT 1');$q->execute([$username,(int)$old['user_id']]);if($q->fetch())throw new Exception('Username already exists.');
                if($password!=='') $pdo->prepare("UPDATE users SET username=?,name=?,phone=?,password=?,role=? WHERE id=?")->execute([$username,$full,trim($_POST['phone']??''),hash('sha256',$password),$type,$old['user_id']]);
                else $pdo->prepare("UPDATE users SET username=?,name=?,phone=?,role=? WHERE id=?")->execute([$username,$full,trim($_POST['phone']??''),$type,$old['user_id']]);
                $adminSql="UPDATE st_site_admins SET username=?,full_name=?,admin_type=?,office=?,phone=?,email=?,permissions=?,whatsapp_template=?";
                $adminValues=[$username,$full,$type,$office,trim($_POST['phone']??''),trim($_POST['email']??''),json_encode($adminPermissions),$whatsappTemplate];
                if($passportPhoto){$adminSql.=',passport_photo=?';$adminValues[]=$passportPhoto;}
                $adminSql.=' WHERE id=?';$adminValues[]=$id;
                $pdo->prepare($adminSql)->execute($adminValues);
            }
            st_flash($id?'Administrator updated.':'Administrator created.');st_redirect('st_administration');
        }

        if($a==='st_delete_site_admin'){
            if(!st_is_admin())throw new Exception('Only Administrator can delete site administrators.');
            $id=(int)($_POST['id']??0);
            $q=$pdo->prepare('SELECT user_id,passport_photo FROM st_site_admins WHERE id=?');$q->execute([$id]);$admin=$q->fetch(PDO::FETCH_ASSOC);
            if(!$admin)throw new Exception('Administrator not found.');
            if((int)$admin['user_id']===(int)($_SESSION['user']['id']??0))throw new Exception('Huwezi kujifuta mwenyewe.');
            $pdo->beginTransaction();
            try{
                $pdo->prepare('DELETE FROM st_site_admins WHERE id=?')->execute([$id]);
                if(!empty($admin['user_id']))$pdo->prepare('DELETE FROM users WHERE id=?')->execute([(int)$admin['user_id']]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            if(!empty($admin['passport_photo'])){
                $photoPath=__DIR__.'/'.ltrim($admin['passport_photo'],'/\\');
                if(is_file($photoPath))@unlink($photoPath);
            }
            st_flash('Administrator deleted.');st_redirect('st_administration');
        }

        /* ---------- SITE COMPLETION ---------- */
        if($a==='st_complete_site'){
            if(!st_is_technician())throw new Exception('Only technician can complete a site.');
            $siteId=(int)($_POST['site_id']??0);$ct=st_current_technician($pdo);if(!$ct)throw new Exception('Technician account is not linked.');
            $q=$pdo->prepare("SELECT 1 FROM st_site_technicians WHERE site_id=? AND technician_id=? LIMIT 1");$q->execute([$siteId,$ct['id']]);
            if(!$q->fetch())throw new Exception('You are not assigned to this site.');
            $photo=st_upload_photo('completion_photo');if(!$photo)throw new Exception('Completion photo is required.');
            $lat=($_POST['latitude']??'')!==''?(float)$_POST['latitude']:null;$lng=($_POST['longitude']??'')!==''?(float)$_POST['longitude']:null;$acc=($_POST['accuracy']??'')!==''?(float)$_POST['accuracy']:null;if($lat===null||$lng===null)throw new Exception('GPS location is required for completion report.');
            $pdo->prepare("UPDATE st_sites SET status='Pending Admin Approval',completion_notes=?,completion_photo=?,completion_submitted_at=CURRENT_TIMESTAMP,completion_submitted_by=?,admin_approved=0,completion_latitude=?,completion_longitude=?,completion_accuracy=? WHERE id=?")
                ->execute([trim($_POST['completion_notes']??''),$photo,st_current_user_name(),$lat,$lng,$acc,$siteId]);
            st_audit($pdo,'site',$siteId,'SITE COMPLETION SUBMITTED');st_flash('Site completion submitted for Admin approval.');st_redirect('st_portal');
        }

        if($a==='st_approve_site_completion'){
            if(!st_is_admin())throw new Exception('Only Administrator can close a site.');
            $siteId=(int)$_POST['site_id'];
            $pdo->prepare("UPDATE st_sites SET status='Closed',admin_approved=1,admin_approved_by=?,admin_approved_at=CURRENT_TIMESTAMP,rejection_reason=NULL WHERE id=? AND status='Pending Admin Approval'")
                ->execute([st_current_user_name(),$siteId]);
            st_audit($pdo,'site',$siteId,'SITE CLOSED BY ADMIN');st_flash('Site approved and CLOSED.');st_redirect('st_sites');
        }

        if($a==='st_reject_site_completion'){
            if(!st_is_admin())throw new Exception('Only Administrator can reject site completion.');
            $siteId=(int)$_POST['site_id'];$reason=trim($_POST['reason']??'');if($reason==='')throw new Exception('Rejection reason is required.');
            $pdo->prepare("UPDATE st_sites SET status='In Progress',admin_approved=0,rejection_reason=? WHERE id=? AND status='Pending Admin Approval'")
                ->execute([$reason,$siteId]);
            st_audit($pdo,'site',$siteId,'SITE COMPLETION REJECTED',$reason);st_flash('Site returned to technician.');st_redirect('st_sites');
        }

    }catch(Throwable $e){
        st_flash('Error: '.$e->getMessage());
        if($a==='st_save_technician')st_redirect('st_technicians');
        st_redirect(st_is_technician()?'st_portal':'site_tracking');
    }
}

/* =========================================================
   SYSTEM ACCESS / CHOOSE SYSTEM
   ========================================================= */
if (isset($_SESSION['user'])) {
    $sessionUser=$_SESSION['user'];
    $systemPerms=json_decode($sessionUser['permissions']??'{}',true);if(!is_array($systemPerms))$systemPerms=[];
    $roleLower=strtolower(trim($sessionUser['role']??''));
    $accountSystem=strtolower(trim($sessionUser['account_system']??'business'));
    $isSuperAdmin=in_array($roleLower,['super admin','super_admin','superadmin'],true);
    $isBusinessAdmin=in_array($roleLower,['admin','msimamizi'],true);
    $canBusinessSystem=$isSuperAdmin||(($accountSystem==='business'||$accountSystem==='both')&&($isBusinessAdmin||!empty($systemPerms['business_system'])));
    $canSiteTrackingSystem=$isSuperAdmin||(($accountSystem==='site'||$accountSystem==='both')&&!empty($systemPerms['site_tracking_system']));
    $stPagesGuard=['site_tracking','st_portal','st_administration','st_technicians','st_sites','st_tools','st_materials','st_material_report','st_movements','st_approvals','st_site_gallery','st_reports','st_activity','st_daily_reports'];
    if(in_array($page,$stPagesGuard,true)&&!$canSiteTrackingSystem){http_response_code(403);exit('Huna ruhusa ya kutumia Site Tracking System.');}
    if(isset($_GET['system'])&&$_GET['system']==='business'&&!$canBusinessSystem){http_response_code(403);exit('Huna ruhusa ya kutumia Business System.');}
    if(!$canBusinessSystem&&!$isSuperAdmin&&!in_array($page,$stPagesGuard,true)&&$page!=='choose_system'){http_response_code(403);exit('Huna ruhusa ya kutumia Business System.');}
}

/* =========================================================
   CENTRAL PAGE PERMISSION GUARD
   ========================================================= */
if (isset($_SESSION['user'])) {
    $permissionMap=[
        'customers'=>'customers','add_customer'=>'customers','edit_customer'=>'customers','save_customer'=>'customers','update_customer'=>'customers','delete_customer'=>'customers',
        'products'=>'products','add_product'=>'products','edit_product'=>'products','save_product'=>'products','update_product'=>'products','delete_product'=>'products','barcode_product'=>'products',
        'suppliers'=>'products','edit_supplier'=>'products','save_supplier'=>'products','update_supplier'=>'products','delete_supplier'=>'products',
        'new_invoice'=>'invoices','invoices'=>'invoices','edit_invoice'=>'invoices','update_invoice'=>'invoices','save_invoice'=>'invoices','delete_invoice'=>'invoices','print'=>'invoices',
        'quotations'=>'invoices','new_quotation'=>'invoices','save_quotation'=>'invoices','print_quotation'=>'invoices','delete_quotation'=>'invoices',
        'proforma'=>'invoices','new_proforma'=>'invoices','save_proforma'=>'invoices','print_proforma'=>'invoices','delete_proforma'=>'invoices',
        'reports'=>'reports','expenses'=>'expenses','save_expense'=>'expenses','delete_expense'=>'expenses',
        'settings'=>'settings','save_settings'=>'settings','users'=>'users','add_user'=>'users','edit_user'=>'users','save_user'=>'users','update_user'=>'users','delete_user'=>'users','toggle_user'=>'users','activity'=>'activity'
    ];
    if(isset($permissionMap[$page])) {
        $guardRole=strtolower(trim($_SESSION['user']['role'] ?? ''));
        $guardPerms=json_decode($_SESSION['user']['permissions'] ?? '{}', true);
        if(!is_array($guardPerms)) $guardPerms=[];
        $guardBusiness=in_array($guardRole,['super admin','super_admin','superadmin','admin','msimamizi'],true) || !empty($guardPerms['business_system']);
        if(!$guardBusiness && !can_do($permissionMap[$page])) { http_response_code(403); exit('Huna ruhusa ya kutumia sehemu hii.'); }
    }
}

/* =========================================================
   /* =========================================================
   SITE TRACKING SYSTEM PAGES
   ========================================================= */
$stPages=['site_tracking','st_portal','st_administration','st_technicians','st_sites','st_tools','st_materials','st_material_report','st_movements','st_approvals','st_site_gallery','st_reports','st_activity','st_daily_reports'];

if(isset($_SESSION['user'])&&!st_is_technician()&&in_array($page,$stPages,true)){
    $stPermissionMap=['site_tracking'=>'dashboard','st_administration'=>'administration','st_technicians'=>'technicians','st_sites'=>'sites','st_tools'=>'tools','st_materials'=>'materials','st_material_report'=>'materials','st_movements'=>'movements','st_approvals'=>'approvals','st_site_gallery'=>'photos','st_reports'=>'reports','st_activity'=>'activity','st_daily_reports'=>'daily_reports'];
    $requiredPermission=$stPermissionMap[$page]??'dashboard';
    if(!st_admin_can($pdo,$requiredPermission)){http_response_code(403);exit('Huna ruhusa ya kuona sehemu hii ya Site Tracking.');}
}

if(in_array($page,$stPages,true)){
    $flash=$_SESSION['st_flash']??'';unset($_SESSION['st_flash']);

    $allTechs=$pdo->query("SELECT * FROM st_technicians ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    $techs=$allTechs;
    $sites=$pdo->query("SELECT * FROM st_sites ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $tools=$pdo->query("SELECT * FROM st_tools ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $materials=$pdo->query("SELECT * FROM st_materials ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

    $ct=st_is_technician()?st_current_technician($pdo):null;

    if(st_is_technician()){
        if(!$ct){ http_response_code(403); exit('Akaunti ya fundi haijaunganishwa na Technician record.'); }
        $techs=[$ct];
        $q=$pdo->prepare("SELECT s.* FROM st_sites s
                          JOIN st_site_technicians st ON st.site_id=s.id
                          WHERE st.technician_id=? ORDER BY s.id DESC");
        $q->execute([(int)$ct['id']]);$sites=$q->fetchAll(PDO::FETCH_ASSOC);
    }

    function st_e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
    function st_photo($path,$class='st-photo'){
        if(!$path)return '';
        return '<a href="'.st_e($path).'" target="_blank"><img class="'.st_e($class).'" src="'.st_e($path).'" alt="Photo"></a>';
    }

    /* Edit records loaded only for Admin. */
    $editTech=null;$editSite=null;$editTool=null;$editMaterial=null;
    if(st_is_admin()){
        if(isset($_GET['edit']) && $page==='st_technicians'){
            $q=$pdo->prepare("SELECT * FROM st_technicians WHERE id=?");$q->execute([(int)$_GET['edit']]);$editTech=$q->fetch(PDO::FETCH_ASSOC);
        }
        if(isset($_GET['edit']) && $page==='st_sites'){
            $q=$pdo->prepare("SELECT * FROM st_sites WHERE id=?");$q->execute([(int)$_GET['edit']]);$editSite=$q->fetch(PDO::FETCH_ASSOC);
            if($editSite)$editSite['team']=st_site_team_ids($pdo,(int)$editSite['id']);
        }
        if(isset($_GET['edit']) && $page==='st_tools'){
            $q=$pdo->prepare("SELECT * FROM st_tools WHERE id=?");$q->execute([(int)$_GET['edit']]);$editTool=$q->fetch(PDO::FETCH_ASSOC);
        }
        if(isset($_GET['edit']) && $page==='st_materials'){
            $q=$pdo->prepare("SELECT * FROM st_materials WHERE id=?");$q->execute([(int)$_GET['edit']]);$editMaterial=$q->fetch(PDO::FETCH_ASSOC);
        }
    }

    ?>
<!doctype html>
<html lang="<?=st_e($zanLang)?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Site Tracking System</title>
<link rel="stylesheet" href="style.css">
<style>
body{margin:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#222}
.st-head{background:#ffd000;padding:14px 20px;display:flex;gap:12px;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:20}
.st-layout{display:flex;min-height:calc(100vh - 60px)}
.st-side{width:245px;background:#171717;padding:16px;box-sizing:border-box}
.st-side a{display:block;color:#fff;text-decoration:none;padding:12px;border-radius:8px;margin:5px 0}
.st-side a:hover{background:#333}
.st-side details{margin:8px 0}.st-side summary{cursor:pointer;color:#ffd000;font-weight:800;padding:10px 6px;list-style:none}.st-side summary::-webkit-details-marker{display:none}.st-side details a{padding:8px 12px;margin:2px 0;font-size:13px}
.st-main{flex:1;padding:22px;max-width:1450px;box-sizing:border-box}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px}
.card,.panel{background:#fff;border-radius:12px;padding:18px;box-shadow:0 3px 12px rgba(0,0,0,.08)}
.card{text-decoration:none;color:#222}.card b{font-size:20px}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px}
.field{margin-bottom:10px}.field input,.field select,.field textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #ccc;border-radius:7px}
.field select[multiple]{min-height:130px}
.btn{display:inline-block;padding:9px 13px;border:0;border-radius:7px;background:#ffd000;color:#111;font-weight:bold;cursor:pointer;text-decoration:none}
.btn.danger{background:#e53935;color:#fff}.btn.dark{background:#222;color:#fff}.btn.small{padding:6px 9px;font-size:12px}
.notice{padding:12px;background:#e7f7ea;border-radius:8px;margin-bottom:14px}
.warn{padding:12px;background:#fff3cd;border-radius:8px;margin-bottom:14px}
table{width:100%;border-collapse:collapse;background:#fff;min-width:720px}
th,td{padding:10px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top}
.table-wrap{overflow:auto}
img.passport{width:55px;height:55px;object-fit:cover;border-radius:50%}
.st-photo{max-width:110px;max-height:90px;object-fit:cover;border-radius:8px;border:1px solid #ddd}
.small{font-size:12px;color:#666}.muted{color:#777}.actions{display:flex;gap:6px;flex-wrap:wrap}
.status{display:inline-block;padding:4px 8px;border-radius:99px;background:#eee;font-size:12px;font-weight:bold}
.profile{display:grid;grid-template-columns:120px 1fr;gap:20px;align-items:start}
.profile img{width:110px;height:110px;border-radius:12px;object-fit:cover;border:1px solid #ddd}
.report-card{margin-bottom:18px}
.dash-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:18px}.dash-panel{min-width:0}.dash-kpi{border-left:5px solid #ffd000}.dash-kpi .label{font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.04em}.dash-kpi .value{font-size:30px;font-weight:800;margin-top:5px}.dash-actions{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0}.dash-actions .btn{font-size:13px}.alert-row{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid #eee}.alert-row:last-child{border-bottom:0}.progress-line{display:flex;align-items:center;gap:10px;margin:11px 0}.progress-line span:first-child{width:145px}.progress-track{height:10px;flex:1;background:#eee;border-radius:99px;overflow:hidden}.progress-fill{height:100%;background:#2563eb;border-radius:99px}
.overdue-row{background:#fff0f0!important;color:#9b1c1c}.warning-row{background:#fff8df!important;color:#7a5800}.ok-row{background:#effaf1!important;color:#176b2c}.wa-btn{background:#25d366!important;color:#fff!important}
.photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:18px}.photo-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:12px;box-shadow:0 2px 10px rgba(0,0,0,.06)}.gallery-photo{width:100%;height:190px;object-fit:cover;border-radius:10px;border:1px solid #ddd}.gps-status{margin-left:8px}.gps-ready{color:#087f23;font-weight:bold}.gps-error{color:#b42318;font-weight:bold}
@media(max-width:760px){.st-layout{display:block}.st-side{width:auto;display:flex;overflow:auto;padding:8px}.st-side a{white-space:nowrap}.st-main{padding:12px}.st-head{font-size:13px}.profile{grid-template-columns:1fr}table{min-width:850px}}
</style>
</head>
<body>
<div class="st-head">
    <b style="display:flex;align-items:center;gap:10px"><img src="<?=st_e($settings['logo']??'uploads/logo_1788004312.jpeg')?>" alt="Zantronix logo" style="height:38px;width:auto;max-width:170px;object-fit:contain;background:#fff;padding:3px;border-radius:4px"> <?=zan_t('SITE TRACKING SYSTEM','SITE TRACKING SYSTEM')?></b>
  <span>
    <a href="?page=<?=st_e($page)?>&lang=sw">SW</a> |
    <a href="?page=<?=st_e($page)?>&lang=en">EN</a> |
    <?php if($canBusinessSystem):?><a href="?page=choose_system"><?=zan_t('Badilisha Mfumo','Switch System')?></a> | <?php endif;?>
    <a href="?logout=1"><?=zan_t('Toka','Logout')?></a>
  </span>
</div>

<div class="st-layout">
<aside class="st-side">
<?php if(st_is_technician()): ?>
    <a href="?page=st_portal">🏠 <?=zan_t('Dashboard / Portal ya Fundi','Dashboard / Technician Portal')?></a>
    <details open><summary>📍 <?=zan_t('Sites','Sites')?></summary><a href="?page=st_sites">Orodha ya Sites</a><a href="?page=st_site_gallery">📸 Photos + GPS</a></details>
    <details open><summary>🔄 <?=zan_t('Movements','Movements')?></summary><a href="?page=st_movements">Tool / Material Movements</a></details>
<?php else: ?>
    <?php if(st_admin_can($pdo,'dashboard')):?><a href="?page=site_tracking">🏠 <?=zan_t('Dashibodi','Dashboard')?></a><?php endif;?>
        <?php if(st_admin_can($pdo,'technicians')):?><details open><summary>👷 Mafundi / Technicians</summary><a href="?page=st_technicians">Orodha ya Mafundi</a><a href="?page=st_technicians">Ongeza Fundi</a><a href="?page=st_technicians">Profile ya Fundi</a></details><?php endif;?>
        <?php if(st_admin_can($pdo,'sites')):?><details open><summary>📍 Sites</summary><a href="?page=st_sites">Orodha ya Sites</a><a href="?page=st_sites">Ongeza Site</a><a href="?page=st_sites">Site Details / Progress</a><a href="?page=st_site_gallery">Photos + GPS</a></details><?php endif;?>
        <?php if(st_admin_can($pdo,'tools')):?><details><summary>🧰 Tools</summary><a href="?page=st_tools">Tools Register</a><a href="?page=st_movements">Issue / Return Tool</a><a href="?page=st_movements">Tool Custody</a><a href="?page=st_movements">Movement History</a></details><?php endif;?>
        <?php if(st_admin_can($pdo,'materials')):?><details><summary>📦 Materials</summary><a href="?page=st_materials">Materials</a><a href="?page=st_movements">Manager Issues Material</a><a href="?page=st_movements">Fundi Receives / Material Used</a><a href="?page=st_material_report">Usage History</a></details><?php endif;?>
        <?php if(st_admin_can($pdo,'photos')):?><details><summary>📸 Site Photos</summary><a href="?page=st_site_gallery">Arrival / Before Work</a><a href="?page=st_site_gallery">Work Progress / Material Used</a><a href="?page=st_site_gallery">Completed / Problem / Exit</a><a href="?page=st_site_gallery">Customer Handover</a></details><a href="?page=st_site_gallery">🗺️ GPS / Site Map</a><?php endif;?>
        <?php if(st_admin_can($pdo,'movements')):?><details><summary>🔄 Movements</summary><a href="?page=st_movements">Tool Movements</a><a href="?page=st_movements">Material Movements</a></details><?php endif;?>
        <?php if(st_admin_can($pdo,'approvals')):?><a href="?page=st_approvals">✅ Approvals</a><?php endif;?>
        <?php if(st_admin_can($pdo,'reports')):?><a href="?page=st_reports">📊 Reports</a><?php endif;?>
        <?php if(st_admin_can($pdo,'daily_reports')):?><a href="?page=st_daily_reports">📚 Daily Reports</a><?php endif;?>
        <?php if(st_admin_can($pdo,'activity')):?><a href="?page=st_activity">📝 Activity / Audit History</a><?php endif;?>
        <?php if(st_admin_can($pdo,'administration')):?><a href="?page=st_administration">🏢 Administration</a><?php endif;?>
<?php endif;?>
</aside>

<main class="st-main">
<?php if($flash):?><div class="notice"><?=st_e($flash)?></div><?php endif;?>

<?php if($page==='st_portal' && st_is_technician()): ?>

  <?php
  $tid=(int)$ct['id'];
  $q=$pdo->prepare("SELECT s.*,GROUP_CONCAT(t.full_name, ', ') AS team_names
                    FROM st_sites s
                    LEFT JOIN st_site_technicians st ON st.site_id=s.id
                    LEFT JOIN st_technicians t ON t.id=st.technician_id
                    WHERE s.id IN (SELECT site_id FROM st_site_technicians WHERE technician_id=?)
                    GROUP BY s.id ORDER BY s.id DESC");
  $q->execute([$tid]);$mySites=$q->fetchAll(PDO::FETCH_ASSOC);

  $q=$pdo->prepare("SELECT tm.*,t.tool_name,t.tool_code,s.site_name
                    FROM st_tool_movements tm
                    LEFT JOIN st_tools t ON t.id=tm.tool_id
                    LEFT JOIN st_sites s ON s.id=tm.site_id
                    WHERE tm.technician_id=? ORDER BY tm.id DESC LIMIT 30");
  $q->execute([$tid]);$myTools=$q->fetchAll(PDO::FETCH_ASSOC);

  $q=$pdo->prepare("SELECT mm.*,m.material_name,m.unit,s.site_name
                    FROM st_material_movements mm
                    LEFT JOIN st_materials m ON m.id=mm.material_id
                    LEFT JOIN st_sites s ON s.id=mm.site_id
                    WHERE mm.technician_id=? ORDER BY mm.id DESC LIMIT 30");
  $q->execute([$tid]);$myMaterials=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$pdo->prepare("SELECT f.*,s.site_name FROM st_material_funds f LEFT JOIN st_sites s ON s.id=f.site_id WHERE f.technician_id=? ORDER BY f.id DESC LIMIT 50");
    $q->execute([$tid]);$myMaterialFunds=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$pdo->prepare("SELECT u.*,s.site_name FROM st_daily_updates u JOIN st_sites s ON s.id=u.site_id WHERE u.technician_id=? ORDER BY u.update_date DESC LIMIT 30");
    $q->execute([$tid]);$myDailyUpdates=$q->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <h2>👷 <?=zan_t('Technician Portal','Technician Portal')?></h2>

  <div class="panel profile">
    <div>
      <?php if($ct['photo']):?><?=st_photo($ct['photo'],'passport')?><?php else:?><div class="passport" style="width:110px;height:110px;border-radius:12px;background:#eee;display:grid;place-items:center;font-size:42px">👷</div><?php endif;?>
    </div>
    <div>
      <h2 style="margin-top:0"><?=st_e($ct['full_name'])?></h2>
      <p><b>Technician No:</b> <?=st_e($ct['technician_no'])?></p>
      <p><b>Username:</b> <?=st_e($ct['username'])?></p>
      <p><b>Phone:</b> <?=st_e($ct['phone'])?></p>
    <p><b>Office:</b> <?=st_e($ct['office']??'')?></p>
    <p><b>Jinsia:</b> <?=st_e($ct['gender']??'')?></p>
    <p><b>Hali ya ndoa:</b> <?=st_e($ct['marital_status']??'')?></p>
    <p><b>Watoto:</b> <?=st_e($ct['children_count']??0)?></p>
      <p><b>Specialization:</b> <?=st_e($ct['specialization'])?></p>
      <p><b>Status:</b> <span class="status"><?=st_e($ct['status'])?></span></p>
    </div>
  </div>

  <h3>🏗️ <?=zan_t('Sites ulizopewa','Assigned Sites')?></h3>
  <div class="table-wrap panel"><table>
    <tr><th>Site</th><th>Customer</th><th>Location</th><th>Team</th><th>Status</th><th>Action</th></tr>
    <?php foreach($mySites as $s):?>
      <tr>
        <td><?=st_e($s['site_name'])?></td><td><?=st_e($s['customer_name'])?></td><td><?=st_e($s['location'])?></td>
        <td><?=st_e($s['team_names']??$ct['full_name'])?></td><td><span class="status"><?=st_e($s['status'])?></span></td>
        <td><?php if($s['status']!=='Closed'):?><form method="post" enctype="multipart/form-data" class="actions">
          <input type="hidden" name="st_action" value="st_complete_site"><input type="hidden" name="site_id" value="<?=$s['id']?>">
          <input type="file" name="completion_photo" accept="image/*" capture="environment" required>
          <input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><input type="hidden" name="captured_at" class="gps-time">
          <button type="button" class="btn gps-btn">📍 <?=zan_t('Pata Location','Get Location')?></button> <span class="gps-status small"><?=zan_t('Location bado haijapatikana','Location not captured yet')?></span>
          <input name="completion_notes" placeholder="Completion notes" required>
          <button class="btn">SUBMIT COMPLETION</button>
        </form><?php endif;?></td>
      </tr>
    <?php endforeach;?>
  </table></div>

    <div class="grid2">
        <div class="panel"><h3>🧰 Omba Tool</h3><form method="post"><input type="hidden" name="st_action" value="st_request_tool"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><div class="field"><select name="site_id" required><option value="">Chagua Site</option><?php foreach($mySites as $site):?><option value="<?=$site['id']?>"><?=st_e($site['site_name'])?></option><?php endforeach;?></select></div><div class="field"><select name="item_id" required><option value="">Chagua Tool</option><?php foreach($tools as $tool):?><option value="<?=$tool['id']?>"><?=st_e($tool['tool_name'].' - '.$tool['tool_code'])?></option><?php endforeach;?></select></div><div class="field"><textarea name="reason" placeholder="Kwa nini unahitaji tool hii?"></textarea></div><button class="btn">SUBMIT TOOL REQUEST</button></form></div>
        <div class="panel"><h3>📦 Omba Material</h3><form method="post"><input type="hidden" name="st_action" value="st_request_material"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><div class="field"><select name="site_id" required><option value="">Chagua Site</option><?php foreach($mySites as $site):?><option value="<?=$site['id']?>"><?=st_e($site['site_name'])?></option><?php endforeach;?></select></div><div class="field"><select name="item_id" required><option value="">Chagua Material</option><?php foreach($materials as $material):?><option value="<?=$material['id']?>"><?=st_e($material['material_name'].' ('.$material['unit'].')')?></option><?php endforeach;?></select></div><div class="field"><input type="number" name="quantity" min="0.01" step="0.01" placeholder="Quantity" required></div><div class="field"><textarea name="reason" placeholder="Material itatumika kwa kazi gani?"></textarea></div><button class="btn">SUBMIT MATERIAL REQUEST</button></form></div>
    </div>

    <div class="panel"><h3>📝 Daily Work Update</h3>
        <form method="post" enctype="multipart/form-data" class="gps-form">
            <input type="hidden" name="st_action" value="st_save_daily_update">
            <div class="grid2"><div class="field"><select name="site_id" required><option value="">-- Site --</option><?php foreach($mySites as $s):?><option value="<?=$s['id']?>"><?=st_e($s['site_name'])?></option><?php endforeach;?></select></div><div class="field"><input type="date" name="update_date" value="<?=date('Y-m-d')?>" max="<?=date('Y-m-d')?>" required></div><div class="field"><textarea name="work_done" placeholder="Umefanya nini leo?" required></textarea></div><div class="field"><textarea name="blockers" placeholder="Changamoto / kilichokuzuia"></textarea></div><div class="field"><textarea name="next_steps" placeholder="Hatua za kesho"></textarea></div><div class="field"><input type="file" name="photo" accept="image/*" capture="environment" required><small>Piga picha ya update kwa camera ya simu (lazima).</small></div></div>
            <input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><button type="button" class="btn gps-btn">📍 GPS</button> <span class="gps-status small">GPS optional</span> <button class="btn" type="submit">SAVE DAILY UPDATE</button>
        </form>
    </div>
    <div class="panel"><h3>📚 My Daily Reports</h3><div class="table-wrap"><table><tr><th>Date</th><th>Site</th><th>Work Done</th><th>Blockers</th><th>Next Steps</th></tr><?php foreach($myDailyUpdates as $update):?><tr><td><?=st_e($update['update_date'])?></td><td><?=st_e($update['site_name'])?></td><td><?=nl2br(st_e($update['work_done']))?></td><td><?=nl2br(st_e($update['blockers']))?></td><td><?=nl2br(st_e($update['next_steps']))?></td></tr><?php endforeach;?></table></div><?php if(!$myDailyUpdates):?><p class="muted">Hakuna daily update bado.</p><?php endif;?></div>

  <div class="grid2">
    <div class="panel">
      <h3>📦 <?=zan_t('Ripoti Material Iliyotumika','Report Material Used')?></h3>
      <div class="notice"><?=zan_t('Material inakabidhiwa na Meneja. Wewe una-report kiasi kilichotumika, picha na GPS ya site.','Material is issued by the Manager. You report what was used, with a site photo and GPS.')?></div>
      <form method="post" enctype="multipart/form-data" class="gps-form">
        <input type="hidden" name="st_action" value="st_report_material_usage">
        <div class="field"><select name="material_id" required><option value="">-- Material --</option><?php foreach($materials as $m):?><option value="<?=$m['id']?>"><?=st_e($m['material_name'].' ('.$m['unit'].')')?></option><?php endforeach;?></select></div>
        <div class="field"><select name="site_id" required><option value="">-- Site --</option><?php foreach($mySites as $s):?><option value="<?=$s['id']?>"><?=st_e($s['site_name'].' - '.$s['customer_name'])?></option><?php endforeach;?></select></div>
        <div class="field"><input type="number" step="0.01" min="0.01" name="quantity" placeholder="Quantity Used" required></div>
        <div class="field"><textarea name="reason" placeholder="What was done with this material?"></textarea></div>
        <div class="field">📷 <?=zan_t('Picha ya matumizi','Usage photo')?><input type="file" name="photo" accept="image/*" capture="environment" required></div>
        <input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><input type="hidden" name="captured_at" class="gps-time">
        <button type="button" class="btn gps-btn">📍 <?=zan_t('Pata Location','Get Location')?></button> <span class="gps-status small"><?=zan_t('Location bado haijapatikana','Location not captured yet')?></span>
        <br><button class="btn" type="submit">SUBMIT USAGE</button>
      </form>
    </div>

    <div class="panel">
      <h3>📷 <?=zan_t('Tuma Picha ya Site + GPS','Submit Site Photo + GPS')?></h3>
      <form method="post" enctype="multipart/form-data" class="gps-form">
        <input type="hidden" name="st_action" value="st_add_site_photo">
        <div class="field"><select name="site_id" required><option value="">-- Site --</option><?php foreach($mySites as $s):?><option value="<?=$s['id']?>"><?=st_e($s['site_name'])?></option><?php endforeach;?></select></div>
        <div class="field"><select name="category"><option>Site Arrival</option><option>Before Work</option><option>Material Received</option><option selected>Work Progress</option><option>Material Used</option><option>Completed Work</option><option>Problem / Damage</option><option>Site Exit</option><option>Customer Handover</option></select></div>
        <div class="field"><input name="caption" placeholder="<?=zan_t('Maelezo ya picha','Photo description')?>"></div>
        <div class="field">📷 <input type="file" name="photo" accept="image/*" capture="environment" required></div>
        <input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><input type="hidden" name="captured_at" class="gps-time">
        <button type="button" class="btn gps-btn">📍 <?=zan_t('Pata Location','Get Location')?></button> <span class="gps-status small"><?=zan_t('Location bado haijapatikana','Location not captured yet')?></span>
        <br><button class="btn" type="submit">📤 <?=zan_t('TUMA PICHA','SUBMIT PHOTO')?></button>
      </form>
    </div>

    <div class="panel">
      <h3>🔧 <?=zan_t('Picha za Tools nilizopewa','My Tool Records')?></h3>
      <div class="table-wrap"><table>
        <tr><th>Tool</th><th>Site</th><th>Type</th><th>Photo</th><th>Status</th></tr>
        <?php foreach($myTools as $m):?><tr>
          <td><?=st_e(($m['tool_code']??'').' '.$m['tool_name'])?></td><td><?=st_e($m['site_name']??'')?></td>
          <td><?=st_e($m['action_type'])?></td><td><?=st_photo($m['photo']??'')?></td><td><?=st_e($m['status'])?></td>
        </tr><?php endforeach;?>
      </table></div>
    </div>
  </div>

        <div class="panel"><h3>💰 <?=zan_t('Pesa za Material Nilizopewa','Material Funds Received')?></h3><div class="table-wrap"><table>
            <tr><th>Site</th><th>Kiasi</th><th>Sababu</th><th>Status</th><th>Risiti</th><th>Action</th></tr>
            <?php foreach($myMaterialFunds as $fund):?><tr>
                <td><?=st_e($fund['site_name']??'')?></td><td><b><?=st_e($fund['amount'].' '.$fund['currency'])?></b></td><td><?=st_e($fund['purpose']??'')?></td>
                <td><span class="status"><?=st_e($fund['status'])?></span></td><td><?=!empty($fund['receipt_photo'])?st_photo($fund['receipt_photo']):'-'?></td>
                <td><?php if(empty($fund['receipt_photo'])&&$fund['status']==='Granted'):?><form method="post" enctype="multipart/form-data" class="gps-form actions"><input type="hidden" name="st_action" value="st_submit_material_receipt"><input type="hidden" name="fund_id" value="<?=$fund['id']?>"><input type="file" name="receipt_photo" accept="image/*" capture="environment" required><input name="receipt_notes" placeholder="Maelezo ya risiti" required><input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><button type="button" class="btn gps-btn">📍 GPS</button><button class="btn small">TUMA RISITI</button></form><?php else:?>Imetumwa<?php endif;?></td>
            </tr><?php endforeach;?>
        </table></div><?php if(!$myMaterialFunds):?><p class="muted">Hujawekewa pesa ya material bado.</p><?php endif;?></div>

  <div class="panel"><h3>📦 <?=zan_t('Material Requests Zangu','My Material Requests')?></h3><div class="table-wrap"><table>
    <tr><th>Material</th><th>Qty</th><th>Site</th><th>Photo</th><th>Date</th><th>Status</th></tr>
    <?php foreach($myMaterials as $m):?><tr>
      <td><?=st_e($m['material_name']??'')?></td><td><?=st_e($m['quantity'])?> <?=st_e($m['unit']??'')?></td>
      <td><?=st_e($m['site_name']??'')?></td><td><?=st_photo($m['photo']??'')?></td>
      <td><?=st_e($m['created_at'])?></td><td><span class="status"><?=st_e($m['status'])?></span></td>
    </tr><?php endforeach;?>
  </table></div></div>

<?php elseif($page==='site_tracking'): ?>
  <?php
  $held=$pdo->query("SELECT tm.*,t.tool_name,t.tool_code,tc.full_name,s.site_name
                     FROM st_tool_movements tm
                     LEFT JOIN st_tools t ON t.id=tm.tool_id
                     LEFT JOIN st_technicians tc ON tc.id=tm.technician_id
                     LEFT JOIN st_sites s ON s.id=tm.site_id
                     WHERE tm.action_type='Issued' AND COALESCE(tm.status,'') NOT IN ('Returned','Rejected')
                     ORDER BY tm.id DESC")->fetchAll(PDO::FETCH_ASSOC);
  $graph=$pdo->query("SELECT tc.full_name,COUNT(tm.id) total FROM st_technicians tc
                      JOIN st_tool_movements tm ON tm.technician_id=tc.id
                      WHERE tm.action_type='Issued' AND COALESCE(tm.status,'') NOT IN ('Returned','Rejected')
                      GROUP BY tc.id ORDER BY total DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $mx=1;foreach($graph as $g)$mx=max($mx,(int)$g['total']);
    $dashActive=(int)$pdo->query("SELECT COUNT(*) FROM st_sites WHERE COALESCE(status,'')<>'Closed'")->fetchColumn();
    $dashOverdue=(int)$pdo->query("SELECT COUNT(*) FROM st_sites WHERE due_date IS NOT NULL AND due_date<>'' AND due_date < date('now') AND COALESCE(status,'')<>'Closed'")->fetchColumn();
    $dashPending=(int)$pdo->query("SELECT COUNT(*) FROM st_sites WHERE status='Pending Admin Approval'")->fetchColumn();
    $dashOpenTasks=(int)$pdo->query("SELECT COUNT(*) FROM st_site_tasks WHERE status<>'Completed'")->fetchColumn();
    $dashPhotos=(int)$pdo->query("SELECT COUNT(*) FROM st_site_photos WHERE created_at >= datetime('now','-7 days')")->fetchColumn();
    $dashOverdueTools=$pdo->query("SELECT tm.id,tm.due_date,tm.tool_name,tm.technician_id,tm.technician_name,tm.phone,tm.site_name FROM (SELECT m.id,m.due_date,m.technician_id,t.tool_name,n.full_name technician_name,n.phone,s.site_name FROM st_tool_movements m JOIN st_tools t ON t.id=m.tool_id JOIN st_technicians n ON n.id=m.technician_id LEFT JOIN st_sites s ON s.id=m.site_id WHERE m.action_type='Issued' AND m.due_date IS NOT NULL AND m.due_date<>'' AND m.due_date < date('now') AND COALESCE(m.status,'') NOT IN ('Returned','Rejected')) tm ORDER BY tm.due_date LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    $dashOverdueSites=$pdo->query("SELECT s.*,t.id technician_id,t.full_name technician_name,t.phone FROM st_sites s LEFT JOIN st_technicians t ON t.id=s.technician_id WHERE s.due_date IS NOT NULL AND s.due_date<>'' AND s.due_date < date('now') AND COALESCE(s.status,'')<>'Closed' ORDER BY s.due_date LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    $dashMissingUpdates=$pdo->query("SELECT s.id,s.site_name,s.due_date,t.id technician_id,t.full_name technician_name,t.phone FROM st_sites s JOIN st_technicians t ON t.id=s.technician_id LEFT JOIN st_daily_updates u ON u.site_id=s.id AND u.technician_id=t.id AND u.update_date=date('now') WHERE COALESCE(s.status,'') NOT IN ('Closed','Pending') AND u.id IS NULL ORDER BY s.due_date LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    foreach($dashOverdueTools as $tool){$days=max(1,(int)((strtotime(date('Y-m-d'))-strtotime($tool['due_date']))/86400));st_queue_whatsapp_notification($pdo,$tool['technician_id'],'overdue_tool','Tool '.$tool['tool_name'].' imechelewa kwa '.$days.' siku.');}
    foreach($dashOverdueSites as $site){$days=max(1,(int)((strtotime(date('Y-m-d'))-strtotime($site['due_date']))/86400));st_queue_whatsapp_notification($pdo,$site['technician_id'],'overdue_site','Site '.$site['site_name'].' imechelewa kwa '.$days.' siku.');}
    foreach($dashMissingUpdates as $missing){st_queue_whatsapp_notification($pdo,$missing['technician_id'],'missing_daily_update','Daily update ya site '.$missing['site_name'].' haijawekwa leo.');}
    $dashStatuses=$pdo->query("SELECT status,COUNT(*) total FROM st_sites GROUP BY status ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC);
    $dashStatusMax=1;foreach($dashStatuses as $statusRow)$dashStatusMax=max($dashStatusMax,(int)$statusRow['total']);
    $dashAlerts=$pdo->query("SELECT site_name,status,due_date FROM st_sites WHERE (due_date IS NOT NULL AND due_date<>'' AND due_date < date('now') AND COALESCE(status,'')<>'Closed') OR status='Pending Admin Approval' ORDER BY due_date LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    $dashActivity=$pdo->query('SELECT action,details,performed_by,created_at FROM st_audit_log ORDER BY id DESC LIMIT 6')->fetchAll(PDO::FETCH_ASSOC);
    $monthBest=st_best_technicians($pdo,$allTechs,date('Y-m-01'),date('Y-m-t'));
    $yearBest=st_best_technicians($pdo,$allTechs,date('Y-01-01'),date('Y-12-31'));
    $bestMonth=$monthBest[0]??null;$bestYear=$yearBest[0]??null;
  ?>
  <h2>📊 Site Tracking Dashboard</h2>
  <div class="cards">
        <a class="card dash-kpi" href="?page=st_sites"><div class="label">Active Sites</div><div class="value"><?=$dashActive?></div><span>🏗️ View sites</span></a>
        <a class="card dash-kpi" href="?page=st_reports"><div class="label">Overdue Sites</div><div class="value"><?=$dashOverdue?></div><span>⚠️ Review report</span></a>
        <a class="card dash-kpi" href="?page=st_approvals"><div class="label">Pending Approval</div><div class="value"><?=$dashPending?></div><span>✅ Open approvals</span></a>
        <a class="card dash-kpi" href="?page=st_sites"><div class="label">Open Tasks</div><div class="value"><?=$dashOpenTasks?></div><span>☑️ View checklist</span></a>
        <a class="card" href="?page=st_technicians">👷 <b><?=count($allTechs)?></b><br>Technicians</a>
        <a class="card" href="?page=st_site_gallery">📷 <b><?=$dashPhotos?></b><br>Photos last 7 days</a>
  </div>
    <div class="dash-actions"><a class="btn" href="?page=st_sites">➕ Add Site</a><a class="btn" href="?page=st_technicians">👷 Add Technician</a><a class="btn" href="?page=st_tools">🔧 Register Tool</a><a class="btn" href="?page=st_reports">📊 View Reports</a><a class="btn" href="?page=st_approvals">✅ Review Approvals</a></div>
    <div class="dash-grid">
        <div class="panel dash-panel"><h3>📈 Site Progress</h3><?php foreach($dashStatuses as $statusRow):?><div class="progress-line"><span><?=st_e($statusRow['status']?:'Unknown')?></span><div class="progress-track"><div class="progress-fill" style="width:<?=round((int)$statusRow['total']/$dashStatusMax*100)?>%"></div></div><b><?=st_e($statusRow['total'])?></b></div><?php endforeach;?><?php if(!$dashStatuses):?><p class="muted">Hakuna site data bado.</p><?php endif;?></div>
        <div class="panel dash-panel"><h3>🔔 Attention Needed</h3><?php foreach($dashAlerts as $alert):?><div class="alert-row"><span><b><?=st_e($alert['site_name'])?></b><br><small><?=st_e($alert['status'])?></small></span><span><?=st_e($alert['due_date']?:'Review')?></span></div><?php endforeach;?><?php if(!$dashAlerts):?><p class="muted">Hakuna alerts kwa sasa.</p><?php endif;?></div>
        <div class="panel dash-panel"><h3>📝 Recent Activity</h3><?php foreach($dashActivity as $activity):?><div class="alert-row"><span><b><?=st_e($activity['action'])?></b><br><small><?=st_e($activity['details'])?></small></span><span><?=st_e($activity['created_at'])?></span></div><?php endforeach;?><?php if(!$dashActivity):?><p class="muted">Hakuna activity bado.</p><?php endif;?><a class="btn small" href="?page=st_activity">View full history</a></div>
        <div class="panel dash-panel"><h3>📦 System Snapshot</h3><div class="alert-row"><span>Registered tools</span><b><?=count($tools)?></b></div><div class="alert-row"><span>Materials catalogue</span><b><?=count($materials)?></b></div><div class="alert-row"><span>Photos last 7 days</span><b><?=$dashPhotos?></b></div><div class="alert-row"><span>Unreturned tools</span><b><?=count($held)?></b></div></div>
    </div>
    <div class="dash-grid">
        <div class="panel dash-panel"><h3>🧰 Overdue Tools</h3><div class="table-wrap"><table><tr><th>Tool</th><th>Fundi</th><th>Site</th><th>Due</th><th>Action</th></tr><?php foreach($dashOverdueTools as $tool):$days=max(1,(int)((strtotime(date('Y-m-d'))-strtotime($tool['due_date']))/86400));$msg=st_admin_message($pdo,$tool['technician_name'],$tool['site_name'],$days,$tool['due_date'],'Habari {technician}, tool '.$tool['tool_name'].' ya site {site} imechelewa kurudishwa kwa {days} siku. Tafadhali toa update.');$wa=st_whatsapp_link($tool['phone'],$msg);?><tr class="overdue-row"><td><?=st_e($tool['tool_name'])?></td><td><?=st_e($tool['technician_name'])?></td><td><?=st_e($tool['site_name'])?></td><td><?=st_e($tool['due_date'])?><br><small><?=$days?> days late</small></td><td><?php if($wa):?><a class="btn small wa-btn" target="_blank" href="<?=st_e($wa)?>">WhatsApp</a><?php endif;?></td></tr><?php endforeach;?></table></div><?php if(!$dashOverdueTools):?><p class="muted">Hakuna tool iliyochelewa.</p><?php endif;?></div>
        <div class="panel dash-panel"><h3>🏗️ Overdue Sites</h3><div class="table-wrap"><table><tr><th>Site</th><th>Fundi</th><th>Due</th><th>Action</th></tr><?php foreach($dashOverdueSites as $site):$days=max(1,(int)((strtotime(date('Y-m-d'))-strtotime($site['due_date']))/86400));$msg=st_admin_message($pdo,$site['technician_name'],$site['site_name'],$days,$site['due_date'],'Habari {technician}, site {site} imechelewa kwa {days} siku. Tafadhali tuma daily update na completion plan.');$wa=st_whatsapp_link($site['phone'],$msg);?><tr class="overdue-row"><td><?=st_e($site['site_name'])?></td><td><?=st_e($site['technician_name'])?></td><td><?=st_e($site['due_date'])?><br><small><?=$days?> days late</small></td><td><?php if($wa):?><a class="btn small wa-btn" target="_blank" href="<?=st_e($wa)?>">WhatsApp</a><?php endif;?></td></tr><?php endforeach;?></table></div><?php if(!$dashOverdueSites):?><p class="muted">Hakuna site iliyochelewa.</p><?php endif;?></div>
        <div class="panel dash-panel"><h3>📝 Missing Daily Updates</h3><?php foreach($dashMissingUpdates as $missing):$msg=st_admin_message($pdo,$missing['technician_name'],$missing['site_name'],0,date('Y-m-d'),'Habari {technician}, bado hujaweka daily update ya site {site} leo. Tafadhali weka update ya kazi uliyofanya.');$wa=st_whatsapp_link($missing['phone'],$msg);?><div class="alert-row warning-row"><span><b><?=st_e($missing['site_name'])?></b><br><?=st_e($missing['technician_name'])?></span><?php if($wa):?><a class="btn small wa-btn" target="_blank" href="<?=st_e($wa)?>">WhatsApp</a><?php endif;?></div><?php endforeach;?><?php if(!$dashMissingUpdates):?><p class="muted">Mafundi wote wana daily update ya leo.</p><?php endif;?></div>
        <div class="panel dash-panel"><h3>📚 Daily Updates</h3><a class="btn" href="?page=st_daily_reports">View all daily reports</a><p class="muted">Updates za leo na historia ya mafundi.</p></div>
    </div>
    <div class="dash-grid">
        <div class="panel dash-panel"><h3>🏆 Fundi Bora wa Mwezi</h3><?php if($bestMonth):?><h2 style="margin:8px 0"><?=st_e($bestMonth['name'])?></h2><p><?=st_e($bestMonth['office'])?></p><div class="status">Score: <?=st_e($bestMonth['score'])?>/100</div><p class="small">Sites kwa wakati: <?=$bestMonth['on_time']?> · Updates: <?=$bestMonth['updates']?> · Tasks: <?=$bestMonth['tasks']?> · Photos: <?=$bestMonth['photos']?> · Tools: <?=$bestMonth['tools']?></p><?php else:?><p class="muted">Hakuna data ya mwezi huu.</p><?php endif;?></div>
        <div class="panel dash-panel"><h3>🏅 Fundi Bora wa Mwaka</h3><?php if($bestYear):?><h2 style="margin:8px 0"><?=st_e($bestYear['name'])?></h2><p><?=st_e($bestYear['office'])?></p><div class="status">Score: <?=st_e($bestYear['score'])?>/100</div><p class="small">Sites kwa wakati: <?=$bestYear['on_time']?> · Updates: <?=$bestYear['updates']?> · Tasks: <?=$bestYear['tasks']?> · Photos: <?=$bestYear['photos']?> · Tools: <?=$bestYear['tools']?></p><?php else:?><p class="muted">Hakuna data ya mwaka huu.</p><?php endif;?></div>
    </div>
    <div class="panel"><h3>📊 Technician Performance Ranking</h3><p class="muted">Score: sites kwa wakati 40%, daily updates 25%, tasks 15%, photos/GPS 10%, tools zilizorudishwa kwa wakati 10%.</p><div class="table-wrap"><table><tr><th>#</th><th>Fundi</th><th>Office</th><th>Score</th><th>Sites</th><th>Updates</th><th>Tasks</th><th>Photos</th><th>Tools</th></tr><?php foreach($monthBest as $rank=>$performer):?><tr class="<?=($rank===0?'ok-row':'')?>"><td><?=($rank+1)?></td><td><?=st_e($performer['name'])?></td><td><?=st_e($performer['office'])?></td><td><b><?=st_e($performer['score'])?>/100</b></td><td><?=st_e($performer['on_time'])?></td><td><?=st_e($performer['updates'])?></td><td><?=st_e($performer['tasks'])?></td><td><?=st_e($performer['photos'])?></td><td><?=st_e($performer['tools'])?></td></tr><?php endforeach;?></table></div><?php if(!$monthBest):?><p class="muted">Hakuna technician performance data bado.</p><?php endif;?></div>
  <div class="panel"><h3>📈 Mafundi wenye tools ambazo hawajarudisha</h3>
  <?php foreach($graph as $g):?><div style="display:grid;grid-template-columns:150px 1fr 40px;gap:8px;align-items:center;margin:8px 0">
    <span><?=st_e($g['full_name'])?></span><div style="height:20px;background:#eee;border-radius:10px;overflow:hidden"><div style="height:100%;width:<?=round($g['total']/$mx*100)?>%;background:#2563eb"></div></div><b><?=$g['total']?></b>
  </div><?php endforeach;?><?php if(!$graph):?><p>Hakuna tools ambazo hazijarudishwa.</p><?php endif;?></div>

<?php elseif($page==='st_technicians'): ?>
  <h2>👷 <?=zan_t('Mafundi','Technicians')?></h2>
  <?php if($editTech):?>
  <div class="panel"><h3>✏️ Edit Technician</h3>
    <form method="post" enctype="multipart/form-data"><input type="hidden" name="st_action" value="st_save_technician"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="id" value="<?=$editTech['id']?>">
    <div class="grid2">
      <div class="field"><input name="technician_no" value="<?=st_e($editTech['technician_no'])?>" placeholder="Technician / Employee Number" required></div>
      <div class="field"><input name="full_name" value="<?=st_e($editTech['full_name'])?>" placeholder="Full name" required></div>
      <div class="field"><input name="username" value="<?=st_e($editTech['username']??'')?>" placeholder="Username" required></div>
      <div class="field"><input type="password" name="password" placeholder="New password (leave blank to keep current)"></div>
      <div class="field"><input name="phone" value="<?=st_e($editTech['phone'])?>" placeholder="Phone"></div>
      <div class="field"><input name="email" value="<?=st_e($editTech['email'])?>" placeholder="Email"></div>
      <div class="field"><textarea name="address" placeholder="Address"><?=st_e($editTech['address'])?></textarea></div>
    <div class="field"><input name="office" value="<?=st_e($editTech['office']??'')?>" placeholder="Office / Kituo cha kazi" required></div>
    <div class="field"><select name="gender"><option value="">Jinsia</option><option value="Mwanaume" <?=($editTech['gender']??'')==='Mwanaume'?'selected':''?>>Mwanaume</option><option value="Mwanamke" <?=($editTech['gender']??'')==='Mwanamke'?'selected':''?>>Mwanamke</option></select></div>
    <div class="field"><select name="marital_status"><option value="">Hali ya ndoa</option><option value="Ameoa" <?=($editTech['marital_status']??'')==='Ameoa'?'selected':''?>>Ameoa</option><option value="Ameolewa" <?=($editTech['marital_status']??'')==='Ameolewa'?'selected':''?>>Ameolewa</option><option value="Hajaoa" <?=($editTech['marital_status']??'')==='Hajaoa'?'selected':''?>>Hajaoa</option><option value="Hajaolewa" <?=($editTech['marital_status']??'')==='Hajaolewa'?'selected':''?>>Hajaolewa</option><option value="Talaka" <?=($editTech['marital_status']??'')==='Talaka'?'selected':''?>>Talaka</option><option value="Mjane" <?=($editTech['marital_status']??'')==='Mjane'?'selected':''?>>Mjane</option></select></div>
    <div class="field"><input type="number" name="children_count" min="0" step="1" value="<?=st_e($editTech['children_count']??0)?>" placeholder="Idadi ya watoto"></div>
      <div class="field"><input name="position" value="<?=st_e($editTech['position'])?>" placeholder="Position"></div>
      <div class="field"><input name="specialization" value="<?=st_e($editTech['specialization'])?>" placeholder="Specialization"></div>
      <div class="field"><input name="emergency_name" value="<?=st_e($editTech['emergency_name'])?>" placeholder="Emergency contact"></div>
      <div class="field"><input name="emergency_phone" value="<?=st_e($editTech['emergency_phone'])?>" placeholder="Emergency phone"></div>
      <div class="field"><select name="status"><option <?=($editTech['status']==='Active'?'selected':'')?>>Active</option><option <?=($editTech['status']==='Inactive'?'selected':'')?>>Inactive</option><option <?=($editTech['status']==='Suspended'?'selected':'')?>>Suspended</option></select></div>
      <div class="field">📷 Photo<input type="file" name="photo" accept="image/*" capture="environment"></div>
    </div><button class="btn">UPDATE TECHNICIAN</button> <a class="btn dark" href="?page=st_technicians">CANCEL</a>
  </form></div>
  <?php else:?>
    <div class="panel"><form method="post" enctype="multipart/form-data"><input type="hidden" name="st_action" value="st_save_technician"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>">
    <div class="grid2">
      <div class="field"><input name="technician_no" placeholder="Technician / Employee Number" required></div><div class="field"><input name="full_name" placeholder="Full name" required></div>
      <div class="field"><input name="username" placeholder="Username" required></div><div class="field"><input type="password" name="password" placeholder="Password" required></div>
      <div class="field"><input name="phone" placeholder="Phone"></div><div class="field"><input name="email" placeholder="Email"></div>
      <div class="field"><textarea name="address" placeholder="Address"></textarea></div><div class="field"><input name="position" placeholder="Position"></div>
    <div class="field"><input name="office" placeholder="Office / Kituo cha kazi" required></div>
    <div class="field"><select name="gender"><option value="">Jinsia</option><option value="Mwanaume">Mwanaume</option><option value="Mwanamke">Mwanamke</option></select></div>
    <div class="field"><select name="marital_status"><option value="">Hali ya ndoa</option><option value="Ameoa">Ameoa</option><option value="Ameolewa">Ameolewa</option><option value="Hajaoa">Hajaoa</option><option value="Hajaolewa">Hajaolewa</option><option value="Talaka">Talaka</option><option value="Mjane">Mjane</option></select></div>
    <div class="field"><input type="number" name="children_count" min="0" step="1" value="0" placeholder="Idadi ya watoto"></div>
      <div class="field"><input name="specialization" placeholder="Technical specialization"></div><div class="field"><input name="emergency_name" placeholder="Emergency contact name"></div>
      <div class="field"><input name="emergency_phone" placeholder="Emergency phone"></div>
      <div class="field">📷 Passport photo<input type="file" name="photo" accept="image/*" capture="environment"></div>
      <div class="field"><select name="status"><option>Active</option><option>Inactive</option><option>Suspended</option></select></div>
    </div><button class="btn">SAVE TECHNICIAN</button>
  </form></div><?php endif;?>

    <div class="panel table-wrap"><table><tr><th>Photo</th><th>Technician</th><th>Office</th><th>Jinsia</th><th>Ndoa</th><th>Watoto</th><th>Username</th><th>Phone</th><th>Status</th><th>Action</th></tr>
  <?php foreach($allTechs as $x):?><tr>
        <td><?=st_photo($x['photo']??'','passport')?></td><td><b><?=st_e($x['full_name'])?></b><div class="small"><?=st_e($x['technician_no'])?> · <?=st_e($x['specialization'])?></div></td>
        <td><?=st_e($x['office']??'')?></td><td><?=st_e($x['gender']??'')?></td><td><?=st_e($x['marital_status']??'')?></td><td><?=st_e($x['children_count']??0)?></td>
    <td><?=st_e($x['username']??'')?></td><td><?=st_e($x['phone'])?></td><td><?=st_e($x['status'])?></td>
    <td><div class="actions"><a class="btn small" href="?page=st_technicians&edit=<?=$x['id']?>">✏️ EDIT</a>
      <form method="post" onsubmit="return confirm('Delete this technician?')"><input type="hidden" name="st_action" value="st_delete_technician"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="btn danger small">🗑️ DELETE</button></form>
    </div></td>
  </tr><?php endforeach;?></table></div>

<?php elseif($page==='st_sites'): ?>
  <h2>🏗️ Sites / <?=zan_t('Wateja','Customers')?></h2>
  <?php if($editSite):?>
  <div class="panel"><h3>✏️ Edit Site / Customer</h3>
  <form method="post"><input type="hidden" name="st_action" value="st_edit_site"><input type="hidden" name="id" value="<?=$editSite['id']?>">
    <div class="grid2">
      <div class="field"><input name="site_name" value="<?=st_e($editSite['site_name'])?>" placeholder="Site / Project Name" required></div>
      <div class="field"><input name="customer_name" value="<?=st_e($editSite['customer_name'])?>" placeholder="Customer Name" required></div>
      <div class="field"><input name="location" value="<?=st_e($editSite['location'])?>" placeholder="Location"></div>
      <div class="field"><select name="technician_id"><option value="">Primary Technician</option><?php foreach($allTechs as $t):?><option value="<?=$t['id']?>" <?=((int)$editSite['technician_id']===(int)$t['id']?'selected':'')?>><?=st_e($t['full_name'])?></option><?php endforeach;?></select></div>
      <div class="field"><label>Other technicians on this site</label><select name="team_technicians[]" multiple><?php foreach($allTechs as $t):?><option value="<?=$t['id']?>" <?=in_array((int)$t['id'],$editSite['team'],true)?'selected':''?>><?=st_e($t['full_name'])?></option><?php endforeach;?></select></div>
      <div class="field"><input type="date" name="start_date" value="<?=st_e($editSite['start_date'])?>"></div><div class="field"><input type="date" name="due_date" value="<?=st_e($editSite['due_date'])?>"></div>
      <div class="field"><select name="status"><?php foreach(['Pending','Assigned','In Progress','Completed','Verified','Pending Admin Approval','Closed'] as $s):?><option <?=($editSite['status']===$s?'selected':'')?>><?=$s?></option><?php endforeach;?></select></div>
      <div class="field"><textarea name="notes" placeholder="Notes"><?=st_e($editSite['notes'])?></textarea></div>
    </div><button class="btn">UPDATE SITE</button> <a class="btn dark" href="?page=st_sites">CANCEL</a>
  </form></div>
  <?php else:?>
  <div class="panel"><form method="post"><input type="hidden" name="st_action" value="st_save_site"><div class="grid2">
    <div class="field"><input name="site_name" placeholder="Site / Project Name" required></div><div class="field"><input name="customer_name" placeholder="Customer Name" required></div>
    <div class="field"><input name="location" placeholder="Location"></div>
    <div class="field"><select name="technician_id"><option value="">Primary Technician</option><?php foreach($allTechs as $t):?><option value="<?=$t['id']?>"><?=st_e($t['full_name'])?></option><?php endforeach;?></select></div>
    <div class="field"><label>Other technicians on this site</label><select name="team_technicians[]" multiple><?php foreach($allTechs as $t):?><option value="<?=$t['id']?>"><?=st_e($t['full_name'])?></option><?php endforeach;?></select></div>
    <div class="field"><input type="date" name="start_date"></div><div class="field"><input type="date" name="due_date"></div>
    <div class="field"><select name="status"><option>Pending</option><option>Assigned</option><option>In Progress</option><option>Completed</option><option>Verified</option><option>Closed</option></select></div>
    <div class="field"><textarea name="notes" placeholder="Notes"></textarea></div>
  </div><button class="btn">SAVE SITE</button></form></div><?php endif;?>

    <div class="panel table-wrap"><table><tr><th>Site</th><th>Customer</th><th>Primary</th><th>Other Technicians</th><th>Status</th><th>Action</th></tr>
  <?php foreach($sites as $x):
    $team=st_site_team_ids($pdo,(int)$x['id']);
    $names=[];foreach($allTechs as $t)if(in_array((int)$t['id'],$team,true))$names[]=$t['full_name'];
  ?><tr><td><?=st_e($x['site_name'])?><div class="small"><?=st_e($x['location'])?></div></td><td><?=st_e($x['customer_name'])?></td>
    <td><?php foreach($allTechs as $t)if((int)$t['id']===(int)$x['technician_id'])echo st_e($t['full_name']);?></td>
    <td><?=st_e(implode(', ',$names))?></td><td><span class="status"><?=st_e($x['status'])?></span></td>
    <td><div class="actions"><a class="btn small" href="?page=st_sites&edit=<?=$x['id']?>">✏️ EDIT</a>
            <?php if(st_is_manager()||st_is_admin()):?><form method="post" class="actions"><input type="hidden" name="st_action" value="st_assign_site"><input type="hidden" name="site_id" value="<?=$x['id']?>"><select name="technician_id" required><option value="">Assign / Reassign fundi</option><?php foreach($allTechs as $t):?><option value="<?=$t['id']?>" <?=((int)$x['technician_id']===(int)$t['id']?'selected':'')?>><?=st_e($t['full_name'])?></option><?php endforeach;?></select><button class="btn small">ASSIGN</button></form><?php endif;?>
      <?php if($x['status']==='Pending Admin Approval'):?><form method="post"><input type="hidden" name="st_action" value="st_approve_site_completion"><input type="hidden" name="site_id" value="<?=$x['id']?>"><button class="btn small">APPROVE & CLOSE</button></form>
      <form method="post"><input type="hidden" name="st_action" value="st_reject_site_completion"><input type="hidden" name="site_id" value="<?=$x['id']?>"><input name="reason" placeholder="Reason" required><button class="btn danger small">REJECT</button></form><?php endif;?>
      <form method="post" onsubmit="return confirm('Delete this site?')"><input type="hidden" name="st_action" value="st_delete_site"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="btn danger small">🗑️ DELETE</button></form>
        </div></td></tr><?php endforeach;?></table></div>

    <?php $siteTasks=$pdo->query("SELECT t.*,s.site_name,tech.full_name AS assigned_name FROM st_site_tasks t JOIN st_sites s ON s.id=t.site_id LEFT JOIN st_technicians tech ON tech.id=t.assigned_to ORDER BY t.id DESC")->fetchAll(PDO::FETCH_ASSOC); ?>
    <div class="panel"><h3>✅ Tasks / Checklist</h3>
        <?php if(st_is_admin()):?><form method="post" class="grid2"><input type="hidden" name="st_action" value="st_save_site_task"><div class="field"><select name="site_id" required><option value="">Chagua Site</option><?php foreach($sites as $site):?><option value="<?=$site['id']?>"><?=st_e($site['site_name'])?></option><?php endforeach;?></select></div><div class="field"><input name="task_title" placeholder="Task / Checklist item" required></div><div class="field"><select name="assigned_to"><option value="">Assign technician (optional)</option><?php foreach($allTechs as $technician):?><option value="<?=$technician['id']?>"><?=st_e($technician['full_name'])?></option><?php endforeach;?></select></div><div class="field"><input name="notes" placeholder="Notes"></div><div><button class="btn">ADD TASK</button></div></form><?php endif;?>
        <div class="table-wrap"><table><tr><th>Site</th><th>Task</th><th>Assigned To</th><th>Status</th><th>Action</th></tr><?php foreach($siteTasks as $task):?><tr><td><?=st_e($task['site_name'])?></td><td><?=st_e($task['task_title'])?><div class="small"><?=st_e($task['notes'])?></div></td><td><?=st_e($task['assigned_name']??'Any assigned technician')?></td><td><span class="status"><?=st_e($task['status'])?></span></td><td><form method="post" style="display:inline"><input type="hidden" name="st_action" value="st_toggle_site_task"><input type="hidden" name="id" value="<?=$task['id']?>"><button class="btn small"><?=$task['status']==='Completed'?'REOPEN':'COMPLETE'?></button></form><?php if(st_is_admin()):?> <form method="post" style="display:inline" onsubmit="return confirm('Delete this task?')"><input type="hidden" name="st_action" value="st_delete_site_task"><input type="hidden" name="id" value="<?=$task['id']?>"><button class="btn danger small">DELETE</button></form><?php endif;?></td></tr><?php endforeach;?></table></div>
        <?php if(!$siteTasks):?><p class="muted">Hakuna tasks bado.</p><?php endif;?></div>

<?php elseif($page==='st_tools'): ?>
  <h2>🔧 Tools</h2>
  <?php if($editTool):?>
  <div class="panel"><h3>✏️ Edit Tool</h3><form method="post" enctype="multipart/form-data"><input type="hidden" name="st_action" value="st_edit_tool"><input type="hidden" name="id" value="<?=$editTool['id']?>">
    <div class="grid2"><div class="field"><input name="tool_code" value="<?=st_e($editTool['tool_code'])?>" required></div><div class="field"><input name="tool_name" value="<?=st_e($editTool['tool_name'])?>" required></div>
    <div class="field"><input name="serial_no" value="<?=st_e($editTool['serial_no'])?>"></div><div class="field"><input type="number" name="quantity" min="1" value="<?=st_e($editTool['quantity'])?>"></div>
    <div class="field"><select name="condition_status"><?php foreach(['Good','Damaged','Missing'] as $s):?><option <?=($editTool['condition_status']===$s?'selected':'')?>><?=$s?></option><?php endforeach;?></select></div>
    <div class="field"><input name="storage_location" value="<?=st_e($editTool['storage_location']??'')?>"></div><div class="field"><textarea name="notes"><?=st_e($editTool['notes'])?></textarea></div>
    <div class="field">📷 Replace photo<input type="file" name="tool_photo" accept="image/*" capture="environment"></div></div>
    <button class="btn">UPDATE TOOL</button> <a class="btn dark" href="?page=st_tools">CANCEL</a>
  </form></div>
  <?php else:?>
  <div class="panel"><form method="post" enctype="multipart/form-data"><input type="hidden" name="st_action" value="st_save_tool"><div class="grid2">
    <div class="field"><input name="tool_code" placeholder="Tool ID / Code" required></div><div class="field"><input name="tool_name" placeholder="Tool Name" required></div>
    <div class="field"><input name="serial_no" placeholder="Serial Number"></div><div class="field"><input type="number" name="quantity" value="1" min="1"></div>
    <div class="field"><select name="condition_status"><option>Good</option><option>Damaged</option><option>Missing</option></select></div>
    <div class="field">📷 Tool registration photo<input type="file" name="tool_photo" accept="image/*" capture="environment" required></div>
    <div class="field"><input type="date" name="purchase_date"></div><div class="field"><input type="number" step="0.01" name="value_amount" placeholder="Tool value"></div>
    <div class="field"><input name="storage_location" placeholder="Storage location"></div><div class="field"><textarea name="registration_notes" placeholder="Registration notes"></textarea></div>
    <div class="field"><textarea name="notes" placeholder="Notes"></textarea></div>
  </div><button class="btn">SAVE TOOL</button></form></div><?php endif;?>

  <div class="panel table-wrap"><table><tr><th>Photo</th><th>Tool</th><th>Serial</th><th>Qty</th><th>Condition</th><th>Status</th><th>Action</th></tr>
  <?php foreach($tools as $x):?><tr><td><?=st_photo($x['photo']??'')?></td><td><b><?=st_e($x['tool_name'])?></b><div class="small"><?=st_e($x['tool_code'])?></div></td><td><?=st_e($x['serial_no'])?></td><td><?=st_e($x['quantity'])?></td><td><?=st_e($x['condition_status'])?></td><td><?=st_e($x['status'])?></td>
    <td><div class="actions"><a class="btn small" href="?page=st_tools&edit=<?=$x['id']?>">✏️ EDIT</a><form method="post" onsubmit="return confirm('Delete this tool?')"><input type="hidden" name="st_action" value="st_delete_tool"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="btn danger small">🗑️ DELETE</button></form></div></td>
  </tr><?php endforeach;?></table></div>

<?php elseif($page==='st_materials'): ?>
  <h2>📦 Materials</h2>
  <?php if($editMaterial):?>
  <div class="panel"><h3>✏️ Edit Material</h3><form method="post"><input type="hidden" name="st_action" value="st_edit_material"><input type="hidden" name="id" value="<?=$editMaterial['id']?>">
    <div class="grid2"><div class="field"><input name="material_name" value="<?=st_e($editMaterial['material_name'])?>" required></div><div class="field"><input name="unit" value="<?=st_e($editMaterial['unit'])?>"></div>
    <div class="field"><input type="number" step="0.01" name="stock" value="<?=st_e($editMaterial['stock'])?>" required></div><div class="field"><input type="number" step="0.01" name="reorder_level" value="<?=st_e($editMaterial['reorder_level'])?>"></div>
    <div class="field"><textarea name="notes"><?=st_e($editMaterial['notes'])?></textarea></div></div>
    <button class="btn">UPDATE MATERIAL</button> <a class="btn dark" href="?page=st_materials">CANCEL</a>
  </form></div>
  <?php else:?>
  <div class="panel"><form method="post"><input type="hidden" name="st_action" value="st_save_material"><div class="grid2">
    <div class="field"><input name="material_name" placeholder="Material Name" required></div><div class="field"><input name="unit" value="pcs" placeholder="Unit"></div>
    <div class="field"><input type="number" step="0.01" name="stock" placeholder="Opening Stock" required></div><div class="field"><input type="number" step="0.01" name="reorder_level" placeholder="Reorder Level"></div>
    <div class="field"><textarea name="notes" placeholder="Notes"></textarea></div>
  </div><button class="btn">SAVE MATERIAL</button></form></div><?php endif;?>

  <div class="panel table-wrap"><table><tr><th>Material</th><th>Stock</th><th>Reorder Level</th><th>Unit</th><th>Action</th></tr>
  <?php foreach($materials as $x):?><tr><td><?=st_e($x['material_name'])?></td><td><b><?=st_e($x['stock'])?></b></td><td><?=st_e($x['reorder_level'])?></td><td><?=st_e($x['unit'])?></td>
    <td><div class="actions"><a class="btn small" href="?page=st_materials&edit=<?=$x['id']?>">✏️ EDIT</a><form method="post" onsubmit="return confirm('Delete this material?')"><input type="hidden" name="st_action" value="st_delete_material"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="btn danger small">🗑️ DELETE</button></form></div></td>
  </tr><?php endforeach;?></table></div>

<?php elseif($page==='st_material_report'): ?>
  <?php
  $report=$pdo->query("SELECT mm.*,m.material_name,m.unit,t.full_name AS technician_name,s.site_name,s.customer_name
                       FROM st_material_movements mm
                       JOIN st_materials m ON m.id=mm.material_id
                       JOIN st_technicians t ON t.id=mm.technician_id
                       LEFT JOIN st_sites s ON s.id=mm.site_id
                       ORDER BY mm.id DESC")->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <h2>📦 Material Report</h2>
  <div class="panel"><p class="muted">Ripoti hii inaonyesha material iliyochukuliwa, quantity, fundi, site, customer, mafundi wengine wa site, picha, tarehe na status.</p>
  <div class="table-wrap"><table><tr><th>Material</th><th>Aina</th><th>Qty</th><th>Fundi</th><th>Site</th><th>Customer</th><th>Mafundi wengine</th><th>Picha</th><th>GPS</th><th>Tarehe</th><th>Status</th></tr>
  <?php foreach($report as $r):
    $team=[];if(!empty($r['site_id'])){$ids=st_site_team_ids($pdo,(int)$r['site_id']);foreach($allTechs as $t)if(in_array((int)$t['id'],$ids,true) && (int)$t['id']!==(int)$r['technician_id'])$team[]=$t['full_name'];}
  ?><tr>
    <td><?=st_e($r['material_name'])?></td><td><?=st_e($r['movement_type']??'Issued')?></td><td><?=st_e($r['quantity'])?> <?=st_e($r['unit'])?></td><td><?=st_e($r['technician_name'])?></td>
    <td><?=st_e($r['site_name']??'')?></td><td><?=st_e($r['customer_name']??'')?></td><td><?=st_e(implode(', ',$team))?></td>
    <td><?=st_photo($r['photo']??'')?></td><td><?php if($r['latitude']!==null && $r['longitude']!==null):?><a class="btn small" target="_blank" href="https://www.openstreetmap.org/?mlat=<?=rawurlencode($r['latitude'])?>&mlon=<?=rawurlencode($r['longitude'])?>#map=18/<?=rawurlencode($r['latitude'])?>/<?=rawurlencode($r['longitude'])?>">🗺️ MAP</a><div class="small"><?=st_e($r['latitude'])?>, <?=st_e($r['longitude'])?></div><?php else:?>-<?php endif;?></td><td><?=st_e($r['created_at'])?></td><td><span class="status"><?=st_e($r['status'])?></span></td>
  </tr><?php endforeach;?>
  </table></div></div>

<?php elseif($page==='st_movements'): ?>
  <?php if(st_is_technician()):?>
    <h2>🔄 Tools / Materials</h2>
    <div class="panel">
      <h3>🔧 <?=zan_t('Kuchukua Tool','Receive Tool')?></h3>
      <form method="post" enctype="multipart/form-data" class="gps-form"><input type="hidden" name="st_action" value="st_issue_tool">
        <div class="field"><select name="tool_id" required><option value="">-- Tool --</option><?php foreach($tools as $x):?><option value="<?=$x['id']?>"><?=st_e($x['tool_name'].' - '.$x['tool_code'])?></option><?php endforeach;?></select></div>
        <div class="field"><select name="site_id" required><option value="">-- Site --</option><?php foreach($sites as $x):?><option value="<?=$x['id']?>"><?=st_e($x['site_name'])?></option><?php endforeach;?></select></div>
        <div class="field"><input type="date" name="due_date"></div><div class="field"><select name="condition_status"><option>Good</option><option>Damaged</option></select></div>
        <div class="field">📷 Photo<input type="file" name="photo" accept="image/*" capture="environment" required></div><input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><input type="hidden" name="captured_at" class="gps-time"><button type="button" class="btn gps-btn">📍 GPS</button> <span class="gps-status small">Location not captured yet</span><div class="field"><textarea name="notes"></textarea></div>
        <button class="btn">SUBMIT TOOL</button>
      </form>
    </div>
  <?php else:?>
    <h2>🔄 Tool & Material Movements</h2>
    <div class="grid2">
      <div class="panel"><h3>🔧 Issue / Return Tool</h3><form method="post" enctype="multipart/form-data" class="gps-form"><input type="hidden" name="st_action" value="st_issue_tool">
        <div class="field"><select name="tool_id" required><?php foreach($tools as $x):?><option value="<?=$x['id']?>"><?=st_e($x['tool_name'].' - '.$x['tool_code'])?></option><?php endforeach;?></select></div>
        <div class="field"><select name="technician_id" required><?php foreach($allTechs as $x):?><option value="<?=$x['id']?>"><?=st_e($x['full_name'])?></option><?php endforeach;?></select></div>
        <div class="field"><select name="site_id"><option value="">Select Site</option><?php foreach($sites as $x):?><option value="<?=$x['id']?>"><?=st_e($x['site_name'])?></option><?php endforeach;?></select></div>
        <div class="field"><input name="customer_name" placeholder="Customer Name"></div><div class="field"><input type="date" name="due_date"></div>
        <div class="field"><select name="condition_status"><option>Good</option><option>Damaged</option></select></div>
        <div class="field">📷 Photo<input type="file" name="photo" accept="image/*" capture="environment" required></div><input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><input type="hidden" name="captured_at" class="gps-time"><button type="button" class="btn gps-btn">📍 GPS</button> <span class="gps-status small">Location not captured yet</span><div class="field"><textarea name="notes"></textarea></div>
        <button class="btn">ISSUE TOOL</button></form>
        <hr><form method="post" enctype="multipart/form-data" class="gps-form"><input type="hidden" name="st_action" value="st_return_tool">
        <div class="field"><select name="tool_id"><?php foreach($tools as $x):?><option value="<?=$x['id']?>"><?=st_e($x['tool_name'])?></option><?php endforeach;?></select></div>
        <div class="field"><select name="technician_id"><?php foreach($allTechs as $x):?><option value="<?=$x['id']?>"><?=st_e($x['full_name'])?></option><?php endforeach;?></select></div>
        <div class="field"><select name="condition_status"><option>Good</option><option>Damaged</option><option>Missing</option></select></div>
        <div class="field">📷 Return Photo<input type="file" name="photo" accept="image/*" capture="environment" required></div><input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><input type="hidden" name="captured_at" class="gps-time"><button type="button" class="btn gps-btn">📍 GPS</button> <span class="gps-status small">Location not captured yet</span> <button class="btn">SUBMIT RETURN</button></form>
      </div>
      <div class="panel"><h3>📦 <?=zan_t('Meneja Anakabidhi Material','Manager Issues Material')?></h3>
        <div class="notice"><?=zan_t('Meneja anachagua material, fundi na site. Stock inapungua mara moja baada ya makabidhiano rasmi.','The Manager selects the material, technician and site. Stock is deducted immediately after official issue.')?></div>
        <form method="post" enctype="multipart/form-data" class="gps-form">
          <input type="hidden" name="st_action" value="st_issue_material">
          <div class="field"><select name="material_id" required><option value="">-- Material --</option><?php foreach($materials as $m):?><option value="<?=$m['id']?>"><?=st_e($m['material_name'].' ('.$m['stock'].' '.$m['unit'].')')?></option><?php endforeach;?></select></div>
          <div class="field"><select name="technician_id" required><option value="">-- Fundi --</option><?php foreach($allTechs as $t):?><option value="<?=$t['id']?>"><?=st_e($t['full_name'])?></option><?php endforeach;?></select></div>
          <div class="field"><select name="site_id" required><option value="">-- Site --</option><?php foreach($sites as $x):?><option value="<?=$x['id']?>"><?=st_e($x['site_name'].' - '.$x['customer_name'])?></option><?php endforeach;?></select></div>
          <div class="field"><input type="number" step="0.01" min="0.01" name="quantity" placeholder="<?=zan_t('Kiasi cha kutolewa','Quantity issued')?>" required></div>
          <div class="field"><textarea name="reason" placeholder="<?=zan_t('Maelezo ya makabidhiano','Issue notes')?>"></textarea></div>
          <div class="field">📷 <?=zan_t('Picha ya makabidhiano (optional)','Handover photo (optional)')?><input type="file" name="photo" accept="image/*" capture="environment"></div>
          <input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><input type="hidden" name="captured_at" class="gps-time">
          <button type="button" class="btn gps-btn">📍 <?=zan_t('Pata Location','Get Location')?></button> <span class="gps-status small"><?=zan_t('GPS ni optional kwa makabidhiano ya Meneja','GPS is optional for manager handover')?></span>
          <br><button class="btn" type="submit">📦 <?=zan_t('KABIDHI MATERIAL','ISSUE MATERIAL')?></button>
        </form>
      </div>
    </div>
        <div class="panel"><h3>💰 <?=zan_t('Meneja Anampa Fundi Pesa ya Material','Manager Grants Material Purchase Funds')?></h3>
            <form method="post" class="grid2"><input type="hidden" name="st_action" value="st_grant_material_fund">
                <div class="field"><select name="technician_id" required><option value="">-- Fundi --</option><?php foreach($allTechs as $t):?><option value="<?=$t['id']?>"><?=st_e($t['full_name'])?></option><?php endforeach;?></select></div>
                <div class="field"><select name="site_id" required><option value="">-- Site --</option><?php foreach($sites as $x):?><option value="<?=$x['id']?>"><?=st_e($x['site_name'].' - '.$x['customer_name'])?></option><?php endforeach;?></select></div>
                <div class="field"><input type="number" step="0.01" min="0.01" name="amount" placeholder="Kiasi cha pesa" required></div>
                <div class="field"><input name="currency" value="TZS" placeholder="Currency" required></div>
                <div class="field"><textarea name="purpose" placeholder="Material gani itanunuliwa?" required></textarea></div>
                <div><button class="btn">💰 PEANA PESA</button></div>
            </form>
        </div>
        <?php $materialFunds=$pdo->query("SELECT f.*,t.full_name AS technician_name,s.site_name,s.customer_name FROM st_material_funds f JOIN st_technicians t ON t.id=f.technician_id JOIN st_sites s ON s.id=f.site_id ORDER BY f.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC); ?>
        <div class="panel"><h3>📋 <?=zan_t('History ya Pesa na Risiti za Mafundi','Technician Material Funds and Receipts History')?></h3><div class="table-wrap"><table>
            <tr><th>Tarehe</th><th>Fundi</th><th>Site</th><th>Kiasi</th><th>Sababu</th><th>Status</th><th>Risiti</th><th>GPS</th></tr>
            <?php foreach($materialFunds as $fund):?><tr><td><?=st_e($fund['granted_at']??$fund['created_at'])?></td><td><?=st_e($fund['technician_name'])?></td><td><?=st_e($fund['site_name'])?></td><td><b><?=st_e($fund['amount'].' '.$fund['currency'])?></b></td><td><?=st_e($fund['purpose']??'')?></td><td><span class="status"><?=st_e($fund['status'])?></span></td><td><?=!empty($fund['receipt_photo'])?st_photo($fund['receipt_photo']):'Haijatumwa'?></td><td><?php if($fund['receipt_latitude']!==null&&$fund['receipt_longitude']!==null):?><a class="btn small" target="_blank" href="https://www.openstreetmap.org/?mlat=<?=rawurlencode($fund['receipt_latitude'])?>&mlon=<?=rawurlencode($fund['receipt_longitude'])?>#map=18/<?=rawurlencode($fund['receipt_latitude'])?>/<?=rawurlencode($fund['receipt_longitude'])?>">MAP</a><?php else:?>-<?php endif;?></td></tr><?php endforeach;?>
        </table></div><?php if(!$materialFunds):?><p class="muted">Hakuna history ya pesa bado.</p><?php endif;?></div>
  <?php endif;?>

<?php elseif($page==='st_site_gallery'): ?>
  <?php $gallerySql="SELECT p.*,s.site_name,s.customer_name,t.full_name AS technician_name FROM st_site_photos p LEFT JOIN st_sites s ON s.id=p.site_id LEFT JOIN st_technicians t ON t.id=p.technician_id";if(st_is_technician())$gallerySql.=" JOIN st_site_technicians ast ON ast.site_id=p.site_id AND ast.technician_id=".(int)$ct['id'];$gallerySql.=" ORDER BY p.id DESC";$gallery=$pdo->query($gallerySql)->fetchAll(PDO::FETCH_ASSOC); ?>
  <h2>📷 <?=zan_t('Picha za Sites','Site Photos')?></h2><div class="panel"><p class="muted"><?=zan_t('Kila picha inaonyesha site, fundi, tarehe, GPS na usahihi wa GPS.','Every photo shows the site, technician, date, GPS and GPS accuracy.')?></p><div class="photo-grid">
  <?php foreach($gallery as $g):?><div class="photo-card"><?=st_photo($g['photo']??'','gallery-photo')?><h3><?=st_e($g['category'])?></h3><b><?=st_e($g['site_name']??'')?></b><div class="small">👷 <?=st_e($g['technician_name']??'')?></div><div class="small">🕒 <?=st_e($g['captured_at']??$g['created_at'])?></div><div class="small">📍 <?=st_e($g['latitude'])?>, <?=st_e($g['longitude'])?></div><div class="small">🎯 ±<?=st_e($g['accuracy']??'')?> m</div><?php if(!empty($g['caption'])):?><p><?=st_e($g['caption'])?></p><?php endif;?><?php if($g['latitude']!==null&&$g['longitude']!==null):?><a class="btn small" target="_blank" href="https://www.openstreetmap.org/?mlat=<?=rawurlencode($g['latitude'])?>&mlon=<?=rawurlencode($g['longitude'])?>#map=18/<?=rawurlencode($g['latitude'])?>/<?=rawurlencode($g['longitude'])?>">🗺️ <?=zan_t('Fungua Map','Open Map')?></a><?php endif;?></div><?php endforeach;?><?php if(!$gallery):?><p><?=zan_t('Hakuna picha za site bado.','No site photos yet.')?></p><?php endif;?></div></div>

<?php elseif($page==='st_approvals'): ?>
  <h2>✅ Approvals</h2>
    <div class="panel"><h3>🧰 Tool Requests kutoka kwa Mafundi</h3><div class="table-wrap"><table><tr><th>Fundi</th><th>Site</th><th>Tool</th><th>Sababu</th><th>Tarehe</th><th>Action</th></tr><?php foreach($pdo->query("SELECT r.*,n.full_name,s.site_name,t.tool_name FROM st_tool_requests r JOIN st_technicians n ON n.id=r.technician_id JOIN st_sites s ON s.id=r.site_id JOIN st_tools t ON t.id=r.tool_id WHERE r.status='Pending' ORDER BY r.id DESC")->fetchAll(PDO::FETCH_ASSOC) as $request):?><tr><td><?=st_e($request['full_name'])?></td><td><?=st_e($request['site_name'])?></td><td><?=st_e($request['tool_name'])?></td><td><?=st_e($request['reason'])?></td><td><?=st_e($request['created_at'])?></td><td><form method="post" style="display:inline"><input type="hidden" name="st_action" value="st_review_tool_request"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="id" value="<?=$request['id']?>"><input type="hidden" name="decision" value="approve"><button class="btn small">APPROVE & ISSUE</button></form><form method="post" style="display:inline"><input type="hidden" name="st_action" value="st_review_tool_request"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="id" value="<?=$request['id']?>"><input type="hidden" name="decision" value="reject"><button class="btn danger small">REJECT</button></form></td></tr><?php endforeach;?></table></div></div>
    <div class="panel"><h3>📦 Material Requests kutoka kwa Mafundi</h3><div class="table-wrap"><table><tr><th>Fundi</th><th>Site</th><th>Material</th><th>Qty</th><th>Sababu</th><th>Action</th></tr><?php foreach($pdo->query("SELECT r.*,n.full_name,s.site_name,m.material_name,m.unit FROM st_material_requests r JOIN st_technicians n ON n.id=r.technician_id JOIN st_sites s ON s.id=r.site_id JOIN st_materials m ON m.id=r.material_id WHERE r.status='Pending' ORDER BY r.id DESC")->fetchAll(PDO::FETCH_ASSOC) as $request):?><tr><td><?=st_e($request['full_name'])?></td><td><?=st_e($request['site_name'])?></td><td><?=st_e($request['material_name'])?></td><td><?=st_e($request['quantity'].' '.$request['unit'])?></td><td><?=st_e($request['reason'])?></td><td><form method="post" style="display:inline"><input type="hidden" name="st_action" value="st_review_material_request"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="id" value="<?=$request['id']?>"><input type="hidden" name="decision" value="approve"><button class="btn small">APPROVE & ISSUE</button></form><form method="post" style="display:inline"><input type="hidden" name="st_action" value="st_review_material_request"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="id" value="<?=$request['id']?>"><input type="hidden" name="decision" value="reject"><button class="btn danger small">REJECT</button></form></td></tr><?php endforeach;?></table></div></div>
  <div class="panel"><h3>🔧 Tool records</h3><div class="table-wrap"><table><tr><th>Tool</th><th>Technician</th><th>Site</th><th>Type</th><th>Photo</th><th>Manager</th><th>Technical</th><th>Action</th></tr>
  <?php foreach($pdo->query("SELECT m.*,t.tool_name,n.full_name,s.site_name FROM st_tool_movements m JOIN st_tools t ON t.id=m.tool_id JOIN st_technicians n ON n.id=m.technician_id LEFT JOIN st_sites s ON s.id=m.site_id ORDER BY m.id DESC")->fetchAll(PDO::FETCH_ASSOC) as $x):?><tr>
    <td><?=st_e($x['tool_name'])?></td><td><?=st_e($x['full_name'])?></td><td><?=st_e($x['site_name']??'')?></td><td><?=st_e($x['action_type'])?></td><td><?=st_photo($x['photo']??'')?></td>
    <td><?=$x['manager_approved']?'✅':'⏳'?></td><td><?=$x['technical_approved']?'✅':'⏳'?></td>
    <td><?php foreach(['manager'=>'Manager','technical'=>'Technical Manager'] as $k=>$v):if(!$x[$k.'_approved']):?><form method="post" style="display:inline"><input type="hidden" name="st_action" value="st_approve_tool"><input type="hidden" name="id" value="<?=$x['id']?>"><input type="hidden" name="stage" value="<?=$k?>"><button class="btn small"><?=$v?> Approve</button></form><?php endif;endforeach;?></td>
  </tr><?php endforeach;?></table></div></div>

  <div class="panel"><h3>📦 Material records</h3><div class="table-wrap"><table><tr><th>Material</th><th>Technician</th><th>Site</th><th>Qty</th><th>Photo</th><th>Status</th><th>Manager</th><th>Technical</th><th>Action</th></tr>
  <?php foreach($pdo->query("SELECT m.*,a.material_name,n.full_name,s.site_name FROM st_material_movements m JOIN st_materials a ON a.id=m.material_id JOIN st_technicians n ON n.id=m.technician_id LEFT JOIN st_sites s ON s.id=m.site_id ORDER BY m.id DESC")->fetchAll(PDO::FETCH_ASSOC) as $x):?><tr>
    <td><?=st_e($x['material_name'])?></td><td><?=st_e($x['full_name'])?></td><td><?=st_e($x['site_name']??'')?></td><td><?=st_e($x['quantity'])?></td><td><?=st_photo($x['photo']??'')?></td><td><?=st_e($x['status'])?></td>
    <td><?=$x['manager_approved']?'✅':'⏳'?></td><td><?=$x['technical_approved']?'✅':'⏳'?></td>
        <td><?php foreach(['manager'=>'Manager','technical'=>'Technical Manager'] as $k=>$v):if(!$x[$k.'_approved']):?><form method="post" style="display:inline"><input type="hidden" name="st_action" value="st_approve_material"><input type="hidden" name="id" value="<?=$x['id']?>"><input type="hidden" name="stage" value="<?=$k?>"><button class="btn small"><?=$v?> Approve</button></form><?php endif;endforeach;?></td>
    </tr><?php endforeach;?></table></div></div>

<?php elseif($page==='st_reports'): ?>
    <?php
    $reportSites=(int)$pdo->query('SELECT COUNT(*) FROM st_sites')->fetchColumn();
    $reportClosed=(int)$pdo->query("SELECT COUNT(*) FROM st_sites WHERE status='Closed'")->fetchColumn();
    $reportTools=(int)$pdo->query('SELECT COUNT(*) FROM st_tools')->fetchColumn();
    $reportMaterials=(int)$pdo->query('SELECT COUNT(*) FROM st_materials')->fetchColumn();
    $overdueSites=$pdo->query("SELECT * FROM st_sites WHERE due_date IS NOT NULL AND due_date<>'' AND due_date < date('now') AND COALESCE(status,'')<>'Closed' ORDER BY due_date")->fetchAll(PDO::FETCH_ASSOC);
    $techReport=$pdo->query("SELECT t.full_name,COUNT(DISTINCT st.site_id) AS sites_count,COUNT(DISTINCT tm.id) AS tool_movements FROM st_technicians t LEFT JOIN st_site_technicians st ON st.technician_id=t.id LEFT JOIN st_tool_movements tm ON tm.technician_id=t.id GROUP BY t.id ORDER BY t.full_name")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <h2>📊 Reports</h2>
    <div class="cards"><div class="card"><b><?=$reportSites?></b><br>Sites</div><div class="card"><b><?=$reportClosed?></b><br>Completed Sites</div><div class="card"><b><?=$reportTools?></b><br>Tools</div><div class="card"><b><?=$reportMaterials?></b><br>Materials</div></div>
    <div class="panel report-card"><h3>⚠️ Overdue Sites</h3><div class="table-wrap"><table><tr><th>Site</th><th>Customer</th><th>Due Date</th><th>Status</th></tr><?php foreach($overdueSites as $site):?><tr><td><?=st_e($site['site_name'])?></td><td><?=st_e($site['customer_name'])?></td><td><?=st_e($site['due_date'])?></td><td><?=st_e($site['status'])?></td></tr><?php endforeach;?></table></div><?php if(!$overdueSites):?><p class="muted">Hakuna sites zilizochelewa.</p><?php endif;?></div>
    <div class="panel"><h3>👷 Technician Report</h3><div class="table-wrap"><table><tr><th>Technician</th><th>Assigned Sites</th><th>Tool Movements</th></tr><?php foreach($techReport as $technician):?><tr><td><?=st_e($technician['full_name'])?></td><td><?=st_e($technician['sites_count'])?></td><td><?=st_e($technician['tool_movements'])?></td></tr><?php endforeach;?></table></div></div>

<?php elseif($page==='st_activity'): ?>
    <?php $auditRows=$pdo->query('SELECT * FROM st_audit_log ORDER BY id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC); ?>
    <h2>📝 Activity / Audit History</h2>
    <div class="panel"><div class="table-wrap"><table><tr><th>Date</th><th>Entity</th><th>Action</th><th>Details</th><th>Performed By</th></tr><?php foreach($auditRows as $audit):?><tr><td><?=st_e($audit['created_at'])?></td><td><?=st_e($audit['entity_type'])?> #<?=st_e($audit['entity_id'])?></td><td><?=st_e($audit['action'])?></td><td><?=st_e($audit['details'])?></td><td><?=st_e($audit['performed_by'])?></td></tr><?php endforeach;?></table></div><?php if(!$auditRows):?><p class="muted">Hakuna activity bado.</p><?php endif;?></div>

<?php elseif($page==='st_daily_reports'): ?>
    <?php $dailyRows=$pdo->query("SELECT u.*,s.site_name,t.full_name technician_name,t.phone FROM st_daily_updates u JOIN st_sites s ON s.id=u.site_id JOIN st_technicians t ON t.id=u.technician_id ORDER BY u.update_date DESC,u.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC); ?>
    <h2>📚 Daily Technician Reports</h2>
    <div class="panel"><div class="table-wrap"><table><tr><th>Date</th><th>Technician</th><th>Site</th><th>Work Done</th><th>Blockers</th><th>Next Steps</th><th>GPS</th></tr><?php foreach($dailyRows as $daily):?><tr><td><?=st_e($daily['update_date'])?></td><td><?=st_e($daily['technician_name'])?></td><td><?=st_e($daily['site_name'])?></td><td><?=nl2br(st_e($daily['work_done']))?></td><td><?=nl2br(st_e($daily['blockers']))?></td><td><?=nl2br(st_e($daily['next_steps']))?></td><td><?php if($daily['latitude']!==null&&$daily['longitude']!==null):?><a class="btn small" target="_blank" href="https://www.openstreetmap.org/?mlat=<?=rawurlencode($daily['latitude'])?>&mlon=<?=rawurlencode($daily['longitude'])?>#map=18/<?=rawurlencode($daily['latitude'])?>/<?=rawurlencode($daily['longitude'])?>">MAP</a><?php else:?>-<?php endif;?></td></tr><?php endforeach;?></table></div><?php if(!$dailyRows):?><p class="muted">Hakuna daily reports bado.</p><?php endif;?></div>

<?php elseif($page==='st_administration'): ?>
  <h2>🏢 Administration</h2>
    <div class="panel"><h3>Site Administrators</h3><?php $editAdmin=null;$editAdminPermissions=[];if(isset($_GET['edit'])){$q=$pdo->prepare('SELECT * FROM st_site_admins WHERE id=?');$q->execute([(int)$_GET['edit']]);$editAdmin=$q->fetch(PDO::FETCH_ASSOC);$editAdminPermissions=json_decode($editAdmin['permissions']??'{}',true);if(!is_array($editAdminPermissions))$editAdminPermissions=[];} ?>
    <form method="post" enctype="multipart/form-data"><input type="hidden" name="st_action" value="<?=$editAdmin?'st_edit_site_admin':'st_save_site_admin'?>"><input type="hidden" name="id" value="<?=$editAdmin['id']??0?>">
        <div class="grid2"><div class="field"><input name="username" placeholder="Username" value="<?=st_e($editAdmin['username']??'')?>" required></div><div class="field"><input name="full_name" placeholder="Full name" value="<?=st_e($editAdmin['full_name']??'')?>" required></div>
        <div class="field"><input name="password" type="password" placeholder="<?=$editAdmin?'New password (optional)':'Password'?>" <?=$editAdmin?'':'required'?>></div><div class="field"><select name="admin_type"><option <?=($editAdmin['admin_type']??'')==='Manager'?'selected':''?>>Manager</option><option <?=($editAdmin['admin_type']??'')==='Technical Manager'?'selected':''?>>Technical Manager</option></select></div>
        <div class="field"><input name="office" placeholder="Office / Kituo cha kazi" value="<?=st_e($editAdmin['office']??'')?>" required></div><div class="field"><input name="passport_photo" type="file" accept="image/jpeg,image/png,image/webp" <?=$editAdmin?'':'required'?>></div><div class="field"><input name="phone" placeholder="Phone" value="<?=st_e($editAdmin['phone']??'')?>"></div><div class="field"><input name="email" placeholder="Email" value="<?=st_e($editAdmin['email']??'')?>"></div></div>
        <div class="panel"><b>Ruhusa za kuona Site Tracking</b><div class="grid2"><?php foreach(['dashboard'=>'Dashboard','technicians'=>'Mafundi','sites'=>'Sites','tools'=>'Tools','materials'=>'Materials','photos'=>'Photos + GPS','movements'=>'Movements','approvals'=>'Approvals','reports'=>'Reports','daily_reports'=>'Daily Reports','activity'=>'Activity History','administration'=>'Administration'] as $permissionKey=>$permissionLabel):?><label><input type="checkbox" name="perm_<?=$permissionKey?>" value="1" <?=($editAdmin&&(!empty($editAdminPermissions[$permissionKey])||empty($editAdmin['permissions'])))?'checked':''?> style="width:auto"> <?=st_e($permissionLabel)?></label><?php endforeach;?></div></div>
        <div class="field"><textarea name="whatsapp_template" rows="3" placeholder="Ujumbe wa WhatsApp wa reminder"><?=st_e($editAdmin['whatsapp_template']??'Habari {technician}, tafadhali tuma daily update ya site {site}.')?></textarea><small>Tumia: {technician}, {site}, {days} na {due_date}</small></div>
        <?php if(!empty($editAdmin['passport_photo'])):?><p><img src="<?=st_e($editAdmin['passport_photo'])?>" alt="Passport photo" style="width:80px;height:100px;object-fit:cover;border:1px solid #ddd;border-radius:6px"></p><?php endif;?>
    <button class="btn"><?=$editAdmin?'✏️ UPDATE ADMINISTRATOR':'SAVE ADMINISTRATOR'?></button> <?php if($editAdmin):?><a class="btn" href="?page=st_administration">CANCEL</a><?php endif;?></form>
    <div class="table-wrap"><table><tr><th>Picha</th><th>Username</th><th>Name</th><th>Type</th><th>Office</th><th>Phone</th><th>Action</th></tr><?php foreach($pdo->query('SELECT * FROM st_site_admins ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC) as $ad):?><tr><td><?php if(!empty($ad['passport_photo'])):?><img src="<?=st_e($ad['passport_photo'])?>" alt="Passport photo" style="width:55px;height:70px;object-fit:cover;border:1px solid #ddd;border-radius:5px"><?php endif;?></td><td><?=st_e($ad['username'])?></td><td><?=st_e($ad['full_name'])?></td><td><?=st_e($ad['admin_type'])?></td><td><?=st_e($ad['office']??'')?></td><td><?=st_e($ad['phone'])?></td><td><a class="btn small" href="?page=st_administration&edit=<?=$ad['id']?>">✏️ HARIRI</a> <form method="post" style="display:inline" onsubmit="return confirm('Una uhakika unataka kufuta administrator huyu?');"><input type="hidden" name="st_action" value="st_delete_site_admin"><input type="hidden" name="id" value="<?=$ad['id']?>"><button class="btn small danger" type="submit">🗑️ FUTA</button></form></td></tr><?php endforeach;?></table></div></div>
<?php endif;?>

</main></div><script>
document.addEventListener('DOMContentLoaded',function(){const token=<?=json_encode(st_csrf_token())?>;document.querySelectorAll('form').forEach(function(form){if(form.querySelector('input[name="st_action"]')&&!form.querySelector('input[name="st_csrf"])){const input=document.createElement('input');input.type='hidden';input.name='st_csrf';input.value=token;form.appendChild(input);}});});
document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('input[type=file][capture]').forEach(function(i){i.setAttribute('accept','image/*');i.setAttribute('capture','environment');});function gps(form,status){if(!navigator.geolocation){status.textContent='GPS haipatikani kwenye kifaa hiki.';status.className='gps-status gps-error';return;}status.textContent='Inatafuta location...';navigator.geolocation.getCurrentPosition(function(p){var c=p.coords;form.querySelector('.gps-lat').value=c.latitude;form.querySelector('.gps-lng').value=c.longitude;form.querySelector('.gps-accuracy').value=c.accuracy||'';form.querySelector('.gps-time').value=new Date().toISOString();status.textContent='✓ GPS imepatikana ±'+Math.round(c.accuracy||0)+' m';status.className='gps-status gps-ready';},function(){status.textContent='GPS haikupatikana. Washa Location kwenye simu na ujaribu tena.';status.className='gps-status gps-error';},{enableHighAccuracy:true,timeout:15000,maximumAge:0});}document.querySelectorAll('.gps-form').forEach(function(form){var b=form.querySelector('.gps-btn'),st=form.querySelector('.gps-status');if(b)b.addEventListener('click',function(){gps(form,st);});form.addEventListener('submit',function(e){var lat=form.querySelector('.gps-lat');if(lat&&!lat.value){e.preventDefault();if(st){st.textContent='Bonyeza Pata Location kwanza.';st.className='gps-status gps-error';}}});});});
</script><script>(function(){const lang=document.documentElement.lang||'sw';const Msw={'Dashboard':'Dashibodi','Technicians':'Mafundi','Customers':'Wateja','Materials':'Vifaa','Material Report':'Ripoti za Material','Site Photos':'Picha za Sites','Approvals':'Uidhinishaji','Administration':'Usimamizi','Logout':'Toka','Switch System':'Badilisha Mfumo','Technician Portal':'Portal ya Fundi','Assigned Sites':'Sites Nilizopewa','My Sites':'Sites Zangu','My Tool Records':'Rekodi za Tools Zangu','Status':'Hali','Action':'Kitendo','Date':'Tarehe','Quantity':'Kiasi','Customer':'Mteja','Team':'Timu','Good':'Nzuri','Damaged':'Imeharibika','Missing':'Imepotea','Available':'Ipo','Issued':'Imetolewa','Returned':'Imerejeshwa','Pending':'Inasubiri','Approved':'Imeidhinishwa','Rejected':'Imekataliwa','Completed':'Imekamilika','In Progress':'Inaendelea','Closed':'Imefungwa','Assigned':'Imepewa Fundi','Verified':'Imethibitishwa','Before Work':'Kabla ya Kazi','Work Progress':'Kazi Inaendelea','Material Received':'Material Imepokelewa','Material Used':'Material Imetumika','Completed Work':'Kazi Imekamilika','Problem / Damage':'Tatizo / Uharibifu','Site Arrival':'Kufika Site','Site Exit':'Kuondoka Site','Customer Handover':'Makabidhiano kwa Mteja','Open Map':'Fungua Map','Get Location':'Pata Location','EDIT':'HARIRI','DELETE':'FUTA','CANCEL':'GHAIRI'};const Men={};Object.keys(Msw).forEach(k=>Men[Msw[k]]=k);const M=lang==='sw'?Msw:Men;document.querySelectorAll('body *').forEach(function(e){if(e.children.length===0){let t=e.textContent.trim();if(M[t])e.textContent=M[t];}if(e.placeholder&&M[e.placeholder])e.placeholder=M[e.placeholder];});})();</script></body></html><?php exit; }

if ($page === 'choose_system' && isset($_SESSION['user'])):
    $chooseRole = strtolower(trim($_SESSION['user']['role'] ?? ''));
    $choosePerms = json_decode($_SESSION['user']['permissions'] ?? '{}', true);
    if (!is_array($choosePerms)) $choosePerms = [];
    $chooseSuperAdmin = in_array($chooseRole, ['super admin', 'super_admin', 'superadmin'], true);
    $chooseBusiness = $chooseSuperAdmin || in_array($chooseRole, ['admin', 'msimamizi'], true) || !empty($choosePerms['business_system']);
    $chooseSite = $chooseSuperAdmin || !empty($choosePerms['site_tracking_system']);
?>
<!doctype html>
<html lang="sw">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Chagua Mfumo - Zantronix</title>
<link rel="stylesheet" href="style.css">
<style>
.system-choice{max-width:760px;margin:70px auto;padding:28px;background:#fff;border-radius:14px;text-align:center}
.system-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:28px}
.system-card{display:block;padding:28px 18px;border:2px solid #111;border-radius:12px;color:#111;text-decoration:none;background:#ffd000;font-size:20px;font-weight:800}
.system-card.site{background:#111;color:#ffd000}
.system-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.18)}
@media(max-width:600px){.system-choice{margin:25px 14px}.system-options{grid-template-columns:1fr}}
</style>
</head>
<body style="background:#f4f4f4">
<main class="system-choice">
<h1>⚡ ZANTRONIX</h1>
<h2>Chagua Mfumo</h2>
<p>Chagua sehemu unayotaka kutumia.</p>
<div class="system-options">
<?php if ($chooseBusiness): ?><a class="system-card" href="?page=dashboard&system=business">Business System</a><?php endif; ?>
<?php if ($chooseSite): ?><a class="system-card site" href="?page=site_tracking">Site Tracking System</a><?php endif; ?>
</div>
<p style="margin-top:26px"><a href="?logout=1">Toka</a></p>
</main>
</body>
</html>
<?php exit; endif; ?>

<?php
/* =========================================================
   RESTORE BACKUP
   ========================================================= */

if (
    $page === 'settings' &&
    $action === 'restore_backup' &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    if (!can_do('settings')) {
        exit('Huna ruhusa ya kufanya restore.');
    }

    if (
        !isset($_FILES['backup_zip']) ||
        $_FILES['backup_zip']['error'] !== UPLOAD_ERR_OK
    ) {
        exit('Tafadhali chagua backup ya ZIP.');
    }

    $file = $_FILES['backup_zip'];

    $ext = strtolower(
        pathinfo($file['name'], PATHINFO_EXTENSION)
    );

    if ($ext !== 'zip') {
        exit('Backup lazima iwe faili la .zip');
    }

    if (!class_exists('ZipArchive')) {
        exit(
            'ZipArchive haijawezeshwa kwenye PHP.'
        );
    }

    $dbFile =
        __DIR__ . '/db/zantronix.sqlite';

    $backupDir =
        __DIR__ . '/backups';

    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0777, true);
    }

    /*
    ---------------------------------------------------------
    BACKUP YA DATABASE YA SASA KABLA YA RESTORE
    ---------------------------------------------------------
    */

    $safeBackup = '';

    if (file_exists($dbFile)) {

        $safeBackup =
            $backupDir .
            '/Before_Restore_' .
            date('Y-m-d_H-i-s') .
            '.sqlite';

        copy(
            $dbFile,
            $safeBackup
        );
    }

    /*
    ---------------------------------------------------------
    FUNGUA ZIP
    ---------------------------------------------------------
    */

    $zip = new ZipArchive();

    if (
        $zip->open($file['tmp_name']) !== true
    ) {

        exit(
            'Backup ZIP haiwezi kufunguliwa.'
        );
    }

    /*
    ---------------------------------------------------------
    HAKIKISHA DATABASE IPO KWENYE ZIP
    ---------------------------------------------------------
    */

    $dbIndex = $zip->locateName(
        'db/zantronix.sqlite'
    );

    if ($dbIndex === false) {

        $zip->close();

        exit(
            'Backup hii si backup halali ya Zantronix.'
        );
    }

    /*
    ---------------------------------------------------------
    TEMPORARY FOLDER
    ---------------------------------------------------------
    */

    $tempDir =
        __DIR__ .
        '/restore_temp_' .
        time();

    if (!mkdir($tempDir, 0777, true)) {

        $zip->close();

        exit(
            'Imeshindikana kutengeneza folder ya muda.'
        );
    }

    /*
    ---------------------------------------------------------
    EXTRACT ZIP
    ---------------------------------------------------------
    */

    if (!$zip->extractTo($tempDir)) {

        $zip->close();

        exit(
            'Imeshindikana kufungua backup.'
        );
    }

    $zip->close();

    /*
    ---------------------------------------------------------
    RESTORED DATABASE
    ---------------------------------------------------------
    */

    $restoredDb =
        $tempDir .
        '/db/zantronix.sqlite';

    if (!file_exists($restoredDb)) {

        exit(
            'Database haikupatikana ndani ya backup.'
        );
    }

    /*
    ---------------------------------------------------------
    CLOSE DATABASE CONNECTION
    ---------------------------------------------------------
    */

    $pdo = null;

    /*
    ---------------------------------------------------------
    COPY RESTORED DATABASE
    ---------------------------------------------------------
    */

    if (!copy($restoredDb, $dbFile)) {

        exit(
            'Database imeshindikana kurejeshwa.'
        );
    }

    /*
    ---------------------------------------------------------
    RESTORE UPLOADS
    ---------------------------------------------------------
    */

    $restoredUploads =
        $tempDir . '/uploads';

    $uploadDir =
        __DIR__ . '/uploads';

    if (is_dir($restoredUploads)) {

        if (!is_dir($uploadDir)) {

            mkdir(
                $uploadDir,
                0777,
                true
            );
        }

        $iterator =
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $restoredUploads,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::SELF_FIRST
            );

        foreach ($iterator as $item) {

            $relative =
                substr(
                    $item->getPathname(),
                    strlen($restoredUploads) + 1
                );

            $destination =
                $uploadDir .
                '/' .
                $relative;

            if ($item->isDir()) {

                if (!is_dir($destination)) {

                    mkdir(
                        $destination,
                        0777,
                        true
                    );
                }

            } else {

                $parent =
                    dirname($destination);

                if (!is_dir($parent)) {

                    mkdir(
                        $parent,
                        0777,
                        true
                    );
                }

                copy(
                    $item->getPathname(),
                    $destination
                );
            }
        }
    }

    /*
    ---------------------------------------------------------
    DELETE TEMPORARY FOLDER
    ---------------------------------------------------------
    */

    function deleteRestoreFolder($folder) {

        if (!is_dir($folder)) {
            return;
        }

        $files =
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $folder,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::CHILD_FIRST
            );

        foreach ($files as $file) {

            if ($file->isDir()) {

                @rmdir(
                    $file->getRealPath()
                );

            } else {

                @unlink(
                    $file->getRealPath()
                );
            }
        }

        @rmdir($folder);
    }

    deleteRestoreFolder($tempDir);

    /*
    ---------------------------------------------------------
    REDIRECT AFTER RESTORE
    ---------------------------------------------------------
    */

    header(
        'Location:index.php?page=settings&restored=1'
    );

    exit;
}

/* =========================================================
   CREATE BACKUP
   ========================================================= */

if ($page === 'settings' && $action === 'backup') {

    if (!can_do('settings')) {
        exit('Huna ruhusa ya kufanya backup.');
    }

    try {
        $zipFile = zan_create_local_backup(
            'Zantronix_Backup'
        );

        if (google_is_connected()) {
            google_upload_backup($zipFile);

            header(
                'Location: index.php?page=settings&google_backup=1'
            );

            exit;
        }

        header(
            'Content-Type: application/zip'
        );

        header(
            'Content-Disposition: attachment; filename="' .
            basename($zipFile) . '"'
        );

        header(
            'Content-Length: ' .
            filesize($zipFile)
        );

        readfile($zipFile);
        exit;

    } catch (Exception $e) {
        exit(
            'Backup error: ' .
            htmlspecialchars($e->getMessage())
        );
    }
}

/* =========================================================
   HELPERS
   ========================================================= */

function vat_customer($customer) {

    return isset($customer['vat_status']) &&
        strtolower(
            trim($customer['vat_status'])
        ) === 'mteja wa vat';
}

function invoice_numbers($subtotal, $isVat) {

    $vat =
        $isVat
        ? round($subtotal * 0.18, 2)
        : 0;

    return [
        $subtotal,
        $vat,
        round($subtotal + $vat, 2)
    ];
}

/* Discount is applied to the invoice amount only.
   Product quantity/stock is deliberately NOT touched here. */
function invoice_totals_with_discount($subtotal, $isVat, $discountType, $discountValue) {

    $subtotal = max(0, (float)$subtotal);
    $discountType = strtolower(trim((string)$discountType));
    $discountValue = max(0, (float)$discountValue);

    if ($discountType === 'percent') {
        $discountAmount = round($subtotal * min(100, $discountValue) / 100, 2);
    } else {
        $discountType = 'amount';
        $discountAmount = min($subtotal, round($discountValue, 2));
    }

    $afterDiscount = max(0, round($subtotal - $discountAmount, 2));
    $vat = $isVat ? round($afterDiscount * 0.18, 2) : 0;
    $grand = round($afterDiscount + $vat, 2);

    return [
        $subtotal,
        $discountType,
        $discountValue,
        $discountAmount,
        $afterDiscount,
        $vat,
        $grand
    ];
}

/* =========================================================
   SAVE SETTINGS
   ========================================================= */

if (
    $page === 'save_settings' &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    can_do('settings')
) {

    $logo =
        $settings['logo'] ?? '';

    $payQr =
        $settings['pay_qr'] ?? '';

    if (
        isset($_FILES['logo']) &&
        $_FILES['logo']['error'] === UPLOAD_ERR_OK
    ) {

        $ext =
            strtolower(
                pathinfo(
                    $_FILES['logo']['name'],
                    PATHINFO_EXTENSION
                )
            );

        if (
            in_array(
                $ext,
                [
                    'png',
                    'jpg',
                    'jpeg',
                    'webp'
                ],
                true
            )
        ) {

            $name =
                'logo_' .
                time() .
                '.' .
                $ext;

            if (
                move_uploaded_file(
                    $_FILES['logo']['tmp_name'],
                    __DIR__ .
                    '/uploads/' .
                    $name
                )
            ) {

                $logo =
                    'uploads/' .
                    $name;
            }
        }
    }

    /* QR code ya namba ya malipo */
    if (
        isset($_FILES['pay_qr']) &&
        $_FILES['pay_qr']['error'] === UPLOAD_ERR_OK
    ) {
        $qrExt = strtolower(pathinfo($_FILES['pay_qr']['name'], PATHINFO_EXTENSION));
        if (in_array($qrExt, ['png','jpg','jpeg','webp'], true)) {
            $qrName = 'pay_qr_' . time() . '_' . mt_rand(1000,9999) . '.' . $qrExt;
            if (move_uploaded_file($_FILES['pay_qr']['tmp_name'], __DIR__ . '/uploads/' . $qrName)) {
                $payQr = 'uploads/' . $qrName;
            }
        }
    }

    $companyColor =
        $_POST['company_color'] ??
        '#ffd000';

    if (
        !preg_match(
            '/^#[0-9A-Fa-f]{6}$/',
            $companyColor
        )
    ) {

        $companyColor =
            '#ffd000';
    }

    $s = $pdo->prepare("
        UPDATE settings SET
        company_name=?,
        tagline=?,
        phone=?,
        email=?,
        address=?,
        tin=?,
        vrn=?,
        vat=?,
        currency=?,
        bank_name=?,
        bank_account_name=?,
        bank_account_number=?,
        bank_branch=?,
        pay_number=?,
        pay_qr=?,
        company_color=?,
        footer=?,
        logo=?
        WHERE id=1
    ");

    $s->execute([

        $_POST['company_name'] ?? '',

        $_POST['tagline'] ?? '',

        $_POST['phone'] ?? '',

        $_POST['email'] ?? '',

        $_POST['address'] ?? '',

        $_POST['tin'] ?? '',

        $_POST['vrn'] ?? '',

        $_POST['vat'] ?? '',

        $_POST['currency'] ?? 'TZS',

        $_POST['bank_name'] ?? '',

        $_POST['bank_account_name'] ?? '',

        $_POST['bank_account_number'] ?? '',

        $_POST['bank_branch'] ?? '',

        $_POST['pay_number'] ?? '',

        $payQr,

        $companyColor,

        $_POST['footer'] ?? '',

        $logo
    ]);

    log_action(
        'Alibadilisha mipangilio',
        'Taarifa za kampuni zilisasishwa'
    );

    header(
        'Location:?page=settings&saved=1'
    );

    exit;
}

/* =========================================================
   USERS
   ========================================================= */

if (
    $page === 'save_user' &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    can_do('users')
) {

    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($name === '' || $username === '' || $password === '') {
        exit('Jina, username na nenosiri vinahitajika.');
    }

    $existing = $pdo->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
    $existing->execute([$username]);
    if ($existing->fetch()) {
        exit('Username hiyo tayari inatumika.');
    }

    $perms = [];

    foreach (
        [
            'dashboard',
            'customers',
            'products',
            'invoices',
            'reports',
            'expenses',
            'settings',
            'users',
            'activity',
            'business_system'
        ] as $k
    ) {
        $perms[$k] = isset($_POST['perm_'.$k]) ? 1 : 0;
    }

    $s = $pdo->prepare(
        'INSERT INTO users
        (name,username,phone,password,role,permissions,account_system,active)
        VALUES(?,?,?,?,?,?,?,1)'
    );

    $s->execute([
        $name,
        $username,
        $_POST['phone'] ?? '',
        hash('sha256', $password),
        $_POST['role'] ?? 'Mauzo',
        json_encode($perms),
        'business'
    ]);

    log_action(
        'Alitengeneza mtumiaji',
        'Mtumiaji: ' . ($_POST['username'] ?? '')
    );

    header('Location:?page=users');
    exit;
}

/* =========================================================
   EDIT USER
   ========================================================= */

if (
    $page === 'update_user' &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    can_do('users')
) {

    $id = (int)($_POST['id'] ?? 0);

    $s = $pdo->prepare("SELECT * FROM users WHERE id=? AND account_system='business'");
    $s->execute([$id]);
    $oldUser = $s->fetch();

    if (!$oldUser) {
        exit('Mtumiaji hajapatikana.');
    }

    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    if ($name === '' || $username === '') {
        exit('Jina na username vinahitajika.');
    }

    $existing = $pdo->prepare('SELECT id FROM users WHERE username=? AND id<>? LIMIT 1');
    $existing->execute([$username, $id]);
    if ($existing->fetch()) {
        exit('Username hiyo tayari inatumika.');
    }

    $perms = [];

    foreach (
        [
            'dashboard',
            'customers',
            'products',
            'invoices',
            'reports',
            'expenses',
            'settings',
            'users',
            'activity',
            'business_system'
        ] as $k
    ) {
        $perms[$k] = isset($_POST['perm_'.$k]) ? 1 : 0;
    }

    $newPassword = $_POST['password'] ?? '';

    if ($newPassword !== '') {
        $s = $pdo->prepare(
            "UPDATE users SET
             name=?, username=?, phone=?, password=?, role=?, permissions=?, account_system='business'
             WHERE id=?"
        );

        $s->execute([
            $name,
            $username,
            $_POST['phone'] ?? '',
            hash('sha256', $newPassword),
            $_POST['role'] ?? $oldUser['role'],
            json_encode($perms),
            $id
        ]);
    } else {
        $s = $pdo->prepare(
            "UPDATE users SET
             name=?, username=?, phone=?, role=?, permissions=?, account_system='business'
             WHERE id=?"
        );

        $s->execute([
            $name,
            $username,
            $_POST['phone'] ?? '',
            $_POST['role'] ?? $oldUser['role'],
            json_encode($perms),
            $id
        ]);
    }

    log_action(
        'Alihariri mtumiaji',
        'ID: ' . $id
    );

    header('Location:?page=users');
    exit;
}

if (
    $page === 'toggle_user' &&
    can_do('users')
) {

    $id = (int)($_GET['id'] ?? 0);

    $s = $pdo->prepare("SELECT role FROM users WHERE id=? AND account_system='business'");
    $s->execute([$id]);
    $target = $s->fetch();

    if (!$target) {
        exit('Mtumiaji hajapatikana.');
    }

    if ($target['role'] === 'Msimamizi' && $id === (int)($_SESSION['user']['id'] ?? 0)) {
        exit('Huwezi kujizima mwenyewe.');
    }

    $s = $pdo->prepare(
        'UPDATE users SET active=CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id=?'
    );
    $s->execute([$id]);

    log_action(
        'Alibadilisha hali ya mtumiaji',
        'ID: ' . $id
    );

    header('Location:?page=users');
    exit;
}

/* =========================================================
   DELETE USER
   ========================================================= */
if($page==='delete_user' && can_do('users')){
    $id=(int)($_GET['id']??0); if($id<=0)exit('Mtumiaji si sahihi.');
    if($id===(int)($_SESSION['user']['id']??0))exit('Huwezi kujifuta mwenyewe.');
    $q=$pdo->prepare("SELECT role,username FROM users WHERE id=? AND account_system='business'");$q->execute([$id]);$target=$q->fetch();
    if(!$target)exit('Mtumiaji hajapatikana.');
    if($target['role']==='Msimamizi')exit('Kwa usalama, futa msimamizi mwingine kwa kubadilisha role kwanza.');
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]); log_action('Alifuta mtumiaji','ID: '.$id.' Username: '.$target['username']); header('Location:?page=users&deleted=1'); exit;
}

/* =========================================================
   CUSTOMERS
   ========================================================= */

if (
    $page === 'save_customer' &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    $s = $pdo->prepare(
        'INSERT INTO customers
        (name,phone,email,address,tin,vrn,vat_status,whatsapp)
        VALUES(?,?,?,?,?,?,?,?)'
    );

    $s->execute([

        $_POST['name'],

        $_POST['phone'] ?? '',

        $_POST['email'] ?? '',

        $_POST['address'] ?? '',

        $_POST['tin'] ?? '',

        $_POST['vrn'] ?? '',

        $_POST['vat_status'] ??
        'Asiye wa VAT',

        $_POST['whatsapp'] ?? ''
    ]);

    log_action(
        'Aliongeza mteja',
        'Mteja: ' .
        $_POST['name']
    );

    header(
        'Location:?page=customers'
    );

    exit;
}

if (
    $page === 'update_customer' &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    $s = $pdo->prepare(
        'UPDATE customers SET
        name=?,
        phone=?,
        email=?,
        address=?,
        tin=?,
        vrn=?,
        vat_status=?,
        whatsapp=?
        WHERE id=?'
    );

    $s->execute([

        $_POST['name'],

        $_POST['phone'] ?? '',

        $_POST['email'] ?? '',

        $_POST['address'] ?? '',

        $_POST['tin'] ?? '',

        $_POST['vrn'] ?? '',

        $_POST['vat_status'] ??
        'Asiye wa VAT',

        $_POST['whatsapp'] ?? '',

        (int)$_POST['id']
    ]);

    header(
        'Location:?page=customers'
    );

    exit;
}

if ($page === 'delete_customer') {

    $s = $pdo->prepare(
        'DELETE FROM customers WHERE id=?'
    );

    $s->execute([
        (int)$_GET['id']
    ]);

    header(
        'Location:?page=customers'
    );

    exit;
}


/* =========================================================
   SUPPLIERS / WASAMBAZAJI + BARCODE API
   ========================================================= */

if ($page === 'save_supplier' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $company = trim($_POST['company_name'] ?? '');
    if ($company === '') exit('Jina la msambazaji linahitajika.');
    $s=$pdo->prepare('INSERT INTO suppliers (company_name,contact_person,phone,email,address,tin,vrn,notes,created_at) VALUES(?,?,?,?,?,?,?,?,?)');
    $s->execute([$company,trim($_POST['contact_person']??''),trim($_POST['phone']??''),trim($_POST['email']??''),trim($_POST['address']??''),trim($_POST['tin']??''),trim($_POST['vrn']??''),trim($_POST['notes']??''),date('Y-m-d H:i:s')]);
    log_action('Aliongeza msambazaji','Msambazaji: '.$company);
    header('Location:?page=suppliers'); exit;
}
if ($page === 'update_supplier' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id=(int)($_POST['id']??0); $company=trim($_POST['company_name']??'');
    if($id<=0||$company==='') exit('Taarifa za msambazaji si sahihi.');
    $s=$pdo->prepare('UPDATE suppliers SET company_name=?,contact_person=?,phone=?,email=?,address=?,tin=?,vrn=?,notes=?,updated_at=? WHERE id=?');
    $s->execute([$company,trim($_POST['contact_person']??''),trim($_POST['phone']??''),trim($_POST['email']??''),trim($_POST['address']??''),trim($_POST['tin']??''),trim($_POST['vrn']??''),trim($_POST['notes']??''),date('Y-m-d H:i:s'),$id]);
    log_action('Alibadilisha msambazaji','ID: '.$id); header('Location:?page=suppliers'); exit;
}
if ($page === 'delete_supplier') {
    $id=(int)($_GET['id']??0); $c=$pdo->prepare('SELECT COUNT(*) FROM products WHERE supplier_id=?'); $c->execute([$id]);
    if((int)$c->fetchColumn()>0) exit('Msambazaji bado ameunganishwa na bidhaa. Badilisha msambazaji wa bidhaa kwanza.');
    $pdo->prepare('DELETE FROM suppliers WHERE id=?')->execute([$id]); log_action('Alifuta msambazaji','ID: '.$id); header('Location:?page=suppliers'); exit;
}
if ($page === 'barcode_product') {
    header('Content-Type: application/json; charset=utf-8'); $barcode=trim($_GET['barcode']??'');
    if($barcode===''){echo json_encode(['ok'=>false,'message'=>'Barcode haijawekwa.']);exit;}
    $q=$pdo->prepare('SELECT * FROM products WHERE barcode=? LIMIT 1'); $q->execute([$barcode]); $product=$q->fetch();
    if(!$product){echo json_encode(['ok'=>false,'message'=>'Barcode hii haipo kwenye bidhaa.']);exit;}
    echo json_encode(['ok'=>true,'product'=>$product],JSON_UNESCAPED_UNICODE); exit;
}

/* =========================================================
   PRODUCTS
   ========================================================= */

if (
    $page === 'save_product' &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {
    $barcode = trim($_POST['barcode'] ?? '');
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    if ($barcode !== '') {
        $check=$pdo->prepare('SELECT id FROM products WHERE barcode=? LIMIT 1'); $check->execute([$barcode]);
        if($check->fetch()) exit('Barcode hii tayari imetumika na bidhaa nyingine.');
    }
    $receivedAt=trim($_POST['stock_received_at']??''); if($receivedAt==='')$receivedAt=date('Y-m-d');
    $s=$pdo->prepare('INSERT INTO products (name,sku,category,price,cost,stock,low_stock,barcode,supplier_id,stock_received_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $s->execute([$_POST['name'],$_POST['sku']??'',$_POST['category']??'',(float)$_POST['price'],(float)($_POST['cost']??0),(int)$_POST['stock'],(int)($_POST['low_stock']??5),$barcode,$supplierId>0?$supplierId:null,$receivedAt]);
    log_action('Aliongeza bidhaa','Bidhaa: '.$_POST['name']); header('Location:?page=products'); exit;
}

if (
    $page === 'update_product' &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {
    $barcode=trim($_POST['barcode']??''); $productId=(int)($_POST['id']??0); $supplierId=(int)($_POST['supplier_id']??0);
    if($barcode!==''){ $check=$pdo->prepare('SELECT id FROM products WHERE barcode=? AND id<>? LIMIT 1'); $check->execute([$barcode,$productId]); if($check->fetch()) exit('Barcode hii tayari imetumika na bidhaa nyingine.'); }
    $receivedAt=trim($_POST['stock_received_at']??''); if($receivedAt==='')$receivedAt=date('Y-m-d');
    $s=$pdo->prepare('UPDATE products SET name=?,sku=?,category=?,price=?,cost=?,stock=?,low_stock=?,barcode=?,supplier_id=?,stock_received_at=? WHERE id=?');
    $s->execute([$_POST['name'],$_POST['sku']??'',$_POST['category']??'',(float)$_POST['price'],(float)($_POST['cost']??0),(int)$_POST['stock'],(int)($_POST['low_stock']??5),$barcode,$supplierId>0?$supplierId:null,$receivedAt,$productId]);
    log_action('Alibadilisha bidhaa','ID: '.$productId); header('Location:?page=products'); exit;
}

if ($page === 'delete_product' ) {

    $s = $pdo->prepare(
        'DELETE FROM products WHERE id=?'
    );

    $s->execute([
        (int)$_GET['id']
    ]);

    header(
        'Location:?page=products'
    );

    exit;
}

/* =========================================================
   CREATE INVOICE
   ========================================================= */

if (
    $page === 'save_invoice' &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    $customerId =
        (int)$_POST['customer_id'];

    $ids =
        $_POST['product_id'] ?? [];

    $qty =
        $_POST['qty'] ?? [];

    $prices =
        $_POST['price'] ?? [];

    $laborDescription = trim($_POST['labor_description'] ?? '');
    $laborCharge = max(0, (float)($_POST['labor_charge'] ?? 0));
    $discountType = strtolower(trim($_POST['discount_type'] ?? 'amount'));
    $discountValue = max(0, (float)($_POST['discount_value'] ?? 0));

    $cs =
        $pdo->prepare(
            'SELECT * FROM customers WHERE id=?'
        );

    $cs->execute([
        $customerId
    ]);

    $customer =
        $cs->fetch();

    if (!$customer) {

        $error =
            'Mteja hajapatikana.';

    } else {

        $subtotal = 0;

        foreach (
            $ids as $k => $id
        ) {

            $q =
                (int)(
                    $qty[$k] ?? 0
                );

            $pr =
                (float)(
                    $prices[$k] ?? 0
                );

            if ($q > 0) {

                $subtotal +=
                    $q * $pr;
            }
        }

        if ($laborCharge > 0) {
            $subtotal += $laborCharge;
        }

        [
            $subtotal,
            $discountType,
            $discountValue,
            $discountAmount,
            $afterDiscount,
            $vat,
            $grand
        ] = invoice_totals_with_discount(
            $subtotal,
            vat_customer($customer),
            $discountType,
            $discountValue
        );

        $paid =
            (float)(
                $_POST['paid'] ?? 0
            );

        if ($paid > $grand) {

            $paid = $grand;
        }

        $status =
            $paid <= 0
            ? 'Haijalipwa'
            : (
                $paid < $grand
                ? 'Sehemu imelipwa'
                : 'Imelipwa'
            );

        try {

            $pdo->beginTransaction();

            $no =
                'ANK-' .
                date('Ym') .
                '-' .
                str_pad(
                    (string)(
                        $pdo
                        ->query(
                            'SELECT COUNT(*) FROM invoices'
                        )
                        ->fetchColumn()
                        + 1
                    ),
                    4,
                    '0',
                    STR_PAD_LEFT
                );

            $s =
                $pdo->prepare(
                    'INSERT INTO invoices
                    (
                        invoice_no,
                        customer_id,
                        total,
                        paid,
                        status,
                        prepared_by,
                        subtotal,
                        vat,
                        grand_total,
                        discount_type,
                        discount_value,
                        discount_amount
                    )
                    VALUES(?,?,?,?,?,?,?,?,?,?,?,?)'
                );

            $s->execute([

                $no,

                $customerId,

                $grand,

                $paid,

                $status,

                $_SESSION['user']['id'],

                $subtotal,

                $vat,

                $grand,

                $discountType,

                $discountValue,

                $discountAmount
            ]);

            $iid =
                $pdo->lastInsertId();

            $ins =
                $pdo->prepare(
                    'INSERT INTO invoice_items
                    (
                        invoice_id,
                        product_id,
                        description,
                        qty,
                        price,
                        subtotal
                    )
                    VALUES(?,?,?,?,?,?)'
                );

            $up =
                $pdo->prepare(
                    'UPDATE products
                     SET stock=stock-?
                     WHERE id=?'
                );

            foreach (
                $ids as $k => $id
            ) {

                $q =
                    (int)(
                        $qty[$k] ?? 0
                    );

                $pr =
                    (float)(
                        $prices[$k] ?? 0
                    );

                if ($q < 1) {
                    continue;
                }

                $p =
                    $pdo->prepare(
                        'SELECT * FROM products
                         WHERE id=?'
                    );

                $p->execute([
                    (int)$id
                ]);

                $prod =
                    $p->fetch();

                if (!$prod) {

                    throw new Exception(
                        'Bidhaa haijapatikana.'
                    );
                }

                if (
                    $q >
                    (int)$prod['stock']
                ) {

                    throw new Exception(
                        'Stoo haitoshi kwa bidhaa: ' .
                        $prod['name']
                    );
                }

                $ins->execute([

                    $iid,

                    $id,

                    $prod['name'],

                    $q,

                    $pr,

                    $q * $pr
                ]);

                $up->execute([
                    $q,
                    $id
                ]);
            }

            if ($laborCharge > 0) {
                $ins->execute([
                    $iid,
                    null,
                    $laborDescription !== '' ? $laborDescription : 'Labor Charge',
                    1,
                    $laborCharge,
                    $laborCharge
                ]);
            }

            $pdo->commit();

            log_action(
                'Alitengeneza ankara',
                'Namba: ' . $no
            );

            header(
                'Location:?page=print&id=' .
                $iid
            );

            exit;

        } catch (Exception $e) {

            if (
                $pdo->inTransaction()
            ) {

                $pdo->rollBack();
            }

            $error =
                $e->getMessage();
        }
    }
}

/* =========================================================
   UPDATE INVOICE
   ========================================================= */

if (
    $page === 'update_invoice' &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    $invoiceId =
        (int)$_POST['invoice_id'];

    $oldQ =
        $pdo->prepare(
            'SELECT * FROM invoice_items
             WHERE invoice_id=?'
        );

    $oldQ->execute([
        $invoiceId
    ]);

    $oldItems =
        $oldQ->fetchAll();

    try {

        $pdo->beginTransaction();

        /*
        RETURN OLD STOCK
        */

        $return =
            $pdo->prepare(
                'UPDATE products
                 SET stock=stock+?
                 WHERE id=?'
            );

        foreach (
            $oldItems as $old
        ) {

            if (
                $old['product_id']
            ) {

                $return->execute([
                    (int)$old['qty'],
                    (int)$old['product_id']
                ]);
            }
        }

        $customerId =
            (int)$_POST['customer_id'];

        $cs =
            $pdo->prepare(
                'SELECT * FROM customers
                 WHERE id=?'
            );

        $cs->execute([
            $customerId
        ]);

        $customer =
            $cs->fetch();

        if (!$customer) {

            throw new Exception(
                'Mteja hajapatikana.'
            );
        }

        $ids =
            $_POST['product_id'] ?? [];

        $qty =
            $_POST['qty'] ?? [];

        $prices =
            $_POST['price'] ?? [];

        $laborDescription = trim($_POST['labor_description'] ?? '');
        $laborCharge = max(0, (float)($_POST['labor_charge'] ?? 0));
        $discountType = strtolower(trim($_POST['discount_type'] ?? 'amount'));
        $discountValue = max(0, (float)($_POST['discount_value'] ?? 0));

        $subtotal = 0;

        foreach (
            $ids as $k => $id
        ) {

            $q =
                (int)(
                    $qty[$k] ?? 0
                );

            $p =
                (float)(
                    $prices[$k] ?? 0
                );

            if ($q > 0) {

                $subtotal +=
                    $q * $p;
            }
        }

        if ($laborCharge > 0) {
            $subtotal += $laborCharge;
        }

        [
            $subtotal,
            $discountType,
            $discountValue,
            $discountAmount,
            $afterDiscount,
            $vat,
            $grand
        ] = invoice_totals_with_discount(
            $subtotal,
            vat_customer($customer),
            $discountType,
            $discountValue
        );

        $paid =
            min(
                (float)(
                    $_POST['paid'] ?? 0
                ),
                $grand
            );

        $status =
            $paid <= 0
            ? 'Haijalipwa'
            : (
                $paid < $grand
                ? 'Sehemu imelipwa'
                : 'Imelipwa'
            );

        $u =
            $pdo->prepare(
                'UPDATE invoices SET
                customer_id=?,
                total=?,
                paid=?,
                status=?,
                subtotal=?,
                vat=?,
                grand_total=?,
                discount_type=?,
                discount_value=?,
                discount_amount=?
                WHERE id=?'
            );

        $u->execute([

            $customerId,

            $grand,

            $paid,

            $status,

            $subtotal,

            $vat,

            $grand,

            $discountType,

            $discountValue,

            $discountAmount,

            $invoiceId
        ]);

        $pdo->prepare(
            'DELETE FROM invoice_items
             WHERE invoice_id=?'
        )->execute([
            $invoiceId
        ]);

        $ins =
            $pdo->prepare(
                'INSERT INTO invoice_items
                (
                    invoice_id,
                    product_id,
                    description,
                    qty,
                    price,
                    subtotal
                )
                VALUES(?,?,?,?,?,?)'
            );

        $stock =
            $pdo->prepare(
                'UPDATE products
                 SET stock=stock-?
                 WHERE id=?'
            );

        foreach (
            $ids as $k => $id
        ) {

            $q =
                (int)(
                    $qty[$k] ?? 0
                );

            $p =
                (float)(
                    $prices[$k] ?? 0
                );

            if ($q < 1) {
                continue;
            }

            $ps =
                $pdo->prepare(
                    'SELECT * FROM products
                     WHERE id=?'
                );

            $ps->execute([
                (int)$id
            ]);

            $prod =
                $ps->fetch();

            if (!$prod) {

                throw new Exception(
                    'Bidhaa haijapatikana.'
                );
            }

            if (
                $q >
                (int)$prod['stock']
            ) {

                throw new Exception(
                    'Stoo haitoshi kwa bidhaa: ' .
                    $prod['name']
                );
            }

            $ins->execute([

                $invoiceId,

                $id,

                $prod['name'],

                $q,

                $p,

                $q * $p
            ]);

            $stock->execute([
                $q,
                $id
            ]);
        }

        if ($laborCharge > 0) {
            $ins->execute([
                $invoiceId,
                null,
                $laborDescription !== '' ? $laborDescription : 'Labor Charge',
                1,
                $laborCharge,
                $laborCharge
            ]);
        }

        $pdo->commit();

        log_action(
            'Alihariri ankara',
            'ID: ' .
            $invoiceId
        );

        header(
            'Location:?page=print&id=' .
            $invoiceId
        );

        exit;

    } catch (Exception $e) {

        if (
            $pdo->inTransaction()
        ) {

            $pdo->rollBack();
        }

        $error =
            $e->getMessage();
    }
}

/* =========================================================
   DELETE INVOICE + RETURN STOCK
   ========================================================= */
if($page==='delete_invoice'){
    $invoiceId=(int)($_GET['id']??0); if($invoiceId<=0)exit('Invoice si sahihi.');
    try{ $pdo->beginTransaction(); $q=$pdo->prepare('SELECT invoice_no FROM invoices WHERE id=?');$q->execute([$invoiceId]);$inv=$q->fetch();if(!$inv)throw new Exception('Invoice haipatikani.');
        $iq=$pdo->prepare('SELECT product_id,qty FROM invoice_items WHERE invoice_id=?');$iq->execute([$invoiceId]);$restore=$pdo->prepare('UPDATE products SET stock=stock+? WHERE id=?');foreach($iq->fetchAll() as $it)if(!empty($it['product_id']))$restore->execute([(int)$it['qty'],(int)$it['product_id']]);
        $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id=?')->execute([$invoiceId]);$pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$invoiceId]);$pdo->commit();log_action('Alifuta ankara','Namba: '.$inv['invoice_no']);header('Location:?page=invoices&deleted=1');exit;
    }catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();exit('Imeshindikana kufuta ankara: '.htmlspecialchars($e->getMessage()));}
}

/* =========================================================
   EXPENSES
   ========================================================= */
if($page==='save_expense'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $date=trim($_POST['expense_date']??date('Y-m-d'));$category=trim($_POST['category']??'Other');$description=trim($_POST['description']??'');$amount=(float)($_POST['amount']??0);$paidBy=trim($_POST['paid_by']??'');$notes=trim($_POST['notes']??'');
    if($amount<=0)exit('Amount ya matumizi lazima iwe zaidi ya 0.');$q=$pdo->prepare('INSERT INTO expenses (expense_date,category,description,amount,paid_by,notes,created_by,created_at) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$date,$category,$description,$amount,$paidBy,$notes,(int)$_SESSION['user']['id'],date('Y-m-d H:i:s')]);log_action('Aliongeza matumizi',$category.' TZS '.number_format($amount,2));header('Location:?page=expenses&saved=1');exit;
}
if($page==='delete_expense'){ $id=(int)($_GET['id']??0);$pdo->prepare('DELETE FROM expenses WHERE id=?')->execute([$id]);log_action('Alifuta matumizi','ID: '.$id);header('Location:?page=expenses&deleted=1');exit; }

/* =========================================================
   QUOTATION / PROFORMA
   ========================================================= */
function next_doc_no($table,$prefix){global $pdo;$n=(int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn()+1;return $prefix.date('Ym').'-'.str_pad((string)$n,4,'0',STR_PAD_LEFT);}
function save_sales_document($type){
    global $pdo;
    $customerId=(int)($_POST['customer_id']??0);
    $ids=$_POST['product_id']??[];
    $qty=$_POST['qty']??[];
    $prices=$_POST['price']??[];
    $laborDescription=trim($_POST['labor_description']??'');
    $laborCharge=max(0,(float)($_POST['labor_charge']??0));
    $validUntil=trim($_POST['valid_until']??'');
    $notes=trim($_POST['notes']??'');
    $terms=trim($_POST['terms']??'');
    $cq=$pdo->prepare('SELECT * FROM customers WHERE id=?');
    $cq->execute([$customerId]);
    $customer=$cq->fetch();
    if(!$customer)throw new Exception('Customer not found.');
    $subtotal=0;
    foreach($ids as $k=>$id){$q=(int)($qty[$k]??0);$pr=(float)($prices[$k]??0);if($q>0)$subtotal+=$q*$pr;}
    if($laborCharge>0)$subtotal+=$laborCharge;
    [$subtotal,$vat,$grand]=invoice_numbers($subtotal,vat_customer($customer));
    $quote=$type==='quotation';
    $table=$quote?'quotations':'proforma_invoices';
    $itemsTable=$quote?'quotation_items':'proforma_items';
    $no=next_doc_no($table,$quote?'QUO-':'PRO-');
    $docCol=$quote?'quotation_no':'proforma_no';
    $fk=$quote?'quotation_id':'proforma_id';
    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare("INSERT INTO {$table} ({$docCol},customer_id,subtotal,vat,total,status,prepared_by,created_at,valid_until,notes,terms) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
        $q->execute([$no,$customerId,$subtotal,$vat,$grand,'Draft',(int)$_SESSION['user']['id'],date('Y-m-d H:i:s'),$validUntil!==''?$validUntil:null,$notes!==''?$notes:null,$terms!==''?$terms:null]);
        $docId=$pdo->lastInsertId();
        $ins=$pdo->prepare("INSERT INTO {$itemsTable} ({$fk},product_id,description,qty,price,subtotal) VALUES(?,?,?,?,?,?)");
        foreach($ids as $k=>$id){$qv=(int)($qty[$k]??0);$pr=(float)($prices[$k]??0);if($qv<1)continue;$pq=$pdo->prepare('SELECT name FROM products WHERE id=?');$pq->execute([(int)$id]);$prod=$pq->fetch();if(!$prod)throw new Exception('Product not found.');$ins->execute([$docId,(int)$id,$prod['name'],$qv,$pr,$qv*$pr]);}
        if($laborCharge>0){$ins->execute([$docId,null,$laborDescription!==''?$laborDescription:'Labor Charge',1,$laborCharge,$laborCharge]);}
        $pdo->commit();
        return[$docId,$no];
    }catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
if($page==='save_quotation'&&$_SERVER['REQUEST_METHOD']==='POST'){try{[$id,$no]=save_sales_document('quotation');header('Location:?page=print_quotation&id='.$id);exit;}catch(Exception $e){$error=$e->getMessage();}}
if($page==='save_proforma'&&$_SERVER['REQUEST_METHOD']==='POST'){try{[$id,$no]=save_sales_document('proforma');header('Location:?page=print_proforma&id='.$id);exit;}catch(Exception $e){$error=$e->getMessage();}}
function update_sales_document($type,$docId){global $pdo;$customerId=(int)($_POST['customer_id']??0);$ids=$_POST['product_id']??[];$qty=$_POST['qty']??[];$prices=$_POST['price']??[];$laborDescription=trim($_POST['labor_description']??'');$laborCharge=max(0,(float)($_POST['labor_charge']??0));$validUntil=trim($_POST['valid_until']??'');$notes=trim($_POST['notes']??'');$terms=trim($_POST['terms']??'');$cq=$pdo->prepare('SELECT * FROM customers WHERE id=?');$cq->execute([$customerId]);$customer=$cq->fetch();if(!$customer)throw new Exception('Customer not found.');$subtotal=0;foreach($ids as $k=>$pid){$q=(int)($qty[$k]??0);$p=(float)($prices[$k]??0);if($q>0)$subtotal+=$q*$p;}$subtotal+=$laborCharge;[$subtotal,$vat,$grand]=invoice_numbers($subtotal,vat_customer($customer));$quote=$type==='quotation';$table=$quote?'quotations':'proforma_invoices';$items=$quote?'quotation_items':'proforma_items';$fk=$quote?'quotation_id':'proforma_id';$pdo->beginTransaction();try{$pdo->prepare("UPDATE {$table} SET customer_id=?,subtotal=?,vat=?,total=?,valid_until=?,notes=?,terms=? WHERE id=?")->execute([$customerId,$subtotal,$vat,$grand,$validUntil!==''?$validUntil:null,$notes!==''?$notes:null,$terms!==''?$terms:null,$docId]);$pdo->prepare("DELETE FROM {$items} WHERE {$fk}=?")->execute([$docId]);$ins=$pdo->prepare("INSERT INTO {$items} ({$fk},product_id,description,qty,price,subtotal) VALUES(?,?,?,?,?,?)");foreach($ids as $k=>$pid){$q=(int)($qty[$k]??0);$p=(float)($prices[$k]??0);if($q<1)continue;$s=$pdo->prepare('SELECT name FROM products WHERE id=?');$s->execute([(int)$pid]);$prod=$s->fetch();if(!$prod)throw new Exception('Product not found.');$ins->execute([$docId,(int)$pid,$prod['name'],$q,$p,$q*$p]);}if($laborCharge>0)$ins->execute([$docId,null,$laborDescription?:'Labor Charge',1,$laborCharge,$laborCharge]);$pdo->commit();}catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}}
if($page==='update_quotation'&&$_SERVER['REQUEST_METHOD']==='POST'){try{$id=(int)($_GET['id']??0);update_sales_document('quotation',$id);header('Location:?page=print_quotation&id='.$id);exit;}catch(Exception $e){$error=$e->getMessage();}}
if($page==='update_proforma'&&$_SERVER['REQUEST_METHOD']==='POST'){try{$id=(int)($_GET['id']??0);update_sales_document('proforma',$id);header('Location:?page=print_proforma&id='.$id);exit;}catch(Exception $e){$error=$e->getMessage();}}
if($page==='delete_quotation'){ $id=(int)($_GET['id']??0);$pdo->prepare('DELETE FROM quotation_items WHERE quotation_id=?')->execute([$id]);$pdo->prepare('DELETE FROM quotations WHERE id=?')->execute([$id]);header('Location:?page=quotations');exit; }
if($page==='delete_proforma'){ $id=(int)($_GET['id']??0);$pdo->prepare('DELETE FROM proforma_items WHERE proforma_id=?')->execute([$id]);$pdo->prepare('DELETE FROM proforma_invoices WHERE id=?')->execute([$id]);header('Location:?page=proforma');exit; }

/* =========================================================
   DATA
   ========================================================= */

$customers =
    $pdo
    ->query(
        'SELECT * FROM customers
         ORDER BY id DESC'
    )
    ->fetchAll();

$products =
    $pdo
    ->query(
        'SELECT * FROM products
         ORDER BY name'
    )
    ->fetchAll();

$suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY company_name')->fetchAll();
$expenses=$pdo->query('SELECT e.*,u.name created_by_name FROM expenses e LEFT JOIN users u ON u.id=e.created_by ORDER BY e.expense_date DESC,e.id DESC LIMIT 500')->fetchAll();
$expenseTotal=(float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM expenses')->fetchColumn();
$quotationRows=$pdo->query('SELECT q.*,c.name customer FROM quotations q LEFT JOIN customers c ON c.id=q.customer_id ORDER BY q.id DESC LIMIT 200')->fetchAll();
$proformaRows=$pdo->query('SELECT p.*,c.name customer FROM proforma_invoices p LEFT JOIN customers c ON c.id=p.customer_id ORDER BY p.id DESC LIMIT 200')->fetchAll();
$owingCustomers=$pdo->query("SELECT c.id,c.name,c.phone,c.tin,c.vrn,COALESCE(SUM(i.total-i.paid),0) balance,COUNT(i.id) invoices_count FROM customers c LEFT JOIN invoices i ON i.customer_id=c.id GROUP BY c.id HAVING balance>0 ORDER BY balance DESC")->fetchAll();

$month =
    $pdo
    ->query("
        SELECT COALESCE(SUM(total),0)
        FROM invoices
        WHERE strftime(
            '%Y-%m',
            created_at
        )
        =
        strftime(
            '%Y-%m',
            'now'
        )
    ")
    ->fetchColumn();

$due =
    $pdo
    ->query(
        'SELECT COALESCE(
            SUM(total-paid),0
         )
         FROM invoices
         WHERE total>paid'
    )
    ->fetchColumn();

$countInv =
    $pdo
    ->query(
        'SELECT COUNT(*)
         FROM invoices'
    )
    ->fetchColumn();

$low =
    $pdo
    ->query(
        'SELECT COUNT(*)
         FROM products
         WHERE stock<=low_stock'
    )
    ->fetchColumn();

$recent =
    $pdo
    ->query("
        SELECT
            i.*,
            c.name customer,
            c.vat_status,
            u.name prepared_by_name
        FROM invoices i
        LEFT JOIN customers c
            ON c.id=i.customer_id
        LEFT JOIN users u
            ON u.id=i.prepared_by
        ORDER BY i.id DESC
        LIMIT 50
    ")
    ->fetchAll();

/* =========================================================
   NAVIGATION
   ========================================================= */

$nav = [
    'dashboard' => zan_t('Dashibodi','Dashboard'),
    'customers' => zan_t('Wateja','Customers'),
    'products' => zan_t('Bidhaa na Stoo','Products & Stock'),
    'suppliers' => zan_t('Wasambazaji','Suppliers'),
    'new_invoice' => zan_t('Tengeneza Ankara','New Invoice'),
    'invoices' => zan_t('Ankara','Invoices'),
    'new_quotation' => zan_t('Quotation Mpya','New Quotation'),
    'quotations' => 'Quotations',
    'new_proforma' => zan_t('Proforma Invoice Mpya','New Proforma Invoice'),
    'proforma' => 'Proforma Invoices',
    'expenses' => zan_t('Matumizi','Expenses'),
    'reports' => zan_t('Ripoti','Reports'),
    'settings' => zan_t('Mipangilio','Settings'),
    'users' => zan_t('Watumiaji','Users'),
    'activity' => zan_t('Historia ya Shughuli','Activity History')
];

?>
<!doctype html>

<html lang="sw">

<head>

<meta charset="utf-8">

<meta name="viewport"
      content="width=device-width,initial-scale=1">

<title>
<?=htmlspecialchars(
    $settings['company_name']
    ?? 'Zantronix'
)?>
</title>

<link rel="stylesheet"
      href="style.css">

<style>

@media print {

    .side,
    .top,
    .footer,
    .no-print {
        display:none!important;
    }

    .main {
        margin:0!important;
    }

    .wrap {
        padding:0!important;
    }

    body {
        background:#fff!important;
    }

    .invoicebox {
        border:0!important;
        box-shadow:none!important;
    }
}

.wrap{font-size:13px}.wrap h1{font-size:24px}.wrap h2{font-size:20px}.wrap h3{font-size:16px}.wrap input,.wrap select,.wrap textarea,.wrap button,.wrap .btn{font-size:13px}.dashboard-charts{display:grid;grid-template-columns:repeat(2,1fr);gap:18px;margin:18px 0}.chart-row{display:flex;justify-content:space-between;gap:10px;margin:10px 0 5px}.bar{height:10px;background:#e9ecef;border-radius:999px;overflow:hidden}.bar span{display:block;height:100%;background:#ffd000;border-radius:999px}.pay-box{margin-top:18px;border:1px solid #ddd;border-radius:10px;padding:14px}.pay-title{font-weight:800;margin-bottom:8px}.pay-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.pay-qr{max-width:100px;max-height:100px;object-fit:contain;border:1px solid #ddd;padding:4px;background:#fff}.doc-bank{margin-top:18px;border-top:2px solid #111;padding-top:12px;display:flex;justify-content:space-between;gap:20px;align-items:flex-start}.doc-bank .bank-details{font-size:12px;line-height:1.65}.doc-bank .pay-code{text-align:right;font-size:12px}.doc-bank img{max-width:95px;max-height:95px;margin-top:5px}.labor-box{margin:15px 0;padding:14px;border:1px solid #ddd;border-radius:10px;background:#fafafa}.labor-grid{display:grid;grid-template-columns:2fr 1fr;gap:12px}.lang-switch .btn.on{background:#111!important;color:#ffd000!important}

.vat-note {
    font-size:12px;
    color:#555;
    margin-top:8px;
}

.backup-box {
    border:1px solid #e5e5e5;
    border-radius:14px;
    padding:20px;
    background:#fff;
}

.backup-buttons {
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:15px;
}

.backup-warning {
    background:#fff3cd;
    border:1px solid #ffe08a;
    padding:12px 15px;
    border-radius:10px;
    margin:15px 0;
}


/* ZANTRONIX BLACK & YELLOW THEME */
:root{--zan-yellow:#ffd000;--zan-black:#111}
body{background:#f4f4f4!important;color:#171717}.side{background:#111!important;color:#fff!important;border-right:4px solid #ffd000!important}.logo{color:#ffd000!important}.logo span{color:#fff!important}.nav a{color:#fff!important}.nav a:hover,.nav a.on{background:#ffd000!important;color:#111!important}.top{background:#111!important;color:#fff!important;border-bottom:3px solid #ffd000!important}.btn{background:#ffd000!important;color:#111!important;border-color:#ffd000!important;font-weight:700}.btn.black{background:#111!important;color:#ffd000!important;border-color:#111!important}.table th{background:#111!important;color:#ffd000!important}.table tr:hover td{background:#fff8cf!important}.badge{display:inline-block;padding:6px 10px;border-radius:999px;font-weight:800;border:1px solid transparent}.badge-unpaid{background:#ffd000!important;color:#111!important;border-color:#e0b800!important}.badge-partial{background:#ffd000!important;color:#111!important;border-color:#e0b800!important}.badge-paid{background:#20a64a!important;color:#fff!important;border-color:#16833a!important}.search:focus,input:focus,select:focus,textarea:focus{border-color:#ffd000!important;outline:2px solid rgba(255,208,0,.25)}.grand{border-color:#111!important;background:#fff8cf!important}.doc-head{display:flex;justify-content:space-between;gap:30px;border-bottom:2px solid #111;padding-bottom:10px;margin-bottom:15px}
@media print{.side,.top,.footer,.no-print{display:none!important}.main{margin:0!important}.wrap{padding:0!important;font-size:11px!important}.wrap h1{font-size:18px!important}.wrap h2{font-size:16px!important}.wrap h3{font-size:13px!important}.table th,.table td{padding:6px!important;font-size:10px!important}.invoicebox,.panel{border:0!important;box-shadow:none!important}.doc-bank{margin-top:12px;padding-top:8px}.doc-bank .bank-details{font-size:10px!important}.doc-bank img{max-width:75px;max-height:75px}}
</style>

</head>

<body>

<!-- ======================================================
     SIDEBAR
     ====================================================== -->

<aside class="side">

<div class="logo">
<?php if(!empty($settings['logo']) && file_exists(__DIR__.'/'.$settings['logo'])):?><img src="<?=htmlspecialchars($settings['logo'])?>" alt="Zantronix logo" style="display:block;max-width:190px;max-height:70px;object-fit:contain;background:#fff;padding:4px;border-radius:5px"><?php else:?>⚡ ZANTRONIX<?php endif;?>

<span>
MFUMO WA ANKARA
</span>

</div>

<nav class="nav">

<?php
$navPermissionMap=['new_invoice'=>'invoices','invoices'=>'invoices','new_quotation'=>'invoices','quotations'=>'invoices','new_proforma'=>'invoices','proforma'=>'invoices','suppliers'=>'products'];
$navRole=strtolower(trim($_SESSION['user']['role'] ?? ''));
$navPerms=json_decode($_SESSION['user']['permissions'] ?? '{}', true);
if(!is_array($navPerms)) $navPerms=[];
$navFullBusiness=in_array($navRole,['super admin','super_admin','superadmin','admin','msimamizi'],true) || !empty($navPerms['business_system']);
foreach($nav as $k=>$v): $requiredPerm=$navPermissionMap[$k]??$k; if(!$navFullBusiness && !can_do($requiredPerm))continue;
?>
<a
    class="<?=(
        $page === $k
        ? 'on'
        : ''
    )?>"
    href="?page=<?=$k?>"
>

<?=$v?>

</a>

<?php endforeach; ?>

</nav>

<div class="sidefoot">

<b>
<?=htmlspecialchars(
    $settings['company_name']
    ?? ''
)?>
</b>

<br>

<?=htmlspecialchars(
    $settings['tagline']
    ?? ''
)?>

<br>

<?=htmlspecialchars(
    $settings['address']
    ?? ''
)?>

<br>

<?=htmlspecialchars(
    $settings['phone']
    ?? ''
)?>

<br>

<?=htmlspecialchars(
    $settings['email']
    ?? ''
)?>

</div>

</aside>

<!-- ======================================================
     MAIN
     ====================================================== -->

<main class="main">

<header class="top">

<form method="get" action="index.php" style="margin:0;flex:1;max-width:560px"><input type="hidden" name="page" value="search"><input class="search" name="q" value="<?=htmlspecialchars($_GET['q']??'')?>" placeholder="<?=htmlspecialchars(zan_t('Tafuta mteja, ankara, bidhaa, barcode, simu...','Search customer, invoice, product, barcode, phone...'))?>"></form>

<div class="lang-switch no-print" style="display:flex;gap:5px;margin-right:10px">
<a class="btn <?=($zanLang==='sw'?'on':'')?>" style="padding:6px 9px" href="?page=<?=urlencode($page)?>&lang=sw">SW</a>
<a class="btn <?=($zanLang==='en'?'on':'')?>" style="padding:6px 9px" href="?page=<?=urlencode($page)?>&lang=en">EN</a>
</div>

<b>
<?=htmlspecialchars(
    $settings['company_name']
    ?? ''
)?>
</b>

<span>
<?=htmlspecialchars(
    $_SESSION['user']['name']
    ?? ''
)?>
</span>

<a class="top-logout" href="?logout=1">Toka</a>

</header>

<div class="wrap">

<!-- ======================================================
     DASHBOARD
     ====================================================== -->

<?php if (
    $page === 'dashboard'
): ?>

<div class="head">

<div>

<h1>
Dashibodi
</h1>

<span class="muted">
Muhtasari wa biashara yako
</span>

</div>

<a
    class="btn"
    href="?page=new_invoice"
>
+ Tengeneza Ankara
</a>

</div>

<div class="cards">

<div class="card">

<div class="label">
MAUZO YA MWEZI HUU
</div>

<div class="big">
TZS <?=number_format(
    $month,
    2
)?>
</div>

</div>

<div class="card">

<div class="label">
MADENI
</div>

<div class="big">
TZS <?=number_format(
    $due,
    2
)?>
</div>

</div>

<div class="card">

<div class="label">
ANKARA ZOTE
</div>

<div class="big">
<?=$countInv?>
</div>

</div>

<div class="card">

<div class="label">
STOO CHINI
</div>

<div class="big low">
<?=$low?>
</div>

</div>

</div>

<?php
$stockTotal = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$stockLowCount = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE stock<=low_stock')->fetchColumn();
$stockOutCount = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE stock<=0')->fetchColumn();
$stockHealthy = max(0, $stockTotal - $stockLowCount);
$stockLowPct = $stockTotal ? round(($stockLowCount/$stockTotal)*100,1) : 0;
$stockOutPct = $stockTotal ? round(($stockOutCount/$stockTotal)*100,1) : 0;
$stockHealthyPct = $stockTotal ? round(($stockHealthy/$stockTotal)*100,1) : 0;
$salesAll = (float)$pdo->query('SELECT COALESCE(SUM(total),0) FROM invoices')->fetchColumn();
$paidAll = (float)$pdo->query('SELECT COALESCE(SUM(paid),0) FROM invoices')->fetchColumn();
$dueAll = max(0, $salesAll-$paidAll);
$paidPct = $salesAll ? round(($paidAll/$salesAll)*100,1) : 0;
$duePct = $salesAll ? round(($dueAll/$salesAll)*100,1) : 0;
?>

<div class="dashboard-charts">
    <div class="panel">
        <h3><?=htmlspecialchars(zan_t('Hali ya Stoo (%)','Stock Status (%)'))?></h3>
        <div class="chart-row"><span><?=htmlspecialchars(zan_t('Stoo Salama','Healthy Stock'))?></span><b><?=$stockHealthyPct?>%</b></div>
        <div class="bar"><span style="width:<?=$stockHealthyPct?>%"></span></div>
        <div class="chart-row"><span><?=htmlspecialchars(zan_t('Stoo Chini','Low Stock'))?></span><b><?=$stockLowPct?>%</b></div>
        <div class="bar"><span style="width:<?=$stockLowPct?>%"></span></div>
        <div class="chart-row"><span><?=htmlspecialchars(zan_t('Imeisha','Out of Stock'))?></span><b><?=$stockOutPct?>%</b></div>
        <div class="bar"><span style="width:<?=$stockOutPct?>%"></span></div>
    </div>
    <div class="panel">
        <h3><?=htmlspecialchars(zan_t('Mauzo na Malipo (%)','Sales & Payments (%)'))?></h3>
        <div class="chart-row"><span><?=htmlspecialchars(zan_t('Imelipwa','Paid'))?></span><b><?=$paidPct?>%</b></div>
        <div class="bar"><span style="width:<?=$paidPct?>%"></span></div>
        <div class="chart-row"><span><?=htmlspecialchars(zan_t('Bado Kulipwa','Outstanding'))?></span><b><?=$duePct?>%</b></div>
        <div class="bar"><span style="width:<?=$duePct?>%"></span></div>
        <p class="muted" style="margin-top:10px">TZS <?=number_format($salesAll,2)?> <?=htmlspecialchars(zan_t('mauzo yote','total sales'))?></p>
    </div>
</div>

<div class="grid">

<div class="panel">

<h3>
Ankara za Karibuni
</h3>

<table class="table">

<tr>

<th>
Namba
</th>

<th>
Mteja
</th>

<th>
Jumla
</th>

<th>
Hali
</th>

</tr>

<?php foreach (
    $recent as $r
): ?>

<tr>

<td>

<a
    href="?page=print&id=<?=$r['id']?>"
>
<?=$r['invoice_no']?>
</a>

</td>

<td>
<?=htmlspecialchars(
    $r['customer']
    ?? 'Mteja wa kawaida'
)?>
</td>

<td>
TZS <?=number_format(
    $r['total'],
    2
)?>
</td>

<td>

<span class="badge <?=(
    ($r['status'] ?? '') === 'Imelipwa'
        ? 'badge-paid'
        : (
            ($r['status'] ?? '') === 'Sehemu imelipwa'
                ? 'badge-partial'
                : 'badge-unpaid'
        )
)?>">
<?=htmlspecialchars($r['status'] ?? 'Haijalipwa')?>
</span>

</td>

</tr>

<?php endforeach; ?>

</table>

</div>

<div class="panel">

<h3>
Tahadhari ya Stoo
</h3>

<?php foreach (
    $pdo
    ->query(
        'SELECT name,stock
         FROM products
         WHERE stock<=low_stock
         ORDER BY stock
         LIMIT 8'
    ) as $p
): ?>

<p>

<?=htmlspecialchars(
    $p['name']
)?>

—

<b class="low">
<?=$p['stock']?>
</b>

</p>

<?php endforeach; ?>

</div>

</div>

<!-- ======================================================
     CUSTOMERS
     ====================================================== -->

<?php elseif (
    $page === 'customers'
): ?>

<div class="head">

<h1>
Wateja
</h1>

<a
    class="btn"
    href="?page=add_customer"
>
+ Ongeza Mteja
</a>

</div>

<div class="panel">

<table class="table">

<tr>

<th>Jina</th>
<th>Simu</th>
<th>TIN</th>
<th>VRN</th>
<th>VAT</th>
<th>WhatsApp</th>
<th>Vitendo</th>

</tr>

<?php foreach (
    $customers as $c
): ?>

<tr>

<td>
<?=htmlspecialchars(
    $c['name']
)?>
</td>

<td>
<?=htmlspecialchars(
    $c['phone']
)?>
</td>

<td>
<?=htmlspecialchars(
    $c['tin']
)?>
</td>

<td>
<?=htmlspecialchars(
    $c['vrn']
)?>
</td>

<td>
<?=htmlspecialchars(
    $c['vat_status']
)?>
</td>

<td>
<?=htmlspecialchars(
    $c['whatsapp']
)?>
</td>

<td>

<a
    class="btn"
    href="?page=edit_customer&id=<?=$c['id']?>"
>
Hariri
</a>

<a
    class="btn black"
    href="?page=delete_customer&id=<?=$c['id']?>"
    onclick="return confirm('Unataka kumfuta mteja huyu?')"
>
Futa
</a>

</td>

</tr>

<?php endforeach; ?>

</table>

</div>

<div class="panel" style="margin-top:18px"><h2>Customers Who Owe Money</h2><table class="table"><tr><th>Customer</th><th>Phone</th><th>TIN</th><th>VRN</th><th>Invoices</th><th>Balance</th></tr><?php if(!$owingCustomers): ?><tr><td colspan="6">No outstanding customer balances.</td></tr><?php else: foreach($owingCustomers as $oc): ?><tr><td><?=htmlspecialchars($oc['name'])?></td><td><?=htmlspecialchars($oc['phone']?:'-')?></td><td><?=htmlspecialchars($oc['tin']?:'-')?></td><td><?=htmlspecialchars($oc['vrn']?:'-')?></td><td><?=$oc['invoices_count']?></td><td><b>TZS <?=number_format((float)$oc['balance'],2)?></b></td></tr><?php endforeach; endif; ?></table></div>

<!-- ======================================================
     ADD CUSTOMER
     ====================================================== -->

<?php elseif (
    $page === 'add_customer'
): ?>

<div class="head">

<h1>
Ongeza Mteja
</h1>

<a
    class="btn black"
    href="?page=customers"
>
Rudi
</a>

</div>

<div class="panel">

<form
    class="form"
    method="post"
    action="?page=save_customer"
>

<label>
Jina la Mteja
<input
    name="name"
    required
>
</label>

<label>
Namba ya Simu
<input
    name="phone"
>
</label>

<label>
WhatsApp
<input
    name="whatsapp"
>
</label>

<label>
Barua Pepe
<input
    name="email"
>
</label>

<label>
Anwani
<input
    name="address"
>
</label>

<label>
TIN
<input
    name="tin"
>
</label>

<label>
VRN
<input
    name="vrn"
>
</label>

<label>
Hali ya VAT

<select name="vat_status">

<option value="Mteja wa VAT">
Mteja wa VAT
</option>

<option
    value="Asiye wa VAT"
    selected
>
Asiye wa VAT
</option>

</select>

</label>

<div class="full">

<button class="btn">
Hifadhi Mteja
</button>

</div>

</form>

</div>

<!-- ======================================================
     EDIT CUSTOMER
     ====================================================== -->

<?php elseif (
    $page === 'edit_customer'
):

$id =
    (int)$_GET['id'];

$q =
    $pdo->prepare(
        'SELECT * FROM customers
         WHERE id=?'
    );

$q->execute([
    $id
]);

$c =
    $q->fetch();

?>

<div class="head">

<h1>
Hariri Mteja
</h1>

<a
    class="btn black"
    href="?page=customers"
>
Rudi
</a>

</div>

<div class="panel">

<form
    class="form"
    method="post"
    action="?page=update_customer"
>

<input
    type="hidden"
    name="id"
    value="<?=$c['id']?>"
>

<label>
Jina la Mteja

<input
    name="name"
    value="<?=htmlspecialchars(
        $c['name']
    )?>"
    required
>

</label>

<label class="full">
Namba ya Simu

<input
    name="phone"
    value="<?=htmlspecialchars(
        $c['phone']
    )?>"
>

</label>

<label>
WhatsApp

<input
    name="whatsapp"
    value="<?=htmlspecialchars(
        $c['whatsapp']
    )?>"
>

</label>

<label class="full">
Barua Pepe

<input
    name="email"
    value="<?=htmlspecialchars(
        $c['email']
    )?>"
>

</label>

<label>
Anwani

<input
    name="address"
    value="<?=htmlspecialchars(
        $c['address']
    )?>"
>

</label>

<label>
TIN

<input
    name="tin"
    value="<?=htmlspecialchars(
        $c['tin']
    )?>"
>

</label>

<label>
VRN

<input
    name="vrn"
    value="<?=htmlspecialchars(
        $c['vrn']
    )?>"
>

</label>

<label>
Hali ya VAT

<select name="vat_status">

<option
    value="Mteja wa VAT"
    <?=(
        $c['vat_status']
        ==
        'Mteja wa VAT'
        ? 'selected'
        : ''
    )?>
>
Mteja wa VAT
</option>

<option
    value="Asiye wa VAT"
    <?=(
        $c['vat_status']
        ==
        'Asiye wa VAT'
        ? 'selected'
        : ''
    )?>
>
Asiye wa VAT
</option>

</select>

</label>

<div class="full">

<button class="btn">
Hifadhi Mabadiliko
</button>

</div>

</form>

</div>

<!-- ======================================================
     SUPPLIERS / WASAMBAZAJI
     ====================================================== -->

<?php elseif ($page === 'suppliers'): ?>
<div class="head"><h1>Wasambazaji</h1></div>
<div class="panel">
<h2>➕ Ongeza Msambazaji</h2>
<form class="form" method="post" action="?page=save_supplier">
<label>Jina la Kampuni / Msambazaji *<input name="company_name" required></label>
<label>Contact Person<input name="contact_person"></label>
<label>Simu<input name="phone"></label>
<label>Email<input type="email" name="email"></label>
<label>TIN<input name="tin"></label>
<label>VRN<input name="vrn"></label>
<label>Anwani<input name="address"></label>
<label class="full">Notes / Maelezo<textarea name="notes"></textarea></label>
<div class="full"><button class="btn">➕ Hifadhi Msambazaji</button></div>
</form>
</div>
<div class="panel"><h2>🚚 Orodha ya Wasambazaji</h2><div style="overflow-x:auto"><table class="table">
<tr><th>#</th><th>Msambazaji</th><th>Contact</th><th>Simu</th><th>TIN/VRN</th><th>Address</th><th>Vitendo</th></tr>
<?php foreach($suppliers as $sp): ?><tr>
<td><?=$sp['id']?></td><td><b><?=htmlspecialchars($sp['company_name'])?></b><br><small><?=htmlspecialchars($sp['notes']??'')?></small></td>
<td><?=htmlspecialchars($sp['contact_person']?:'-')?></td><td><?=htmlspecialchars($sp['phone']?:'-')?></td>
<td>TIN: <?=htmlspecialchars($sp['tin']?:'-')?><br>VRN: <?=htmlspecialchars($sp['vrn']?:'-')?></td>
<td><?=htmlspecialchars($sp['address']?:'-')?></td>
<td><a class="btn" href="?page=edit_supplier&id=<?=$sp['id']?>">Hariri</a> <a class="btn black" href="?page=delete_supplier&id=<?=$sp['id']?>" onclick="return confirm('Una uhakika unataka kufuta msambazaji huyu?')">Futa</a></td>
</tr><?php endforeach; ?></table></div></div>

<?php elseif ($page === 'edit_supplier'): ?>
<?php $editId=(int)($_GET['id']??0); $eq=$pdo->prepare('SELECT * FROM suppliers WHERE id=?'); $eq->execute([$editId]); $es=$eq->fetch(); if(!$es) exit('Msambazaji hajapatikana.'); ?>
<div class="head"><h1>Hariri Msambazaji</h1><a class="btn black" href="?page=suppliers">Rudi</a></div>
<div class="panel"><form class="form" method="post" action="?page=update_supplier"><input type="hidden" name="id" value="<?=$es['id']?>">
<label>Jina la Kampuni / Msambazaji *<input name="company_name" value="<?=htmlspecialchars($es['company_name'])?>" required></label>
<label>Contact Person<input name="contact_person" value="<?=htmlspecialchars($es['contact_person']??'')?>"></label>
<label>Simu<input name="phone" value="<?=htmlspecialchars($es['phone']??'')?>"></label>
<label>Email<input type="email" name="email" value="<?=htmlspecialchars($es['email']??'')?>"></label>
<label>TIN<input name="tin" value="<?=htmlspecialchars($es['tin']??'')?>"></label>
<label>VRN<input name="vrn" value="<?=htmlspecialchars($es['vrn']??'')?>"></label>
<label>Anwani<input name="address" value="<?=htmlspecialchars($es['address']??'')?>"></label>
<label class="full">Notes / Maelezo<textarea name="notes"><?=htmlspecialchars($es['notes']??'')?></textarea></label>
<div class="full"><button class="btn">💾 Hifadhi Mabadiliko</button></div></form></div>

<!-- ======================================================
     PRODUCTS
     ====================================================== -->

<?php elseif (
    $page === 'products'
): ?>

<div class="head">

<h1>
Bidhaa na Stoo
</h1>

<a
    class="btn"
    href="?page=add_product"
>
+ Ongeza Bidhaa
</a>

</div>

<div class="panel">

<table class="table">

<tr>

<th>Bidhaa</th>
<th>Barcode</th>
<th>Namba</th>
<th>Aina</th>
<th>Msambazaji</th>
<th>Bei</th>
<th>Stoo</th>
<th>Stock Entry Date</th>
<th>Vitendo</th>

</tr>

<?php foreach (
    $products as $p
): ?>

<tr>

<td>
<?=htmlspecialchars(
    $p['name']
)?>
</td>

<td><?=htmlspecialchars($p['barcode'] ?? '') ?: '-'?></td>

<td>
<?=htmlspecialchars(
    $p['sku']
)?>
</td>

<td>
<?=htmlspecialchars(
    $p['category']
)?>
</td>

<td>
<?php $ps=$pdo->prepare('SELECT company_name FROM suppliers WHERE id=?'); $ps->execute([(int)($p['supplier_id']??0)]); ?>
<?=htmlspecialchars($ps->fetchColumn() ?: '-')?>
</td>

<td>
TZS <?=number_format(
    $p['price'],
    2
)?>
</td>

<td class="<?=($p['stock'] <= $p['low_stock'] ? 'low' : '')?>"><?=$p['stock']?></td>
<td><?=htmlspecialchars($p['stock_received_at']??'-')?></td>

<td>

<a
    class="btn"
    href="?page=edit_product&id=<?=$p['id']?>"
>
Hariri
</a>

<a
    class="btn black"
    href="?page=delete_product&id=<?=$p['id']?>"
    onclick="return confirm('Unataka kulifuta bidhaa hili?')"
>
Futa
</a>

</td>

</tr>

<?php endforeach; ?>

</table>

</div>

<!-- ======================================================
     ADD PRODUCT
     ====================================================== -->

<?php elseif (
    $page === 'add_product'
): ?>

<div class="head">

<h1>
Ongeza Bidhaa
</h1>

<a
    class="btn black"
    href="?page=products"
>
Rudi
</a>

</div>

<div class="panel">

<form
    class="form"
    method="post"
    action="?page=save_product"
>

<label>
Jina la Bidhaa
<input
    name="name"
    required
>
</label>

<label>
Barcode
<input name="barcode" id="productBarcode" inputmode="numeric" autocomplete="off" placeholder="Scan barcode hapa">
<small>Weka cursor hapa kisha scan kwa scanner ya USB.</small>
</label>

<label>
Msambazaji
<select name="supplier_id">
<option value="0">-- Chagua Msambazaji --</option>
<?php foreach ($suppliers as $sp): ?>
<option value="<?=$sp['id']?>"><?=htmlspecialchars($sp['company_name'])?></option>
<?php endforeach; ?>
</select>
</label>

<label>
Namba ya Bidhaa
<input name="sku">
</label>

<label>
Aina ya Bidhaa
<input
    name="category"
>
</label>

<label>
Bei ya Kuuza
<input
    name="price"
    type="number"
    step=".01"
    required
>
</label>

<label>
Bei ya Kununua
<input
    name="cost"
    type="number"
    step=".01"
>
</label>

<label>
Kiasi cha Stoo
<input
    name="stock"
    type="number"
    min="0"
    required
>
</label>

<label>
Kiwango cha Tahadhari
<input name="low_stock" type="number" value="5">
</label>
<label>
Stock Entry Date
<input name="stock_received_at" type="date" value="<?=date('Y-m-d')?>">
</label>

<div class="full">

<button class="btn">
Hifadhi Bidhaa
</button>

</div>

</form>

</div>

<!-- ======================================================
     EDIT PRODUCT
     ====================================================== -->

<?php elseif (
    $page === 'edit_product'
):

$id =
    (int)$_GET['id'];

$q =
    $pdo->prepare(
        'SELECT * FROM products
         WHERE id=?'
    );

$q->execute([
    $id
]);

$p =
    $q->fetch();

?>

<div class="head">

<h1>
Hariri Bidhaa
</h1>

<a
    class="btn black"
    href="?page=products"
>
Rudi
</a>

</div>

<div class="panel">

<form
    class="form"
    method="post"
    action="?page=update_product"
>

<input
    type="hidden"
    name="id"
    value="<?=$p['id']?>"
>

<label>
Jina la Bidhaa

<input
    name="name"
    value="<?=htmlspecialchars(
        $p['name']
    )?>"
    required
>

</label>

<label>
Barcode
<input name="barcode" id="editProductBarcode" value="<?=htmlspecialchars($p['barcode'] ?? '')?>" inputmode="numeric" autocomplete="off" placeholder="Scan barcode hapa">
</label>

<label>
Msambazaji
<select name="supplier_id">
<option value="0">-- Chagua Msambazaji --</option>
<?php foreach ($suppliers as $sp): ?>
<option value="<?=$sp['id']?>" <?=((int)($p['supplier_id'] ?? 0)===(int)$sp['id']?'selected':'')?>><?=htmlspecialchars($sp['company_name'])?></option>
<?php endforeach; ?>
</select>
</label>

<label>
Namba ya Bidhaa
<input name="sku" value="<?=htmlspecialchars($p['sku'])?>">
</label>

<label>
Aina ya Bidhaa

<input
    name="category"
    value="<?=htmlspecialchars(
        $p['category']
    )?>"
>

</label>

<label>
Bei ya Kuuza

<input
    name="price"
    type="number"
    step=".01"
    value="<?=$p['price']?>"
    required
>

</label>

<label>
Bei ya Kununua

<input
    name="cost"
    type="number"
    step=".01"
    value="<?=$p['cost']?>"
>

</label>

<label>
Kiasi cha Stoo

<input
    name="stock"
    type="number"
    min="0"
    value="<?=$p['stock']?>"
    required
>

</label>

<label>
Kiwango cha Tahadhari

<input
    name="low_stock"
    type="number"
    value="<?=$p['low_stock']?>"
>

</label>
<label>
Stock Entry Date
<input name="stock_received_at" type="date" value="<?=htmlspecialchars($p['stock_received_at'] ?? date('Y-m-d'))?>">
</label>

<div class="full">

<button class="btn">
Hifadhi Mabadiliko
</button>

</div>

</form>

</div>

<!-- ======================================================
     NEW INVOICE
     ====================================================== -->

<?php elseif (
    $page === 'new_invoice'
): ?>

<div class="head">

<div>

<h1>
Tengeneza Ankara
</h1>

<span class="muted">
VAT 18% itaongezwa kwa mteja wa VAT pekee.
</span>

</div>

</div>

<?php if (
    isset($error)
): ?>

<div class="notice">
<?=htmlspecialchars(
    $error
)?>
</div>

<?php endif; ?>

<div class="invoicebox">

<form
    method="post"
    action="?page=save_invoice"
>

<label>

Mteja

<select
    name="customer_id"
    id="customer_id"
    required
    onchange="updateVatNotice()"
>

<option value="">
Chagua Mteja
</option>

<?php foreach (
    $customers as $c
): ?>

<option
    value="<?=$c['id']?>"
    data-vat="<?=(
        vat_customer($c)
        ? '1'
        : '0'
    )?>"
>

<?=htmlspecialchars(
    $c['name']
)?>

—

<?=htmlspecialchars(
    $c['vat_status']
)?>

</option>

<?php endforeach; ?>

</select>

</label>

<div
    id="vatNotice"
    class="vat-note"
>
Chagua mteja kuona hali ya VAT.
</div>

<div style="margin:15px 0;padding:15px;border:1px solid #ddd;border-radius:10px;background:#fafafa">
<label>Scan Barcode ya Bidhaa
<input type="text" id="invoiceBarcode" placeholder="Weka cursor hapa kisha scan barcode..." autocomplete="off" autofocus style="font-size:18px;font-weight:bold">
</label>
<small id="barcodeMessage">Scanner ya USB inaweza kutumika moja kwa moja.</small>
</div>

<div id="mistari">

<?php if (
    $products
): ?>

<div class="invoice-row">

<select
    name="product_id[]"
    onchange="bei(this)"
>

<?php foreach (
    $products as $p
): ?>

<option
    value="<?=$p['id']?>"
    data-price="<?=$p['price']?>"
    data-barcode="<?=htmlspecialchars($p['barcode'] ?? '')?>"
>

<?=htmlspecialchars(
    $p['name']
)?>

(Stoo:
<?=$p['stock']?>)

</option>

<?php endforeach; ?>

</select>

<input
    name="qty[]"
    type="number"
    min="1"
    value="1"
    oninput="hesabu()"
>

<input
    name="price[]"
    type="number"
    step=".01"
    value="<?=$products[0]['price']?>"
    oninput="hesabu()"
>

<input
    class="sub"
    value="<?=$products[0]['price']?>"
    readonly
>

<button
    type="button"
    class="btn"
    onclick="this.parentNode.remove();hesabu()"
>
×
</button>

</div>

<?php endif; ?>

</div>

<button
    type="button"
    class="btn black"
    onclick="mstari()"
>
+ Ongeza Bidhaa
</button>

<div class="labor-box">
    <b><?=htmlspecialchars(zan_t('Kazi / Labor Charge','Labor Charge'))?></b>
    <div class="labor-grid" style="margin-top:10px">
        <input name="labor_description" value="" placeholder="<?=htmlspecialchars(zan_t('Maelezo ya kazi / survey / installation','Work / survey / installation description'))?>">
        <input name="labor_charge" id="laborCharge" type="number" step=".01" min="0" value="0" oninput="hesabu()" placeholder="0.00">
    </div>
</div>

<div class="discount-box" style="margin:15px 0;padding:15px;border:1px solid #ddd;border-radius:10px;background:#fff8cf">
    <b>Discount</b>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px">
        <select name="discount_type" id="discountType" onchange="hesabu()">
            <option value="amount">Kiasi (TZS)</option>
            <option value="percent">Asilimia (%)</option>
        </select>
        <input name="discount_value" id="discountValue" type="number" step=".01" min="0" value="0" oninput="hesabu()" placeholder="0.00">
    </div>
    <small>Discount inapunguza jumla ya invoice tu; haiathiri kiasi cha bidhaa kwenye stoo.</small>
</div>

<div class="totals">

<div>
<span>Subtotal</span>
<b id="subtotalText">
TZS 0.00
</b>
</div>

<div>
<span>Discount</span>
<b id="discountText">
TZS 0.00
</b>
</div>

<div>
<span>VAT 18%</span>
<b id="vatText">
TZS 0.00
</b>
</div>

<div class="grand">
<span>Total</span>
<b id="jumla">
TZS 0.00
</b>
</div>

<div>
<span>Malipo</span>

<input
    name="paid"
    type="number"
    step=".01"
    value="0"
    oninput="hesabu()"
>

</div>

<div>
<span>Balance</span>

<b id="salio">
TZS 0.00
</b>

</div>

</div>

<button
    class="btn"
    type="submit"
>
Hifadhi na Chapisha
</button>

</form>

</div>

<!-- ======================================================
     INVOICES
     ====================================================== -->

<?php elseif (
    $page === 'invoices'
): ?>

<div class="head">

<h1>
Ankara
</h1>

<a
    class="btn"
    href="?page=new_invoice"
>
+ Tengeneza Ankara
</a>

</div>

<div class="panel">

<table class="table">

<tr>

<th>Namba</th>
<th>Mteja</th>
<th>Subtotal</th>
<th>Discount</th>
<th>VAT</th>
<th>Jumla</th>
<th>Malipo</th>
<th>Hali</th>
<th>Vitendo</th>

</tr>

<?php foreach (
    $recent as $r
): ?>

<tr>

<td>
<?=$r['invoice_no']?>
</td>

<td>
<?=htmlspecialchars(
    $r['customer']
    ?? 'Mteja wa kawaida'
)?>
</td>

<td>
TZS <?=number_format(
    (float)$r['subtotal'],
    2
)?>
</td>

<td>
TZS <?=number_format(
    (float)($r['discount_amount'] ?? 0),
    2
)?>
</td>

<td>
TZS <?=number_format(
    (float)$r['vat'],
    2
)?>
</td>

<td>
TZS <?=number_format(
    $r['total'],
    2
)?>
</td>

<td>
TZS <?=number_format(
    $r['paid'],
    2
)?>
</td>

<td>
<span class="badge <?=(
    ($r['status'] ?? '') === 'Imelipwa'
        ? 'badge-paid'
        : (
            ($r['status'] ?? '') === 'Sehemu imelipwa'
                ? 'badge-partial'
                : 'badge-unpaid'
        )
)?>">
<?=htmlspecialchars($r['status'] ?? 'Haijalipwa')?>
</span>
</td>

<td>

<a
    class="btn"
    href="?page=print&id=<?=$r['id']?>"
>
Angalia
</a>

<a class="btn black" href="?page=edit_invoice&id=<?=$r['id']?>">Hariri</a>
<a class="btn black" href="?page=delete_invoice&id=<?=$r['id']?>" onclick="return confirm('Delete this invoice? Stock will be returned to inventory.')">Delete</a>

</td>

</tr>

<?php endforeach; ?>

</table>

</div>

<!-- ======================================================
     EDIT INVOICE
     ====================================================== -->

<?php elseif (
    $page === 'edit_invoice'
):

$id =
    (int)$_GET['id'];

$iq =
    $pdo->prepare(
        'SELECT
            i.*,
            c.name customer,
            c.vat_status
         FROM invoices i
         LEFT JOIN customers c
            ON c.id=i.customer_id
         WHERE i.id=?'
    );

$iq->execute([
    $id
]);

$inv =
    $iq->fetch();

if (!$inv):

?>

<div class="notice">
Ankara haipatikani.
</div>

<?php else:

$ii =
    $pdo->prepare(
        'SELECT *
         FROM invoice_items
         WHERE invoice_id=?'
    );

$ii->execute([
    $id
]);

$items =
    $ii->fetchAll();

$editLaborDescription = '';
$editLaborCharge = 0;
foreach ($items as $it) {
    if (empty($it['product_id'])) {
        $editLaborDescription = $it['description'] ?? '';
        $editLaborCharge = (float)($it['subtotal'] ?? 0);
    }
}

?>

<div class="head">

<h1>
Hariri Ankara
<?=$inv['invoice_no']?>
</h1>

<a
    class="btn black"
    href="?page=invoices"
>
Rudi
</a>

</div>

<?php if (
    isset($error)
): ?>

<div class="notice">
<?=htmlspecialchars(
    $error
)?>
</div>

<?php endif; ?>

<div class="invoicebox">

<form
    method="post"
    action="?page=update_invoice"
>

<input
    type="hidden"
    name="invoice_id"
    value="<?=$id?>"
>

<label>

Mteja

<select
    name="customer_id"
    id="edit_customer_id"
    onchange="updateEditVat()"
    required
>

<?php foreach (
    $customers as $c
): ?>

<option
    value="<?=$c['id']?>"
    data-vat="<?=(
        vat_customer($c)
        ? '1'
        : '0'
    )?>"
    <?=$c['id']==$inv['customer_id']
        ? 'selected'
        : ''?>
>

<?=htmlspecialchars(
    $c['name']
)?>

—

<?=htmlspecialchars(
    $c['vat_status']
)?>

</option>

<?php endforeach; ?>

</select>

</label>

<div
    id="editVatNotice"
    class="vat-note"
></div>

<div id="editMistari">

<?php foreach (
    $items as $item
): ?>

<div class="invoice-row">

<select
    name="product_id[]"
    onchange="bei(this)"
>

<?php foreach (
    $products as $p
): ?>

<option
    value="<?=$p['id']?>"
    data-price="<?=$p['price']?>"
    <?=$p['id']==$item['product_id']
        ? 'selected'
        : ''?>
>

<?=htmlspecialchars(
    $p['name']
)?>

(Stoo:
<?=$p['stock']?>)

</option>

<?php endforeach; ?>

</select>

<input
    name="qty[]"
    type="number"
    min="1"
    value="<?=$item['qty']?>"
    oninput="hesabuEdit()"
>

<input
    name="price[]"
    type="number"
    step=".01"
    value="<?=$item['price']?>"
    oninput="hesabuEdit()"
>

<input
    class="sub"
    value="<?=number_format(
        $item['subtotal'],
        2,
        '.',
        ''
    )?>"
    readonly
>

<button
    type="button"
    class="btn"
    onclick="this.parentNode.remove();hesabuEdit()"
>
×
</button>

</div>

<?php endforeach; ?>

</div>

<button
    type="button"
    class="btn black"
    onclick="mstariEdit()"
>
+ Ongeza Bidhaa
</button>

<div class="labor-box">
    <b><?=htmlspecialchars(zan_t('Kazi / Labor Charge','Labor Charge'))?></b>
    <div class="labor-grid" style="margin-top:10px">
        <input name="labor_description" value="<?=htmlspecialchars($editLaborDescription)?>" placeholder="Maelezo ya kazi">
        <input name="labor_charge" id="editLaborCharge" type="number" step=".01" min="0" value="<?=number_format($editLaborCharge,2,'.','')?>" oninput="hesabuEdit()">
    </div>
</div>

<div class="discount-box" style="margin:15px 0;padding:15px;border:1px solid #ddd;border-radius:10px;background:#fff8cf">
    <b>Discount</b>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px">
        <select name="discount_type" id="editDiscountType" onchange="hesabuEdit()">
            <option value="amount" <?=($inv['discount_type'] ?? 'amount') === 'amount' ? 'selected' : ''?>>Kiasi (TZS)</option>
            <option value="percent" <?=($inv['discount_type'] ?? '') === 'percent' ? 'selected' : ''?>>Asilimia (%)</option>
        </select>
        <input name="discount_value" id="editDiscountValue" type="number" step=".01" min="0" value="<?=number_format((float)($inv['discount_value'] ?? 0),2,'.','')?>" oninput="hesabuEdit()" placeholder="0.00">
    </div>
    <small>Discount inapunguza jumla ya invoice tu; haiathiri kiasi cha bidhaa kwenye stoo.</small>
</div>

<div class="totals">

<div>
<span>Subtotal</span>
<b id="editSubtotal">
TZS 0.00
</b>
</div>

<div>
<span>Discount</span>
<b id="editDiscount">
TZS 0.00
</b>
</div>

<div>
<span>VAT 18%</span>
<b id="editVat">
TZS 0.00
</b>
</div>

<div class="grand">
<span>Total</span>
<b id="editTotal">
TZS 0.00
</b>
</div>

<div>

<span>
Malipo
</span>

<input
    name="paid"
    type="number"
    step=".01"
    value="<?=$inv['paid']?>"
    oninput="hesabuEdit()"
>

</div>

<div>

<span>
Balance
</span>

<b id="editBalance">
TZS 0.00
</b>

</div>

</div>

<button class="btn">
Hifadhi Mabadiliko na Chapisha
</button>

</form>

</div>

<script>

updateEditVat();
hesabuEdit();

</script>

<?php endif; ?>

<!-- ======================================================
     SETTINGS
     ====================================================== -->

<?php elseif (
    $page === 'settings'
): ?>

<div class="head">

<h1>
Mipangilio
</h1>

</div>

<?php if (
    isset($_GET['saved'])
): ?>

<div class="notice">
Mabadiliko yamehifadhiwa kikamilifu.
</div>

<?php endif; ?>

<div class="panel">

<h3>
Taarifa za Kampuni
</h3>

<form
    class="form"
    method="post"
    enctype="multipart/form-data"
    action="?page=save_settings"
>

<label>
Nembo ya Kampuni

<input
    name="logo"
    type="file"
    accept="image/png,image/jpeg,image/webp"
>

</label>

<label>
Jina la Kampuni

<input
    name="company_name"
    value="<?=htmlspecialchars(
        $settings['company_name'] ?? ''
    )?>"
>

</label>

<label>
Maelezo mafupi

<input
    name="tagline"
    value="<?=htmlspecialchars(
        $settings['tagline'] ?? ''
    )?>"
>

</label>

<label>
Namba ya Simu

<input
    name="phone"
    value="<?=htmlspecialchars(
        $settings['phone'] ?? ''
    )?>"
>

</label>

<label>
Barua Pepe

<input
    name="email"
    value="<?=htmlspecialchars(
        $settings['email'] ?? ''
    )?>"
>

</label>

<label>
Anwani

<input
    name="address"
    value="<?=htmlspecialchars(
        $settings['address'] ?? ''
    )?>"
>

</label>

<label>
TIN

<input
    name="tin"
    value="<?=htmlspecialchars(
        $settings['tin'] ?? ''
    )?>"
>

</label>

<label>
VRN/VAT ya Kampuni

<input
    name="vat"
    value="<?=htmlspecialchars(
        $settings['vat'] ?? ''
    )?>"
>

</label>

<label>
Currency

<input
    name="currency"
    value="<?=htmlspecialchars(
        $settings['currency']
        ?? 'TZS'
    )?>"
>

</label>

<label>
Jina la Benki

<input
    name="bank_name"
    value="<?=htmlspecialchars(
        $settings['bank_name']
        ?? ''
    )?>"
>

</label>

<label>
Jina la Akaunti

<input
    name="bank_account_name"
    value="<?=htmlspecialchars(
        $settings['bank_account_name']
        ?? ''
    )?>"
>

</label>

<label>
Namba ya Akaunti

<input
    name="bank_account_number"
    value="<?=htmlspecialchars(
        $settings['bank_account_number']
        ?? ''
    )?>"
>

</label>

<label>
Tawi la Benki

<input
    name="bank_branch"
    value="<?=htmlspecialchars(
        $settings['bank_branch']
        ?? ''
    )?>"
>

</label>

<label>
Namba ya Lipa / Payment Number
<input name="pay_number" value="<?=htmlspecialchars($settings['pay_number'] ?? '')?>" placeholder="Mfano: 07XXXXXXXX">
</label>

<label>
QR Code ya Lipa
<input name="pay_qr" type="file" accept="image/png,image/jpeg,image/webp">
<?php if (!empty($settings['pay_qr']) && file_exists(__DIR__ . '/' . $settings['pay_qr'])): ?>
<br><img src="<?=htmlspecialchars($settings['pay_qr'])?>" class="pay-qr" alt="QR Code">
<?php endif; ?>
</label>

<label class="full">

Ujumbe wa Chini ya Ankara

<textarea
    name="footer"
    rows="3"
><?=htmlspecialchars(
    $settings['footer']
    ?? ''
)?></textarea>

</label>

<div class="full">

<button class="btn">
Hifadhi Mabadiliko
</button>

</div>

</form>

</div>

<!-- ======================================================
     BACKUP & RESTORE
     ====================================================== -->

<?php if (can_do('settings')): ?>

<?php
$googleBackups = [];
$googleError = $_GET['google_error'] ?? '';

if (google_is_connected()) {
    try {
        $googleBackups = google_list_backups();
    } catch (Exception $e) {
        $googleError = $e->getMessage();
    }
}
?>

<div
    class="panel backup-box"
    style="margin-top:18px"
>

<h3>
💾 Backup & Restore
</h3>

<p class="muted">
Hifadhi taarifa za Zantronix kwenye computer na Google Drive.
</p>

<?php if (isset($_GET['google_connected'])): ?>

<div class="notice">
✓ Google Drive imeunganishwa kikamilifu.
</div>

<?php endif; ?>

<?php if (isset($_GET['google_backup'])): ?>

<div class="notice">
✓ Backup imehifadhiwa kwenye Google Drive.
</div>

<?php endif; ?>

<?php if (isset($_GET['google_restored'])): ?>

<div class="notice">
✓ Restore kutoka Google Drive imekamilika.
</div>

<?php endif; ?>

<?php if (isset($_GET['google_disconnected'])): ?>

<div class="notice">
Google Drive imekatwa kwenye mfumo.
</div>

<?php endif; ?>

<?php if ($googleError): ?>

<div class="notice">
Google Drive error:
<?=htmlspecialchars($googleError)?>
</div>

<?php endif; ?>

<div class="backup-box">

<h3>
☁️ Google Drive
</h3>

<?php if (!google_is_connected()): ?>

<p class="muted">
Google Drive bado haijaunganishwa.
</p>

<a
    class="btn"
    href="?page=settings&action=google_connect"
>
🔗 UNGANISHA GOOGLE DRIVE
</a>

<?php else: ?>

<div class="notice">
✓ Google Drive imeunganishwa.
<br>
Folder: <strong>Zantronix Backups</strong>
</div>

<div class="backup-buttons">

<a
    class="btn"
    href="?page=settings&action=google_backup"
    onclick="return confirmBackup()"
>
☁️ BACKUP KWENDA GOOGLE DRIVE
</a>

<a
    class="btn black"
    href="?page=settings&action=google_disconnect"
    onclick="return confirm('Unataka kukata Google Drive kwenye Zantronix?')"
>
🔌 KATA GOOGLE DRIVE
</a>

</div>

<hr style="margin:25px 0">

<h3>
♻️ Backups za Google Drive
</h3>

<?php if (empty($googleBackups)): ?>

<p class="muted">
Hakuna backup iliyopo Google Drive bado.
</p>

<?php else: ?>

<?php foreach ($googleBackups as $gb): ?>

<div
    style="
        border:1px solid #e5e5e5;
        border-radius:12px;
        padding:15px;
        margin:12px 0;
    "
>

<strong>
☁️
<?=htmlspecialchars($gb->getName())?>
</strong>

<div
    class="muted"
    style="margin:7px 0"
>
Tarehe:
<?=htmlspecialchars(
    date(
        'd/m/Y H:i',
        strtotime($gb->getCreatedTime())
    )
)?>
</div>

<form
    method="post"
    action="?page=settings&action=google_restore"
    onsubmit="return confirmRestore()"
>

<input
    type="hidden"
    name="file_id"
    value="<?=htmlspecialchars(
        $gb->getId(),
        ENT_QUOTES
    )?>"
>

<button
    class="btn black"
    type="submit"
>
♻️ RESTORE
</button>

</form>

</div>

<?php endforeach; ?>

<?php endif; ?>

<?php endif; ?>

</div>

<hr style="margin:25px 0">

<h3>
💻 Backup ya Computer
</h3>

<div class="backup-buttons">

<a
    class="btn"
    href="?page=settings&action=backup"
    onclick="return confirmBackup()"
>
💾 TENGENEZA BACKUP
</a>

</div>

<p class="muted" style="margin-top:10px">
Kama Google Drive imeunganishwa, backup ya mfumo itatumwa moja kwa moja Google Drive.
</p>

<hr style="margin:25px 0">

<h3>
♻️ Restore kutoka Computer
</h3>

<p class="muted">
Chagua backup ya Zantronix yenye <strong>.zip</strong>.
</p>

<div class="backup-warning">

<strong>⚠️ Tahadhari:</strong>

<br>

Restore itabadilisha taarifa za sasa.
Backup ya sasa itahifadhiwa kwanza.

</div>

<form
    method="post"
    action="?page=settings&action=restore_backup"
    enctype="multipart/form-data"
    onsubmit="return confirmRestore()"
>

<input
    type="file"
    name="backup_zip"
    accept=".zip"
    required
    style="
        display:block;
        margin-bottom:15px;
    "
>

<button
    class="btn black"
    type="submit"
>
♻️ KUBALI NA RESTORE
</button>

</form>

<?php if (isset($_GET['restored'])): ?>

<div
    class="notice"
    style="margin-top:15px"
>

✓ <strong>Restore imekamilika.</strong>

<br>

Taarifa za backup zimerejeshwa kwenye mfumo.

</div>

<?php endif; ?>

</div>

<?php endif; ?>

<!-- ======================================================
     USERS
     ====================================================== -->

<?php elseif (
    $page === 'users' &&
    can_do('users')
):

    $users =
    $pdo
    ->query(
        "SELECT * FROM users
         WHERE account_system='business'
         ORDER BY id DESC"
    )
    ->fetchAll();

?>

<div class="head">

<h1>
Watumiaji na Ruhusa
</h1>

<a
    class="btn"
    href="?page=add_user"
>
+ Tengeneza Mtumiaji
</a>

</div>

<div class="panel">

<table class="table">

<tr>
<th>Jina</th>
<th>Username</th>
<th>Simu</th>
<th>Nafasi</th>
<th>Hali</th>
<th>Kitendo</th>
</tr>

<?php foreach ($users as $u): ?>

<tr>

<td><?=htmlspecialchars($u['name'])?></td>

<td><?=htmlspecialchars($u['username'])?></td>

<td><?=htmlspecialchars($u['phone'] ?? '')?></td>

<td><?=htmlspecialchars($u['role'])?></td>

<td><?=$u['active'] ? 'Hai' : 'Imezimwa'?></td>

<td>

<a
    class="btn"
    href="?page=edit_user&id=<?=$u['id']?>"
>
Hariri
</a>

<?php if (!($u['role'] === 'Msimamizi' && (int)$u['id'] === (int)($_SESSION['user']['id'] ?? 0))): ?>
<a
    class="btn"
    href="?page=toggle_user&id=<?=$u['id']?>"
>
<?=$u['active'] ? 'Zima' : 'Washa'?>
</a>
<?php endif; ?>
<?php if($u['role']!=='Msimamizi'): ?><a class="btn black" href="?page=delete_user&id=<?=$u['id']?>" onclick="return confirm('Delete this user permanently?')">Delete</a><?php endif; ?>

</td>

</tr>

<?php endforeach; ?>

</table>

</div>

<!-- ======================================================
     ADD USER
     ====================================================== -->

<?php elseif (
    $page === 'add_user' &&
    can_do('users')
): ?>

<div class="head">

<h1>
Tengeneza Mtumiaji
</h1>

<a
    class="btn black"
    href="?page=users"
>
Rudi
</a>

</div>

<div class="panel">

<form
    class="form"
    method="post"
    action="?page=save_user"
>

<label>
Jina kamili

<input
    name="name"
    required
>

</label>

<label>
Jina la kuingia

<input
    name="username"
    required
>

</label>

<label>
Namba ya Simu

<input
    name="phone"
    type="tel"
    placeholder="07XXXXXXXX"
>

</label>

<label>
Nenosiri

<input
    name="password"
    type="password"
    required
>

</label>

<label>
Nafasi

<select name="role">

<option>
Mauzo
</option>

<option>
Stoo
</option>

<option>
Mhasibu
</option>

<option>
Msimamizi
</option>

</select>

</label>

<div class="full">

<b>
Ruhusa
</b>

</div>

<?php foreach (
    [
        'dashboard' =>
            'Dashibodi',

        'customers' =>
            'Wateja',

        'products' =>
            'Bidhaa na Stoo',

        'invoices' =>
            'Ankara',

        'reports' =>
            'Ripoti',

        'expenses' =>
            'Expenses',

        'settings' =>
            'Mipangilio',

        'users' =>
            'Watumiaji',

        'activity' =>
            'Historia ya Shughuli'
    ] as $k => $v
): ?>

<label>

<input
    type="checkbox"
    name="perm_<?=$k?>"
    style="width:auto"
>

<?=$v?>

</label>

<?php endforeach; ?>

<div class="full">
<b>Mifumo ya kutumia</b>
</div>

<label>
<input type="checkbox" name="perm_business_system" value="1" checked style="width:auto">
Business System
</label>

<div class="full">

<button class="btn">
Hifadhi Mtumiaji
</button>

</div>

</form>

</div>

<!-- ======================================================
     EDIT USER
     ====================================================== -->

<?php elseif (
    $page === 'edit_user' &&
    can_do('users')
):

$id = (int)($_GET['id'] ?? 0);

$s = $pdo->prepare('SELECT * FROM users WHERE id=?');
$s->execute([$id]);
$editUser = $s->fetch();

if (!$editUser) {
    exit('Mtumiaji hajapatikana.');
}

$editPerms = json_decode($editUser['permissions'] ?? '{}', true);
if (!is_array($editPerms)) {
    $editPerms = [];
}

?>

<div class="head">

<h1>
Hariri Mtumiaji
</h1>

<form
    class="form"
    method="post"
    action="?page=update_user"
>

<input
    type="hidden"
    name="id"
    value="<?=$editUser['id']?>"
>

<label>
Jina kamili

<input
    name="name"
    value="<?=htmlspecialchars($editUser['name'])?>"
    required
>

</label>

<label>
Jina la kuingia

<input
    name="username"
    value="<?=htmlspecialchars($editUser['username'])?>"
    required
>

</label>

<label>
Namba ya Simu

<input
    name="phone"
    type="tel"
    value="<?=htmlspecialchars($editUser['phone'] ?? '')?>"
    placeholder="07XXXXXXXX"
>

</label>

<label>
Password Mpya

<input
    name="password"
    type="password"
    autocomplete="new-password"
>

<small>Acha tupu kama hutaki kubadilisha password.</small>

</label>

<label>
Nafasi

<select name="role">

<?php foreach (
    ['Mauzo','Stoo','Mhasibu','Msimamizi']
    as $role
): ?>

<option
    value="<?=htmlspecialchars($role)?>"
    <?=$editUser['role'] === $role ? 'selected' : ''?>
>
<?=htmlspecialchars($role)?>
</option>

<?php endforeach; ?>

</select>

</label>

<div class="full">
<b>Ruhusa</b>
</div>

<?php foreach (
    [
        'dashboard' => 'Dashibodi',
        'customers' => 'Wateja',
        'products' => 'Bidhaa na Stoo',
        'invoices' => 'Ankara',
        'reports' => 'Ripoti',
        'expenses' => 'Matumizi',
        'settings' => 'Mipangilio',
        'users' => 'Watumiaji',
        'activity' => 'Historia ya Shughuli'
    ] as $k => $v
): ?>

<label>

<input
    type="checkbox"
    name="perm_<?=$k?>"
    style="width:auto"
    <?=$editPerms[$k] ?? 0 ? 'checked' : ''?>
>

<?=htmlspecialchars($v)?>

</label>

<?php endforeach; ?>

<div class="full">
<b>Mifumo ya kutumia</b>
</div>

<label>
<input
    type="checkbox"
    name="perm_business_system"
    value="1"
    <?=!empty($editPerms['business_system']) ? 'checked' : ''?>
    style="width:auto"
>
Business System
</label>

<div class="full">

<button class="btn" type="submit">
Hifadhi Mabadiliko
</button>

</div>

</form>

</div>

<!-- ======================================================
     ACTIVITY
     ====================================================== -->

<?php elseif (
    $page === 'activity' &&
    can_do('activity')
):

$logs =
    $pdo
    ->query(
        'SELECT
            a.*,
            u.name username
         FROM activity_log a
         LEFT JOIN users u
            ON u.id=a.user_id
         ORDER BY a.id DESC
         LIMIT 100'
    )
    ->fetchAll();

?>

<div class="head">

<h1>
Historia ya Shughuli
</h1>

</div>

<div class="panel">

<table class="table">

<tr>

<th>Tarehe</th>
<th>Mtumiaji</th>
<th>Kitendo</th>
<th>Maelezo</th>

</tr>

<?php foreach (
    $logs as $l
): ?>

<tr>

<td>
<?=htmlspecialchars(
    $l['created_at']
)?>
</td>

<td>
<?=htmlspecialchars(
    $l['username']
    ?? 'Mfumo'
)?>
</td>

<td>
<?=htmlspecialchars(
    $l['action']
)?>
</td>

<td>
<?=htmlspecialchars(
    $l['details']
)?>
</td>

</tr>

<?php endforeach; ?>

</table>

</div>

<!-- ======================================================
     QUOTATIONS
     ====================================================== -->
<?php elseif($page==='quotations'): ?>
<div class="head"><h1>Quotations</h1><a class="btn" href="?page=new_quotation">+ New Quotation</a></div>
<div class="panel"><table class="table"><tr><th>Quotation No.</th><th>Customer</th><th>Date</th><th>Total</th><th>Actions</th></tr><?php foreach($quotationRows as $q): ?><tr><td><?=htmlspecialchars($q['quotation_no'])?></td><td><?=htmlspecialchars($q['customer']??'-')?></td><td><?=htmlspecialchars($q['created_at'])?></td><td>TZS <?=number_format((float)$q['total'],2)?></td><td><a class="btn" href="?page=print_quotation&id=<?=$q['id']?>">Print</a> <a class="btn black" href="?page=edit_quotation&id=<?=$q['id']?>">Edit</a> <a class="btn black" href="?page=delete_quotation&id=<?=$q['id']?>" onclick="return confirm('Delete this quotation?')">Delete</a></td></tr><?php endforeach; ?></table></div>
<?php elseif($page==='new_quotation'): ?>
<div class="head"><h1>New Quotation</h1><a class="btn black" href="?page=quotations">Back</a></div>
<div class="invoicebox">
<form method="post" enctype="multipart/form-data" action="?page=save_quotation" id="quotationForm">
<div class="form" style="margin-bottom:15px">
<label class="full">Customer<select name="customer_id" required><option value="">Select Customer</option><?php foreach($customers as $c): ?><option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option><?php endforeach; ?></select></label>
<label>Quotation Date<input type="date" value="<?=date('Y-m-d')?>" disabled></label>
<label>Valid Until<input name="valid_until" type="date" value="<?=date('Y-m-d',strtotime('+30 days'))?>" required></label>
</div>
<h3 style="margin:15px 0 8px">Products / Services</h3>
<div class="invoice-row" style="font-weight:700;margin-bottom:5px"><span>Description</span><span>Qty</span><span>Unit Price</span><span>Action</span></div>
<div id="quotationProductRows">
<div class="invoice-row product-row"><select name="product_id[]" onchange="bei(this)" required><?php foreach($products as $p): ?><option value="<?=$p['id']?>" data-price="<?=$p['price']?>"><?=htmlspecialchars($p['name'])?> (Stock: <?=$p['stock']?>)</option><?php endforeach; ?></select><input name="qty[]" type="number" min="1" value="1" required><input name="price[]" type="number" step=".01" min="0" value="<?=($products[0]['price']??0)?>" required><button type="button" class="btn black" onclick="removeQuotationRow(this)">Remove</button></div>
</div>
<button type="button" class="btn" onclick="addQuotationRow()">+ Add Product</button>
<div class="labor-box"><b><?=htmlspecialchars(zan_t('Kazi / Labor Charge','Labor Charge'))?></b><div class="labor-grid" style="margin-top:10px"><input name="labor_description" placeholder="<?=htmlspecialchars(zan_t('Maelezo ya kazi / survey / installation','Work / survey / installation description'))?>"><input name="labor_charge" type="number" step=".01" min="0" value="0"></div></div>
<div class="form" style="margin-top:15px">
<label class="full">Notes<textarea name="notes" rows="3" placeholder="<?=htmlspecialchars(zan_t('Maelezo ya ziada kwa mteja','Additional notes for customer'))?>"></textarea></label>
<label class="full">Terms & Conditions<textarea name="terms" rows="4" placeholder="Mfano: Bei ni halali kwa siku 30. Delivery/installation kama ilivyoelezwa hapo juu."></textarea></label>
</div>
<div class="totals"><div><span>Subtotal</span><b>TZS (calculated on save)</b></div><div><span>VAT</span><b>Calculated automatically</b></div><div class="grand"><span>Total</span><b>Calculated automatically</b></div></div>
<button class="btn" type="submit">Save & Print Quotation</button>
</form></div>
<template id="quotationProductTemplate"><div class="invoice-row product-row"><select name="product_id[]" onchange="bei(this)" required><?php foreach($products as $p): ?><option value="<?=$p['id']?>" data-price="<?=$p['price']?>"><?=htmlspecialchars($p['name'])?> (Stock: <?=$p['stock']?>)</option><?php endforeach; ?></select><input name="qty[]" type="number" min="1" value="1" required><input name="price[]" type="number" step=".01" min="0" value="<?=($products[0]['price']??0)?>" required><button type="button" class="btn black" onclick="removeQuotationRow(this)">Remove</button></div></template>
<script>
function addQuotationRow(){document.getElementById('quotationProductRows').appendChild(document.getElementById('quotationProductTemplate').content.cloneNode(true));}
function removeQuotationRow(btn){const rows=document.querySelectorAll('#quotationProductRows .product-row');if(rows.length>1)btn.parentElement.remove();else alert('Lazima kuwe na bidhaa angalau moja.');}
</script>
<?php elseif($page==='edit_quotation'):$id=(int)($_GET['id']??0);$s=$pdo->prepare('SELECT * FROM quotations WHERE id=?');$s->execute([$id]);$doc=$s->fetch();$it=$pdo->prepare('SELECT * FROM quotation_items WHERE quotation_id=?');$it->execute([$id]);$editItems=$it->fetchAll();$laborItem=null;foreach($editItems as $ei){if($ei['product_id']===null){$laborItem=$ei;break;}} ?>
<div class="head"><h1>Edit Quotation</h1><a class="btn black" href="?page=quotations">Back</a></div>
<div class="invoicebox">
<form method="post" enctype="multipart/form-data" action="?page=update_quotation&id=<?=$id?>">
<div class="form" style="margin-bottom:15px">
<label class="full">Customer<select name="customer_id" required><?php foreach($customers as $c):?><option value="<?=$c['id']?>" <?=$doc['customer_id']==$c['id']?'selected':''?>><?=htmlspecialchars($c['name'])?></option><?php endforeach;?></select></label>
<label>Quotation Date<input type="text" value="<?=htmlspecialchars(date('d/m/Y',strtotime($doc['created_at']??'now')))?>" disabled></label>
<label>Valid Until<input name="valid_until" type="date" value="<?=htmlspecialchars($doc['valid_until']??'')?>" required></label>
</div>
<h3 style="margin:15px 0 8px">Products / Services</h3>
<div class="invoice-row" style="font-weight:700;margin-bottom:5px"><span>Description</span><span>Qty</span><span>Unit Price</span><span>Action</span></div>
<div id="quotationProductRows">
<?php $hasProduct=false; foreach($editItems as $x): if($x['product_id']===null)continue; $hasProduct=true; ?>
<div class="invoice-row product-row"><select name="product_id[]" onchange="bei(this)" required><?php foreach($products as $p):?><option value="<?=$p['id']?>" data-price="<?=$p['price']?>" <?=$x['product_id']==$p['id']?'selected':''?>><?=htmlspecialchars($p['name'])?> (Stock: <?=$p['stock']?>)</option><?php endforeach;?></select><input name="qty[]" type="number" min="1" value="<?=$x['qty']?>" required><input name="price[]" type="number" step=".01" min="0" value="<?=$x['price']?>" required><button type="button" class="btn black" onclick="removeQuotationRow(this)">Remove</button></div>
<?php endforeach; if(!$hasProduct): ?><div class="invoice-row product-row"><select name="product_id[]" onchange="bei(this)" required><?php foreach($products as $p):?><option value="<?=$p['id']?>" data-price="<?=$p['price']?>"><?=htmlspecialchars($p['name'])?> (Stock: <?=$p['stock']?>)</option><?php endforeach;?></select><input name="qty[]" type="number" min="1" value="1" required><input name="price[]" type="number" step=".01" min="0" value="<?=($products[0]['price']??0)?>" required><button type="button" class="btn black" onclick="removeQuotationRow(this)">Remove</button></div><?php endif; ?>
</div>
<button type="button" class="btn" onclick="addQuotationRow()">+ Add Product</button>
<div class="labor-box"><b>Labor Charge</b><div class="labor-grid"><input name="labor_description" placeholder="Work description" value="<?=htmlspecialchars($laborItem['description']??'')?>"><input name="labor_charge" type="number" step=".01" min="0" value="<?=htmlspecialchars($laborItem['price']??0)?>"></div></div>
<div class="form" style="margin-top:15px"><label class="full">Notes<textarea name="notes" rows="3"><?=htmlspecialchars($doc['notes']??'')?></textarea></label><label class="full">Terms & Conditions<textarea name="terms" rows="4"><?=htmlspecialchars($doc['terms']??'')?></textarea></label></div>
<button class="btn" type="submit">Save Changes</button>
</form></div>
<template id="quotationProductTemplate"><div class="invoice-row product-row"><select name="product_id[]" onchange="bei(this)" required><?php foreach($products as $p):?><option value="<?=$p['id']?>" data-price="<?=$p['price']?>"><?=htmlspecialchars($p['name'])?> (Stock: <?=$p['stock']?>)</option><?php endforeach;?></select><input name="qty[]" type="number" min="1" value="1" required><input name="price[]" type="number" step=".01" min="0" value="<?=($products[0]['price']??0)?>" required><button type="button" class="btn black" onclick="removeQuotationRow(this)">Remove</button></div></template>
<script>
function addQuotationRow(){document.getElementById('quotationProductRows').appendChild(document.getElementById('quotationProductTemplate').content.cloneNode(true));}
function removeQuotationRow(btn){const rows=document.querySelectorAll('#quotationProductRows .product-row');if(rows.length>1)btn.parentElement.remove();else alert('Lazima kuwe na bidhaa angalau moja.');}
</script>
<?php elseif($page==='print_quotation'): $id=(int)($_GET['id']??0);$q=$pdo->prepare('SELECT q.*,c.name customer,c.phone,c.email,c.address customer_address,c.tin,c.vrn,u.name prepared_name FROM quotations q LEFT JOIN customers c ON c.id=q.customer_id LEFT JOIN users u ON u.id=q.prepared_by WHERE q.id=?');$q->execute([$id]);$doc=$q->fetch();$qi=$pdo->prepare('SELECT * FROM quotation_items WHERE quotation_id=?');$qi->execute([$id]);$docItems=$qi->fetchAll(); ?>
<div class="invoicebox">
<div class="doc-head">
<div>
<?php if(!empty($settings['logo']) && file_exists(__DIR__ . '/' . $settings['logo'])): ?><img src="<?=htmlspecialchars($settings['logo'])?>" alt="Logo" style="max-width:120px;max-height:70px;object-fit:contain"><br><?php endif; ?>
<b style="font-size:18px"><?=htmlspecialchars($settings['company_name']??'')?></b>
<p><?=htmlspecialchars($settings['address']??'')?><br><b>Phone:</b> <?=htmlspecialchars($settings['phone']??'-')?><br><b>Email:</b> <?=htmlspecialchars($settings['email']??'-')?><br><b>TIN:</b> <?=htmlspecialchars($settings['tin']??'-')?><br><b>VRN:</b> <?=htmlspecialchars(($settings['vrn']??'')!==''?$settings['vrn']:(($settings['vat']??'')!==''?$settings['vat']:'-'))?></p>
<hr><b>CUSTOMER</b><p><b>Name:</b> <?=htmlspecialchars($doc['customer']??'-')?><br><b>Phone:</b> <?=htmlspecialchars($doc['phone']??'-')?><br><b>Email:</b> <?=htmlspecialchars($doc['email']??'-')?><br><?php if(!empty($doc['customer_address'])): ?><b>Address:</b> <?=htmlspecialchars($doc['customer_address'])?><br><?php endif; ?><?php if(!empty($doc['tin'])): ?><b>TIN:</b> <?=htmlspecialchars($doc['tin'])?><br><?php endif; ?><?php if(!empty($doc['vrn'])): ?><b>VRN:</b> <?=htmlspecialchars($doc['vrn'])?><?php endif; ?></p>
</div>
<div style="text-align:right"><b style="font-size:22px">QUOTATION</b><br><b><?=htmlspecialchars($doc['quotation_no']??'')?></b><br><b>Date:</b> <?=htmlspecialchars(date('d/m/Y',strtotime($doc['created_at']??'now')))?><?php if(!empty($doc['valid_until'])): ?><br><b>Valid Until:</b> <?=htmlspecialchars(date('d/m/Y',strtotime($doc['valid_until'])))?><?php endif; ?><br><b>Prepared By:</b> <?=htmlspecialchars($doc['prepared_name']??'-')?></div>
</div>
<table class="table"><tr><th>#</th><th>Description</th><th>Qty</th><th>Unit Price</th><th>Amount</th></tr><?php $n=1;foreach($docItems as $x): ?><tr><td><?=$n++?></td><td><?=htmlspecialchars($x['description'])?></td><td><?=$x['qty']?></td><td>TZS <?=number_format($x['price'],2)?></td><td>TZS <?=number_format($x['subtotal'],2)?></td></tr><?php endforeach; ?></table>
<div class="totals"><div><span>Subtotal</span><b>TZS <?=number_format($doc['subtotal']??0,2)?></b></div><div><span>VAT</span><b>TZS <?=number_format($doc['vat']??0,2)?></b></div><div class="grand"><span>Total</span><b>TZS <?=number_format($doc['total']??0,2)?></b></div></div>
<?php if(!empty($doc['notes']) || !empty($doc['terms'])): ?><div class="doc-notes" style="margin-top:15px;border:1px solid #ddd;padding:12px;border-radius:8px"><?php if(!empty($doc['notes'])): ?><b>Notes</b><p><?=nl2br(htmlspecialchars($doc['notes']))?></p><?php endif; ?><?php if(!empty($doc['terms'])): ?><b>Terms & Conditions</b><p><?=nl2br(htmlspecialchars($doc['terms']))?></p><?php endif; ?></div><?php endif; ?>
<div class="doc-bank"><div class="bank-details"><b>PAYMENT DETAILS</b><br><?=htmlspecialchars($settings['bank_name']??'')?><br>Account Name: <?=htmlspecialchars($settings['bank_account_name']??'')?><br>Account Number: <?=htmlspecialchars($settings['bank_account_number']??'')?><br>Branch: <?=htmlspecialchars($settings['bank_branch']??'')?><br>Payment Number: <?=htmlspecialchars($settings['pay_number']??'')?></div><?php if(!empty($settings['pay_qr']) && file_exists(__DIR__.'/'.$settings['pay_qr'])): ?><div class="pay-code"><b>QR</b><br><img src="<?=htmlspecialchars($settings['pay_qr'])?>" alt="Payment QR"></div><?php endif; ?></div>
<div class="no-print"><button class="btn" onclick="window.print()">Print</button> <a class="btn black" href="?page=quotations">Back</a></div>
</div>
<?php elseif($page==='proforma'): ?>
<div class="head"><h1>Proforma Invoices</h1><a class="btn" href="?page=new_proforma">+ New Proforma Invoice</a></div>
<div class="panel"><table class="table"><tr><th>Proforma No.</th><th>Customer</th><th>Date</th><th>Total</th><th>Actions</th></tr><?php foreach($proformaRows as $q): ?><tr><td><?=htmlspecialchars($q['proforma_no'])?></td><td><?=htmlspecialchars($q['customer']??'-')?></td><td><?=htmlspecialchars($q['created_at'])?></td><td>TZS <?=number_format((float)$q['total'],2)?></td><td><a class="btn" href="?page=print_proforma&id=<?=$q['id']?>">Print</a> <a class="btn black" href="?page=edit_proforma&id=<?=$q['id']?>">Edit</a> <a class="btn black" href="?page=delete_proforma&id=<?=$q['id']?>" onclick="return confirm('Delete this proforma invoice?')">Delete</a></td></tr><?php endforeach; ?></table></div>
<?php elseif($page==='new_proforma'): ?>
<div class="head"><h1>New Proforma Invoice</h1><a class="btn black" href="?page=proforma">Back</a></div>
<div class="invoicebox">
<form method="post" enctype="multipart/form-data" action="?page=save_proforma" id="proformaForm">
<label>Customer<select name="customer_id" required><option value="">Select Customer</option><?php foreach($customers as $c): ?><option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option><?php endforeach; ?></select></label>
<div id="productRows">
<div class="invoice-row product-row"><select name="product_id[]" onchange="bei(this)"><?php foreach($products as $p): ?><option value="<?=$p['id']?>" data-price="<?=$p['price']?>"><?=htmlspecialchars($p['name'])?> (Stock: <?=$p['stock']?>)</option><?php endforeach; ?></select><input name="qty[]" type="number" min="1" value="1"><input name="price[]" type="number" step=".01" min="0" value="<?=($products[0]['price']??0)?>"><button type="button" class="btn black qty-minus" onclick="changeQty(this,-1)">−</button><button type="button" class="btn qty-plus" onclick="changeQty(this,1)">+</button><button type="button" class="btn black" onclick="removeProformaRow(this)">Remove</button></div>
</div>
<button type="button" class="btn" onclick="addProformaRow()">+ Add Product</button>
<div class="labor-box"><b><?=htmlspecialchars(zan_t('Kazi / Labor Charge','Labor Charge'))?></b><div class="labor-grid" style="margin-top:10px"><input name="labor_description" placeholder="<?=htmlspecialchars(zan_t('Maelezo ya kazi / survey / installation','Work / survey / installation description'))?>"><input name="labor_charge" type="number" step=".01" min="0" value="0"></div></div>
<div class="form" style="margin-top:15px"><label>Valid Until<input name="valid_until" type="date" value="<?=date('Y-m-d',strtotime('+30 days'))?>" required></label><label class="full">Notes<textarea name="notes" rows="3" placeholder="<?=htmlspecialchars(zan_t('Maelezo ya ziada kwa mteja','Additional notes for customer'))?>"></textarea></label><label class="full">Terms & Conditions<textarea name="terms" rows="3" placeholder="Mfano: Bei ni halali kwa siku 30. Delivery/installation kama ilivyoelezwa hapo juu."></textarea></label></div>
<div class="totals"><div><span>Subtotal</span><b>TZS (calculated on save)</b></div></div>
<button class="btn" type="submit">Save & Print Proforma Invoice</button>
</form></div>
<template id="proformaProductTemplate"><div class="invoice-row product-row"><select name="product_id[]" onchange="bei(this)"><?php foreach($products as $p): ?><option value="<?=$p['id']?>" data-price="<?=$p['price']?>"><?=htmlspecialchars($p['name'])?> (Stock: <?=$p['stock']?>)</option><?php endforeach; ?></select><input name="qty[]" type="number" min="1" value="1"><input name="price[]" type="number" step=".01" min="0" value="<?=($products[0]['price']??0)?>"><button type="button" class="btn black" onclick="changeQty(this,-1)">−</button><button type="button" class="btn qty-plus" onclick="changeQty(this,1)">+</button><button type="button" class="btn black" onclick="removeProformaRow(this)">Remove</button></div></template>
<script>
function changeQty(btn,delta){const input=btn.parentElement.querySelector('input[name="qty[]"]');input.value=Math.max(1,(parseInt(input.value||1,10)+delta));}
function removeProformaRow(btn){const rows=document.querySelectorAll('#productRows .product-row');if(rows.length>1)btn.parentElement.remove();else alert('Lazima kuwe na bidhaa angalau moja.');}
function addProformaRow(){document.getElementById('productRows').appendChild(document.getElementById('proformaProductTemplate').content.cloneNode(true));}
</script>
<?php elseif($page==='edit_proforma'):$id=(int)($_GET['id']??0);$s=$pdo->prepare('SELECT * FROM proforma_invoices WHERE id=?');$s->execute([$id]);$doc=$s->fetch();$it=$pdo->prepare('SELECT * FROM proforma_items WHERE proforma_id=?');$it->execute([$id]);$editItems=$it->fetchAll(); ?>
<div class="head"><h1>Edit Proforma Invoice</h1><a class="btn black" href="?page=proforma">Back</a></div>
<div class="invoicebox"><form method="post" enctype="multipart/form-data" action="?page=update_proforma&id=<?=$id?>">
<label>Customer<select name="customer_id" required><?php foreach($customers as $c):?><option value="<?=$c['id']?>" <?=$doc['customer_id']==$c['id']?'selected':''?>><?=htmlspecialchars($c['name'])?></option><?php endforeach;?></select></label>
<div id="productRows">
<?php foreach($editItems as $x):if($x['product_id']===null)continue;?>
<div class="invoice-row product-row"><select name="product_id[]" onchange="bei(this)"><?php foreach($products as $p):?><option value="<?=$p['id']?>" data-price="<?=$p['price']?>" <?=$x['product_id']==$p['id']?'selected':''?>><?=htmlspecialchars($p['name'])?> (Stock: <?=$p['stock']?>)</option><?php endforeach;?></select><input name="qty[]" type="number" min="1" value="<?=$x['qty']?>"><input name="price[]" type="number" step=".01" min="0" value="<?=$x['price']?>"><button type="button" class="btn black" onclick="changeQty(this,-1)">−</button><button type="button" class="btn qty-plus" onclick="changeQty(this,1)">+</button><button type="button" class="btn black" onclick="removeProformaRow(this)">Remove</button></div>
<?php endforeach;?></div>
<button type="button" class="btn" onclick="addProformaRow()">+ Add Product</button>
<div class="labor-box"><b>Labor Charge</b><div class="labor-grid"><input name="labor_description" placeholder="Work description"><input name="labor_charge" type="number" step=".01" min="0" value="0"></div></div>
<div class="form" style="margin-top:15px"><label>Valid Until<input name="valid_until" type="date" value="<?=htmlspecialchars($doc['valid_until']??'')?>" required></label><label class="full">Notes<textarea name="notes" rows="3"><?=htmlspecialchars($doc['notes']??'')?></textarea></label><label class="full">Terms & Conditions<textarea name="terms" rows="3"><?=htmlspecialchars($doc['terms']??'')?></textarea></label></div>
<button class="btn" type="submit">Save Changes</button></form></div>
<template id="proformaProductTemplate"><div class="invoice-row product-row"><select name="product_id[]" onchange="bei(this)"><?php foreach($products as $p):?><option value="<?=$p['id']?>" data-price="<?=$p['price']?>"><?=htmlspecialchars($p['name'])?> (Stock: <?=$p['stock']?>)</option><?php endforeach;?></select><input name="qty[]" type="number" min="1" value="1"><input name="price[]" type="number" step=".01" min="0" value="<?=($products[0]['price']??0)?>"><button type="button" class="btn black" onclick="changeQty(this,-1)">−</button><button type="button" class="btn qty-plus" onclick="changeQty(this,1)">+</button><button type="button" class="btn black" onclick="removeProformaRow(this)">Remove</button></div></template>
<script>
function changeQty(btn,delta){const input=btn.parentElement.querySelector('input[name="qty[]"]');input.value=Math.max(1,(parseInt(input.value||1,10)+delta));}
function removeProformaRow(btn){const rows=document.querySelectorAll('#productRows .product-row');if(rows.length>1)btn.parentElement.remove();else alert('Lazima kuwe na bidhaa angalau moja.');}
function addProformaRow(){document.getElementById('productRows').appendChild(document.getElementById('proformaProductTemplate').content.cloneNode(true));}
</script>
<?php elseif($page==='print_proforma'): $id=(int)($_GET['id']??0);$q=$pdo->prepare('SELECT p.*,c.name customer,c.phone,c.email,c.address customer_address,c.tin,c.vrn FROM proforma_invoices p LEFT JOIN customers c ON c.id=p.customer_id WHERE p.id=?');$q->execute([$id]);$doc=$q->fetch();$qi=$pdo->prepare('SELECT * FROM proforma_items WHERE proforma_id=?');$qi->execute([$id]);$docItems=$qi->fetchAll(); ?>
<div class="invoicebox"><div class="doc-head"><div><?php if(!empty($settings['logo']) && file_exists(__DIR__ . '/' . $settings['logo'])): ?><img src="<?=htmlspecialchars($settings['logo'])?>" alt="Logo" style="max-width:120px;max-height:70px;object-fit:contain"><br><?php endif; ?><b style="font-size:18px"><?=htmlspecialchars($settings['company_name']??'')?></b><p><?=htmlspecialchars($settings['address']??'')?><br><b>Phone:</b> <?=htmlspecialchars($settings['phone']??'-')?> · <b>Email:</b> <?=htmlspecialchars($settings['email']??'-')?><br>TIN: <?=htmlspecialchars($settings['tin']??'-')?> · VRN: <?=htmlspecialchars(($settings['vrn']??'')!==''?$settings['vrn']:(($settings['vat']??'')!==''?$settings['vat']:'-'))?></p><hr><b>BILL TO / CUSTOMER</b><p><b>Name:</b> <?=htmlspecialchars($doc['customer']??'-')?><br><b>Phone:</b> <?=htmlspecialchars($doc['phone']??'-')?><br><b>Email:</b> <?=htmlspecialchars($doc['email']??'-')?><br><?php if(!empty($doc['customer_address'])): ?><b>Address:</b> <?=htmlspecialchars($doc['customer_address'])?><br><?php endif; ?><?php if(!empty($doc['tin'])): ?>TIN: <?=htmlspecialchars($doc['tin'])?><br><?php endif; ?><?php if(!empty($doc['vrn'])): ?>VRN: <?=htmlspecialchars($doc['vrn'])?><?php endif; ?></p></div><div style="text-align:right"><b style="font-size:22px">PROFORMA INVOICE</b><br><b><?=htmlspecialchars($doc['proforma_no']??'')?></b><br>Date: <?=htmlspecialchars(date('d/m/Y',strtotime($doc['created_at']??'now')))?><?php if(!empty($doc['valid_until'])): ?><br><b>Valid Until:</b> <?=htmlspecialchars(date('d/m/Y',strtotime($doc['valid_until'])))?><?php endif; ?><br><span class="badge badge-unpaid">DRAFT / NOT A TAX INVOICE</span></div></div><table class="table"><tr><th>#</th><th>Description</th><th>Qty</th><th>Unit Price</th><th>Amount</th></tr><?php $n=1;foreach($docItems as $x): ?><tr><td><?=$n++?></td><td><?=htmlspecialchars($x['description'])?></td><td><?=$x['qty']?></td><td>TZS <?=number_format($x['price'],2)?></td><td>TZS <?=number_format($x['subtotal'],2)?></td></tr><?php endforeach; ?></table><div class="totals"><div><span>Subtotal</span><b>TZS <?=number_format($doc['subtotal']??0,2)?></b></div><div><span>VAT</span><b>TZS <?=number_format($doc['vat']??0,2)?></b></div><div class="grand"><span>Total</span><b>TZS <?=number_format($doc['total']??0,2)?></b></div></div><?php if(!empty($doc['notes']) || !empty($doc['terms'])): ?><div class="doc-notes" style="margin-top:15px;border:1px solid #ddd;padding:12px;border-radius:8px"><?php if(!empty($doc['notes'])): ?><b>Notes</b><p><?=nl2br(htmlspecialchars($doc['notes']))?></p><?php endif; ?><?php if(!empty($doc['terms'])): ?><b>Terms & Conditions</b><p><?=nl2br(htmlspecialchars($doc['terms']))?></p><?php endif; ?></div><?php endif; ?><div class="doc-bank"><div class="bank-details"><b>PAYMENT DETAILS</b><br><?=htmlspecialchars($settings['bank_name']??'')?><br>Account Name: <?=htmlspecialchars($settings['bank_account_name']??'')?><br>Account Number: <?=htmlspecialchars($settings['bank_account_number']??'')?><br>Branch: <?=htmlspecialchars($settings['bank_branch']??'')?><br>Payment Number: <?=htmlspecialchars($settings['pay_number']??'')?></div><?php if(!empty($settings['pay_qr']) && file_exists(__DIR__.'/'.$settings['pay_qr'])): ?><div class="pay-code"><b>QR</b><br><img src="<?=htmlspecialchars($settings['pay_qr'])?>" alt="Payment QR"></div><?php endif; ?></div><div class="no-print" style="margin-top:15px"><button class="btn" onclick="window.print()">Print Proforma</button> <a class="btn black" href="?page=proforma">Back</a></div></div>
<?php elseif($page==='expenses'): ?>
<div class="head"><h1>Expenses</h1><button class="btn" onclick="window.print()">Print Report</button></div><div class="cards"><div class="card"><div class="label">TOTAL EXPENSES</div><div class="big">TZS <?=number_format($expenseTotal,2)?></div></div></div>
<div class="panel"><h2>Add Expense</h2><form class="form" method="post" action="?page=save_expense"><label>Date<input type="date" name="expense_date" value="<?=date('Y-m-d')?>" required></label><label>Category<select name="category"><option>Office Expenses</option><option>Salaries</option><option>Electricity</option><option>Internet</option><option>Transport</option><option>Rent</option><option>Maintenance</option><option>Other</option></select></label><label>Description<input name="description"></label><label>Amount<input name="amount" type="number" step=".01" min="0.01" required></label><label>Paid By<input name="paid_by"></label><label class="full">Notes<textarea name="notes"></textarea></label><div class="full"><button class="btn">Save Expense</button></div></form></div>
<div class="panel"><h2>Expense Report</h2><div style="overflow:auto"><table class="table"><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Paid By</th><th>Created By</th><th class="no-print">Action</th></tr><?php foreach($expenses as $e): ?><tr><td><?=htmlspecialchars($e['expense_date'])?></td><td><?=htmlspecialchars($e['category'])?></td><td><?=htmlspecialchars($e['description'])?></td><td>TZS <?=number_format((float)$e['amount'],2)?></td><td><?=htmlspecialchars($e['paid_by'])?></td><td><?=htmlspecialchars($e['created_by_name']??'-')?></td><td class="no-print"><a class="btn black" href="?page=delete_expense&id=<?=$e['id']?>" onclick="return confirm('Delete this expense?')">Delete</a></td></tr><?php endforeach; ?></table></div></div>
<?php elseif($page==='search'): $term=trim($_GET['q']??'');$like='%'.$term.'%';$sr=[];if($term!==''){ $q=$pdo->prepare("SELECT 'Customer' type,id,name title,phone details FROM customers WHERE name LIKE ? OR phone LIKE ? OR tin LIKE ? OR vrn LIKE ? OR email LIKE ?");$q->execute([$like,$like,$like,$like,$like]);foreach($q->fetchAll() as $r)$sr[]=$r;$q=$pdo->prepare("SELECT 'Product' type,id,name title,barcode details FROM products WHERE name LIKE ? OR barcode LIKE ? OR sku LIKE ? OR category LIKE ?");$q->execute([$like,$like,$like,$like]);foreach($q->fetchAll() as $r)$sr[]=$r;$q=$pdo->prepare("SELECT 'Supplier' type,id,company_name title,phone details FROM suppliers WHERE company_name LIKE ? OR phone LIKE ? OR tin LIKE ? OR vrn LIKE ?");$q->execute([$like,$like,$like,$like]);foreach($q->fetchAll() as $r)$sr[]=$r;$q=$pdo->prepare("SELECT 'Invoice' type,i.id,i.invoice_no title,c.name details FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id WHERE i.invoice_no LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR c.tin LIKE ?");$q->execute([$like,$like,$like,$like]);foreach($q->fetchAll() as $r)$sr[]=$r;$q=$pdo->prepare("SELECT 'Expense' type,id,category title,description details FROM expenses WHERE category LIKE ? OR description LIKE ? OR paid_by LIKE ?");$q->execute([$like,$like,$like]);foreach($q->fetchAll() as $r)$sr[]=$r;$q=$pdo->prepare("SELECT 'Quotation' type,id,quotation_no title,status details FROM quotations WHERE quotation_no LIKE ? OR status LIKE ?");$q->execute([$like,$like]);foreach($q->fetchAll() as $r)$sr[]=$r;$q=$pdo->prepare("SELECT 'Proforma' type,id,proforma_no title,status details FROM proforma_invoices WHERE proforma_no LIKE ? OR status LIKE ?");$q->execute([$like,$like]);foreach($q->fetchAll() as $r)$sr[]=$r; } ?>
<div class="head"><h1>Search Results</h1></div><div class="panel"><p>Results for: <b><?=htmlspecialchars($term)?></b></p><table class="table"><tr><th>Type</th><th>Title</th><th>Details</th><th>Open</th></tr><?php foreach($sr as $r): ?><tr><td><?=htmlspecialchars($r['type'])?></td><td><?=htmlspecialchars($r['title'])?></td><td><?=htmlspecialchars($r['details']??'-')?></td><td><?php if($r['type']==='Invoice'): ?><a class="btn" href="?page=print&id=<?=$r['id']?>">Open</a><?php elseif($r['type']==='Customer'): ?><a class="btn" href="?page=edit_customer&id=<?=$r['id']?>">Open</a><?php elseif($r['type']==='Product'): ?><a class="btn" href="?page=edit_product&id=<?=$r['id']?>">Open</a><?php elseif($r['type']==='Quotation'): ?><a class="btn" href="?page=print_quotation&id=<?=$r['id']?>">Open</a><?php elseif($r['type']==='Proforma'): ?><a class="btn" href="?page=print_proforma&id=<?=$r['id']?>">Open</a><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; ?><?php if(!$sr): ?><tr><td colspan="4">No results found.</td></tr><?php endif; ?></table></div>

<!-- ======================================================
     REPORTS
     ====================================================== -->
<?php elseif($page==='reports'): ?>

<div class="head"><h1>Ripoti</h1><button class="btn" onclick="window.print()">Print Reports</button></div>

<div class="cards">
<div class="card"><div class="label">MAUZO YA MWEZI</div><div class="big">TZS <?=number_format($month,2)?></div></div>
<div class="card"><div class="label">MADENI</div><div class="big">TZS <?=number_format($due,2)?></div></div>
<div class="card"><div class="label">TOTAL EXPENSES</div><div class="big">TZS <?=number_format($expenseTotal,2)?></div></div>
</div>

<div class="panel"><h2>Customers Who Owe</h2><div style="overflow:auto"><table class="table"><tr><th>Customer</th><th>Phone</th><th>TIN</th><th>VRN</th><th>Balance</th></tr><?php foreach($owingCustomers as $oc): ?><tr><td><?=htmlspecialchars($oc['name'])?></td><td><?=htmlspecialchars($oc['phone']?:'-')?></td><td><?=htmlspecialchars($oc['tin']?:'-')?></td><td><?=htmlspecialchars($oc['vrn']?:'-')?></td><td><b>TZS <?=number_format((float)$oc['balance'],2)?></b></td></tr><?php endforeach; ?><?php if(!$owingCustomers): ?><tr><td colspan="5">No outstanding balances.</td></tr><?php endif; ?></table></div></div>

<div class="panel"><h2>Expenses</h2><div style="overflow:auto"><table class="table"><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Paid By</th></tr><?php foreach($expenses as $e): ?><tr><td><?=htmlspecialchars($e['expense_date'])?></td><td><?=htmlspecialchars($e['category'])?></td><td><?=htmlspecialchars($e['description'])?></td><td>TZS <?=number_format((float)$e['amount'],2)?></td><td><?=htmlspecialchars($e['paid_by']?:'-')?></td></tr><?php endforeach; ?><?php if(!$expenses): ?><tr><td colspan="5">No expenses recorded.</td></tr><?php endif; ?></table></div><p><a class="btn" href="?page=expenses">Manage Expenses</a></p></div>

<!-- ======================================================
     PRINT INVOICE
     ====================================================== -->

<?php elseif (
    $page === 'print'
):

$id =
    (int)$_GET['id'];

$s =
    $pdo->prepare(
        'SELECT
            i.*,
            c.name customer,
            c.phone,
            c.email,
            c.address,
            c.tin,
            c.vrn,
            c.vat_status,
            u.name prepared_by_name
         FROM invoices i
         LEFT JOIN customers c
            ON c.id=i.customer_id
         LEFT JOIN users u
            ON u.id=i.prepared_by
         WHERE i.id=?'
    );

$s->execute([
    $id
]);

$i =
    $s->fetch();

$s =
    $pdo->prepare(
        'SELECT *
         FROM invoice_items
         WHERE invoice_id=?'
    );

$s->execute([
    $id
]);

$items =
    $s->fetchAll();

?>

<div
    class="invoicebox"
    id="printInvoice"
>

<div style="
    display:flex;
    justify-content:space-between;
    gap:30px;
">

<div>

<?php if (
    !empty($settings['logo']) &&
    file_exists(
        __DIR__ .
        '/' .
        $settings['logo']
    )
): ?>

<img
    src="<?=htmlspecialchars(
        $settings['logo']
    )?>"
    style="
        max-width:130px;
        max-height:80px;
    "
>

<?php endif; ?>

<b style="font-size:20px">INVOICE</b>


<p>

<?=htmlspecialchars(
    $settings['address']
)?>

<br>

<?=htmlspecialchars(
    $settings['phone']
)?>

<br>

<?=htmlspecialchars(
    $settings['email']
)?>

<br>TIN: <?=htmlspecialchars($settings['tin'] ?? '-')?>
<br>VRN: <?=htmlspecialchars(($settings['vrn'] ?? '') !== '' ? $settings['vrn'] : ($settings['vat'] ?? '-'))?>

</p>



</div>

<div style="
    text-align:right;
">

<h2>
INVOICE
</h2>

<b>
<?=$i['invoice_no']?>
</b>

<p>
Date:
<?=date(
    'd/m/Y',
    strtotime(
        $i['created_at']
    )
)?>
</p>

</div>

</div>

<hr>

<div style="border:1px solid #ddd;padding:12px;border-radius:10px;margin:12px 0"><b>Customer</b><br>Name: <?=htmlspecialchars($i['customer']??'Walk-in Customer')?><br>Phone: <?=htmlspecialchars($i['phone']??'-')?><br>

<?php if (
    !empty($i['tin'])
): ?>

TIN:
<?=htmlspecialchars(
    $i['tin']
)?>

<br>

<?php endif; ?>

<?php if (
    !empty($i['vrn'])
): ?>

VRN:
<?=htmlspecialchars(
    $i['vrn']
)?>

<br>

<?php endif; ?>

</div>

<table class="table">

<tr>

<th>
Description
</th>

<th>
Quantity
</th>

<th>
Unit Price
</th>

<th>
Amount
</th>

</tr>

<?php foreach (
    $items as $x
): ?>

<tr>

<td>
<?=htmlspecialchars(
    $x['description']
)?>
</td>

<td>
<?=$x['qty']?>
</td>

<td>
TZS <?=number_format(
    $x['price'],
    2
)?>
</td>

<td>
TZS <?=number_format(
    $x['subtotal'],
    2
)?>
</td>

</tr>

<?php endforeach; ?>

</table>

<div class="totals">

<div>

<span>
Subtotal
</span>

<b>
TZS <?=number_format(
    (float)$i['subtotal'],
    2
)?>
</b>

</div>

<div>

<span>
Discount
</span>

<b>
TZS <?=number_format(
    (float)($i['discount_amount'] ?? 0),
    2
)?>
</b>

</div>

<div>

<span>
VAT 18%
</span>

<b>
TZS <?=number_format(
    (float)$i['vat'],
    2
)?>
</b>

</div>

<div class="grand">

<span>
Total
</span>

<b>
TZS <?=number_format(
    (float)$i['total'],
    2
)?>
</b>

</div>

<div>

<span>
Paid
</span>

<b>
TZS <?=number_format(
    (float)$i['paid'],
    2
)?>
</b>

</div>

<div>

<span>
Balance
</span>

<b>
TZS <?=number_format(
    (float)$i['total']
    -
    (float)$i['paid'],
    2
)?>
</b>

</div>

</div>

<div class="doc-bank">
    <div class="bank-details">
        <b><?=htmlspecialchars(zan_t('TAARIFA ZA MALIPO','PAYMENT DETAILS'))?></b><br>
        <?=htmlspecialchars($settings['bank_name'] ?? '')?><br>
        <?=htmlspecialchars(zan_t('Jina la Akaunti','Account Name'))?>: <?=htmlspecialchars($settings['bank_account_name'] ?? '')?><br>
        <?=htmlspecialchars(zan_t('Namba ya Akaunti','Account Number'))?>: <?=htmlspecialchars($settings['bank_account_number'] ?? '')?><br>
        <?=htmlspecialchars(zan_t('Tawi','Branch'))?>: <?=htmlspecialchars($settings['bank_branch'] ?? '')?><br>
        <?=htmlspecialchars(zan_t('Lipa Namba','Payment Number'))?>: <?=htmlspecialchars($settings['pay_number'] ?? '')?>
    </div>
    <?php if (!empty($settings['pay_qr']) && file_exists(__DIR__ . '/' . $settings['pay_qr'])): ?>
    <div class="pay-code"><b>QR</b><br><img src="<?=htmlspecialchars($settings['pay_qr'])?>" alt="Payment QR"></div>
    <?php endif; ?>
</div>

<p>

Prepared By:

<?=htmlspecialchars(
    $i['prepared_by_name']
    ?? 'System'
)?>

</p>

<p>

<?=htmlspecialchars(
    $settings['footer']
    ?? ''
)?>

</p>

<div class="no-print">

<button
    class="btn"
    onclick="window.print()"
>
Chapisha
</button>

<a
    class="btn black"
    href="?page=edit_invoice&id=<?=$id?>"
>
Hariri Ankara
</a>

<a
    class="btn black"
    href="?page=invoices"
>
Rudi
</a>

</div>

</div>

<?php endif; ?>

<div class="footer no-print">

Zantronix Invoice System

</div>

</div>

</main>

<!-- ======================================================
     JAVASCRIPT
     ====================================================== -->

<script>

/* =========================================================
   BACKUP CONFIRMATION
   ========================================================= */

function confirmBackup() {

    return confirm(
        "TENGENEZA BACKUP\n\n" +
        "Mfumo utahifadhi database, " +
        "uploads na mafaili muhimu.\n\n" +
        "Una uhakika unataka kuendelea?"
    );

}

/* =========================================================
   RESTORE CONFIRMATION
   ========================================================= */

function confirmRestore() {

    return confirm(
        "TAHADHARI!\n\n" +
        "Restore itabadilisha taarifa za sasa " +
        "kwa taarifa zilizopo kwenye backup.\n\n" +
        "Backup ya sasa itahifadhiwa kwanza.\n\n" +
        "Una uhakika kuendelea?"
    );

}

/* =========================================================
   PRODUCT PRICE
   ========================================================= */

function bei(s) {

    let o =
        s.options[
            s.selectedIndex
        ];

    let price =
        s.parentNode.querySelector(
            '[name="price[]"]'
        );

    if (price) {

        price.value =
            o.dataset.price;
    }

    hesabu();

    hesabuEdit();
}

/* =========================================================
   ADD INVOICE ROW
   ========================================================= */

function mstari() {

    let x =
        document.querySelector(
            '#mistari .invoice-row'
        );

    if (!x) {
        return;
    }

    let n =
        x.cloneNode(true);

    n.querySelector(
        '[name="qty[]"]'
    ).value = 1;

    n.querySelector(
        '[name="price[]"]'
    ).value =
        n.querySelector(
            'select'
        ).options[0].dataset.price;

    document
        .getElementById(
            'mistari'
        )
        .appendChild(n);

    hesabu();
}

/* =========================================================
   ADD EDIT INVOICE ROW
   ========================================================= */

function mstariEdit() {

    let x =
        document.querySelector(
            '#editMistari .invoice-row'
        );

    if (!x) {
        return;
    }

    let n =
        x.cloneNode(true);

    n.querySelector(
        '[name="qty[]"]'
    ).value = 1;

    n.querySelector(
        '[name="price[]"]'
    ).value =
        n.querySelector(
            'select'
        ).options[0].dataset.price;

    document
        .getElementById(
            'editMistari'
        )
        .appendChild(n);

    hesabuEdit();
}

/* =========================================================
   VAT CUSTOMER
   ========================================================= */

function isVatCustomer(
    selectId
) {

    let s =
        document.getElementById(
            selectId
        );

    if (
        !s ||
        !s.selectedOptions.length
    ) {

        return false;
    }

    return (
        s.selectedOptions[0]
        .dataset.vat === '1'
    );
}

/* =========================================================
   VAT NOTICE
   ========================================================= */

function updateVatNotice() {

    let vat =
        isVatCustomer(
            'customer_id'
        );

    let n =
        document.getElementById(
            'vatNotice'
        );

    if (n) {

        n.innerText =
            vat
            ? 'Mteja wa VAT: VAT 18% itaongezwa.'
            : 'Asiye wa VAT: VAT 18% haitatozwa.';
    }

    hesabu();
}

/* =========================================================
   EDIT VAT NOTICE
   ========================================================= */

function updateEditVat() {

    let vat =
        isVatCustomer(
            'edit_customer_id'
        );

    let n =
        document.getElementById(
            'editVatNotice'
        );

    if (n) {

        n.innerText =
            vat
            ? 'Mteja wa VAT: VAT 18% itaongezwa.'
            : 'Asiye wa VAT: VAT 18% haitatozwa.';
    }

    hesabuEdit();
}

/* =========================================================
   CALCULATE ROWS
   ========================================================= */

function calculateRows(
    selector
) {

    let t = 0;

    document
        .querySelectorAll(
            selector +
            ' .invoice-row'
        )
        .forEach(
            r => {

                let q =
                    +(
                        r.querySelector(
                            '[name="qty[]"]'
                        )?.value || 0
                    );

                let p =
                    +(
                        r.querySelector(
                            '[name="price[]"]'
                        )?.value || 0
                    );

                let v =
                    q * p;

                let sub =
                    r.querySelector(
                        '.sub'
                    );

                if (sub) {

                    sub.value =
                        v.toFixed(2);
                }

                t += v;
            }
        );

    return t;
}

/* =========================================================
   CALCULATE NEW INVOICE
   ========================================================= */

function hesabu() {

    let box =
        document.getElementById(
            'mistari'
        );

    if (!box) {
        return;
    }

    let t =
        calculateRows(
            '#mistari'
        );

    let labor = parseFloat(document.getElementById('laborCharge')?.value || 0);

    t += Math.max(0, labor);

    let discountType = document.getElementById('discountType')?.value || 'amount';
    let discountValue = Math.max(0, parseFloat(document.getElementById('discountValue')?.value || 0));
    let discount = discountType === 'percent'
        ? Math.min(t, t * Math.min(100, discountValue) / 100)
        : Math.min(t, discountValue);

    let taxable = Math.max(0, t - discount);

    let vat =
        isVatCustomer(
            'customer_id'
        )
        ? taxable * 0.18
        : 0;

    let total =
        taxable + vat;

    let paid =
        +(
            document
            .querySelector(
                '#mistari'
            )
            ?.parentNode
            .querySelector(
                '[name="paid"]'
            )
            ?.value || 0
        );

    if (
        document.getElementById(
            'subtotalText'
        )
    ) {

        document.getElementById(
            'subtotalText'
        ).innerText =
            'TZS ' +
            t.toLocaleString(
                undefined,
                {
                    minimumFractionDigits:2
                }
            );
    }

    if (
        document.getElementById(
            'discountText'
        )
    ) {
        document.getElementById(
            'discountText'
        ).innerText =
            'TZS ' +
            discount.toLocaleString(
                undefined,
                {
                    minimumFractionDigits:2
                }
            );
    }

    if (
        document.getElementById(
            'vatText'
        )
    ) {

        document.getElementById(
            'vatText'
        ).innerText =
            'TZS ' +
            vat.toLocaleString(
                undefined,
                {
                    minimumFractionDigits:2
                }
            );
    }

    if (
        document.getElementById(
            'jumla'
        )
    ) {

        document.getElementById(
            'jumla'
        ).innerText =
            'TZS ' +
            total.toLocaleString(
                undefined,
                {
                    minimumFractionDigits:2
                }
            );
    }

    if (
        document.getElementById(
            'salio'
        )
    ) {

        document.getElementById(
            'salio'
        ).innerText =
            'TZS ' +
            (total - paid)
            .toLocaleString(
                undefined,
                {
                    minimumFractionDigits:2
                }
            );
    }
}

/* =========================================================
   CALCULATE EDIT INVOICE
   ========================================================= */

function hesabuEdit() {

    let box =
        document.getElementById(
            'editMistari'
        );

    if (!box) {
        return;
    }

    let t =
        calculateRows(
            '#editMistari'
        );

    let labor = parseFloat(document.getElementById('editLaborCharge')?.value || 0);

    t += Math.max(0, labor);

    let discountType = document.getElementById('editDiscountType')?.value || 'amount';
    let discountValue = Math.max(0, parseFloat(document.getElementById('editDiscountValue')?.value || 0));
    let discount = discountType === 'percent'
        ? Math.min(t, t * Math.min(100, discountValue) / 100)
        : Math.min(t, discountValue);

    let taxable = Math.max(0, t - discount);

    let vat =
        isVatCustomer(
            'edit_customer_id'
        )
        ? taxable * 0.18
        : 0;

    let total =
        taxable + vat;

    let paid =
        +(
            document
            .querySelector(
                '#editMistari'
            )
            ?.parentNode
            .querySelector(
                '[name="paid"]'
            )
            ?.value || 0
        );

    if (
        document.getElementById(
            'editSubtotal'
        )
    ) {

        document.getElementById(
            'editSubtotal'
        ).innerText =
            'TZS ' +
            t.toLocaleString(
                undefined,
                {
                    minimumFractionDigits:2
                }
            );
    }

    if (
        document.getElementById(
            'editDiscount'
        )
    ) {
        document.getElementById(
            'editDiscount'
        ).innerText =
            'TZS ' +
            discount.toLocaleString(
                undefined,
                {
                    minimumFractionDigits:2
                }
            );
    }

    if (
        document.getElementById(
            'editVat'
        )
    ) {

        document.getElementById(
            'editVat'
        ).innerText =
            'TZS ' +
            vat.toLocaleString(
                undefined,
                {
                    minimumFractionDigits:2
                }
            );
    }

    if (
        document.getElementById(
            'editTotal'
        )
    ) {

        document.getElementById(
            'editTotal'
        ).innerText =
            'TZS ' +
            total.toLocaleString(
                undefined,
                {
                    minimumFractionDigits:2
                }
            );
    }

    if (
        document.getElementById(
            'editBalance'
        )
    ) {

        document.getElementById(
            'editBalance'
        ).innerText =
            'TZS ' +
            (total - paid)
            .toLocaleString(
                undefined,
                {
                    minimumFractionDigits:2
                }
            );
    }
}

/* =========================================================
   AUTOMATIC CALCULATION
   ========================================================= */

document.addEventListener(
    'input',
    function() {

        hesabu();

        hesabuEdit();

    }
);

document.addEventListener(
    'change',
    function() {

        hesabu();

        hesabuEdit();

    }
);

/* =========================================================
   INITIAL CALCULATION
   ========================================================= */

hesabu();

hesabuEdit();

</script>

<script>
(function(){
 const input=document.getElementById('invoiceBarcode'); const msg=document.getElementById('barcodeMessage'); if(!input)return;
 input.addEventListener('keydown',function(e){if(e.key!=='Enter')return;e.preventDefault();const barcode=input.value.trim();if(!barcode)return;msg.textContent='Inatafuta bidhaa...';
 fetch('?page=barcode_product&barcode='+encodeURIComponent(barcode)).then(r=>r.json()).then(data=>{
  if(!data.ok){msg.textContent=data.message||'Bidhaa haijapatikana.';input.select();return;}
  const p=data.product; const rows=document.querySelectorAll('#mistari .invoice-row');
  for(const row of rows){const sel=row.querySelector('select[name="product_id[]"]');if(sel&&String(sel.value)===String(p.id)){const q=row.querySelector('input[name="qty[]"]');q.value=parseInt(q.value||'0',10)+1;if(typeof hesabu==='function')hesabu();msg.textContent=p.name+' — quantity imeongezwa.';input.value='';input.focus();return;}}
  if(typeof mstari==='function'){mstari();const rows2=document.querySelectorAll('#mistari .invoice-row');const row=rows2[rows2.length-1];const sel=row.querySelector('select[name="product_id[]"]');sel.value=p.id;if(typeof bei==='function')bei(sel);msg.textContent=p.name+' imeongezwa.';input.value='';input.focus();}
 }).catch(()=>msg.textContent='Hitilafu ya kuwasiliana na mfumo.');
 });
})();
</script>
</body>

</html>
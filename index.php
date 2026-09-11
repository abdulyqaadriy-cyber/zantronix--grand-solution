<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require 'config.php';
require_once __DIR__.'/debt_reminders.php';
zan_debt_reminder_schema($pdo);

/* =========================================================
   FOUNDER / SUPER ADMIN BOOTSTRAP ACCOUNT
   Creates the requested first Founder account if it does not
   already exist. Change the password after first login.
   ========================================================= */
try {
    $bootstrapUsername = 'founder';
    $bootstrapPassword = 'Zantronix@2026!';
    $q = $pdo->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
    $q->execute([$bootstrapUsername]);
    if (!$q->fetchColumn()) {
        $bootstrapPerms = json_encode([
            'site_tracking_system' => true,
            'business_system' => true
        ]);
        $ins = $pdo->prepare('INSERT INTO users(name,username,phone,password,role,permissions,account_system,active) VALUES(?,?,?,?,?,?,?,1)');
        $ins->execute([
            'Founder',
            $bootstrapUsername,
            '',
            password_hash($bootstrapPassword, PASSWORD_DEFAULT),
            'super admin',
            $bootstrapPerms,
            'both'
        ]);
    }
} catch (Throwable $e) {
    /* Existing installations may have a different users schema; do not block login. */
}

/* =========================================================
   FOUNDER / SUPER ADMIN TOTP SECURITY (LOCAL-FRIENDLY)
   Works offline after initial setup: authenticator apps generate
   6-digit codes locally. Recovery codes are stored as hashes.
   ========================================================= */
function st_random_secret(int $length=20): string { return random_bytes($length); }
function st_base32_encode(string $data): string {
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits=''; $out='';
    for($i=0,$n=strlen($data);$i<$n;$i++) $bits.=str_pad(decbin(ord($data[$i])),8,'0',STR_PAD_LEFT);
    for($i=0,$n=strlen($bits);$i<$n;$i+=5){$chunk=substr($bits,$i,5); if(strlen($chunk)<5)$chunk=str_pad($chunk,5,'0'); $out.=$alphabet[bindec($chunk)];}
    return $out;
}
function st_base32_decode(string $data): string {
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $data=strtoupper(preg_replace('/[^A-Z2-7]/','',$data)??''); $bits=''; $out='';
    for($i=0,$n=strlen($data);$i<$n;$i++){ $pos=strpos($alphabet,$data[$i]); if($pos===false) continue; $bits.=str_pad(decbin($pos),5,'0',STR_PAD_LEFT); }
    for($i=0,$n=strlen($bits)-7;$i<$n;$i+=8) $out.=chr(bindec(substr($bits,$i,8)));
    return $out;
}
function st_totp_code(string $secret,int $time=null): string {
    $time=$time??time(); $counter=intdiv($time,30); $bin=pack('N2',($counter>>32)&0xffffffff,$counter&0xffffffff); $key=st_base32_decode($secret); $hash=hash_hmac('sha1',$bin,$key,true); $offset=ord($hash[19])&15; $num=((ord($hash[$offset])&127)<<24)|((ord($hash[$offset+1])&255)<<16)|((ord($hash[$offset+2])&255)<<8)|(ord($hash[$offset+3])&255); return str_pad((string)($num%1000000),6,'0',STR_PAD_LEFT);
}
function st_totp_verify(string $secret,string $code,int $window=1): bool {
    $code=preg_replace('/\D/','',$code)??''; if(strlen($code)!==6)return false; $now=time(); for($i=-$window;$i<=$window;$i++) if(hash_equals(st_totp_code($secret,$now+$i*30),$code)) return true; return false;
}
function st_make_recovery_codes(int $count=8): array { $plain=[]; $hash=[]; for($i=0;$i<$count;$i++){ $c=strtoupper(bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(4))); $plain[]=$c; $hash[]=password_hash($c,PASSWORD_DEFAULT); } return [$plain,$hash]; }
function st_recovery_verify_and_consume(PDO $pdo,int $userId,string $code): bool {
    $q=$pdo->prepare('SELECT recovery_codes FROM users WHERE id=? LIMIT 1');$q->execute([$userId]);$raw=$q->fetchColumn();$arr=json_decode((string)$raw,true);if(!is_array($arr))return false;
    foreach($arr as $i=>$hash){ if(password_verify($code,$hash)){ unset($arr[$i]); $pdo->prepare('UPDATE users SET recovery_codes=? WHERE id=?')->execute([json_encode(array_values($arr)), $userId]); return true; } } return false;
}
try {
    $cols=$pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC); $names=array_column($cols,'name');
    $adds=['totp_secret'=>'TEXT','totp_enabled'=>'INTEGER NOT NULL DEFAULT 0','recovery_codes'=>'TEXT','totp_setup_at'=>'TEXT'];
    foreach($adds as $cn=>$def) if(!in_array($cn,$names,true)) $pdo->exec("ALTER TABLE users ADD COLUMN $cn $def");
} catch(Throwable $e) { /* MySQL/other DB: best-effort INFORMATION_SCHEMA fallback */
    try { $checks=['totp_secret TEXT','totp_enabled INTEGER NOT NULL DEFAULT 0','recovery_codes TEXT','totp_setup_at TEXT']; foreach($checks as $def){$cn=preg_split('/\s+/',$def)[0]; try{$pdo->exec("ALTER TABLE users ADD COLUMN $def");}catch(Throwable $ignore){}} } catch(Throwable $ignore2) {}
}

/* Keep old UTF-8 emoji labels readable on browsers/WebViews with bad decoding. */
function zan_clean_ui_text(string $html): string {
    $labels=[
        "\u{00E2}\u{0153}\u{2026}"=>'✓', "\u{00E2}\u{0161}\u{00A1}"=>'ZANTRONIX',
        "\u{00E2}\u{2020}\u{00A9}"=>'RUDI', "\u{00F0}\u{0178}\u{0161}\u{2014}"=>'USAFIRI',
        "\u{00F0}\u{0178}\u{201C}\u{0160}"=>'REPORTS', "\u{00F0}\u{0178}\u{201D}\u{009D}"=>'TAARIFA',
        "\u{00F0}\u{0178}\u{201C}\u{139}"=>'ORODHA', "\u{00F0}\u{0178}\u{2018}\u{00B7}"=>'FUNDI',
        "\u{00F0}\u{0178}\u{00A7}\u{00AD}"=>'SAFARI', "\u{00F0}\u{0178}\u{201C}\u{A6}"=>'MATERIAL',
        "\u{00F0}\u{0178}\u{201D}\u{2014}"=>'TOOLS', "\u{00F0}\u{0178}\u{201C}\u{141}"=>'GPS',
        "\u{00F0}\u{0178}\u{201D}\u{2009}"=>'AI', "\u{00F0}\u{0178}\u{2019}\u{AC}"=>'CHAT',
        "\u{00F0}\u{0178}\u{2018}\u{168}"=>'PHOTO', "\u{00E2}\u{009A}\u{00A0}"=>'WARNING'
    ];
    $iconLabels = [
        'âœ…' => '✓',
        'âž•' => '➤',
        '&#9998;' => '✏️',
        'âš™ï¸' => '⚙️',
        '&#128465;' => '🗑️'
    ];
    $clean = strtr($html, $iconLabels);
    $clean = strtr($clean, $labels);
    return preg_replace('/(?:\x{00E2}[\x{0080}-\x{FFFF}]{1,2}|\x{00F0}[\x{0080}-\x{FFFF}]{1,3})/u','',$clean)??$clean;
}
ob_start('zan_clean_ui_text');
if (isset($_SESSION['user']['password'])) {
    unset($_SESSION['user']['password']);
}
if (isset($_SESSION['user']['id'])) {
    $sessionRefresh = $pdo->prepare('SELECT id,name,username,phone,role,permissions,account_system,active,totp_enabled,totp_secret,recovery_codes FROM users WHERE id=? LIMIT 1');
    $sessionRefresh->execute([(int)$_SESSION['user']['id']]);
    $freshSessionUser = $sessionRefresh->fetch(PDO::FETCH_ASSOC);
    if (!$freshSessionUser || !(int)$freshSessionUser['active']) {
        unset($_SESSION['user'], $_SESSION['login_system']);
    } else {
        $_SESSION['user'] = $freshSessionUser;
    }
}

function zan_access_denied($message){
    http_response_code(403);
    $safeMessage=htmlspecialchars((string)$message,ENT_QUOTES,'UTF-8');
    echo '<!doctype html><html lang="sw"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Access denied</title><style>body{margin:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#222;display:grid;place-items:center;min-height:100vh}.box{width:min(520px,calc(100% - 32px));box-sizing:border-box;background:#fff;border-radius:12px;padding:28px;box-shadow:0 3px 14px rgba(0,0,0,.12);text-align:center}.actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:22px}a,button{border:0;border-radius:7px;padding:11px 18px;font-weight:700;text-decoration:none;cursor:pointer;font-size:14px}.back{background:#ffd000;color:#111}.exit{background:#222;color:#fff}@media(max-width:900px){.ai-feature-row{grid-template-columns:repeat(2,minmax(0,1fr))}.ai-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.ai-bottom-grid{grid-template-columns:1fr}.ai-chat-header-actions{display:none}}
@media(max-width:600px){.ai-page{padding:0}.ai-page-head{align-items:flex-start}.ai-page-head .actions{display:none}.ai-hero{padding:20px 18px}.ai-hero-top{gap:12px}.ai-hero-avatar{width:54px;height:54px;flex-basis:54px;font-size:28px}.ai-hero h2{font-size:21px}.ai-feature-row{grid-template-columns:1fr}.ai-prompts{padding-left:16px;padding-right:16px}.ai-chat-log{padding:16px}.ai-metrics{padding-left:14px;padding-right:14px}.ai-status{margin-left:14px;margin-right:14px;flex-direction:column}.ai-side-list{grid-template-columns:1fr;padding-left:14px;padding-right:14px}.ai-data-live-title{padding-left:14px;padding-right:14px}.ai-action-row{padding-left:14px;padding-right:14px}}</style></head><body><main class="box"><h2>Huna ruhusa</h2><p>'.$safeMessage.'</p><div class="actions"><button class="back" type="button" onclick="if(history.length>1){history.back();}else{location.href=\'index.php\';}">â†© RUDI</button><a class="exit" href="index.php?logout=1">EXIT / TOKA</a></div></main></body></html>';
    exit;
}

/* =========================================================
   ROUTING
   ========================================================= */

$page = $_GET['page'] ?? 'dashboard';
$action = $_GET['action'] ?? '';

/* Site Tracking sessions expire after a configurable period of inactivity. */
$stTimeoutMinutes = max(5, min(240, (int)($_SESSION['st_timeout_minutes'] ?? 30)));
if (isset($_SESSION['user'])) {
    $lastActivity = (int)($_SESSION['st_last_activity'] ?? time());
    if (time() - $lastActivity >= $stTimeoutMinutes * 60 && !isset($_GET['logout'])) {
        unset($_SESSION['user'], $_SESSION['login_system'], $_SESSION['st_last_activity']);
        header('Location:index.php?timeout=1');
        exit;
    }
    $_SESSION['st_last_activity'] = time();
}

/* =========================================================
   SYSTEM ISOLATION - EARLY ACTION GUARD
   Super Admin can use everything. Other users must stay
   inside the system(s) explicitly assigned to them.
   ========================================================= */
if (isset($_SESSION['user']) && $action !== '' && strpos($action, 'st_') === 0) {
    $roleForAccess = strtolower(trim($_SESSION['user']['role'] ?? ''));
    $permForAccess = json_decode($_SESSION['user']['permissions'] ?? '{}', true);
    if (!is_array($permForAccess)) $permForAccess = [];
    $isSuperForAccess = in_array($roleForAccess, ['super admin','super_admin','superadmin','founder'], true);
    $accountSystemForAccess = strtolower(trim($_SESSION['user']['account_system'] ?? 'business'));
    $hasSiteForAccess = !empty($permForAccess['site_tracking_system']);

    if (!$isSuperForAccess && empty($hasSiteForAccess) && $accountSystemForAccess !== 'site' && $accountSystemForAccess !== 'both') {
        http_response_code(403);
        zan_access_denied('Huna ruhusa ya kutumia Site Tracking System.');
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
function zan_password_hash($password) {
    return password_hash((string)$password, PASSWORD_DEFAULT);
}
function zan_password_verify($password, $storedHash) {
    $storedHash=(string)$storedHash;
    if($storedHash==='' ) return false;
    if(str_starts_with($storedHash,'$2y$') || str_starts_with($storedHash,'$argon2')) return password_verify((string)$password,$storedHash);
    return hash_equals($storedHash,hash('sha256',(string)$password));
}

function zan_delete_dir($dir) {
    if (!is_dir($dir)) {
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
if (!column_exists('quotations', 'converted_invoice_id')) {
    $pdo->exec("ALTER TABLE quotations ADD COLUMN converted_invoice_id INTEGER DEFAULT NULL");
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
if (!column_exists('products', 'photo')) {
    $pdo->exec("ALTER TABLE products ADD COLUMN photo TEXT DEFAULT ''");
}
try { $pdo->exec("UPDATE products SET stock_received_at=COALESCE(NULLIF(stock_received_at,''),datetime('now'))"); } catch (Exception $e) {}

function zan_upload_product_photo(string $field='product_photo'): string {
    if (empty($_FILES[$field]) || !isset($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return '';
    if ((int)($_FILES[$field]['size'] ?? 0) > 5*1024*1024) throw new Exception('Picha ya bidhaa isiwe zaidi ya 5MB.');
    $tmp=(string)$_FILES[$field]['tmp_name'];
    $mime=(string)($_FILES[$field]['type'] ?? '');
    if (function_exists('finfo_open')) { $fi=finfo_open(FILEINFO_MIME_TYPE); $mime=(string)finfo_file($fi,$tmp); finfo_close($fi); }
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($allowed[$mime])) throw new Exception('Picha ya bidhaa lazima iwe JPG, PNG au WEBP.');
    $dir=__DIR__.'/uploads/products';
    if (!is_dir($dir) && !mkdir($dir,0777,true)) throw new Exception('Folder ya picha za bidhaa haikuweza kutengenezwa.');
    $name='product_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$allowed[$mime];
    if (!move_uploaded_file($tmp,$dir.'/'.$name)) throw new Exception('Picha ya bidhaa haikuweza kuhifadhiwa.');
    return 'uploads/products/'.$name;
}

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
        else { $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([zan_password_hash($newPassword),$u['id']]); log_action('Alibadilisha password','Password reset ya username: '.$username); $resetDone=true; }
    }
    ?>
    <!doctype html><html lang="<?=htmlspecialchars($zanLang, ENT_QUOTES, 'UTF-8')?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=htmlspecialchars(zan_t('Umesahau Nenosiri - Zantronix','Forgot Password - Zantronix'))?></title><link rel="stylesheet" href="style.css"><style>body{background:#ffd000!important}.reset-box{max-width:430px;margin:70px auto;background:#fff;padding:28px;border-radius:16px;box-shadow:0 10px 35px rgba(0,0,0,.18)}.reset-box input{width:100%;box-sizing:border-box;margin:7px 0 15px;padding:12px;border:1px solid #ddd;border-radius:8px}.reset-box button{width:100%}
.invoice-row .qty-minus,.invoice-row .qty-plus{min-width:38px;padding:8px 10px}.invoice-row input[name="qty[]"]{max-width:80px;text-align:center}.doc-notes p{margin:6px 0 12px;white-space:normal}.badge{display:inline-block;margin-top:8px;padding:4px 8px;border-radius:999px;font-weight:800;font-size:9px}
</style>
<style>
.st-actions{display:flex;gap:8px;flex-wrap:wrap}.st-actions .btn{min-width:90px}
.st-edit-box{border:1px solid #ddd;border-radius:12px;padding:16px;margin:15px 0;background:#fff}
.st-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin:15px 0}
.st-chart-wrap{background:#fff;border:1px solid #ddd;border-radius:12px;padding:18px;margin-top:18px}
.st-bar-row{display:grid;grid-template-columns:150px 1fr 55px;gap:10px;align-items:center;margin:10px 0}
.st-bar-track{height:22px;background:#eee;border-radius:20px;overflow:hidden}.st-bar{height:100%;background:#1f6feb;border-radius:20px}
.st-photo{max-width:130px;max-height:130px;border-radius:10px;border:1px solid #ddd;object-fit:cover}
@media(max-width:700px){.st-bar-row{grid-template-columns:100px 1fr 45px}.grid-form{grid-template-columns:1fr!important}.btn{width:100%}.st-actions .btn{width:auto;flex:1}}
</style>
</head><body><div class="reset-box"><h1>âš¡ ZANTRONIX</h1><h2><?=htmlspecialchars(zan_t('Umesahau Nenosiri','Forgot Password'))?></h2><?php if($resetError): ?><div class="notice"><?=htmlspecialchars($resetError)?></div><?php endif; ?><?php if($resetDone): ?><div class="notice">âœ“ <?=htmlspecialchars(zan_t('Nenosiri limebadilishwa. Sasa unaweza kuingia.','Password changed. You can now log in.'))?></div><a class="btn" href="index.php"><?=htmlspecialchars(zan_t('Rudi Kuingia','Back to Login'))?></a><?php else: ?><form method="post" enctype="multipart/form-data"><label><?=htmlspecialchars(zan_t('Jina la mtumiaji','Username'))?><input name="username" required></label><label><?=htmlspecialchars(zan_t('Namba ya simu','Phone Number'))?><input name="phone" required></label><label><?=htmlspecialchars(zan_t('Nenosiri jipya','New Password'))?><input type="password" name="new_password" minlength="6" required></label><label><?=htmlspecialchars(zan_t('Thibitisha Nenosiri','Confirm Password'))?><input type="password" name="confirm_password" minlength="6" required></label><button class="btn" type="submit"><?=htmlspecialchars(zan_t('Weka Upya Nenosiri','Reset Password'))?></button></form><br><a class="btn black" href="index.php"><?=htmlspecialchars(zan_t('Rudi Kuingia','Back to Login'))?></a><?php endif; ?></div>
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
   FOUNDER 2FA GATE / SETUP
   ========================================================= */
if(false && isset($_SESSION['st_2fa_pending_user']) && (($_GET['page'] ?? '') !== 'founder_2fa_setup')){
    $pendingId=(int)$_SESSION['st_2fa_pending_user'];
    $q=$pdo->prepare('SELECT * FROM users WHERE id=? AND active=1 LIMIT 1');$q->execute([$pendingId]);$pending=$q->fetch(PDO::FETCH_ASSOC);
    if(!$pending || !in_array(strtolower(trim($pending['role']??'')),['super admin','super_admin','superadmin','founder'],true)){unset($_SESSION['st_2fa_pending_user']);}
    else {
        $twoErr='';
        if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['st_2fa_verify'])){
            $code=trim((string)($_POST['code']??'')); $ok=st_totp_verify((string)($pending['totp_secret']??''),$code);
            if(!$ok && strlen($code)>=8) $ok=st_recovery_verify_and_consume($pdo,$pendingId,strtoupper($code));
            if($ok){
                unset($_SESSION['st_2fa_pending_user']);
                unset($pending['password']);
                $pending['role']='super admin';
                $pending['account_system']='both';
                $pending['permissions']=json_encode(['site_tracking_system'=>true,'business_system'=>true]);
                $_SESSION['user']=$pending;
                $_SESSION['login_system']='';
                $_SESSION['st_last_activity']=time();
                log_action('Founder 2FA verified','Founder login imekamilika');
                header('Location:index.php?page=choose_system'); exit;
            }
            $twoErr='Code ya 2FA si sahihi. Jaribu tena au tumia recovery code.';
        }
        ?><!doctype html><html lang="sw"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Founder Security</title><style>body{margin:0;background:#f4f6f8;font-family:Arial,sans-serif;display:grid;place-items:center;min-height:100vh}.box{width:min(440px,calc(100% - 30px));background:#fff;padding:30px;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.12)}h1{margin-top:0}.code{font-size:28px;letter-spacing:8px;text-align:center}.btn{width:100%;padding:13px;border:0;border-radius:9px;background:#111;color:#fff;font-weight:700;cursor:pointer}.notice{padding:10px;border-radius:8px;background:#fff3cd;margin:12px 0}.muted{color:#666;font-size:13px}</style></head><body><main class="box"><h1>🔐 Founder Security</h1><p>Ingiza code ya tarakimu 6 kutoka kwenye Authenticator App yako.</p><?php if($twoErr):?><div class="notice"><?=htmlspecialchars($twoErr)?></div><?php endif;?><form method="post"><input type="hidden" name="st_2fa_verify" value="1"><input class="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="20" placeholder="123456" required><br><br><button class="btn">THIBITISHA NA INGIA</button></form><p class="muted">Unaweza pia kutumia recovery code uliyohifadhi wakati wa setup.</p><p style="margin-top:16px;text-align:center"><a href="index.php?page=founder_2fa_setup" style="color:#111;font-weight:700;text-decoration:underline">Sijawahi ku-setup 2FA / Fungua Setup</a></p></main></body></html><?php exit;
    }
}
if(false && isset($_GET['page']) && $_GET['page']==='founder_2fa_setup'){
    $uid=(int)($_SESSION['st_2fa_pending_user'] ?? 0);
    if(!$uid && isset($_SESSION['user'])) $uid=(int)($_SESSION['user']['id'] ?? 0);
    if(!$uid){ header('Location:index.php'); exit; }
    $q=$pdo->prepare('SELECT * FROM users WHERE id=? AND active=1 LIMIT 1');$q->execute([$uid]);$fu=$q->fetch(PDO::FETCH_ASSOC);
    $role=strtolower(trim($fu['role']??'')); if(!$fu || !in_array($role,['super admin','super_admin','superadmin','founder'],true)){ unset($_SESSION['st_2fa_pending_user']); header('Location:index.php'); exit; }
    $setupErr='';$codes=[];
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['st_2fa_setup'])){
        $secret=trim((string)($_POST['secret']??''));$code=trim((string)($_POST['code']??''));
        if($secret==='' || !st_totp_verify($secret,$code,1)) $setupErr='Code ya Authenticator si sahihi. Hakikisha umeingiza code ya sasa.';
        else { [$codes,$hashes]=st_make_recovery_codes(8); $pdo->prepare('UPDATE users SET totp_secret=?,totp_enabled=1,recovery_codes=?,totp_setup_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$secret,json_encode($hashes),$uid]); $_SESSION['st_recovery_codes_once']=$codes; log_action('Founder aliwasha 2FA','TOTP 2FA imewezeshwa'); $_SESSION['user']=$fu; $_SESSION['user']['totp_enabled']=1; $_SESSION['user']['totp_secret']=$secret; unset($_SESSION['st_2fa_pending_user']); $_SESSION['st_last_activity']=time(); $_GET['done']='1'; }
    }
    if(isset($_GET['done']) && empty($codes)) $codes=$_SESSION['st_recovery_codes_once']??[];
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['st_regenerate_recovery'])) { [$codes,$hashes]=st_make_recovery_codes(8); $pdo->prepare('UPDATE users SET recovery_codes=? WHERE id=?')->execute([json_encode($hashes),$uid]); $_SESSION['st_recovery_codes_once']=$codes; $_GET['done']='1'; }
    $secret=(string)($fu['totp_secret']??''); if($secret==='') $secret=st_base32_encode(st_random_secret(20));
    $issuer='Zantronix';$account=(string)($fu['username']??'Founder');$otpauth='otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account).'?secret='.rawurlencode($secret).'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    ?><!doctype html><html lang="sw"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Founder 2FA</title><style>body{margin:0;background:#f4f6f8;font-family:Arial,sans-serif;padding:30px}.wrap{max-width:700px;margin:auto}.card{background:#fff;padding:25px;border-radius:16px;box-shadow:0 5px 20px rgba(0,0,0,.1);margin-bottom:18px}.secret{font-size:22px;letter-spacing:2px;word-break:break-all;background:#f2f2f2;padding:15px;border-radius:8px}.btn{padding:12px 18px;border:0;border-radius:8px;background:#111;color:#fff;font-weight:700;cursor:pointer}.err{background:#ffe2e2;padding:10px;border-radius:8px}.codes{font-family:monospace;font-size:18px;line-height:1.8}</style></head><body><div class="wrap"><div class="card"><h1>🔐 Founder 2FA</h1><p>Weka <b>Google Authenticator</b>, Microsoft Authenticator au Authy kwenye simu. Chagua <b>Add account → Enter setup key</b>.</p><div class="secret"><?=htmlspecialchars($secret)?></div><p>Account: <b><?=htmlspecialchars($account)?></b></p><p style="font-size:12px;color:#666;word-break:break-all">OTPAUTH: <?=htmlspecialchars($otpauth)?></p><?php if($setupErr):?><div class="err"><?=htmlspecialchars($setupErr)?></div><?php endif;?><form method="post"><input type="hidden" name="st_2fa_setup" value="1"><input type="hidden" name="secret" value="<?=htmlspecialchars($secret)?>"><label>Ingiza code ya tarakimu 6 <input name="code" inputmode="numeric" maxlength="6" required style="padding:12px;font-size:20px;width:100%;box-sizing:border-box;margin:8px 0 15px"></label><button class="btn">WASHA 2FA</button></form></div><?php if($codes):?><div class="card"><h2>Recovery Codes — zihifadhi sasa</h2><p>Kila code inaweza kutumika mara moja. Usizishirikishe.</p><div class="codes"><?php foreach($codes as $c):?><div><?=htmlspecialchars($c)?></div><?php endforeach;?></div></div><?php else:?><div class="card"><h2>Recovery Codes</h2><p>Codes hazijaonyeshwa kwenye session hii. Bonyeza hapa kutengeneza codes mpya.</p><form method="post"><input type="hidden" name="st_regenerate_recovery" value="1"><button class="btn" type="submit">TENGENEZA RECOVERY CODES</button></form></div><?php endif;?></div></body></html><?php exit;
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

        $s = $pdo->prepare('SELECT * FROM users WHERE username=? AND active=1 LIMIT 1');
        $s->execute([trim((string)($_POST['username'] ?? ''))]);
        $u = $s->fetch();
        $loginPassword=(string)($_POST['password']??'');
        if($u && !zan_password_verify($loginPassword,$u['password'] ?? '')) $u=false;

        if ($u && $u['active']) {

            if(!str_starts_with((string)$u['password'],'$2y$')&&!str_starts_with((string)$u['password'],'$argon2')){
                $pdo->prepare('UPDATE users SET password=? WHERE id=?')->execute([zan_password_hash($loginPassword),(int)$u['id']]);
            }
            $loginRoleLower=strtolower(trim($u['role']??''));
            $isFounderLogin=in_array($loginRoleLower,['super admin','super_admin','superadmin','founder'],true);
            if($isFounderLogin) {
                // Founder/Super Admin always owns BOTH systems, regardless of stale
                // account_system/permissions values saved on the user record.
                $u['role']='super admin';
                $u['account_system']='both';
                $u['permissions']=json_encode(['site_tracking_system'=>true,'business_system'=>true]);
                $pdo->prepare("UPDATE users SET role='super admin', account_system='both', permissions=? WHERE id=? AND active=1")
                    ->execute([$u['permissions'],(int)$u['id']]);
                $_SESSION['user']=$u;
                $_SESSION['login_system']='';
                $_SESSION['st_last_activity']=time();
            }
            $accountSystem = strtolower(trim($u['account_system'] ?? 'business'));
            $loginPermsForChoice = json_decode($u['permissions'] ?? '{}', true);
            if (!is_array($loginPermsForChoice)) $loginPermsForChoice = [];
            $canChooseSite = !empty($loginPermsForChoice['site_tracking_system']) || in_array($accountSystem, ['site','both'], true);
            $canChooseBusiness = in_array(strtolower(trim($u['role'] ?? '')), ['admin','msimamizi','administrator','super admin','super_admin','superadmin'], true)
                || !empty($loginPermsForChoice['business_system'])
                || in_array($accountSystem, ['business','both'], true);
            $requestedSystem = strtolower(trim($_POST['login_system'] ?? ''));
            if ($requestedSystem === 'site' && !$canChooseSite) $requestedSystem = '';
            if ($requestedSystem === 'business' && !$canChooseBusiness) $requestedSystem = '';
            if ($requestedSystem === '') {
                if ($canChooseSite && !$canChooseBusiness) $requestedSystem = 'site';
                elseif ($canChooseBusiness && !$canChooseSite) $requestedSystem = 'business';
                else $requestedSystem = 'site';
            }
            unset($u['password']);
            $_SESSION['user'] = $u;

            // Kama ana access ya mifumo yote miwili, usimpeleke moja kwa moja.
            // Mpeleke kwanza kwenye ukurasa wa kuchagua mfumo.
            if ($canChooseSite && $canChooseBusiness && empty($_POST['login_system'])) {
                $_SESSION['login_system'] = '';
                header('Location: index.php?page=choose_system');
                exit;
            }

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
    ['super admin', 'super_admin', 'superadmin', 'founder'],
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

            if ($requestedSystem === 'site' && $hasTools) {

    // User aliyepewa Site Tracking anaingia moja kwa moja kwenye Site Tracking.
    header('Location: index.php?page=site_tracking');
    exit;

} elseif ($requestedSystem === 'business' && $hasBusiness) {

    // User aliyechagua Business System anaingia kwenye Business System.
    header('Location: index.php');
    exit;

} elseif ($hasTools) {
    header('Location: index.php?page=site_tracking');
    exit;

} elseif ($hasBusiness) {
    header('Location: index.php');
    exit;

} else {

    // Hana ruhusa ya mfumo wowote.
    http_response_code(403);
    zan_access_denied('Huna ruhusa ya kutumia mfumo.');
}
        }

        $err = ($u && !$u['active'])
            ? 'Mtumiaji huyu amezimwa.'
            : 'Jina la mtumiaji au nenosiri si sahihi.';
    }
?>
<!doctype html>
<html lang="<?=htmlspecialchars($zanLang, ENT_QUOTES, 'UTF-8')?>">

<head>

<meta charset="utf-8">

<meta name="viewport"
      content="width=device-width,initial-scale=1">

<title><?=htmlspecialchars(zan_t('Kuingia - Zantronix','Login - Zantronix'))?></title>

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
<div style="font-size:28px;font-weight:800;text-align:center;margin-bottom:18px">âš¡</div>
<?php endif; ?>

<?php if ($err): ?>

<div class="notice">
    <?=htmlspecialchars($err)?>
</div>

<?php endif; ?>

<form method="post" enctype="multipart/form-data" autocomplete="off" onsubmit="document.getElementById('login_username_real').value=this.zan_login_identifier.value;document.getElementById('login_password_real').value=this.zan_login_secret.value;">
<input type="hidden" name="username" id="login_username_real" value="">
<input type="hidden" name="password" id="login_password_real" value="">

<label>
<?=htmlspecialchars(zan_t('Jina la mtumiaji','Username'))?>

<input
    class="search"
    style="width:100%;margin:6px 0 14px"
    name="zan_login_identifier"
    type="text"
    autocomplete="off"
    readonly
    onfocus="this.removeAttribute('readonly')"
    autocapitalize="none"
    spellcheck="false"
    data-lpignore="true"
    data-1p-ignore="true"
    required
>

</label>

<label>
<?=htmlspecialchars(zan_t('Nenosiri','Password'))?>

<div style="position:relative;width:100%;margin:6px 0 18px">
<input
    class="search"
    style="width:100%;padding-right:48px;box-sizing:border-box;margin:0"
    type="password"
    name="zan_login_secret"
    id="login_password_field"
    autocomplete="new-password"
    readonly
    onfocus="this.removeAttribute('readonly')"
    data-lpignore="true"
    data-1p-ignore="true"
    required
>
<button type="button" id="toggle_login_password" aria-label="Onesha password" title="Onesha password" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:transparent;font-size:19px;cursor:pointer;padding:6px;line-height:1">👁️</button>
</div>

</label>

<script>
(function(){
  var b=document.getElementById('toggle_login_password');
  var i=document.getElementById('login_password_field');
  if(b&&i){b.addEventListener('click',function(){var show=i.type==='password';i.type=show?'text':'password';b.textContent=show?'🙈':'👁️';b.title=show?'Ficha password':'Onesha password';b.setAttribute('aria-label',show?'Ficha password':'Onesha password');});}
})();
</script>

<div class="notice" style="margin:8px 0 18px">Baada ya username na password kuthibitishwa, kama una mifumo zaidi ya mmoja utaonyeshwa <b>Chagua Mfumo</b>.</div>

<button
    class="btn"
    style="width:100%"
>
<?=htmlspecialchars(zan_t('INGIA','LOGIN'))?>
</button>

</form>

<p style="text-align:center;margin-top:15px"><a href="?page=forgot_password"><?=htmlspecialchars(zan_t('Umesahau Nenosiri?','Forgot Password?'))?></a></p>

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
$pdo->exec("CREATE TABLE IF NOT EXISTS st_assignment_jobs (
 id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, job_title TEXT NOT NULL,
 technician_id INTEGER NOT NULL, driver_id INTEGER NOT NULL, vehicle_id INTEGER NOT NULL,
 technician_task_id INTEGER, driver_task_id INTEGER, trip_id INTEGER, priority TEXT DEFAULT 'Normal',
 due_at TEXT, notes TEXT, status TEXT DEFAULT 'Assigned', created_by TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
try{$pdo->exec("ALTER TABLE st_assignment_jobs ADD COLUMN materials_text TEXT");}catch(Throwable $e){}
$pdo->exec("CREATE TABLE IF NOT EXISTS st_team_change_requests (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL,
 requester_id INTEGER NOT NULL,
 technician_id INTEGER NOT NULL,
 reason TEXT NOT NULL,
 status TEXT DEFAULT 'Pending',
 reviewed_by TEXT,
 reviewed_at TEXT,
 created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS st_daily_updates (
 id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, technician_id INTEGER NOT NULL,
 update_date TEXT NOT NULL, work_done TEXT NOT NULL, blockers TEXT, next_steps TEXT,
 photo TEXT, latitude REAL, longitude REAL, accuracy REAL, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(site_id, technician_id, update_date)
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS st_notifications (
 id INTEGER PRIMARY KEY AUTOINCREMENT, technician_id INTEGER, notification_type TEXT NOT NULL,
 recipient_user_id INTEGER, recipient_type TEXT DEFAULT 'technician', message TEXT NOT NULL, channel TEXT DEFAULT 'whatsapp', status TEXT DEFAULT 'Pending',
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
$pdo->exec("CREATE TABLE IF NOT EXISTS st_evidence_photos (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, category TEXT NOT NULL,
 photo TEXT NOT NULL, uploaded_by TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS st_vehicles (
 id INTEGER PRIMARY KEY AUTOINCREMENT, registration_no TEXT NOT NULL UNIQUE,
 vehicle_type TEXT NOT NULL DEFAULT 'Car', make_model TEXT DEFAULT '',
 ownership TEXT DEFAULT 'Company', status TEXT DEFAULT 'Available',
 insurance_expiry TEXT DEFAULT '', service_due TEXT DEFAULT '', notes TEXT DEFAULT '',
 created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS st_drivers (
 id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT NOT NULL, phone TEXT DEFAULT '',
 license_no TEXT DEFAULT '', license_expiry TEXT DEFAULT '', address TEXT DEFAULT '',
 gender TEXT DEFAULT '', marital_status TEXT DEFAULT '', children_count INTEGER DEFAULT 0,
 emergency_name TEXT DEFAULT '', emergency_phone TEXT DEFAULT '', status TEXT DEFAULT 'Active', user_id INTEGER,
 username TEXT DEFAULT '', notes TEXT DEFAULT '', created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS st_transport_trips (
 id INTEGER PRIMARY KEY AUTOINCREMENT, vehicle_id INTEGER NOT NULL, driver_id INTEGER NOT NULL,
 site_id INTEGER, destination TEXT NOT NULL, purpose TEXT DEFAULT '', departure_at TEXT NOT NULL,
 return_at TEXT DEFAULT '', start_latitude REAL, start_longitude REAL, start_accuracy REAL,
 end_latitude REAL, end_longitude REAL, end_accuracy REAL, start_odometer REAL DEFAULT 0,
 end_odometer REAL DEFAULT 0, status TEXT DEFAULT 'Open', notes TEXT DEFAULT '',
 created_by TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS st_transport_trip_technicians (
 trip_id INTEGER NOT NULL, technician_id INTEGER NOT NULL, PRIMARY KEY(trip_id,technician_id)
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS st_transport_requests (
 id INTEGER PRIMARY KEY AUTOINCREMENT, driver_id INTEGER NOT NULL, trip_id INTEGER,
 request_type TEXT NOT NULL, amount REAL DEFAULT 0, description TEXT NOT NULL,
 photo TEXT DEFAULT '', latitude REAL, longitude REAL, status TEXT DEFAULT 'Pending',
 reviewed_by TEXT DEFAULT '', reviewed_at TEXT DEFAULT '', created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

/* Extra columns for old databases. */
function st_add_column($pdo,$table,$column,$definition){
    try {
        $cols=$pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
        foreach($cols as $c){ if(($c['name']??'')===$column) return; }
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    } catch(Throwable $e) {}
}
st_add_column($pdo,'st_notifications','recipient_user_id','INTEGER');
st_add_column($pdo,'st_notifications','recipient_type','TEXT DEFAULT \'technician\'');
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
st_add_column($pdo,'st_sites','jobcard_photo','TEXT');
st_add_column($pdo,'st_sites','completion_submitted_at','TEXT');
st_add_column($pdo,'st_sites','completion_submitted_by','TEXT');
st_add_column($pdo,'st_sites','admin_approved','INTEGER DEFAULT 0');
st_add_column($pdo,'st_sites','admin_approved_by','TEXT');
st_add_column($pdo,'st_sites','admin_approved_at','TEXT');
st_add_column($pdo,'st_sites','rejection_reason','TEXT');
st_add_column($pdo,'st_sites','introduced_by','TEXT');
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
st_add_column($pdo,'st_site_tasks','assigned_to_type','TEXT DEFAULT \'technician\'');
st_add_column($pdo,'st_site_tasks','task_type','TEXT DEFAULT \'site\'');
st_add_column($pdo,'st_site_tasks','priority','TEXT DEFAULT \'Normal\'');
st_add_column($pdo,'st_site_tasks','due_at','TEXT DEFAULT \'\'');
st_add_column($pdo,'st_site_tasks','accepted_at','TEXT');
st_add_column($pdo,'st_site_tasks','started_at','TEXT');
st_add_column($pdo,'st_site_tasks','blocked_at','TEXT');
st_add_column($pdo,'st_site_tasks','blocked_reason','TEXT DEFAULT \'\'');
st_add_column($pdo,'st_site_tasks','task_update_notes','TEXT DEFAULT \'\'');
st_add_column($pdo,'st_vehicles','insurance_expiry','TEXT');
st_add_column($pdo,'st_vehicles','service_due','TEXT');
st_add_column($pdo,'st_drivers','username','TEXT');
st_add_column($pdo,'st_drivers','license_expiry','TEXT');
st_add_column($pdo,'st_drivers','address','TEXT');
st_add_column($pdo,'st_transport_requests','review_comment',"TEXT DEFAULT ''");
st_add_column($pdo,'st_transport_trips','driver_arrived_at','TEXT');
st_add_column($pdo,'st_transport_trips','driver_arrival_latitude','REAL');
st_add_column($pdo,'st_transport_trips','driver_arrival_longitude','REAL');
st_add_column($pdo,'st_transport_trips','driver_arrival_accuracy','REAL');
st_add_column($pdo,'st_transport_trip_technicians','arrived_at','TEXT');
st_add_column($pdo,'st_transport_trip_technicians','arrival_latitude','REAL');
st_add_column($pdo,'st_transport_trip_technicians','arrival_longitude','REAL');
st_add_column($pdo,'st_transport_trip_technicians','arrival_accuracy','REAL');
st_add_column($pdo,'st_drivers','gender','TEXT');
st_add_column($pdo,'st_drivers','marital_status','TEXT');
st_add_column($pdo,'st_drivers','children_count','INTEGER DEFAULT 0');
st_add_column($pdo,'st_drivers','photo','TEXT');
st_add_column($pdo,'st_drivers','emergency_name','TEXT');
st_add_column($pdo,'st_drivers','emergency_phone','TEXT');
$pdo->exec("CREATE TABLE IF NOT EXISTS st_money_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, recipient_type TEXT NOT NULL DEFAULT 'technician', recipient_id INTEGER NOT NULL, site_id INTEGER, amount REAL NOT NULL DEFAULT 0, currency TEXT DEFAULT 'TZS', purpose TEXT DEFAULT '', status TEXT DEFAULT 'Granted', receipt_photo TEXT DEFAULT '', receipt_notes TEXT DEFAULT '', receipt_submitted_at TEXT, receipt_latitude REAL, receipt_longitude REAL, receipt_accuracy REAL, approved_by TEXT DEFAULT '', approved_at TEXT, rejection_reason TEXT DEFAULT '', granted_by TEXT DEFAULT '', granted_at TEXT DEFAULT CURRENT_TIMESTAMP, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
st_add_column($pdo,'st_money_transactions','received_by',"TEXT DEFAULT ''");
st_add_column($pdo,'st_money_transactions','received_at','TEXT');

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
function st_is_super_admin(){ return in_array(st_user_role(),['super admin','super_admin','superadmin','founder'],true); }
function st_has_site_permission(){ return st_is_super_admin() || !empty(st_system_permissions()['site_tracking_system']); }
function st_has_business_permission(){ $r=st_user_role();$p=st_system_permissions();return st_is_super_admin()||in_array($r,['admin','msimamizi'],true)||!empty($p['business_system']); }
function st_site_admin_allowed(){ return st_has_site_permission() && st_is_admin(); }
function st_current_user_name(){ return $_SESSION['user']['username'] ?? ($_SESSION['user']['name'] ?? 'User'); }
function st_whatsapp_number($phone){
    // Customers may have more than one phone stored in the same field.
    // Try each number until a valid WhatsApp number is found.
    $raw=(string)$phone;
    $candidates=preg_split('/[,;\/\n\r]+/',$raw);
    foreach($candidates as $candidate){
        $number=preg_replace('/[^0-9]/','',(string)$candidate);
        if($number==='')continue;
        // Tanzania formats: 0713xxxxxx, 713xxxxxx, +255713xxxxxx, 00255713xxxxxx.
        if(str_starts_with($number,'00'))$number=substr($number,2);
        if(str_starts_with($number,'0') && strlen($number)===10)$number='255'.substr($number,1);
        elseif(strlen($number)===9 && preg_match('/^[67][0-9]{8}$/',$number))$number='255'.$number;
        if(preg_match('/^255[67][0-9]{8}$/',$number))return $number;
        // Keep valid international/E.164 style numbers if stored with country code.
        if(preg_match('/^[1-9][0-9]{9,14}$/',$number))return $number;
    }
    return '';
}
function st_whatsapp_icon(){
    return '<svg class="wa-svg" viewBox="0 0 32 32" aria-hidden="true"><path fill="currentColor" d="M16 3.2A12.8 12.8 0 0 0 5.1 22.7L3.3 28.7l6.2-1.8A12.8 12.8 0 1 0 16 3.2Zm0 23.1a10.2 10.2 0 0 1-5.2-1.4l-.4-.2-3.7 1.1 1.1-3.6-.2-.4A10.2 10.2 0 1 1 16 26.3Zm5.6-7.6c-.3-.2-1.8-.9-2.1-1-.3-.1-.5-.2-.7.2-.2.3-.8 1-.9 1.2-.2.2-.3.2-.6.1-1.7-.8-2.8-1.4-3.9-3.2-.3-.5.3-.5.8-1.6.1-.2.1-.4 0-.6l-.9-2.2c-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.6.1-.9.4-.3.3-1.1 1-1.1 2.5s1.1 2.9 1.3 3.1c.2.2 2.2 3.4 5.4 4.7 2 .8 2.7.9 3.7.8.6-.1 1.8-.7 2-1.4.3-.7.3-1.3.2-1.4-.1-.1-.3-.2-.6-.3Z"/></svg>';
}
function st_whatsapp_link($phone,$message){
    $number=st_whatsapp_number($phone);
    if($number==='')return '';
    // Use WhatsApp's official send endpoint; it works reliably on both desktop and mobile.
    return 'https://wa.me/'.rawurlencode($number).'?text='.rawurlencode($message);
}
function st_queue_whatsapp_notification($pdo,$technicianId,$type,$message){
    $q=$pdo->prepare("SELECT id FROM st_notifications WHERE technician_id=? AND notification_type=? AND message=? AND date(created_at)=date('now') LIMIT 1");
    $q->execute([(int)$technicianId,$type,$message]);
    if(!$q->fetch())$pdo->prepare('INSERT INTO st_notifications(technician_id,notification_type,message) VALUES(?,?,?)')->execute([(int)$technicianId,$type,$message]);
}
function st_queue_task_assignment_notification($pdo,$recipientType,$recipientId,$taskTitle,$siteName,$type='site_task_assigned'){
    $message='Umepewa task "'.$taskTitle.'" kwa site '.$siteName.'.';
    $q=$pdo->prepare("SELECT id FROM st_notifications WHERE technician_id=? AND recipient_type=? AND notification_type=? AND message=? AND date(created_at)=date('now') LIMIT 1");
    $q->execute([(int)$recipientId,$recipientType,$type,$message]);
    if(!$q->fetch())$pdo->prepare('INSERT INTO st_notifications(technician_id,recipient_type,notification_type,message,channel,status) VALUES(?,?,?,?,\'whatsapp\',\'Pending\')')->execute([(int)$recipientId,$recipientType,$type,$message]);
}
function st_driver_notifications($pdo,$driverId,$limit=20){
    $q=$pdo->prepare("SELECT * FROM st_notifications WHERE technician_id=? AND recipient_type='driver' ORDER BY id DESC LIMIT ".(int)$limit);
    $q->execute([(int)$driverId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function st_notify_site_admins($pdo,$type,$message){
    $admins=$pdo->query("SELECT DISTINCT u.id FROM users u LEFT JOIN st_site_admins sa ON sa.user_id=u.id WHERE u.active=1 AND (LOWER(u.role) IN ('admin','msimamizi','manager','meneja','technical manager','technical_manager','technicalmanager','site admin','site_admin') OR LOWER(u.role) LIKE '%super%') AND (u.account_system IN ('site','both') OR sa.id IS NOT NULL)")->fetchAll(PDO::FETCH_COLUMN);
    $insert=$pdo->prepare("INSERT INTO st_notifications(recipient_user_id,recipient_type,notification_type,message,channel,status) VALUES(?,'site_admin',?,?, 'whatsapp','Pending')");
    foreach($admins as $adminId){
        $exists=$pdo->prepare("SELECT id FROM st_notifications WHERE recipient_user_id=? AND recipient_type='site_admin' AND notification_type=? AND message=? AND date(created_at)=date('now') LIMIT 1");
        $exists->execute([(int)$adminId,$type,$message]);
        if(!$exists->fetchColumn())$insert->execute([(int)$adminId,$type,$message]);
    }
}
function st_user_notifications($pdo,$userId,$limit=20){
    $q=$pdo->prepare("SELECT n.*,u.phone FROM st_notifications n LEFT JOIN users u ON u.id=n.recipient_user_id WHERE n.recipient_type='site_admin' AND n.recipient_user_id=? ORDER BY n.id DESC LIMIT ".(int)$limit);
    $q->execute([(int)$userId]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function st_technician_notifications($pdo,$technicianId,$limit=20){
    $q=$pdo->prepare("SELECT * FROM st_notifications WHERE technician_id=? AND (recipient_type='technician' OR recipient_type IS NULL) ORDER BY id DESC LIMIT ".(int)$limit);
    $q->execute([(int)$technicianId]);return $q->fetchAll(PDO::FETCH_ASSOC);
}
function st_csrf_token(){
    if(empty($_SESSION['st_csrf_token']))$_SESSION['st_csrf_token']=bin2hex(random_bytes(32));
    return $_SESSION['st_csrf_token'];
}
function zan_require_csrf(){
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST' || !hash_equals(st_csrf_token(),(string)($_POST['st_csrf']??''))){
        http_response_code(419);
        exit('Ombi limekataliwa. Refresh ukurasa ujaribu tena.');
    }
}
function st_audit($pdo,$type,$id,$action,$details=''){
    $u=$_SESSION['user']['username'] ?? ($_SESSION['user']['name'] ?? 'System');
    $pdo->prepare("INSERT INTO st_audit_log(entity_type,entity_id,action,details,performed_by) VALUES(?,?,?,?,?)")->execute([$type,$id,$action,$details,$u]);
}
function st_is_manager(){
    if(!st_has_site_permission()) return false;
    $r=st_user_role();
    if(in_array($r,['manager','meneja','admin','msimamizi','super admin','super_admin','superadmin'],true)) return true;
    try{
        global $pdo;
        $uid=(int)($_SESSION['user']['id']??0);
        if($uid){
            $q=$pdo->prepare("SELECT admin_type FROM st_site_admins WHERE user_id=? AND active=1 LIMIT 1");
            $q->execute([$uid]);
            $t=strtolower(trim((string)$q->fetchColumn()));
            return in_array($t,['manager','meneja'],true);
        }
    }catch(Throwable $e){}
    return false;
}
function st_is_technical(){
    if(!st_has_site_permission()) return false;
    $r=st_user_role();
    if(in_array($r,['technical manager','technical_manager','technicalmanager','admin','msimamizi','super admin','super_admin','superadmin'],true)) return true;
    try{
        global $pdo;
        $uid=(int)($_SESSION['user']['id']??0);
        if($uid){
            $q=$pdo->prepare("SELECT admin_type FROM st_site_admins WHERE user_id=? AND active=1 LIMIT 1");
            $q->execute([$uid]);
            $t=strtolower(trim((string)$q->fetchColumn()));
            return in_array($t,['technical manager','technical_manager','technicalmanager'],true);
        }
    }catch(Throwable $e){}
    return false;
}
function st_is_admin(){ if(st_is_super_admin())return true;if(!st_has_site_permission())return false;$r=st_user_role();if(in_array($r,['admin','msimamizi','administrator','site administrator','site admin','site_admin','manager','meneja','technical manager','technical_manager','technicalmanager'],true))return true;try{$uid=(int)($_SESSION['user']['id']??0);if($uid){global $pdo;$q=$pdo->prepare('SELECT 1 FROM st_site_admins WHERE user_id=? AND active=1 LIMIT 1');$q->execute([$uid]);return (bool)$q->fetchColumn();}}catch(Throwable $e){}return false; }
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
function st_is_driver(){ return st_has_site_permission() && in_array(st_user_role(),['driver','dereva'],true); }

function st_current_technician($pdo){
    $uid=(int)($_SESSION['user']['id']??0);
    if(!$uid) return null;
    $q=$pdo->prepare("SELECT * FROM st_technicians WHERE user_id=? LIMIT 1");
    $q->execute([$uid]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}
function st_current_driver($pdo){
    $uid=(int)($_SESSION['user']['id']??0);if(!$uid)return null;
    $q=$pdo->prepare('SELECT * FROM st_drivers WHERE user_id=? AND status=\'Active\' LIMIT 1');$q->execute([$uid]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
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
function st_upload_photos($field){
    $photos=[];
    if(empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name'])) return $photos;
    $files=$_FILES[$field];
    foreach($files['name'] as $i=>$name){
        if((int)($files['error'][$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) continue;
        $_FILES[$field]=[
            'name'=>$files['name'][$i], 'type'=>$files['type'][$i]??'',
            'tmp_name'=>$files['tmp_name'][$i], 'error'=>$files['error'][$i],
            'size'=>$files['size'][$i]??0
        ];
        $photo=st_upload_photo($field);
        if($photo)$photos[]=$photo;
    }
    $_FILES[$field]=$files;
    return $photos;
}
function st_save_evidence_photos($pdo,$entityType,$entityId,$category,$photos,$uploadedBy){
    if(!$photos)return;
    $q=$pdo->prepare('INSERT INTO st_evidence_photos(entity_type,entity_id,category,photo,uploaded_by) VALUES(?,?,?,?,?)');
    foreach($photos as $photo)$q->execute([$entityType,(int)$entityId,$category,$photo,$uploadedBy]);
}
function zan_business_ai_context(PDO $pdo): string{
    $facts=[];
    $queries=[
        'Customers'=>'SELECT COUNT(*) FROM customers',
        'Products'=>'SELECT COUNT(*) FROM products',
        'Low stock products'=>"SELECT COUNT(*) FROM products WHERE COALESCE(stock,0)<=COALESCE(low_stock,0)",
        'Suppliers'=>'SELECT COUNT(*) FROM suppliers',
        'Invoices'=>'SELECT COUNT(*) FROM invoices',
        'Invoice total'=>"SELECT COALESCE(SUM(COALESCE(grand_total,total,0)),0) FROM invoices",
        'Paid invoices'=>"SELECT COALESCE(SUM(COALESCE(paid,0)),0) FROM invoices",
        'Expenses'=>'SELECT COALESCE(SUM(COALESCE(amount,0)),0) FROM expenses',
        'Quotations'=>'SELECT COUNT(*) FROM quotations',
        'Proforma invoices'=>'SELECT COUNT(*) FROM proforma_invoices',
        'Customers with balance'=>"SELECT COUNT(DISTINCT customer_id) FROM invoices WHERE COALESCE(total,0)>COALESCE(paid,0)",
        'Outstanding balance'=>"SELECT COALESCE(SUM(MAX(COALESCE(total,0)-COALESCE(paid,0),0)),0) FROM invoices"
    ];
    foreach($queries as $label=>$sql){
        try{$facts[]=$label.': '.$pdo->query($sql)->fetchColumn();}catch(Throwable $e){}
    }
    $details=[];
    $detailQueries=[
        'Customer records'=>"SELECT name,phone,email FROM customers ORDER BY id DESC LIMIT 30",
        'Product records'=>"SELECT name,COALESCE(stock,0) stock,COALESCE(low_stock,0) low_stock,COALESCE(price,0) price FROM products ORDER BY id DESC LIMIT 50",
        'Supplier records'=>"SELECT company_name,phone,email FROM suppliers ORDER BY id DESC LIMIT 30",
        'Invoice records'=>"SELECT invoice_no,customer_id,COALESCE(grand_total,total,0) total,COALESCE(paid,0) paid,status FROM invoices ORDER BY id DESC LIMIT 50",
        'Quotation records'=>"SELECT quotation_no,total,status FROM quotations ORDER BY id DESC LIMIT 30",
        'Expense records'=>"SELECT category,amount,expense_date,description FROM expenses ORDER BY id DESC LIMIT 30",
        'User records'=>"SELECT name,username,role,active FROM users WHERE account_system IN ('business','both') ORDER BY id DESC LIMIT 30"
    ];
    foreach($detailQueries as $label=>$sql){
        try{
            $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            if($rows){
                $values=[];
                foreach($rows as $row){
                    $parts=[];foreach($row as $key=>$value)$parts[]=$key.'='.($value===null?'':(string)$value);
                    $values[]=implode(', ',$parts);
                }
                $details[]=$label.': '.implode(' | ',$values);
            }
        }catch(Throwable $e){}
    }
    return implode('; ',$facts).( $details ? '. Records: '.implode('. ',$details) : '');
}
function zan_business_ai_local_answer(PDO $pdo,string $question): ?string{
    $text=strtolower(trim($question));
    if(str_contains($text,'deni') || str_contains($text,'madeni') || str_contains($text,'wadaiwa') || str_contains($text,'outstanding')){
        try{
            $count=(int)$pdo->query("SELECT COUNT(DISTINCT customer_id) FROM invoices WHERE COALESCE(total,0)>COALESCE(paid,0)")->fetchColumn();
            $balance=(float)$pdo->query("SELECT COALESCE(SUM(MAX(COALESCE(total,0)-COALESCE(paid,0),0)),0) FROM invoices")->fetchColumn();
            return 'Kwa sasa kuna '.$count.' wateja wenye deni la jumla ya TZS '.number_format($balance,2).'. Fungua Wateja Wadaiwa kuona salio na WhatsApp reminders.';
        }catch(Throwable $e){return 'Siwezi kusoma taarifa za madeni kwa sasa.';}
    }
    if(str_contains($text,'jina la kampuni') || str_contains($text,'company name') || $text==='kampuni'){
        try{
            $name=$pdo->query('SELECT company_name FROM settings WHERE id=1 LIMIT 1')->fetchColumn();
            return 'Jina la kampuni ni '.trim((string)$name ?: 'Zantronix').'.';
        }catch(Throwable $e){return 'Jina la kampuni ni Zantronix.';}
    }
    if((str_contains($text,'bidhaa') || str_contains($text,'product')) &&
       (str_contains($text,'uzwa') || str_contains($text,'uzika') || str_contains($text,'uza') || str_contains($text,'sold') || str_contains($text,'sana'))){
        try{
            $q=$pdo->query("SELECT p.name,COALESCE(SUM(ii.qty),0) sold_qty
                FROM invoice_items ii
                INNER JOIN products p ON p.id=ii.product_id
                INNER JOIN invoices i ON i.id=ii.invoice_id
                GROUP BY p.id,p.name
                ORDER BY sold_qty DESC,p.name ASC LIMIT 10");
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(!$rows)return 'Bado hakuna bidhaa iliyouzwa kwenye invoice.';
            $names=[];foreach($rows as $row)$names[]=$row['name'].' ('.$row['sold_qty'].' sold)';
            return 'Bidhaa zilizouzwa zaidi ni: '.implode(', ',$names).'.';
        }catch(Throwable $e){return 'Siwezi kusoma historia ya mauzo ya bidhaa kwa sasa.';}
    }
    $localCounts=[
        'customer'=>['Wateja','SELECT COUNT(*) FROM customers'],
        'mteja'=>['Wateja','SELECT COUNT(*) FROM customers'],
        'wateja'=>['Wateja','SELECT COUNT(*) FROM customers'],
        'product'=>['Bidhaa','SELECT COUNT(*) FROM products'],
        'bidhaa'=>['Bidhaa','SELECT COUNT(*) FROM products'],
        'supplier'=>['Wasambazaji','SELECT COUNT(*) FROM suppliers'],
        'msambazaji'=>['Wasambazaji','SELECT COUNT(*) FROM suppliers'],
        'wasambazaji'=>['Wasambazaji','SELECT COUNT(*) FROM suppliers'],
        'expense'=>['Expenses','SELECT COALESCE(SUM(COALESCE(amount,0)),0) FROM expenses'],
        'matumizi'=>['Matumizi','SELECT COALESCE(SUM(COALESCE(amount,0)),0) FROM expenses'],
        'site'=>['Active sites',"SELECT COUNT(*) FROM st_sites WHERE status<>'Closed'"],
        'fundi'=>['Active technicians',"SELECT COUNT(*) FROM st_technicians WHERE status='Active'"],
        'technician'=>['Active technicians',"SELECT COUNT(*) FROM st_technicians WHERE status='Active'"],
        'mtumiaji'=>['Watumiaji','SELECT COUNT(*) FROM users'],
        'watumiaji'=>['Watumiaji','SELECT COUNT(*) FROM users'],
        'quotation'=>['Quotations','SELECT COUNT(*) FROM quotations'],
        'proforma'=>['Proforma invoices','SELECT COUNT(*) FROM proforma_invoices']
    ];
    foreach($localCounts as $keyword=>$definition){
        if($keyword==='bidhaa' && (str_contains($text,'low stock') || str_contains($text,'stock chini') || str_contains($text,'bidhaa chini')))continue;
        if(str_contains($text,$keyword)){
            try{
                $value=$pdo->query($definition[1])->fetchColumn();
                $suffix=in_array($keyword,['expense','matumizi'],true)?' TZS':'';
                return 'Kwa sasa kuna '.$value.' '.$definition[0].$suffix.'.';
            }catch(Throwable $e){return 'Siwezi kusoma taarifa hiyo kwa sasa.';}
        }
    }
    if(str_contains($text,'muhtasari') || str_contains($text,'summary') || str_contains($text,'hali ya biashara')){
        return 'Muhtasari: '.zan_business_ai_context($pdo).'.';
    }
    if(str_contains($text,'low stock') || str_contains($text,'stock chini') || str_contains($text,'bidhaa chini')){
        try{
            $q=$pdo->query("SELECT name,COALESCE(stock,0) stock,COALESCE(low_stock,0) low_stock FROM products WHERE COALESCE(stock,0)<=COALESCE(low_stock,0) ORDER BY name");
            $items=$q->fetchAll(PDO::FETCH_ASSOC);
            if(!$items)return 'Hakuna bidhaa yenye low stock kwa sasa. Bidhaa zote ziko juu ya reorder level.';
            $names=[];foreach($items as $item)$names[]=$item['name'].' (stock '.$item['stock'].', kiwango cha chini '.$item['low_stock'].')';
            return 'Bidhaa zenye low stock ni '.count($items).': '.implode(', ',$names).'.';
        }catch(Throwable $e){return 'Siwezi kusoma taarifa za stock kwa sasa.';}
    }
    if(!str_contains($text,'invoice') && !str_contains($text,'ankara'))return null;
    if(str_contains($text,'taka') || str_contains($text,'tengeneza') || str_contains($text,'create') || str_contains($text,'new')){
        return 'Sawa. Tumia kitufe cha "Tengeneza Invoice" hapa chini kuanza invoice mpya. Chagua mteja, bidhaa, kiasi, kisha hifadhi.';
    }
    try{
        $total=(float)$pdo->query("SELECT COALESCE(SUM(COALESCE(grand_total,total,0)),0) FROM invoices")->fetchColumn();
        $paid=(float)$pdo->query("SELECT COALESCE(SUM(COALESCE(paid,0)),0) FROM invoices")->fetchColumn();
        $count=(int)$pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
        return 'Kwa sasa kuna '.$count.' invoice. Jumla ni '.number_format($total,2).' TZS, zimelipwa '.number_format($paid,2).' TZS, na salio ni '.number_format(max(0,$total-$paid),2).' TZS.';
    }catch(Throwable $e){return 'Nimepata invoice lakini siwezi kusoma muhtasari wake kwa sasa.';}
}
function zan_business_ai_no_api_answer(PDO $pdo,string $question): string{
    $text=strtolower(trim($question));
    $context=zan_business_ai_context($pdo);
    if(str_contains($text,'msaada') || str_contains($text,'help') || str_contains($text,'unaweza')){
        return 'Naweza kukusaidia na mauzo, invoices, payments, madeni ya wateja, products, stock, suppliers, expenses, quotations, proforma, users na reports. Uliza swali lako moja kwa moja.';
    }
    if(str_contains($text,'jina') || str_contains($text,'majina') || str_contains($text,'nani') || str_contains($text,'nitajie')){
        return 'Nimeangalia taarifa za sasa za mfumo. Ili nikutajie majina kwa usahihi, taja eneo unalotaka: wateja, bidhaa, suppliers, users, mafundi, sites au tools.';
    }
    return 'Nimepokea swali lako: "'.$question.'". Sina API ya majibu ya lugha iliyounganishwa kwa sasa, lakini taarifa hai za mfumo nilizonazo ni: '.$context.'. Taja module unayotaka (wateja, bidhaa, stock, invoice, expenses, users, sites au reports) ili nikujibu moja kwa moja.';
}
function st_ai_remote_answer(string $question,string $context,bool $siteTracking=false): ?string{
    $key=getenv('ZANTRONIX_AI_API_KEY')?:'';if($key===''||!function_exists('curl_init'))return null;
    $model=getenv('ZANTRONIX_AI_MODEL')?:'gpt-4o-mini';
    $systemPrompt=$siteTracking?'You are the official Zantronix Site Tracking AI Assistant. Answer in the same language as the user, especially Swahili. Teach the whole Site Tracking system: dashboard, sites, site leaders and multi-technician teams, technician/driver accounts, assignments, tasks and statuses NEW/ACCEPTED/IN PROGRESS/COMPLETED/VERIFIED plus BLOCKED, transport trips, vehicles, drivers, transport requests, tools registration and movements, issued/returned/damaged/missing tools, GPS/photos/evidence, daily updates, approvals, reports, notifications, administration, permissions and Super Admin. Explain exact menu and workflow. Use live context only for live figures/records. Never reveal passwords, password hashes or credentials.':'You are the official Zantronix Business AI Assistant. Answer every user question helpfully in the same language used by the user. Focus only on business operations: company settings, customers, products, stock, suppliers, quotations, proformas, invoices, payments, debts, expenses, users and reports. Use only the supplied live business context; never invent figures or records. If the requested detail is absent, say so clearly and suggest the exact module or next action. Keep answers concise, practical and professional.';
    $payload=json_encode(['model'=>$model,'temperature'=>0.2,'max_tokens'=>700,'messages'=>[
        ['role'=>'system','content'=>$systemPrompt],
        ['role'=>'user','content'=>'Business context:\n'.$context.'\n\nQuestion:\n'.$question]
    ]],JSON_UNESCAPED_UNICODE);
    $ch=curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$key],CURLOPT_POSTFIELDS=>$payload]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($raw===false||$status<200||$status>=300)return null;
    $data=json_decode($raw,true);$answer=$data['choices'][0]['message']['content']??'';return trim((string)$answer)?:null;
}
function st_e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function st_photo($path,$class='st-photo'){
    if(!$path)return '';
    return '<a href="'.st_e($path).'" target="_blank"><img class="'.st_e($class).'" src="'.st_e($path).'" alt="Photo"></a>';
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

if(isset($_SESSION['user']) && ($_SERVER['REQUEST_METHOD']??'')==='POST'
    && ($_POST['business_ai_action']??'')==='ask'){
     try{
          if(!st_has_business_permission())throw new Exception('Huna ruhusa ya kutumia Business AI Assistant.');
          if(!hash_equals(st_csrf_token(),(string)($_POST['business_ai_csrf']??'')))throw new Exception('Session ime-expire. Refresh ukurasa ujaribu tena.');
          $question=trim((string)($_POST['question']??''));
          if($question==='')throw new Exception('Andika swali kwanza.');
          $userName=$_SESSION['user']['name']??$_SESSION['user']['username']??'User';
          $history=[];foreach(array_slice($_SESSION['business_ai_chat']??[],-8) as $chat)$history[]='User: '.$chat['question'].' | AI: '.$chat['answer'];
          $context='Current user: '.$userName.'. Recent chat: '.implode(' || ',$history).'. Business facts: '.zan_business_ai_context($pdo);
        $answer=zan_business_ai_local_answer($pdo,$question);
        if($answer===null)$answer=st_ai_remote_answer($question,$context);
        if($answer===null)$answer=zan_business_ai_no_api_answer($pdo,$question);
          $_SESSION['business_ai_chat'][]=['question'=>$question,'answer'=>$answer,'created_at'=>date('Y-m-d H:i:s')];
          $_SESSION['business_ai_chat']=array_slice($_SESSION['business_ai_chat'],-20);
     }catch(Throwable $e){$_SESSION['business_ai_error']=$e->getMessage();}
     header('Location:index.php?page=ai_assistant');exit;
}

/* =========================================================
    SITE TRACKING BACKUP
    ========================================================= */
if(isset($_SESSION['user']) && $page==='st_settings' && isset($_GET['st_backup']) && (string)$_GET['st_backup']==='1') {
    if(!st_is_admin()) exit('Huna ruhusa ya kufanya backup.');
    try {
        $zipFile=zan_create_local_backup('Site_Tracking_Backup');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.basename($zipFile).'"');
        header('Content-Length: '.filesize($zipFile));
        readfile($zipFile);
        exit;
    } catch(Throwable $e) {
        exit('Backup error: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'));
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
    $transportActionWithoutToken=false;
    if(!$transportActionWithoutToken&&!hash_equals(st_csrf_token(),(string)($_POST['st_csrf']??''))){
        if($a==='st_ai_ask')$_SESSION['st_ai_question']=trim((string)($_POST['question']??''));
        $_SESSION['st_csrf_token']=bin2hex(random_bytes(32));
        st_flash('Session refreshed. Bonyeza Tuma tena kwa swali lako.');
        st_redirect($a==='st_ai_ask'?'st_ai_assistant':(st_is_technician()?'st_portal':'site_tracking'));
    }
        if($a==='st_change_own_password'){
            $currentPassword=(string)($_POST['current_password']??'');
            $newPassword=(string)($_POST['new_password']??'');
            $confirmPassword=(string)($_POST['confirm_password']??'');
            if($currentPassword===''||$newPassword===''||$newPassword!==$confirmPassword||strlen($newPassword)<6)throw new Exception('Password mpya lazima iwe na herufi 6 au zaidi na zifanane.');
            $q=$pdo->prepare('SELECT password FROM users WHERE id=? AND active=1 LIMIT 1');$q->execute([(int)$_SESSION['user']['id']]);$hash=$q->fetchColumn();
            if(!$hash||!zan_password_verify($currentPassword,$hash))throw new Exception('Password ya sasa si sahihi.');
            $update=$pdo->prepare('UPDATE users SET password=? WHERE id=? AND active=1');
            $update->execute([zan_password_hash($newPassword),(int)$_SESSION['user']['id']]);
            if($update->rowCount()!==1)throw new Exception('Password haikuhifadhiwa. Jaribu tena.');
            st_flash('Password imebadilishwa.');st_redirect('st_portal');
        }
        if($a==='st_save_settings'){
            if(!st_is_admin())throw new Exception('Only Site Tracking administrators can change settings.');
            $timeout=(int)($_POST['timeout_minutes']??30);
            if(!in_array($timeout,[15,30,60,120],true))$timeout=30;
            $_SESSION['st_timeout_minutes']=$timeout;
            $_SESSION['st_last_activity']=time();
            st_flash('Site Tracking settings zimehifadhiwa.');st_redirect('st_settings');
        }
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
                        ->execute([$username,zan_password_hash($password),$full,trim($_POST['phone']??''),'Technician',$perms,'site']);
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
                    $activeUser=(($_POST['status']??'Active')==='Active'?1:0);
                    if($password!=='') $pdo->prepare("UPDATE users SET username=?,name=?,phone=?,password=?,active=?,role='Technician',permissions=?,account_system='site' WHERE id=?")->execute([$username,$full,trim($_POST['phone']??''),zan_password_hash($password),$activeUser,json_encode(['site_tracking_system'=>true]),$old['user_id']]);
                    else $pdo->prepare("UPDATE users SET username=?,name=?,phone=?,active=?,role='Technician',permissions=?,account_system='site' WHERE id=?")->execute([$username,$full,trim($_POST['phone']??''),$activeUser,json_encode(['site_tracking_system'=>true]),$old['user_id']]);
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

            $oldTeamIds=st_site_team_ids($pdo,$siteId);
            $oldTechnicianId=(int)($site['technician_id']??0);
            $otherTechnicians=array_values(array_filter(
                $oldTeamIds,
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
                st_queue_whatsapp_notification($pdo,$oldTechnicianId,'site_reassignment','Site '.$site['site_name'].' imehamishiwa fundi mwingine; haipo tena kwenye Assigned Sites zako.');
            foreach($oldTeamIds as $removedId){$removedId=(int)$removedId;if($removedId>0&&!in_array($removedId,st_site_team_ids($pdo,$siteId),true))st_queue_whatsapp_notification($pdo,$removedId,'site_removed','Umeondolewa kwenye site '.$site['site_name'].' na Administrator. Site hii sasa haionekani tena kwenye portal yako.');}
            st_flash($oldTechnicianId&&$oldTechnicianId!==$technicianId?'Site reassigned successfully.':'Site assigned successfully.');
            st_redirect('st_sites');
        }

        if($a==='st_update_site_team'){
            if(!st_is_manager()&&!st_is_admin()) throw new Exception('Only Manager or Administrator can update site team.');
            $siteId=(int)($_POST['site_id']??0);$primaryId=(int)($_POST['primary_id']??0);$others=$_POST['team_technicians']??[];
            if($siteId<=0)throw new Exception('Site is required.');
            $q=$pdo->prepare('SELECT site_name FROM st_sites WHERE id=? LIMIT 1');$q->execute([$siteId]);$site=$q->fetch(PDO::FETCH_ASSOC);if(!$site)throw new Exception('Site not found.');
            $q=$pdo->prepare('SELECT site_name FROM st_sites WHERE id=? LIMIT 1');$q->execute([$siteId]);$siteTeam=$q->fetch(PDO::FETCH_ASSOC);if(!$siteTeam)throw new Exception('Site not found.');
            $beforeTeam=st_site_team_ids($pdo,$siteId);
            $primary=$primaryId>0?null:null;
            if($primaryId>0){$q=$pdo->prepare("SELECT id,full_name FROM st_technicians WHERE id=? AND status='Active' LIMIT 1");$q->execute([$primaryId]);$primary=$q->fetch(PDO::FETCH_ASSOC);if(!$primary)throw new Exception('Primary technician is not active.');}
            $newTeam=array_values(array_unique(array_filter(array_merge($primaryId>0?[$primaryId]:[],array_map('intval',(array)$others)))));
            foreach($newTeam as $teamId){$q=$pdo->prepare("SELECT id FROM st_technicians WHERE id=? AND status='Active' LIMIT 1");$q->execute([$teamId]);if(!$q->fetch())throw new Exception('One selected technician is not active.');}
            st_save_site_team($pdo,$siteId,$primaryId,$newTeam);
            $afterTeam=st_site_team_ids($pdo,$siteId);
            foreach($beforeTeam as $removedId){if(!in_array((int)$removedId,$afterTeam,true))st_queue_whatsapp_notification($pdo,(int)$removedId,'site_removed','Umeondolewa kwenye site '.$siteTeam['site_name'].' na Administrator. Site hii sasa haionekani tena kwenye portal yako.');}
            foreach($afterTeam as $addedId){if(!in_array((int)$addedId,$beforeTeam,true))st_queue_whatsapp_notification($pdo,(int)$addedId,'site_added','Umeongezwa kwenye site '.$siteTeam['site_name'].' na Administrator. Angalia Assigned Sites kwenye portal yako.');}
            st_audit($pdo,'site',$siteId,'SITE TEAM UPDATED','Primary: '.($primary['full_name']??'Hakuna').' | Team members: '.implode(',',array_map('intval',$afterTeam)));
            st_flash('Site team updated successfully.');st_redirect('st_sites');
        }

        if($a==='st_save_site' || $a==='st_edit_site' || $a==='st_edit_site_save'){
            if(!st_is_admin()) throw new Exception('Only Administrator can save/edit sites.');
            $id=(int)($_POST['id']??0);
            $site=trim($_POST['site_name']??'');
            $customer=trim($_POST['customer_name']??'');
            $location=trim($_POST['location']??'');
            $workDate=trim($_POST['start_date']??'');
            $introducedBy=trim($_POST['introduced_by']??'');
            if($site==='' || $customer==='' || $location==='' || $workDate==='' || $introducedBy==='')
                throw new Exception('Site, customer, location, siku ya kufanyia kazi na aliyeleta site vinahitajika.');

            if($id){
                $q=$pdo->prepare("SELECT id FROM st_sites WHERE id=? LIMIT 1");$q->execute([$id]);
                if(!$q->fetch()) throw new Exception('Site not found.');
                $pdo->prepare("UPDATE st_sites SET site_name=?,customer_name=?,location=?,start_date=?,introduced_by=? WHERE id=?")
                    ->execute([$site,$customer,$location,$workDate,$introducedBy,$id]);
                st_audit($pdo,'site',$id,'EDITED');
                st_flash('Site updated successfully.');
            }else{
                $pdo->prepare("INSERT INTO st_sites(site_name,customer_name,location,start_date,introduced_by,status) VALUES(?,?,?,?,?,?)")
                    ->execute([$site,$customer,$location,$workDate,$introducedBy,'Pending']);
                $id=(int)$pdo->lastInsertId();
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

        if($a==='st_create_assignment'){
            if(!st_is_admin()&&!st_is_manager())throw new Exception('Only Manager or Administrator can create assignments.');
            $siteId=(int)($_POST['site_id']??0);$techId=(int)($_POST['technician_id']??0);$driverId=(int)($_POST['driver_id']??0);$vehicleId=(int)($_POST['vehicle_id']??0);
            $title=trim($_POST['job_title']??'');$purpose=trim($_POST['purpose']??'');$destination=trim($_POST['destination']??'');$priority=trim($_POST['priority']??'Normal');$dueAt=trim($_POST['due_at']??'');$notes=trim($_POST['notes']??'');$materialsText=trim($_POST['materials_text']??'');
            if($siteId<=0||$techId<=0||$vehicleId<=0||$title==='')throw new Exception('Site, Job title, Fundi na Gari/Pikipiki ni lazima. Dereva ni optional.');
            $q=$pdo->prepare('SELECT id,site_name FROM st_sites WHERE id=? LIMIT 1');$q->execute([$siteId]);$site=$q->fetch(PDO::FETCH_ASSOC);if(!$site)throw new Exception('Site haijapatikana.');
            $q=$pdo->prepare("SELECT id,full_name FROM st_technicians WHERE id=? AND status='Active' LIMIT 1");$q->execute([$techId]);$tech=$q->fetch(PDO::FETCH_ASSOC);if(!$tech)throw new Exception('Fundi hayupo au hayuko Active.');
            $driver=null; if($driverId>0){$q=$pdo->prepare("SELECT id,full_name FROM st_drivers WHERE id=? AND status='Active' LIMIT 1");$q->execute([$driverId]);$driver=$q->fetch(PDO::FETCH_ASSOC);if(!$driver)throw new Exception('Dereva hayupo au hayuko Active.');} else {$driverId=0;}
            $q=$pdo->prepare("SELECT id,registration_no,vehicle_type,make_model FROM st_vehicles WHERE id=? AND COALESCE(NULLIF(status,''),'Available') NOT IN ('On Trip','Inactive') LIMIT 1");$q->execute([$vehicleId]);$vehicle=$q->fetch(PDO::FETCH_ASSOC);if(!$vehicle)throw new Exception('Gari/Pikipiki haipatikani; chagua Available.');
            $q=$pdo->prepare("SELECT COUNT(*) FROM st_site_tasks WHERE assigned_to_type='technician' AND assigned_to=? AND status IN ('New','Accepted','In Progress','Blocked')");$q->execute([$techId]);if((int)$q->fetchColumn()>0)throw new Exception('Fundi ana task ambayo bado haijaisha.');
            if($driverId>0){$q=$pdo->prepare("SELECT COUNT(*) FROM st_site_tasks WHERE assigned_to_type='driver' AND assigned_to=? AND status IN ('New','Accepted','In Progress','Blocked')");$q->execute([$driverId]);if((int)$q->fetchColumn()>0)throw new Exception('Dereva ana task ambayo bado haijaisha.');}
            $toolIds=array_values(array_unique(array_filter(array_map('intval',$_POST['tool_ids']??[]))));
            foreach($toolIds as $toolId){$q=$pdo->prepare("SELECT tool_name,status FROM st_tools WHERE id=? LIMIT 1");$q->execute([$toolId]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r)throw new Exception('Tool haipatikani.');if(strtolower((string)$r['status'])!=='available')throw new Exception('Tool '.$r['tool_name'].' haipo Available.');}

            $pdo->beginTransaction();
            try{
                $taskNote='JOB: '.$title.' | '.$notes.($materialsText!==''?' | MATERIALS: '.$materialsText:'');
                $pdo->prepare("INSERT INTO st_site_tasks(site_id,task_title,notes,status,assigned_to,assigned_to_type,task_type,priority,due_at) VALUES(?,?,?,?,?,?,?,?,?)")->execute([$siteId,$title,$taskNote,'New',$techId,'technician','site',$priority,$dueAt]);$techTask=(int)$pdo->lastInsertId();
                if($driverId>0){$pdo->prepare("INSERT INTO st_site_tasks(site_id,task_title,notes,status,assigned_to,assigned_to_type,task_type,priority,due_at) VALUES(?,?,?,?,?,?,?,?,?)")->execute([$siteId,'Transport: '.$title,$taskNote,'New',$driverId,'driver','transport',$priority,$dueAt]);$driverTask=(int)$pdo->lastInsertId();}
                $pdo->prepare("INSERT INTO st_transport_trips(vehicle_id,driver_id,site_id,destination,purpose,departure_at,status,notes,created_by) VALUES(?,?,?,?,?,CURRENT_TIMESTAMP,'Open',?,?)")->execute([$vehicleId,$driverId,$siteId,$destination?:$site['site_name'],$purpose?:$title,$notes,st_current_user_name()]);$tripId=(int)$pdo->lastInsertId();
                $pdo->prepare('INSERT OR IGNORE INTO st_transport_trip_technicians(trip_id,technician_id) VALUES(?,?)')->execute([$tripId,$techId]);
                $pdo->prepare("UPDATE st_vehicles SET status='On Trip' WHERE id=?")->execute([$vehicleId]);
                $pdo->prepare('INSERT INTO st_assignment_jobs(site_id,job_title,technician_id,driver_id,vehicle_id,technician_task_id,driver_task_id,trip_id,priority,due_at,notes,materials_text,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$siteId,$title,$techId,$driverId,$vehicleId,$techTask,$driverTask,$tripId,$priority,$dueAt,$notes,$materialsText,'Assigned',st_current_user_name()]);$jobId=(int)$pdo->lastInsertId();
                foreach($toolIds as $toolId){$pdo->prepare("INSERT INTO st_tool_movements(tool_id,technician_id,site_id,action_type,due_date,condition_status,notes,technician_confirmed,manager_approved,technical_approved,status,created_by,manager_by,manager_at,manager_comment) VALUES(?,?,?,'Issued',?,'Good',?,0,1,1,'Approved',?,?,CURRENT_TIMESTAMP,?)")->execute([$toolId,$techId,$siteId,$dueAt?:null,'Assigned via JOB #'.$jobId.' - '.$title,$created,$created,'Assignment Center']);$pdo->prepare("UPDATE st_tools SET status='Issued',condition_status='Good' WHERE id=?")->execute([$toolId]);}
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            st_queue_task_assignment_notification($pdo,'technician',$techId,$title,$site['site_name']);if($driverId>0)st_queue_task_assignment_notification($pdo,'driver',$driverId,'Transport: '.$title,$site['site_name']);
            st_audit($pdo,'assignment_job',$jobId,'ASSIGNED','Site: '.$site['site_name'].' | Fundi: '.$tech['full_name'].' | Dereva: '.($driver['full_name']??'Hakuna'). ' | Gari: '.$vehicle['registration_no'].' | Trip: '.$tripId);
            st_flash('Assignment imekamilika: Fundi + Dereva + Gari + Trip + Tasks zimeunganishwa.');st_redirect('st_assignment_center');
        }

        if($a==='st_save_site_task'){
            if(!st_is_admin()&&!st_is_manager())throw new Exception('Only Manager or Administrator can create site tasks.');
            $siteId=(int)($_POST['site_id']??0);
            $title=trim($_POST['task_title']??'');
            $notes=trim($_POST['notes']??'');
            $assignedType=trim($_POST['assigned_to_type']??'technician');
            if(!in_array($assignedType,['technician','driver'],true)){$assignedType='technician';}
            $assignedRaw=trim($_POST['assigned_to']??'');
            $assigned=(int)$assignedRaw;
            if(strpos($assignedRaw,'t_')===0){$assigned=(int)substr($assignedRaw,2);$assignedType='technician';}
            if(strpos($assignedRaw,'d_')===0){$assigned=(int)substr($assignedRaw,2);$assignedType='driver';}
            $taskType=trim($_POST['task_type']??'site');
            $priority=trim($_POST['priority']??'Normal');
            $dueAt=trim($_POST['due_at']??'');
            if($siteId<=0||$title==='')throw new Exception('Site and task title are required.');
            $q=$pdo->prepare('SELECT id,site_name FROM st_sites WHERE id=? LIMIT 1');$q->execute([$siteId]);$site=$q->fetch(PDO::FETCH_ASSOC);if(!$site)throw new Exception('Site not found.');
            if($assigned>0){
                if($assignedType==='driver'){
                    $q=$pdo->prepare('SELECT id FROM st_drivers WHERE id=? AND status=\'Active\' LIMIT 1');$q->execute([$assigned]);
                    if(!$q->fetch())throw new Exception('Assigned driver was not found.');
                }else{
                    $q=$pdo->prepare('SELECT id FROM st_technicians WHERE id=? AND status=\'Active\' LIMIT 1');$q->execute([$assigned]);
                    if(!$q->fetch())throw new Exception('Assigned technician was not found.');
                }
            }
            $pdo->prepare("INSERT INTO st_site_tasks(site_id,task_title,notes,status,assigned_to,assigned_to_type,task_type,priority,due_at) VALUES(?,?,?,?,?,?,?,?,?)")
                ->execute([$siteId,$title,$notes,'New',$assigned?:null,$assignedType,$taskType,$priority,$dueAt]);
            $taskId=(int)$pdo->lastInsertId();
            if($assigned>0){
                $recipientType=$assignedType==='driver'?'driver':'technician';
                st_queue_task_assignment_notification($pdo,$recipientType,$assigned,$title,$site['site_name']);
            }
            st_audit($pdo,'site_task',$taskId,'CREATED','Site: '.$siteId.' | Type: '.$taskType.' | Assignee: '.$assignedType.' | Priority: '.$priority);st_flash('Site task created.');st_redirect('st_tasks');
        }

        if($a==='st_request_team_change'){
            if(!st_is_technician())throw new Exception('Only Site Leader can request a team change.');
            $ct=st_current_technician($pdo);$siteId=(int)($_POST['site_id']??0);$technicianId=(int)($_POST['technician_id']??0);$reason=trim($_POST['reason']??'');
            if(!$ct||$siteId<=0||$technicianId<=0||$reason==='')throw new Exception('Site, technician and reason are required.');
            $q=$pdo->prepare('SELECT site_name,technician_id FROM st_sites WHERE id=? LIMIT 1');$q->execute([$siteId]);$site=$q->fetch(PDO::FETCH_ASSOC);
            if(!$site||(int)$site['technician_id']!==(int)$ct['id'])throw new Exception('Only the Site Leader can request a team change.');
            if($technicianId===(int)$ct['id'])throw new Exception('Site Leader cannot remove himself.');
            $q=$pdo->prepare('SELECT 1 FROM st_site_technicians WHERE site_id=? AND technician_id=? LIMIT 1');$q->execute([$siteId,$technicianId]);if(!$q->fetch())throw new Exception('Technician is not assigned to this site.');
            $q=$pdo->prepare("SELECT 1 FROM st_team_change_requests WHERE site_id=? AND technician_id=? AND status='Pending' LIMIT 1");$q->execute([$siteId,$technicianId]);if($q->fetch())throw new Exception('There is already a pending request for this technician.');
            $pdo->prepare('INSERT INTO st_team_change_requests(site_id,requester_id,technician_id,reason) VALUES(?,?,?,?)')->execute([$siteId,(int)$ct['id'],$technicianId,$reason]);
            st_flash('Request ya kubadilisha team imetumwa kwa Manager.');st_redirect('st_portal');
        }

        if($a==='st_review_team_change'){
            if(!st_is_manager()&&!st_is_admin())throw new Exception('Only Manager or Administrator can review team requests.');
            $requestId=(int)($_POST['request_id']??0);$decision=$_POST['decision']??'';
            $q=$pdo->prepare("SELECT * FROM st_team_change_requests WHERE id=? AND status='Pending' LIMIT 1");$q->execute([$requestId]);$request=$q->fetch(PDO::FETCH_ASSOC);if(!$request)throw new Exception('Team request not found.');
            if($decision==='approve'){
                $pdo->prepare('DELETE FROM st_site_technicians WHERE site_id=? AND technician_id=?')->execute([(int)$request['site_id'],(int)$request['technician_id']]);
                $status='Approved';
            }elseif($decision==='reject'){$status='Rejected';}else throw new Exception('Invalid team request decision.');
            $pdo->prepare('UPDATE st_team_change_requests SET status=?,reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$status,st_current_user_name(),$requestId]);
            st_audit($pdo,'team_change_request',$requestId,'TEAM CHANGE '.$status,'Site: '.$request['site_id'].' | Technician: '.$request['technician_id']);st_flash('Team request '.$status.'.');st_redirect('st_sites');
        }

        if($a==='st_update_site_task' || $a==='st_toggle_site_task'){
            $taskId=(int)($_POST['id']??0);$q=$pdo->prepare('SELECT * FROM st_site_tasks WHERE id=? LIMIT 1');$q->execute([$taskId]);$task=$q->fetch(PDO::FETCH_ASSOC);if(!$task)throw new Exception('Task not found.');
            $taskType=strtolower((string)($task['assigned_to_type']??'technician'));$assignedId=(int)($task['assigned_to']??0);$isAssignee=false;
            if($taskType==='driver'&&st_is_driver()){$driver=st_current_driver($pdo);$isAssignee=$driver&&(int)$driver['id']===$assignedId;}elseif($taskType!=='driver'&&st_is_technician()){$tech=st_current_technician($pdo);$isAssignee=$tech&&(int)$tech['id']===$assignedId;}
            $action=trim((string)($_POST['task_action']??''));if($a==='st_toggle_site_task'&&$action==='')$action=((string)$task['status']==='Completed')?'reopen':'complete';
            $status=(string)($task['status']??'Pending');if($status==='Pending')$status='New';$user=st_current_user_name();$note=trim((string)($_POST['update_notes']??''));$reason=trim((string)($_POST['blocked_reason']??''));
            if($action==='verify'){if(!st_is_admin()&&!st_is_manager())throw new Exception('Only Manager or Administrator can verify a completed task.');if($status!=='Completed')throw new Exception('Only a Completed task can be Verified.');$pdo->prepare("UPDATE st_site_tasks SET status='Verified',task_update_notes=?,completed_by=COALESCE(completed_by,?),completed_at=COALESCE(completed_at,CURRENT_TIMESTAMP) WHERE id=?")->execute([$note,$user,$taskId]);st_audit($pdo,'site_task',$taskId,'VERIFIED',$note);st_flash('Task verified successfully.');st_redirect('st_tasks');}
            if($action==='reopen'){if(!st_is_admin()&&!st_is_manager())throw new Exception('Only Manager or Administrator can reopen a task.');$pdo->prepare("UPDATE st_site_tasks SET status='Accepted',blocked_reason='',task_update_notes=?,completed_by=NULL,completed_at=NULL WHERE id=?")->execute([$note,$taskId]);st_audit($pdo,'site_task',$taskId,'REOPENED',$note);st_flash('Task reopened.');st_redirect('st_tasks');}
            if(!$isAssignee)throw new Exception('Task is not assigned to your portal account.');if(!$assignedId)throw new Exception('This task has no assigned driver/technician.');
            if($action==='accept'){if($status!=='New')throw new Exception('Only a new task can be accepted.');$pdo->prepare("UPDATE st_site_tasks SET status='Accepted',accepted_at=CURRENT_TIMESTAMP,task_update_notes=? WHERE id=?")->execute([$note,$taskId]);st_notify_site_admins($pdo,'task_accepted','Task accepted: '.$task['task_title'].' | Site ID: '.$task['site_id'].' | By: '.$user);}
            elseif($action==='start'){if($status!=='Accepted')throw new Exception('Accept the task before starting it.');$pdo->prepare("UPDATE st_site_tasks SET status='In Progress',started_at=CURRENT_TIMESTAMP,task_update_notes=? WHERE id=?")->execute([$note,$taskId]);st_notify_site_admins($pdo,'task_started','Task started: '.$task['task_title'].' | Site ID: '.$task['site_id'].' | By: '.$user);}
            elseif($action==='block'){if(in_array($status,['Completed','Verified'],true))throw new Exception('Completed task cannot be blocked.');if($reason==='')throw new Exception('Reason for Blocked status is required.');$pdo->prepare("UPDATE st_site_tasks SET status='Blocked',blocked_at=CURRENT_TIMESTAMP,blocked_reason=?,task_update_notes=? WHERE id=?")->execute([$reason,$note,$taskId]);st_notify_site_admins($pdo,'task_blocked','Task BLOCKED: '.$task['task_title'].' | Site ID: '.$task['site_id'].' | Reason: '.$reason);}
            elseif($action==='complete'){if(!in_array($status,['Accepted','In Progress','Blocked'],true))throw new Exception('Task must be accepted or in progress before completion.');$pdo->prepare("UPDATE st_site_tasks SET status='Completed',completed_by=?,completed_at=CURRENT_TIMESTAMP,task_update_notes=?,blocked_reason='' WHERE id=?")->execute([$user,$note,$taskId]);st_notify_site_admins($pdo,'task_completed','Task completed: '.$task['task_title'].' | Site ID: '.$task['site_id'].' | By: '.$user);}
            else throw new Exception('Invalid task action.');
            st_audit($pdo,'site_task',$taskId,strtoupper($action),$reason!==''?'Blocked: '.$reason:$note);st_flash('Task updated successfully.');st_redirect(st_is_driver()?'st_driver_portal':(st_is_technician()?'st_portal':'st_tasks'));
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
                if(in_array($data[4],['Damaged','Missing'],true))$pdo->prepare('UPDATE st_tools SET status=? WHERE id=?')->execute([$data[4],$id]);
                st_audit($pdo,'tool',$id,'EDITED');st_flash('Tool updated.');
            }else{
                $initialStatus=in_array($data[4],['Damaged','Missing'],true)?$data[4]:'Available';
                $pdo->prepare("INSERT INTO st_tools(tool_code,tool_name,serial_no,quantity,condition_status,status,notes,photo,purchase_date,value_amount,storage_location,registration_notes)
                               VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([
                        $data[0],$data[1],$data[2],$data[3],$data[4],$initialStatus,$data[6],$photo,
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
            // GPS is optional; save it when available.
            if($siteId){$q=$pdo->prepare("SELECT 1 FROM st_site_technicians WHERE site_id=? AND technician_id=? LIMIT 1");$q->execute([$siteId,$tid]);if(!$q->fetch())throw new Exception('Technician is not assigned to the selected site.');}
            if($type==='Issued'){$q=$pdo->prepare("SELECT status FROM st_tools WHERE id=? LIMIT 1");$q->execute([$toolId]);$ts=$q->fetchColumn();if(!$ts)throw new Exception('Tool not found.');if(strtolower((string)$ts)!=='available')throw new Exception('Tool is not available. Current status: '.$ts);}else{$q=$pdo->prepare("SELECT 1 FROM st_tool_movements WHERE tool_id=? AND technician_id=? AND action_type='Issued' AND COALESCE(status,'') NOT IN ('Rejected','Returned') ORDER BY id DESC LIMIT 1");$q->execute([$toolId,$tid]);if(!$q->fetch())throw new Exception('Tool is not currently issued to this technician.');}
            $pdo->prepare("INSERT INTO st_tool_movements(tool_id,technician_id,site_id,customer_name,action_type,due_date,condition_status,photo,return_photo,notes,status,created_by,latitude,longitude,accuracy,captured_at) VALUES(?,?,?,?,?,?,?,?,?,'Submitted',?,?,?,?,?,?)")
                ->execute([$toolId,$tid,$siteId?:null,trim($_POST['customer_name']??''),$type,$_POST['due_date']??null,$_POST['condition_status']??'Good',$photo,($type==='Returned'?$photo:null),trim($_POST['notes']??''),$created,$lat,$lng,$acc,trim($_POST['captured_at']??'')?:null]);
            if($type==='Issued')$pdo->prepare("UPDATE st_tools SET status='Issued',condition_status='Good' WHERE id=?")->execute([$toolId]);
            if($type==='Returned'){$returnCondition=$_POST['condition_status']??'Good';$returnStatus=in_array($returnCondition,['Damaged','Missing'],true)?$returnCondition:'Available';$pdo->prepare('UPDATE st_tools SET status=?,condition_status=? WHERE id=?')->execute([$returnStatus,$returnCondition,$toolId]);}
            if($type==='Issued'){
                $q=$pdo->prepare('SELECT tool_name FROM st_tools WHERE id=?');$q->execute([$toolId]);$toolName=(string)$q->fetchColumn();
                st_queue_whatsapp_notification($pdo,$tid,'tool_issued','Umepewa tool '.$toolName.' na Manager.');
            }
            st_flash($type.' record submitted with GPS.');st_redirect(st_is_technician()?'st_portal':'st_movements');
        }

        /* ---------- MATERIAL ISSUE BY MANAGER / ADMIN ---------- */
        if($a==='st_issue_material'){
            if(!st_is_manager()&&!st_is_admin())throw new Exception('Only Manager or Administrator can issue material.');
            $mid=(int)($_POST['material_id']??0);$sid=(int)($_POST['site_id']??0);$tid=(int)($_POST['technician_id']??0);$qty=(float)($_POST['quantity']??0);
            if($qty<=0||$mid<=0||$tid<=0||$sid<=0)throw new Exception('Material, quantity, technician and site are required.');
            $q=$pdo->prepare("SELECT material_name FROM st_materials WHERE id=? LIMIT 1");$q->execute([$mid]);if(!$q->fetchColumn())throw new Exception('Material not found.');
            $q=$pdo->prepare("SELECT status FROM st_sites WHERE id=? LIMIT 1");$q->execute([$sid]);$site=$q->fetch(PDO::FETCH_ASSOC);if(!$site)throw new Exception('Site not found.');if(strtolower((string)$site['status'])==='closed')throw new Exception('Closed site cannot receive material.');
            $q=$pdo->prepare("SELECT 1 FROM st_site_technicians WHERE site_id=? AND technician_id=? LIMIT 1");$q->execute([$sid,$tid]);if(!$q->fetch())throw new Exception('Selected technician is not assigned to this site.');
            $photo=st_upload_photo('photo');$lat=($_POST['latitude']??'')!==''?(float)$_POST['latitude']:null;$lng=($_POST['longitude']??'')!==''?(float)$_POST['longitude']:null;$acc=($_POST['accuracy']??'')!==''?(float)$_POST['accuracy']:null;
            $pdo->prepare("INSERT INTO st_material_movements(material_id,technician_id,site_id,customer_name,quantity,movement_type,photo,reason,technician_confirmed,manager_approved,technical_approved,status,created_by,manager_by,manager_at,manager_comment,latitude,longitude,accuracy,captured_at) VALUES(?,?,?,?,?,'Issued',?,?,0,1,1,'Approved',?,?,CURRENT_TIMESTAMP,?,?,?,?,?)")->execute([$mid,$tid,$sid,trim($_POST['customer_name']??''),$qty,$photo,trim($_POST['reason']??'Material issued by Manager'),$created,$created,trim($_POST['manager_comment']??''),$lat,$lng,$acc,trim($_POST['captured_at']??'')?:null]);$movementId=(int)$pdo->lastInsertId();
            $q=$pdo->prepare('SELECT material_name FROM st_materials WHERE id=?');$q->execute([$mid]);$materialName=(string)$q->fetchColumn();
            st_queue_whatsapp_notification($pdo,$tid,'material_issued','Umepewa material '.$materialName.' ('.$qty.') na Manager. Piga picha kuthibitisha umeipokea.');
            st_audit($pdo,'material_movement',$movementId,'MATERIAL ISSUED','Qty: '.$qty.' | Technician: '.$tid.' | Site: '.$sid);st_flash('Material issued to technician successfully.');st_redirect('st_movements');
        }

        if($a==='st_confirm_material_received'){
            if(!st_is_technician())throw new Exception('Only technicians can confirm received material.');
            $ct=st_current_technician($pdo);if(!$ct)throw new Exception('Technician account is not linked.');
            $movementId=(int)($_POST['movement_id']??0);$photo=st_upload_photo('received_photo');
            if($movementId<=0||!$photo)throw new Exception('Picha ya material iliyopokelewa inahitajika.');
            $q=$pdo->prepare("SELECT id,site_id,material_id,quantity FROM st_material_movements WHERE id=? AND technician_id=? AND movement_type='Issued' AND status='Approved' AND technician_confirmed=0 LIMIT 1");
            $q->execute([$movementId,(int)$ct['id']]);$movement=$q->fetch(PDO::FETCH_ASSOC);
            if(!$movement)throw new Exception('Material hii haipatikani au tayari imethibitishwa.');
            $update=$pdo->prepare('UPDATE st_material_movements SET received_photo=?,technician_confirmed=1 WHERE id=? AND technician_id=? AND technician_confirmed=0');
            $update->execute([$photo,$movementId,(int)$ct['id']]);
            if($update->rowCount()!==1)throw new Exception('Material haikuhifadhiwa kama imepokelewa.');
            st_audit($pdo,'material_movement',$movementId,'MATERIAL RECEIVED BY TECHNICIAN','Photo: '.$photo);
            st_flash('Material imethibitishwa kuwa imepokelewa kwa picha.');st_redirect('st_portal');
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

        if($a==='st_grant_money'){
            if(!st_is_manager()&&!st_is_admin())throw new Exception('Only Manager or Administrator can grant money.');
            $recipientType=trim($_POST['recipient_type']??'technician');$recipientId=(int)($_POST['recipient_id']??0);$sid=(int)($_POST['site_id']??0);$amount=(float)($_POST['amount']??0);$currency=trim($_POST['currency']??'TZS')?:'TZS';$purpose=trim($_POST['purpose']??'');
            if(!in_array($recipientType,['technician','driver'],true)||$recipientId<=0||$amount<=0||$purpose==='')throw new Exception('Recipient, amount and purpose are required.');
            $table=$recipientType==='driver'?'st_drivers':'st_technicians';$q=$pdo->prepare("SELECT full_name FROM $table WHERE id=? AND status='Active' LIMIT 1");$q->execute([$recipientId]);$recipient=$q->fetch(PDO::FETCH_ASSOC);if(!$recipient)throw new Exception('Recipient is not Active.');
            if($recipientType==='technician' && $sid>0){$q=$pdo->prepare('SELECT 1 FROM st_site_technicians WHERE site_id=? AND technician_id=? LIMIT 1');$q->execute([$sid,$recipientId]);if(!$q->fetch())throw new Exception('Fundi hajawekwa kwenye site hiyo.');}
            $site=['site_name'=>'Bila Site']; if($sid>0){$q=$pdo->prepare('SELECT site_name FROM st_sites WHERE id=? LIMIT 1');$q->execute([$sid]);$site=$q->fetch(PDO::FETCH_ASSOC);if(!$site)throw new Exception('Site not found.');}
            $pdo->prepare("INSERT INTO st_money_transactions(recipient_type,recipient_id,site_id,amount,currency,purpose,status,granted_by) VALUES(?,?,?,?,?,?,'Granted',?)")->execute([$recipientType,$recipientId,$sid,$amount,$currency,$purpose,st_current_user_name()]);$id=(int)$pdo->lastInsertId();st_audit($pdo,'money_transaction',$id,'MONEY GRANTED',ucfirst($recipientType).': '.$recipient['full_name'].' | '.$amount.' '.$currency.' | Site: '.$site['site_name']);st_queue_whatsapp_notification($pdo,$recipientId,'money_granted','Umepewa '.$amount.' '.$currency.' kwa '.$purpose.($sid>0?' ya site '.$site['site_name']:' bila kuhusisha site.').'.');st_flash('Money transfer imetumwa kwa '.$recipient['full_name'].'.');st_redirect('st_money_transfer');
        }
        if($a==='st_confirm_money_received'){
            if(!st_is_technician()&&!st_is_driver())throw new Exception('Only the recipient can confirm money received.');$recipientType=st_is_driver()?'driver':'technician';$recipient=st_is_driver()?st_current_driver($pdo):st_current_technician($pdo);if(!$recipient)throw new Exception('Account is not linked.');$id=(int)($_POST['money_id']??0);$q=$pdo->prepare("SELECT id FROM st_money_transactions WHERE id=? AND recipient_type=? AND recipient_id=? AND status='Granted' LIMIT 1");$q->execute([$id,$recipientType,(int)$recipient['id']]);if(!$q->fetchColumn())throw new Exception('Pesa hii haipo au tayari imethibitishwa.');$pdo->prepare("UPDATE st_money_transactions SET status='Received',received_by=?,received_at=CURRENT_TIMESTAMP WHERE id=?")->execute([st_current_user_name(),$id]);st_audit($pdo,'money_transaction',$id,'MONEY RECEIVED CONFIRMED','Confirmed by: '.st_current_user_name());st_flash('Umehakikisha umeipokea pesa. Sasa unaweza kutuma risiti.');st_redirect($recipientType==='driver'?'st_driver_portal':'st_portal');
        }

        if($a==='st_submit_money_receipt'){
            if(!st_is_technician()&&!st_is_driver())throw new Exception('Only assigned user can submit a money receipt.');$recipientType=st_is_driver()?'driver':'technician';$recipient=st_is_driver()?st_current_driver($pdo):st_current_technician($pdo);if(!$recipient)throw new Exception('Account is not linked.');$id=(int)($_POST['money_id']??0);$photo=st_upload_photo('receipt_photo');$notes=trim($_POST['receipt_notes']??'');$lat=($_POST['latitude']??'')!==''?(float)$_POST['latitude']:null;$lng=($_POST['longitude']??'')!==''?(float)$_POST['longitude']:null;$acc=($_POST['accuracy']??'')!==''?(float)$_POST['accuracy']:null;if($id<=0||!$photo)throw new Exception('Receipt photo is required.');$q=$pdo->prepare("SELECT * FROM st_money_transactions WHERE id=? AND recipient_type=? AND recipient_id=? AND status IN ('Granted','Received','Rejected') LIMIT 1");$q->execute([$id,$recipientType,(int)$recipient['id']]);$money=$q->fetch(PDO::FETCH_ASSOC);if(!$money)throw new Exception('Money transaction not found or already submitted.');$pdo->prepare("UPDATE st_money_transactions SET status='Receipt Submitted',receipt_photo=?,receipt_notes=?,receipt_submitted_at=CURRENT_TIMESTAMP,receipt_latitude=?,receipt_longitude=?,receipt_accuracy=? WHERE id=?")->execute([$photo,$notes,$lat,$lng,$acc,$id]);st_save_evidence_photos($pdo,'money_transaction',$id,'Money Receipt',st_upload_photos('receipt_photos'),st_current_user_name());st_notify_site_admins($pdo,'money_receipt','Money receipt submitted for transaction #'.$id.' by '.st_current_user_name());st_audit($pdo,'money_transaction',$id,'RECEIPT SUBMITTED','GPS: '.$lat.','.$lng);st_flash('Money receipt submitted for approval.');st_redirect($recipientType==='driver'?'st_driver_portal':'st_portal');
        }
        if($a==='st_review_money'){
            if(!st_is_manager()&&!st_is_admin())throw new Exception('Only Manager or Administrator can approve money.');$id=(int)($_POST['id']??0);$decision=trim($_POST['decision']??'');$reason=trim($_POST['reason']??'');if(!in_array($decision,['Approved','Rejected'],true))throw new Exception('Invalid money approval.');if($decision==='Rejected'&&$reason==='')throw new Exception('Rejection reason is required.');$q=$pdo->prepare("UPDATE st_money_transactions SET status=?,approved_by=?,approved_at=CURRENT_TIMESTAMP,rejection_reason=? WHERE id=? AND status='Receipt Submitted'");$q->execute([$decision,st_current_user_name(),$decision==='Rejected'?$reason:'',$id]);if($q->rowCount()!==1)throw new Exception('Money transaction is not awaiting approval.');st_audit($pdo,'money_transaction',$id,'MONEY '.$decision,$reason.' | Approved by: '.st_current_user_name());$q=$pdo->prepare("SELECT recipient_type,recipient_id FROM st_money_transactions WHERE id=?");$q->execute([$id]);$rr=$q->fetch(PDO::FETCH_ASSOC);if($rr)st_queue_task_assignment_notification($pdo,$rr['recipient_type'],(int)$rr['recipient_id'],'MONEY RECEIPT: '.$decision.' — '.st_current_user_name(),'Transaction #'.$id,'money_receipt_review');st_flash('Money transaction '.$decision.'.');st_redirect('st_approvals');
        }

        if($a==='st_submit_material_receipt'){
            if(!st_is_technician())throw new Exception('Only technicians can submit material receipts.');
            $ct=st_current_technician($pdo);if(!$ct)throw new Exception('Technician account is not linked.');
            $fundId=(int)($_POST['fund_id']??0);$notes=trim($_POST['receipt_notes']??'');$photo=st_upload_photo('receipt_photo');$extraReceiptPhotos=st_upload_photos('receipt_photos');
            if($fundId<=0||!$photo)throw new Exception('Receipt photo is required. Use the phone camera to capture it.');
            $lat=($_POST['latitude']??'')!==''?(float)$_POST['latitude']:null;$lng=($_POST['longitude']??'')!==''?(float)$_POST['longitude']:null;$acc=($_POST['accuracy']??'')!==''?(float)$_POST['accuracy']:null;
            // GPS is optional for receipts.
            $q=$pdo->prepare("SELECT id,site_id,amount,currency FROM st_material_funds WHERE id=? AND technician_id=? AND status='Granted' LIMIT 1");$q->execute([$fundId,(int)$ct['id']]);$fund=$q->fetch(PDO::FETCH_ASSOC);if(!$fund)throw new Exception('Material fund not found or already submitted.');
            $pdo->prepare("UPDATE st_material_funds SET status='Receipt Submitted',receipt_photo=?,receipt_notes=?,receipt_submitted_at=CURRENT_TIMESTAMP,receipt_latitude=?,receipt_longitude=?,receipt_accuracy=? WHERE id=? AND technician_id=? AND status='Granted'")
                ->execute([$photo,$notes,$lat,$lng,$acc,$fundId,(int)$ct['id']]);
            st_save_evidence_photos($pdo,'material_fund',$fundId,'Material Receipt',$extraReceiptPhotos,st_current_user_name());
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
            $requestId=(int)$pdo->lastInsertId();
            $q=$pdo->prepare($a==='st_request_tool'?'SELECT tool_name FROM st_tools WHERE id=?':'SELECT material_name FROM st_materials WHERE id=?');$q->execute([$itemId]);$itemName=(string)$q->fetchColumn();
            st_notify_site_admins($pdo,$a==='st_request_tool'?'tool_request':'material_request',$ct['full_name'].' ameomba '.($a==='st_request_tool'?'tool ':'material ').$itemName.' kwa site #'.$siteId.'. Sababu: '.$reason);
            st_audit($pdo,$a==='st_request_tool'?'tool_request':'material_request',$requestId,'SUBMITTED','Site: '.$siteId);st_flash('Request submitted to Manager.');st_redirect('st_portal');
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
                $pdo->prepare("INSERT INTO st_material_movements(material_id,technician_id,site_id,quantity,movement_type,reason,status,created_by) VALUES(?,?,?,?,'Issued',?,'Approved',?)")->execute([(int)$request['material_id'],(int)$request['technician_id'],(int)$request['site_id'],(float)$request['quantity'],'Approved from request #'.$id,$reviewer]);
            }
            $pdo->prepare("UPDATE $table SET status=?,reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$status,$reviewer,$id]);
            $reqType=$a==='st_review_tool_request'?'tool':'material'; $msg=$approve ? strtoupper($reqType).' REQUEST: ISSUE SOLVED — umeidhinishiwa '.($a==='st_review_tool_request'?'tool':'material').'.' : strtoupper($reqType).' REQUEST: imekataliwa.'; st_queue_task_assignment_notification($pdo,'technician',(int)$request['technician_id'],$msg,'Site #'.(int)$request['site_id'],'request_'.($approve?'approved':'rejected'));
            st_audit($pdo,$a==='st_review_tool_request'?'tool_request':'material_request', $id,strtoupper($status),$msg);st_flash('Request '.$status.'.');st_redirect('st_approvals');
        }

        /* ---------- MATERIAL USAGE BY TECHNICIAN ---------- */
        if($a==='st_report_material_usage'){
            if(!st_is_technician())throw new Exception('Only technician can report material usage.');$ct=st_current_technician($pdo);if(!$ct)throw new Exception('Technician account is not linked.');
            $mid=(int)($_POST['material_id']??0);$sid=(int)($_POST['site_id']??0);$qty=(float)($_POST['quantity']??0);if($mid<=0||$sid<=0||$qty<=0)throw new Exception('Material, site and used quantity are required.');
            $q=$pdo->prepare("SELECT 1 FROM st_site_technicians WHERE site_id=? AND technician_id=? LIMIT 1");$q->execute([$sid,(int)$ct['id']]);if(!$q->fetch())throw new Exception('You are not assigned to this site.');
            $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type='Issued' AND status='Approved' THEN quantity ELSE 0 END),0) issued,COALESCE(SUM(CASE WHEN movement_type='Used' AND status IN ('Submitted','Approved') THEN quantity ELSE 0 END),0) used FROM st_material_movements WHERE material_id=? AND technician_id=? AND site_id=?");$q->execute([$mid,(int)$ct['id'],$sid]);$bal=$q->fetch(PDO::FETCH_ASSOC);$remaining=(float)$bal['issued']-(float)$bal['used'];if($qty>$remaining+0.00001)throw new Exception('Used quantity exceeds material issued for this site. Remaining: '.$remaining);
            $photo=st_upload_photo('photo');if(!$photo)throw new Exception('Usage photo is required.');$lat=($_POST['latitude']??'')!==''?(float)$_POST['latitude']:null;$lng=($_POST['longitude']??'')!==''?(float)$_POST['longitude']:null;$acc=($_POST['accuracy']??'')!==''?(float)$_POST['accuracy']:null;// GPS is optional for material usage.
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

        if($a==='st_save_vehicle'){
            if(!st_is_admin())throw new Exception('Only Administrator can register company vehicles.');
            $id=(int)($_POST['id']??0);$plate=trim($_POST['registration_no']??'');$type=trim($_POST['vehicle_type']??'Car');
            if($plate==='')throw new Exception('Registration number is required.');
            $values=[$plate,$type,trim($_POST['make_model']??''),trim($_POST['ownership']??'Company'),trim($_POST['status']??'Available'),trim($_POST['insurance_expiry']??''),trim($_POST['service_due']??''),trim($_POST['notes']??'')];
            if($id>0){$values[]=$id;$pdo->prepare('UPDATE st_vehicles SET registration_no=?,vehicle_type=?,make_model=?,ownership=?,status=?,insurance_expiry=?,service_due=?,notes=? WHERE id=?')->execute($values);}
            else{$pdo->prepare('INSERT INTO st_vehicles(registration_no,vehicle_type,make_model,ownership,status,insurance_expiry,service_due,notes) VALUES(?,?,?,?,?,?,?,?)')->execute($values);$id=(int)$pdo->lastInsertId();}
            st_audit($pdo,'vehicle',$id,'VEHICLE SAVED',$plate);st_flash('Vehicle saved.');st_redirect('st_transport&section=vehicles');
        }
        if($a==='st_delete_vehicle'){
            if(!st_is_admin())throw new Exception('Only Administrator can delete vehicles.');$id=(int)($_POST['id']??0);$q=$pdo->prepare('SELECT registration_no FROM st_vehicles WHERE id=?');$q->execute([$id]);$plate=$q->fetchColumn();if(!$plate)throw new Exception('Vehicle not found.');$q=$pdo->prepare('SELECT COUNT(*) FROM st_transport_trips WHERE vehicle_id=?');$q->execute([$id]);if((int)$q->fetchColumn()>0)throw new Exception('Gari lina history ya safari; haliwezi kufutwa.');$pdo->prepare('DELETE FROM st_vehicles WHERE id=?')->execute([$id]);st_audit($pdo,'vehicle',$id,'VEHICLE DELETED',$plate);st_flash('Vehicle deleted.');st_redirect('st_transport&section=vehicles');
        }

        if($a==='st_save_driver'){
            if(!st_is_admin())throw new Exception('Only Administrator can register drivers.');
            $id=(int)($_POST['id']??0);$name=trim($_POST['full_name']??'');$username=trim($_POST['username']??'');$password=(string)($_POST['password']??'');$photo=st_upload_photo('driver_photo');if($name==='')throw new Exception('Driver name is required.');
            if($id<=0&&($username===''||$password===''))throw new Exception('New driver needs username and password.');
            $driverProfile=[trim($_POST['license_expiry']??''),trim($_POST['address']??''),trim($_POST['gender']??''),trim($_POST['marital_status']??''),max(0,(int)($_POST['children_count']??0)),trim($_POST['emergency_name']??''),trim($_POST['emergency_phone']??'')];
            $values=array_merge([$name,trim($_POST['phone']??''),trim($_POST['license_no']??'')],$driverProfile,[trim($_POST['status']??'Active'),$username,trim($_POST['notes']??'')]);
            if($id>0){$q=$pdo->prepare('SELECT user_id,photo FROM st_drivers WHERE id=?');$q->execute([$id]);$old=$q->fetch(PDO::FETCH_ASSOC);$oldUser=(int)($old['user_id']??0);$sql='UPDATE st_drivers SET full_name=?,phone=?,license_no=?,license_expiry=?,address=?,gender=?,marital_status=?,children_count=?,emergency_name=?,emergency_phone=?,status=?,username=?,notes=?'.($photo?',photo=?':'').' WHERE id=?';$uv=$values;if($photo)$uv[]=$photo;$uv[]=$id;$pdo->prepare($sql)->execute($uv);if($oldUser&&$password!=='')$pdo->prepare('UPDATE users SET username=?,name=?,phone=?,password=?,active=? WHERE id=?')->execute([$username,$name,trim($_POST['phone']??''),zan_password_hash($password),($_POST['status']??'Active')==='Active'?1:0,$oldUser]);}
            else{$pdo->beginTransaction();try{$pdo->prepare("INSERT INTO users(username,password,name,phone,role,active,permissions,account_system) VALUES(?,?,?,?,?,1,?,?)")->execute([$username,zan_password_hash($password),$name,trim($_POST['phone']??''),'Driver',json_encode(['site_tracking_system'=>true]),'site']);$userId=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO st_drivers(full_name,phone,license_no,license_expiry,address,gender,marital_status,children_count,emergency_name,emergency_phone,status,user_id,username,notes,photo) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge([$name,trim($_POST['phone']??''),trim($_POST['license_no']??'')],$driverProfile,[trim($_POST['status']??'Active'),$userId,$username,trim($_POST['notes']??''),$photo]));$id=(int)$pdo->lastInsertId();$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}}
            st_audit($pdo,'driver',$id,'DRIVER SAVED',$name);st_flash('Driver saved.');st_redirect('st_transport&section=drivers');
        }
        if($a==='st_delete_driver'){
            if(!st_is_admin())throw new Exception('Only Administrator can delete drivers.');
            $id=(int)($_POST['id']??0);$q=$pdo->prepare('SELECT user_id,photo FROM st_drivers WHERE id=?');$q->execute([$id]);$drv=$q->fetch(PDO::FETCH_ASSOC);if(!$drv)throw new Exception('Driver not found.');
            $q=$pdo->prepare('SELECT COUNT(*) FROM st_transport_trips WHERE driver_id=?');$q->execute([$id]);if((int)$q->fetchColumn()>0)throw new Exception('Dereva ana history ya safari; hawezi kufutwa. Tumia INACTIVE.');
            if(!empty($drv['user_id']))$pdo->prepare('DELETE FROM users WHERE id=?')->execute([(int)$drv['user_id']]);
            $pdo->prepare('DELETE FROM st_drivers WHERE id=?')->execute([$id]);
            if(!empty($drv['photo'])){$pp=__DIR__.'/'.ltrim($drv['photo'],'/\\');if(is_file($pp))@unlink($pp);}
            st_audit($pdo,'driver',$id,'DRIVER DELETED');st_flash('Driver deleted.');st_redirect('st_transport&section=drivers');
        }
        if($a==='st_save_transport_trip'){
            if(!st_is_admin()&&!st_is_manager())throw new Exception('Only Manager or Administrator can record trips.');$vehicle=(int)($_POST['vehicle_id']??0);$driver=(int)($_POST['driver_id']??0);$destination=trim($_POST['destination']??'');$techInput=(array)($_POST['technician_ids']??[]);$techs=array_values(array_unique(array_filter(array_map('intval',$techInput))));
            if($vehicle<=0||$destination==='')throw new Exception('Vehicle and destination are required. Driver na fundi si lazima.');
            $q=$pdo->prepare("SELECT id,status FROM st_vehicles WHERE id=? LIMIT 1");$q->execute([$vehicle]);$vehicleRow=$q->fetch(PDO::FETCH_ASSOC);if(!$vehicleRow)throw new Exception('Vehicle not found.');if(in_array(strtolower(trim((string)($vehicleRow['status']??''))),['on trip','inactive','broken down','defective vehicle'],true))throw new Exception('Selected vehicle is not Available.');
            $driverRow=null; if($driver>0){$q=$pdo->prepare("SELECT id,status,full_name FROM st_drivers WHERE id=? LIMIT 1");$q->execute([$driver]);$driverRow=$q->fetch(PDO::FETCH_ASSOC);if(!$driverRow)throw new Exception('Driver not found.');if(($driverRow['status']??'')!=='Active')throw new Exception('Selected driver is not Active.');$q=$pdo->prepare("SELECT id FROM st_transport_trips WHERE driver_id=? AND status='Open' ORDER BY id DESC LIMIT 1");$q->execute([$driver]);if($q->fetchColumn())throw new Exception('Dereva huyu bado yuko kwenye safari ya awali. Funga safari ya awali kwanza ndipo apewe usafiri mwingine.');} else {$driver=0;}
            $lat=($_POST['start_latitude']??'')!==''?(float)$_POST['start_latitude']:null;$lng=($_POST['start_longitude']??'')!==''?(float)$_POST['start_longitude']:null;$pdo->beginTransaction();
            try{$pdo->prepare('INSERT INTO st_transport_trips(vehicle_id,driver_id,site_id,destination,purpose,departure_at,start_latitude,start_longitude,start_accuracy,start_odometer,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$vehicle,$driver,(int)($_POST['site_id']??0)?:null,$destination,trim($_POST['purpose']??''),trim($_POST['departure_at']??date('Y-m-d H:i:s')),$lat,$lng,($_POST['start_accuracy']??'')!==''?(float)$_POST['start_accuracy']:null,(float)($_POST['start_odometer']??0),'Open',trim($_POST['notes']??''),st_current_user_name()]);$trip=(int)$pdo->lastInsertId();$insert=$pdo->prepare('INSERT OR IGNORE INTO st_transport_trip_technicians(trip_id,technician_id) VALUES(?,?)');foreach($techs as $tech){$q=$pdo->prepare("SELECT id,full_name FROM st_technicians WHERE id=? AND status='Active' LIMIT 1");$q->execute([$tech]);$techRow=$q->fetch(PDO::FETCH_ASSOC);if(!$techRow)throw new Exception('One selected technician is not Active or does not exist.');$insert->execute([$trip,$tech]);}
                $siteId=(int)($_POST['site_id']??0);if($siteId>0){$taskTitle='Transport: '.$destination;$taskNotes='Safari ya kampuni. '.($driver>0?'Dereva: '.($driverRow['id']??$driver).'. ':'').'Trip #'.$trip;$taskInsert=$pdo->prepare("INSERT INTO st_site_tasks(site_id,task_title,notes,status,assigned_to,assigned_to_type,task_type,priority,due_at) VALUES(?,?,?,?,?,?,?,?,?)");if($driver>0){$taskInsert->execute([$siteId,$taskTitle,$taskNotes,'New',$driver,'driver','transport','High',trim($_POST['departure_at']??date('Y-m-d H:i:s'))]);$driverTaskId=(int)$pdo->lastInsertId();st_queue_task_assignment_notification($pdo,'driver',$driver,$taskTitle,$destination,'transport_task_assigned');}foreach($techs as $tech){$taskInsert->execute([$siteId,$taskTitle,$taskNotes,'New',$tech,'technician','transport','High',trim($_POST['departure_at']??date('Y-m-d H:i:s'))]);$techTaskId=(int)$pdo->lastInsertId();st_queue_task_assignment_notification($pdo,'technician',$tech,$taskTitle,$destination,'transport_task_assigned');}}
                $pdo->prepare("UPDATE st_vehicles SET status='On Trip' WHERE id=?")->execute([$vehicle]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            st_audit($pdo,'transport_trip',$trip,'TRIP APPROVED','Destination: '.$destination.' | GPS: '.$lat.','.$lng);st_flash('Approved trip assigned to driver and technicians.');st_redirect('st_transport&section=trips');
        }

        if($a==='st_mark_transport_arrival'){
            $tripId=(int)($_POST['trip_id']??0); if($tripId<=0)throw new Exception('Safari haijapatikana.');
            $lat=($_POST['latitude']??'')!==''?(float)$_POST['latitude']:null; $lng=($_POST['longitude']??'')!==''?(float)$_POST['longitude']:null; $acc=($_POST['accuracy']??'')!==''?(float)$_POST['accuracy']:null;
            $q=$pdo->prepare("SELECT * FROM st_transport_trips WHERE id=? AND status='Open' LIMIT 1");$q->execute([$tripId]);$trip=$q->fetch(PDO::FETCH_ASSOC);if(!$trip)throw new Exception('Safari haipo au tayari imefungwa.');
            if(st_is_driver()){
                $me=st_current_driver($pdo);if(!$me||((int)$trip['driver_id']!==(int)$me['id']))throw new Exception('Safari hii si yako.');
                $pdo->prepare("UPDATE st_transport_trips SET driver_arrived_at=CURRENT_TIMESTAMP,driver_arrival_latitude=?,driver_arrival_longitude=?,driver_arrival_accuracy=? WHERE id=?")->execute([$lat,$lng,$acc,$tripId]);
                $q=$pdo->prepare("UPDATE st_site_tasks SET status='In Progress',notes=CASE WHEN notes IS NULL OR notes='' THEN 'Dereva amefika alipo tumwa.' ELSE notes||' | Dereva amefika alipo tumwa.' END WHERE assigned_to_type='driver' AND assigned_to=? AND notes LIKE ? AND status IN ('New','Accepted')");$q->execute([(int)$me['id'],'%Trip #'.$tripId.'%']);
                st_audit($pdo,'transport_trip',$tripId,'DRIVER ARRIVED','GPS: '.$lat.','.$lng);st_flash('Umefika alipo tumwa.');st_redirect('st_driver_portal');
            }
            if(st_is_technician()){
                $me=st_current_technician($pdo);if(!$me)throw new Exception('Fundi hajapatikana.');
                $q=$pdo->prepare('SELECT 1 FROM st_transport_trip_technicians WHERE trip_id=? AND technician_id=? LIMIT 1');$q->execute([$tripId,(int)$me['id']]);if(!$q->fetch())throw new Exception('Safari hii haijapewa fundi huyu.');
                $pdo->prepare("UPDATE st_transport_trip_technicians SET arrived_at=CURRENT_TIMESTAMP,arrival_latitude=?,arrival_longitude=?,arrival_accuracy=? WHERE trip_id=? AND technician_id=?")->execute([$lat,$lng,$acc,$tripId,(int)$me['id']]);
                $q=$pdo->prepare("UPDATE st_site_tasks SET status='In Progress',notes=CASE WHEN notes IS NULL OR notes='' THEN 'Fundi amefika site.' ELSE notes||' | Fundi amefika site.' END WHERE assigned_to_type='technician' AND assigned_to=? AND notes LIKE ? AND status IN ('New','Accepted')");$q->execute([(int)$me['id'],'%Trip #'.$tripId.'%']);
                st_audit($pdo,'transport_trip',$tripId,'TECHNICIAN ARRIVED','Technician: '.$me['full_name'].' | GPS: '.$lat.','.$lng);st_flash('Umefika site.');st_redirect('st_portal');
            }
            throw new Exception('Huna ruhusa ya kuthibitisha kufika.');
        }

        if($a==='st_return_transport_to_office'){
            if(!st_is_driver())throw new Exception('Dereva pekee ndiye anaweza kurudisha usafiri ofisini.');
            $me=st_current_driver($pdo);$tripId=(int)($_POST['trip_id']??0);$endOdo=(float)($_POST['end_odometer']??0);
            if(!$me||$tripId<=0)throw new Exception('Safari haijapatikana.');
            $q=$pdo->prepare("SELECT * FROM st_transport_trips WHERE id=? AND driver_id=? AND status='Open' LIMIT 1");$q->execute([$tripId,(int)$me['id']]);$trip=$q->fetch(PDO::FETCH_ASSOC);if(!$trip)throw new Exception('Safari hii haipo au tayari imerudishwa.');
            if($endOdo<(float)$trip['start_odometer'])throw new Exception('End odometer haiwezi kuwa chini ya start odometer.');
            $lat=($_POST['latitude']??'')!==''?(float)$_POST['latitude']:null;$lng=($_POST['longitude']??'')!==''?(float)$_POST['longitude']:null;$acc=($_POST['accuracy']??'')!==''?(float)$_POST['accuracy']:null;
            $pdo->prepare("UPDATE st_transport_trips SET return_at=CURRENT_TIMESTAMP,end_latitude=?,end_longitude=?,end_accuracy=?,end_odometer=?,status='Closed' WHERE id=?")->execute([$lat,$lng,$acc,$endOdo,$tripId]);
            $pdo->prepare("UPDATE st_vehicles SET status='Available' WHERE id=? AND status='On Trip'")->execute([(int)$trip['vehicle_id']]);
            st_audit($pdo,'transport_trip',$tripId,'TRANSPORT RETURNED TO OFFICE','Driver: '.$me['full_name'].' | End GPS: '.$lat.','.$lng.' | Odometer: '.$endOdo);st_flash('Usafiri umerudishwa ofisini. Gari limekuwa Available.');st_redirect('st_driver_portal');
        }

        if($a==='st_close_transport_trip'){
            if(!st_is_admin()&&!st_is_manager())throw new Exception('Only Manager or Administrator can close trips.');
            $id=(int)($_POST['id']??0);$lat=($_POST['end_latitude']??'')!==''?(float)$_POST['end_latitude']:null;$lng=($_POST['end_longitude']??'')!==''?(float)$_POST['end_longitude']:null;if($id<=0)throw new Exception('Trip is required.');
            $q=$pdo->prepare("SELECT vehicle_id,start_odometer,status FROM st_transport_trips WHERE id=? LIMIT 1");$q->execute([$id]);$openTrip=$q->fetch(PDO::FETCH_ASSOC);if(!$openTrip||($openTrip['status']??'')!=='Open')throw new Exception('Trip not found or already closed.');$endOdo=(float)($_POST['end_odometer']??0);if($endOdo<(float)$openTrip['start_odometer'])throw new Exception('End odometer cannot be less than start odometer.');
            $pdo->prepare("UPDATE st_transport_trips SET return_at=CURRENT_TIMESTAMP,end_latitude=?,end_longitude=?,end_accuracy=?,end_odometer=?,status='Closed' WHERE id=?")->execute([$lat,$lng,($_POST['end_accuracy']??'')!==''?(float)$_POST['end_accuracy']:null,$endOdo,$id]);$pdo->prepare("UPDATE st_vehicles SET status='Available' WHERE id=? AND status='On Trip'")->execute([(int)$openTrip['vehicle_id']]);st_audit($pdo,'transport_trip',$id,'TRIP CLOSED','End GPS: '.$lat.','.$lng.' | Odometer: '.$endOdo);st_flash('Trip closed with return GPS. Vehicle is Available again.');st_redirect('st_transport&section=trips');
        }
        if($a==='st_submit_transport_request'){
            if(!st_is_driver())throw new Exception('Only drivers can submit transport requests.');
            $driver=st_current_driver($pdo);$type=trim($_POST['request_type']??'');$description=trim($_POST['description']??'');$amount=(float)($_POST['amount']??0);$tripId=(int)($_POST['trip_id']??0);$photo=st_upload_photo('photo');
            if(!$driver||!in_array($type,['Fuel','Maintenance','Breakdown','Other'],true)||$description==='')throw new Exception('Request type and description are required.');
            $lat=($_POST['latitude']??'')!==''?(float)$_POST['latitude']:null;$lng=($_POST['longitude']??'')!==''?(float)$_POST['longitude']:null;
            $pdo->prepare('INSERT INTO st_transport_requests(driver_id,trip_id,request_type,amount,description,photo,latitude,longitude) VALUES(?,?,?,?,?,?,?,?)')->execute([(int)$driver['id'],$tripId?:null,$type,$amount,$description,$photo?:'',$lat,$lng]);$id=(int)$pdo->lastInsertId();st_audit($pdo,'transport_request',$id,'SUBMITTED',$type.': '.$description);st_flash('Transport request sent to Administrator.');st_redirect('st_driver_portal');
        }
        if($a==='st_review_transport_request'){
            if(!st_is_admin()&&!st_is_manager())throw new Exception('Only Manager or Administrator can review transport requests.');
            $id=(int)($_POST['id']??0);$decision=$_POST['decision']??'';$comment=trim($_POST['review_comment']??'');if(!in_array($decision,['Approved','Rejected'],true))throw new Exception('Invalid request decision.');
            if($decision==='Rejected' && $comment==='')throw new Exception('Rejection reason is required.');
            $q=$pdo->prepare("SELECT trip_id,request_type FROM st_transport_requests WHERE id=? LIMIT 1");$q->execute([$id]);$reqRow=$q->fetch(PDO::FETCH_ASSOC);
            $q=$pdo->prepare("UPDATE st_transport_requests SET status=?,reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP,review_comment=? WHERE id=? AND status='Pending'");$q->execute([$decision,st_current_user_name(),$comment,$id]);if($q->rowCount()!==1)throw new Exception('Request not found or already reviewed.');
            if($decision==='Approved' && strcasecmp((string)($reqRow['request_type']??''),'Breakdown')===0 && !empty($reqRow['trip_id'])){ $vq=$pdo->prepare('SELECT vehicle_id FROM st_transport_trips WHERE id=? LIMIT 1');$vq->execute([(int)$reqRow['trip_id']]);$vehicleId=(int)$vq->fetchColumn();if($vehicleId>0)$pdo->prepare("UPDATE st_vehicles SET status='Defective Vehicle' WHERE id=?")->execute([$vehicleId]); }
            st_audit($pdo,'transport_request',$id,'REQUEST '.$decision,$comment);st_flash('Transport request '.$decision.'.');st_redirect('st_transport&section=requests');
        }

        /* ---------- SITE PHOTO / GPS EVIDENCE ---------- */
        if($a==='st_add_site_photo'){
            if(!st_is_technician())throw new Exception('Only technician can submit site photos.');$ct=st_current_technician($pdo);if(!$ct)throw new Exception('Technician account is not linked.');$sid=(int)($_POST['site_id']??0);$category=trim($_POST['category']??'Work Progress');$caption=trim($_POST['caption']??'');$lat=($_POST['latitude']??'')!==''?(float)$_POST['latitude']:null;$lng=($_POST['longitude']??'')!==''?(float)$_POST['longitude']:null;$acc=($_POST['accuracy']??'')!==''?(float)$_POST['accuracy']:null;if($sid<=0)throw new Exception('Site is required.');$q=$pdo->prepare("SELECT s.id FROM st_sites s JOIN st_site_technicians st ON st.site_id=s.id WHERE s.id=? AND st.technician_id=? LIMIT 1");$q->execute([$sid,(int)$ct['id']]);if(!$q->fetch())throw new Exception('You are not assigned to this site.');$photo=st_upload_photo('photo');if(!$photo)throw new Exception('Site photo is required.');$pdo->prepare("INSERT INTO st_site_photos(site_id,technician_id,photo,category,caption,latitude,longitude,accuracy,captured_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)")->execute([$sid,(int)$ct['id'],$photo,$category,$caption,$lat,$lng,$acc,trim($_POST['captured_at']??'')?:null,$created]);$pid=(int)$pdo->lastInsertId();st_audit($pdo,'site_photo',$pid,'SITE PHOTO SUBMITTED','Site: '.$sid.' | '.$category.' | GPS: '.$lat.','.$lng);st_flash('Site photo saved with GPS location.');st_redirect('st_portal');
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
                $pdo->prepare("UPDATE $table SET status='Approved' WHERE id=?")->execute([$id]);
                $notifyTech=(int)($row['technician_id']??0); if($notifyTech>0){$kind=$a==='st_approve_tool'?'Tool':'Material'; st_queue_task_assignment_notification($pdo,'technician',$notifyTech,$kind.' ISSUE SOLVED — '.$kind.' imeidhinishwa na iko tayari/imetolewa.','', 'issue_solved');}
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
            $id=(int)($_POST['id']??0); $type=trim((string)($_POST['admin_type']??'Storekeeper'));
            $office=trim($_POST['office']??'');
            $adminPermissions=[];foreach(['dashboard','technicians','sites','tools','materials','photos','movements','vehicles','money_transfer','approvals','reports','daily_reports','activity','administration'] as $permission){if(isset($_POST['perm_'.$permission]))$adminPermissions[$permission]=1;}
            $whatsappTemplate=trim($_POST['whatsapp_template']??'');
            $passportPhoto=st_upload_photo('passport_photo');
            $username=trim($_POST['username']??''); $full=trim($_POST['full_name']??''); $password=(string)($_POST['password']??'');
            if($username===''||$full==='') throw new Exception('Username and full name are required.');
            if(!in_array($type,['Storekeeper','Manager','Technical Manager','Site Administrator'],true))throw new Exception('Invalid administrator title.');
            if($office==='')throw new Exception('Office is required.');
            if(!$id){
                if($password==='') throw new Exception('Password is required.');
                if(!$passportPhoto)throw new Exception('Passport-size photo is required.');
                $q=$pdo->prepare('SELECT id FROM users WHERE username=? LIMIT 1');$q->execute([$username]);if($q->fetch())throw new Exception('Username already exists.');
                $pdo->prepare("INSERT INTO users(username,password,name,phone,role,active,permissions,account_system) VALUES(?,?,?,?,?,1,?,?)")
                    ->execute([$username,zan_password_hash($password),$full,trim($_POST['phone']??''),'Administrator',json_encode(['site_tracking_system'=>true]),'site']);
                $uid=(int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO st_site_admins(user_id,username,full_name,admin_type,office,passport_photo,phone,email,permissions,whatsapp_template,active) VALUES(?,?,?,?,?,?,?,?,?,?,1)")
                    ->execute([$uid,$username,$full,$type,$office,$passportPhoto,trim($_POST['phone']??''),trim($_POST['email']??''),json_encode($adminPermissions),$whatsappTemplate]);
            }else{
                $q=$pdo->prepare('SELECT user_id FROM st_site_admins WHERE id=?');$q->execute([$id]);$old=$q->fetch(PDO::FETCH_ASSOC);if(!$old)throw new Exception('Administrator not found.');
                $q=$pdo->prepare('SELECT id FROM users WHERE username=? AND id<>? LIMIT 1');$q->execute([$username,(int)$old['user_id']]);if($q->fetch())throw new Exception('Username already exists.');
                if($password!=='') $pdo->prepare("UPDATE users SET username=?,name=?,phone=?,password=?,role=? WHERE id=?")->execute([$username,$full,trim($_POST['phone']??''),zan_password_hash($password),'Administrator',$old['user_id']]);
                else $pdo->prepare("UPDATE users SET username=?,name=?,phone=?,role=? WHERE id=?")->execute([$username,$full,trim($_POST['phone']??''),'Administrator',$old['user_id']]);
                $adminSql="UPDATE st_site_admins SET username=?,full_name=?,admin_type=?,office=?,phone=?,email=?,permissions=?,whatsapp_template=?";
                $adminValues=[$username,$full,$type,$office,trim($_POST['phone']??''),trim($_POST['email']??''),json_encode($adminPermissions),$whatsappTemplate];
                if($passportPhoto){$adminSql.=',passport_photo=?';$adminValues[]=$passportPhoto;}
                $adminSql.=' WHERE id=?';$adminValues[]=$id;
                $pdo->prepare($adminSql)->execute($adminValues);
            }
            st_flash($id?'Administrator updated.':'Administrator created.');st_redirect('st_administration');
        }

        if($a==='st_make_super_admin'){
            if(!st_is_super_admin())throw new Exception('Only current Super Admin can promote another Super Admin.');
            $uid=(int)($_POST['user_id']??0);if($uid<=0)throw new Exception('User not found.');
            $pdo->prepare("UPDATE users SET role='super admin',account_system='both',permissions=? WHERE id=? AND active=1")->execute([json_encode(['site_tracking_system'=>true,'business_system'=>true]),$uid]);
            st_audit($pdo,'user',$uid,'PROMOTED TO SUPER ADMIN','Access: Business + Site Tracking');st_flash('User amepandishwa kuwa Super Admin wa mifumo yote.');st_redirect('st_administration');
        }

        if($a==='st_clear_history'){
            if(!st_is_super_admin())throw new Exception('Only Super Admin can clear system history.');
            $confirm=trim((string)($_POST['confirm_text']??''));if(strtoupper($confirm)!=='CLEAR')throw new Exception('Andika CLEAR kuthibitisha kusafisha history.');
            $tables=['st_audit_log','st_notifications','st_assignment_jobs','st_site_tasks','st_transport_requests','st_transport_trip_technicians','st_transport_trips','st_daily_updates','st_material_movements','st_tool_movements','st_material_funds','st_money_transactions'];
            foreach($tables as $table){try{$pdo->exec('DELETE FROM '.$table);}catch(Throwable $e){}}
            try{$pdo->exec("UPDATE st_vehicles SET status='Available'");}catch(Throwable $e){}
            try{$pdo->exec("UPDATE st_tools SET status='Available'");}catch(Throwable $e){}
            st_flash('History na transactions zimefutwa. Master data (users, mafundi, madereva, magari na tools) imehifadhiwa.');st_redirect('st_administration');
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

        /* ---------- SITE HANDOVER ---------- */
        if($a==='st_handover_site'){
            if(!st_is_technician())throw new Exception('Only technician can hand over a site.');
            $siteId=(int)($_POST['site_id']??0);$ct=st_current_technician($pdo);if(!$ct)throw new Exception('Technician account is not linked.');
            $q=$pdo->prepare("SELECT s.* FROM st_sites s JOIN st_site_technicians st ON st.site_id=s.id WHERE s.id=? AND st.technician_id=? LIMIT 1");$q->execute([$siteId,(int)$ct['id']]);$site=$q->fetch(PDO::FETCH_ASSOC);
            if(!$site)throw new Exception('You are not assigned to this site.');
            if(($site['status']??'')==='Closed')throw new Exception('Closed site cannot be handed over again.');
            $handoverPhoto=st_upload_photo('handover_photo');$jobcardPhoto=st_upload_photo('handover_jobcard_photo');$extraHandoverPhotos=st_upload_photos('handover_photos');
            if(!$handoverPhoto||!$jobcardPhoto)throw new Exception('Picha ya makabidhiano na picha ya jobcard vinahitajika.');
            $lat=($_POST['latitude']??'')!==''?(float)$_POST['latitude']:null;$lng=($_POST['longitude']??'')!==''?(float)$_POST['longitude']:null;$acc=($_POST['accuracy']??'')!==''?(float)$_POST['accuracy']:null;
            // GPS is optional for site handover.
            $capturedAt=trim($_POST['captured_at']??'')?:null;$notes=trim($_POST['handover_notes']??'');$user=st_current_user_name();
            $pdo->beginTransaction();
            try{
                $pdo->prepare("UPDATE st_sites SET status='Pending Admin Approval',completion_notes=?,completion_photo=?,jobcard_photo=?,completion_submitted_at=CURRENT_TIMESTAMP,completion_submitted_by=?,admin_approved=0,rejection_reason=NULL,completion_latitude=?,completion_longitude=?,completion_accuracy=? WHERE id=?")
                    ->execute([$notes,$handoverPhoto,$jobcardPhoto,$user,$lat,$lng,$acc,$siteId]);
                $photoInsert=$pdo->prepare("INSERT INTO st_site_photos(site_id,technician_id,photo,category,caption,latitude,longitude,accuracy,captured_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)");
                $photoInsert->execute([$siteId,(int)$ct['id'],$handoverPhoto,'Customer Handover',$notes,$lat,$lng,$acc,$capturedAt,$user]);
                $photoInsert->execute([$siteId,(int)$ct['id'],$jobcardPhoto,'Jobcard',$notes,$lat,$lng,$acc,$capturedAt,$user]);
                foreach($extraHandoverPhotos as $extraPhoto)$photoInsert->execute([$siteId,(int)$ct['id'],$extraPhoto,'Customer Handover',$notes,$lat,$lng,$acc,$capturedAt,$user]);
                st_save_evidence_photos($pdo,'site',$siteId,'Customer Handover',array_merge([$handoverPhoto],$extraHandoverPhotos),$user);
                st_save_evidence_photos($pdo,'site',$siteId,'Jobcard',[$jobcardPhoto],$user);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            st_audit($pdo,'site',$siteId,'SITE HANDOVER SUBMITTED','Jobcard and handover photos submitted.');st_flash('Makabidhiano ya site yametumwa kwa Admin approval.');st_redirect('st_portal');
        }

        /* ---------- SITE COMPLETION ---------- */
        if($a==='st_complete_site'){
            if(!st_is_technician())throw new Exception('Only technician can complete a site.');
            $siteId=(int)($_POST['site_id']??0);$ct=st_current_technician($pdo);if(!$ct)throw new Exception('Technician account is not linked.');
            $q=$pdo->prepare("SELECT 1 FROM st_site_technicians WHERE site_id=? AND technician_id=? LIMIT 1");$q->execute([$siteId,$ct['id']]);
            if(!$q->fetch())throw new Exception('You are not assigned to this site.');
            $photo=st_upload_photo('completion_photo');$jobcardPhoto=st_upload_photo('jobcard_photo');$extraJobcardPhotos=st_upload_photos('jobcard_photos');if(!$photo||!$jobcardPhoto)throw new Exception('Completion photo na picha ya jobcard vinahitajika.');
            $lat=($_POST['latitude']??'')!==''?(float)$_POST['latitude']:null;$lng=($_POST['longitude']??'')!==''?(float)$_POST['longitude']:null;$acc=($_POST['accuracy']??'')!==''?(float)$_POST['accuracy']:null;// GPS is optional for completion reports.
            $pdo->prepare("UPDATE st_sites SET status='Pending Admin Approval',completion_notes=?,completion_photo=?,jobcard_photo=?,completion_submitted_at=CURRENT_TIMESTAMP,completion_submitted_by=?,admin_approved=0,completion_latitude=?,completion_longitude=?,completion_accuracy=? WHERE id=?")
                ->execute([trim($_POST['completion_notes']??''),$photo,$jobcardPhoto,st_current_user_name(),$lat,$lng,$acc,$siteId]);
            st_save_evidence_photos($pdo,'site',$siteId,'Jobcard',$extraJobcardPhotos,st_current_user_name());
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

        if($a==='st_ai_ask'){
            if(!st_is_admin()&&!st_is_technician())throw new Exception('Huna ruhusa ya kutumia AI Assistant.');
            $question=trim($_POST['question']??'');if($question==='')throw new Exception('Andika swali kwanza.');
            $like='%'.strtolower($question).'%';$answer='';
            $qText=strtolower($question);
            $people=$pdo->query("SELECT name,role FROM users WHERE active=1 ORDER BY name LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);$peopleContext=[];foreach($people as $person)$peopleContext[]=$person['name'].' ('.$person['role'].')';
            $currentUser=($_SESSION['user']['name']??$_SESSION['user']['username']??'User').' ('.($_SESSION['user']['role']??'User').')';$chatContext=[];foreach(array_slice($_SESSION['st_ai_chat']??[],-8) as $chat)$chatContext[]='User: '.$chat['question'].' | AI: '.$chat['answer'];
            $context='Current user: '.$currentUser.'. Recent chat: '.implode(' || ',$chatContext).'. Active system users: '.implode('; ',$peopleContext).'. Sites active: '.(int)$pdo->query("SELECT COUNT(*) FROM st_sites WHERE status<>'Closed'")->fetchColumn().'; Sites pending approval: '.(int)$pdo->query("SELECT COUNT(*) FROM st_sites WHERE status='Pending Admin Approval'")->fetchColumn().'; Active technicians: '.(int)$pdo->query("SELECT COUNT(*) FROM st_technicians WHERE status='Active'")->fetchColumn().'; Money transfers: '.(int)$pdo->query('SELECT COUNT(*) FROM st_material_funds')->fetchColumn().'; Money pending receipt: '.(int)$pdo->query("SELECT COUNT(*) FROM st_material_funds WHERE status='Granted'")->fetchColumn().'; Tools issued: '.(int)$pdo->query("SELECT COUNT(*) FROM st_tools WHERE status='Issued'")->fetchColumn().'; Registered vehicles: '.(int)$pdo->query('SELECT COUNT(*) FROM st_vehicles')->fetchColumn().'; Active drivers: '.(int)$pdo->query("SELECT COUNT(*) FROM st_drivers WHERE status='Active'")->fetchColumn().'; Open transport trips: '.(int)$pdo->query("SELECT COUNT(*) FROM st_transport_trips WHERE status='Open'")->fetchColumn().'; Pending driver requests: '.(int)$pdo->query("SELECT COUNT(*) FROM st_transport_requests WHERE status='Pending'")->fetchColumn();
            $context.=' SYSTEM GUIDE: Sites have one Site Leader/Primary and can have multiple additional technicians. Assignment Center links job, technician, optional driver, available vehicle, trip, tools and typed materials. Driver is optional. Tasks use NEW -> ACCEPTED -> IN PROGRESS -> COMPLETED -> VERIFIED; BLOCKED is an exception with a reason. Technicians and drivers see assigned tasks in their portals. Tools are registered and tracked by issue/return with photo/GPS; reports cover issued, returned, damaged, missing and tools held by technicians. Materials are not a stock/store workflow. Transport has Dashboard, Safari, Vehicles, Drivers, Requests and Reports. Administrators never see passwords. Super Admin can promote a user to both systems and clear operational history.';
            $remoteAnswer=st_ai_remote_answer($question,$context,true);
            if($remoteAnswer!==null){$answer=$remoteAnswer;}
            elseif(str_contains($qText,'usafiri')||str_contains($qText,'gari')||str_contains($qText,'pikipiki')||str_contains($qText,'dereva')||str_contains($qText,'transport')){$stats=$pdo->query("SELECT COUNT(*) vehicles,COALESCE(SUM(status='Available'),0) available FROM st_vehicles")->fetch(PDO::FETCH_ASSOC);$trips=(int)$pdo->query("SELECT COUNT(*) FROM st_transport_trips WHERE status='Open'")->fetchColumn();$requests=(int)$pdo->query("SELECT COUNT(*) FROM st_transport_requests WHERE status='Pending'")->fetchColumn();$answer='Usafiri wa kampuni: '.(int)$stats['vehicles'].' vyombo vilivyosajiliwa, '.(int)$stats['available'].' vinapatikana, safari '.$trips.' ziko wazi, na requests '.$requests.' zinasubiri review. Mfumo unahifadhi dereva, fundi, destination, GPS, odometer, mafuta, maintenance na breakdown.';}
            elseif(str_contains($qText,'habari')||str_contains($qText,'hello')||str_contains($qText,'hi')||str_contains($qText,'jambo')||str_contains($qText,'mambo')){$answer='Habari! Nipo hapa kukusaidia. Uliza kuhusu watu, sites, mafundi, pesa, tools, usafiri au reports.';}
            elseif(str_contains($qText,'asante')||str_contains($qText,'thanks')||str_contains($qText,'thank you')){$answer='Karibu! Endelea kuniuliza, nitaangalia taarifa za Zantronix kwa ajili yako.';}
            elseif(str_contains($qText,'iliombwa')||str_contains($qText,'imeombwa')||str_contains($qText,'ombi')||str_contains($qText,'request')){
                $stats=$pdo->query("SELECT COUNT(*) total,COALESCE(SUM(amount),0) amount,COALESCE(SUM(CASE WHEN status='Granted' THEN 1 ELSE 0 END),0) granted,COALESCE(SUM(CASE WHEN status='Receipt Submitted' THEN 1 ELSE 0 END),0) receipted FROM st_material_funds")->fetch(PDO::FETCH_ASSOC);
                if((int)$stats['total']>0){$answer='Kuna rekodi '.(int)$stats['total'].' za pesa zilizoombwa/kutolewa, jumla '.number_format((float)$stats['amount'],2).' TZS. Zilizotolewa: '.(int)$stats['granted'].'. Zilizowasilisha risiti: '.(int)$stats['receipted'].'.';}else{$answer='Hakuna rekodi ya pesa iliyoombwa au kutolewa bado. Mfumo kwa sasa huhifadhi ombi la pesa linapokuwa Money Transfer imetumwa na admin.';}
            }elseif(str_contains($qText,'risiti')||str_contains($qText,'receipt')){
                $rows=$pdo->query("SELECT t.full_name,s.site_name,f.amount,f.currency FROM st_material_funds f JOIN st_technicians t ON t.id=f.technician_id JOIN st_sites s ON s.id=f.site_id WHERE f.status='Granted' ORDER BY f.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
                if($rows){$items=[];foreach($rows as $row)$items[]=$row['technician_name']??$row['full_name'].' ('.$row['site_name'].'): '.$row['amount'].' '.$row['currency'];$answer='Transfers zinazosubiri receipt: '.implode('; ',$items).'.';}else{$answer='Hakuna transfer inayosubiri receipt kwa sasa.';}
            }elseif(str_contains($qText,'approval')||str_contains($qText,'approve')||str_contains($qText,'idhinish')){
                $rows=$pdo->query("SELECT s.site_name,t.full_name FROM st_sites s LEFT JOIN st_technicians t ON t.id=s.technician_id WHERE s.status='Pending Admin Approval' ORDER BY s.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
                if($rows){$items=[];foreach($rows as $row)$items[]=$row['site_name'].' - '.($row['full_name']??'Fundi hajapangwa');$answer='Sites zinazosubiri approval: '.implode('; ',$items).'.';}else{$answer='Hakuna site inayosubiri approval kwa sasa.';}
            }elseif(str_contains($qText,'pesa')||str_contains($qText,'money')||str_contains($qText,'transfer')){
                $sql="SELECT COUNT(*) total,COALESCE(SUM(amount),0) amount,COALESCE(SUM(CASE WHEN status='Granted' THEN amount ELSE 0 END),0) pending FROM st_material_funds";$stats=$pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
                $answer='Money Transfer: '.(int)$stats['total'].' transfers, jumla '.number_format((float)$stats['amount'],2).' TZS. Zinazosubiri risiti: '.number_format((float)$stats['pending'],2).' TZS.';
            }elseif(str_contains($qText,'watu')||str_contains($qText,'people')||str_contains($qText,'users')||str_contains($qText,'mtumiaji')||str_contains($qText,'staff')||str_contains($qText,'admin')){
                if($people){$items=[];foreach($people as $person)$items[]=$person['name'].' - '.$person['role'].' ('.$person['username'].')';$answer='Watu active waliopo kwenye mfumo: '.implode('; ',$items).'.';}else{$answer='Hakuna user active aliyepatikana kwenye mfumo.';}
            }elseif((str_contains($qText,'bora')||str_contains($qText,'best'))&&(str_contains($qText,'mwezi')||str_contains($qText,'month'))){
                $ranking=st_best_technicians($pdo,$pdo->query('SELECT * FROM st_technicians WHERE status=\'Active\' ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC),date('Y-m-01'),date('Y-m-t'));
                if($ranking){$top=$ranking[0];$answer='Fundi bora wa mwezi ni '.$top['name'].' akiwa na score '.$top['score'].'/100. Sites kwa wakati: '.$top['on_time'].', daily updates: '.$top['updates'].', tasks: '.$top['tasks'].', photos: '.$top['photos'].', tools: '.$top['tools'].'.';}else{$answer='Hakuna data ya kutosha kumtaja fundi bora wa mwezi.';}
            }elseif(str_contains($qText,'fundi')||str_contains($qText,'technician')){
                $stats=$pdo->query("SELECT COUNT(*) total,COALESCE(SUM(status='Active'),0) active FROM st_technicians")->fetch(PDO::FETCH_ASSOC);$answer='Mafundi: '.(int)$stats['total'].' kwa jumla, '.(int)$stats['active'].' wako Active.';
            }elseif(str_contains($qText,'site')){
                $stats=$pdo->query("SELECT COUNT(*) total,COALESCE(SUM(status='Closed'),0) closed,COALESCE(SUM(status='Pending Admin Approval'),0) pending FROM st_sites")->fetch(PDO::FETCH_ASSOC);$answer='Sites: '.(int)$stats['total'].' kwa jumla, '.(int)$stats['closed'].' zimefungwa, na '.(int)$stats['pending'].' zinasubiri approval ya admin.';
            }elseif(str_contains($qText,'tool')){
                $stats=$pdo->query("SELECT COUNT(*) total,COALESCE(SUM(status='Issued'),0) issued FROM st_tools")->fetch(PDO::FETCH_ASSOC);$answer='Tools: '.(int)$stats['total'].' kwa jumla, '.(int)$stats['issued'].' ziko kwa mafundi sasa.';
            }else{
                $sites=(int)$pdo->query("SELECT COUNT(*) FROM st_sites WHERE status<>'Closed'")->fetchColumn();$pending=(int)$pdo->query("SELECT COUNT(*) FROM st_material_funds WHERE status='Granted'")->fetchColumn();$answer='Muhtasari: kuna '.$sites.' active sites na '.$pending.' money transfers zinazosubiri receipt. Uliza kuhusu site, fundi, pesa au tool kwa majibu maalum.';
            }
            $_SESSION['st_ai_question']=$question;$_SESSION['st_ai_answer']=$answer;
            $_SESSION['st_ai_chat'][]=['question'=>$question,'answer'=>$answer,'created_at'=>date('Y-m-d H:i:s')];
            $_SESSION['st_ai_chat']=array_slice($_SESSION['st_ai_chat'],-20);
            st_redirect('st_ai_assistant');
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
    $isSuperAdmin=in_array($roleLower,['super admin','super_admin','superadmin','founder'],true);
    $isBusinessAdmin=in_array($roleLower,['admin','msimamizi'],true);
    $canBusinessSystem=$isSuperAdmin||(($accountSystem==='business'||$accountSystem==='both')&&($isBusinessAdmin||!empty($systemPerms['business_system'])));
    $canSiteTrackingSystem=$isSuperAdmin||!empty($systemPerms['site_tracking_system'])||$accountSystem==='site'||$accountSystem==='both';
    $stPagesGuard=['site_tracking','st_portal','st_driver_portal','st_ai_assistant','st_settings','st_administration','st_technicians','st_sites','st_tools','st_tool_report','st_materials','st_material_report','st_movements','st_transport','st_transport_reports','st_export_report','st_money_transfer','st_approvals','st_site_gallery','st_reports','st_activity','st_daily_reports','st_tasks','st_assignment_center'];
    if(in_array($page,$stPagesGuard,true)&&!$canSiteTrackingSystem){zan_access_denied('Huna ruhusa ya kutumia Site Tracking System.');}
    if(isset($_GET['system'])&&$_GET['system']==='business'&&!$canBusinessSystem){zan_access_denied('Huna ruhusa ya kutumia Business System.');}
    if(!$canBusinessSystem&&!$isSuperAdmin&&!in_array($page,$stPagesGuard,true)&&$page!=='choose_system'){zan_access_denied('Huna ruhusa ya kutumia Business System.');}
}

/* =========================================================
   CENTRAL PAGE PERMISSION GUARD
   ========================================================= */
if (isset($_SESSION['user'])) {
    $permissionMap=[
        'customers'=>'customers','customer_statement'=>'customers','add_customer'=>'customers','edit_customer'=>'customers','save_customer'=>'customers','update_customer'=>'customers','delete_customer'=>'customers',
        'products'=>'products','add_product'=>'products','edit_product'=>'products','save_product'=>'products','update_product'=>'products','delete_product'=>'products','barcode_product'=>'products',
        'suppliers'=>'products','edit_supplier'=>'products','save_supplier'=>'products','update_supplier'=>'products','delete_supplier'=>'products',
        'new_invoice'=>'invoices','invoices'=>'invoices','edit_invoice'=>'invoices','update_invoice'=>'invoices','save_invoice'=>'invoices','delete_invoice'=>'invoices','record_payment'=>'invoices','payment'=>'invoices','print'=>'invoices',
        'quotations'=>'invoices','new_quotation'=>'invoices','save_quotation'=>'invoices','print_quotation'=>'invoices','delete_quotation'=>'invoices','convert_quotation'=>'invoices',
        'proforma'=>'invoices','new_proforma'=>'invoices','save_proforma'=>'invoices','print_proforma'=>'invoices','delete_proforma'=>'invoices',
        'reports'=>'reports','export_reports'=>'reports','expenses'=>'expenses','save_expense'=>'expenses','delete_expense'=>'expenses',
        'ai_assistant'=>'dashboard','debt_reminders'=>'invoices','settings'=>'settings','save_settings'=>'settings','users'=>'users','add_user'=>'users','edit_user'=>'users','save_user'=>'users','update_user'=>'users','delete_user'=>'users','toggle_user'=>'users','activity'=>'activity'
    ];
    if(isset($permissionMap[$page])) {
        $guardRole=strtolower(trim($_SESSION['user']['role'] ?? ''));
        $guardPerms=json_decode($_SESSION['user']['permissions'] ?? '{}', true);
        if(!is_array($guardPerms)) $guardPerms=[];
        $guardBusiness=in_array($guardRole,['super admin','super_admin','superadmin','admin','msimamizi'],true) || !empty($guardPerms['business_system']);
        if(!$guardBusiness && !can_do($permissionMap[$page])) { zan_access_denied('Huna ruhusa ya kutumia sehemu hii.'); }
    }
}

/* =========================================================
   /* =========================================================
   SITE TRACKING SYSTEM PAGES
   ========================================================= */
$stPages=['site_tracking','st_portal','st_driver_portal','st_ai_assistant','st_settings','st_administration','st_technicians','st_sites','st_tools','st_tool_report','st_materials','st_material_report','st_movements','st_transport','st_transport_reports','st_export_report','st_money_transfer','st_approvals','st_site_gallery','st_reports','st_activity','st_daily_reports','st_tasks','st_assignment_center'];

if(st_is_technician() && $page==='site_tracking'){
    header('Location:index.php?page=st_portal');
    exit;
}
if(st_is_driver() && $page==='site_tracking'){
    header('Location:index.php?page=st_driver_portal');
    exit;
}
    if(st_is_technician() && !in_array($page,['st_portal','st_ai_assistant','st_sites','st_movements','st_site_gallery'],true) && in_array($page,$stPages,true)){
    zan_access_denied('Fundi anaweza kuona taarifa zake tu kupitia Technician Portal.');
}
if(st_is_driver() && !in_array($page,['st_driver_portal','st_ai_assistant'],true) && in_array($page,$stPages,true)){
    zan_access_denied('Dereva anaweza kuona safari zake kupitia Driver Portal.');
}

if(isset($_SESSION['user'])&&!st_is_technician()&&in_array($page,$stPages,true)){
    $stPermissionMap=['site_tracking'=>'dashboard','st_ai_assistant'=>'dashboard','st_settings'=>'administration','st_administration'=>'administration','st_technicians'=>'technicians','st_sites'=>'sites','st_tools'=>'tools','st_tool_report'=>'reports','st_materials'=>'materials','st_material_report'=>'materials','st_movements'=>'movements','st_transport'=>'vehicles','st_transport_reports'=>'reports','st_export_report'=>'reports','st_money_transfer'=>'money_transfer','st_approvals'=>'approvals','st_site_gallery'=>'photos','st_reports'=>'reports','st_activity'=>'activity','st_daily_reports'=>'daily_reports','st_tasks'=>'tasks','st_assignment_center'=>'tasks'];
    $requiredPermission=$stPermissionMap[$page]??'dashboard';
    if($page!=='st_driver_portal'&&!st_admin_can($pdo,$requiredPermission)){zan_access_denied('Huna ruhusa ya kuona sehemu hii ya Site Tracking.');}
}

if($page==='st_export_report'){
    if(!isset($_SESSION['user'])||!st_admin_can($pdo,'reports'))zan_access_denied('Huna ruhusa ya ku-export reports.');
    $reportType=$_GET['type']??'summary';$rows=[];$headers=[];
    if($reportType==='activity'){$headers=['Date','Entity','Entity ID','Action','Details','Performed By'];$rows=$pdo->query('SELECT created_at,entity_type,entity_id,action,details,performed_by FROM st_audit_log ORDER BY id DESC LIMIT 2000')->fetchAll(PDO::FETCH_NUM);}
    elseif($reportType==='daily'){$headers=['Date','Technician','Site','Work Done','Blockers','Next Steps','Latitude','Longitude','Accuracy'];$rows=$pdo->query("SELECT u.update_date,t.full_name,s.site_name,u.work_done,u.blockers,u.next_steps,u.latitude,u.longitude,u.accuracy FROM st_daily_updates u JOIN st_sites s ON s.id=u.site_id JOIN st_technicians t ON t.id=u.technician_id ORDER BY u.update_date DESC,u.id DESC LIMIT 2000")->fetchAll(PDO::FETCH_NUM);}
    elseif($reportType==='tools'){$headers=['Tool','Code','Serial','Condition','Status','Technician','Site','Action','Date','Latitude','Longitude'];$rows=$pdo->query("SELECT t.tool_name,t.tool_code,t.serial_no,t.condition_status,t.status,tech.full_name,s.site_name,m.action_type,m.created_at,m.latitude,m.longitude FROM st_tools t LEFT JOIN st_tool_movements m ON m.id=(SELECT mm.id FROM st_tool_movements mm WHERE mm.tool_id=t.id ORDER BY mm.id DESC LIMIT 1) LEFT JOIN st_technicians tech ON tech.id=m.technician_id LEFT JOIN st_sites s ON s.id=m.site_id ORDER BY t.tool_name")->fetchAll(PDO::FETCH_NUM);}
    elseif($reportType==='transport'){$headers=['Departure','Vehicle','Type','Driver','Destination','Status','Start Latitude','Start Longitude','End Latitude','End Longitude','Start Odometer','End Odometer'];$rows=$pdo->query('SELECT tr.departure_at,v.registration_no,v.vehicle_type,d.full_name,tr.destination,tr.status,tr.start_latitude,tr.start_longitude,tr.end_latitude,tr.end_longitude,tr.start_odometer,tr.end_odometer FROM st_transport_trips tr JOIN st_vehicles v ON v.id=tr.vehicle_id LEFT JOIN st_drivers d ON d.id=tr.driver_id ORDER BY tr.id DESC LIMIT 2000')->fetchAll(PDO::FETCH_NUM);}
    else{$headers=['Metric','Value'];$rows=[['Sites',(int)$pdo->query('SELECT COUNT(*) FROM st_sites')->fetchColumn()],['Closed Sites',(int)$pdo->query("SELECT COUNT(*) FROM st_sites WHERE status='Closed'")->fetchColumn()],['Technicians',(int)$pdo->query('SELECT COUNT(*) FROM st_technicians')->fetchColumn()],['Tools',(int)$pdo->query('SELECT COUNT(*) FROM st_tools')->fetchColumn()],['Damaged Tools',(int)$pdo->query("SELECT COUNT(*) FROM st_tools WHERE status='Damaged' OR condition_status='Damaged'")->fetchColumn()],['Missing Tools',(int)$pdo->query("SELECT COUNT(*) FROM st_tools WHERE status='Missing' OR condition_status='Missing'")->fetchColumn()],['Vehicles',(int)$pdo->query('SELECT COUNT(*) FROM st_vehicles')->fetchColumn()],['Drivers',(int)$pdo->query('SELECT COUNT(*) FROM st_drivers')->fetchColumn()],['Open Trips',(int)$pdo->query("SELECT COUNT(*) FROM st_transport_trips WHERE status='Open'")->fetchColumn()]];}
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="zantronix-site-'.$reportType.'-'.date('Y-m-d').'.csv"');$out=fopen('php://output','w');fputcsv($out,$headers);foreach($rows as $row)fputcsv($out,$row);fclose($out);exit;
}

if(in_array($page,$stPages,true)){
    if(in_array($page,['st_materials','st_material_report'],true)){ header('Location:index.php?page=st_tools'); exit; }
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
        $editDriver=null;
        if(isset($_GET['edit_driver']) && $page==='st_transport'){
            $q=$pdo->prepare('SELECT * FROM st_drivers WHERE id=?');$q->execute([(int)$_GET['edit_driver']]);$editDriver=$q->fetch(PDO::FETCH_ASSOC);
        }
        $editVehicle=null;
        if(isset($_GET['edit_vehicle']) && $page==='st_transport'){
            $q=$pdo->prepare('SELECT * FROM st_vehicles WHERE id=?');$q->execute([(int)$_GET['edit_vehicle']]);$editVehicle=$q->fetch(PDO::FETCH_ASSOC);
        }
    }

    ?>
<!doctype html>
<html lang="<?=htmlspecialchars($zanLang, ENT_QUOTES, 'UTF-8')?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Site Tracking System</title>
<link rel="stylesheet" href="style.css">
<style>
@media print{.st-head,.st-side,.no-print,.st-main .btn{display:none!important}.st-layout{display:block!important;min-height:0!important}.st-main{max-width:none!important;padding:0!important}.panel,.card{box-shadow:none!important;border:1px solid #bbb!important;break-inside:avoid}table{min-width:0!important;font-size:10px!important}th,td{padding:6px!important}.photo-grid{grid-template-columns:repeat(3,1fr)!important}.photo-card{break-inside:avoid}.st-photo,.gallery-photo{max-width:90px!important;max-height:70px!important}}
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
.camera-field{display:block;padding:12px;border:1px dashed #aaa;border-radius:8px;background:#fafafa;font-weight:700;cursor:pointer}.phone-camera{display:none}.real-camera-button{margin-top:8px}.camera-result{display:block;margin-top:8px;color:#087f23;font-size:12px}.camera-modal{position:fixed;inset:0;z-index:1000;display:none;place-items:center;background:rgba(0,0,0,.78);padding:16px}.camera-modal.open{display:grid}.camera-box{width:min(520px,100%);background:#fff;border-radius:12px;padding:14px}.camera-box video{display:block;width:100%;max-height:65vh;object-fit:cover;background:#111;border-radius:8px}.camera-actions{display:flex;gap:8px;margin-top:10px}.camera-actions button{flex:1}
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
.overdue-row{background:#fff0f0!important;color:#9b1c1c}.warning-row{background:#fff8df!important;color:#7a5800}.ok-row{background:#effaf1!important;color:#176b2c}.wa-btn{background:#25d366!important;color:#fff!important;display:inline-flex!important;align-items:center;gap:5px;border-radius:7px!important;box-shadow:0 1px 5px rgba(37,211,102,.18)}.wa-svg{width:15px;height:15px;display:block;flex:0 0 15px}.customers-modern{border-top:2px solid #ffd000!important;padding:12px 12px 10px!important}.customers-modern .table{min-width:900px}.customers-modern .table th{background:#111!important;color:#ffd000!important;border-bottom:0;text-transform:uppercase;font-size:10px;letter-spacing:.03em}.customers-modern .table td{padding:9px 8px;font-size:12px}.customer-title-row{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:9px}.customer-title{display:flex;align-items:center;gap:9px}.customer-title-icon{width:38px;height:38px;border-radius:50%;background:#ffd000;display:grid;place-items:center;font-size:19px}.customer-title h2{margin:0;font-size:20px}.customer-title p{margin:2px 0 0;color:#6b7280;font-size:11px}.customer-add{font-size:11px}.debt-modern{border-top:2px solid #ffd000!important;margin-top:13px;padding:14px 12px 11px!important}.debt-title{display:flex;align-items:center;gap:9px;margin-bottom:10px}.debt-icon{width:40px;height:40px;border-radius:50%;background:#ffe9e9;display:grid;place-items:center;font-size:19px}.debt-title h2{margin:0;font-size:20px}.debt-title p{margin:2px 0 0;color:#6b7280;font-size:11px}.debt-modern .table{min-width:920px}.debt-modern .table th{background:#111!important;color:#ffd000!important;border-bottom:0;text-transform:uppercase;font-size:10px}.debt-modern .table td{padding:10px 8px;font-size:12px}.debt-balance{font-size:13px}.debt-wa-cell{background:#f0fff6}.debt-wa-btn{min-width:0;justify-content:center;font-size:11px!important;padding:6px 9px!important}.customer-wa-cell{white-space:nowrap}.customer-wa-btn{font-size:11px!important;padding:5px 8px!important}.customer-empty-wa{color:#888;font-size:11px}
.photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:18px}.photo-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:12px;box-shadow:0 2px 10px rgba(0,0,0,.06)}.gallery-photo{width:100%;height:190px;object-fit:cover;border-radius:10px;border:1px solid #ddd}.gps-status{margin-left:8px}.gps-ready{color:#087f23;font-weight:bold}.gps-error{color:#b42318;font-weight:bold}
.team-editor{position:relative;display:inline-block}.team-editor summary{list-style:none}.team-editor summary::-webkit-details-marker{display:none}.team-editor-form{position:absolute;right:0;top:38px;z-index:15;width:240px;padding:12px;background:#fff;border:1px solid #ddd;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.16)}.team-editor-form strong,.team-editor-form label{display:block;margin-bottom:8px}.team-editor-form input{width:auto}
.mobile-menu-button{display:none}.mobile-menu-overlay{display:none}
.quick-icon{min-width:32px;text-align:center;padding:7px!important}.actions .quick-icon{font-size:16px}
.ai-workspace{display:grid;grid-template-columns:minmax(0,1fr);gap:18px;align-items:start}.ai-page{background:#f7f7f5;border-radius:18px;padding:4px 0 18px}.ai-hero{position:relative;overflow:hidden;background:linear-gradient(135deg,#fff8cf 0%,#fff 58%,#fffdf1 100%);border:1px solid #ffe37a;border-radius:18px;padding:24px 28px;margin-bottom:16px;box-shadow:0 8px 24px rgba(0,0,0,.06)}.ai-hero:after{content:'↗';position:absolute;right:28px;bottom:18px;font-size:110px;line-height:1;color:#ffd000;opacity:.10;font-weight:900;transform:rotate(-12deg)}.ai-hero-top{display:flex;align-items:flex-start;gap:18px;position:relative;z-index:1}.ai-hero-avatar{width:68px;height:68px;flex:0 0 68px;display:grid;place-items:center;border-radius:20px;background:#171717;color:#ffd000;font-size:36px;box-shadow:0 8px 18px rgba(0,0,0,.15)}.ai-hero h2{margin:0 0 6px;font-size:26px;color:#171717}.ai-hero p{margin:0;color:#5f6368;line-height:1.55}.ai-feature-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:22px;position:relative;z-index:1}.ai-feature{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #eee7c7;border-radius:12px;padding:12px;text-decoration:none;color:#222;transition:.2s}.ai-feature:hover{transform:translateY(-2px);border-color:#ffd000;box-shadow:0 6px 14px rgba(0,0,0,.07)}.ai-feature-icon{width:36px;height:36px;display:grid;place-items:center;border-radius:10px;background:#ffd000;color:#171717;font-size:18px}.ai-feature b{display:block;font-size:13px}.ai-feature span{display:block;color:#777;font-size:11px;margin-top:2px}.ai-chat-shell{padding:0;overflow:hidden;border:1px solid #e6e6e6;border-radius:18px;box-shadow:0 6px 20px rgba(0,0,0,.07);background:#fff}.ai-chat-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;background:#ffd000;color:#171717}.ai-chat-header-main{display:flex;align-items:center;gap:12px}.ai-chat-avatar{display:grid;place-items:center;width:44px;height:44px;border-radius:14px;background:#171717;color:#ffd000;font-size:23px}.ai-chat-header strong{display:block;font-size:17px}.ai-chat-header small{display:block;opacity:.72;margin-top:3px}.ai-chat-header-actions{display:flex;gap:7px}.ai-chat-header-actions a{color:#171717;text-decoration:none;font-size:12px;padding:8px 10px;background:#fff;border-radius:8px;font-weight:700}.ai-welcome{padding:22px 24px 10px}.ai-welcome h2{margin:0 0 5px;font-size:20px}.ai-welcome p{margin:0;color:#777}.ai-prompts{display:flex;flex-wrap:wrap;gap:8px;padding:10px 24px 18px}.ai-prompt{border:1px solid #e6dfbd;border-radius:18px;padding:8px 12px;background:#fffdf0;color:#4d4732;text-decoration:none;font-size:12px;font-weight:600}.ai-prompt:hover{border-color:#ffd000;background:#fff7bf}.ai-chat-log{display:flex;flex-direction:column;gap:16px;padding:24px;background:#f8f8f7;max-height:560px;min-height:180px;overflow:auto;border-top:1px solid #eee}.ai-chat-message{display:flex;flex-direction:column;gap:6px;border:0;padding:0;background:transparent;max-width:min(76%,620px)}.ai-chat-message:has(.ai-user-message){align-self:flex-end;align-items:flex-end}.ai-chat-message:has(.ai-answer-message){align-self:flex-start;align-items:flex-start}.ai-user-message,.ai-answer-message{padding:12px 16px;border-radius:18px;line-height:1.55;box-shadow:0 1px 2px rgba(0,0,0,.10);white-space:normal;overflow-wrap:anywhere}.ai-user-message{background:#171717;color:#ffd000;border-bottom-right-radius:5px}.ai-answer-message{background:#fff;color:#20242a;border:1px solid #ececec;border-bottom-left-radius:5px}.ai-chat-message>.small{padding:0 7px;color:#888}.ai-chat-form{display:flex;align-items:flex-end;gap:10px!important;margin:0;padding:14px 18px;background:#fff;border-top:1px solid #e5e5e5}.ai-chat-form .field{flex:1;margin:0}.ai-chat-form textarea{min-height:48px;max-height:130px;resize:vertical;border-radius:13px;padding:12px 15px;background:#fafafa;border:1px solid #ddd}.ai-chat-form textarea:focus{outline:2px solid rgba(255,208,0,.35);border-color:#ffd000}.ai-chat-form>div:last-child{display:flex;align-items:flex-end}.ai-chat-form>div:last-child .btn{width:48px;height:48px;padding:0;border-radius:13px;font-size:20px;background:#ffd000!important;color:#171717!important}.ai-action-row{display:flex;gap:8px;flex-wrap:wrap;padding:0 18px 18px}.ai-side-card{display:grid;grid-template-columns:1fr;gap:0;position:static;border-radius:18px;border:1px solid #e7e1bf;background:#fffef5;box-shadow:0 6px 20px rgba(0,0,0,.05)}.ai-side-card h3{margin:0;padding:18px 20px 8px}.ai-side-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin:0;padding:0 20px 18px;list-style:none}.ai-side-list li{display:flex;gap:8px;align-items:flex-start;color:#525866;font-size:13px;background:#fff;border:1px solid #eee9d6;border-radius:10px;padding:10px}.ai-side-list li:before{content:'✓';color:#171717;background:#ffd000;width:19px;height:19px;display:grid;place-items:center;border-radius:50%;font-weight:800;flex:0 0 19px}.ai-data-preview{max-height:180px;overflow:auto;font-size:11px;line-height:1.5;background:#171717;padding:14px 16px;border-radius:12px;color:#f8f8f8;margin:0 20px 20px}.ai-data-live-title{display:flex;align-items:center;justify-content:space-between;padding:4px 20px 12px}.ai-data-live-title span{font-size:11px;color:#777}.ai-data-live-title b{font-size:15px}.ai-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:0 20px 18px}.ai-metric{background:#fff;border:1px solid #ececec;border-radius:12px;padding:13px}.ai-metric .icon{width:32px;height:32px;display:grid;place-items:center;border-radius:9px;background:#ffd000;color:#171717;margin-bottom:8px}.ai-metric span{display:block;color:#777;font-size:11px}.ai-metric b{display:block;font-size:20px;margin-top:2px}.ai-status{margin:0 20px 20px;padding:10px 12px;border-radius:10px;background:#f4f8f0;border:1px solid #dfead7;color:#2c6b31;font-size:12px;display:flex;justify-content:space-between;gap:10px}.ai-status .dot{display:inline-block;width:8px;height:8px;background:#27ae60;border-radius:50%;margin-right:6px}.ai-bottom-grid{display:grid;grid-template-columns:1fr 1.4fr;gap:16px;margin-top:16px}.ai-bottom-card{background:#fff;border:1px solid #e8e8e8;border-radius:16px;box-shadow:0 5px 16px rgba(0,0,0,.05)}.ai-bottom-card h3{margin:0;padding:18px 20px 10px}.ai-help-list{margin:0;padding:0 20px 20px;list-style:none;display:grid;gap:8px}.ai-help-list li{font-size:13px;color:#555;display:flex;gap:9px;line-height:1.4}.ai-help-list li:before{content:'✓';color:#171717;font-weight:900;background:#ffd000;border-radius:50%;width:18px;height:18px;display:grid;place-items:center;flex:0 0 18px}.ai-quote{margin:0 20px 18px;padding:16px;border-radius:13px;background:#fff8c9;color:#4b4528;font-size:14px;line-height:1.5}.ai-page-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.ai-page-head h1{margin:0;font-size:27px}.ai-page-head .muted{margin-top:3px;display:block}

.management-strip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:18px 0}.management-item{background:#fff;border:1px solid #e6e8ec;border-radius:10px;padding:14px}.management-item .label{font-size:11px;color:#69707d;text-transform:uppercase;letter-spacing:.04em}.management-item .value{font-size:20px;font-weight:800;margin-top:6px}.management-actions{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px}.management-actions .btn{font-size:12px}
@media(max-width:900px){.ai-workspace{grid-template-columns:1fr}.ai-side-card{position:static}.management-strip{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:760px){.st-layout{display:block;position:relative}.st-side{width:auto;display:flex;overflow:auto;padding:8px}.st-side a{white-space:nowrap}.st-main{padding:12px}.st-head{font-size:13px;position:sticky;z-index:40}.st-head>span{display:flex;align-items:center;gap:6px;flex-wrap:wrap}.profile{grid-template-columns:1fr}table{min-width:850px}.ai-chat-message{max-width:88%}.ai-chat-header-actions{display:none}.ai-chat-log{padding:16px;min-height:260px}.ai-workspace{gap:12px}.management-strip{grid-template-columns:1fr 1fr;gap:8px}.management-item{padding:11px}.management-item .value{font-size:17px}.mobile-menu-button{display:inline-flex!important;align-items:center;justify-content:center;background:#171717;color:#ffd000;border:0;border-radius:8px;padding:9px 12px;font-size:18px;cursor:pointer;position:relative;z-index:100}.st-side{display:none;position:absolute;top:0;left:0;right:0;z-index:90;background:#171717;padding:12px;box-shadow:0 8px 18px rgba(0,0,0,.25);max-height:75vh;overflow:auto}.st-side.mobile-open,.st-side[style*="display: block"]{display:block!important}.st-side a{white-space:normal}.st-side details a{padding:10px 12px}.st-main{padding-top:16px}.mobile-menu-overlay{position:fixed;inset:0;background:rgba(0,0,0,.28);z-index:80}.mobile-menu-overlay.open{display:block}}
</style>
</head>
<body>
<div class="st-head">
    <button class="mobile-menu-button no-print" type="button" aria-label="Open menu" onclick="toggleMobileMenu(this);return false;">â˜°</button>
    <b style="display:flex;align-items:center;gap:10px"><img src="<?=st_e($settings['logo']??'uploads/logo_1788004312.jpeg')?>" alt="Zantronix logo" style="height:38px;width:auto;max-width:170px;object-fit:contain;background:#fff;padding:3px;border-radius:4px"> <?=zan_t('SITE TRACKING SYSTEM','SITE TRACKING SYSTEM')?></b>
  <span>
    <a href="?page=<?=st_e($page)?>&lang=sw">SW</a> |
    <a href="?page=<?=st_e($page)?>&lang=en">EN</a> |
    <?php if($canBusinessSystem):?><a href="?page=choose_system"><?=zan_t('Badilisha Mfumo','Switch System')?></a> | <?php endif;?>
    <button class="btn no-print" type="button" onclick="window.print()" title="Print this Site Tracking report">ðŸ–¨ <?=zan_t('Chapisha','Print')?></button>
    <a href="?logout=1"><?=zan_t('Toka','Logout')?></a>
  </span>
</div>

<div class="st-layout">
<aside class="st-side">
<?php if(st_is_technician()): ?>
    <a href="?page=st_portal">ðŸ  <?=zan_t('Dashboard / Portal ya Fundi','Dashboard / Technician Portal')?></a>
    <a href="?page=st_ai_assistant">ðŸ¤– AI Assistant</a>
    <details open><summary>ðŸ“ <?=zan_t('Sites','Sites')?></summary><a href="?page=st_sites">Orodha ya Sites Zangu</a><a href="?page=st_site_gallery">ðŸ“¸ Photos + GPS</a></details>
    <details open><summary>ðŸ”„ <?=zan_t('Movements','Movements')?></summary><a href="?page=st_movements">🔧 Tools Zangu</a><a href="?page=st_portal#tools">↩️ Rudisha Tools</a><a href="?page=st_portal#money">💸 Pesa / Risiti</a></details><details open><summary>🚗 Usafiri</summary><a href="?page=st_portal#transport">Safari Nilizopewa</a><a href="?page=st_portal#notifications">Notifications</a></details>
<?php elseif(st_is_driver()): ?>
    <a href="?page=st_driver_portal">ðŸ  Driver Portal</a><details open><summary>🚗 Usafiri</summary><a href="?page=st_driver_portal#trips">Safari Zangu</a><a href="?page=st_driver_portal#money">Pesa / Risiti</a><a href="?page=st_driver_portal#requests">Requests</a><a href="?page=st_driver_portal#notifications">Notifications</a></details>
<?php else: ?>
    <?php if(st_admin_can($pdo,'dashboard')):?><a href="?page=site_tracking">🏠 Dashibodi</a><?php endif;?>
    <?php if(st_admin_can($pdo,'dashboard')):?><a href="?page=st_ai_assistant">🤖 AI Assistant</a><?php endif;?>
    <?php if(st_admin_can($pdo,'tasks')):?><a href="?page=st_assignment_center">🎯 Assignment Center</a><?php endif;?>
    <?php if(st_admin_can($pdo,'sites')):?><a href="?page=st_sites">📍 Sites</a><?php endif;?>
    <?php if(st_admin_can($pdo,'technicians')):?><a href="?page=st_technicians">👷 Mafundi</a><?php endif;?>
    <?php if(st_admin_can($pdo,'vehicles')):?><a href="?page=st_transport&section=dashboard">🚗 Usafiri</a><?php endif;?>
    <?php if(st_admin_can($pdo,'tools')):?><a href="?page=st_tools">Tools</a><?php endif;?>
    <?php if(st_admin_can($pdo,'money_transfer')):?><a href="?page=st_money_transfer">💸 Money / Transactions</a><?php endif;?>
    <?php if(st_admin_can($pdo,'approvals')):?><a href="?page=st_approvals">✅ Approvals</a><?php endif;?>
    <?php if(st_admin_can($pdo,'reports')):?><a href="?page=st_reports">📊 Reports</a><?php endif;?>
    <?php if(st_admin_can($pdo,'daily_reports')):?><a href="?page=st_daily_reports">📚 Daily Reports</a><?php endif;?>
    <?php if(st_admin_can($pdo,'activity')):?><a href="?page=st_activity">📝 Activity</a><?php endif;?>
    <?php if(st_admin_can($pdo,'administration')):?><a href="?page=st_administration">🏢 Administration</a><a href="?page=st_settings">⚙️ Settings</a><?php endif;?>
<?php endif;?>
</aside>

<main class="st-main">
<?php if($flash):?><div class="notice"><?=st_e($flash)?></div><?php endif;?>

<?php if($page==='st_driver_portal' && st_is_driver()): ?>
<?php
    $driver=st_current_driver($pdo);$driverTrips=[];$driverRequests=[];$driverTasks=[];$myNotifications=[];
    if($driver){
        $q=$pdo->prepare("SELECT tr.*,v.registration_no,v.vehicle_type,v.make_model,d.full_name driver_name FROM st_transport_trips tr JOIN st_vehicles v ON v.id=tr.vehicle_id LEFT JOIN st_drivers d ON d.id=tr.driver_id WHERE tr.driver_id=? ORDER BY tr.id DESC LIMIT 50");$q->execute([(int)$driver['id']]);$driverTrips=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$pdo->prepare('SELECT * FROM st_transport_requests WHERE driver_id=? ORDER BY id DESC LIMIT 50');$q->execute([(int)$driver['id']]);$driverRequests=$q->fetchAll(PDO::FETCH_ASSOC);
        $q=$pdo->prepare("SELECT t.*,s.site_name FROM st_site_tasks t JOIN st_sites s ON s.id=t.site_id WHERE t.assigned_to_type='driver' AND t.assigned_to=? ORDER BY t.id DESC LIMIT 50");$q->execute([(int)$driver['id']]);$driverTasks=$q->fetchAll(PDO::FETCH_ASSOC);
        $myNotifications=st_driver_notifications($pdo,(int)$driver['id']);
    }
    $driverId=(int)($driver['id']??0);
    $driverMoney=[];if($driver){$q=$pdo->prepare("SELECT m.*,s.site_name FROM st_money_transactions m LEFT JOIN st_sites s ON s.id=m.site_id WHERE m.recipient_type='driver' AND m.recipient_id=? ORDER BY m.id DESC LIMIT 30");$q->execute([$driverId]);$driverMoney=$q->fetchAll(PDO::FETCH_ASSOC);}
    $driverDefectiveVehicles=$pdo->query("SELECT * FROM st_vehicles WHERE status IN ('Broken Down','Defective Vehicle') ORDER BY registration_no")->fetchAll(PDO::FETCH_ASSOC);
    $driverNew=(int)$pdo->query("SELECT COUNT(*) FROM st_site_tasks WHERE assigned_to_type='driver' AND assigned_to=$driverId AND status IN ('Pending','New')")->fetchColumn();
    $driverActive=(int)$pdo->query("SELECT COUNT(*) FROM st_site_tasks WHERE assigned_to_type='driver' AND assigned_to=$driverId AND status='In Progress'")->fetchColumn();
    $driverBlocked=(int)$pdo->query("SELECT COUNT(*) FROM st_site_tasks WHERE assigned_to_type='driver' AND assigned_to=$driverId AND status='Blocked'")->fetchColumn();
    $driverDone=(int)$pdo->query("SELECT COUNT(*) FROM st_site_tasks WHERE assigned_to_type='driver' AND assigned_to=$driverId AND status IN ('Completed','Verified')")->fetchColumn();
?>
<div class="driver-profile-card">
  <div class="driver-profile-photo"><?=st_photo($driver['photo']??'','passport')?></div>
  <div class="driver-profile-main"><div class="small" style="opacity:.8">WASIFU WA DEREVA</div><h2><?=st_e($driver['full_name']??'')?></h2><div class="driver-profile-meta"><span>Simu: <?=st_e($driver['phone']??'-')?></span><span>Leseni: <?=st_e($driver['license_no']??'-')?></span><span>Expiry: <?=st_e($driver['license_expiry']??'-')?></span><span>Status: <?=st_e($driver['status']??'-')?></span></div></div>
</div>
<div class="driver-menu-wrap"><div class="driver-menu-title">MENU YA DEREVA</div><div class="driver-menu-slider" id="driverMenu">
  <button type="button" class="driver-menu-btn active" data-driver-target="driver-profile">Wasifu</button>
  <button type="button" class="driver-menu-btn" data-driver-target="trips">Safari Zangu</button>
  <button type="button" class="driver-menu-btn" data-driver-target="driver-defective">Defective Vehicle</button>
  <button type="button" class="driver-menu-btn" data-driver-target="driver-requests">Requests</button>
  <button type="button" class="driver-menu-btn" data-driver-target="driver-tasks">Kazi</button>
  <button type="button" class="driver-menu-btn" data-driver-target="money">Pesa / Risiti</button>
  <button type="button" class="driver-menu-btn" data-driver-target="notifications">Notifications</button>
</div></div>
<div class="panel driver-menu-section" id="driver-profile" data-driver-section="driver-profile"><h3>Wasifu Wangu</h3><div class="grid2 driver-profile-details"><div><b>Jina</b><br><?=st_e($driver['full_name']??'-')?></div><div><b>Simu</b><br><?=st_e($driver['phone']??'-')?></div><div><b>Leseni</b><br><?=st_e($driver['license_no']??'-')?></div><div><b>Leseni inaisha</b><br><?=st_e($driver['license_expiry']??'-')?></div></div></div>
<div class="grid2 driver-menu-section" id="driver-summary" data-driver-section="driver-profile" style="grid-template-columns:repeat(4,minmax(0,1fr));gap:10px"><div class="panel"><b>NEW</b><h2 style="margin:6px 0"><?=$driverNew?></h2></div><div class="panel"><b>IN PROGRESS</b><h2 style="margin:6px 0"><?=$driverActive?></h2></div><div class="panel"><b>BLOCKED</b><h2 style="margin:6px 0"><?=$driverBlocked?></h2></div><div class="panel"><b>DONE</b><h2 style="margin:6px 0"><?=$driverDone?></h2></div></div>
<div class="panel driver-menu-section" id="trips" data-driver-section="trips"><h3>ðŸ§­ Safari Zangu</h3><div class="table-wrap"><table><tr><th>Tarehe</th><th>Chombo</th><th>Destination</th><th>Status</th><th>GPS</th></tr><?php foreach($driverTrips as $trip): ?><tr><td><?=st_e($trip['departure_at'])?></td><td><?=st_e($trip['registration_no'].' - '.$trip['vehicle_type'].' '.$trip['make_model'])?></td><td><?=st_e($trip['destination'])?></td><td><span class="status"><?=st_e($trip['status'])?></span></td><td><?php if($trip['start_latitude']!==null&&$trip['start_longitude']!==null): ?><a class="btn small" target="_blank" href="<?=st_e(st_google_map_url($trip['start_latitude'],$trip['start_longitude']))?>">Start Map</a><?php endif; ?><?php if($trip['end_latitude']!==null&&$trip['end_longitude']!==null): ?><a class="btn small" target="_blank" href="<?=st_e(st_google_map_url($trip['end_latitude'],$trip['end_longitude']))?>">End Map</a><?php endif; ?><?php if($trip['status']==='Open'): ?><form method="post" class="gps-form gps-submit-form" style="margin-top:6px"><input type="hidden" name="st_action" value="st_mark_transport_arrival"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$trip['id']?>"><input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><button type="submit" class="btn small gps-submit-btn">📍 NIMEFIKA</button><span class="gps-status small">GPS optional</span></form><form method="post" class="gps-form gps-submit-form" style="margin-top:6px"><input type="hidden" name="st_action" value="st_return_transport_to_office"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$trip['id']?>"><input type="number" step="0.1" name="end_odometer" placeholder="End odometer" required><input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><button type="submit" class="btn small gps-submit-btn">🏁 RUDISHA OFISINI</button><span class="gps-status small">GPS optional</span></form><?php endif; ?></td></tr><tr><td colspan="5"><b>Mafundi:</b> <?php $dq=$pdo->prepare('SELECT t.full_name FROM st_transport_trip_technicians tt JOIN st_technicians t ON t.id=tt.technician_id WHERE tt.trip_id=? ORDER BY t.full_name');$dq->execute([(int)$trip['id']]);echo st_e(implode(', ',$dq->fetchAll(PDO::FETCH_COLUMN))); ?></td></tr><?php endforeach; ?><?php if(!$driverTrips): ?><tr><td colspan="5">Hakuna safari iliyoidhinishwa bado.</td></tr><?php endif; ?></table></div></div>
<div class="panel driver-menu-section" id="driver-defective" data-driver-section="driver-defective"><h3>Defective Vehicle</h3><p class="muted">Magari / pikipiki zenye hitilafu. Chombo kilicho hapa hakipaswi kutumika mpaka Administrator abadilishe status.</p><div class="table-wrap"><table><tr><th>Usajili</th><th>Aina</th><th>Model</th><th>Status</th><th>Maelezo</th></tr><?php foreach($driverDefectiveVehicles as $dv):?><tr><td><b><?=st_e($dv['registration_no'])?></b></td><td><?=st_e($dv['vehicle_type'])?></td><td><?=st_e($dv['make_model'])?></td><td><span class="status"><?=st_e($dv['status'])?></span></td><td><?=st_e($dv['notes']??'-')?></td></tr><?php endforeach;?><?php if(!$driverDefectiveVehicles):?><tr><td colspan="5">Hakuna defective vehicle kwa sasa.</td></tr><?php endif;?></table></div></div>
<div class="panel driver-menu-section" data-driver-section="driver-requests"><h3>ðŸ“ Omba Msaada wa Usafiri</h3><form method="post" enctype="multipart/form-data" class="grid2 gps-form"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="st_action" value="st_submit_transport_request"><div class="field"><select name="trip_id"><option value="">Chagua safari (optional)</option><?php foreach($driverTrips as $trip): ?><option value="<?=$trip['id']?>"><?=st_e($trip['departure_at'].' - '.$trip['destination'])?></option><?php endforeach; ?></select></div><div class="field"><select name="request_type" required><option value="">Aina ya ombi</option><option>Fuel</option><option>Maintenance</option><option>Breakdown</option><option>Other</option></select></div><div class="field"><input type="number" step="0.01" min="0" name="amount" placeholder="Kiasi (kwa mafuta/gharama)"></div><div class="field full"><textarea name="description" placeholder="Eleza unachohitaji, mfano mafuta, tyre imepasuka, service, engine problem..." required></textarea></div><label class="camera-field">ðŸ“· Picha ya ushahidi (optional)<input class="phone-camera" type="file" name="photo" accept="image/*" capture="environment"></label><input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><button type="button" class="btn gps-btn">ðŸ“ PATA GPS</button><span class="gps-status small">GPS optional</span><button class="btn">TUMA REQUEST</button></form></div>
<div class="panel driver-menu-section" id="notifications" data-driver-section="notifications"><h3>ðŸ”” Notifications</h3><?php foreach($myNotifications as $notification):?><div class="alert-row"><span><?=st_e($notification['message'])?></span><small><?=st_e($notification['created_at'])?></small></div><?php endforeach;?><?php if(!$myNotifications):?><p class="muted">Hakuna notification kwa sasa.</p><?php endif;?></div>
<div class="panel driver-menu-section" id="driver-tasks" data-driver-section="driver-tasks"><h3>✓ Tasks Zangu</h3><p class="muted">Kubali task, anza, weka Blocked ikiwa kuna tatizo, au maliza task baada ya kazi.</p><div class="table-wrap"><table><tr><th>Site</th><th>Task</th><th>Priority</th><th>Due</th><th>Status</th><th>Kitendo</th></tr><?php foreach($driverTasks as $task): $ts=(string)($task['status']??'Pending'); if($ts==='Pending')$ts='New'; ?><tr><td><?=st_e($task['site_name'])?></td><td><?=st_e($task['task_title'])?><div class="small"><?=st_e($task['notes']??'')?></div><?php if(!empty($task['blocked_reason'])):?><div class="small"><b>Blocked:</b> <?=st_e($task['blocked_reason'])?></div><?php endif;?></td><td><?=st_e($task['priority']??'Normal')?></td><td><?=st_e($task['due_at']?:'-')?></td><td><span class="status"><?=st_e($ts)?></span></td><td><form method="post" class="actions"><input type="hidden" name="st_action" value="st_update_site_task"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="id" value="<?=$task['id']?>"><?php if($ts==='New'):?><button class="btn small" name="task_action" value="accept">KUBALI</button><?php elseif($ts==='Accepted'):?><button class="btn small" name="task_action" value="start">ANZA KAZI</button><?php elseif(in_array($ts,['In Progress','Blocked'],true)):?><button class="btn small" name="task_action" value="complete">MALIZA KAZI</button><button class="btn danger small" name="task_action" value="block" onclick="var r=prompt('Sababu ya ku-block task:');if(!r)return false;this.form.blocked_reason.value=r;">BLOCK</button><input type="hidden" name="blocked_reason" value=""><input name="update_notes" placeholder="Maelezo (optional)" class="small-input"><?php endif;?></form></td></tr><?php endforeach;?><?php if(!$driverTasks):?><tr><td colspan="6">Hakuna task ulizopewa.</td></tr><?php endif;?></table></div></div>
<?php $driverDebt=array_filter($driverMoney,function($m){return in_array((string)($m['status']??''),['Granted','Rejected'],true);});$driverDebtTotal=0;foreach($driverDebt as $dm)$driverDebtTotal+=(float)$dm['amount'];?>
<div class="panel driver-menu-section" id="money" data-driver-section="money"><h3>💸 Pesa Nilizopewa</h3><?php $driverDebt=array_filter($driverMoney,function($m){return in_array((string)($m['status']??''),['Granted','Rejected'],true);});$driverDebtTotal=0;foreach($driverDebt as $dm)$driverDebtTotal+=(float)$dm['amount'];?><?php if($driverDebt):?><div class="notice" style="border-left:4px solid #b91c1c"><b>DENI:</b> <?=count($driverDebt)?> transfer bado haijafungwa. Jumla: <b><?=st_e(number_format($driverDebtTotal,2).' TZS')?></b>.</div><?php endif;?><div class="table-wrap"><table><tr><th>Site</th><th>Kiasi</th><th>Purpose</th><th>Aliyetuma</th><th>Aliyeidhinisha</th><th>Amepokea</th><th>Status / Action</th></tr><?php foreach($driverMoney as $m):?><tr><td><?=st_e($m['site_name']??'Bila Site')?></td><td><b><?=st_e($m['amount'].' '.$m['currency'])?></b></td><td><?=st_e($m['purpose'])?></td><td><?=st_e($m['granted_by']??'')?></td><td><?=st_e($m['approved_by']??'—')?></td><td><?=!empty($m['received_at'])?st_e(($m['received_by']??'').' @ '.$m['received_at']):'BADO'?></td><td><?php if((string)$m['status']==='Granted'):?><span class="status">PESA IMETUMWA</span><form method="post" style="margin-top:6px"><input type="hidden" name="st_action" value="st_confirm_money_received"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="money_id" value="<?=$m['id']?>"><button class="btn small">THIBITISHA NIMEPOKEA</button></form><?php elseif((string)$m['status']==='Received'):?><span class="status">IMEEPOKELEWA</span><form method="post" enctype="multipart/form-data" class="gps-form" style="margin-top:6px"><input type="hidden" name="st_action" value="st_submit_money_receipt"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="money_id" value="<?=$m['id']?>"><label class="camera-field">📷 Piga picha ya risiti<input class="phone-camera" type="file" name="receipt_photo" accept="image/*" capture="environment" required></label><input name="receipt_notes" placeholder="Maelezo ya risiti" required><button type="submit" class="btn small">TUMA RISITI</button></form><?php elseif((string)$m['status']==='Rejected'):?><span class="status">RISITI IMERUDISHWA</span><form method="post" enctype="multipart/form-data" class="gps-form" style="margin-top:6px"><input type="hidden" name="st_action" value="st_submit_money_receipt"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="money_id" value="<?=$m['id']?>"><label class="camera-field">📷 Tuma risiti tena<input class="phone-camera" type="file" name="receipt_photo" accept="image/*" capture="environment" required></label><input name="receipt_notes" placeholder="Maelezo ya risiti" required><button type="submit" class="btn small">TUMA TENA</button></form><?php else:?><span class="status"><?=st_e($m['status'])?></span><?php if(!empty($m['receipt_photo'])):?> <?=st_photo($m['receipt_photo'])?><?php endif;?><?php endif;?></td></tr><?php endforeach;?><?php if(!$driverMoney):?><tr><td colspan="7">Hakuna pesa ulizopewa.</td></tr><?php endif;?></table></div></div><div class="panel driver-menu-section" id="requests" data-driver-section="driver-requests"><h3>ðŸ“‹ Requests Zangu</h3><div class="table-wrap"><table><tr><th>Tarehe</th><th>Aina</th><th>Maelezo</th><th>Kiasi</th><th>Status</th></tr><?php foreach($driverRequests as $request): ?><tr><td><?=st_e($request['created_at'])?></td><td><?=st_e($request['request_type'])?></td><td><?=st_e($request['description'])?></td><td><?=st_e(number_format((float)$request['amount'],2))?></td><td><?=st_e($request['status'])?></td></tr><?php endforeach; ?><?php if(!$driverRequests): ?><tr><td colspan="5">Hakuna request bado.</td></tr><?php endif; ?></table></div></div>

<style>
.driver-profile-card{display:flex;gap:18px;align-items:center;background:#171717;color:#fff;border-radius:18px;padding:18px;margin-bottom:14px;box-shadow:0 8px 24px rgba(0,0,0,.12)}
.driver-profile-photo img,.driver-profile-photo .photo-thumb{width:92px;height:112px;object-fit:cover;border-radius:12px;border:2px solid #ffd000;display:block}.driver-profile-photo{width:92px;min-width:92px}.driver-profile-main h2{margin:4px 0 10px}.driver-profile-meta{display:flex;gap:10px;flex-wrap:wrap;font-size:13px}.driver-profile-meta span{background:rgba(255,255,255,.09);padding:6px 9px;border-radius:8px}.driver-menu-wrap{position:sticky;top:60px;z-index:20;background:#fff;border:1px solid #e5e5e5;border-radius:14px;padding:10px;margin-bottom:14px;box-shadow:0 5px 16px rgba(0,0,0,.06)}.driver-menu-title{font-weight:900;font-size:12px;margin:0 0 7px}.driver-menu-slider{display:flex;gap:8px;overflow-x:auto;overflow-y:hidden;white-space:nowrap;padding-bottom:3px;scrollbar-width:thin}.driver-menu-btn{border:1px solid #ddd;background:#f7f7f7;border-radius:10px;padding:10px 15px;font-weight:800;cursor:pointer;flex:0 0 auto}.driver-menu-btn.active{background:#ffd000;border-color:#ffd000}.driver-menu-section[hidden]{display:none!important}.driver-profile-details>div{padding:12px;border:1px solid #eee;border-radius:10px;background:#fafafa}@media(max-width:700px){.driver-profile-card{align-items:flex-start}.driver-profile-photo img,.driver-profile-photo .photo-thumb{width:78px;height:96px}.driver-profile-photo{width:78px;min-width:78px}.driver-profile-meta{display:grid;grid-template-columns:1fr;gap:6px}.driver-menu-wrap{top:56px}.driver-profile-details{grid-template-columns:1fr!important}}
</style>
<script>(function(){function showDriverSection(id,updateHash){var sections=document.querySelectorAll('[data-driver-section]');sections.forEach(function(el){el.hidden=(el.getAttribute('data-driver-section')!==id);});document.querySelectorAll('[data-driver-target]').forEach(function(btn){btn.classList.toggle('active',btn.getAttribute('data-driver-target')===id);});var active=document.querySelector('[data-driver-target="'+id+'"]');if(active&&active.scrollIntoView)active.scrollIntoView({behavior:'smooth',block:'nearest',inline:'center'});if(updateHash&&history.replaceState)history.replaceState(null,'','#'+id);}document.querySelectorAll('[data-driver-target]').forEach(function(btn){btn.addEventListener('click',function(){showDriverSection(btn.getAttribute('data-driver-target'),true);});});var hash=(location.hash||'').replace('#','');var valid=document.querySelector('[data-driver-target="'+hash+'"]');showDriverSection(valid?hash:'driver-profile',false);})();</script>
<?php elseif($page==='st_portal' && st_is_technician()): ?>

  <?php
  $tid=(int)$ct['id'];
    $q=$pdo->prepare("SELECT s.*,leader.full_name AS leader_name,
                                        GROUP_CONCAT(CASE WHEN team.id<>s.technician_id THEN team.full_name END, ', ') AS team_names
                                        FROM st_sites s
                                        LEFT JOIN st_technicians leader ON leader.id=s.technician_id
                                        LEFT JOIN st_site_technicians members ON members.site_id=s.id
                                        LEFT JOIN st_technicians team ON team.id=members.technician_id
                    WHERE s.id IN (SELECT site_id FROM st_site_technicians WHERE technician_id=?)
                                        GROUP BY s.id ORDER BY s.id DESC");
  $q->execute([$tid]);$mySites=$q->fetchAll(PDO::FETCH_ASSOC);

    $q=$pdo->prepare("SELECT t.*,s.site_name FROM st_site_tasks t JOIN st_sites s ON s.id=t.site_id WHERE t.assigned_to_type='technician' AND t.assigned_to=? AND t.site_id IN (SELECT site_id FROM st_site_technicians WHERE technician_id=?) ORDER BY t.id DESC LIMIT 50");
    $q->execute([$tid,$tid]);$myTasks=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$pdo->prepare("SELECT tr.*,tr.driver_arrived_at AS arrived_at,v.registration_no,v.vehicle_type,v.make_model FROM st_transport_trips tr JOIN st_transport_trip_technicians tt ON tt.trip_id=tr.id JOIN st_vehicles v ON v.id=tr.vehicle_id WHERE tt.technician_id=? ORDER BY tr.id DESC LIMIT 50");$q->execute([$tid]);$techTrips=$q->fetchAll(PDO::FETCH_ASSOC);

  $q=$pdo->prepare("SELECT tm.*,t.tool_name,t.tool_code,s.site_name
                    FROM st_tool_movements tm
                    LEFT JOIN st_tools t ON t.id=tm.tool_id
                    LEFT JOIN st_sites s ON s.id=tm.site_id
                    WHERE tm.technician_id=? ORDER BY tm.id DESC LIMIT 30");
  $q->execute([$tid]);$myTools=$q->fetchAll(PDO::FETCH_ASSOC);

  // Tools currently held by this technician: latest movement for each tool is Issued.
  $q=$pdo->prepare("SELECT tm.*,t.tool_name,t.tool_code,t.serial_no,s.site_name
                    FROM st_tool_movements tm
                    JOIN st_tools t ON t.id=tm.tool_id
                    LEFT JOIN st_sites s ON s.id=tm.site_id
                    WHERE tm.technician_id=? AND tm.action_type='Issued'
                      AND NOT EXISTS (SELECT 1 FROM st_tool_movements r WHERE r.tool_id=tm.tool_id AND r.technician_id=tm.technician_id AND r.action_type='Returned' AND r.id>tm.id)
                    ORDER BY tm.id DESC");
  $q->execute([$tid]);$myHeldTools=$q->fetchAll(PDO::FETCH_ASSOC);

    $q=$pdo->prepare("SELECT f.*,s.site_name FROM st_material_funds f LEFT JOIN st_sites s ON s.id=f.site_id WHERE f.technician_id=? ORDER BY f.id DESC LIMIT 50");
    $q->execute([$tid]);$myMaterialFunds=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$pdo->prepare("SELECT u.*,s.site_name FROM st_daily_updates u JOIN st_sites s ON s.id=u.site_id WHERE u.technician_id=? ORDER BY u.update_date DESC LIMIT 30");
    $q->execute([$tid]);$myDailyUpdates=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$pdo->prepare("SELECT tr.*,v.registration_no,v.vehicle_type,v.make_model,d.full_name driver_name FROM st_transport_trips tr JOIN st_transport_trip_technicians tt ON tt.trip_id=tr.id JOIN st_vehicles v ON v.id=tr.vehicle_id LEFT JOIN st_drivers d ON d.id=tr.driver_id WHERE tt.technician_id=? ORDER BY tr.departure_at DESC LIMIT 30");
    $q->execute([$tid]);$myTransportTrips=$q->fetchAll(PDO::FETCH_ASSOC);
    $myNotifications=st_technician_notifications($pdo,$tid);
    $techNew=(int)$pdo->query("SELECT COUNT(*) FROM st_site_tasks WHERE assigned_to_type='technician' AND assigned_to=$tid AND status IN ('Pending','New')")->fetchColumn();
    $techActive=(int)$pdo->query("SELECT COUNT(*) FROM st_site_tasks WHERE assigned_to_type='technician' AND assigned_to=$tid AND status='In Progress'")->fetchColumn();
    $techBlocked=(int)$pdo->query("SELECT COUNT(*) FROM st_site_tasks WHERE assigned_to_type='technician' AND assigned_to=$tid AND status='Blocked'")->fetchColumn();
    $techDone=(int)$pdo->query("SELECT COUNT(*) FROM st_site_tasks WHERE assigned_to_type='technician' AND assigned_to=$tid AND status IN ('Completed','Verified')")->fetchColumn();
  ?>
  <h2>ðŸ‘· <?=zan_t('Technician Portal','Technician Portal')?></h2>
  <div class="grid2" style="grid-template-columns:repeat(4,minmax(0,1fr));gap:10px"><div class="panel"><b>NEW</b><h2 style="margin:6px 0"><?=$techNew?></h2></div><div class="panel"><b>IN PROGRESS</b><h2 style="margin:6px 0"><?=$techActive?></h2></div><div class="panel"><b>BLOCKED</b><h2 style="margin:6px 0"><?=$techBlocked?></h2></div><div class="panel"><b>DONE</b><h2 style="margin:6px 0"><?=$techDone?></h2></div></div>

    <div class="panel"><h3>ðŸš— Usafiri Niliopewa</h3><p class="muted">Hapa utaona gari/pikipiki, dereva, muda wa kuondoka na destination baada ya safari kuidhinishwa.</p><div class="table-wrap"><table><tr><th>Tarehe</th><th>Chombo</th><th>Dereva</th><th>Destination</th><th>Status</th><th>Map</th></tr><?php foreach($myTransportTrips as $trip): ?><tr><td><?=st_e($trip['departure_at'])?></td><td><?=st_e($trip['registration_no'].' - '.$trip['vehicle_type'].' '.$trip['make_model'])?></td><td><?=st_e($trip['driver_name']?:'Hakuna dereva')?></td><td><?=st_e($trip['destination'])?></td><td><?=st_e($trip['status'])?></td><td><?php if($trip['start_latitude']!==null&&$trip['start_longitude']!==null): ?><a class="btn small" target="_blank" href="<?=st_e(st_google_map_url($trip['start_latitude'],$trip['start_longitude']))?>">Google Map</a><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$myTransportTrips): ?><tr><td colspan="6">Hakuna safari ya usafiri uliyopewa.</td></tr><?php endif; ?></table></div></div>

    <?php if($myNotifications): ?><div class="panel"><h3>ðŸ”” Notifications</h3><?php foreach($myNotifications as $notification):?><div class="alert-row"><span><?=st_e($notification['message'])?></span><small><?=st_e($notification['created_at'])?></small></div><?php endforeach;?></div><?php endif; ?>

  <div class="panel"><h3>✓ Tasks Zangu</h3><p class="muted">New → Accepted → In Progress → Completed → Verified. Ukikwama tumia BLOCK na eleza sababu.</p><div class="table-wrap"><table><tr><th>Site</th><th>Task</th><th>Type</th><th>Priority</th><th>Due</th><th>Status</th><th>Kitendo</th></tr><?php foreach($myTasks as $task): $ts=(string)($task['status']??'Pending'); if($ts==='Pending')$ts='New'; ?><tr><td><?=st_e($task['site_name'])?></td><td><?=st_e($task['task_title'])?><div class="small"><?=st_e($task['notes']??'')?></div><?php if(!empty($task['blocked_reason'])):?><div class="small"><b>Blocked:</b> <?=st_e($task['blocked_reason'])?></div><?php endif;?></td><td><?=st_e($task['task_type']??'site')?></td><td><?=st_e($task['priority']??'Normal')?></td><td><?=st_e($task['due_at']?:'-')?></td><td><span class="status"><?=st_e($ts)?></span></td><td><form method="post" class="actions"><input type="hidden" name="st_action" value="st_update_site_task"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="id" value="<?=$task['id']?>"><?php if($ts==='New'):?><button class="btn small" name="task_action" value="accept">KUBALI</button><?php elseif($ts==='Accepted'):?><button class="btn small" name="task_action" value="start">ANZA KAZI</button><?php elseif(in_array($ts,['In Progress','Blocked'],true)):?><button class="btn small" name="task_action" value="complete">MALIZA KAZI</button><button class="btn danger small" name="task_action" value="block" onclick="var r=prompt('Sababu ya ku-block task:');if(!r)return false;this.form.blocked_reason.value=r;">BLOCK</button><input type="hidden" name="blocked_reason" value=""><input name="update_notes" placeholder="Maelezo (optional)" class="small-input"><?php endif;?></form></td></tr><?php endforeach;?><?php if(!$myTasks):?><tr><td colspan="7">Hakuna task ulizopewa.</td></tr><?php endif;?></table></div></div>

  <div class="panel profile">
    <div>
      <?php if($ct['photo']):?><?=st_photo($ct['photo'],'passport')?><?php else:?><div class="passport" style="width:110px;height:110px;border-radius:12px;background:#eee;display:grid;place-items:center;font-size:42px">ðŸ‘·</div><?php endif;?>
    </div>
    <div>
      <h2 style="margin-top:0"><?=st_e($ct['full_name'])?></h2>
      <p><b>Technician No:</b> <?=st_e($ct['technician_no'])?></p>
      <p><b>Phone:</b> <?=st_e($ct['phone'])?></p>
      <p><b>Office:</b> <?=st_e($ct['office']??'')?></p>
      <p><b>Specialization:</b> <?=st_e($ct['specialization'])?></p>
      <p><b>Status:</b> <span class="status"><?=st_e($ct['status'])?></span></p>
    </div>
  </div>

    <div class="panel">
        <h3>ðŸ” Badilisha Password</h3>
        <p class="muted">Password ya sasa haiwezi kuonyeshwa kwa sababu imehifadhiwa kwa usalama. Unaweza kuweka password mpya hapa.</p>
        <form method="post" class="grid2">
            <input type="hidden" name="st_action" value="st_change_own_password">
            <input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>">
            <div class="field"><input type="password" name="current_password" placeholder="Password ya sasa" required></div>
            <div class="field"><input type="password" name="new_password" minlength="6" placeholder="Password mpya" required></div>
            <div class="field"><input type="password" name="confirm_password" minlength="6" placeholder="Thibitisha password mpya" required></div>
            <div><button class="btn" type="submit">HIFADHI PASSWORD</button></div>
        </form>
    </div>

  <h3>&#127959; <?=zan_t('Sites ulizopewa','Assigned Sites')?></h3>
  <div class="table-wrap panel"><table>
    <tr><th>Site</th><th>Customer</th><th>Location</th><th>Site Leader</th><th>Other Technicians</th><th>Status</th><th>Action</th></tr>
    <?php foreach($mySites as $s):?>
      <tr>
        <td><?=st_e($s['site_name'])?></td><td><?=st_e($s['customer_name'])?></td><td><?=st_e($s['location'])?></td>
        <td><?=st_e($s['leader_name']??$ct['full_name'])?></td><td><?=st_e($s['team_names']?:'Hakuna wengine')?></td><td><span class="status"><?=st_e($s['status'])?></span></td>
        <td><?php if($s['status']!=='Closed'):?><form method="post" enctype="multipart/form-data" class="actions gps-form">
          <input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="st_action" value="st_complete_site"><input type="hidden" name="site_id" value="<?=$s['id']?>">
          <label class="camera-field">ðŸ“· Picha ya kumaliza kazi<input class="phone-camera" type="file" name="completion_photo" accept="image/*" capture="environment" required></label>
          <div class="photo-upload-list" data-photo-list="jobcard_photos"><label class="camera-field">ðŸ§¾ Picha ya Jobcard iliyosainiwa<input class="phone-camera" type="file" name="jobcard_photo" accept="image/*" capture="environment" required></label></div>
          <button type="button" class="btn small add-photo-btn" data-photo-target="jobcard_photos" data-photo-name="jobcard_photos[]">âž• ONGEZA PICHA YA JOBCARD</button>
          <input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><input type="hidden" name="captured_at" class="gps-time">
          <button type="button" class="btn gps-btn">ðŸ“ <?=zan_t('Pata Location','Get Location')?></button> <span class="gps-status small"><?=zan_t('Location ni optional','Location is optional')?></span>
          <input name="completion_notes" placeholder="Completion notes" required>
          <button class="btn">SUBMIT COMPLETION</button>
                </form>
                <?php if($s['status']!=='Closed'):?><details style="margin-top:10px"><summary class="btn small">ðŸ¤ KABIDHI SITE</summary>
                    <form method="post" enctype="multipart/form-data" class="gps-form" style="margin-top:10px">
                        <input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="st_action" value="st_handover_site"><input type="hidden" name="site_id" value="<?=$s['id']?>">
                        <label class="camera-field">ðŸ¤ Picha ya makabidhiano<input class="phone-camera" type="file" name="handover_photo" accept="image/*" capture="environment" required></label>
                        <div class="photo-upload-list"><label class="camera-field">ðŸ§¾ Picha ya Jobcard ya makabidhiano<input class="phone-camera" type="file" name="handover_jobcard_photo" accept="image/*" capture="environment" required></label></div>
                        <button type="button" class="btn small add-photo-btn" data-photo-name="handover_photos[]">âž• ONGEZA PICHA NYINGINE</button>
                        <textarea name="handover_notes" placeholder="Maelezo ya makabidhiano" required></textarea>
                        <input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><input type="hidden" name="captured_at" class="gps-time">
                        <button type="button" class="btn gps-btn">ðŸ“ Pata Location</button> <span class="gps-status small">Location bado haijapatikana</span>
                        <button class="btn" type="submit">TUMA MAKABIDHIANO</button>
                    </form>
                </details><?php endif;?><?php endif;?></td>
      </tr>
    <?php endforeach;?>
  </table></div>

    <?php $leaderSites=array_filter($mySites,function($site)use($tid){return (int)($site['technician_id']??0)===$tid;}); ?>
    <?php if($leaderSites): ?><div class="panel"><h3>ðŸ‘‘ Maombi ya Kubadilisha Fundi</h3><p class="muted">Kama kazi haihitaji fundi fulani, Site Leader anaweza kutuma ombi kwa Manager. Manager ndiye anayekubali au kukataa.</p>
        <?php foreach($leaderSites as $leaderSite): $siteTeamIds=st_site_team_ids($pdo,(int)$leaderSite['id']); ?>
            <?php foreach($siteTeamIds as $teamId): if($teamId===$tid)continue; $teamMember=null;foreach($allTechs as $tech)if((int)$tech['id']===$teamId)$teamMember=$tech; if(!$teamMember)continue; ?>
                <form method="post" class="grid2" style="margin:10px 0;padding:10px;border:1px solid #eee;border-radius:8px"><input type="hidden" name="st_action" value="st_request_team_change"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="site_id" value="<?=$leaderSite['id']?>"><input type="hidden" name="technician_id" value="<?=$teamId?>"><div><b><?=st_e($leaderSite['site_name'])?></b><br><?=st_e($teamMember['full_name'])?></div><div><input name="reason" placeholder="Sababu ya kuomba kuondolewa" required><button class="btn small" type="submit">TUMA OMBI</button></div></form>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div><?php endif; ?>

    <div class="panel"><h3>ðŸ§° Omba Tool</h3><form method="post"><input type="hidden" name="st_action" value="st_request_tool"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><div class="field"><select name="site_id" required><option value="">Chagua Site</option><?php foreach($mySites as $site):?><option value="<?=$site['id']?>"><?=st_e($site['site_name'])?></option><?php endforeach;?></select></div><div class="field"><select name="item_id" required><option value="">Chagua Tool</option><?php foreach($tools as $tool):?><option value="<?=$tool['id']?>"><?=st_e($tool['tool_name'].' - '.$tool['tool_code'])?></option><?php endforeach;?></select></div><div class="field"><textarea name="reason" placeholder="Kwa nini unahitaji tool hii?"></textarea></div><button class="btn">SUBMIT TOOL REQUEST</button></form></div>
    </div>


        <form method="post" enctype="multipart/form-data" class="gps-form">
            <input type="hidden" name="st_action" value="st_save_daily_update">
            <div class="grid2"><div class="field"><select name="site_id" required><option value="">-- Site --</option><?php foreach($mySites as $s):?><option value="<?=$s['id']?>"><?=st_e($s['site_name'])?></option><?php endforeach;?></select></div><div class="field"><input type="date" name="update_date" value="<?=date('Y-m-d')?>" max="<?=date('Y-m-d')?>" required></div><div class="field"><textarea name="work_done" placeholder="Umefanya nini leo?" required></textarea></div><div class="field"><textarea name="blockers" placeholder="Changamoto / kilichokuzuia"></textarea></div><div class="field"><textarea name="next_steps" placeholder="Hatua za kesho"></textarea></div><div class="field"><input type="file" name="photo" accept="image/*" capture="environment" required><small>Piga picha ya update kwa camera ya simu (lazima).</small></div></div>
            <input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><input type="hidden" name="captured_at" class="gps-time"><button type="button" class="btn gps-btn">ðŸ“ GPS</button> <span class="gps-status small">GPS ni optional</span> <button class="btn" type="submit">SAVE DAILY UPDATE</button>
        </form>
    </div>
    <div class="panel"><h3>ðŸ“š My Daily Reports</h3><div class="table-wrap"><table><tr><th>Date</th><th>Site</th><th>Work Done</th><th>Blockers</th><th>Next Steps</th></tr><?php foreach($myDailyUpdates as $update):?><tr><td><?=st_e($update['update_date'])?></td><td><?=st_e($update['site_name'])?></td><td><?=nl2br(st_e($update['work_done']))?></td><td><?=nl2br(st_e($update['blockers']))?></td><td><?=nl2br(st_e($update['next_steps']))?></td></tr><?php endforeach;?></table></div><?php if(!$myDailyUpdates):?><p class="muted">Hakuna daily update bado.</p><?php endif;?></div>

        <div class="panel"><h3>âœ… Kazi Nilizopewa</h3><div class="table-wrap"><table><tr><th>Site</th><th>Kazi</th><th>Maelezo</th><th>Status</th></tr><?php foreach($myTasks as $task):?><tr><td><?=st_e($task['site_name'])?></td><td><?=st_e($task['task_title'])?></td><td><?=st_e($task['notes']??'')?></td><td><span class="status"><?=st_e($task['status'])?></span></td></tr><?php endforeach;?></table></div><?php if(!$myTasks):?><p class="muted">Hakuna kazi ulizopewa bado.</p><?php endif;?></div>

    <div class="panel" id="requests"><h3>📋 Maombi Yangu — Tools & Materials</h3><?php $myToolRequests=[];$q=$pdo->prepare("SELECT r.*,t.tool_name FROM st_tool_requests r JOIN st_tools t ON t.id=r.tool_id WHERE r.technician_id=? ORDER BY r.id DESC LIMIT 30");$q->execute([$tid]);$myToolRequests=$q->fetchAll(PDO::FETCH_ASSOC);$myMaterialRequests=[];$q=$pdo->prepare("SELECT r.*,m.material_name,m.unit FROM st_material_requests r JOIN st_materials m ON m.id=r.material_id WHERE r.technician_id=? ORDER BY r.id DESC LIMIT 30");$q->execute([$tid]);$myMaterialRequests=$q->fetchAll(PDO::FETCH_ASSOC);?><div class="table-wrap"><table><tr><th>Aina</th><th>Kitu</th><th>Site</th><th>Status</th><th>Aliyeidhinisha</th><th>Tarehe</th></tr><?php foreach($myToolRequests as $r):?><tr><td>Tool</td><td><?=st_e($r['tool_name'])?></td><td>#<?=st_e($r['site_id'])?></td><td><span class="status"><?=st_e($r['status'])?><?php if($r['status']==='Approved'):?> — ISSUE SOLVED<?php endif;?></span></td><td><?=st_e($r['reviewed_by']??'—')?></td><td><?=st_e($r['created_at'])?></td></tr><?php endforeach;?><?php foreach($myMaterialRequests as $r):?><tr><td>Material</td><td><?=st_e($r['material_name'].' '.$r['quantity'].' '.$r['unit'])?></td><td>#<?=st_e($r['site_id'])?></td><td><span class="status"><?=st_e($r['status'])?><?php if($r['status']==='Approved'):?> — ISSUE SOLVED<?php endif;?></span></td><td><?=st_e($r['reviewed_by']??'—')?></td><td><?=st_e($r['created_at'])?></td></tr><?php endforeach;?><?php if(!$myToolRequests&&!$myMaterialRequests):?><tr><td colspan="6">Hakuna ombi la tools/material.</td></tr><?php endif;?></table></div></div>

<div class="panel" id="transport"><h3>🚗 Usafiri Niliopewa</h3><div class="table-wrap"><table><tr><th>Tarehe</th><th>Gari</th><th>Destination</th><th>Status</th><th>Kufika</th><th>Kitendo</th></tr><?php foreach($techTrips as $trip):?><tr><td><?=st_e($trip['departure_at'])?></td><td><?=st_e($trip['registration_no'].' - '.$trip['vehicle_type'].' '.$trip['make_model'])?></td><td><?=st_e($trip['destination'])?></td><td><span class="status"><?=st_e($trip['status'])?></span></td><td><?=!empty($trip['arrived_at'])?'AMEFIKA':'HAJAFIKA'?></td><td><?php if($trip['status']==='Open' && empty($trip['arrived_at'])):?><form method="post" class="gps-form gps-submit-form"><input type="hidden" name="st_action" value="st_mark_transport_arrival"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$trip['id']?>"><input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><button type="submit" class="btn small gps-submit-btn">📍 NIMEFIKA</button><span class="gps-status small">GPS optional</span></form><?php elseif($trip['arrived_at']):?><span class="small">✓ Umefika <?=st_e($trip['arrived_at'])?></span><?php endif;?></td></tr><?php endforeach;?><?php if(!$techTrips):?><tr><td colspan="6">Hakuna usafiri uliopangiwa.</td></tr><?php endif;?></table></div></div>

<div class="panel">
      <h3>ðŸ“· <?=zan_t('Tuma Picha ya Site + GPS','Submit Site Photo + GPS')?></h3>
      <form method="post" enctype="multipart/form-data" class="gps-form">
        <input type="hidden" name="st_action" value="st_add_site_photo">
        <div class="field"><select name="site_id" required><option value="">-- Site --</option><?php foreach($mySites as $s):?><option value="<?=$s['id']?>"><?=st_e($s['site_name'])?></option><?php endforeach;?></select></div>
        <div class="field"><select name="category"><option>Site Arrival</option><option>Before Work</option><option>Material Received</option><option selected>Work Progress</option><option>Material Used</option><option>Completed Work</option><option>Problem / Damage</option><option>Site Exit</option><option>Customer Handover</option></select></div>
        <div class="field"><input name="caption" placeholder="<?=zan_t('Maelezo ya picha','Photo description')?>"></div>
        <label class="camera-field">ðŸ“· Piga picha ya site<input class="phone-camera" type="file" name="photo" accept="image/*" capture="environment" required></label>
        <input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><input type="hidden" name="captured_at" class="gps-time">
        <button type="button" class="btn gps-btn">ðŸ“ <?=zan_t('Pata Location','Get Location')?></button> <span class="gps-status small"><?=zan_t('Location ni optional','Location is optional')?></span>
        <br><button class="btn" type="submit">ðŸ“¤ <?=zan_t('TUMA PICHA','SUBMIT PHOTO')?></button>
      </form>
    </div>

    <div class="panel">
      <h3>&#128295; <?=zan_t('Picha za Tools nilizopewa','My Tool Records')?></h3>
      <div class="table-wrap"><table>
        <tr><th>Tool</th><th>Site</th><th>Type</th><th>Photo</th><th>Status</th></tr>
        <?php foreach($myTools as $m):?><tr>
          <td><?=st_e(($m['tool_code']??'').' '.$m['tool_name'])?></td><td><?=st_e($m['site_name']??'')?></td>
          <td><?=st_e($m['action_type'])?></td><td><?=st_photo($m['photo']??'')?></td><td><?=st_e($m['status'])?></td>
        </tr><?php endforeach;?>
      </table></div>
    </div>
  </div>

  <div class="panel" id="tools">
    <h3>↩️ Rudisha Tools</h3>
    <p class="muted">Chagua tool uliyonayo, piga picha ya tool wakati wa kurudisha, kisha tuma. GPS ni optional.</p>
    <?php if($myHeldTools): foreach($myHeldTools as $heldTool): ?>
      <form method="post" enctype="multipart/form-data" style="margin:12px 0;padding:12px;border:1px solid #eee;border-radius:8px">
        <input type="hidden" name="st_action" value="st_return_tool"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="tool_id" value="<?=$heldTool['tool_id']?>"><input type="hidden" name="technician_id" value="<?=$tid?>">
        <b><?=st_e($heldTool['tool_name'])?></b> <span class="small"><?=st_e($heldTool['tool_code'])?></span>
        <?php if(!empty($heldTool['site_name'])):?><div class="small">Site: <?=st_e($heldTool['site_name'])?></div><?php endif;?>
        <div class="grid2" style="margin-top:8px"><div class="field"><select name="condition_status"><option>Good</option><option>Damaged</option><option>Missing</option></select></div>
        <label class="camera-field">📷 Piga picha ya tool inayorudishwa<input class="phone-camera" type="file" name="photo" accept="image/*" capture="environment" required></label></div>
        <input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><input type="hidden" name="captured_at" class="gps-time">
        <button class="btn" type="submit">RUDISHA TOOL</button>
      </form>
    <?php endforeach; else: ?><p class="muted">Huna tool yoyote inayosubiri kurudishwa.</p><?php endif; ?>
  </div>

        <?php $myMoneyTransactions=[];$q=$pdo->prepare("SELECT m.*,s.site_name FROM st_money_transactions m LEFT JOIN st_sites s ON s.id=m.site_id WHERE m.recipient_type='technician' AND m.recipient_id=? ORDER BY m.id DESC LIMIT 30");$q->execute([$tid]);$myMoneyTransactions=$q->fetchAll(PDO::FETCH_ASSOC);?>
        <div id="money">
        <?php if($myNotifications): ?><div class="panel" id="notifications"><h3>🔔 Notifications</h3><?php foreach($myNotifications as $notification):?><div class="alert-row"><span><?=st_e($notification['message'])?></span><small><?=st_e($notification['created_at'])?></small></div><?php endforeach;?></div><?php endif; ?>
        <?php $myMoneyDebt=array_filter($myMoneyTransactions,function($m){return in_array((string)($m['status']??''),['Granted','Rejected'],true);}); $myMoneyDebtTotal=0; foreach($myMoneyDebt as $dm)$myMoneyDebtTotal+=(float)$dm['amount']; ?>
        <div class="panel" style="margin-top:12px"><h3>💸 Pesa Nilizopewa</h3>
          <?php if($myMoneyDebt): ?><div class="notice" style="border-left:4px solid #b91c1c"><b>DENI:</b> <?=count($myMoneyDebt)?> transfer bado haijafungwa. Jumla: <b><?=st_e(number_format($myMoneyDebtTotal,2).' TZS')?></b>.</div><?php endif; ?>
          <div class="table-wrap"><table><tr><th>Site</th><th>Kiasi</th><th>Purpose</th><th>Aliyetuma</th><th>Aliyeidhinisha</th><th>Amepokea</th><th>Status / Action</th></tr>
          <?php foreach($myMoneyTransactions as $m): ?><tr><td><?=st_e($m['site_name']??'Bila Site')?></td><td><b><?=st_e($m['amount'].' '.$m['currency'])?></b></td><td><?=st_e($m['purpose'])?></td><td><?=st_e($m['granted_by']??'')?></td><td><?=st_e($m['approved_by']??'—')?></td><td><?=!empty($m['received_at'])?st_e(($m['received_by']??'').' @ '.$m['received_at']):'BADO'?></td><td>
          <?php if((string)$m['status']==='Granted'): ?><span class="status">PESA IMETUMWA</span><form method="post" style="margin-top:6px"><input type="hidden" name="st_action" value="st_confirm_money_received"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="money_id" value="<?=$m['id']?>"><button class="btn small">THIBITISHA NIMEPOKEA</button></form>
          <?php elseif((string)$m['status']==='Received'): ?><span class="status">IMEEPOKELEWA</span><form method="post" enctype="multipart/form-data" class="gps-form" style="margin-top:6px"><input type="hidden" name="st_action" value="st_submit_money_receipt"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="money_id" value="<?=$m['id']?>"><label class="camera-field">📷 Piga picha ya risiti<input class="phone-camera" type="file" name="receipt_photo" accept="image/*" capture="environment" required></label><input name="receipt_notes" placeholder="Maelezo ya risiti" required><input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><button type="submit" class="btn small">TUMA RISITI</button></form>
          <?php elseif((string)$m['status']==='Rejected'): ?><span class="status">RISITI IMERUDISHWA</span><form method="post" enctype="multipart/form-data" class="gps-form" style="margin-top:6px"><input type="hidden" name="st_action" value="st_submit_money_receipt"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="money_id" value="<?=$m['id']?>"><label class="camera-field">📷 Tuma risiti tena<input class="phone-camera" type="file" name="receipt_photo" accept="image/*" capture="environment" required></label><input name="receipt_notes" placeholder="Maelezo ya risiti" required><button type="submit" class="btn small">TUMA TENA</button></form>
          <?php else: ?><span class="status"><?=st_e($m['status'])?></span><?php if(!empty($m['receipt_photo'])):?> <?=st_photo($m['receipt_photo'])?><?php endif;?><?php endif; ?></td></tr><?php endforeach; ?>
          <?php if(!$myMoneyTransactions): ?><tr><td colspan="7">Hakuna pesa ulizopewa.</td></tr><?php endif; ?></table></div></div>
        </div>

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
    $adminNotifications=st_user_notifications($pdo,(int)($_SESSION['user']['id']??0),20);
    $monthBest=st_best_technicians($pdo,$allTechs,date('Y-m-01'),date('Y-m-t'));
    $yearBest=st_best_technicians($pdo,$allTechs,date('Y-01-01'),date('Y-12-31'));
    $bestMonth=$monthBest[0]??null;$bestYear=$yearBest[0]??null;
  ?>
  <h2>&#128202; Site Tracking Dashboard</h2>
    <?php if($adminNotifications): ?><div class="panel"><h3>ðŸ”” Notifications za Administrator</h3><?php foreach($adminNotifications as $notification):$wa=st_whatsapp_link($notification['phone']??'', $notification['message']);?><div class="alert-row"><span><?=st_e($notification['message'])?></span><span><small><?=st_e($notification['created_at'])?></small><?php if($wa):?> <a class="btn small wa-btn" target="_blank" href="<?=st_e($wa)?>">WhatsApp</a><?php endif;?></span></div><?php endforeach;?></div><?php endif; ?>
  <div class="cards">
        <a class="card dash-kpi" href="?page=st_sites"><div class="label">Active Sites</div><div class="value"><?=$dashActive?></div><span>&#127959; View sites</span></a>
        <a class="card dash-kpi" href="?page=st_reports"><div class="label">Overdue Sites</div><div class="value"><?=$dashOverdue?></div><span>âš ï¸ Review report</span></a>
        <a class="card dash-kpi" href="?page=st_approvals"><div class="label">Pending Approval</div><div class="value"><?=$dashPending?></div><span>âœ… Open approvals</span></a>
        <a class="card dash-kpi" href="?page=st_sites"><div class="label">Open Tasks</div><div class="value"><?=$dashOpenTasks?></div><span>â˜‘ï¸ View checklist</span></a>
        <a class="card" href="?page=st_technicians">ðŸ‘· <b><?=count($allTechs)?></b><br>Technicians</a>
        <a class="card" href="?page=st_site_gallery">ðŸ“· <b><?=$dashPhotos?></b><br>Photos last 7 days</a>
  </div>
    <div class="dash-actions"><a class="btn" href="?page=st_sites">âž• Add Site</a><a class="btn" href="?page=st_technicians">ðŸ‘· Add Technician</a><a class="btn" href="?page=st_tools">&#128295; Register Tool</a><a class="btn" href="?page=st_reports">&#128202; View Reports</a><a class="btn" href="?page=st_approvals">âœ… Review Approvals</a></div>
    <div class="dash-grid">
        <div class="panel dash-panel"><h3>ðŸ“ˆ Site Progress</h3><?php foreach($dashStatuses as $statusRow):?><div class="progress-line"><span><?=st_e($statusRow['status']?:'Unknown')?></span><div class="progress-track"><div class="progress-fill" style="width:<?=round((int)$statusRow['total']/$dashStatusMax*100)?>%"></div></div><b><?=st_e($statusRow['total'])?></b></div><?php endforeach;?><?php if(!$dashStatuses):?><p class="muted">Hakuna site data bado.</p><?php endif;?></div>
        <div class="panel dash-panel"><h3>ðŸ”” Attention Needed</h3><?php foreach($dashAlerts as $alert):?><div class="alert-row"><span><b><?=st_e($alert['site_name'])?></b><br><small><?=st_e($alert['status'])?></small></span><span><?=st_e($alert['due_date']?:'Review')?></span></div><?php endforeach;?><?php if(!$dashAlerts):?><p class="muted">Hakuna alerts kwa sasa.</p><?php endif;?></div>
        <div class="panel dash-panel"><h3>ðŸ“ Recent Activity</h3><?php foreach($dashActivity as $activity):?><div class="alert-row"><span><b><?=st_e($activity['action'])?></b><br><small><?=st_e($activity['details'])?></small></span><span><?=st_e($activity['created_at'])?></span></div><?php endforeach;?><?php if(!$dashActivity):?><p class="muted">Hakuna activity bado.</p><?php endif;?><a class="btn small" href="?page=st_activity">View full history</a></div>
        <div class="panel dash-panel"><h3>ðŸ“¦ System Snapshot</h3><div class="alert-row"><span>Registered tools</span><b><?=count($tools)?></b></div><div class="alert-row"><span>Materials catalogue</span><b><?=count($materials)?></b></div><div class="alert-row"><span>Photos last 7 days</span><b><?=$dashPhotos?></b></div><div class="alert-row"><span>Unreturned tools</span><b><?=count($held)?></b></div></div>
    </div>
    <div class="dash-grid">
        <div class="panel dash-panel"><h3>ðŸ§° Overdue Tools</h3><div class="table-wrap"><table><tr><th>Tool</th><th>Fundi</th><th>Site</th><th>Due</th><th>Action</th></tr><?php foreach($dashOverdueTools as $tool):$days=max(1,(int)((strtotime(date('Y-m-d'))-strtotime($tool['due_date']))/86400));$msg=st_admin_message($pdo,$tool['technician_name'],$tool['site_name'],$days,$tool['due_date'],'Habari {technician}, tool '.$tool['tool_name'].' ya site {site} imechelewa kurudishwa kwa {days} siku. Tafadhali toa update.');$wa=st_whatsapp_link($tool['phone'],$msg);?><tr class="overdue-row"><td><?=st_e($tool['tool_name'])?></td><td><?=st_e($tool['technician_name'])?></td><td><?=st_e($tool['site_name'])?></td><td><?=st_e($tool['due_date'])?><br><small><?=$days?> days late</small></td><td><?php if($wa):?><a class="btn small wa-btn" target="_blank" href="<?=st_e($wa)?>">WhatsApp</a><?php endif;?></td></tr><?php endforeach;?></table></div><?php if(!$dashOverdueTools):?><p class="muted">Hakuna tool iliyochelewa.</p><?php endif;?></div>
        <div class="panel dash-panel"><h3>&#127959; Overdue Sites</h3><div class="table-wrap"><table><tr><th>Site</th><th>Fundi</th><th>Due</th><th>Action</th></tr><?php foreach($dashOverdueSites as $site):$days=max(1,(int)((strtotime(date('Y-m-d'))-strtotime($site['due_date']))/86400));$msg=st_admin_message($pdo,$site['technician_name'],$site['site_name'],$days,$site['due_date'],'Habari {technician}, site {site} imechelewa kwa {days} siku. Tafadhali tuma daily update na completion plan.');$wa=st_whatsapp_link($site['phone'],$msg);?><tr class="overdue-row"><td><?=st_e($site['site_name'])?></td><td><?=st_e($site['technician_name'])?></td><td><?=st_e($site['due_date'])?><br><small><?=$days?> days late</small></td><td><?php if($wa):?><a class="btn small wa-btn" target="_blank" href="<?=st_e($wa)?>">WhatsApp</a><?php endif;?></td></tr><?php endforeach;?></table></div><?php if(!$dashOverdueSites):?><p class="muted">Hakuna site iliyochelewa.</p><?php endif;?></div>
        <div class="panel dash-panel"><h3>ðŸ“ Missing Daily Updates</h3><?php foreach($dashMissingUpdates as $missing):$msg=st_admin_message($pdo,$missing['technician_name'],$missing['site_name'],0,date('Y-m-d'),'Habari {technician}, bado hujaweka daily update ya site {site} leo. Tafadhali weka update ya kazi uliyofanya.');$wa=st_whatsapp_link($missing['phone'],$msg);?><div class="alert-row warning-row"><span><b><?=st_e($missing['site_name'])?></b><br><?=st_e($missing['technician_name'])?></span><?php if($wa):?><a class="btn small wa-btn" target="_blank" href="<?=st_e($wa)?>">WhatsApp</a><?php endif;?></div><?php endforeach;?><?php if(!$dashMissingUpdates):?><p class="muted">Mafundi wote wana daily update ya leo.</p><?php endif;?></div>
        <div class="panel dash-panel"><h3>ðŸ“š Daily Updates</h3><a class="btn" href="?page=st_daily_reports">View all daily reports</a><p class="muted">Updates za leo na historia ya mafundi.</p></div>
    </div>
    <div class="dash-grid">
        <div class="panel dash-panel"><h3>ðŸ† Fundi Bora wa Mwezi</h3><?php if($bestMonth):?><h2 style="margin:8px 0"><?=st_e($bestMonth['name'])?></h2><p><?=st_e($bestMonth['office'])?></p><div class="status">Score: <?=st_e($bestMonth['score'])?>/100</div><p class="small">Sites kwa wakati: <?=$bestMonth['on_time']?> Â· Updates: <?=$bestMonth['updates']?> Â· Tasks: <?=$bestMonth['tasks']?> Â· Photos: <?=$bestMonth['photos']?> Â· Tools: <?=$bestMonth['tools']?></p><?php else:?><p class="muted">Hakuna data ya mwezi huu.</p><?php endif;?></div>
        <div class="panel dash-panel"><h3>ðŸ… Fundi Bora wa Mwaka</h3><?php if($bestYear):?><h2 style="margin:8px 0"><?=st_e($bestYear['name'])?></h2><p><?=st_e($bestYear['office'])?></p><div class="status">Score: <?=st_e($bestYear['score'])?>/100</div><p class="small">Sites kwa wakati: <?=$bestYear['on_time']?> Â· Updates: <?=$bestYear['updates']?> Â· Tasks: <?=$bestYear['tasks']?> Â· Photos: <?=$bestYear['photos']?> Â· Tools: <?=$bestYear['tools']?></p><?php else:?><p class="muted">Hakuna data ya mwaka huu.</p><?php endif;?></div>
    </div>
    <div class="panel"><h3>&#128202; Technician Performance Ranking</h3><p class="muted">Score: sites kwa wakati 40%, daily updates 25%, tasks 15%, photos/GPS 10%, tools zilizorudishwa kwa wakati 10%.</p><div class="table-wrap"><table><tr><th>#</th><th>Fundi</th><th>Office</th><th>Score</th><th>Sites</th><th>Updates</th><th>Tasks</th><th>Photos</th><th>Tools</th></tr><?php foreach($monthBest as $rank=>$performer):?><tr class="<?=($rank===0?'ok-row':'')?>"><td><?=($rank+1)?></td><td><?=st_e($performer['name'])?></td><td><?=st_e($performer['office'])?></td><td><b><?=st_e($performer['score'])?>/100</b></td><td><?=st_e($performer['on_time'])?></td><td><?=st_e($performer['updates'])?></td><td><?=st_e($performer['tasks'])?></td><td><?=st_e($performer['photos'])?></td><td><?=st_e($performer['tools'])?></td></tr><?php endforeach;?></table></div><?php if(!$monthBest):?><p class="muted">Hakuna technician performance data bado.</p><?php endif;?></div>
  <div class="panel"><h3>ðŸ“ˆ Mafundi wenye tools ambazo hawajarudisha</h3>
  <?php foreach($graph as $g):?><div style="display:grid;grid-template-columns:150px 1fr 40px;gap:8px;align-items:center;margin:8px 0">
    <span><?=st_e($g['full_name'])?></span><div style="height:20px;background:#eee;border-radius:10px;overflow:hidden"><div style="height:100%;width:<?=round($g['total']/$mx*100)?>%;background:#2563eb"></div></div><b><?=$g['total']?></b>
  </div><?php endforeach;?><?php if(!$graph):?><p>Hakuna tools ambazo hazijarudishwa.</p><?php endif;?></div>

<?php elseif($page==='st_ai_assistant'): ?>
    <?php
        $aiQuestion=$_SESSION['st_ai_question']??'';unset($_SESSION['st_ai_question']);
        $aiAnswer=$_SESSION['st_ai_answer']??'';unset($_SESSION['st_ai_answer']);
        $aiChat=$_SESSION['st_ai_chat']??[];
        $aiSites=(int)$pdo->query("SELECT COUNT(*) FROM st_sites WHERE status<>'Closed'")->fetchColumn();
        $aiTechnicians=(int)$pdo->query("SELECT COUNT(*) FROM st_technicians WHERE status='Active'")->fetchColumn();
        $aiTransfers=(int)$pdo->query("SELECT COUNT(*) FROM st_material_funds WHERE status='Granted'")->fetchColumn();
    ?>
    <h2>ðŸ¤– AI Assistant</h2>
    <div class="notice">Uliza AI kuhusu <b>kila sehemu ya Site Tracking</b>: Sites, Mafundi, Assignments, Tasks, Tools, Usafiri, GPS, Approvals, Reports, Notifications, Accounts na Super Admin. AI inafundisha hatua kwa hatua na haitoi password/credentials.</div>
    <div class="cards"><div class="card"><span class="small">Active Sites</span><b><?=$aiSites?></b></div><div class="card"><span class="small">Active Technicians</span><b><?=$aiTechnicians?></b></div><div class="card"><span class="small">Pesa zinazosubiri risiti</span><b><?=$aiTransfers?></b></div></div>
    <div class="panel ai-chat-shell" style="margin-top:18px"><h3>&#128172; Mazungumzo na AI</h3><div class="ai-chat-log"><?php if($aiChat): foreach($aiChat as $chat):?><div class="ai-chat-message"><div class="ai-user-message"><b>Wewe:</b> <?=nl2br(st_e($chat['question']))?></div><div class="ai-answer-message"><b>AI:</b> <?=nl2br(st_e($chat['answer']))?> <button type="button" class="btn small ai-speak" title="Soma jibu kwa sauti" aria-label="Soma jibu kwa sauti" data-speak="<?=st_e($chat['answer'])?>">ðŸ”Š</button></div><div class="small"><?=st_e($chat['created_at'])?></div></div><?php endforeach; else:?><p class="muted">Anza mazungumzo hapa.</p><?php endif;?></div><form method="post" class="grid2 ai-chat-form" id="ai-chat-form"><input type="hidden" name="st_action" value="st_ai_ask"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><div class="field"><textarea id="ai-question" name="question" rows="2" placeholder="Andika ujumbe..." required><?=st_e($aiQuestion)?></textarea><div class="actions"><button class="btn small" id="ai-mic" type="button" title="Ongea swali" aria-label="Ongea swali">ðŸŽ™ï¸</button><span class="small" id="ai-voice-status">Bonyeza mic kuongea</span></div></div><div><button class="btn" type="submit" title="Tuma swali" aria-label="Tuma swali">âž¤</button></div></form></div>
    <div class="panel"><h3>Maswali ya kuanzia</h3><div class="actions"><span class="status">Nifundishe mfumo mzima</span><span class="status">Nipangie site na mafundi wawili</span><span class="status">Nipangie safari bila dereva</span><span class="status">Nioneshe tools zilizopotea/kuharibika</span><span class="status">Fundi anaonaje assignments zake?</span><span class="status">Super Admin anaweza kufanya nini?</span></div></div>

<?php elseif($page==='st_technicians'): ?>
  <h2><?=zan_t('Mafundi','Technicians')?></h2>
  <?php if($editTech):?>
  <div class="panel"><h3>&#9998; Edit Technician</h3>
    <form method="post" enctype="multipart/form-data" autocomplete="off"><input type="hidden" name="st_action" value="st_save_technician"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="id" value="<?=$editTech['id']?>">
    <div class="grid2">
      <div class="field"><input name="technician_no" value="<?=st_e($editTech['technician_no'])?>" placeholder="Technician / Employee Number" required></div>
      <div class="field"><input name="full_name" value="<?=st_e($editTech['full_name'])?>" placeholder="Full name" required></div>
      <div class="field"><input name="username" value="<?=st_e($editTech['username']??'')?>" placeholder="Username ya Fundi" autocomplete="off" required></div>
      <div class="field"><input type="password" name="password" placeholder="New password (leave blank to keep current)" autocomplete="new-password"></div>
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
      <div class="field">ðŸ“· Photo<input type="file" name="photo" accept="image/*" capture="environment"></div>
    </div><button class="btn">UPDATE TECHNICIAN</button> <a class="btn dark" href="?page=st_technicians">CANCEL</a>
  </form></div>
  <?php else:?>
    <div class="panel" id="technician-form"><form method="post" enctype="multipart/form-data" autocomplete="off"><input type="hidden" name="st_action" value="st_save_technician"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>">
    <div class="grid2">
      <div class="field"><input name="technician_no" placeholder="Technician / Employee Number" required></div><div class="field"><input name="full_name" placeholder="Full name" required></div>
      <div class="field"><input name="username" placeholder="Username ya Fundi" autocomplete="off" required></div><div class="field"><input type="password" name="password" placeholder="Password ya Fundi" autocomplete="new-password" required></div>
      <div class="field"><input name="phone" placeholder="Phone"></div><div class="field"><input name="email" placeholder="Email"></div>
      <div class="field"><textarea name="address" placeholder="Address"></textarea></div><div class="field"><input name="position" placeholder="Position"></div>
    <div class="field"><input name="office" placeholder="Office / Kituo cha kazi" required></div>
    <div class="field"><select name="gender"><option value="">Jinsia</option><option value="Mwanaume">Mwanaume</option><option value="Mwanamke">Mwanamke</option></select></div>
    <div class="field"><select name="marital_status"><option value="">Hali ya ndoa</option><option value="Ameoa">Ameoa</option><option value="Ameolewa">Ameolewa</option><option value="Hajaoa">Hajaoa</option><option value="Hajaolewa">Hajaolewa</option><option value="Talaka">Talaka</option><option value="Mjane">Mjane</option></select></div>
    <div class="field"><input type="number" name="children_count" min="0" step="1" value="0" placeholder="Idadi ya watoto"></div>
      <div class="field"><input name="specialization" placeholder="Technical specialization"></div><div class="field"><input name="emergency_name" placeholder="Emergency contact name"></div>
      <div class="field"><input name="emergency_phone" placeholder="Emergency phone"></div>
      <div class="field">ðŸ“· Passport photo<input type="file" name="photo" accept="image/*" capture="environment"></div>
      <div class="field"><select name="status"><option>Active</option><option>Inactive</option><option>Suspended</option></select></div>
    </div><button class="btn">SAVE TECHNICIAN</button>
  </form></div><?php endif;?>

        <div class="panel table-wrap"><table><tr><th>Photo</th><th>Technician</th><th>Office</th><th>Jinsia</th><th>Ndoa</th><th>Watoto</th><th>Username</th><th>Phone</th><th>Status</th><th>Summary</th><th>Kitendo</th></tr>
    <?php foreach($allTechs as $x):
        $techSiteCountStmt=$pdo->prepare('SELECT COUNT(*) FROM st_site_technicians WHERE technician_id=?');$techSiteCountStmt->execute([(int)$x['id']]);$techSiteCount=(int)$techSiteCountStmt->fetchColumn();
        $techToolCountStmt=$pdo->prepare("SELECT COUNT(*) FROM st_tool_movements WHERE technician_id=? AND action_type='Issued' AND COALESCE(status,'') NOT IN ('Returned','Rejected')");$techToolCountStmt->execute([(int)$x['id']]);$techToolCount=(int)$techToolCountStmt->fetchColumn();
        $techFundStmt=$pdo->prepare('SELECT COALESCE(SUM(amount),0),COUNT(*) FROM st_material_funds WHERE technician_id=?');$techFundStmt->execute([(int)$x['id']]);$techFund=$techFundStmt->fetch(PDO::FETCH_NUM);
        $techReceiptStmt=$pdo->prepare("SELECT COUNT(*) FROM st_material_funds WHERE technician_id=? AND status='Receipt Submitted'");$techReceiptStmt->execute([(int)$x['id']]);$techReceiptCount=(int)$techReceiptStmt->fetchColumn();
        $techWhatsApp=st_whatsapp_link($x['phone']??'','Habari '.($x['full_name']??'').', tafadhali tuma update ya kazi na risiti kama bado hujatuma.');
    ?><tr>
                <td><?=st_photo($x['photo']??'','passport')?></td><td><b><?=st_e($x['full_name'])?></b><div class="small"><?=st_e($x['technician_no'])?> · <?=st_e($x['specialization'])?></div></td>
        <td><?=st_e($x['office']??'')?></td><td><?=st_e($x['gender']??'')?></td><td><?=st_e($x['marital_status']??'')?></td><td><?=st_e($x['children_count']??0)?></td>
        <td><?=st_e($x['username']??'')?></td><td><?=st_e($x['phone'])?></td><td><?=st_e($x['status'])?></td>
        <td><div class="small">Sites: <b><?=$techSiteCount?></b> · Tools: <b><?=$techToolCount?></b><br>Pesa: <b><?=st_e(number_format((float)$techFund[0],2))?></b><br>Risiti: <b><?=$techReceiptCount?></b></div></td>
                <td><div class="actions">
            <a class="btn small" href="?page=st_technicians&edit=<?=$x['id']?>">EDIT</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this technician?')"><input type="hidden" name="st_action" value="st_delete_technician"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="btn danger small" type="submit">DELETE</button></form>
    </div></td>
  </tr><?php endforeach;?></table></div>

<?php elseif($page==='st_sites'): ?>
  <div class="simple-page-head">
    <div>
      <h2>🏗️ Sites na Wateja</h2>
      <p class="muted">Usajili wa site, siku ya kufanyia kazi, na orodha ya sites.</p>
    </div>
  </div>

  <?php if(!st_is_technician()): ?>
  <div class="panel simple-form-card" id="site-form">
    <h3>📝 Usajili wa Site</h3>
    <form method="post" autocomplete="off">
      <input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>">
      <input type="hidden" name="st_action" value="st_save_site">
      <input type="hidden" name="id" value="<?=st_e($editSite['id']??0)?>">
      <div class="grid2">
        <div class="field"><label>Site / Project Name</label><input name="site_name" value="<?=st_e($editSite['site_name']??'')?>" placeholder="Jina la Site" required></div>
        <div class="field"><label>Customer / Mteja</label><input name="customer_name" value="<?=st_e($editSite['customer_name']??'')?>" placeholder="Jina la Mteja" required></div>
        <div class="field"><label>Location</label><input name="location" value="<?=st_e($editSite['location']??'')?>" placeholder="Eneo la Site" required></div>
        <div class="field"><label>Siku ya Kufanyia Kazi</label><input type="date" name="start_date" value="<?=st_e($editSite['start_date']??date('Y-m-d'))?>" required></div>
        <div class="field"><label>Site imeletwa na nani?</label><input name="introduced_by" value="<?=st_e($editSite['introduced_by']??'')?>" placeholder="Jina la aliyeleta site" required></div>
      </div>
      <button class="btn"><?=!empty($editSite)?'UPDATE SITE':'SAVE SITE'?></button>
      <?php if(!empty($editSite)): ?><a class="btn dark" href="?page=st_sites">CANCEL</a><?php endif; ?>
    </form>
  </div>
  <?php endif; ?>

  <div class="panel table-wrap">
    <h3>📋 Orodha ya Sites</h3>
    <table>
      <tr><th>Site</th><th>Mteja</th><th>Location</th><th>Siku ya Kufanyia Kazi</th><th>Site imeletwa na</th><th>Action</th></tr>
      <?php foreach($sites as $x): ?>
        <tr>
          <td><b><?=st_e($x['site_name'])?></b></td>
          <td><?=st_e($x['customer_name'])?></td>
          <td><?=st_e($x['location'])?></td>
          <td><?=st_e($x['start_date']?:'-')?></td>
          <td><?=st_e($x['introduced_by']??'-')?></td>
          <td>
            <?php if(st_is_admin()): ?>
              <a class="btn small" href="?page=st_sites&edit=<?=$x['id']?>">✎ EDIT</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this site?')">
                <input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>">
                <input type="hidden" name="st_action" value="st_delete_site">
                <input type="hidden" name="id" value="<?=$x['id']?>">
                <button class="btn danger small">🗑 DELETE</button>
              </form>
            <?php else: ?>
              <span class="muted">-</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$sites): ?><tr><td colspan="6">Hakuna site iliyosajiliwa bado.</td></tr><?php endif; ?>
    </table>
  </div>

<?php elseif($page==='st_assignment_center'): ?>
  <h2>🎯 Assignment Center</h2>
  <p class="muted">Unda assignment ya Site kwa mara moja: <b>Fundi + Dereva + Gari/Pikipiki + Trip + Tasks</b>. Mfumo uta-link kila kitu na kutuma notification.</p>
  <?php
    $activeTechs=$pdo->query("SELECT id,full_name,phone FROM st_technicians WHERE status='Active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    $activeDrivers=$pdo->query("SELECT id,full_name,phone FROM st_drivers WHERE status='Active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    $availableVehicles=$pdo->query("SELECT id,registration_no,vehicle_type,make_model FROM st_vehicles WHERE COALESCE(NULLIF(status,''),'Available') NOT IN ('On Trip','Inactive') ORDER BY registration_no")->fetchAll(PDO::FETCH_ASSOC);
    $assignments=$pdo->query("SELECT a.*,s.site_name,s.technician_id site_leader_id,t.full_name technician_name,d.full_name driver_name,v.registration_no,v.vehicle_type,v.make_model,tr.status trip_status,tr.destination FROM st_assignment_jobs a JOIN st_sites s ON s.id=a.site_id JOIN st_technicians t ON t.id=a.technician_id LEFT JOIN st_drivers d ON d.id=a.driver_id JOIN st_vehicles v ON v.id=a.vehicle_id LEFT JOIN st_transport_trips tr ON tr.id=a.trip_id ORDER BY a.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <?php if(st_is_admin()||st_is_manager()): ?>
  <div class="panel"><h3>➕ New Site Assignment</h3>
    <form method="post" class="grid2"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="st_action" value="st_create_assignment">
      <div class="field"><label>Site</label><select name="site_id" required><option value="">Chagua Site</option><?php foreach($sites as $site):?><option value="<?=$site['id']?>"><?=st_e($site['site_name'])?></option><?php endforeach;?></select></div>
      <div class="field"><label>Job / Kazi</label><input name="job_title" placeholder="Mfano: Installation ya vifaa" required></div>
      <div class="field"><label>Fundi</label><select name="technician_id" required><option value="">Chagua Fundi</option><?php foreach($activeTechs as $x):?><option value="<?=$x['id']?>"><?=st_e($x['full_name'].' — '.($x['phone']??''))?></option><?php endforeach;?></select></div>
      <div class="field"><label>Dereva <span class="small muted">(optional)</span></label><select name="driver_id"><option value="0">Hakuna Dereva</option><?php foreach($activeDrivers as $x):?><option value="<?=$x['id']?>"><?=st_e($x['full_name'].' — '.($x['phone']??''))?></option><?php endforeach;?></select></div>
      <div class="field"><label>Gari / Pikipiki</label><select name="vehicle_id" required><option value="">Chagua Available</option><?php foreach($availableVehicles as $x):?><option value="<?=$x['id']?>"><?=st_e($x['registration_no'].' — '.$x['vehicle_type'].' '.$x['make_model'])?></option><?php endforeach;?></select><div class="small muted">Vyombo vya On Trip/Inactive havionekani.</div></div>
      <div class="field"><label>Tools za Fundi</label><select name="tool_ids[]" multiple size="4"><?php foreach($pdo->query("SELECT id,tool_name,tool_code FROM st_tools WHERE COALESCE(NULLIF(status,''),'Available')='Available' ORDER BY tool_name")->fetchAll(PDO::FETCH_ASSOC) as $tool):?><option value="<?=$tool['id']?>"><?=st_e($tool['tool_name'].' — '.$tool['tool_code'])?></option><?php endforeach;?></select></div>
      <div class="field full"><label>Materials za Fundi — andika tu, hakuna Stock</label><textarea name="materials_text" rows="3" placeholder="Mfano:
Cable 100m
Connectors 20pcs
RJ45 10pcs
Battery 1pcs"></textarea><div class="small muted">Materials hizi zitaunganishwa na Job/Task kama maelekezo. Mfumo hautapunguza stock wala kuhitaji stock.</div></div>
      <div class="field"><label>Destination</label><input name="destination" placeholder="Destination / eneo la kazi"></div>
      <div class="field"><label>Purpose</label><input name="purpose" placeholder="Sababu ya safari"></div>
      <div class="field"><label>Priority</label><select name="priority"><option>Low</option><option selected>Normal</option><option>High</option><option>Urgent</option></select></div>
      <div class="field"><label>Due date</label><input type="date" name="due_at"></div>
      <div class="field full"><label>Maelezo</label><textarea name="notes" placeholder="Maelekezo ya kazi, customer, vifaa, au taarifa muhimu..."></textarea></div>
      <div><button class="btn">🚀 CREATE & ASSIGN JOB</button></div>
    </form></div>
  <?php endif; ?>
  <div class="grid2" style="grid-template-columns:repeat(4,minmax(0,1fr));gap:10px"><div class="panel"><b>ASSIGNMENTS</b><h2 style="margin:6px 0"><?=count($assignments)?></h2></div><div class="panel"><b>ACTIVE FUNDIS</b><h2 style="margin:6px 0"><?=count($activeTechs)?></h2></div><div class="panel"><b>ACTIVE DRIVERS</b><h2 style="margin:6px 0"><?=count($activeDrivers)?></h2></div><div class="panel"><b>AVAILABLE VEHICLES</b><h2 style="margin:6px 0"><?=count($availableVehicles)?></h2></div></div>
  <div class="panel table-wrap"><h3>📋 Assignment Board</h3><table><tr><th>Site / Job</th><th>Site Leader</th><th>Fundi wa Kazi</th><th>Dereva</th><th>Gari</th><th>Trip</th><th>Materials</th><th>Priority</th><th>Due</th><th>Status</th><th>Action</th></tr><?php foreach($assignments as $x):?><tr><td><b><?=st_e($x['site_name'])?></b><div class="small"><?=st_e($x['job_title'])?></div></td><td><?php $leaderName='Haijawekwa'; foreach($allTechs as $lt){if((int)$lt['id']===(int)$x['site_leader_id']){$leaderName=$lt['full_name'];break;}} echo st_e($leaderName); ?></td><td><?=st_e($x['technician_name'])?></td><td><?=st_e($x['driver_name']?:'Hakuna')?></td><td><?=st_e($x['registration_no'].' — '.$x['vehicle_type'])?></td><td><?=st_e(($x['trip_status']??'-').' | '.($x['destination']??''))?></td><td><?=nl2br(st_e($x['materials_text']??''))?:'-'?></td><td><?=st_e($x['priority'])?></td><td><?=st_e($x['due_at']?:'-')?></td><td><span class="status"><?=st_e($x['status'])?></span></td><td><a class="btn small" href="?page=st_sites&edit=<?=$x['site_id']?>">✎ EDIT SITE</a></td></tr><?php endforeach;?><?php if(!$assignments):?><tr><td colspan="11">Hakuna assignment bado.</td></tr><?php endif;?></table></div>

<?php elseif($page==='st_tasks'): ?>
  <h2>✓ Tasks / Assignments</h2>
  <?php
    $drivers=$pdo->query("SELECT * FROM st_drivers WHERE status='Active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    $siteTasks=$pdo->query("SELECT t.*,s.site_name,
        CASE WHEN t.assigned_to_type='driver' THEN d.full_name ELSE tech.full_name END AS assigned_name,
        CASE WHEN t.assigned_to_type='driver' THEN 'driver' ELSE 'technician' END AS display_type
        FROM st_site_tasks t
        JOIN st_sites s ON s.id=t.site_id
        LEFT JOIN st_technicians tech ON tech.id=t.assigned_to AND t.assigned_to_type='technician'
        LEFT JOIN st_drivers d ON d.id=t.assigned_to AND t.assigned_to_type='driver'
        ORDER BY t.id DESC")->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <?php if(st_is_admin()||st_is_manager()):?>
    <div class="panel">
      <h3>➤ Create Site Task</h3><p class="muted">Task mpya huanza <b>New</b>. Fundi/Dereva ndiye anayekubali na kuanza; Manager/Admin ndiye anayeverify.</p>
      <form method="post" class="grid2">
        <input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>">
        <input type="hidden" name="st_action" value="st_save_site_task">
        <div class="field"><select name="site_id" required><option value="">Chagua Site</option><?php foreach($sites as $site):?><option value="<?=$site['id']?>"><?=st_e($site['site_name'])?></option><?php endforeach;?></select></div>
        <div class="field"><input name="task_title" placeholder="Task / Checklist item" required></div>
        <div class="field"><select name="assigned_to" required><option value="">Assign technician or driver</option><?php foreach($allTechs as $technician):?><option value="t_<?=$technician['id']?>"><?=st_e('Technician: '.$technician['full_name'])?></option><?php endforeach;?><?php foreach($drivers as $driver):?><option value="d_<?=$driver['id']?>"><?=st_e('Driver: '.$driver['full_name'])?></option><?php endforeach;?></select></div>
        <div class="field"><select name="assigned_to_type"><option value="technician" selected>Technician</option><option value="driver">Driver</option></select></div>
        <div class="field"><select name="task_type"><option value="site" selected>Site Task</option><option value="transport">Transport</option><option value="material">Material</option><option value="money">Money</option><option value="tool">Tool</option></select></div>
        <div class="field"><select name="priority"><option>Low</option><option selected>Normal</option><option>High</option><option>Urgent</option></select></div>
        <div class="field"><input type="date" name="due_at"></div>
        <div class="field"><textarea name="notes" placeholder="Notes"></textarea></div>
        <div><button class="btn">ADD TASK</button></div>
      </form>
    </div>
  <?php endif;?>

  <div class="panel table-wrap">
    <h3>✓ Site Task Ledger</h3>
    <table>
      <tr><th>Site</th><th>Task</th><th>Assigned To</th><th>Type</th><th>Priority</th><th>Due</th><th>Status</th><th>Action</th></tr>
      <?php foreach($siteTasks as $task):?>
      <tr>
        <td><?=st_e($task['site_name'])?></td>
        <td><?=st_e($task['task_title'])?><div class="small"><?=st_e($task['notes']??'')?></div></td>
        <td><?=st_e($task['assigned_name']??'Unassigned')?></td>
        <td><?=st_e($task['display_type']??$task['assigned_to_type'])?></td>
        <td><?=st_e($task['priority'])?></td>
        <td><?=st_e($task['due_at']?:'-')?></td>
        <td><span class="status"><?=st_e(($task['status']??'Pending')==='Pending'?'New':$task['status'])?></span><?php if(!empty($task['blocked_reason'])):?><div class="small"><b>Blocked:</b> <?=st_e($task['blocked_reason'])?></div><?php endif;?><?php if(!empty($task['task_update_notes'])):?><div class="small"><b>Update:</b> <?=st_e($task['task_update_notes'])?></div><?php endif;?></td>
        <td>
          <?php $ledgerStatus=(string)($task['status']??'Pending');if($ledgerStatus==='Pending')$ledgerStatus='New'; ?>
          <?php if($ledgerStatus==='Completed' && (st_is_admin()||st_is_manager())):?><form method="post" style="display:inline"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="st_action" value="st_update_site_task"><input type="hidden" name="id" value="<?=$task['id']?>"><button class="btn small" name="task_action" value="verify">VERIFY</button></form><?php elseif(in_array($ledgerStatus,['New','Accepted','In Progress','Blocked'],true)):?><span class="small muted">Mshiriki ndiye anafanya hatua hii</span><?php endif;?>
          <?php if(st_is_admin()):?> 
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this task?')">
              <input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>">
              <input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="st_action" value="st_delete_site_task">
              <input type="hidden" name="id" value="<?=$task['id']?>">
              <button class="btn danger small">DELETE</button>
            </form>
          <?php endif;?>
        </td>
      </tr>
      <?php endforeach;?>
      <?php if(!$siteTasks):?><tr><td colspan="8"><p class="muted">Hakuna tasks bado.</p></td></tr><?php endif;?>
    </table>
  </div>
<?php elseif($page==='st_tools'): ?>
  <?php
    $toolHolders=$pdo->query("SELECT t.id,t.tool_name,t.tool_code,t.serial_no,t.quantity,t.status,t.condition_status,t.photo,m.created_at issued_at,m.due_date,s.site_name,tech.full_name technician_name FROM st_tools t LEFT JOIN st_tool_movements m ON m.id=(SELECT mm.id FROM st_tool_movements mm WHERE mm.tool_id=t.id AND mm.action_type='Issued' AND COALESCE(mm.status,'') NOT IN ('Rejected','Returned') ORDER BY mm.id DESC LIMIT 1) LEFT JOIN st_technicians tech ON tech.id=m.technician_id LEFT JOIN st_sites s ON s.id=m.site_id WHERE t.status='Issued' ORDER BY tech.full_name,t.tool_name")->fetchAll(PDO::FETCH_ASSOC);
  ?>

  <div class="simple-page-head"><div><h2>Tools</h2><p class="muted">Usajili, orodha ya tools za ofisi na kuona nani ana tool.</p></div></div>

  <?php if($editTool):?>
  <div class="panel simple-form-card">
    <h3>Hariri Tool</h3>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>">
      <input type="hidden" name="st_action" value="st_edit_tool">
      <input type="hidden" name="id" value="<?=$editTool['id']?>">
      <div class="grid2">
        <div class="field"><input name="tool_code" placeholder="Tool Code" value="<?=st_e($editTool['tool_code'])?>" required></div>
        <div class="field"><input name="tool_name" placeholder="Tool Name" value="<?=st_e($editTool['tool_name'])?>" required></div>
        <div class="field"><input name="serial_no" placeholder="Serial Number" value="<?=st_e($editTool['serial_no'])?>"></div>
        <div class="field"><input type="number" name="quantity" min="1" value="<?=st_e($editTool['quantity'])?>"></div>
        <div class="field"><select name="condition_status"><?php foreach(['Good','Damaged','Missing'] as $s):?><option <?=($editTool['condition_status']===$s?'selected':'')?>><?=$s?></option><?php endforeach;?></select></div>
        <div class="field"><input name="storage_location" placeholder="Mahali ilipo ofisini" value="<?=st_e($editTool['storage_location']??'')?>"></div>
        <div class="field"><input type="file" name="tool_photo" accept="image/*" capture="environment"></div>
        <div class="field"><textarea name="notes" placeholder="Maelezo"><?=st_e($editTool['notes'])?></textarea></div>
      </div>
      <button class="btn">UPDATE TOOL</button> <a class="btn dark" href="?page=st_tools">CANCEL</a>
    </form>
  </div>
  <?php else:?>
  <div class="panel simple-form-card">
    <h3>Usajili wa Tools</h3>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>">
      <input type="hidden" name="st_action" value="st_save_tool">
      <div class="grid2">
        <div class="field"><input name="tool_code" placeholder="Tool Code / ID" required></div>
        <div class="field"><input name="tool_name" placeholder="Jina la Tool" required></div>
        <div class="field"><input name="serial_no" placeholder="Serial Number"></div>
        <div class="field"><input type="number" name="quantity" value="1" min="1" placeholder="Quantity"></div>
        <div class="field"><select name="condition_status"><option>Good</option><option>Damaged</option><option>Missing</option></select></div>
        <div class="field"><input type="file" name="tool_photo" accept="image/*" capture="environment"></div>
        <div class="field"><input type="date" name="purchase_date" placeholder="Purchase Date"></div>
        <div class="field"><input type="number" step="0.01" name="value_amount" placeholder="Thamani ya Tool"></div>
        <div class="field"><input name="storage_location" placeholder="Mahali ilipo ofisini"></div>
        <div class="field"><textarea name="registration_notes" placeholder="Maelezo ya usajili"></textarea></div>
        <div class="field full"><textarea name="notes" placeholder="Maelezo ya ziada"></textarea></div>
      </div>
      <button class="btn">SAJILI TOOL</button>
    </form>
  </div>
  <?php endif;?>

  <div class="panel table-wrap">
    <h3>Orodha ya Tools za Ofisi</h3>
    <table>
      <tr><th>Tool</th><th>Serial</th><th>Qty</th><th>Hali</th><th>Status</th><th>Action</th></tr>
      <?php foreach($tools as $x):?>
      <tr>
        <td><b><?=st_e($x['tool_name'])?></b><div class="small"><?=st_e($x['tool_code'])?></div></td>
        <td><?=st_e($x['serial_no']?:'-')?></td>
        <td><?=st_e($x['quantity'])?></td>
        <td><?=st_e($x['condition_status'])?></td>
        <td><?=st_e($x['status'])?></td>
        <td>
          <div class="actions">
            <a class="btn small" href="?page=st_tools&edit=<?=$x['id']?>">EDIT</a>
            <form method="post" onsubmit="return confirm('Delete this tool?')">
              <input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>">
              <input type="hidden" name="st_action" value="st_delete_tool">
              <input type="hidden" name="id" value="<?=$x['id']?>">
              <button class="btn danger small">DELETE</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach;?>
      <?php if(!$tools):?><tr><td colspan="6"><p class="muted">Hakuna tools zilizosajiliwa.</p></td></tr><?php endif;?>
    </table>
  </div>

  <div class="panel table-wrap">
    <h3>Nani Ana Tools</h3>
    <table>
      <tr><th>Fundi</th><th>Tool</th><th>Serial</th><th>Site</th><th>Alikabidhiwa</th><th>Kurudisha</th></tr>
      <?php foreach($toolHolders as $h):?>
      <tr>
        <td><b><?=st_e($h['technician_name']??'Haijulikani')?></b></td>
        <td><?=st_e($h['tool_name'])?><div class="small"><?=st_e($h['tool_code'])?></div></td>
        <td><?=st_e($h['serial_no']?:'-')?></td>
        <td><?=st_e($h['site_name']??'-')?></td>
        <td><?=st_e($h['issued_at']??'-')?></td>
        <td><?=st_e($h['due_date']??'-')?></td>
      </tr>
      <?php endforeach;?>
      <?php if(!$toolHolders):?><tr><td colspan="6"><p class="muted">Hakuna fundi mwenye tool kwa sasa.</p></td></tr><?php endif;?>
    </table>
  </div>

<?php elseif($page==='st_tool_report'): ?>
<?php
    $toolSummary=$pdo->query("SELECT COALESCE(SUM(CASE WHEN status='Issued' THEN 1 ELSE 0 END),0) issued,COALESCE(SUM(CASE WHEN status='Damaged' OR condition_status='Damaged' THEN 1 ELSE 0 END),0) damaged,COALESCE(SUM(CASE WHEN status='Missing' OR condition_status='Missing' THEN 1 ELSE 0 END),0) missing,COALESCE(SUM(CASE WHEN status='Available' AND condition_status='Good' THEN 1 ELSE 0 END),0) available FROM st_tools")->fetch(PDO::FETCH_ASSOC);
    $problemTools=$pdo->query("SELECT t.*,m.action_type,m.condition_status movement_condition,m.photo movement_photo,m.latitude,m.longitude,m.created_at movement_date,tech.full_name technician_name,s.site_name FROM st_tools t LEFT JOIN st_tool_movements m ON m.id=(SELECT mm.id FROM st_tool_movements mm WHERE mm.tool_id=t.id ORDER BY mm.id DESC LIMIT 1) LEFT JOIN st_technicians tech ON tech.id=m.technician_id LEFT JOIN st_sites s ON s.id=m.site_id WHERE t.status IN ('Damaged','Missing') OR t.condition_status IN ('Damaged','Missing') ORDER BY CASE WHEN t.status='Missing' OR t.condition_status='Missing' THEN 0 ELSE 1 END,t.tool_name")->fetchAll(PDO::FETCH_ASSOC);
    $issuedTools=$pdo->query("SELECT t.tool_name,t.tool_code,t.serial_no,t.status,m.due_date,m.photo,m.latitude,m.longitude,m.created_at,tech.full_name technician_name,s.site_name FROM st_tools t LEFT JOIN st_tool_movements m ON m.id=(SELECT mm.id FROM st_tool_movements mm WHERE mm.tool_id=t.id AND mm.action_type='Issued' ORDER BY mm.id DESC LIMIT 1) LEFT JOIN st_technicians tech ON tech.id=m.technician_id LEFT JOIN st_sites s ON s.id=m.site_id WHERE t.status='Issued' ORDER BY m.due_date IS NULL,m.due_date")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="head"><div><h2>âš ï¸ Tools Mbovu na Zilizopotea</h2><p class="muted">Report ya condition, fundi aliyekuwa na tool, site, picha, GPS na tarehe ya mwisho.</p></div><div class="actions no-print"><a class="btn" href="?page=st_export_report&type=tools">â¬‡ Export CSV</a><button class="btn" type="button" onclick="window.print()">ðŸ–¨ Print</button><a class="btn" href="?page=st_tools">Tools Register</a><a class="btn" href="?page=st_movements">Issue / Return</a></div></div>
<div class="cards"><div class="card"><span class="small">Available</span><b><?=st_e($toolSummary['available'])?></b></div><div class="card"><span class="small">Issued</span><b><?=st_e($toolSummary['issued'])?></b></div><div class="card"><span class="small">Damaged</span><b class="low"><?=st_e($toolSummary['damaged'])?></b></div><div class="card"><span class="small">Missing</span><b class="low"><?=st_e($toolSummary['missing'])?></b></div></div>
<div class="panel"><h3>ðŸ”´ Tools Damaged / Missing</h3><div class="table-wrap"><table><tr><th>Tool</th><th>Condition</th><th>Fundi wa mwisho</th><th>Site</th><th>Photo</th><th>GPS</th><th>Tarehe</th></tr><?php foreach($problemTools as $tool): ?><tr><td><b><?=st_e($tool['tool_name'])?></b><div class="small"><?=st_e($tool['tool_code'])?> Â· <?=st_e($tool['serial_no'])?></div></td><td><span class="status"><?=st_e($tool['status']==='Missing'||$tool['condition_status']==='Missing'?'Missing':'Damaged')?></span></td><td><?=st_e($tool['technician_name']??'Haijulikani')?></td><td><?=st_e($tool['site_name']??'')?></td><td><?=st_photo($tool['movement_photo']??'')?></td><td><?php if($tool['latitude']!==null&&$tool['longitude']!==null): ?><a class="btn small" target="_blank" href="<?=st_e(st_google_map_url($tool['latitude'],$tool['longitude']))?>">Google Map</a><?php else: ?>-<?php endif; ?></td><td><?=st_e($tool['movement_date']??'')?></td></tr><?php endforeach; ?><?php if(!$problemTools): ?><tr><td colspan="7">Hakuna tool mbovu au iliyopotea.</td></tr><?php endif; ?></table></div></div>
<div class="panel"><h3>ðŸŸ¡ Tools Zilizo kwa Mafundi</h3><div class="table-wrap"><table><tr><th>Tool</th><th>Fundi</th><th>Site</th><th>Due date</th><th>Photo/GPS</th></tr><?php foreach($issuedTools as $tool): ?><tr><td><?=st_e($tool['tool_name'].' - '.$tool['tool_code'])?></td><td><?=st_e($tool['technician_name']??'')?></td><td><?=st_e($tool['site_name']??'')?></td><td><?=st_e($tool['due_date']??'')?></td><td><?=st_photo($tool['photo']??'')?> <?php if($tool['latitude']!==null&&$tool['longitude']!==null): ?><a class="btn small" target="_blank" href="<?=st_e(st_google_map_url($tool['latitude'],$tool['longitude']))?>">Map</a><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$issuedTools): ?><tr><td colspan="5">Hakuna tool iliyo kwa fundi.</td></tr><?php endif; ?></table></div></div>

<?php elseif($page==='st_materials'): ?>
  <h2>ðŸ“¦ Materials</h2>
  <?php if($editMaterial):?>
  <div class="panel"><h3>&#9998; Edit Material</h3><form method="post"><input type="hidden" name="st_action" value="st_edit_material"><input type="hidden" name="id" value="<?=$editMaterial['id']?>">
    <div class="grid2"><div class="field"><input name="material_name" value="<?=st_e($editMaterial['material_name'])?>" required></div><div class="field"><input name="unit" value="<?=st_e($editMaterial['unit'])?>"></div>
    <div class="field"><textarea name="notes"><?=st_e($editMaterial['notes'])?></textarea></div></div>
    <button class="btn">UPDATE MATERIAL</button> <a class="btn dark" href="?page=st_materials">CANCEL</a>
  </form></div>
  <?php else:?>
  <div class="panel"><form method="post"><input type="hidden" name="st_action" value="st_save_material"><div class="grid2">
    <div class="field"><input name="material_name" placeholder="Material Name" required></div><div class="field"><input name="unit" value="pcs" placeholder="Unit"></div>
    <div class="field full"><textarea name="notes" placeholder="Notes"></textarea></div>
  </div><button class="btn">SAVE MATERIAL</button></form></div><?php endif;?>

  <div class="panel"><p class="muted">Materials hapa si stock/store. Ni kumbukumbu ya majina tu. Assignment Center inaruhusu Administrator kuandika material moja kwa moja bila kupunguza stock.</p></div>
  <div class="panel table-wrap"><table><tr><th>Material</th><th>Unit</th><th>Notes</th><th>Action</th></tr>
  <?php foreach($materials as $x):?><tr><td><?=st_e($x['material_name'])?></td><td><?=st_e($x['unit'])?></td><td><?=st_e($x['notes'])?></td>
    <td><div class="actions"><a class="btn small" href="?page=st_materials&edit=<?=$x['id']?>">&#9998; EDIT</a><form method="post" onsubmit="return confirm('Delete this material?')"><input type="hidden" name="st_action" value="st_delete_material"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="btn danger small">&#128465; DELETE</button></form></div></td>
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
  <h2>ðŸ“¦ Material Report</h2>
  <div class="panel"><p class="muted">Ripoti hii inaonyesha material iliyochukuliwa, quantity, fundi, site, customer, mafundi wengine wa site, picha, tarehe na status.</p>
  <div class="table-wrap"><table><tr><th>Material</th><th>Aina</th><th>Qty</th><th>Fundi</th><th>Site</th><th>Customer</th><th>Mafundi wengine</th><th>Picha</th><th>GPS</th><th>Tarehe</th><th>Status</th></tr>
  <?php foreach($report as $r):
    $team=[];if(!empty($r['site_id'])){$ids=st_site_team_ids($pdo,(int)$r['site_id']);foreach($allTechs as $t)if(in_array((int)$t['id'],$ids,true) && (int)$t['id']!==(int)$r['technician_id'])$team[]=$t['full_name'];}
  ?><tr>
    <td><?=st_e($r['material_name'])?></td><td><?=st_e($r['movement_type']??'Issued')?></td><td><?=st_e($r['quantity'])?> <?=st_e($r['unit'])?></td><td><?=st_e($r['technician_name'])?></td>
    <td><?=st_e($r['site_name']??'')?></td><td><?=st_e($r['customer_name']??'')?></td><td><?=st_e(implode(', ',$team))?></td>
    <td><?=st_photo($r['photo']??'')?><?php if(!empty($r['received_photo'])):?><div class="small">ðŸ“¦ <?=st_photo($r['received_photo'])?></div><?php endif;?></td><td><?php if($r['latitude']!==null && $r['longitude']!==null):?><a class="btn small" target="_blank" href="<?=st_e(st_google_map_url($r['latitude'],$r['longitude']))?>">ðŸ—ºï¸ Google Map</a><div class="small"><?=st_e($r['latitude'])?>, <?=st_e($r['longitude'])?></div><?php else:?>-<?php endif;?></td><td><?=st_e($r['created_at'])?></td><td><span class="status"><?=st_e($r['status'])?></span></td>
  </tr><?php endforeach;?>
  </table></div></div>

<?php elseif($page==='st_money_transfer'): ?>
    <?php
        $moneyTransfers=$pdo->query("SELECT m.*,s.site_name,s.customer_name,CASE WHEN m.recipient_type='driver' THEN d.full_name ELSE t.full_name END AS recipient_name,CASE WHEN m.recipient_type='driver' THEN d.phone ELSE t.phone END AS recipient_phone FROM st_money_transactions m LEFT JOIN st_sites s ON s.id=m.site_id LEFT JOIN st_drivers d ON d.id=m.recipient_id AND m.recipient_type='driver' LEFT JOIN st_technicians t ON t.id=m.recipient_id AND m.recipient_type='technician' ORDER BY m.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
        $moneyTotal=(float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM st_money_transactions')->fetchColumn();
        $moneyPending=(int)$pdo->query("SELECT COUNT(*) FROM st_money_transactions WHERE status='Granted'")->fetchColumn();
        $moneyReceipts=(int)$pdo->query("SELECT COUNT(*) FROM st_money_transactions WHERE status='Receipt Submitted'")->fetchColumn();
        $moneyActiveDrivers=$pdo->query("SELECT id,full_name,phone FROM st_drivers WHERE status='Active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    ?>
>
    <h2>&#128176; Money Transfer</h2>
    <div class="notice"><b>Ushauri wa usimamizi wa pesa:</b> Tuma pesa baada ya kuainisha site na sababu, Mpokeaji ataona pesa, athibitishe kuwa ameipokea, kisha atume risiti. Rekodi zote hapa zinaonekana kwenye audit history.</div>
    <div class="cards">
        <div class="card"><span class="small">Jumla ya pesa zilizotolewa</span><b><?=st_e(number_format($moneyTotal,2).' TZS')?></b></div>
        <div class="card"><span class="small">Transfers zinazosubiri risiti</span><b><?=st_e($moneyPending)?></b></div>
        <div class="card"><span class="small">Risiti zilizowasilishwa</span><b><?=st_e($moneyReceipts)?></b></div>
    </div>
    <div class="panel"><h3>💸 Tuma Pesa kwa Fundi au Dereva</h3><form method="post" class="grid2"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="st_action" value="st_grant_money"><div class="field"><label>Aina ya mpokeaji<select name="recipient_type" required><option value="technician">Fundi</option><option value="driver">Dereva</option></select></label></div><div class="field"><label>Mpokeaji<select name="recipient_id" required><option value="">Chagua</option><?php foreach($allTechs as $t):?><option value="<?=$t['id']?>"><?=st_e('Fundi: '.$t['full_name'])?></option><?php endforeach;?><?php foreach($moneyActiveDrivers as $d):?><option value="<?=$d['id']?>"><?=st_e('Dereva: '.$d['full_name'])?></option><?php endforeach;?></select></label></div><div class="field"><label>Site <span class="small muted">(optional)</span><select name="site_id"><option value="">-- Bila Site --</option><?php foreach($sites as $site):?><option value="<?=$site['id']?>"><?=st_e($site['site_name'])?></option><?php endforeach;?></select></label></div><div class="field"><label>Kiasi<input type="number" name="amount" step="0.01" min="0.01" required></label></div><div class="field"><label>Currency<input name="currency" value="TZS"></label></div><div class="field full"><label>Purpose<textarea name="purpose" required placeholder="Transport, mafuta, material, accommodation, etc."></textarea></label></div><div><button class="btn">TUMA PESA</button></div></form></div>
<div class="panel"><h3>ðŸ“‹ Historia ya Money Transfer na Risiti</h3><div class="table-wrap"><table>
        <tr><th>Tarehe</th><th>Mpokeaji</th><th>Site</th><th>Kiasi</th><th>Sababu</th><th>Aliyetuma</th><th>Amepokea</th><th>Aliyeidhinisha</th><th>Status</th><th>Risiti</th><th>GPS</th></tr>
        <?php foreach($moneyTransfers as $transfer):
            $receiptEvidence=$pdo->prepare("SELECT photo FROM st_evidence_photos WHERE entity_type='money_transaction' AND entity_id=? AND category='Money Receipt' ORDER BY id");
            $receiptEvidence->execute([(int)$transfer['id']]);$receiptEvidence=$receiptEvidence->fetchAll(PDO::FETCH_COLUMN);
        ?><tr>
            <td><?=st_e($transfer['granted_at']??$transfer['created_at'])?></td>
            <td><b><?=st_e($transfer['recipient_name'])?></b><div class="small"><?=st_e($transfer['recipient_phone']??'')?></div></td>
            <td><?=st_e($transfer['site_name'])?><div class="small"><?=st_e($transfer['customer_name']??'')?></div></td>
            <td><b><?=st_e($transfer['amount'].' '.$transfer['currency'])?></b></td>
            <td><?=nl2br(st_e($transfer['purpose']??''))?></td>
            <td><?=st_e($transfer['granted_by']??'')?></td>
            <td><?=!empty($transfer['received_at'])?st_e(($transfer['received_by']??'').' @ '.$transfer['received_at']):'BADO'?></td>
            <td><?=st_e($transfer['approved_by']??'Bado')?><div class="small"><?=st_e($transfer['approved_at']??'')?></div></td>
            <td><span class="status"><?=st_e($transfer['status'])?></span><?php if(!empty($transfer['receipt_submitted_at'])):?><div class="small">Risiti: <?=st_e($transfer['receipt_submitted_at'])?></div><?php endif;?></td>
            <td><?php if(!empty($transfer['receipt_photo'])):?><?=st_photo($transfer['receipt_photo'])?><?php else:?>Haijatumwa<?php endif;?><?php foreach($receiptEvidence as $receiptPhoto):?><?=st_photo($receiptPhoto)?><?php endforeach;?><?php if(!empty($transfer['receipt_notes'])):?><div class="small"><?=st_e($transfer['receipt_notes'])?></div><?php endif;?></td>
            <td><?php if($transfer['receipt_latitude']!==null&&$transfer['receipt_longitude']!==null):?><a class="btn small" target="_blank" href="<?=st_e(st_google_map_url($transfer['receipt_latitude'],$transfer['receipt_longitude']))?>">ðŸ—ºï¸ Map</a><?php else:?>-<?php endif;?></td>
        </tr><?php endforeach;?>
    </table></div><?php if(!$moneyTransfers):?><p class="muted">Hakuna money transfer bado.</p><?php endif;?></div>

<?php elseif($page==='st_movements'): ?>
  <?php if(st_is_technician()):?>
    <h2>🔧 Tools Zangu</h2>
    <div class="panel">
      <h3>&#128295; <?=zan_t('Kuchukua Tool','Receive Tool')?></h3>
      <form method="post" enctype="multipart/form-data" class="gps-form"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="st_action" value="st_issue_tool">
        <div class="field"><select name="tool_id" required><option value="">-- Tool --</option><?php foreach($tools as $x):?><option value="<?=$x['id']?>"><?=st_e($x['tool_name'].' - '.$x['tool_code'])?></option><?php endforeach;?></select></div>
        <div class="field"><select name="site_id" required><option value="">-- Site --</option><?php foreach($sites as $x):?><option value="<?=$x['id']?>"><?=st_e($x['site_name'])?></option><?php endforeach;?></select></div>
        <div class="field"><input type="date" name="due_date"></div><div class="field"><select name="condition_status"><option>Good</option><option>Damaged</option></select></div>
        <div class="field">ðŸ“· Photo<input type="file" name="photo" accept="image/*" capture="environment" required></div><input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><input type="hidden" name="captured_at" class="gps-time"><button type="button" class="btn gps-btn">ðŸ“ GPS</button> <span class="gps-status small">Location not captured yet</span><div class="field"><textarea name="notes"></textarea></div>
        <button class="btn">SUBMIT TOOL</button>
      </form>
    </div>
  <?php else:?>
    <h2>🔧 Tool Movements</h2>
    <div class="grid2">
    <div class="panel" id="issue-tool"><h3>&#128295; Issue / Return Tool</h3><form method="post" enctype="multipart/form-data" class="gps-form"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="st_action" value="st_issue_tool">
        <div class="field"><select name="tool_id" required><?php foreach($tools as $x):?><option value="<?=$x['id']?>"><?=st_e($x['tool_name'].' - '.$x['tool_code'])?></option><?php endforeach;?></select></div>
        <div class="field"><select name="technician_id" required><?php foreach($allTechs as $x):?><option value="<?=$x['id']?>" <?=((int)($_GET['technician_id']??0)===(int)$x['id']?'selected':'')?>><?=st_e($x['full_name'])?></option><?php endforeach;?></select></div>
        <div class="field"><select name="site_id"><option value="">Select Site</option><?php foreach($sites as $x):?><option value="<?=$x['id']?>"><?=st_e($x['site_name'])?></option><?php endforeach;?></select></div>
        <div class="field"><input name="customer_name" placeholder="Customer Name"></div><div class="field"><input type="date" name="due_date"></div>
        <div class="field"><select name="condition_status"><option>Good</option><option>Damaged</option></select></div>
        <div class="field">ðŸ“· Photo<input type="file" name="photo" accept="image/*" capture="environment" required></div><input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><input type="hidden" name="captured_at" class="gps-time"><button type="button" class="btn gps-btn">ðŸ“ GPS</button> <span class="gps-status small">Location not captured yet</span><div class="field"><textarea name="notes"></textarea></div>
        <button class="btn">ISSUE TOOL</button></form>
        <hr><form method="post" enctype="multipart/form-data" class="gps-form"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="st_action" value="st_return_tool">
        <div class="field"><select name="tool_id"><?php foreach($tools as $x):?><option value="<?=$x['id']?>"><?=st_e($x['tool_name'])?></option><?php endforeach;?></select></div>
        <div class="field"><select name="technician_id"><?php foreach($allTechs as $x):?><option value="<?=$x['id']?>"><?=st_e($x['full_name'])?></option><?php endforeach;?></select></div>
        <div class="field"><select name="condition_status"><option>Good</option><option>Damaged</option><option>Missing</option></select></div>
        <div class="field">ðŸ“· Return Photo<input type="file" name="photo" accept="image/*" capture="environment" required></div><input type="hidden" name="latitude" class="gps-lat"><input type="hidden" name="longitude" class="gps-lng"><input type="hidden" name="accuracy" class="gps-accuracy"><input type="hidden" name="captured_at" class="gps-time"><button type="button" class="btn gps-btn">ðŸ“ GPS</button> <span class="gps-status small">Location not captured yet</span> <button class="btn">SUBMIT RETURN</button></form>
      </div>
          <?php endif;?>

<?php elseif($page==='st_transport_reports'): ?>
<?php
    $rfFrom=trim($_GET['from']??''); $rfTo=trim($_GET['to']??''); $rfVehicle=(int)($_GET['vehicle_id']??0); $rfDriver=(int)($_GET['driver_id']??0); $rfStatus=trim($_GET['status']??'');
    $where=[];$params=[];
    if($rfFrom!==''){$where[]="date(tr.departure_at)>=date(?)";$params[]=$rfFrom;}
    if($rfTo!==''){$where[]="date(tr.departure_at)<=date(?)";$params[]=$rfTo;}
    if($rfVehicle>0){$where[]='tr.vehicle_id=?';$params[]=$rfVehicle;}
    if($rfDriver>0){$where[]='tr.driver_id=?';$params[]=$rfDriver;}
    if(in_array($rfStatus,['Open','Closed'],true)){$where[]='tr.status=?';$params[]=$rfStatus;}
    $filterSql=$where?' WHERE '.implode(' AND ',$where):'';
    $q=$pdo->prepare("SELECT tr.*,v.registration_no,v.vehicle_type,v.make_model,d.full_name driver_name FROM st_transport_trips tr JOIN st_vehicles v ON v.id=tr.vehicle_id LEFT JOIN st_drivers d ON d.id=tr.driver_id $filterSql ORDER BY tr.id DESC LIMIT 1000");$q->execute($params);$trRows=$q->fetchAll(PDO::FETCH_ASSOC);
    $trVehicles=$pdo->query('SELECT id,registration_no,vehicle_type,make_model FROM st_vehicles ORDER BY registration_no')->fetchAll(PDO::FETCH_ASSOC);
    $trDrivers=$pdo->query("SELECT id,full_name FROM st_drivers ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    $trTotal=count($trRows);$trOpen=count(array_filter($trRows,fn($r)=>$r['status']==='Open'));$trClosed=count(array_filter($trRows,fn($r)=>$r['status']==='Closed'));
    $trKm=0;$trDistanceRows=0;foreach($trRows as $r){if($r['end_odometer']!==null&&(float)$r['end_odometer']>=(float)$r['start_odometer']){$trKm+=(float)$r['end_odometer']-(float)$r['start_odometer'];$trDistanceRows++;}}
?>
<div class="head"><div><h2>📊 Transport Reports</h2><p class="muted">Ripoti kamili ya safari, magari, madereva, kilomita na status.</p></div><div class="actions no-print"><a class="btn" href="?page=st_export_report&type=transport">Export CSV</a><button class="btn" type="button" onclick="window.print()">Print</button><a class="btn dark" href="?page=st_transport&section=dashboard">Usafiri</a></div></div>
<div class="panel no-print"><form method="get" class="grid2"><input type="hidden" name="page" value="st_transport_reports"><div class="field"><label>Kuanzia</label><input type="date" name="from" value="<?=st_e($rfFrom)?>"></div><div class="field"><label>Mpaka</label><input type="date" name="to" value="<?=st_e($rfTo)?>"></div><div class="field"><label>Gari / Pikipiki</label><select name="vehicle_id"><option value="0">Vyote</option><?php foreach($trVehicles as $v):?><option value="<?=$v['id']?>" <?=$rfVehicle===(int)$v['id']?'selected':''?>><?=st_e($v['registration_no'].' - '.$v['vehicle_type'].' '.$v['make_model'])?></option><?php endforeach;?></select></div><div class="field"><label>Dereva</label><select name="driver_id"><option value="0">Wote</option><?php foreach($trDrivers as $d):?><option value="<?=$d['id']?>" <?=$rfDriver===(int)$d['id']?'selected':''?>><?=st_e($d['full_name'])?></option><?php endforeach;?></select></div><div class="field"><label>Status</label><select name="status"><option value="">Zote</option><option value="Open" <?=$rfStatus==='Open'?'selected':''?>>Open</option><option value="Closed" <?=$rfStatus==='Closed'?'selected':''?>>Closed</option></select></div><div class="field" style="align-self:end"><button class="btn">TAFUTA RIPOTI</button> <a class="btn dark" href="?page=st_transport_reports">CLEAR</a></div></form></div>
<div class="cards"><div class="card"><span class="small">Safari</span><b><?=$trTotal?></b></div><div class="card"><span class="small">Open</span><b><?=$trOpen?></b></div><div class="card"><span class="small">Closed</span><b><?=$trClosed?></b></div><div class="card"><span class="small">Jumla Km</span><b><?=number_format($trKm,1)?></b></div></div>
<div class="panel"><h3>Safari kwa tarehe</h3><div class="table-wrap"><table><tr><th>Tarehe</th><th>Chombo</th><th>Dereva</th><th>Destination</th><th>Purpose</th><th>Kuondoka</th><th>Kurudi</th><th>Km</th><th>Status</th></tr><?php foreach($trRows as $r):$km=($r['end_odometer']!==null&&(float)$r['end_odometer']>=(float)$r['start_odometer'])?(float)$r['end_odometer']-(float)$r['start_odometer']:null;?><tr><td><?=st_e(substr((string)$r['departure_at'],0,10))?></td><td><?=st_e($r['registration_no'].' - '.$r['vehicle_type'])?></td><td><?=st_e($r['driver_name'])?></td><td><?=st_e($r['destination'])?></td><td><?=st_e($r['purpose'])?></td><td><?=st_e($r['start_odometer'])?></td><td><?=st_e($r['end_odometer']??'-')?></td><td><?=$km===null?'-':number_format($km,1)?></td><td><span class="status"><?=st_e($r['status'])?></span></td></tr><?php endforeach;?><?php if(!$trRows):?><tr><td colspan="9">Hakuna safari kwa filter uliyochagua.</td></tr><?php endif;?></table></div></div>

<?php elseif($page==='st_transport'): ?>
<?php
    $transportSection=$_GET['section']??'dashboard';$validTransportSections=['dashboard','trips','vehicles','drivers','requests'];if(!in_array($transportSection,$validTransportSections,true))$transportSection='dashboard';
    $transportVehicles=$pdo->query('SELECT * FROM st_vehicles ORDER BY registration_no')->fetchAll(PDO::FETCH_ASSOC);
    $availableTransportVehicles=array_values(array_filter($transportVehicles,fn($v)=>!in_array(strtolower(trim((string)($v['status']??''))),['on trip','inactive','broken down','defective vehicle'],true)));
    $transportDrivers=$pdo->query("SELECT * FROM st_drivers WHERE status='Active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    $driverList=$pdo->query("SELECT * FROM st_drivers ORDER BY CASE WHEN status='Active' THEN 0 ELSE 1 END,full_name")->fetchAll(PDO::FETCH_ASSOC);
    $transportTrips=$pdo->query("SELECT tr.*,v.registration_no,v.vehicle_type,v.make_model,d.full_name driver_name FROM st_transport_trips tr JOIN st_vehicles v ON v.id=tr.vehicle_id LEFT JOIN st_drivers d ON d.id=tr.driver_id ORDER BY CASE WHEN tr.status='Open' THEN 0 ELSE 1 END,tr.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    $transportRequests=$pdo->query("SELECT r.*,d.full_name driver_name,v.registration_no FROM st_transport_requests r JOIN st_drivers d ON d.id=r.driver_id LEFT JOIN st_transport_trips tr ON tr.id=r.trip_id LEFT JOIN st_vehicles v ON v.id=tr.vehicle_id ORDER BY CASE WHEN r.status='Pending' THEN 0 ELSE 1 END,r.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    $openTripsCount=(int)$pdo->query("SELECT COUNT(*) FROM st_transport_trips WHERE status='Open'")->fetchColumn();$closedTripsCount=(int)$pdo->query("SELECT COUNT(*) FROM st_transport_trips WHERE status='Closed'")->fetchColumn();$availableVehicles=count($availableTransportVehicles);$onTripVehicles=(int)$pdo->query("SELECT COUNT(*) FROM st_vehicles WHERE status='On Trip'")->fetchColumn();$defectiveVehicles=(int)$pdo->query("SELECT COUNT(*) FROM st_vehicles WHERE status IN ('Broken Down','Defective Vehicle')")->fetchColumn();$pendingTransportRequests=(int)$pdo->query("SELECT COUNT(*) FROM st_transport_requests WHERE status='Pending'")->fetchColumn();
    $dueVehicles=array_filter($transportVehicles,fn($v)=>!empty($v['service_due'])&&$v['service_due']<=date('Y-m-d',strtotime('+30 days')));
?>
<div class="transport-module">
<div class="transport-nav no-print"><a class="<?=$transportSection==='dashboard'?'active':''?>" href="?page=st_transport&section=dashboard">Dashboard</a><a class="<?=$transportSection==='trips'?'active':''?>" href="?page=st_transport&section=trips">Safari</a><a class="<?=$transportSection==='vehicles'?'active':''?>" href="?page=st_transport&section=vehicles">Magari / Pikipiki</a><a class="<?=$transportSection==='drivers'?'active':''?>" href="?page=st_transport&section=drivers">Madereva</a><a class="<?=$transportSection==='requests'?'active':''?>" href="?page=st_transport&section=requests">Transport Requests</a><a href="?page=st_transport_reports">Reports</a></div>
<div class="head"><div><h2>🚗 Usafiri</h2><p class="muted">Control ya safari, vyombo, madereva, requests na reports.</p></div><div class="actions no-print"><a class="btn" href="?page=st_transport&section=trips">+ Panga Safari</a><a class="btn" href="?page=st_transport_reports">Reports</a></div></div>
<div data-transport-panel="dashboard" class="grid4 transport-section-panel" style="margin-bottom:16px"><div class="panel"><b>Safari Active</b><div class="metric"><?=$openTripsCount?></div></div><div class="panel"><b>Vyombo Available</b><div class="metric"><?=$availableVehicles?></div></div><div class="panel"><b>Vyombo On Trip</b><div class="metric"><?=$onTripVehicles?></div></div><div class="panel"><b>Defective Vehicle</b><div class="metric"><?=$defectiveVehicles?></div></div><div class="panel"><b>Madereva Active</b><div class="metric"><?=count($transportDrivers)?></div></div><div class="panel"><b>Requests Pending</b><div class="metric"><?=$pendingTransportRequests?></div></div><div class="panel"><b>Safari Closed</b><div class="metric"><?=$closedTripsCount?></div></div><div class="panel"><b>Service ndani ya 30d</b><div class="metric"><?=count($dueVehicles)?></div></div></div>
<div data-transport-panel="dashboard" class="grid2 transport-section-panel"><div class="panel"><h3>🟢 Vyombo Available Sasa</h3><div class="table-wrap"><table><tr><th>Usajili</th><th>Aina</th><th>Model</th></tr><?php foreach($transportVehicles as $vv): if(!in_array(strtolower(trim((string)($vv['status']??''))),['on trip','inactive','broken down','defective vehicle'],true)):?><tr><td><b><?=st_e($vv['registration_no'])?></b></td><td><?=st_e($vv['vehicle_type'])?></td><td><?=st_e($vv['make_model'])?></td></tr><?php endif; endforeach;?><?php if(!$availableTransportVehicles):?><tr><td colspan="3">Hakuna chombo available.</td></tr><?php endif;?></table></div></div><div class="panel"><h3>🛠 Service / Insurance Inayokaribia</h3><div class="table-wrap"><table><tr><th>Chombo</th><th>Service</th><th>Insurance</th></tr><?php foreach($dueVehicles as $dv):?><tr><td><b><?=st_e($dv['registration_no'])?></b></td><td><?=st_e($dv['service_due']??'-')?></td><td><?=st_e($dv['insurance_expiry']??'-')?></td></tr><?php endforeach;?><?php if(!$dueVehicles):?><tr><td colspan="3">Hakuna inayokaribia ndani ya siku 30.</td></tr><?php endif;?></table></div></div></div>
<div data-transport-panel="dashboard" class="panel transport-section-panel"><h3>Safari Zinazoendelea</h3><div class="table-wrap"><table><tr><th>Safari</th><th>Chombo</th><th>Dereva</th><th>Destination</th><th>Departure</th><th>Km Start</th><th>GPS</th></tr><?php $activeTrips=array_filter($transportTrips,fn($t)=>$t['status']==='Open');foreach(array_slice($activeTrips,0,12) as $trip):?><tr><td>#<?=st_e($trip['id'])?></td><td><?=st_e($trip['registration_no'])?></td><td><?=st_e($trip['driver_name']?:'Hakuna dereva')?></td><td><?=st_e($trip['destination'])?></td><td><?=st_e($trip['departure_at'])?></td><td><?=st_e($trip['start_odometer'])?></td><td><?php if($trip['start_latitude']!==null&&$trip['start_longitude']!==null):?><a class="btn small" target="_blank" href="<?=st_e(st_google_map_url($trip['start_latitude'],$trip['start_longitude']))?>">MAP</a><?php else:?>-<?php endif;?></td></tr><?php endforeach;?><?php if(!$activeTrips):?><tr><td colspan="7">Hakuna safari inayoendelea.</td></tr><?php endif;?></table></div></div>
<div data-transport-panel="vehicles" class="panel transport-section-panel"><div class="head"><div><h3>Magari / Pikipiki</h3><p class="muted">Available, On Trip, insurance na service.</p></div></div><div class="table-wrap"><table><tr><th>Usajili</th><th>Aina</th><th>Model</th><th>Ownership</th><th>Insurance</th><th>Service</th><th>Status</th><th>Action</th></tr><?php foreach($transportVehicles as $v):?><tr><td><b><?=st_e($v['registration_no'])?></b></td><td><?=st_e($v['vehicle_type'])?></td><td><?=st_e($v['make_model'])?></td><td><?=st_e($v['ownership']??'')?></td><td><?=st_e($v['insurance_expiry']??'-')?></td><td><?=st_e($v['service_due']??'-')?></td><td><span class="status"><?=st_e($v['status'])?></span></td><td><a class="btn small" href="?page=st_transport&section=vehicles&edit_vehicle=<?=$v['id']?>">EDIT</a> <form method="post" style="display:inline" onsubmit="return confirm('Futa gari/pikipiki hii? Kama ina history ya safari mfumo utakataa.')"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="st_action" value="st_delete_vehicle"><input type="hidden" name="id" value="<?=$v['id']?>"><button class="btn danger small">DELETE</button></form></td></tr><?php endforeach;?><?php if(!$transportVehicles):?><tr><td colspan="8">Hakuna chombo.</td></tr><?php endif;?></table></div></div>
<?php if(st_is_admin()):?><div data-transport-panel="vehicles" class="panel transport-section-panel"><h3><?=$editVehicle?'Edit Vehicle':'Sajili Gari / Pikipiki'?></h3><form method="post" class="grid2"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="st_action" value="st_save_vehicle"><input type="hidden" name="id" value="<?=$editVehicle['id']??0?>"><div class="field"><label>Usajili</label><input name="registration_no" value="<?=st_e($editVehicle['registration_no']??'')?>" required></div><div class="field"><label>Aina</label><select name="vehicle_type"><?php foreach(['Car','Motorcycle','Van','Truck'] as $x):?><option <?=$x===($editVehicle['vehicle_type']??'Car')?'selected':''?>><?=$x?></option><?php endforeach;?></select></div><div class="field"><label>Make / Model</label><input name="make_model" value="<?=st_e($editVehicle['make_model']??'')?>"></div><div class="field"><label>Ownership</label><select name="ownership"><?php foreach(['Company','Leased','Hired'] as $x):?><option <?=$x===($editVehicle['ownership']??'Company')?'selected':''?>><?=$x?></option><?php endforeach;?></select></div><div class="field"><label>Insurance expiry</label><input type="date" name="insurance_expiry" value="<?=st_e($editVehicle['insurance_expiry']??'')?>"></div><div class="field"><label>Service due</label><input type="date" name="service_due" value="<?=st_e($editVehicle['service_due']??'')?>"></div><div class="field"><label>Status</label><select name="status"><option <?=$editVehicle&&$editVehicle['status']==='Available'?'selected':(!$editVehicle?'selected':'')?>>Available</option><option <?=$editVehicle&&$editVehicle['status']==='On Trip'?'selected':''?>>On Trip</option><option <?=$editVehicle&&$editVehicle['status']==='Broken Down'?'selected':''?>>Broken Down</option><option <?=$editVehicle&&$editVehicle['status']==='Defective Vehicle'?'selected':''?>>Defective Vehicle</option><option <?=$editVehicle&&$editVehicle['status']==='Inactive'?'selected':''?>>Inactive</option></select></div><div class="field full"><textarea name="notes" placeholder="Maelezo"><?=st_e($editVehicle['notes']??'')?></textarea></div><div><button class="btn"><?=$editVehicle?'UPDATE VEHICLE':'SAVE VEHICLE'?></button> <?php if($editVehicle):?><a class="btn dark" href="?page=st_transport&section=vehicles">CANCEL</a><?php endif;?></div></form></div><?php endif;?>
<div data-transport-panel="drivers" class="panel transport-section-panel"><div class="head"><div><h3>Madereva</h3><p class="muted">Active na Inactive drivers.</p></div></div><div class="table-wrap"><table><tr><th>Picha</th><th>Jina</th><th>Simu</th><th>Leseni</th><th>Expiry</th><th>Status</th><th>Action</th></tr><?php foreach($driverList as $d):?><tr><td><?=st_photo($d['photo']??'','passport')?></td><td><b><?=st_e($d['full_name'])?></b><div class="small"><?=st_e($d['username']??'')?></div></td><td><?=st_e($d['phone'])?></td><td><?=st_e($d['license_no'])?></td><td><?=st_e($d['license_expiry']??'-')?></td><td><span class="status"><?=st_e($d['status'])?></span></td><td><a class="btn small" href="?page=st_transport&section=drivers&edit_driver=<?=$d['id']?>">EDIT</a><form method="post" style="display:inline" onsubmit="return confirm('Futa dereva huyu? Kama ana history ya safari mfumo utakataa kufuta.')"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="st_action" value="st_delete_driver"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="id" value="<?=$d['id']?>"><button class="btn danger small">DELETE</button></form></td></tr><?php endforeach;?></table></div></div>
<?php if(st_is_admin()):?><div data-transport-panel="drivers" class="panel transport-section-panel"><h3><?=$editDriver?'Edit Driver':'Sajili Dereva'?></h3><form method="post" enctype="multipart/form-data" class="grid2"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="st_action" value="st_save_driver"><input type="hidden" name="id" value="<?=$editDriver['id']??0?>"><div class="field"><label>Jina kamili</label><input name="full_name" value="<?=st_e($editDriver['full_name']??'')?>" required></div><div class="field"><label>Username</label><input name="username" value="<?=st_e($editDriver['username']??'')?>" required></div><div class="field"><label>Password</label><input type="password" name="password" placeholder="<?=$editDriver?'Optional':'Required'?>" <?=$editDriver?'':'required'?>></div><div class="field"><label>Simu</label><input name="phone" value="<?=st_e($editDriver['phone']??'')?>"></div><div class="field"><label>Picha ya Passport / Wasifu</label><input type="file" name="driver_photo" accept="image/*" capture="environment"></div><div class="field"><label>License No</label><input name="license_no" value="<?=st_e($editDriver['license_no']??'')?>"></div><div class="field"><label>License expiry</label><input type="date" name="license_expiry" value="<?=st_e($editDriver['license_expiry']??'')?>"></div><div class="field"><label>Status</label><select name="status"><option <?=$editDriver&&$editDriver['status']==='Inactive'?'':'selected'?>>Active</option><option <?=$editDriver&&$editDriver['status']==='Inactive'?'selected':''?>>Inactive</option></select></div><div class="field"><label>Emergency contact</label><input name="emergency_name" value="<?=st_e($editDriver['emergency_name']??'')?>"></div><div class="field"><label>Emergency phone</label><input name="emergency_phone" value="<?=st_e($editDriver['emergency_phone']??'')?>"></div><div class="field full"><textarea name="notes" placeholder="Maelezo"><?=st_e($editDriver['notes']??'')?></textarea></div><div><button class="btn"><?=$editDriver?'UPDATE DRIVER':'SAVE DRIVER'?></button> <?php if($editDriver):?><a class="btn dark" href="?page=st_transport&section=drivers">CANCEL</a><?php endif;?></div></form></div><?php endif;?>
<div data-transport-panel="trips" class="panel transport-section-panel"><div class="head"><div><h3>Panga Safari</h3><p class="muted">Chombo ni lazima. Dereva na fundi si lazima — Administrator anaweza kutuma fundi peke yake au kupanga chombo bila dereva.</p></div></div><form method="post" class="grid2 gps-form"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="st_action" value="st_save_transport_trip"><div class="field"><label>Gari / Pikipiki</label><select name="vehicle_id" required><option value="">Chagua Available</option><?php foreach($availableTransportVehicles as $v):?><option value="<?=$v['id']?>"><?=st_e($v['registration_no'].' - '.$v['vehicle_type'].' '.$v['make_model'])?></option><?php endforeach;?></select></div><div class="field"><label>Dereva <span class="small muted">(optional)</span></label><select name="driver_id"><option value="0">Hakuna Dereva — Fundi/Admin</option><?php foreach($transportDrivers as $d):?><option value="<?=$d['id']?>"><?=st_e($d['full_name'].' - '.$d['license_no'])?></option><?php endforeach;?></select></div><div class="field"><label>Destination</label><input name="destination" required placeholder="Eneo / destination"></div><div class="field"><label>Site</label><select name="site_id"><option value="">Link Site (optional)</option><?php foreach($sites as $site):?><option value="<?=$site['id']?>"><?=st_e($site['site_name'].' - '.$site['customer_name'])?></option><?php endforeach;?></select></div><div class="field full"><label>Mafundi <span class="small muted">(optional — tiki/ondoa tiki)</span></label><div class="check-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;margin-top:6px;max-height:220px;overflow:auto;padding:4px;border:1px solid #ddd;border-radius:8px"><?php foreach($allTechs as $t):?><label style="display:flex;align-items:center;gap:8px;padding:8px;border:1px solid #eee;border-radius:6px;cursor:pointer"><input type="checkbox" name="technician_ids[]" value="<?=$t['id']?>"> <span><?=st_e($t['full_name'])?></span></label><?php endforeach;?></div><small>Unaweza kuchagua fundi mmoja, wengi, au kuacha wote bila tiki.</small></div><div class="field"><label>Purpose</label><input name="purpose"></div><div class="field"><label>Departure</label><input type="datetime-local" name="departure_at" value="<?=date('Y-m-d\\TH:i')?>"></div><div class="field"><label>Odometer ya kuondoka</label><input type="number" step="0.1" name="start_odometer" required></div><input type="hidden" name="start_latitude" class="gps-lat"><input type="hidden" name="start_longitude" class="gps-lng"><input type="hidden" name="start_accuracy" class="gps-accuracy"><div><button type="button" class="btn gps-btn">📍 PATA GPS</button> <span class="gps-status small">GPS bado haijapatikana</span></div><div class="field full"><textarea name="notes" placeholder="Maelezo ya safari"></textarea></div><div><button class="btn">ANZA SAFARI</button></div></form></div>
<div data-transport-panel="trips" class="panel transport-section-panel"><h3>Safari Zote</h3><div class="table-wrap"><table><tr><th>#</th><th>Departure</th><th>Return</th><th>Chombo</th><th>Dereva</th><th>Destination</th><th>Km</th><th>Status</th><th>Action</th></tr><?php foreach($transportTrips as $trip):$km=($trip['end_odometer']!==null&&(float)$trip['end_odometer']>=(float)$trip['start_odometer'])?(float)$trip['end_odometer']-(float)$trip['start_odometer']:null;?><tr><td><?=st_e($trip['id'])?></td><td><?=st_e($trip['departure_at'])?></td><td><?=st_e($trip['return_at']??'-')?></td><td><?=st_e($trip['registration_no'])?></td><td><?=st_e($trip['driver_name']?:'Hakuna dereva')?></td><td><?=st_e($trip['destination'])?></td><td><?=$km===null?'-':number_format($km,1)?></td><td><span class="status"><?=st_e($trip['status'])?></span></td><td><?php if($trip['start_latitude']!==null&&$trip['start_longitude']!==null):?><a class="btn small" target="_blank" href="<?=st_e(st_google_map_url($trip['start_latitude'],$trip['start_longitude']))?>">START MAP</a><?php endif;?><?php if($trip['status']==='Open'&&(st_is_admin()||st_is_manager())):?><form method="post" class="gps-form" style="margin-top:5px"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="st_action" value="st_close_transport_trip"><input type="hidden" name="id" value="<?=$trip['id']?>"><input type="hidden" name="end_latitude" class="gps-lat"><input type="hidden" name="end_longitude" class="gps-lng"><input type="hidden" name="end_accuracy" class="gps-accuracy"><input type="number" step="0.1" name="end_odometer" placeholder="End odometer" required><button type="button" class="btn small gps-btn">PATA GPS</button><button class="btn small">Funga Safari</button><span class="gps-status small"></span></form><?php endif;?></td></tr><?php endforeach;?></table></div></div>
<div data-transport-panel="requests" class="panel transport-section-panel"><div class="head"><div><h3>Transport Requests</h3><p class="muted">Pending requests ziko juu; rejection lazima iwe na sababu.</p></div></div><div class="table-wrap"><table><tr><th>Tarehe</th><th>Dereva</th><th>Gari</th><th>Aina</th><th>Maelezo</th><th>Kiasi</th><th>Evidence/GPS</th><th>Status / Review</th></tr><?php foreach($transportRequests as $r):?><tr><td><?=st_e($r['created_at'])?></td><td><?=st_e($r['driver_name'])?></td><td><?=st_e($r['registration_no']??'-')?></td><td><?=st_e($r['request_type'])?></td><td><?=st_e($r['description'])?></td><td><?=number_format((float)$r['amount'],2)?></td><td><?=st_photo($r['photo']??'')?><?php if($r['latitude']!==null&&$r['longitude']!==null):?> <a class="btn small" target="_blank" href="<?=st_e(st_google_map_url($r['latitude'],$r['longitude']))?>">MAP</a><?php endif;?></td><td><?php if($r['status']==='Pending'):?><form method="post" class="request-review-form"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="st_action" value="st_review_transport_request"><input type="hidden" name="id" value="<?=$r['id']?>"><textarea name="review_comment" placeholder="Comment / sababu (required kwa Reject)"></textarea><button class="btn small" name="decision" value="Approved">APPROVE</button> <button class="btn danger small" name="decision" value="Rejected" onclick="return confirm('Reject request?')">REJECT</button></form><?php else:?><span class="status"><?=st_e($r['status'])?></span><?php if(!empty($r['review_comment'])):?><div class="small"><b>Review:</b> <?=st_e($r['review_comment'])?></div><?php endif;?><?php endif;?></td></tr><?php endforeach;?><?php if(!$transportRequests):?><tr><td colspan="8">Hakuna requests.</td></tr><?php endif;?></table></div></div>
<style>.transport-nav{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px;padding:8px;background:#fff;border:1px solid #ddd;border-radius:12px;position:sticky;top:72px;z-index:10}.transport-nav a{padding:10px 14px;border-radius:8px;text-decoration:none;font-weight:800;color:#222}.transport-nav a.active{background:#ffd000}.transport-section-panel[hidden]{display:none!important}.transport-module .metric{font-size:26px;font-weight:900;margin-top:5px}.transport-module .head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.transport-module table td,.transport-module table th{vertical-align:top}.request-review-form textarea{width:180px;min-height:48px;margin-bottom:5px}@media(max-width:700px){.transport-nav{top:58px}.transport-nav a{flex:1 1 45%;text-align:center}}</style>
<script>(function(){const section=<?=json_encode($transportSection)?>;document.querySelectorAll('[data-transport-panel]').forEach(function(el){el.hidden=!(el.getAttribute('data-transport-panel')||'').split(',').includes(section);});})();</script>
<?php elseif($page==='st_site_gallery'): ?>
  <?php $gallerySql="SELECT p.*,s.site_name,s.customer_name,t.full_name AS technician_name FROM st_site_photos p LEFT JOIN st_sites s ON s.id=p.site_id LEFT JOIN st_technicians t ON t.id=p.technician_id";if(st_is_technician())$gallerySql.=" JOIN st_site_technicians ast ON ast.site_id=p.site_id AND ast.technician_id=".(int)$ct['id'];$gallerySql.=" ORDER BY p.id DESC";$gallery=$pdo->query($gallerySql)->fetchAll(PDO::FETCH_ASSOC); ?>
  <h2>ðŸ“· <?=zan_t('Picha za Sites','Site Photos')?></h2><div class="panel"><p class="muted"><?=zan_t('Kila picha inaonyesha site, fundi, tarehe, GPS na usahihi wa GPS.','Every photo shows the site, technician, date, GPS and GPS accuracy.')?></p><div class="photo-grid">
    <?php foreach($gallery as $g):?><div class="photo-card"><?=st_photo($g['photo']??'','gallery-photo')?><h3><?=st_e($g['category'])?></h3><b><?=st_e($g['site_name']??'')?></b><div class="small">ðŸ‘· <?=st_e($g['technician_name']??'')?></div><div class="small">ðŸ•’ <?=st_e($g['captured_at']??$g['created_at'])?></div><div class="small">ðŸ“ <?=st_e($g['latitude'])?>, <?=st_e($g['longitude'])?></div><div class="small">ðŸŽ¯ Â±<?=st_e($g['accuracy']??'')?> m</div><?php if(!empty($g['caption'])):?><p><?=st_e($g['caption'])?></p><?php endif;?><?php if($g['latitude']!==null&&$g['longitude']!==null):?><a class="btn small" target="_blank" href="<?=st_e(st_google_map_url($g['latitude'],$g['longitude']))?>">ðŸ“ Google Map</a><?php endif;?></div><?php endforeach;?><?php if(!$gallery):?><p><?=zan_t('Hakuna picha za site bado.','No site photos yet.')?></p><?php endif;?></div></div>

<?php elseif($page==='st_approvals'): ?>
  <h2>âœ… Approvals</h2>
    <div class="panel"><h3>ðŸ§° Tool Requests kutoka kwa Mafundi</h3><div class="table-wrap"><table><tr><th>Fundi</th><th>Site</th><th>Tool</th><th>Sababu</th><th>Tarehe</th><th>Aliyeidhinisha</th><th>Action</th></tr><?php foreach($pdo->query("SELECT r.*,n.full_name,s.site_name,t.tool_name FROM st_tool_requests r JOIN st_technicians n ON n.id=r.technician_id JOIN st_sites s ON s.id=r.site_id JOIN st_tools t ON t.id=r.tool_id ORDER BY CASE WHEN r.status='Pending' THEN 0 ELSE 1 END,r.id DESC")->fetchAll(PDO::FETCH_ASSOC) as $request):?><tr><td><?=st_e($request['full_name'])?></td><td><?=st_e($request['site_name'])?></td><td><?=st_e($request['tool_name'])?></td><td><?=st_e($request['reason'])?></td><td><?=st_e($request['created_at'])?></td><td><?=st_e($request['reviewed_by']??'—')?></td><td><?php if($request['status']==='Pending'):?><form method="post" style="display:inline"><input type="hidden" name="st_action" value="st_review_tool_request"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="id" value="<?=$request['id']?>"><input type="hidden" name="decision" value="approve"><button class="btn small">APPROVE & ISSUE</button></form><form method="post" style="display:inline"><input type="hidden" name="st_action" value="st_review_tool_request"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="id" value="<?=$request['id']?>"><input type="hidden" name="decision" value="reject"><button class="btn danger small">REJECT</button></form></td><?php else:?><span class="status"><?=st_e($request['status'])?></span></td><?php endif;?></tr><?php endforeach;?></table></div></div>
    <div class="panel"><h3>ðŸ“¦ Material Requests kutoka kwa Mafundi</h3><div class="table-wrap"><table><tr><th>Fundi</th><th>Site</th><th>Material</th><th>Qty</th><th>Sababu</th><th>Aliyeidhinisha</th><th>Action</th></tr><?php foreach($pdo->query("SELECT r.*,n.full_name,s.site_name,m.material_name,m.unit FROM st_material_requests r JOIN st_technicians n ON n.id=r.technician_id JOIN st_sites s ON s.id=r.site_id JOIN st_materials m ON m.id=r.material_id ORDER BY CASE WHEN r.status='Pending' THEN 0 ELSE 1 END,r.id DESC")->fetchAll(PDO::FETCH_ASSOC) as $request):?><tr><td><?=st_e($request['full_name'])?></td><td><?=st_e($request['site_name'])?></td><td><?=st_e($request['material_name'])?></td><td><?=st_e($request['quantity'].' '.$request['unit'])?></td><td><?=st_e($request['reason'])?></td><td><?=st_e($request['reviewed_by']??'—')?></td><td><?php if($request['status']==='Pending'):?><form method="post" style="display:inline"><input type="hidden" name="st_action" value="st_review_material_request"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="id" value="<?=$request['id']?>"><input type="hidden" name="decision" value="approve"><button class="btn small">APPROVE & ISSUE</button></form><form method="post" style="display:inline"><input type="hidden" name="st_action" value="st_review_material_request"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="id" value="<?=$request['id']?>"><input type="hidden" name="decision" value="reject"><button class="btn danger small">REJECT</button></form></td><?php else:?><span class="status"><?=st_e($request['status'])?></span></td><?php endif;?></tr><?php endforeach;?></table></div></div>
  <?php $moneyApprovals=$pdo->query("SELECT m.*,s.site_name,CASE WHEN m.recipient_type='driver' THEN d.full_name ELSE t.full_name END AS recipient_name FROM st_money_transactions m LEFT JOIN st_sites s ON s.id=m.site_id LEFT JOIN st_drivers d ON d.id=m.recipient_id AND m.recipient_type='driver' LEFT JOIN st_technicians t ON t.id=m.recipient_id AND m.recipient_type='technician' WHERE m.status='Receipt Submitted' ORDER BY m.id DESC")->fetchAll(PDO::FETCH_ASSOC);?>
  <div class="panel"><h3>💸 Money Receipt Approvals</h3><div class="table-wrap"><table><tr><th>Mpokeaji</th><th>Site</th><th>Kiasi</th><th>Purpose</th><th>Receipt/GPS</th><th>Action</th></tr><?php foreach($moneyApprovals as $m):?><tr><td><?=st_e(ucfirst($m['recipient_type']).': '.$m['recipient_name'])?></td><td><?=st_e($m['site_name']??'')?></td><td><?=st_e($m['amount'].' '.$m['currency'])?></td><td><?=st_e($m['purpose'])?></td><td><?=st_photo($m['receipt_photo']??'')?><?php if($m['receipt_latitude']!==null&&$m['receipt_longitude']!==null):?> <a class="btn small" target="_blank" href="<?=st_e(st_google_map_url($m['receipt_latitude'],$m['receipt_longitude']))?>">MAP</a><?php endif;?></td><td><form method="post" style="display:inline"><input type="hidden" name="st_action" value="st_review_money"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="id" value="<?=$m['id']?>"><button class="btn small" name="decision" value="Approved">APPROVE</button> <button class="btn danger small" name="decision" value="Rejected" onclick="var r=prompt('Sababu ya kukataa:');if(!r)return false;this.form.reason.value=r;return true;">REJECT</button><input type="hidden" name="reason" value=""></form></td></tr><?php endforeach;?><?php if(!$moneyApprovals):?><tr><td colspan="6">Hakuna money receipt inayosubiri approval.</td></tr><?php endif;?></table></div></div>
  <div class="panel"><h3>&#128295; Tool records</h3><div class="table-wrap"><table><tr><th>Tool</th><th>Technician</th><th>Site</th><th>Type</th><th>Photo</th><th>Manager</th><th>Technical</th><th>Action</th></tr>
  <?php foreach($pdo->query("SELECT m.*,t.tool_name,n.full_name,s.site_name FROM st_tool_movements m JOIN st_tools t ON t.id=m.tool_id JOIN st_technicians n ON n.id=m.technician_id LEFT JOIN st_sites s ON s.id=m.site_id ORDER BY m.id DESC")->fetchAll(PDO::FETCH_ASSOC) as $x):?><tr>
    <td><?=st_e($x['tool_name'])?></td><td><?=st_e($x['full_name'])?></td><td><?=st_e($x['site_name']??'')?></td><td><?=st_e($x['action_type'])?></td><td><?=st_photo($x['photo']??'')?></td>
    <td><?=$x['manager_approved']?'âœ…':'â³'?></td><td><?=$x['technical_approved']?'âœ…':'â³'?></td>
    <td><?php foreach(['manager'=>'Manager','technical'=>'Technical Manager'] as $k=>$v):if(!$x[$k.'_approved']):?><form method="post" style="display:inline"><input type="hidden" name="st_action" value="st_approve_tool"><input type="hidden" name="id" value="<?=$x['id']?>"><input type="hidden" name="stage" value="<?=$k?>"><button class="btn small"><?=$v?> Approve</button></form><?php endif;endforeach;?></td>
  </tr><?php endforeach;?></table></div></div>

  <div class="panel"><h3>ðŸ“¦ Material records</h3><div class="table-wrap"><table><tr><th>Material</th><th>Technician</th><th>Site</th><th>Qty</th><th>Photo</th><th>Status</th><th>Manager</th><th>Technical</th><th>Action</th></tr>
  <?php foreach($pdo->query("SELECT m.*,a.material_name,n.full_name,s.site_name FROM st_material_movements m JOIN st_materials a ON a.id=m.material_id JOIN st_technicians n ON n.id=m.technician_id LEFT JOIN st_sites s ON s.id=m.site_id ORDER BY m.id DESC")->fetchAll(PDO::FETCH_ASSOC) as $x):?><tr>
    <td><?=st_e($x['material_name'])?></td><td><?=st_e($x['full_name'])?></td><td><?=st_e($x['site_name']??'')?></td><td><?=st_e($x['quantity'])?></td><td><?=st_photo($x['photo']??'')?><?php if(!empty($x['received_photo'])):?><div class="small">ðŸ“¦ <?=st_photo($x['received_photo'])?></div><?php endif;?></td><td><?=st_e($x['status'])?></td>
    <td><?=$x['manager_approved']?'âœ…':'â³'?></td><td><?=$x['technical_approved']?'âœ…':'â³'?></td>
        <td><?php foreach(['manager'=>'Manager','technical'=>'Technical Manager'] as $k=>$v):if(!$x[$k.'_approved']):?><form method="post" style="display:inline"><input type="hidden" name="st_action" value="st_approve_material"><input type="hidden" name="id" value="<?=$x['id']?>"><input type="hidden" name="stage" value="<?=$k?>"><button class="btn small"><?=$v?> Approve</button></form><?php endif;endforeach;?></td>
    </tr><?php endforeach;?></table></div></div>

<?php elseif($page==='st_reports'): ?>
    <?php
    $reportSites=(int)$pdo->query('SELECT COUNT(*) FROM st_sites')->fetchColumn();
    $reportClosed=(int)$pdo->query("SELECT COUNT(*) FROM st_sites WHERE status='Closed'")->fetchColumn();
    $reportTools=(int)$pdo->query('SELECT COUNT(*) FROM st_tools')->fetchColumn();
    $reportMaterials=(int)$pdo->query('SELECT COUNT(*) FROM st_materials')->fetchColumn();
    $overdueSites=$pdo->query("SELECT * FROM st_sites WHERE due_date IS NOT NULL AND due_date<>'' AND due_date < date('now') AND COALESCE(status,'')<>'Closed' ORDER BY due_date")->fetchAll(PDO::FETCH_ASSOC);
    $techReport=$pdo->query("SELECT t.id AS technician_id,t.full_name,t.photo,COUNT(DISTINCT st.site_id) AS sites_count,COUNT(DISTINCT tm.id) AS tool_movements FROM st_technicians t LEFT JOIN st_site_technicians st ON st.technician_id=t.id LEFT JOIN st_tool_movements tm ON tm.technician_id=t.id GROUP BY t.id ORDER BY t.full_name")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="head"><h2>&#128202; Reports</h2><div class="actions no-print"><a class="btn" href="?page=st_export_report&type=summary">â¬‡ Export CSV</a><button class="btn" type="button" onclick="window.print()">ðŸ–¨ Print</button></div></div>
    <div class="cards"><div class="card"><b><?=$reportSites?></b><br>Sites</div><div class="card"><b><?=$reportClosed?></b><br>Completed Sites</div><div class="card"><b><?=$reportTools?></b><br>Tools</div><div class="card"><b><?=$reportMaterials?></b><br>Materials</div></div>
    <div class="panel report-card"><h3>âš ï¸ Overdue Sites</h3><div class="table-wrap"><table><tr><th>Site</th><th>Customer</th><th>Due Date</th><th>Status</th></tr><?php foreach($overdueSites as $site):?><tr><td><?=st_e($site['site_name'])?></td><td><?=st_e($site['customer_name'])?></td><td><?=st_e($site['due_date'])?></td><td><?=st_e($site['status'])?></td></tr><?php endforeach;?></table></div><?php if(!$overdueSites):?><p class="muted">Hakuna sites zilizochelewa.</p><?php endif;?></div>
    <div class="panel"><h3>👷 Technician Report</h3><div class="table-wrap"><table><tr><th>Picha</th><th>Technician</th><th>Assigned Sites</th><th>Tool Movements</th><th>Money Approver</th></tr><?php foreach($techReport as $technician):$mq=$pdo->prepare("SELECT approved_by,approved_at FROM st_money_transactions WHERE recipient_type='technician' AND recipient_id=? AND approved_by<>'' ORDER BY approved_at DESC LIMIT 1");$mq->execute([(int)$technician['technician_id']]);$ma=$mq->fetch(PDO::FETCH_ASSOC);?><tr><td><?=st_photo($technician['photo']??'','passport')?></td><td><?=st_e($technician['full_name'])?></td><td><?=st_e($technician['sites_count'])?></td><td><?=st_e($technician['tool_movements'])?></td><td><?=st_e($ma['approved_by']??'—')?><?php if(!empty($ma['approved_at'])):?><div class="small"><?=st_e($ma['approved_at'])?></div><?php endif;?></td></tr><?php endforeach;?></table></div></div>
    <?php $driverReport=$pdo->query("SELECT d.id,d.full_name,d.photo,COUNT(DISTINCT tr.id) trips_count FROM st_drivers d LEFT JOIN st_transport_trips tr ON tr.driver_id=d.id GROUP BY d.id ORDER BY d.full_name")->fetchAll(PDO::FETCH_ASSOC); ?>
    <div class="panel"><h3>🚗 Driver Report</h3><div class="table-wrap"><table><tr><th>Picha</th><th>Driver</th><th>Trips</th><th>Money Approver</th></tr><?php foreach($driverReport as $driver):$mq=$pdo->prepare("SELECT approved_by,approved_at FROM st_money_transactions WHERE recipient_type='driver' AND recipient_id=? AND approved_by<>'' ORDER BY approved_at DESC LIMIT 1");$mq->execute([(int)$driver['id']]);$ma=$mq->fetch(PDO::FETCH_ASSOC);?><tr><td><?=st_photo($driver['photo']??'','passport')?></td><td><?=st_e($driver['full_name'])?></td><td><?=st_e($driver['trips_count'])?></td><td><?=st_e($ma['approved_by']??'—')?><?php if(!empty($ma['approved_at'])):?><div class="small"><?=st_e($ma['approved_at'])?></div><?php endif;?></td></tr><?php endforeach;?></table></div></div>

<?php elseif($page==='st_activity'): ?>
    <?php $auditRows=$pdo->query('SELECT * FROM st_audit_log ORDER BY id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC); ?>
    <div class="head"><h2>ðŸ“ Activity / Audit History</h2><div class="actions no-print"><a class="btn" href="?page=st_export_report&type=activity">â¬‡ Export CSV</a><button class="btn" type="button" onclick="window.print()">ðŸ–¨ Print</button></div></div>
    <div class="panel"><div class="table-wrap"><table><tr><th>Date</th><th>Entity</th><th>Action</th><th>Details</th><th>Performed By</th></tr><?php foreach($auditRows as $audit):?><tr><td><?=st_e($audit['created_at'])?></td><td><?=st_e($audit['entity_type'])?> #<?=st_e($audit['entity_id'])?></td><td><?=st_e($audit['action'])?></td><td><?=st_e($audit['details'])?></td><td><?=st_e($audit['performed_by'])?></td></tr><?php endforeach;?></table></div><?php if(!$auditRows):?><p class="muted">Hakuna activity bado.</p><?php endif;?></div>

<?php elseif($page==='st_daily_reports'): ?>
    <?php $dailyRows=$pdo->query("SELECT u.*,s.site_name,t.full_name technician_name,t.phone FROM st_daily_updates u JOIN st_sites s ON s.id=u.site_id JOIN st_technicians t ON t.id=u.technician_id ORDER BY u.update_date DESC,u.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC); ?>
    <div class="head"><h2>ðŸ“š Daily Technician Reports</h2><div class="actions no-print"><a class="btn" href="?page=st_export_report&type=daily">â¬‡ Export CSV</a><button class="btn" type="button" onclick="window.print()">ðŸ–¨ Print</button></div></div>
    <div class="panel"><div class="table-wrap"><table><tr><th>Date</th><th>Technician</th><th>Site</th><th>Work Done</th><th>Blockers</th><th>Next Steps</th><th>GPS</th></tr><?php foreach($dailyRows as $daily):?><tr><td><?=st_e($daily['update_date'])?></td><td><?=st_e($daily['technician_name'])?></td><td><?=st_e($daily['site_name'])?></td><td><?=nl2br(st_e($daily['work_done']))?></td><td><?=nl2br(st_e($daily['blockers']))?></td><td><?=nl2br(st_e($daily['next_steps']))?></td><td><?php if($daily['latitude']!==null&&$daily['longitude']!==null):?><a class="btn small" target="_blank" href="<?=st_e(st_google_map_url($daily['latitude'],$daily['longitude']))?>">Google Map</a><?php else:?>-<?php endif;?></td></tr><?php endforeach;?></table></div><?php if(!$dailyRows):?><p class="muted">Hakuna daily reports bado.</p><?php endif;?></div>

<?php elseif($page==='st_settings'): ?>
    <h2>âš™ï¸ <?=zan_t('Mipangilio ya Site Tracking','Site Tracking Settings')?></h2>
    <div class="panel">
        <h3>⏱️ <?=zan_t('Auto Logout','Auto Logout')?></h3>
        <p class="muted"><?=zan_t('Mfumo utakuondoa baada ya kutotumia mfumo kwa muda uliochagua. Activity yoyote ndani ya mfumo huanza muda upya.','The system logs you out after the selected period of inactivity. Any activity resets the timer.')?></p>
        <form method="post" class="grid2">
            <input type="hidden" name="st_action" value="st_save_settings"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>">
            <label class="field"><?=zan_t('Muda wa kutotumia mfumo','Inactivity timeout')?><select name="timeout_minutes"><option value="15" <?=((int)$stTimeoutMinutes===15?'selected':'')?>>15 <?=zan_t('dakika','minutes')?></option><option value="30" <?=((int)$stTimeoutMinutes===30?'selected':'')?>>30 <?=zan_t('dakika','minutes')?></option><option value="60" <?=((int)$stTimeoutMinutes===60?'selected':'')?>>60 <?=zan_t('dakika','minutes')?></option><option value="120" <?=((int)$stTimeoutMinutes===120?'selected':'')?>>120 <?=zan_t('dakika','minutes')?></option></select></label>
            <div><button class="btn" type="submit">ðŸ’¾ <?=zan_t('Hifadhi Mipangilio','Save Settings')?></button></div>
        </form>
    </div>
    <div class="panel"><h3>⚙️ Mipangilio muhimu</h3><div class="grid2"><div><b>GPS</b><p class="small muted">GPS ni optional; mfumo utaendelea bila location.</p></div><div><b>Camera</b><p class="small muted">Camera hutumika kwa picha za kazi, tools na risiti.</p></div><div><b>Security</b><p class="small muted">Password hazionyeshwi. Auto logout inalinda session.</p></div><div><b>Audit</b><p class="small muted">Mabadiliko muhimu yanaonekana kwenye Activity / Audit History.</p></div></div></div>
    <div class="panel" style="margin-top:18px">
        <h3>&#128190; Backup &amp; Restore ya Site Tracking</h3>
        <p class="small muted">Backup hii inahifadhi database ya Business Tracking na Site Tracking pamoja na folder la picha (uploads).</p>
        <div class="backup-buttons" style="margin-top:12px">
            <a class="btn" href="?page=st_settings&st_backup=1" onclick="return confirmBackup()">&#128190; TENGENEZA BACKUP</a>
        </div>
        <hr style="margin:22px 0">
        <h3>&#9851; Restore kutoka Computer</h3>
        <p class="small muted">Chagua backup ya Zantronix yenye <strong>.zip</strong> ili kurejesha data ya mfumo.</p>
        <div class="backup-warning" style="margin:12px 0">
            <strong>&#9888; Tahadhari:</strong><br>
            Restore itabadilisha taarifa za sasa. Backup ya sasa itahifadhiwa kwanza.
        </div>
        <form method="post" action="?page=st_settings&action=restore_backup" enctype="multipart/form-data" onsubmit="return confirmRestore()">
            <input type="file" name="backup_zip" accept=".zip,application/zip" required style="display:block;margin-bottom:15px">
            <button class="btn black" type="submit">&#9851; KUBALI NA RESTORE</button>
        </form>
        <?php if (isset($_GET['restored'])): ?>
            <div class="notice" style="margin-top:15px">&#10003; <strong>Restore imekamilika.</strong><br>Taarifa za backup zimerejeshwa kwenye mfumo.</div>
        <?php endif; ?>
    </div>
    <?php if(st_is_super_admin()): ?>
    <?php endif; ?>
    <div class="grid2">
        <div class="panel"><h3>ðŸ” <?=zan_t('Usalama wa Session','Session Security')?></h3><p><?=zan_t('Logout hufanyika moja kwa moja baada ya muda wa kutotumia. Password hazionyeshwi; fundi au administrator hubadilisha password mpya kwa kutumia password ya sasa.','Logout happens automatically after inactivity. Passwords are never displayed; users change them using the current password.')?></p></div>
        <div class="panel"><h3>ðŸ“± <?=zan_t('Matumizi ya Simu','Mobile Use')?></h3><p><?=zan_t('Ruhusu Camera na Location kwenye APK/browser. Tumia HTTPS au localhost ili camera na GPS vifanye kazi.','Allow Camera and Location in the APK/browser. Use HTTPS or localhost for camera and GPS access.')?></p></div>
    </div>

<?php elseif($page==='st_administration'): ?>
  <h2>ðŸ¢ Administration</h2>
    <div class="panel"><h3>Administrators — chagua Cheo / Nafasi</h3><p class="muted">Administrator ni account type; Cheo kinaweza kuwa Storekeeper, Manager, Technical Manager au Site Administrator.</p><?php $editAdmin=null;$editAdminPermissions=[];if(isset($_GET['edit'])){$q=$pdo->prepare('SELECT * FROM st_site_admins WHERE id=?');$q->execute([(int)$_GET['edit']]);$editAdmin=$q->fetch(PDO::FETCH_ASSOC);$editAdminPermissions=json_decode($editAdmin['permissions']??'{}',true);if(!is_array($editAdminPermissions))$editAdminPermissions=[];} ?>
    <form method="post" enctype="multipart/form-data" autocomplete="off"><input type="hidden" name="st_action" value="<?=$editAdmin?'st_edit_site_admin':'st_save_site_admin'?>"><input type="hidden" name="id" value="<?=$editAdmin['id']??0?>">
        <div class="grid2"><div class="field"><input name="username" placeholder="Username" value="<?=st_e($editAdmin['username']??'')?>" required></div><div class="field"><input name="full_name" placeholder="Full name" value="<?=st_e($editAdmin['full_name']??'')?>" required></div>
        <div class="field"><input name="password" type="password" autocomplete="new-password" placeholder="<?=$editAdmin?'New password (optional)':'Password ya Administrator'?>" <?=$editAdmin?'':'required'?>></div><div class="field"><label>Cheo / Nafasi<select name="admin_type"><option value="Storekeeper" <?=($editAdmin['admin_type']??'')==='Storekeeper'?'selected':''?>>Storekeeper (Stoo)</option><option value="Manager" <?=($editAdmin['admin_type']??'')==='Manager'?'selected':''?>>Manager</option><option value="Technical Manager" <?=($editAdmin['admin_type']??'')==='Technical Manager'?'selected':''?>>Technical Manager</option><option value="Site Administrator" <?=($editAdmin['admin_type']??'')==='Site Administrator'?'selected':''?>>Site Administrator</option></select></label></div>
        <div class="field"><input name="office" placeholder="Office / Kituo cha kazi" value="<?=st_e($editAdmin['office']??'')?>" required></div><div class="field"><input name="passport_photo" type="file" accept="image/jpeg,image/png,image/webp" <?=$editAdmin?'':'required'?>></div><div class="field"><input name="phone" placeholder="Phone" value="<?=st_e($editAdmin['phone']??'')?>"></div><div class="field"><input name="email" placeholder="Email" value="<?=st_e($editAdmin['email']??'')?>"></div></div>
        <div class="panel"><b>Ruhusa za kuona Site Tracking</b><div class="grid2"><?php foreach(['dashboard'=>'Dashboard','technicians'=>'Mafundi','sites'=>'Sites','tools'=>'Tools','photos'=>'Picha','movements'=>'Movements','vehicles'=>'Usafiri wa Kampuni','money_transfer'=>'Money Transfer','approvals'=>'Approvals','reports'=>'Reports','daily_reports'=>'Daily Reports','activity'=>'Activity History','administration'=>'Administration'] as $permissionKey=>$permissionLabel):?><label><input type="checkbox" name="perm_<?=$permissionKey?>" value="1" <?=($editAdmin&&(!empty($editAdminPermissions[$permissionKey])||empty($editAdmin['permissions'])))?'checked':''?> style="width:auto"> <?=st_e($permissionLabel)?></label><?php endforeach;?></div></div>
        <div class="field"><textarea name="whatsapp_template" rows="3" placeholder="Ujumbe wa WhatsApp wa reminder"><?=st_e($editAdmin['whatsapp_template']??'Habari {technician}, tafadhali tuma daily update ya site {site}.')?></textarea><small>Tumia: {technician}, {site}, {days} na {due_date}</small></div>
        <?php if(!empty($editAdmin['passport_photo'])):?><p><img src="<?=st_e($editAdmin['passport_photo'])?>" alt="Passport photo" style="width:80px;height:100px;object-fit:cover;border:1px solid #ddd;border-radius:6px"></p><?php endif;?>
    <button class="btn"><?=$editAdmin?'&#9998; UPDATE ADMINISTRATOR':'SAVE ADMINISTRATOR'?></button> <?php if($editAdmin):?><a class="btn" href="?page=st_administration">CANCEL</a><?php endif;?></form>
    <?php if(st_is_super_admin()): ?>
    <div class="panel" style="margin:14px 0"><h3>⚠️ Super Admin Controls</h3><p class="small muted">Super Admin anaweza kumpa mtumiaji mwingine access ya Business + Site Tracking na kusafisha operational history bila kufuta master data.</p>
      <form method="post" class="grid2" onsubmit="return confirm('Hakikisha unataka kufanya hatua hii?')"><input type="hidden" name="st_action" value="st_make_super_admin"><div class="field"><select name="user_id" required><option value="">Chagua Administrator/User</option><?php foreach($pdo->query("SELECT id,username,name,role FROM users WHERE active=1 ORDER BY name,username")->fetchAll(PDO::FETCH_ASSOC) as $su):?><option value="<?=$su['id']?>"><?=st_e(($su['name']?:$su['username']).' — '.$su['role'])?></option><?php endforeach;?></select></div><div><button class="btn" type="submit">⭐ MAKE SUPER ADMIN — ALL SYSTEMS</button></div></form>
      <form method="post" style="margin-top:10px" onsubmit="return confirm('Hii itafuta operational history yote. Endelea?')"><input type="hidden" name="st_action" value="st_clear_history"><input type="hidden" name="confirm_text" value="CLEAR"><button class="btn danger" type="submit">🧹 SAFISHA HISTORY YA MFUMO</button></form></div>
    <?php endif; ?>
    <div class="table-wrap"><table><tr><th>Picha</th><th>Username</th><th>Name</th><th>Cheo</th><th>Office</th><th>Phone</th><th>Action</th></tr><?php foreach($pdo->query('SELECT * FROM st_site_admins ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC) as $ad):?><tr><td><?php if(!empty($ad['passport_photo'])):?><img src="<?=st_e($ad['passport_photo'])?>" alt="Passport photo" style="width:55px;height:70px;object-fit:cover;border:1px solid #ddd;border-radius:5px"><?php endif;?></td><td><?=st_e($ad['username'])?></td><td><?=st_e($ad['full_name'])?></td><td><?=st_e($ad['admin_type'])?></td><td><?=st_e($ad['office']??'')?></td><td><?=st_e($ad['phone'])?></td><td><a class="btn small" href="?page=st_administration&edit=<?=$ad['id']?>">&#9998; HARIRI</a> <form method="post" style="display:inline" onsubmit="return confirm('Una uhakika unataka kufuta administrator huyu?');"><input type="hidden" name="st_action" value="st_delete_site_admin"><input type="hidden" name="id" value="<?=$ad['id']?>"><button class="btn small danger" type="submit">&#128465; FUTA</button></form></td></tr><?php endforeach;?></table></div></div>
<?php endif;?>

</main></div><script>function toggleMobileMenu(button){var menu=document.querySelector('.st-side'),overlay=document.querySelector('.mobile-menu-overlay');if(!menu)return;var opening=menu.style.display!=='block'&&!menu.classList.contains('mobile-open');menu.classList.toggle('mobile-open',opening);menu.style.display=opening?'block':'none';if(overlay)overlay.classList.toggle('open',opening);if(button)button.setAttribute('aria-expanded',opening?'true':'false');}document.addEventListener('DOMContentLoaded',function(){var overlay=document.createElement('div');overlay.className='mobile-menu-overlay';overlay.addEventListener('click',function(){toggleMobileMenu(null);});document.body.appendChild(overlay);});
document.addEventListener('DOMContentLoaded',function(){const token=<?=json_encode(st_csrf_token())?>;document.querySelectorAll('form').forEach(function(form){if(form.querySelector('input[name="st_action"]')&&!form.querySelector('input[name="st_csrf"])){const input=document.createElement('input');input.type='hidden';input.name='st_csrf';input.value=token;form.appendChild(input);}});});
document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('.add-photo-btn').forEach(function(button){button.addEventListener('click',function(){var form=button.closest('form'),list=form&&form.querySelector('.photo-upload-list');if(!list)return;var label=document.createElement('label');label.className='camera-field';label.textContent='ðŸ“· Picha nyingine';var input=document.createElement('input');input.type='file';input.name=button.dataset.photoName;input.accept='image/*';input.setAttribute('capture','environment');input.required=true;label.appendChild(input);list.appendChild(label);});});});
document.addEventListener('DOMContentLoaded',function(){var mic=document.getElementById('ai-mic'),input=document.getElementById('ai-question'),status=document.getElementById('ai-voice-status');document.querySelectorAll('.ai-speak').forEach(function(button){button.addEventListener('click',function(){if('speechSynthesis' in window){window.speechSynthesis.cancel();var speech=new SpeechSynthesisUtterance(button.dataset.speak||'');speech.lang=document.documentElement.lang==='sw'?'sw-TZ':'en-US';window.speechSynthesis.speak(speech);}});});if(!mic||!input)return;var Recognition=window.SpeechRecognition||window.webkitSpeechRecognition;if(!Recognition){mic.disabled=true;if(status)status.textContent='Voice haijawezeshwa kwenye browser hii';return;}var recognition=new Recognition();recognition.interimResults=false;recognition.continuous=false;recognition.lang=document.documentElement.lang==='sw'?'sw-TZ':'en-US';mic.addEventListener('click',function(){status.textContent='Nasikiliza...';recognition.start();});recognition.onresult=function(event){input.value=(input.value?input.value+' ':'')+event.results[0][0].transcript;status.textContent='Swali limepokelewa. Unaweza kulihariri kisha utume.';};recognition.onerror=function(){status.textContent='Voice haikusikika. Ruhusu microphone ujaribu tena.';};recognition.onend=function(){if(status.textContent==='Nasikiliza...')status.textContent='Bonyeza mic kuongea';};});
document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('input[type=file][capture]').forEach(function(i){i.setAttribute('accept','image/*');i.setAttribute('capture','environment');});function gps(form,status){if(!navigator.geolocation){status.textContent='GPS haipatikani kwenye kifaa hiki.';status.className='gps-status gps-error';return;}status.textContent='Inatafuta location...';navigator.geolocation.getCurrentPosition(function(p){var c=p.coords;var lat=form.querySelector('.gps-lat'),lng=form.querySelector('.gps-lng'),accuracy=form.querySelector('.gps-accuracy'),captured=form.querySelector('.gps-time');if(lat)lat.value=c.latitude;if(lng)lng.value=c.longitude;if(accuracy)accuracy.value=c.accuracy||'';if(captured)captured.value=new Date().toISOString();status.textContent='âœ“ GPS imepatikana Â±'+Math.round(c.accuracy||0)+' m';status.className='gps-status gps-ready';},function(){status.textContent='GPS haikupatikana. Washa Location kwenye simu na ujaribu tena.';status.className='gps-status gps-error';},{enableHighAccuracy:true,timeout:20000,maximumAge:0});}document.querySelectorAll('.gps-form').forEach(function(form){var b=form.querySelector('.gps-btn'),st=form.querySelector('.gps-status');if(b)b.addEventListener('click',function(){gps(form,st);});form.addEventListener('submit',function(e){/* GPS is optional; submission continues without location. */});});});
</script><script>
document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('.gps-submit-form').forEach(function(form){form.addEventListener('submit',function(e){if(form.dataset.gpsSubmitting==='1')return;e.preventDefault();form.dataset.gpsSubmitting='1';var button=form.querySelector('.gps-submit-btn'),status=form.querySelector('.gps-status'),submit=function(){if(button){button.disabled=true;button.textContent='INATUMA...';}HTMLFormElement.prototype.submit.call(form);};if(status)status.textContent='GPS optional — inatuma...';if(!navigator.geolocation){submit();return;}navigator.geolocation.getCurrentPosition(function(p){var c=p.coords,lat=form.querySelector('.gps-lat'),lng=form.querySelector('.gps-lng'),acc=form.querySelector('.gps-accuracy');if(lat)lat.value=c.latitude;if(lng)lng.value=c.longitude;if(acc)acc.value=c.accuracy||'';if(status)status.textContent='GPS imechukuliwa. Inatuma...';submit();},function(){if(status)status.textContent='GPS haijapatikana. Inatuma bila GPS...';submit();},{enableHighAccuracy:true,timeout:5000,maximumAge:30000});});});});
</script><script>
(function(){
    var activeInput=null,stream=null,modal=null;
    function ensureModal(){if(modal)return;modal=document.createElement('div');modal.className='camera-modal';modal.innerHTML='<div class="camera-box"><video autoplay playsinline></video><p class="camera-message small">Ruhusu camera ya simu.</p><div class="camera-actions"><button type="button" class="btn camera-take">PIGA PICHA</button><button type="button" class="btn dark camera-close">FUNGA</button></div></div>';document.body.appendChild(modal);modal.querySelector('.camera-close').addEventListener('click',closeCamera);modal.querySelector('.camera-take').addEventListener('click',takePhoto);}
    function closeCamera(){if(stream){stream.getTracks().forEach(function(t){t.stop();});stream=null;}if(modal){modal.querySelector('video').srcObject=null;modal.classList.remove('open');}activeInput=null;}
    function fallback(input){input.style.display='block';input.click();setTimeout(function(){input.style.display='none';},500);}
    function openCamera(input){activeInput=input;ensureModal();if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){fallback(input);return;}var video=modal.querySelector('video'),msg=modal.querySelector('.camera-message');navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}},audio:false}).then(function(ms){stream=ms;video.srcObject=ms;msg.textContent='Elekeza camera kwenye picha, kisha bonyeza PIGA PICHA.';modal.classList.add('open');}).catch(function(){closeCamera();fallback(input);});}
    function takePhoto(){if(!activeInput||!stream)return;var input=activeInput,video=modal.querySelector('video'),canvas=document.createElement('canvas');canvas.width=video.videoWidth||1280;canvas.height=video.videoHeight||720;canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height);canvas.toBlob(function(blob){if(!blob){closeCamera();return;}try{var file=new File([blob],'camera-'+Date.now()+'.jpg',{type:'image/jpeg'}),dt=new DataTransfer();dt.items.add(file);input.files=dt.files;var result=input.closest('.camera-field')?.querySelector('.camera-result')||input.parentElement?.querySelector('.camera-result');if(result)result.textContent='✓ Picha iko tayari kutumwa.';closeCamera();}catch(e){closeCamera();fallback(input);}},'image/jpeg',.88);}
    function bind(input){if(input.dataset.cameraBound==='1')return;input.dataset.cameraBound='1';input.setAttribute('accept','image/*');input.setAttribute('capture','environment');input.classList.add('real-camera-input');input.style.display='none';var label=input.closest('.camera-field')||input.parentElement;if(!label)return;var button=document.createElement('button');button.type='button';button.className='btn real-camera-button';button.textContent='📷 PIGA PICHA KWA CAMERA';var result=document.createElement('span');result.className='camera-result';button.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();openCamera(input);});if(label.classList.contains('camera-field')){label.addEventListener('click',function(e){if(e.target!==button)e.preventDefault();});label.appendChild(button);label.appendChild(result);}else{label.appendChild(button);label.appendChild(result);}}
    document.addEventListener('DOMContentLoaded',function(){var root=document.querySelector('.st-main');if(!root)return;root.querySelectorAll('input[type=file][accept*="image"],input.phone-camera').forEach(bind);if(window.MutationObserver)new MutationObserver(function(){root.querySelectorAll('input[type=file][accept*="image"],input.phone-camera').forEach(bind);}).observe(root,{childList:true,subtree:true});});
})();
</script><script>(function(){const lang=document.documentElement.lang||'sw';const Msw={'Dashboard':'Dashibodi','Technicians':'Mafundi','Customers':'Wateja','Materials':'Vifaa','Material Report':'Ripoti za Material','Site Photos':'Picha za Sites','Approvals':'Uidhinishaji','Administration':'Usimamizi','Logout':'Toka','Switch System':'Badilisha Mfumo','Technician Portal':'Portal ya Fundi','Assigned Sites':'Sites Nilizopewa','My Sites':'Sites Zangu','My Tool Records':'Rekodi za Tools Zangu','Status':'Hali','Action':'Kitendo','Date':'Tarehe','Quantity':'Kiasi','Customer':'Mteja','Team':'Timu','Good':'Nzuri','Damaged':'Imeharibika','Missing':'Imepotea','Available':'Ipo','Issued':'Imetolewa','Returned':'Imerejeshwa','Pending':'Inasubiri','Approved':'Imeidhinishwa','Rejected':'Imekataliwa','Completed':'Imekamilika','In Progress':'Inaendelea','Closed':'Imefungwa','Assigned':'Imepewa Fundi','Verified':'Imethibitishwa','Before Work':'Kabla ya Kazi','Work Progress':'Kazi Inaendelea','Material Received':'Material Imepokelewa','Material Used':'Material Imetumika','Completed Work':'Kazi Imekamilika','Problem / Damage':'Tatizo / Uharibifu','Site Arrival':'Kufika Site','Site Exit':'Kuondoka Site','Customer Handover':'Makabidhiano kwa Mteja','Open Map':'Fungua Map','Get Location':'Pata Location','EDIT':'HARIRI','DELETE':'FUTA','CANCEL':'GHAIRI'};const Men={};Object.keys(Msw).forEach(k=>Men[Msw[k]]=k);const M=lang==='sw'?Msw:Men;document.querySelectorAll('body *').forEach(function(e){if(e.children.length===0){let t=e.textContent.trim();if(M[t])e.textContent=M[t];}if(e.placeholder&&M[e.placeholder])e.placeholder=M[e.placeholder];});})();</script></body></html><?php exit; }

if ($page === 'choose_system' && isset($_SESSION['user'])):
    $chooseRole = strtolower(trim($_SESSION['user']['role'] ?? ''));
    $choosePerms = json_decode($_SESSION['user']['permissions'] ?? '{}', true);
    if (!is_array($choosePerms)) $choosePerms = [];
    $chooseAccountSystem = strtolower(trim($_SESSION['user']['account_system'] ?? ''));
    $chooseSuperAdmin = in_array($chooseRole, ['super admin', 'super_admin', 'superadmin', 'founder'], true);
    $chooseBusiness = $chooseSuperAdmin || in_array($chooseRole, ['admin', 'msimamizi'], true) || !empty($choosePerms['business_system']) || in_array($chooseAccountSystem, ['business','both'], true);
    $chooseSite = $chooseSuperAdmin || !empty($choosePerms['site_tracking_system']) || in_array($chooseAccountSystem, ['site','both'], true);
    ?>
<!doctype html>
<html lang="<?=htmlspecialchars($zanLang, ENT_QUOTES, 'UTF-8')?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=htmlspecialchars(zan_t('Chagua Mfumo - Zantronix','Choose System -'))?></title>
<link rel="stylesheet" href="style.css">
<style>
.system-choice{max-width:980px;margin:45px auto;padding:34px 30px;background:#fff;border-radius:18px;text-align:center;box-shadow:0 10px 35px rgba(0,0,0,.08)}
.system-choice h1{margin:0;font-size:34px;letter-spacing:.5px;color:#111}
.system-choice h1 .brand-a{color:#f2c400}
.system-choice h2{margin:8px 0 4px;font-size:24px}
.system-choice>p{margin:0;color:#666}
.system-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-top:22px}
.system-card{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:24px 22px;border:2px solid #111;border-radius:14px;color:#111;text-decoration:none;background:#ffd000;font-size:21px;font-weight:800;transition:.2s ease;box-sizing:border-box}
.system-card.site{background:#111;color:#ffd000}
.system-card:hover{transform:translateY(-3px);box-shadow:0 8px 20px rgba(0,0,0,.18)}
.system-card .card-icon{font-size:34px;line-height:1;flex:0 0 auto}
.system-card .card-copy{text-align:left;flex:1}
.system-card .card-title{display:block}
.system-card .card-sub{display:block;margin-top:5px;font-size:13px;font-weight:600;opacity:.75}
.system-card .card-arrow{font-size:28px;flex:0 0 auto}
.workflow{position:relative;margin:28px 0 8px;padding:20px 8px 12px;display:grid;grid-template-columns:repeat(4,1fr);gap:8px;overflow:hidden}
.workflow:before{content:"";position:absolute;left:9%;right:9%;top:61px;height:3px;background:linear-gradient(90deg,#ffd000,#f5b900,#ffd000);z-index:0;border-radius:5px}
.workflow-item{position:relative;z-index:1;text-align:center}
.workflow-icon{width:72px;height:72px;margin:0 auto 9px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#fff;border:4px solid #ffd000;box-shadow:0 5px 15px rgba(0,0,0,.10);font-size:35px;animation:floatTool 2.8s ease-in-out infinite}
.workflow-item:nth-child(2) .workflow-icon{animation-delay:.25s}
.workflow-item:nth-child(3) .workflow-icon{animation-delay:.5s}
.workflow-item:nth-child(4) .workflow-icon{animation-delay:.75s}
.workflow-label{font-size:14px;font-weight:800;color:#222;background:#fff;display:inline-block;padding:3px 8px;border-radius:8px}
.workflow-arrow{position:absolute;top:45px;font-size:25px;font-weight:900;color:#e7b400;animation:moveArrow 1.7s ease-in-out infinite}
.workflow-item:nth-child(1) .workflow-arrow{right:-4px}
.workflow-item:nth-child(2) .workflow-arrow{right:-4px;animation-delay:.25s}
.workflow-item:nth-child(3) .workflow-arrow{right:-4px;animation-delay:.5s}
@keyframes floatTool{0%,100%{transform:translateY(0)}50%{transform:translateY(-7px)}}
@keyframes moveArrow{0%,100%{transform:translateX(0);opacity:.55}50%{transform:translateX(7px);opacity:1}}
@media(max-width:700px){.system-choice{margin:25px 12px;padding:25px 16px}.system-choice h1{font-size:28px}.workflow{grid-template-columns:repeat(2,1fr);gap:18px}.workflow:before{display:none}.workflow-arrow{display:none}.system-options{grid-template-columns:1fr}.system-card{font-size:19px}}
</style>
</head>
<body style="background:#f4f4f4">
<main class="system-choice">
<h1>Z<span class="brand-a">A</span>NTRONIX</h1>
<h2><?=htmlspecialchars(zan_t('Chagua Mfumo','Choose System'))?></h2>
<p><?=htmlspecialchars(zan_t('Chagua sehemu unayotaka kutumia.','Choose the system you want to use.'))?></p>

<div class="workflow" aria-label="Inventory to site tracking workflow">
  <div class="workflow-item"><div class="workflow-icon">📦</div><div class="workflow-label">Inventory</div><span class="workflow-arrow">→</span></div>
  <div class="workflow-item"><div class="workflow-icon">🚚</div><div class="workflow-label">Usafirishaji</div><span class="workflow-arrow">→</span></div>
  <div class="workflow-item"><div class="workflow-icon">📍</div><div class="workflow-label">Site</div><span class="workflow-arrow">→</span></div>
  <div class="workflow-item"><div class="workflow-icon">🔧</div><div class="workflow-label">Fundi</div></div>
</div>

<div class="system-options">
<?php if ($chooseBusiness): ?><a class="system-card" href="?page=dashboard&system=business"><span class="card-icon">📊</span><span class="card-copy"><span class="card-title"><?=htmlspecialchars(zan_t('Business Tracking','Business Tracking'))?></span><span class="card-sub"><?=htmlspecialchars(zan_t('Wateja, bidhaa, mauzo, ankara na reports','Customers, products, sales, invoices and reports'))?></span></span><span class="card-arrow">→</span></a><?php endif; ?>
<?php if ($chooseSite): ?><a class="system-card site" href="?page=site_tracking"><span class="card-icon">📍</span><span class="card-copy"><span class="card-title"><?=htmlspecialchars(zan_t('Site Tracking','Site Tracking'))?></span><span class="card-sub"><?=htmlspecialchars(zan_t('Sites, mafundi, tools, movements na usafiri','Sites, technicians, tools, movements and transport'))?></span></span><span class="card-arrow">→</span></a><?php endif; ?>
</div>
<p style="margin-top:24px"><a href="?logout=1"><?=htmlspecialchars(zan_t('Toka','Logout'))?></a></p>
</main>
</body>
</html>
<?php exit; endif; ?>

<?php
/* =========================================================
   RESTORE BACKUP
   ========================================================= */

if (
    in_array($page, ['settings','st_settings'], true) &&
    $action === 'restore_backup' &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    if ($page === 'st_settings') {
        if (!st_is_admin()) exit('Huna ruhusa ya kufanya restore.');
    } elseif (!can_do('settings')) {
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

    $restoreReturnPage = ($page === 'st_settings') ? 'st_settings' : 'settings';
    header(
        'Location:index.php?page=' . $restoreReturnPage . '&restored=1'
    );

    exit;
}
function st_google_map_url($lat,$lng){
    return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode((string)$lat.','.(string)$lng);
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
    zan_require_csrf();

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
    zan_require_csrf();

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
        zan_password_hash($password),
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
    zan_require_csrf();

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
            zan_password_hash($newPassword),
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
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    can_do('users')
) {
    zan_require_csrf();

    $id = (int)($_POST['id'] ?? 0);

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
if($page==='delete_user' && can_do('users') && $_SERVER['REQUEST_METHOD']==='POST'){
    zan_require_csrf();
    $id=(int)($_POST['id']??0); if($id<=0)exit('Mtumiaji si sahihi.');
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
    zan_require_csrf();

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
    zan_require_csrf();

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

if ($page === 'delete_customer' && $_SERVER['REQUEST_METHOD']==='POST') {
    zan_require_csrf();

    $s = $pdo->prepare(
        'DELETE FROM customers WHERE id=?'
    );

    $s->execute([
        (int)$_POST['id']
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
    zan_require_csrf();
    $company = trim($_POST['company_name'] ?? '');
    if ($company === '') exit('Jina la msambazaji linahitajika.');
    $s=$pdo->prepare('INSERT INTO suppliers (company_name,contact_person,phone,email,address,tin,vrn,notes,created_at) VALUES(?,?,?,?,?,?,?,?,?)');
    $s->execute([$company,trim($_POST['contact_person']??''),trim($_POST['phone']??''),trim($_POST['email']??''),trim($_POST['address']??''),trim($_POST['tin']??''),trim($_POST['vrn']??''),trim($_POST['notes']??''),date('Y-m-d H:i:s')]);
    log_action('Aliongeza msambazaji','Msambazaji: '.$company);
    header('Location:?page=suppliers'); exit;
}
if ($page === 'update_supplier' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    zan_require_csrf();
    $id=(int)($_POST['id']??0); $company=trim($_POST['company_name']??'');
    if($id<=0||$company==='') exit('Taarifa za msambazaji si sahihi.');
    $s=$pdo->prepare('UPDATE suppliers SET company_name=?,contact_person=?,phone=?,email=?,address=?,tin=?,vrn=?,notes=?,updated_at=? WHERE id=?');
    $s->execute([$company,trim($_POST['contact_person']??''),trim($_POST['phone']??''),trim($_POST['email']??''),trim($_POST['address']??''),trim($_POST['tin']??''),trim($_POST['vrn']??''),trim($_POST['notes']??''),date('Y-m-d H:i:s'),$id]);
    log_action('Alibadilisha msambazaji','ID: '.$id); header('Location:?page=suppliers'); exit;
}
if ($page === 'delete_supplier' && $_SERVER['REQUEST_METHOD']==='POST') {
    zan_require_csrf();
    $id=(int)($_POST['id']??0); $c=$pdo->prepare('SELECT COUNT(*) FROM products WHERE supplier_id=?'); $c->execute([$id]);
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
    zan_require_csrf();
    $barcode = trim($_POST['barcode'] ?? '');
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    if ($barcode !== '') {
        $check=$pdo->prepare('SELECT id FROM products WHERE barcode=? LIMIT 1'); $check->execute([$barcode]);
        if($check->fetch()) exit('Barcode hii tayari imetumika na bidhaa nyingine.');
    }
    $receivedAt=trim($_POST['stock_received_at']??''); if($receivedAt==='')$receivedAt=date('Y-m-d');
    $photo=zan_upload_product_photo('product_photo');
    $s=$pdo->prepare('INSERT INTO products (name,sku,category,price,cost,stock,low_stock,barcode,supplier_id,stock_received_at,photo) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $s->execute([$_POST['name'],$_POST['sku']??'',$_POST['category']??'',(float)$_POST['price'],(float)($_POST['cost']??0),(int)$_POST['stock'],(int)($_POST['low_stock']??5),$barcode,$supplierId>0?$supplierId:null,$receivedAt,$photo]);
    log_action('Aliongeza bidhaa','Bidhaa: '.$_POST['name']); header('Location:?page=products'); exit;
}

if (
    $page === 'update_product' &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {
    zan_require_csrf();
    $barcode=trim($_POST['barcode']??''); $productId=(int)($_POST['id']??0); $supplierId=(int)($_POST['supplier_id']??0);
    if($barcode!==''){ $check=$pdo->prepare('SELECT id FROM products WHERE barcode=? AND id<>? LIMIT 1'); $check->execute([$barcode,$productId]); if($check->fetch()) exit('Barcode hii tayari imetumika na bidhaa nyingine.'); }
    $receivedAt=trim($_POST['stock_received_at']??''); if($receivedAt==='')$receivedAt=date('Y-m-d');
    $photo=zan_upload_product_photo('product_photo');
    if($photo){
        $s=$pdo->prepare('UPDATE products SET name=?,sku=?,category=?,price=?,cost=?,stock=?,low_stock=?,barcode=?,supplier_id=?,stock_received_at=?,photo=? WHERE id=?');
        $s->execute([$_POST['name'],$_POST['sku']??'',$_POST['category']??'',(float)$_POST['price'],(float)($_POST['cost']??0),(int)$_POST['stock'],(int)($_POST['low_stock']??5),$barcode,$supplierId>0?$supplierId:null,$receivedAt,$photo,$productId]);
    } else {
        $s=$pdo->prepare('UPDATE products SET name=?,sku=?,category=?,price=?,cost=?,stock=?,low_stock=?,barcode=?,supplier_id=?,stock_received_at=? WHERE id=?');
        $s->execute([$_POST['name'],$_POST['sku']??'',$_POST['category']??'',(float)$_POST['price'],(float)($_POST['cost']??0),(int)$_POST['stock'],(int)($_POST['low_stock']??5),$barcode,$supplierId>0?$supplierId:null,$receivedAt,$productId]);
    }
    log_action('Alibadilisha bidhaa','ID: '.$productId); header('Location:?page=products'); exit;
}

if ($page === 'delete_product' && $_SERVER['REQUEST_METHOD']==='POST' ) {
    zan_require_csrf();

    $s = $pdo->prepare(
        'DELETE FROM products WHERE id=?'
    );

    $s->execute([
        (int)$_POST['id']
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
    zan_require_csrf();

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
                max(0, (float)(
                    $prices[$k] ?? 0
                ));

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

        try {
        if ($grand <= 0) {
            throw new Exception('Invoice lazima iwe na bidhaa au labor yenye thamani zaidi ya sifuri.');
        }

        $paid =
            max(0, (float)(
                $_POST['paid'] ?? 0
            ));

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

            if ($paid > 0) {
                $pdo->prepare(
                    'INSERT INTO payments
                    (invoice_id,amount,payment_date,payment_method,reference,notes,received_by)
                    VALUES(?,?,?,?,?,?,?)'
                )->execute([
                    $iid,
                    $paid,
                    date('Y-m-d'),
                    trim($_POST['payment_method'] ?? 'Cash') ?: 'Cash',
                    trim($_POST['payment_reference'] ?? ''),
                    'Malipo ya awali wakati wa kutengeneza invoice',
                    (int)$_SESSION['user']['id']
                ]);
            }

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
                    max(0, (float)(
                        $prices[$k] ?? 0
                    ));

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
    zan_require_csrf();

    $invoiceId =
        (int)$_POST['invoice_id'];

    $existingInvoiceQuery = $pdo->prepare('SELECT paid FROM invoices WHERE id=? LIMIT 1');
    $existingInvoiceQuery->execute([$invoiceId]);
    $existingInvoicePaid = $existingInvoiceQuery->fetchColumn();
    if ($existingInvoicePaid === false) {
        exit('Invoice haipatikani.');
    }

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
                max(0, (float)(
                    $prices[$k] ?? 0
                ));

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

        if ($grand <= 0) {
            throw new Exception('Invoice lazima iwe na bidhaa au labor yenye thamani zaidi ya sifuri.');
        }

        if ($grand + 0.001 < (float)$existingInvoicePaid) {
            throw new Exception('Jumla mpya haiwezi kuwa chini ya malipo yaliyorekodiwa. Tumia ukurasa wa malipo kusimamia malipo.');
        }

        $paid = min(max(0, (float)$existingInvoicePaid), $grand);

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
if($page==='delete_invoice' && $_SERVER['REQUEST_METHOD']==='POST'){
    zan_require_csrf();
    $invoiceId=(int)($_POST['id']??0); if($invoiceId<=0)exit('Invoice si sahihi.');
    try{ $pdo->beginTransaction(); $q=$pdo->prepare('SELECT invoice_no FROM invoices WHERE id=?');$q->execute([$invoiceId]);$inv=$q->fetch();if(!$inv)throw new Exception('Invoice haipatikani.');
        $iq=$pdo->prepare('SELECT product_id,qty FROM invoice_items WHERE invoice_id=?');$iq->execute([$invoiceId]);$restore=$pdo->prepare('UPDATE products SET stock=stock+? WHERE id=?');foreach($iq->fetchAll() as $it)if(!empty($it['product_id']))$restore->execute([(int)$it['qty'],(int)$it['product_id']]);
        $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id=?')->execute([$invoiceId]);$pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$invoiceId]);$pdo->commit();log_action('Alifuta ankara','Namba: '.$inv['invoice_no']);header('Location:?page=invoices&deleted=1');exit;
    }catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();exit('Imeshindikana kufuta ankara: '.htmlspecialchars($e->getMessage()));}
}

/* =========================================================
   RECORD INVOICE PAYMENT
   ========================================================= */
if($page==='record_payment'&&$_SERVER['REQUEST_METHOD']==='POST'){
    zan_require_csrf();
    $invoiceId=(int)($_POST['invoice_id']??0);
    $amount=(float)($_POST['amount']??0);
    $paymentDate=trim($_POST['payment_date']??date('Y-m-d'));
    $method=trim($_POST['payment_method']??'Cash')?:'Cash';
    $reference=trim($_POST['reference']??'');
    $notes=trim($_POST['notes']??'');
    try{
        if($invoiceId<=0||$amount<=0)throw new Exception('Invoice na kiasi cha malipo vinahitajika.');
        $invoiceQuery=$pdo->prepare('SELECT invoice_no,total,paid FROM invoices WHERE id=? LIMIT 1');$invoiceQuery->execute([$invoiceId]);$invoice=$invoiceQuery->fetch(PDO::FETCH_ASSOC);
        if(!$invoice)throw new Exception('Invoice haipatikani.');
        $balance=max(0,(float)$invoice['total']-(float)$invoice['paid']);
        if($amount>$balance+0.001)throw new Exception('Malipo yanazidi salio la invoice: TZS '.number_format($balance,2));
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO payments(invoice_id,amount,payment_date,payment_method,reference,notes,received_by) VALUES(?,?,?,?,?,?,?)')->execute([$invoiceId,$amount,$paymentDate,$method,$reference,$notes,(int)($_SESSION['user']['id']??0)]);
        $newPaid=(float)$invoice['paid']+$amount;
        $newStatus=$newPaid+0.001>=(float)$invoice['total']?'Imelipwa':'Sehemu imelipwa';
        $pdo->prepare('UPDATE invoices SET paid=?,status=? WHERE id=?')->execute([$newPaid,$newStatus,$invoiceId]);
        $pdo->commit();
        log_action('Alirekodi malipo','Invoice '.$invoice['invoice_no'].' TZS '.number_format($amount,2).' via '.$method);
        header('Location:?page=payment&id='.$invoiceId.'&saved=1');exit;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();header('Location:?page=payment&id='.$invoiceId.'&error='.urlencode($e->getMessage()));exit;}
}

/* =========================================================
   EXPENSES
   ========================================================= */
if($page==='save_expense'&&$_SERVER['REQUEST_METHOD']==='POST'){
    zan_require_csrf();
    $date=trim($_POST['expense_date']??date('Y-m-d'));$category=trim($_POST['category']??'Other');$description=trim($_POST['description']??'');$amount=(float)($_POST['amount']??0);$paidBy=trim($_POST['paid_by']??'');$notes=trim($_POST['notes']??'');
    if($amount<=0)exit('Amount ya matumizi lazima iwe zaidi ya 0.');$q=$pdo->prepare('INSERT INTO expenses (expense_date,category,description,amount,paid_by,notes,created_by,created_at) VALUES(?,?,?,?,?,?,?,?)');$q->execute([$date,$category,$description,$amount,$paidBy,$notes,(int)$_SESSION['user']['id'],date('Y-m-d H:i:s')]);log_action('Aliongeza matumizi',$category.' TZS '.number_format($amount,2));header('Location:?page=expenses&saved=1');exit;
}
if($page==='delete_expense' && $_SERVER['REQUEST_METHOD']==='POST'){ zan_require_csrf(); $id=(int)($_POST['id']??0);$pdo->prepare('DELETE FROM expenses WHERE id=?')->execute([$id]);log_action('Alifuta matumizi','ID: '.$id);header('Location:?page=expenses&deleted=1');exit; }

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
    foreach($ids as $k=>$id){$q=(int)($qty[$k]??0);$pr=max(0,(float)($prices[$k]??0));if($q>0)$subtotal+=$q*$pr;}
    if($laborCharge>0)$subtotal+=$laborCharge;
    [$subtotal,$vat,$grand]=invoice_numbers($subtotal,vat_customer($customer));
    if($grand<=0)throw new Exception('Quotation/Proforma lazima iwe na thamani zaidi ya sifuri.');
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
        foreach($ids as $k=>$id){$qv=(int)($qty[$k]??0);$pr=max(0,(float)($prices[$k]??0));if($qv<1)continue;$pq=$pdo->prepare('SELECT name FROM products WHERE id=?');$pq->execute([(int)$id]);$prod=$pq->fetch();if(!$prod)throw new Exception('Product not found.');$ins->execute([$docId,(int)$id,$prod['name'],$qv,$pr,$qv*$pr]);}
        if($laborCharge>0){$ins->execute([$docId,null,$laborDescription!==''?$laborDescription:'Labor Charge',1,$laborCharge,$laborCharge]);}
        $pdo->commit();
        return[$docId,$no];
    }catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
if($page==='save_quotation'&&$_SERVER['REQUEST_METHOD']==='POST'){zan_require_csrf();try{[$id,$no]=save_sales_document('quotation');header('Location:?page=print_quotation&id='.$id);exit;}catch(Exception $e){$error=$e->getMessage();}}
if($page==='save_proforma'&&$_SERVER['REQUEST_METHOD']==='POST'){zan_require_csrf();try{[$id,$no]=save_sales_document('proforma');header('Location:?page=print_proforma&id='.$id);exit;}catch(Exception $e){$error=$e->getMessage();}}
function update_sales_document($type,$docId){global $pdo;$customerId=(int)($_POST['customer_id']??0);$ids=$_POST['product_id']??[];$qty=$_POST['qty']??[];$prices=$_POST['price']??[];$laborDescription=trim($_POST['labor_description']??'');$laborCharge=max(0,(float)($_POST['labor_charge']??0));$validUntil=trim($_POST['valid_until']??'');$notes=trim($_POST['notes']??'');$terms=trim($_POST['terms']??'');$cq=$pdo->prepare('SELECT * FROM customers WHERE id=?');$cq->execute([$customerId]);$customer=$cq->fetch();if(!$customer)throw new Exception('Customer not found.');$subtotal=0;foreach($ids as $k=>$pid){$q=(int)($qty[$k]??0);$p=max(0,(float)($prices[$k]??0));if($q>0)$subtotal+=$q*$p;}$subtotal+=$laborCharge;[$subtotal,$vat,$grand]=invoice_numbers($subtotal,vat_customer($customer));if($grand<=0)throw new Exception('Quotation/Proforma lazima iwe na thamani zaidi ya sifuri.');$quote=$type==='quotation';$table=$quote?'quotations':'proforma_invoices';$items=$quote?'quotation_items':'proforma_items';$fk=$quote?'quotation_id':'proforma_id';$pdo->beginTransaction();try{$pdo->prepare("UPDATE {$table} SET customer_id=?,subtotal=?,vat=?,total=?,valid_until=?,notes=?,terms=? WHERE id=?")->execute([$customerId,$subtotal,$vat,$grand,$validUntil!==''?$validUntil:null,$notes!==''?$notes:null,$terms!==''?$terms:null,$docId]);$pdo->prepare("DELETE FROM {$items} WHERE {$fk}=?")->execute([$docId]);$ins=$pdo->prepare("INSERT INTO {$items} ({$fk},product_id,description,qty,price,subtotal) VALUES(?,?,?,?,?,?)");foreach($ids as $k=>$pid){$q=(int)($qty[$k]??0);$p=max(0,(float)($prices[$k]??0));if($q<1)continue;$s=$pdo->prepare('SELECT name FROM products WHERE id=?');$s->execute([(int)$pid]);$prod=$s->fetch();if(!$prod)throw new Exception('Product not found.');$ins->execute([$docId,(int)$pid,$prod['name'],$q,$p,$q*$p]);}if($laborCharge>0)$ins->execute([$docId,null,$laborDescription?:'Labor Charge',1,$laborCharge,$laborCharge]);$pdo->commit();}catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}}
if($page==='update_quotation'&&$_SERVER['REQUEST_METHOD']==='POST'){zan_require_csrf();try{$id=(int)($_GET['id']??0);update_sales_document('quotation',$id);header('Location:?page=print_quotation&id='.$id);exit;}catch(Exception $e){$error=$e->getMessage();}}
if($page==='update_proforma'&&$_SERVER['REQUEST_METHOD']==='POST'){zan_require_csrf();try{$id=(int)($_GET['id']??0);update_sales_document('proforma',$id);header('Location:?page=print_proforma&id='.$id);exit;}catch(Exception $e){$error=$e->getMessage();}}
if($page==='delete_quotation' && $_SERVER['REQUEST_METHOD']==='POST'){ zan_require_csrf(); $id=(int)($_POST['id']??0);$pdo->prepare('DELETE FROM quotation_items WHERE quotation_id=?')->execute([$id]);$pdo->prepare('DELETE FROM quotations WHERE id=?')->execute([$id]);header('Location:?page=quotations');exit; }
if($page==='delete_proforma' && $_SERVER['REQUEST_METHOD']==='POST'){ zan_require_csrf(); $id=(int)($_POST['id']??0);$pdo->prepare('DELETE FROM proforma_items WHERE proforma_id=?')->execute([$id]);$pdo->prepare('DELETE FROM proforma_invoices WHERE id=?')->execute([$id]);header('Location:?page=proforma');exit; }

if($page==='convert_quotation'&&$_SERVER['REQUEST_METHOD']==='POST'){
    zan_require_csrf();
    $quotationId=(int)($_POST['quotation_id']??0);
    try{
        $q=$pdo->prepare('SELECT * FROM quotations WHERE id=? LIMIT 1');$q->execute([$quotationId]);$quotation=$q->fetch(PDO::FETCH_ASSOC);if(!$quotation)throw new Exception('Quotation haipatikani.');if(!empty($quotation['converted_invoice_id']))throw new Exception('Quotation hii tayari imegeuzwa kuwa invoice.');
        $itemsQuery=$pdo->prepare('SELECT * FROM quotation_items WHERE quotation_id=? ORDER BY id');$itemsQuery->execute([$quotationId]);$items=$itemsQuery->fetchAll(PDO::FETCH_ASSOC);
        $number='ANK-'.date('Ym').'-'.str_pad((string)((int)$pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn()+1),4,'0',STR_PAD_LEFT);
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO invoices(invoice_no,customer_id,total,paid,status,prepared_by,created_at) VALUES(?,?,?,?,?,?,?)')->execute([$number,$quotation['customer_id'],(float)$quotation['total'],0,'Haijalipwa',(int)($_SESSION['user']['id']??0),date('Y-m-d H:i:s')]);
        $invoiceId=(int)$pdo->lastInsertId();$insertItem=$pdo->prepare('INSERT INTO invoice_items(invoice_id,product_id,description,qty,price,subtotal) VALUES(?,?,?,?,?,?)');$reduceStock=$pdo->prepare('UPDATE products SET stock=stock-? WHERE id=? AND stock>=?');
        foreach($items as $item){$qty=(int)$item['qty'];if($item['product_id']!==null){$reduceStock->execute([$qty,(int)$item['product_id'],$qty]);if($pdo->query('SELECT changes()')->fetchColumn()!=1)throw new Exception('Stock haitoshi kwa bidhaa: '.$item['description']);}$insertItem->execute([$invoiceId,$item['product_id'],$item['description'],$qty,$item['price'],$item['subtotal']]);}
        $pdo->prepare('UPDATE quotations SET status=?,converted_invoice_id=? WHERE id=?')->execute(['Converted',$invoiceId,$quotationId]);
        $pdo->commit();log_action('Quotation imegeuzwa kuwa invoice',$quotation['quotation_no'].' -> '.$number);header('Location:?page=print&id='.$invoiceId);exit;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();header('Location:?page=quotations&error='.urlencode($e->getMessage()));exit;}
}

if($page==='export_reports'){
    $from=trim((string)($_GET['from']??date('Y-m-01')));$to=trim((string)($_GET['to']??date('Y-m-d')));
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from))$from=date('Y-m-01');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))$to=date('Y-m-d');
    $rows=[];$invoiceExport=$pdo->prepare("SELECT i.invoice_no,c.name customer,i.created_at,i.total,i.paid,MAX(0,i.total-i.paid) balance,i.status FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id WHERE date(i.created_at) BETWEEN ? AND ? ORDER BY i.created_at DESC");$invoiceExport->execute([$from,$to]);
    foreach($invoiceExport as $row)$rows[]=['Invoice',$row['invoice_no'],$row['customer']??'',$row['created_at'],$row['total'],$row['paid'],$row['balance'],$row['status']];
    $expenseExport=$pdo->prepare("SELECT category,description,expense_date,amount,paid_by FROM expenses WHERE date(expense_date) BETWEEN ? AND ? ORDER BY expense_date DESC");$expenseExport->execute([$from,$to]);
    foreach($expenseExport as $row)$rows[]=['Expense',$row['category'],$row['description'],$row['expense_date'],$row['amount'],'','',$row['paid_by']];
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="zantronix-report-'.$from.'-to-'.$to.'.csv"');$out=fopen('php://output','w');fputcsv($out,['Type','Number/Category','Customer/Description','Date','Total/Amount','Paid','Balance','Status/Paid By']);foreach($rows as $row)fputcsv($out,$row);fclose($out);exit;
}

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

$reportFrom=trim((string)($_GET['from']??date('Y-m-01')));
$reportTo=trim((string)($_GET['to']??date('Y-m-d')));
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$reportFrom))$reportFrom=date('Y-m-01');
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$reportTo))$reportTo=date('Y-m-d');
$reportSalesQuery=$pdo->prepare("SELECT COALESCE(SUM(total),0) FROM invoices WHERE date(created_at) BETWEEN ? AND ?");$reportSalesQuery->execute([$reportFrom,$reportTo]);$reportSales=(float)$reportSalesQuery->fetchColumn();
$reportPaidQuery=$pdo->prepare("SELECT COALESCE(SUM(paid),0) FROM invoices WHERE date(created_at) BETWEEN ? AND ?");$reportPaidQuery->execute([$reportFrom,$reportTo]);$reportPaid=(float)$reportPaidQuery->fetchColumn();
$reportExpenseQuery=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE date(expense_date) BETWEEN ? AND ?");$reportExpenseQuery->execute([$reportFrom,$reportTo]);$reportExpenses=(float)$reportExpenseQuery->fetchColumn();
$reportExpensesRowsQuery=$pdo->prepare("SELECT e.*,u.name created_by_name FROM expenses e LEFT JOIN users u ON u.id=e.created_by WHERE date(e.expense_date) BETWEEN ? AND ? ORDER BY e.expense_date DESC,e.id DESC");$reportExpensesRowsQuery->execute([$reportFrom,$reportTo]);$reportExpensesRows=$reportExpensesRowsQuery->fetchAll();
$reportOutstanding=max(0,$reportSales-$reportPaid);$reportNet=$reportSales-$reportExpenses;

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
    'ai_assistant' => zan_t('AI ya Biashara','Business AI Assistant'),
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

<html lang="<?=htmlspecialchars($zanLang, ENT_QUOTES, 'UTF-8')?>">

<head>

<meta charset="utf-8">

<meta name="viewport"
      content="width=device-width,initial-scale=1">

<title>
<?=htmlspecialchars(
    $settings['company_name']
    ?? 'Zantronix'
)?></title>
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

/* =========================================================
   BUSINESS TRACKING — MODERN ZANTRONIX DASHBOARD VISUAL
   Visual-only layer: keeps existing data, links and actions.
   ========================================================= */
.business-dashboard{max-width:1380px!important;margin:0 auto!important;padding:28px 34px 40px!important}
.business-dashboard .head{display:flex;align-items:center;justify-content:space-between;gap:20px;margin:0 0 22px!important}
.business-dashboard .head h1{font-size:30px!important;line-height:1.1!important;margin:0 0 5px!important;font-weight:900!important;letter-spacing:-.5px}
.business-dashboard .head .muted{font-size:14px!important;color:#697386!important}
.business-dashboard .head .btn{padding:12px 18px!important;border-radius:11px!important;box-shadow:0 5px 14px rgba(255,208,0,.22)!important}

.business-dashboard .cards{grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:16px!important;margin-bottom:16px!important}
.business-dashboard .cards .card{position:relative;min-height:104px!important;padding:20px 20px 17px 68px!important;border:1px solid #e5e9ef!important;border-radius:15px!important;background:#fff!important;box-shadow:0 5px 18px rgba(20,30,45,.07)!important;overflow:hidden}
.business-dashboard .cards .card:before{content:'';position:absolute;left:18px;top:20px;width:38px;height:38px;border-radius:12px;background:#fff1a8;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:900}
.business-dashboard .cards .card:nth-child(1):before{content:'↗'}
.business-dashboard .cards .card:nth-child(2):before{content:'₿'}
.business-dashboard .cards .card:nth-child(3):before{content:'▤'}
.business-dashboard .cards .card:nth-child(4):before{content:'▣';background:#ffe2e2}
.business-dashboard .cards .label{font-size:11px!important;font-weight:800!important;color:#748093!important;letter-spacing:.05em!important;margin-bottom:7px!important}
.business-dashboard .cards .big{font-size:25px!important;font-weight:900!important;letter-spacing:-.3px!important;color:#18202b!important}
.business-dashboard .cards .big.low{color:#e53935!important}
.business-dashboard .cards .card:after{content:'';position:absolute;right:0;bottom:0;width:42%;height:3px;background:#ffd000}
.business-dashboard .cards .card:nth-child(4):after{background:#ef4444}

.business-dashboard .management-strip{grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:10px!important;margin:0 0 12px!important}
.business-dashboard .management-item{border:1px solid #e7ebf0!important;border-radius:12px!important;padding:12px 14px!important;background:#fbfcfd!important;box-shadow:none!important}
.business-dashboard .management-item .label{font-size:10px!important;font-weight:800!important;color:#7a8493!important}
.business-dashboard .management-item .value{font-size:16px!important;font-weight:900!important;color:#202833!important;margin-top:5px!important}
.business-dashboard .management-actions{gap:8px!important;margin:0 0 18px!important}
.business-dashboard .management-actions .btn{border-radius:9px!important;padding:10px 13px!important;font-size:12px!important;box-shadow:none!important}
.business-dashboard .management-actions .btn:hover{transform:translateY(-1px);box-shadow:0 5px 12px rgba(0,0,0,.08)!important}

.business-dashboard .dashboard-charts{grid-template-columns:1.25fr 1fr!important;gap:16px!important;margin:0 0 18px!important}
.business-dashboard .dashboard-charts .panel,
.business-dashboard .grid .panel{border:1px solid #e5e9ef!important;border-radius:15px!important;background:#fff!important;box-shadow:0 5px 18px rgba(20,30,45,.055)!important}
.business-dashboard .dashboard-charts .panel{padding:20px!important}
.business-dashboard .dashboard-charts h3,
.business-dashboard .grid h3{font-size:15px!important;font-weight:900!important;margin:0 0 15px!important;color:#1b2430!important}
.business-dashboard .chart-row{font-size:12px!important;margin:11px 0 6px!important;color:#4b5563!important}
.business-dashboard .chart-row b{font-size:12px!important;color:#1f2937!important}
.business-dashboard .bar{height:9px!important;background:#edf0f4!important;border-radius:999px!important}
.business-dashboard .bar span{background:linear-gradient(90deg,#ffd000,#f2b900)!important}

.business-dashboard .grid{grid-template-columns:1.65fr 1fr!important;gap:16px!important;align-items:stretch!important}
.business-dashboard .grid .panel{padding:20px!important;min-width:0!important}
.business-dashboard .table{border-collapse:separate!important;border-spacing:0!important;width:100%!important}
.business-dashboard .table th{font-size:10px!important;letter-spacing:.03em!important;text-transform:uppercase!important;padding:11px 10px!important;background:#12161c!important;color:#ffd000!important;border:0!important}
.business-dashboard .table th:first-child{border-radius:8px 0 0 8px}
.business-dashboard .table th:last-child{border-radius:0 8px 8px 0}
.business-dashboard .table td{padding:12px 10px!important;border-bottom:1px solid #edf0f3!important;font-size:12px!important;background:#fff!important}
.business-dashboard .table tr:hover td{background:#fffbea!important}
.business-dashboard .badge{padding:5px 9px!important;font-size:10px!important}
.business-dashboard .grid .panel>p{font-size:12px!important}

/* Make the existing header feel like the reference without changing its controls. */
.top{background:#ffd000!important;color:#111!important;border-bottom:0!important;box-shadow:0 2px 12px rgba(20,20,20,.08)!important;min-height:64px!important}
.top .search{background:#fff!important;color:#111!important;border:1px solid rgba(0,0,0,.10)!important;border-radius:12px!important;padding:11px 15px!important}
.top .lang-switch .btn{background:#fff!important;color:#111!important;border-color:rgba(0,0,0,.08)!important}
.top .lang-switch .btn.on{background:#111!important;color:#ffd000!important}
.top .top-logout{background:#111!important;color:#ffd000!important;border-radius:9px!important;padding:9px 12px!important;text-decoration:none!important;font-weight:800!important}

/* Sidebar polish */
.side{box-shadow:5px 0 18px rgba(0,0,0,.10)!important}
.side .nav a{border-radius:9px!important;margin:2px 8px!important}
.side .nav a.on,.side .nav a:hover{box-shadow:0 4px 12px rgba(255,208,0,.18)!important}

@media(max-width:1100px){
 .business-dashboard{padding:22px 20px 32px!important}
 .business-dashboard .cards{grid-template-columns:repeat(2,minmax(0,1fr))!important}
 .business-dashboard .management-strip{grid-template-columns:repeat(2,minmax(0,1fr))!important}
 .business-dashboard .dashboard-charts,.business-dashboard .grid{grid-template-columns:1fr!important}
}
@media(max-width:700px){
 .business-dashboard{padding:16px 12px 28px!important}
 .business-dashboard .head{align-items:flex-start;flex-direction:column}
 .business-dashboard .head h1{font-size:24px!important}
 .business-dashboard .head .btn{width:100%;text-align:center;box-sizing:border-box}
 .business-dashboard .cards{grid-template-columns:1fr!important}
 .business-dashboard .management-strip{grid-template-columns:1fr 1fr!important}
 .business-dashboard .management-actions{overflow:auto;flex-wrap:nowrap!important;padding-bottom:3px}
 .business-dashboard .management-actions .btn{white-space:nowrap!important}
 .business-dashboard .grid .panel{overflow-x:auto}
}


/* BUSINESS TRACKING — COMPACT PROFESSIONAL UI */
.business-page{font-size:12px!important}
.business-page .head{margin-bottom:12px!important}
.business-page .head h1{font-size:23px!important;margin-bottom:3px!important}
.business-page .panel{padding:12px!important;border-radius:10px!important;box-shadow:0 3px 12px rgba(15,23,42,.05)!important}
.business-page .table th{font-size:10px!important;padding:7px 8px!important}
.business-page .table td{font-size:11px!important;padding:7px 8px!important;line-height:1.2!important}
.business-page .btn{font-size:10px!important;padding:6px 9px!important;border-radius:7px!important}
.business-page input,.business-page select,.business-page textarea{font-size:11px!important;padding:8px 9px!important}
.business-page label{font-size:11px!important}
.business-page .cards .card{min-height:82px!important;padding:13px 13px 12px 52px!important;border-radius:11px!important}
.business-page .cards .card:before{left:12px;top:13px;width:30px;height:30px;border-radius:9px;font-size:16px}
.business-page .dashboard-charts{gap:12px!important;margin:12px 0!important}
.business-page .grid{gap:12px!important}
.business-page .management-strip{gap:8px!important}
.business-page .management-actions .btn{padding:6px 8px!important}
.business-page .backup-box{padding:12px!important;border-radius:10px!important}
@media(max-width:900px){.business-page .table{min-width:720px!important}}

/* CUSTOMER PAGE — COMPACT MODERN ZANTRONIX UI */
.customers-modern,.debt-modern{border-radius:12px!important;box-shadow:0 4px 14px rgba(15,23,42,.055)!important;background:#fff!important}
.customers-modern{padding:10px!important;margin-top:8px!important}
.debt-modern{padding:11px 10px 9px!important;margin-top:10px!important}
.customer-title-row{margin-bottom:7px!important}
.customer-title{gap:7px!important}
.customer-title-icon{width:30px!important;height:30px!important;font-size:15px!important}
.customer-title h2{font-size:18px!important}
.customer-title p,.debt-title p{font-size:10px!important}
.customer-add{font-size:10px!important;padding:7px 10px!important;border-radius:7px!important}
.customers-modern .table,.debt-modern .table{min-width:0!important}
.customers-modern .table th,.debt-modern .table th{font-size:9px!important;padding:8px 8px!important;line-height:1.1!important}
.customers-modern .table td,.debt-modern .table td{font-size:11px!important;padding:7px 8px!important;line-height:1.25!important}
.debt-title{gap:7px!important;margin-bottom:7px!important}
.debt-icon{width:30px!important;height:30px!important;font-size:15px!important}
.debt-title h2{font-size:17px!important}
.debt-balance{font-size:11px!important}
.customer-wa-cell,.debt-wa-cell{white-space:nowrap!important}
.customer-wa-btn,.debt-wa-btn{font-size:9px!important;padding:4px 7px!important;gap:3px!important;border-radius:6px!important;min-height:26px!important;line-height:1!important}
.customer-wa-btn .wa-svg,.debt-wa-btn .wa-svg{width:12px!important;height:12px!important;flex:0 0 12px!important}
.customers-modern .btn.small{font-size:9px!important;padding:4px 7px!important;border-radius:6px!important;line-height:1.1!important}
.customer-empty-wa{font-size:9px!important}
@media(max-width:900px){.customers-modern .table,.debt-modern .table{min-width:760px!important}.customer-title h2,.debt-title h2{font-size:16px!important}}
</style>

</head>

<body>

<!-- ======================================================
     SIDEBAR
     ====================================================== -->

<aside class="side">

<div class="logo">
<?php if(!empty($settings['logo']) && file_exists(__DIR__.'/'.$settings['logo'])):?><img src="<?=htmlspecialchars($settings['logo'])?>" alt="Zantronix logo" style="display:block;max-width:190px;max-height:70px;object-fit:contain;background:#fff;padding:4px;border-radius:5px"><?php else:?>âš¡ ZANTRONIX<?php endif;?>

<span>
MFUMO WA ANKARA
</span>

</div>

<nav class="nav">

<?php
$navPermissionMap=['ai_assistant'=>'dashboard','new_invoice'=>'invoices','invoices'=>'invoices','new_quotation'=>'invoices','quotations'=>'invoices','new_proforma'=>'invoices','proforma'=>'invoices','suppliers'=>'products'];
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

<div class="wrap <?=($page==='dashboard'?'business-dashboard ':'')?><?=in_array($page,array_keys($nav),true)?'business-page':''?>">

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
$customerTotal = (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
$expenseTotal = (float)$pdo->query('SELECT COALESCE(SUM(amount),0) FROM expenses')->fetchColumn();
$afterExpense = $salesAll - $expenseTotal;
?>

<div class="management-strip">
    <div class="management-item"><div class="label">Wateja</div><div class="value"><?=$customerTotal?></div></div>
    <div class="management-item"><div class="label">Malipo yaliyopokelewa</div><div class="value">TZS <?=number_format($paidAll,0)?></div></div>
    <div class="management-item"><div class="label">Expenses</div><div class="value">TZS <?=number_format($expenseTotal,0)?></div></div>
    <div class="management-item"><div class="label">Baada ya expenses</div><div class="value">TZS <?=number_format($afterExpense,0)?></div></div>
</div>
<div class="management-actions">
    <a class="btn" href="?page=reports">&#128202; Fungua Reports</a>
    <a class="btn" href="?page=customers">ðŸ‘¥ Wateja</a>
    <a class="btn" href="?page=debt_reminders">&#128172; Wateja Wadaiwa</a>
    <a class="btn" href="?page=products">ðŸ“¦ Simamia Stock</a>
    <a class="btn" href="?page=ai_assistant">ðŸ¤– Uliza AI</a>
</div>

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

â€”

<b class="low">
<?=$p['stock']?>
</b>

</p>

<?php endforeach; ?>

</div>

</div>

<?php elseif($page==='ai_assistant'): ?>
<?php
    $businessAiChat=$_SESSION['business_ai_chat']??[];
    $businessAiError=$_SESSION['business_ai_error']??'';
    unset($_SESSION['business_ai_error']);
    $businessAiFacts=zan_business_ai_context($pdo);
?>
<?php
$aiCustomers=(int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$aiProducts=(int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$aiLowStock=(int)$pdo->query("SELECT COUNT(*) FROM products WHERE stock<=low_stock")->fetchColumn();
$aiInvoices=(int)$pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
$aiPayments=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments")->fetchColumn();
$aiExpenses=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses")->fetchColumn();
$aiQuotes=(int)$pdo->query("SELECT COUNT(*) FROM quotations")->fetchColumn();
$aiProforma=(int)$pdo->query("SELECT COUNT(*) FROM proforma_invoices")->fetchColumn();
?>
<div class="ai-page">
  <div class="ai-page-head"><div><h1>🤖 AI ya Biashara</h1><span class="muted">Msaidizi wa mauzo, wateja, stock, expenses na reports.</span></div><div class="actions"><a class="btn" href="?page=new_invoice">+ Invoice</a><a class="btn dark" href="?page=reports">Reports</a></div></div>
  <?php if($businessAiError): ?><div class="notice danger"><?=htmlspecialchars($businessAiError,ENT_QUOTES,'UTF-8')?></div><?php endif; ?>

  <section class="ai-hero">
    <div class="ai-hero-top"><div class="ai-hero-avatar">🤖</div><div><h2>Karibu kwenye msaidizi wako wa biashara</h2><p>Uliza swali kwa lugha yako kuhusu data na kazi za biashara. Mfumo utakupa majibu ya haraka, sahihi na rahisi kuelewa.</p></div></div>
    <div class="ai-feature-row">
      <a class="ai-feature" href="?page=customers"><span class="ai-feature-icon">👥</span><span><b>Wateja</b><span>Taarifa za wateja</span></span></a>
      <a class="ai-feature" href="?page=products"><span class="ai-feature-icon">📦</span><span><b>Bidhaa & Stock</b><span>Hisa na bei</span></span></a>
      <a class="ai-feature" href="?page=invoices"><span class="ai-feature-icon">🧾</span><span><b>Invoices</b><span>Ankara na malipo</span></span></a>
      <a class="ai-feature" href="?page=reports"><span class="ai-feature-icon">📊</span><span><b>Reports</b><span>Takvimu na ripoti</span></span></a>
    </div>
  </section>

  <div class="ai-workspace">
    <div class="ai-chat-shell">
      <div class="ai-chat-header"><div class="ai-chat-header-main"><div class="ai-chat-avatar">🤖</div><div><strong>Uliza Swali</strong><small><span style="color:#2c7a35">●</span> Online · Zantronix Business Intelligence</small></div></div><div class="ai-chat-header-actions"><a href="?page=new_invoice">+ Tengeneza Invoice</a><a href="?page=invoices">Angalia Invoices</a></div></div>
      <div class="ai-welcome"><h2>Msaidizi wako yuko tayari</h2><p>Andika swali lako kwa lugha ya kawaida hapa chini.</p></div>
      <div class="ai-prompts"><a class="ai-prompt" href="?page=ai_assistant&prompt=muhtasari">Muhtasari wa biashara</a><a class="ai-prompt" href="?page=ai_assistant&prompt=wateja">Orodhesha wateja wote</a><a class="ai-prompt" href="?page=ai_assistant&prompt=low%20stock">Onyesha bidhaa zenye stock ndogo</a><a class="ai-prompt" href="?page=ai_assistant&prompt=invoices">Invoice na salio</a><a class="ai-prompt" href="?page=ai_assistant&prompt=expenses">Taarifa ya expenses</a></div>
      <div class="ai-chat-log"><?php if($businessAiChat): foreach($businessAiChat as $chat): ?><div class="ai-chat-message"><div class="ai-user-message"><?=nl2br(htmlspecialchars($chat['question'],ENT_QUOTES,'UTF-8'))?></div><div class="ai-answer-message"><?=nl2br(htmlspecialchars($chat['answer'],ENT_QUOTES,'UTF-8'))?></div><div class="small"><?=htmlspecialchars($chat['created_at'],ENT_QUOTES,'UTF-8')?></div></div><?php endforeach; else: ?><p class="muted">Anza mazungumzo hapa.</p><?php endif; ?></div>
      <form method="post" class="ai-chat-form"><input type="hidden" name="business_ai_action" value="ask"><input type="hidden" name="business_ai_csrf" value="<?=st_e(st_csrf_token())?>"><div class="field"><textarea name="question" rows="2" placeholder="Andika swali lako hapa..." required></textarea></div><div><button class="btn" type="submit" title="Tuma ujumbe">➤</button></div></form>
      <div class="ai-action-row"><a class="btn" href="?page=new_invoice">⚡ Tengeneza Invoice</a><a class="btn dark" href="?page=invoices">🧾 Angalia Invoices</a></div>
    </div>

    <aside class="ai-side-card">
      <div class="ai-data-live-title"><div><b>Data ya Biashara</b><div class="small">Hii ni data inayotumika kwenye msaidizi wa AI.</div></div><span>● Live</span></div>
      <div class="ai-metrics">
        <div class="ai-metric"><div class="icon">👥</div><span>Wateja</span><b><?=number_format($aiCustomers)?></b></div>
        <div class="ai-metric"><div class="icon">📦</div><span>Bidhaa</span><b><?=number_format($aiProducts)?></b></div>
        <div class="ai-metric"><div class="icon">🧾</div><span>Invoices</span><b><?=number_format($aiInvoices)?></b></div>
        <div class="ai-metric"><div class="icon">💰</div><span>Malipo</span><b>TZS <?=number_format($aiPayments,0)?></b></div>
        <div class="ai-metric"><div class="icon">🔖</div><span>Quotations</span><b><?=number_format($aiQuotes)?></b></div>
        <div class="ai-metric"><div class="icon">📋</div><span>Expenses</span><b>TZS <?=number_format($aiExpenses,0)?></b></div>
        <div class="ai-metric"><div class="icon">📄</div><span>Proforma</span><b><?=number_format($aiProforma)?></b></div>
        <div class="ai-metric"><div class="icon">⚠️</div><span>Low stock</span><b><?=number_format($aiLowStock)?></b></div>
      </div>
      <div class="ai-status"><span><span class="dot"></span>Mfumo umeunganishwa na data yako — AI iko tayari kujibu</span><span><?=date('Y-m-d H:i')?></span></div>
      <div class="ai-data-live-title"><div><b>AI inaweza kusaidia</b></div></div>
      <ul class="ai-side-list"><li>Mauzo, invoices na malipo</li><li>Wateja na wasambazaji</li><li>Bidhaa, stock na low stock</li><li>Expenses na reports</li><li>Muhtasari wa biashara na madeni</li><li>Maswali ya data kwa lugha rahisi</li></ul>
    </aside>
  </div>

  <div class="ai-bottom-grid">
    <div class="ai-bottom-card"><h3>💡 Mifano ya maswali unayoweza kuuliza</h3><ul class="ai-help-list"><li>Orodhesha wateja wote waliofanya malipo.</li><li>Ni bidhaa zipi zimeisha stock?</li><li>Onyesha invoices za mwezi huu.</li><li>Ni kiasi gani cha expenses kilikuwa mwezi jana?</li><li>Onyesha malipo yote yaliyopokelewa.</li></ul></div>
    <div class="ai-bottom-card"><h3>🚀 Msaidizi wa biashara wa Zantronix</h3><p class="ai-quote">“Uliza chochote kuhusu biashara yako — AI itakusaidia kupata taarifa kwa haraka na kwa lugha rahisi.”</p><div class="ai-action-row"><a class="btn" href="?page=customers">Wateja</a><a class="btn" href="?page=products">Bidhaa & Stock</a><a class="btn" href="?page=expenses">Expenses</a><a class="btn dark" href="?page=reports">Reports</a></div></div>
  </div>
</div>

<!-- ======================================================
    CUSTOMERS
    ====================================================== -->

<?php elseif($page==='debt_reminders'): ?>
<?php
    $debtRows=$pdo->query("SELECT i.invoice_no,i.total,i.paid,c.name,c.phone,c.whatsapp,dr.last_sent_at,dr.status,
        MAX(0,COALESCE(i.total,0)-COALESCE(i.paid,0)) balance
        FROM invoices i INNER JOIN customers c ON c.id=i.customer_id
        LEFT JOIN debt_reminders dr ON dr.invoice_id=i.id
        WHERE COALESCE(i.total,0)>COALESCE(i.paid,0) ORDER BY balance DESC")->fetchAll(PDO::FETCH_ASSOC);
    $debtCompany=$settings['company_name']??'Zantronix';
?>
<div class="head"><div><h1>Wateja Wadaiwa</h1><span class="muted">WhatsApp reminder hutumwa kila baada ya siku 3 kupitia worker.</span></div><div class="actions"><a class="btn" href="?page=invoices">Invoices</a><a class="btn" href="?page=ai_assistant&prompt=madeni">AI ya Biashara</a></div></div>
<div class="panel"><div class="table-wrap"><table class="table"><tr><th>Mteja</th><th>Invoice</th><th>Salio</th><th>Simu</th><th>Mara ya mwisho</th><th>WhatsApp</th></tr><?php foreach($debtRows as $debt): ?><?php $debtMessage='Habari '.($debt['name']?:'Mteja').', tunakukumbusha kuhusu deni lako la TZS '.number_format((float)$debt['balance'],2).' kwenye invoice '.$debt['invoice_no'].'. Tafadhali lipa mapema ili usipoteze uaminifu wetu na tuendelee kufanya biashara vizuri. Asante kwa ushirikiano wako.'; $debtWa=st_whatsapp_link($debt['whatsapp']?:$debt['phone'],$debtMessage); ?><tr><td><?=htmlspecialchars($debt['name'])?></td><td><?=htmlspecialchars($debt['invoice_no'])?></td><td class="low">TZS <?=number_format((float)$debt['balance'],2)?></td><td><?=htmlspecialchars($debt['whatsapp']?:$debt['phone'])?></td><td><?=htmlspecialchars($debt['last_sent_at']?:'Bado')?></td><td><?php if($debtWa): ?><a class="btn small wa-btn" target="_blank" href="<?=st_e($debtWa)?>"><?=st_whatsapp_icon()?> WhatsApp</a><?php else: ?>Hakuna namba<?php endif; ?></td></tr><?php endforeach; ?><?php if(!$debtRows): ?><tr><td colspan="6">Hakuna mteja mwenye deni kwa sasa.</td></tr><?php endif; ?></table></div></div>

<?php elseif($page==='customer_statement'): ?>
<?php
    $statementId=(int)($_GET['id']??0);
    $statementCustomer=null;$statementInvoices=[];
    if($statementId>0){
        $statementQuery=$pdo->prepare('SELECT * FROM customers WHERE id=? LIMIT 1');
        $statementQuery->execute([$statementId]);
        $statementCustomer=$statementQuery->fetch(PDO::FETCH_ASSOC)?:null;
        if($statementCustomer){
            $statementInvoicesQuery=$pdo->prepare('SELECT invoice_no,created_at,total,paid,status FROM invoices WHERE customer_id=? ORDER BY id DESC');
            $statementInvoicesQuery->execute([$statementId]);
            $statementInvoices=$statementInvoicesQuery->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    $statementTotal=0;$statementPaid=0;
    foreach($statementInvoices as $statementInvoice){$statementTotal+=(float)$statementInvoice['total'];$statementPaid+=(float)$statementInvoice['paid'];}
    $statementBalance=max(0,$statementTotal-$statementPaid);
?>
<div class="head"><div><h1>Customer Statement</h1><span class="muted">Invoice, malipo na salio la mteja</span></div><div class="actions no-print"><a class="btn" href="?page=customers">â† Wateja</a><?php if($statementCustomer): ?><button class="btn black" type="button" onclick="window.print()">ðŸ–¨ Print</button><?php endif; ?></div></div>
<?php if(!$statementCustomer): ?><div class="panel"><h3>Mteja hakupatikana</h3><p class="muted">Chagua mteja kutoka kwenye orodha ya Wateja.</p></div><?php else: ?>
<div class="panel statement-head"><h2><?=htmlspecialchars($statementCustomer['name'])?></h2><p><?=htmlspecialchars($statementCustomer['phone']?:'')?> <?=htmlspecialchars($statementCustomer['email']?:'')?></p></div>
<div class="cards"><div class="card"><span class="small">Total invoices</span><b>TZS <?=number_format($statementTotal,2)?></b></div><div class="card"><span class="small">Paid</span><b>TZS <?=number_format($statementPaid,2)?></b></div><div class="card"><span class="small">Balance due</span><b class="low">TZS <?=number_format($statementBalance,2)?></b></div></div>
<div class="panel"><h3>Invoice history</h3><div class="table-wrap"><table class="table"><tr><th>Invoice</th><th>Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th></tr><?php if(!$statementInvoices): ?><tr><td colspan="6">Hakuna invoice kwa mteja huyu.</td></tr><?php else: foreach($statementInvoices as $statementInvoice): ?><tr><td><?=htmlspecialchars($statementInvoice['invoice_no'])?></td><td><?=htmlspecialchars($statementInvoice['created_at']?:'-')?></td><td>TZS <?=number_format((float)$statementInvoice['total'],2)?></td><td>TZS <?=number_format((float)$statementInvoice['paid'],2)?></td><td>TZS <?=number_format(max(0,(float)$statementInvoice['total']-(float)$statementInvoice['paid']),2)?></td><td><?=htmlspecialchars($statementInvoice['status']?:'Haijalipwa')?></td></tr><?php endforeach; endif; ?></table></div></div>
<?php endif; ?>

<?php elseif (
    $page === 'customers'
): ?>

<div class="customer-title-row">
  <div class="customer-title">
    <div class="customer-title-icon">👤</div>
    <div><h2>Wateja</h2><p>Orodha ya wateja wako</p></div>
  </div>
  <a class="btn customer-add" href="?page=add_customer">+ Ongeza Mteja</a>
</div>

<div class="panel customers-modern">
  <div class="table-wrap">
    <table class="table">
      <tr><th>Jina</th><th>Simu</th><th>TIN</th><th>VRN</th><th>VAT</th><th>WhatsApp</th><th>Vitendo</th></tr>
      <?php foreach($customers as $c): ?>
      <?php
        $customerWaMsg='Habari '.($c['name']?:'Mteja').', tunakusalimu kutoka Zantronix. Tunapatikana kwa mawasiliano zaidi kuhusu akaunti yako. Asante.';
        $customerWa=st_whatsapp_link(($c['whatsapp']??'')?:($c['phone']??''),$customerWaMsg);
      ?>
      <tr>
        <td><b><?=htmlspecialchars($c['name'])?></b></td>
        <td><?=htmlspecialchars($c['phone'])?></td>
        <td><?=htmlspecialchars($c['tin'])?></td>
        <td><?=htmlspecialchars($c['vrn'])?></td>
        <td><?=htmlspecialchars($c['vat_status'])?></td>
        <td class="customer-wa-cell">
          <?php if($customerWa): ?><a class="btn small wa-btn customer-wa-btn" target="_blank" rel="noopener" href="<?=st_e($customerWa)?>"><?=st_whatsapp_icon()?> WhatsApp</a><?php else: ?><span class="customer-empty-wa">Hakuna namba</span><?php endif; ?>
        </td>
        <td>
          <a class="btn small" href="?page=customer_statement&id=<?=$c['id']?>">Statement</a>
          <a class="btn small" href="?page=edit_customer&id=<?=$c['id']?>">Hariri</a>
          <form action="?page=delete_customer" method="post" style="display:inline" onsubmit="return confirm('Unataka kumfuta mteja huyu?')">
            <input type="hidden" name="id" value="<?=$c['id']?>"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><button class="btn small black" type="submit">Futa</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>

<div class="panel debt-modern">
  <div class="debt-title">
    <div class="debt-icon">💰</div>
    <div><h2>Customers Who Owe Money</h2><p>Wateja wanaodaiwa</p></div>
  </div>
  <div class="table-wrap">
    <table class="table">
      <tr><th>Customer</th><th>Phone</th><th>TIN</th><th>VRN</th><th>Invoices</th><th>Balance</th><th>WhatsApp</th></tr>
      <?php if(!$owingCustomers): ?>
        <tr><td colspan="7">No outstanding customer balances.</td></tr>
      <?php else: foreach($owingCustomers as $oc): ?>
        <?php
          $oweWaMsg='Habari '.($oc['name']?:'Mteja').', tunakukumbusha kuhusu deni lako la TZS '.number_format((float)$oc['balance'],2).' kwenye kampuni yetu. Tafadhali lipa mapema ili usipoteze uaminifu wetu na tuendelee kufanya biashara vizuri. Asante kwa ushirikiano wako.';
          $oweWa=st_whatsapp_link($oc['phone']??'',$oweWaMsg);
        ?>
        <tr>
          <td><b><?=htmlspecialchars($oc['name'])?></b></td>
          <td><?=htmlspecialchars($oc['phone']?:'-')?></td>
          <td><?=htmlspecialchars($oc['tin']?:'-')?></td>
          <td><?=htmlspecialchars($oc['vrn']?:'-')?></td>
          <td><?=$oc['invoices_count']?></td>
          <td class="debt-balance"><b>TZS <?=number_format((float)$oc['balance'],2)?></b></td>
          <td class="debt-wa-cell">
            <?php if($oweWa): ?><a class="btn wa-btn debt-wa-btn" target="_blank" rel="noopener" href="<?=st_e($oweWa)?>"><?=st_whatsapp_icon()?> Tuma Ujumbe</a><?php else: ?><span class="customer-empty-wa">Hakuna namba</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </table>
  </div>
</div>

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
<input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>">

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
<input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>">

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
<h2>âž• Ongeza Msambazaji</h2>
<form class="form" method="post" action="?page=save_supplier">
<label>Jina la Kampuni / Msambazaji *<input name="company_name" required></label>
<label>Contact Person<input name="contact_person"></label>
<label>Simu<input name="phone"></label>
<label>Email<input type="email" name="email"></label>
<label>TIN<input name="tin"></label>
<label>VRN<input name="vrn"></label>
<label>Anwani<input name="address"></label>
<label class="full">Notes / Maelezo<textarea name="notes"></textarea></label>
<div class="full"><button class="btn">âž• Hifadhi Msambazaji</button></div>
</form>
</div>
<div class="panel"><h2>ðŸšš Orodha ya Wasambazaji</h2><div style="overflow-x:auto"><table class="table">
<tr><th>#</th><th>Msambazaji</th><th>Contact</th><th>Simu</th><th>TIN/VRN</th><th>Address</th><th>Vitendo</th></tr>
<?php foreach($suppliers as $sp): ?><tr>
<td><?=$sp['id']?></td><td><b><?=htmlspecialchars($sp['company_name'])?></b><br><small><?=htmlspecialchars($sp['notes']??'')?></small></td>
<td><?=htmlspecialchars($sp['contact_person']?:'-')?></td><td><?=htmlspecialchars($sp['phone']?:'-')?></td>
<td>TIN: <?=htmlspecialchars($sp['tin']?:'-')?><br>VRN: <?=htmlspecialchars($sp['vrn']?:'-')?></td>
<td><?=htmlspecialchars($sp['address']?:'-')?></td>
<td><a class="btn" href="?page=edit_supplier&id=<?=$sp['id']?>">Hariri</a> <form method="post" action="?page=delete_supplier" style="display:inline" onsubmit="return confirm('Una uhakika unataka kufuta msambazaji huyu?')"><input type="hidden" name="id" value="<?=$sp['id']?>"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><button class="btn black" type="submit">Futa</button></form></td>
</tr><?php endforeach; ?></table></div></div>
<?php elseif ($page === 'edit_supplier'): ?>
<?php $editId=(int)($_GET['id']??0); $eq=$pdo->prepare('SELECT * FROM suppliers WHERE id=?'); $eq->execute([$editId]); $es=$eq->fetch(); if(!$es) exit('Msambazaji hajapatikana.'); ?>
<div class="head"><h1>Hariri Msambazaji</h1><a class="btn black" href="?page=suppliers">Rudi</a></div>
<div class="panel"><form class="form" method="post" action="?page=update_supplier"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><input type="hidden" name="id" value="<?=$es['id']?>">
<label>Jina la Kampuni / Msambazaji *<input name="company_name" value="<?=htmlspecialchars($es['company_name']??'')?>" required></label>
<label>Contact Person<input name="contact_person" value="<?=htmlspecialchars($es['contact_person']??'')?>"></label>
<label>Simu<input name="phone" value="<?=htmlspecialchars($es['phone']??'')?>"></label>
<label>Email<input type="email" name="email" value="<?=htmlspecialchars($es['email']??'')?>"></label>
<label>TIN<input name="tin" value="<?=htmlspecialchars($es['tin']??'')?>"></label>
<label>VRN<input name="vrn" value="<?=htmlspecialchars($es['vrn']??'')?>"></label>
<label>Anwani<input name="address" value="<?=htmlspecialchars($es['address']??'')?>"></label>
<label class="full">Notes / Maelezo<textarea name="notes"><?=htmlspecialchars($es['notes']??'')?></textarea></label>
<div class="full"><button class="btn">ðŸ’¾ Hifadhi Mabadiliko</button></div></form></div>

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

<th>Picha</th>
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

<td><?php if(!empty($p['photo'])):?><img src="<?=htmlspecialchars($p['photo'],ENT_QUOTES)?>" alt="Bidhaa" style="width:42px;height:42px;object-fit:cover;border-radius:7px;border:1px solid #ddd"><?php else:?><span style="font-size:11px;color:#999">-</span><?php endif;?></td>

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

<form method="post" action="?page=delete_product" style="display:inline" onsubmit="return confirm('Unataka kulifuta bidhaa hili?')"><input type="hidden" name="id" value="<?=$p['id']?>"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><button class="btn black" type="submit">Futa</button></form>

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
    enctype="multipart/form-data"
>
<input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>">

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

<label>
Picha ya Bidhaa <span class="muted">(hiari)</span>
<input name="product_photo" type="file" accept="image/jpeg,image/png,image/webp">
<small>Picha itaonekana kwenye Bidhaa & Stock tu; haitachapishwa kwenye invoice.</small>
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
    enctype="multipart/form-data"
>
<input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>">

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

<label>
Picha ya Bidhaa <span class="muted">(hiari)</span>
<input name="product_photo" type="file" accept="image/jpeg,image/png,image/webp">
<?php if(!empty($p['photo'])):?><small>Picha ipo tayari. Chagua nyingine kubadilisha.</small><?php else:?><small>Picha itaonekana kwenye Bidhaa & Stock tu; haitachapishwa kwenye invoice.</small><?php endif;?>
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

â€”

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
Ã—
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
    min="0"
    value="0"
    oninput="hesabu()"
>

<select name="payment_method" aria-label="Njia ya malipo ya awali">
    <option>Cash</option>
    <option>Bank</option>
    <option>Mobile Money</option>
    <option>Cheque</option>
    <option>Other</option>
</select>

<input name="payment_reference" placeholder="Reference ya malipo (optional)">

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
<a class="btn" href="?page=payment&id=<?=$r['id']?>">Malipo</a>
<form method="post" action="?page=delete_invoice" style="display:inline" onsubmit="return confirm('Delete this invoice? Stock will be returned to inventory.')"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><button class="btn black" type="submit">Delete</button></form>

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

â€”

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
Ã—
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

<hr style="margin:25px 0">

<h3>&#128187; Backup ya Business Tracking &amp; Site Tracking</h3>

<div class="backup-buttons">

<a
    class="btn"
    href="?page=settings&action=backup"
    onclick="return confirmBackup()"
>
&#128190; TENGENEZA BACKUP
</a>

</div>

<p class="muted" style="margin-top:10px">
Backup hii inahifadhi database ya systems zote mbili pamoja na folder ya picha (uploads), kwa sababu Business Tracking na Site Tracking zinatumia database moja.
</p>

<hr style="margin:25px 0">

<h3>
&#9851; Restore kutoka Computer
</h3>

<p class="muted">
Chagua backup ya Zantronix yenye <strong>.zip</strong>.
</p>

<div class="backup-warning">

<strong>&#9888; Tahadhari:</strong>

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
&#9851; KUBALI NA RESTORE
</button>

</form>

<?php if (isset($_GET['restored'])): ?>

<div
    class="notice"
    style="margin-top:15px"
>

âœ“ <strong>Restore imekamilika.</strong>

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
<form method="post" action="?page=toggle_user" style="display:inline"><input type="hidden" name="id" value="<?=$u['id']?>"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><button class="btn" type="submit"><?=$u['active'] ? 'Zima' : 'Washa'?></button></form>
<?php endif; ?>
<?php if($u['role']!=='Msimamizi'): ?><form method="post" action="?page=delete_user" style="display:inline" onsubmit="return confirm('Delete this user permanently?')"><input type="hidden" name="id" value="<?=$u['id']?>"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><button class="btn black" type="submit">Delete</button></form><?php endif; ?>

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
<?php if(!empty($_GET['error'])): ?><div class="notice danger"><?=htmlspecialchars($_GET['error'])?></div><?php endif; ?>
<div class="panel"><table class="table"><tr><th>Quotation No.</th><th>Customer</th><th>Date</th><th>Total</th><th>Status</th><th>Actions</th></tr><?php foreach($quotationRows as $q): ?><tr><td><?=htmlspecialchars($q['quotation_no'])?></td><td><?=htmlspecialchars($q['customer']??'-')?></td><td><?=htmlspecialchars($q['created_at'])?></td><td>TZS <?=number_format((float)$q['total'],2)?></td><td><span class="badge <?=!empty($q['converted_invoice_id'])?'badge-paid':''?>"><?=htmlspecialchars($q['status']?:'Draft')?></span></td><td><a class="btn" href="?page=print_quotation&id=<?=$q['id']?>">Print</a> <?php if(!empty($q['converted_invoice_id'])): ?><a class="btn" href="?page=print&id=<?=$q['converted_invoice_id']?>">Invoice</a><?php else: ?><form method="post" action="?page=convert_quotation" style="display:inline" onsubmit="return confirm('Geuza quotation hii kuwa invoice na punguza stock?')"><input type="hidden" name="quotation_id" value="<?=$q['id']?>"><button class="btn" type="submit">â†’ Invoice</button></form><?php endif; ?> <a class="btn black" href="?page=edit_quotation&id=<?=$q['id']?>">Edit</a> <form method="post" action="?page=delete_quotation" style="display:inline" onsubmit="return confirm('Delete this quotation?')"><input type="hidden" name="id" value="<?=$q['id']?>"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><button class="btn black" type="submit">Delete</button></form></td></tr><?php endforeach; ?></table></div>
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
<div class="panel"><table class="table"><tr><th>Proforma No.</th><th>Customer</th><th>Date</th><th>Total</th><th>Actions</th></tr><?php foreach($proformaRows as $q): ?><tr><td><?=htmlspecialchars($q['proforma_no'])?></td><td><?=htmlspecialchars($q['customer']??'-')?></td><td><?=htmlspecialchars($q['created_at'])?></td><td>TZS <?=number_format((float)$q['total'],2)?></td><td><a class="btn" href="?page=print_proforma&id=<?=$q['id']?>">Print</a> <a class="btn black" href="?page=edit_proforma&id=<?=$q['id']?>">Edit</a> <form method="post" action="?page=delete_proforma" style="display:inline" onsubmit="return confirm('Delete this proforma invoice?')"><input type="hidden" name="id" value="<?=$q['id']?>"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><button class="btn black" type="submit">Delete</button></form></td></tr><?php endforeach; ?></table></div>
<?php elseif($page==='new_proforma'): ?>
<div class="head"><h1>New Proforma Invoice</h1><a class="btn black" href="?page=proforma">Back</a></div>
<div class="invoicebox">
<form method="post" enctype="multipart/form-data" action="?page=save_proforma" id="proformaForm">
<label>Customer<select name="customer_id" required><option value="">Select Customer</option><?php foreach($customers as $c): ?><option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option><?php endforeach; ?></select></label>
<div id="productRows">
<div class="invoice-row product-row"><select name="product_id[]" onchange="bei(this)"><?php foreach($products as $p): ?><option value="<?=$p['id']?>" data-price="<?=$p['price']?>"><?=htmlspecialchars($p['name'])?> (Stock: <?=$p['stock']?>)</option><?php endforeach; ?></select><input name="qty[]" type="number" min="1" value="1"><input name="price[]" type="number" step=".01" min="0" value="<?=($products[0]['price']??0)?>"><button type="button" class="btn black qty-minus" onclick="changeQty(this,-1)">âˆ’</button><button type="button" class="btn qty-plus" onclick="changeQty(this,1)">+</button><button type="button" class="btn black" onclick="removeProformaRow(this)">Remove</button></div>
</div>
<button type="button" class="btn" onclick="addProformaRow()">+ Add Product</button>
<div class="labor-box"><b><?=htmlspecialchars(zan_t('Kazi / Labor Charge','Labor Charge'))?></b><div class="labor-grid" style="margin-top:10px"><input name="labor_description" placeholder="<?=htmlspecialchars(zan_t('Maelezo ya kazi / survey / installation','Work / survey / installation description'))?>"><input name="labor_charge" type="number" step=".01" min="0" value="0"></div></div>
<div class="form" style="margin-top:15px"><label>Valid Until<input name="valid_until" type="date" value="<?=date('Y-m-d',strtotime('+30 days'))?>" required></label><label class="full">Notes<textarea name="notes" rows="3" placeholder="<?=htmlspecialchars(zan_t('Maelezo ya ziada kwa mteja','Additional notes for customer'))?>"></textarea></label><label class="full">Terms & Conditions<textarea name="terms" rows="3" placeholder="Mfano: Bei ni halali kwa siku 30. Delivery/installation kama ilivyoelezwa hapo juu."></textarea></label></div>
<div class="totals"><div><span>Subtotal</span><b>TZS (calculated on save)</b></div></div>
<button class="btn" type="submit">Save & Print Proforma Invoice</button>
</form></div>
<template id="proformaProductTemplate"><div class="invoice-row product-row"><select name="product_id[]" onchange="bei(this)"><?php foreach($products as $p): ?><option value="<?=$p['id']?>" data-price="<?=$p['price']?>"><?=htmlspecialchars($p['name'])?> (Stock: <?=$p['stock']?>)</option><?php endforeach; ?></select><input name="qty[]" type="number" min="1" value="1"><input name="price[]" type="number" step=".01" min="0" value="<?=($products[0]['price']??0)?>"><button type="button" class="btn black" onclick="changeQty(this,-1)">âˆ’</button><button type="button" class="btn qty-plus" onclick="changeQty(this,1)">+</button><button type="button" class="btn black" onclick="removeProformaRow(this)">Remove</button></div></template>
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
<div class="invoice-row product-row"><select name="product_id[]" onchange="bei(this)"><?php foreach($products as $p):?><option value="<?=$p['id']?>" data-price="<?=$p['price']?>" <?=$x['product_id']==$p['id']?'selected':''?>><?=htmlspecialchars($p['name'])?> (Stock: <?=$p['stock']?>)</option><?php endforeach;?></select><input name="qty[]" type="number" min="1" value="<?=$x['qty']?>"><input name="price[]" type="number" step=".01" min="0" value="<?=$x['price']?>"><button type="button" class="btn black" onclick="changeQty(this,-1)">âˆ’</button><button type="button" class="btn qty-plus" onclick="changeQty(this,1)">+</button><button type="button" class="btn black" onclick="removeProformaRow(this)">Remove</button></div>
<?php endforeach;?></div>
<button type="button" class="btn" onclick="addProformaRow()">+ Add Product</button>
<div class="labor-box"><b>Labor Charge</b><div class="labor-grid"><input name="labor_description" placeholder="Work description"><input name="labor_charge" type="number" step=".01" min="0" value="0"></div></div>
<div class="form" style="margin-top:15px"><label>Valid Until<input name="valid_until" type="date" value="<?=htmlspecialchars($doc['valid_until']??'')?>" required></label><label class="full">Notes<textarea name="notes" rows="3"><?=htmlspecialchars($doc['notes']??'')?></textarea></label><label class="full">Terms & Conditions<textarea name="terms" rows="3"><?=htmlspecialchars($doc['terms']??'')?></textarea></label></div>
<button class="btn" type="submit">Save Changes</button></form></div>
<template id="proformaProductTemplate"><div class="invoice-row product-row"><select name="product_id[]" onchange="bei(this)"><?php foreach($products as $p):?><option value="<?=$p['id']?>" data-price="<?=$p['price']?>"><?=htmlspecialchars($p['name'])?> (Stock: <?=$p['stock']?>)</option><?php endforeach;?></select><input name="qty[]" type="number" min="1" value="1"><input name="price[]" type="number" step=".01" min="0" value="<?=($products[0]['price']??0)?>"><button type="button" class="btn black" onclick="changeQty(this,-1)">âˆ’</button><button type="button" class="btn qty-plus" onclick="changeQty(this,1)">+</button><button type="button" class="btn black" onclick="removeProformaRow(this)">Remove</button></div></template>
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
<div class="panel"><h2>Expense Report</h2><div style="overflow:auto"><table class="table"><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Paid By</th><th>Created By</th><th class="no-print">Action</th></tr><?php foreach($expenses as $e): ?><tr><td><?=htmlspecialchars($e['expense_date'])?></td><td><?=htmlspecialchars($e['category'])?></td><td><?=htmlspecialchars($e['description'])?></td><td>TZS <?=number_format((float)$e['amount'],2)?></td><td><?=htmlspecialchars($e['paid_by'])?></td><td><?=htmlspecialchars($e['created_by_name']??'-')?></td><td class="no-print"><form method="post" action="?page=delete_expense" style="display:inline" onsubmit="return confirm('Delete this expense?')"><input type="hidden" name="id" value="<?=$e['id']?>"><input type="hidden" name="st_csrf" value="<?=st_e(st_csrf_token())?>"><button class="btn black" type="submit">Delete</button></form></td></tr><?php endforeach; ?></table></div></div>
<?php elseif($page==='search'): $term=trim($_GET['q']??'');$like='%'.$term.'%';$sr=[];if($term!==''){ $q=$pdo->prepare("SELECT 'Customer' type,id,name title,phone details FROM customers WHERE name LIKE ? OR phone LIKE ? OR tin LIKE ? OR vrn LIKE ? OR email LIKE ?");$q->execute([$like,$like,$like,$like,$like]);foreach($q->fetchAll() as $r)$sr[]=$r;$q=$pdo->prepare("SELECT 'Product' type,id,name title,barcode details FROM products WHERE name LIKE ? OR barcode LIKE ? OR sku LIKE ? OR category LIKE ?");$q->execute([$like,$like,$like,$like]);foreach($q->fetchAll() as $r)$sr[]=$r;$q=$pdo->prepare("SELECT 'Supplier' type,id,company_name title,phone details FROM suppliers WHERE company_name LIKE ? OR phone LIKE ? OR tin LIKE ? OR vrn LIKE ?");$q->execute([$like,$like,$like,$like]);foreach($q->fetchAll() as $r)$sr[]=$r;$q=$pdo->prepare("SELECT 'Invoice' type,i.id,i.invoice_no title,c.name details FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id WHERE i.invoice_no LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR c.tin LIKE ?");$q->execute([$like,$like,$like,$like]);foreach($q->fetchAll() as $r)$sr[]=$r;$q=$pdo->prepare("SELECT 'Expense' type,id,category title,description details FROM expenses WHERE category LIKE ? OR description LIKE ? OR paid_by LIKE ?");$q->execute([$like,$like,$like]);foreach($q->fetchAll() as $r)$sr[]=$r;$q=$pdo->prepare("SELECT 'Quotation' type,id,quotation_no title,status details FROM quotations WHERE quotation_no LIKE ? OR status LIKE ?");$q->execute([$like,$like]);foreach($q->fetchAll() as $r)$sr[]=$r;$q=$pdo->prepare("SELECT 'Proforma' type,id,proforma_no title,status details FROM proforma_invoices WHERE proforma_no LIKE ? OR status LIKE ?");$q->execute([$like,$like]);foreach($q->fetchAll() as $r)$sr[]=$r; } ?>
<div class="head"><h1>Search Results</h1></div><div class="panel"><p>Results for: <b><?=htmlspecialchars($term)?></b></p><table class="table"><tr><th>Type</th><th>Title</th><th>Details</th><th>Open</th></tr><?php foreach($sr as $r): ?><tr><td><?=htmlspecialchars($r['type'])?></td><td><?=htmlspecialchars($r['title'])?></td><td><?=htmlspecialchars($r['details']??'-')?></td><td><?php if($r['type']==='Invoice'): ?><a class="btn" href="?page=print&id=<?=$r['id']?>">Open</a><?php elseif($r['type']==='Customer'): ?><a class="btn" href="?page=edit_customer&id=<?=$r['id']?>">Open</a><?php elseif($r['type']==='Product'): ?><a class="btn" href="?page=edit_product&id=<?=$r['id']?>">Open</a><?php elseif($r['type']==='Quotation'): ?><a class="btn" href="?page=print_quotation&id=<?=$r['id']?>">Open</a><?php elseif($r['type']==='Proforma'): ?><a class="btn" href="?page=print_proforma&id=<?=$r['id']?>">Open</a><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; ?><?php if(!$sr): ?><tr><td colspan="4">No results found.</td></tr><?php endif; ?></table></div>

<!-- ======================================================
     REPORTS
     ====================================================== -->
<?php elseif($page==='payment'): ?>
<?php
    $paymentInvoiceId=(int)($_GET['id']??0);
    $paymentQuery=$pdo->prepare('SELECT i.*,c.name customer FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id WHERE i.id=? LIMIT 1');
    $paymentQuery->execute([$paymentInvoiceId]);$paymentInvoice=$paymentQuery->fetch(PDO::FETCH_ASSOC);
    $paymentRows=[];
    if($paymentInvoice){$paymentRowsQuery=$pdo->prepare('SELECT p.*,u.name received_name FROM payments p LEFT JOIN users u ON u.id=p.received_by WHERE p.invoice_id=? ORDER BY p.id DESC');$paymentRowsQuery->execute([$paymentInvoiceId]);$paymentRows=$paymentRowsQuery->fetchAll(PDO::FETCH_ASSOC);}
    $paymentBalance=$paymentInvoice?max(0,(float)$paymentInvoice['total']-(float)$paymentInvoice['paid']):0;
?>
<div class="head"><div><h1>Invoice Payment</h1><span class="muted">Record na historia ya malipo</span></div><a class="btn" href="?page=invoices">â† Invoices</a></div>
<?php if(!$paymentInvoice): ?><div class="panel"><h3>Invoice haipatikani.</h3></div><?php else: ?>
<div class="cards"><div class="card"><span class="small">Invoice</span><b><?=htmlspecialchars($paymentInvoice['invoice_no'])?></b></div><div class="card"><span class="small">Customer</span><b><?=htmlspecialchars($paymentInvoice['customer']?:'Mteja wa kawaida')?></b></div><div class="card"><span class="small">Balance due</span><b class="low">TZS <?=number_format($paymentBalance,2)?></b></div></div>
<?php if(!empty($_GET['error'])): ?><div class="notice danger"><?=htmlspecialchars($_GET['error'])?></div><?php endif; ?>
<?php if(isset($_GET['saved'])): ?><div class="notice">Malipo yamehifadhiwa kikamilifu.</div><?php endif; ?>
<div class="grid2"><div class="panel"><h2>Record payment</h2><?php if($paymentBalance<=0): ?><p class="status">Invoice imelipwa kikamilifu.</p><?php else: ?><form method="post" action="?page=record_payment"><input type="hidden" name="invoice_id" value="<?=$paymentInvoiceId?>"><label>Kiasi <input name="amount" type="number" step=".01" min="0.01" max="<?=htmlspecialchars((string)$paymentBalance)?>" required></label><label>Tarehe <input name="payment_date" type="date" value="<?=date('Y-m-d')?>" required></label><label>Njia ya malipo <select name="payment_method"><option>Cash</option><option>Bank</option><option>Mobile Money</option><option>Cheque</option><option>Other</option></select></label><label>Reference <input name="reference" placeholder="Receipt / transaction number"></label><label>Notes <textarea name="notes" rows="3"></textarea></label><button class="btn" type="submit">Hifadhi Malipo</button></form><?php endif; ?></div><div class="panel"><h2>Payment history</h2><div class="table-wrap"><table class="table"><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Received by</th></tr><?php if(!$paymentRows): ?><tr><td colspan="5">Hakuna payment history.</td></tr><?php else: foreach($paymentRows as $paymentRow): ?><tr><td><?=htmlspecialchars($paymentRow['payment_date'])?></td><td>TZS <?=number_format((float)$paymentRow['amount'],2)?></td><td><?=htmlspecialchars($paymentRow['payment_method'])?></td><td><?=htmlspecialchars($paymentRow['reference']?:'-')?></td><td><?=htmlspecialchars($paymentRow['received_name']?:'-')?></td></tr><?php endforeach; endif; ?></table></div></div></div>
<?php endif; ?>

<?php elseif($page==='reports'): ?>

<div class="head"><div><h1>Ripoti za Biashara</h1><span class="muted">Sales, payments, expenses na net position kwa kipindi ulichochagua</span></div><div class="actions"><a class="btn" href="?page=export_reports&from=<?=urlencode($reportFrom)?>&to=<?=urlencode($reportTo)?>">â¬‡ Export CSV</a><button class="btn" onclick="window.print()">ðŸ–¨ Print Reports</button></div></div>
<div class="panel no-print" style="margin-bottom:18px"><form method="get" class="form"><input type="hidden" name="page" value="reports"><label>From<input type="date" name="from" value="<?=htmlspecialchars($reportFrom)?>" required></label><label>To<input type="date" name="to" value="<?=htmlspecialchars($reportTo)?>" required></label><div class="full actions"><button class="btn" type="submit">Apply period</button><a class="btn black" href="?page=reports">This month</a></div></form></div>

<div class="cards">
<div class="card"><div class="label">SALES</div><div class="big">TZS <?=number_format($reportSales,2)?></div></div>
<div class="card"><div class="label">PAYMENTS RECEIVED</div><div class="big">TZS <?=number_format($reportPaid,2)?></div></div>
<div class="card"><div class="label">OUTSTANDING</div><div class="big low">TZS <?=number_format($reportOutstanding,2)?></div></div>
<div class="card"><div class="label">NET AFTER EXPENSES</div><div class="big">TZS <?=number_format($reportNet,2)?></div></div>
</div>

<div class="panel"><h2>Customers Who Owe</h2><div style="overflow:auto"><table class="table"><tr><th>Customer</th><th>Phone</th><th>TIN</th><th>VRN</th><th>Balance</th><th>WhatsApp</th></tr><?php foreach($owingCustomers as $oc): ?><?php $reportWaMsg='Habari '.($oc['name']?:'Mteja').', tunakukumbusha kuhusu deni lako la TZS '.number_format((float)$oc['balance'],2).' kwenye kampuni yetu. Tafadhali lipa mapema ili usipoteze uaminifu wetu na tuendelee kufanya biashara vizuri. Asante kwa ushirikiano wako.'; $reportWa=st_whatsapp_link($oc['phone']??'',$reportWaMsg); ?><tr><td><?=htmlspecialchars($oc['name'])?></td><td><?=htmlspecialchars($oc['phone']?:'-')?></td><td><?=htmlspecialchars($oc['tin']?:'-')?></td><td><?=htmlspecialchars($oc['vrn']?:'-')?></td><td><b>TZS <?=number_format((float)$oc['balance'],2)?></b></td><td><?php if($reportWa): ?><a class="btn small wa-btn" target="_blank" rel="noopener" href="<?=st_e($reportWa)?>"><?=st_whatsapp_icon()?> WhatsApp</a><?php else: ?>Hakuna namba<?php endif; ?></td></tr><?php endforeach; ?><?php if(!$owingCustomers): ?><tr><td colspan="6">No outstanding balances.</td></tr><?php endif; ?></table></div></div>

<div class="panel"><h2>Expenses</h2><div style="overflow:auto"><table class="table"><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Paid By</th></tr><?php foreach($reportExpensesRows as $e): ?><tr><td><?=htmlspecialchars($e['expense_date'])?></td><td><?=htmlspecialchars($e['category'])?></td><td><?=htmlspecialchars($e['description'])?></td><td>TZS <?=number_format((float)$e['amount'],2)?></td><td><?=htmlspecialchars($e['paid_by']?:'-')?></td></tr><?php endforeach; ?><?php if(!$reportExpensesRows): ?><tr><td colspan="5">No expenses recorded in this period.</td></tr><?php endif; ?></table></div><p><a class="btn" href="?page=expenses">Manage Expenses</a></p></div>

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
  for(const row of rows){const sel=row.querySelector('select[name="product_id[]"]');if(sel&&String(sel.value)===String(p.id)){const q=row.querySelector('input[name="qty[]"]');q.value=parseInt(q.value||'0',10)+1;if(typeof hesabu==='function')hesabu();msg.textContent=p.name+' â€” quantity imeongezwa.';input.value='';input.focus();return;}}
  if(typeof mstari==='function'){mstari();const rows2=document.querySelectorAll('#mistari .invoice-row');const row=rows2[rows2.length-1];const sel=row.querySelector('select[name="product_id[]"]');sel.value=p.id;if(typeof bei==='function')bei(sel);msg.textContent=p.name+' imeongezwa.';input.value='';input.focus();}
 }).catch(()=>msg.textContent='Hitilafu ya kuwasiliana na mfumo.');
 });
})();
<script>
(function () {
    var language = document.documentElement.lang || 'sw';
    var pairs = {
        'Dashboard': 'Dashibodi',
        'Customers': 'Wateja',
        'Products': 'Bidhaa',
        'Products & Stock': 'Bidhaa na Stoo',
        'Suppliers': 'Wasambazaji',
        'New Invoice': 'Tengeneza Ankara',
        'Invoices': 'Ankara',
        'New Quotation': 'Quotation Mpya',
        'Quotations': 'Quotations',
        'New Proforma Invoice': 'Proforma Invoice Mpya',
        'Proforma Invoices': 'Proforma Invoices',
        'Expenses': 'Matumizi',
        'Reports': 'Ripoti',
        'Settings': 'Mipangilio',
        'Users': 'Watumiaji',
        'Activity History': 'Historia ya Shughuli',
        'Login': 'Kuingia',
        'Logout': 'Toka',
        'Username': 'Jina la mtumiaji',
        'Password': 'Nenosiri',
        'Forgot Password': 'Umesahau Nenosiri',
        'Forgot Password?': 'Umesahau Nenosiri?',
        'Search': 'Tafuta',
        'Save': 'Hifadhi',
        'SAVE': 'HIFADHI',
        'Edit': 'Hariri',
        'EDIT': 'HARIRI',
        'Delete': 'Futa',
        'DELETE': 'FUTA',
        'Cancel': 'Ghairi',
        'CANCEL': 'GHAIRI',
        'Close': 'Funga',
        'Submit': 'Tuma',
        'SUBMIT': 'TUMA',
        'Update': 'Sasisha',
        'UPDATE': 'SASISHA',
        'Add': 'Ongeza',
        'Remove': 'Ondoa',
        'Print': 'Chapisha',
        'Download': 'Pakua',
        'Date': 'Tarehe',
        'Amount': 'Kiasi',
        'Quantity': 'Kiasi',
        'Price': 'Bei',
        'Total': 'Jumla',
        'Subtotal': 'Jumla ndogo',
        'Discount': 'Punguzo',
        'Payment': 'Malipo',
        'Payment Details': 'Maelezo ya Malipo',
        'Status': 'Hali',
        'Action': 'Kitendo',
        'Notes': 'Maelezo',
        'Terms & Conditions': 'Masharti',
        'Valid Until': 'Halali Mpaka',
        'Select Customer': 'Chagua Mteja',
        'Select Site': 'Chagua Site',
        'Select Product': 'Chagua Bidhaa',
        'No data found': 'Hakuna data iliyopatikana',
        'No results found': 'Hakuna matokeo yaliyopatikana',
        'Loading...': 'Inapakia...',
        'Error': 'Hitilafu',
        'Stock': 'Stoo',
        'Healthy Stock': 'Stoo Salama',
        'Low Stock': 'Stoo Chini',
        'Out of Stock': 'Imeisha',
        'Paid': 'Imelipwa',
        'Outstanding': 'Bado Kulipwa',
        'Good': 'Nzuri',
        'Damaged': 'Imeharibika',
        'Missing': 'Imepotea',
        'Pending': 'Inasubiri',
        'Approved': 'Imeidhinishwa',
        'Rejected': 'Imekataliwa',
        'Completed': 'Imekamilika',
        'In Progress': 'Inaendelea',
        'Closed': 'Imefungwa',
        'Assigned': 'Imepewa Fundi',
        'Verified': 'Imethibitishwa',
        'Tools': 'Tools',
        'Materials': 'Vifaa',
        'Technicians': 'Mafundi',
        'Technician Portal': 'Portal ya Fundi',
        'Site Photos': 'Picha za Sites',
        'Open Map': 'Fungua Map',
        'Get Location': 'Pata Location',
        'Switch System': 'Badilisha Mfumo',
        'Business System': 'Mfumo wa Biashara',
        'Site Tracking System': 'Mfumo wa Ufuatiliaji wa Sites',
        'Choose System': 'Chagua Mfumo',
        'Customer': 'Mteja',
        'Phone': 'Simu',
        'Email': 'Barua pepe',
        'Address': 'Anwani',
        'Name': 'Jina',
        'Invoice': 'Ankara',
        'Quotation': 'Quotation',
        'Report': 'Ripoti',
        'Photo': 'Picha',
        'GPS': 'GPS',
        'Map': 'Ramani',
        'Created': 'Imeundwa',
        'Received': 'Imepokelewa',
        'Issued': 'Imetolewa',
        'Returned': 'Imerejeshwa',
        'Material Report': 'Ripoti za Vifaa',
        'Administration': 'Usimamizi',
        'Approvals': 'Uidhinishaji'
    };
    var englishToSwahili = pairs;
    var swahiliToEnglish = {};
    Object.keys(pairs).forEach(function (english) {
        swahiliToEnglish[pairs[english]] = english;
    });
    var dictionary = language === 'en' ? swahiliToEnglish : englishToSwahili;
    var sourceWords = Object.keys(dictionary).sort(function (a, b) {
        return b.length - a.length;
    });
    function translate(value) {
        var trimmed = value.trim();
        if (!trimmed) return value;
        if (dictionary[trimmed]) {
            return value.replace(trimmed, dictionary[trimmed]);
        }
        var translated = value;
        sourceWords.forEach(function (source) {
            translated = translated.split(source).join(dictionary[source]);
        });
        return translated;
    }
    document.querySelectorAll('body *').forEach(function (element) {
        element.childNodes.forEach(function (node) {
            if (node.nodeType === 3) node.nodeValue = translate(node.nodeValue);
        });
        ['placeholder', 'title', 'aria-label'].forEach(function (attribute) {
            if (element.hasAttribute(attribute)) {
                element.setAttribute(attribute, translate(element.getAttribute(attribute)));
            }
        });
    });
}());
</script>
<script>
document.addEventListener('DOMContentLoaded',function(){
    var token=<?=json_encode(st_csrf_token())?>;
    document.querySelectorAll('form[method="post"],form[method="POST"]').forEach(function(form){
        if(!form.querySelector('input[name="st_csrf"]')){
            var input=document.createElement('input');
            input.type='hidden';
            input.name='st_csrf';
            input.value=token;
            form.appendChild(input);
        }
    });
});
</script>
</body>

</html>

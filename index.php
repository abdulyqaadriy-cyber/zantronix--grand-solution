<?php
declare(strict_types=1);

/*
 ZAN TRONIX AI — SQLite -> PostgreSQL Migration Engine v3
 SAFETY:
 - DRY_RUN is the default and changes nothing.
 - MIGRATE changes PostgreSQL only; SQLite is never modified.
 - MIGRATE uses one PostgreSQL transaction and rolls back on any error.
 - Keep this file local; never publish it with a database password.
*/

error_reporting(E_ALL);
ini_set('display_errors','1');

const MODE = 'DRY_RUN'; // Deliberately safe by default.
/* A second, explicit acknowledgement is required before a real migration. */
const MIGRATE_CONFIRMATION = '';
/*
 * Set this to true only after the PostgreSQL application's login code has been
 * verified to accept the legacy SHA-256 hashes.  This engine never receives or
 * stores a clear-text password.
 */
const LEGACY_PASSWORDS_COMPATIBLE = false;
/* Approved manual relationship decisions from the legacy data review. */
const LEGACY_TECHNICIAN_USER_SOURCE_MAP = [1 => 7]; // Abdulyqaadriy Nuhu -> legacy user "juma"
const APPROVED_NEW_SITE_CUSTOMERS = ['KITCHEN SPOT'];
const PG_HOST = '127.0.0.1';
const PG_PORT = '5432';
const PG_DB   = 'zan_tronix_ai';
const PG_USER = 'postgres';
const PG_PASS = 'Root@2020'; // Keep the password outside shared copies of this file.
const SQLITE_FILE = __DIR__ . '/db/zantronix.sqlite';

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function qi(string $v): string {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $v)) throw new RuntimeException('Invalid identifier');
    return '"' . $v . '"';
}
function clean(mixed $v): mixed {
    if ($v === null) return null;
    return is_string($v) && trim($v) === '' ? null : $v;
}
function dt(mixed $v): ?string {
    if (!$v) return null;
    return str_replace(' ', 'T', (string)$v);
}
function d(mixed $v): ?string {
    if (!$v) return null;
    $x = substr((string)$v, 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $x) ? $x : null;
}
function uuid(PDO $pg): string { return (string)$pg->query('SELECT gen_random_uuid()')->fetchColumn(); }
function pgTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'public'
          AND table_name = ?
        LIMIT 1
    ");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function sqliteTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM sqlite_master
        WHERE type = 'table'
          AND name = ?
        LIMIT 1
    ");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}
function colExists(PDO $pdo,string $t,string $c): bool {
    $q=$pdo->prepare("SELECT EXISTS(
        SELECT 1 FROM information_schema.columns
        WHERE table_schema='public' AND table_name=? AND column_name=?
    )");
    $q->execute([$t,$c]); return (bool)$q->fetchColumn();
}
function pgExtensionExists(PDO $pdo, string $extension): bool {
    $q=$pdo->prepare('SELECT EXISTS(SELECT 1 FROM pg_extension WHERE extname=?)');
    $q->execute([$extension]); return (bool)$q->fetchColumn();
}
function ensureMigrationInfrastructure(PDO $pg): void {
    /* Called only after beginTransaction(): these changes roll back on failure. */
    if(!colExists($pg,'products','supplier_id')){
        $pg->exec('ALTER TABLE products ADD COLUMN supplier_id UUID REFERENCES suppliers(id) ON DELETE SET NULL');
        $pg->exec('CREATE INDEX IF NOT EXISTS idx_products_supplier_id ON products(supplier_id)');
    }
    $pg->exec("CREATE TABLE IF NOT EXISTS migration_id_map(
        source_table VARCHAR(80) NOT NULL,
        source_id BIGINT NOT NULL,
        target_table VARCHAR(80) NOT NULL,
        target_id UUID NOT NULL,
        created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
        PRIMARY KEY(source_table,source_id,target_table)
    )");
}
function scount(PDO $db,string $t): int { return (int)$db->query('SELECT COUNT(*) FROM '.qi($t))->fetchColumn(); }
function pcount(PDO $db,string $t): int { return (int)$db->query('SELECT COUNT(*) FROM '.qi($t))->fetchColumn(); }

function mapGet(PDO $pg,string $st,int $sid,string $tt): ?string {
    $q=$pg->prepare('SELECT target_id FROM migration_id_map WHERE source_table=? AND source_id=? AND target_table=?');
    $q->execute([$st,$sid,$tt]); $v=$q->fetchColumn(); return $v ?: null;
}
function mapPut(PDO $pg,string $st,int $sid,string $tt,string $tid): void {
    $q=$pg->prepare('INSERT INTO migration_id_map(source_table,source_id,target_table,target_id)
                      VALUES(?,?,?,?)
                      ON CONFLICT(source_table,source_id,target_table)
                      DO UPDATE SET target_id=EXCLUDED.target_id');
    $q->execute([$st,$sid,$tt,$tid]);
}
function mapId(PDO $pg,string $st,mixed $sid,string $tt): ?string {
    if ($sid===null || $sid==='') return null;
    return mapGet($pg,$st,(int)$sid,$tt);
}
function logx(array &$logs,string $stage,string $message,string $status='OK'): void {
    $logs[]=['stage'=>$stage,'message'=>$message,'status'=>$status];
}
function qid(PDO $pg,string $table,string $number,string $org): ?string {
    $q=$pg->prepare("SELECT id FROM ".qi($table)." WHERE organization_id=? AND ".qi($table==='quotations'?'quotation_number':($table==='proforma_invoices'?'proforma_number':'invoice_number'))."=? LIMIT 1");
    $q->execute([$org,$number]); return $q->fetchColumn() ?: null;
}
function qrole(string $r): string { return trim($r) !== '' ? trim($r) : 'Mauzo'; }
function emailFor(array $u): string {
    $e=trim((string)($u['email']??''));
    if(filter_var($e,FILTER_VALIDATE_EMAIL)) return strtolower($e);
    $name=(string)($u['username']??$u['id']);
    $name=preg_replace('/[^a-z0-9._-]+/i','.',$name);
    return strtolower($name).'.local@zantronix.invalid';
}
function qstatus(string $s): string {
    $s=strtolower(trim($s));
    return match(true){
        str_contains($s,'approve')=>'APPROVED',
        str_contains($s,'sent')=>'SENT',
        str_contains($s,'accept')=>'ACCEPTED',
        str_contains($s,'reject')=>'REJECTED',
        str_contains($s,'expire')=>'EXPIRED',
        str_contains($s,'cancel')=>'CANCELLED',
        str_contains($s,'pending')=>'PENDING_APPROVAL',
        default=>'DRAFT'
    };
}
function docstatus(string $s): string {
    $s=strtolower(trim($s));
    return match(true){
        str_contains($s,'cancel')=>'CANCELLED',
        str_contains($s,'archive')=>'ARCHIVED',
        str_contains($s,'active')||str_contains($s,'paid')||str_contains($s,'sent')=>'ACTIVE',
        default=>'DRAFT'
    };
}
function pstatus(string $s): string {
    $s=strtolower(trim($s));
    return match(true){
        str_contains($s,'complete')=>'COMPLETED',
        str_contains($s,'progress')||str_contains($s,'assigned')||str_contains($s,'active')=>'IN_PROGRESS',
        str_contains($s,'hold')=>'ON_HOLD',
        str_contains($s,'cancel')=>'CANCELLED',
        str_contains($s,'plan')=>'PLANNED',
        default=>'DRAFT'
    };
}
function tstatus(string $s): string {
    $s=strtolower(trim($s));
    return match(true){
        str_contains($s,'complete')=>'COMPLETED',
        str_contains($s,'progress')=>'IN_PROGRESS',
        str_contains($s,'cancel')=>'CANCELLED',
        default=>'TODO'
    };
}
function smtype(string $s): string {
    $s=strtolower(trim($s));
    return match(true){
        str_contains($s,'purchase')=>'PURCHASE',
        str_contains($s,'sale')=>'SALE',
        str_contains($s,'return')=>'RETURN',
        str_contains($s,'transfer in')=>'TRANSFER_IN',
        str_contains($s,'transfer out')=>'TRANSFER_OUT',
        default=>'PROJECT_USAGE'
    };
}

$sourceTables=[
 'users','customers','suppliers','products','quotations','quotation_items',
 'proforma_invoices','proforma_items','invoices','invoice_items','expenses','settings',
 'st_technicians','st_sites','st_site_tasks','st_site_technicians','st_daily_updates',
 'st_tools','st_tool_movements','st_material_requests','st_material_movements',
 'st_notifications','st_audit_log'
];
$unsupportedSourceTables=[
 'st_tool_requests','st_materials','st_material_requests','st_material_movements',
 'st_material_funds','st_site_photos','st_site_completion_photos','st_evidence_photos',
 'st_team_change_requests'
];
$reconciledSourceTables=['st_site_admins']; // Test profile already represented by its linked user.

$targetTables=[
 'organizations','roles','users','organization_users','customers','customer_contacts',
 'suppliers','products','product_categories','units','warehouses','inventory',
 'sites','projects','project_tasks','technicians','technician_assignments','site_visits',
 'tools','tool_assignments','material_requests','stock_movements',
 'quotations','quotation_items','proforma_invoices','proforma_items',
 'invoices','invoice_items','payments','expense_categories','expenses',
 'notifications','audit_logs','settings'
];

$logs=[]; $errors=[]; $blockers=[]; $counts=[]; $unsupportedCounts=[]; $src=null; $pg=null; $migrated=false; $orgId=null; $warehouseId=null;

try {
    if(!is_file(SQLITE_FILE)) throw new RuntimeException('SQLite haijapatikana: '.SQLITE_FILE);
    $src=new PDO('sqlite:'.SQLITE_FILE);
    $src->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $src->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);

    if(!extension_loaded('pdo_pgsql')) throw new RuntimeException('pdo_pgsql haijawezeshwa.');
    $pgPass=getenv('ZANTRONIX_PG_PASS') ?: PG_PASS;
    if($pgPass==='') throw new RuntimeException('PostgreSQL password haijawekwa (ZANTRONIX_PG_PASS au PG_PASS).');

    $pg=new PDO('pgsql:host='.PG_HOST.';port='.PG_PORT.';dbname='.PG_DB,PG_USER,$pgPass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);

    foreach($sourceTables as $t){
        if(!sqliteTableExists($src,$t)) throw new RuntimeException('Required SQLite table missing: '.$t);
        $counts[$t]=scount($src,$t);
    }
    foreach($unsupportedSourceTables as $t){
        if(sqliteTableExists($src,$t)) $unsupportedCounts[$t]=scount($src,$t);
    }
    foreach($reconciledSourceTables as $t){
        if(sqliteTableExists($src,$t)) {
            logx($logs,'Reconciled',$t.' = '.scount($src,$t).' test rows; represented by linked users.');
        }
    }
    $integrityChecks=[
        'Sites without an exact customer match'=>"SELECT COUNT(*) FROM st_sites s LEFT JOIN customers c ON lower(trim(c.name))=lower(trim(s.customer_name)) WHERE c.id IS NULL AND lower(trim(s.customer_name)) NOT IN ('kitchen spot')",
        'Technicians without a valid legacy user'=>"SELECT COUNT(*) FROM st_technicians t LEFT JOIN users u ON u.id=t.user_id WHERE (t.user_id IS NULL AND t.id NOT IN (1)) OR (t.user_id IS NOT NULL AND u.id IS NULL)",
        'Quotations with missing customer'=>"SELECT COUNT(*) FROM quotations q LEFT JOIN customers c ON c.id=q.customer_id WHERE c.id IS NULL",
        'Proformas with missing customer'=>"SELECT COUNT(*) FROM proforma_invoices p LEFT JOIN customers c ON c.id=p.customer_id WHERE c.id IS NULL",
        'Invoices with missing customer'=>"SELECT COUNT(*) FROM invoices i LEFT JOIN customers c ON c.id=i.customer_id WHERE c.id IS NULL"
    ];
    foreach($integrityChecks as $label=>$sql){
        $bad=(int)$src->query($sql)->fetchColumn();
        logx($logs,'Foreign-key check',$label.': '.$bad,$bad>0?'ERROR':'OK');
        if($bad>0) $blockers[]=$label.': '.$bad;
    }

    foreach($targetTables as $t) if(!pgTableExists($pg,$t)) $errors[]='Target table missing: '.$t;
    if($errors) throw new RuntimeException(implode("\n",$errors));

    if(!pgExtensionExists($pg,'pgcrypto')) throw new RuntimeException('PostgreSQL extension pgcrypto is required for UUID generation.');
    if(!colExists($pg,'products','supplier_id')) logx($logs,'Schema','MIGRATE will add products.supplier_id inside its transaction.','OK');
    foreach($unsupportedCounts as $t=>$n) {
        logx($logs,'Not handled',$t.' = '.$n.' source rows (will not be migrated).',$n>0?'ERROR':'OK');
        if($n>0) $blockers[]='Unsupported source table has data: '.$t;
    }

    logx($logs,'Preflight','SQLite, PostgreSQL and target schema are ready.');

    if(MODE==='MIGRATE'){
        if(MIGRATE_CONFIRMATION!=='MIGRATE_ZANTRONIX_V4') throw new RuntimeException('MIGRATE_CONFIRMATION is not set.');
        if(!LEGACY_PASSWORDS_COMPATIBLE && $counts['users']>0) throw new RuntimeException('Legacy password compatibility has not been confirmed.');
        if($blockers) throw new RuntimeException(implode("\n",$blockers));
        $pg->beginTransaction();
        ensureMigrationInfrastructure($pg);

        /* 1 — ORGANIZATION */
        $settings=$src->query('SELECT * FROM settings ORDER BY id LIMIT 1')->fetch() ?: [];
        $orgName=$settings['company_name']??'Zan Tronix';
        $q=$pg->prepare('SELECT id FROM organizations WHERE name=? LIMIT 1');
        $q->execute([$orgName]); $orgId=$q->fetchColumn();
        if(!$orgId){
            $orgId=uuid($pg);
            $pg->prepare('INSERT INTO organizations(id,name,legal_name,tax_number,phone,email,address,default_currency_code,timezone)
                          VALUES(?,?,?,?,?,?,?,?,?)')
              ->execute([$orgId,$orgName,$orgName,clean($settings['tin']??null),
                         clean($settings['phone']??null),clean($settings['email']??null),
                         clean($settings['address']??null),clean($settings['currency']??'TZS'),
                         'Africa/Dar_es_Salaam']);
        }
        logx($logs,'Organizations','Ready: '.$orgName);

        /* 2 — ROLES + USERS */
        $roleIds=[];
        foreach($src->query('SELECT DISTINCT role FROM users') as $r){
            $rn=qrole((string)$r['role']);
            $q=$pg->prepare('SELECT id FROM roles WHERE name=?'); $q->execute([$rn]); $rid=$q->fetchColumn();
            if(!$rid){
                $rid=uuid($pg);
                $pg->prepare('INSERT INTO roles(id,name,description) VALUES(?,?,?)')
                   ->execute([$rid,$rn,'Imported from legacy Zan Tronix']);
            }
            $roleIds[$rn]=$rid;
        }
        $ins=$pg->prepare('INSERT INTO users(id,full_name,email,phone,password_hash,role_id,status,created_at)
                           VALUES(?,?,?,?,?,?,?,?)
                           ON CONFLICT(email) DO UPDATE SET full_name=EXCLUDED.full_name,
                           phone=EXCLUDED.phone,role_id=EXCLUDED.role_id,status=EXCLUDED.status
                           RETURNING id');
        foreach($src->query('SELECT * FROM users ORDER BY id') as $u){
            $uid=mapId($pg,'users',(int)$u['id'],'users')??uuid($pg);
            $rid=$roleIds[qrole((string)$u['role'])]??null;
            $status=((int)$u['active']===1)?'ACTIVE':'INACTIVE';
            $ins->execute([$uid,$u['name'],emailFor($u),clean($u['phone']??null),$u['password'],$rid,$status,dt($u['created_at']??null)]);
            $real=$ins->fetchColumn() ?: $uid;
            mapPut($pg,'users',(int)$u['id'],'users',$real);
            $pg->prepare('INSERT INTO organization_users(organization_id,user_id,is_primary)
                          VALUES(?,?,?) ON CONFLICT DO NOTHING')
               ->execute([$orgId,$real,(int)$u['id']===1]);
        }
        logx($logs,'Users','Migrated '.$counts['users'].' users.');

        /* 3 — SUPPLIERS */
        foreach($src->query('SELECT * FROM suppliers ORDER BY id') as $s){
            $sid=mapId($pg,'suppliers',(int)$s['id'],'suppliers')??uuid($pg);
            $supplierCode='SUP-'.str_pad((string)$s['id'],4,'0',STR_PAD_LEFT);
            $q=$pg->prepare('SELECT id FROM suppliers WHERE organization_id=? AND supplier_code=? LIMIT 2');
            $q->execute([$orgId,$supplierCode]); $matches=$q->fetchAll(PDO::FETCH_COLUMN);
            if(count($matches)>1) throw new RuntimeException('Multiple target suppliers have code '.$supplierCode);
            if(count($matches)===1) $sid=$matches[0];
            $pg->prepare('INSERT INTO suppliers
                (id,organization_id,supplier_code,name,contact_person,phone,email,address,tax_number,notes,status,created_at,updated_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(id) DO UPDATE SET
                name=EXCLUDED.name,contact_person=EXCLUDED.contact_person,phone=EXCLUDED.phone,
                email=EXCLUDED.email,address=EXCLUDED.address,tax_number=EXCLUDED.tax_number,
                notes=EXCLUDED.notes,updated_at=EXCLUDED.updated_at
                RETURNING id')
              ->execute([$sid,$orgId,$supplierCode,
                         $s['company_name'],clean($s['contact_person']??null),clean($s['phone']??null),
                         clean($s['email']??null),clean($s['address']??null),clean($s['tin']??null),
                         clean($s['notes']??null),'ACTIVE',dt($s['created_at']??null),
                         dt($s['updated_at']??null)??dt($s['created_at']??null)]);
            $q=$pg->prepare('SELECT id FROM suppliers WHERE organization_id=? AND supplier_code=?');
            $q->execute([$orgId,$supplierCode]); $sid=$q->fetchColumn();
            if(!$sid) throw new RuntimeException('Could not resolve migrated supplier '.$s['id']);
            mapPut($pg,'suppliers',(int)$s['id'],'suppliers',$sid);
        }
        logx($logs,'Suppliers','Migrated '.$counts['suppliers'].' suppliers.');

        /* 4 — CATEGORIES + UNITS + WAREHOUSE + PRODUCTS */
        $catIds=[];
        foreach($src->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND TRIM(category)<>''") as $c){
            $name=trim((string)$c['category']); $q=$pg->prepare('SELECT id FROM product_categories WHERE name=? LIMIT 1');$q->execute([$name]);$cid=$q->fetchColumn();
            if(!$cid){$cid=uuid($pg);$pg->prepare('INSERT INTO product_categories(id,name) VALUES(?,?)')->execute([$cid,$name]);}
            $catIds[$name]=$cid;
        }
        $q=$pg->prepare('SELECT id FROM units WHERE name=? LIMIT 1');$q->execute(['Each']);$unitId=$q->fetchColumn();
        if(!$unitId){$unitId=uuid($pg);$pg->prepare('INSERT INTO units(id,name,symbol) VALUES(?,?,?)')->execute([$unitId,'Each','pcs']);}

        $q=$pg->prepare('SELECT id FROM warehouses WHERE organization_id=? AND name=? LIMIT 1');$q->execute([$orgId,'Main Warehouse']);$warehouseId=$q->fetchColumn();
        if(!$warehouseId){
            $warehouseId=uuid($pg);
            $pg->prepare('INSERT INTO warehouses(id,organization_id,name,location,status) VALUES(?,?,?,?,?)')
               ->execute([$warehouseId,$orgId,'Main Warehouse',clean($settings['address']??null),'ACTIVE']);
        }

        foreach($src->query('SELECT * FROM products ORDER BY id') as $p){
            $pid=mapId($pg,'products',(int)$p['id'],'products')??uuid($pg);
            $sup=mapId($pg,'suppliers',(int)($p['supplier_id']??0),'suppliers');
            $cat=trim((string)($p['category']??''));
            $code='PRD-'.str_pad((string)$p['id'],5,'0',STR_PAD_LEFT);
            $pg->prepare('INSERT INTO products
                (id,organization_id,product_code,sku,name,category_id,unit_id,cost_price,selling_price,reorder_level,status,supplier_id)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(organization_id,product_code) DO UPDATE SET
                name=EXCLUDED.name,sku=EXCLUDED.sku,cost_price=EXCLUDED.cost_price,
                selling_price=EXCLUDED.selling_price,reorder_level=EXCLUDED.reorder_level,
                supplier_id=EXCLUDED.supplier_id')
              ->execute([$pid,$orgId,$code,clean($p['sku']??null),$p['name'],$catIds[$cat]??null,$unitId,
                         (float)($p['cost']??0),(float)($p['price']??0),(float)($p['low_stock']??0),'ACTIVE',$sup]);
            $q=$pg->prepare('SELECT id FROM products WHERE organization_id=? AND product_code=?');$q->execute([$orgId,$code]);$pid=$q->fetchColumn();
            mapPut($pg,'products',(int)$p['id'],'products',$pid);
            $pg->prepare('INSERT INTO inventory(id,warehouse_id,product_id,quantity,minimum_quantity)
                          VALUES(?,?,?,?,?) ON CONFLICT(warehouse_id,product_id)
                          DO UPDATE SET quantity=EXCLUDED.quantity,minimum_quantity=EXCLUDED.minimum_quantity')
               ->execute([uuid($pg),$warehouseId,$pid,(float)($p['stock']??0),(float)($p['low_stock']??0)]);
        }
        logx($logs,'Products','Migrated products, categories and inventory.');

        /* 5 — CUSTOMERS */
        foreach($src->query('SELECT * FROM customers ORDER BY id') as $c){
            $cid=mapId($pg,'customers',(int)$c['id'],'customers')??uuid($pg);
            $code='CUS-'.str_pad((string)$c['id'],4,'0',STR_PAD_LEFT);
            $creator=null;
            $pg->prepare('INSERT INTO customers
                (id,organization_id,customer_code,customer_type,company_name,contact_person,phone,email,address,tax_number,notes,status)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(organization_id,customer_code) DO UPDATE SET
                company_name=EXCLUDED.company_name,phone=EXCLUDED.phone,email=EXCLUDED.email,
                address=EXCLUDED.address,tax_number=EXCLUDED.tax_number')
              ->execute([$cid,$orgId,$code,'INDIVIDUAL',$c['name'],$c['name'],clean($c['phone']??null),
                         clean($c['email']??null),clean($c['address']??null),clean($c['tin']??null),
                         'Legacy VAT status: '.($c['vat_status']??''),'ACTIVE']);
            $q=$pg->prepare('SELECT id FROM customers WHERE organization_id=? AND customer_code=?');$q->execute([$orgId,$code]);$cid=$q->fetchColumn();
            mapPut($pg,'customers',(int)$c['id'],'customers',$cid);
            if(clean($c['whatsapp']??null)){
                $pg->prepare('INSERT INTO customer_contacts(id,customer_id,name,phone,is_primary)
                              VALUES(?,?,?,?,?)')->execute([uuid($pg),$cid,$c['name'],clean($c['whatsapp']),true]);
            }
        }
        logx($logs,'Customers','Migrated '.$counts['customers'].' customers.');

        /* 6 — TECHNICIANS */
        foreach($src->query('SELECT * FROM st_technicians ORDER BY id') as $t){
            $tid=mapId($pg,'st_technicians',(int)$t['id'],'technicians')??uuid($pg);
            $legacyUserId=(int)($t['user_id']??(LEGACY_TECHNICIAN_USER_SOURCE_MAP[(int)$t['id']]??0));
            $uid=mapId($pg,'users',$legacyUserId,'users');
            if(!$uid) throw new RuntimeException('Technician '.$t['id'].' has no approved target user mapping.');
            $em=trim((string)($t['emergency_name']??'')); if(clean($t['emergency_phone']??null)) $em.=($em?' - ':'').$t['emergency_phone'];
            $code=$t['technician_no']??('TECH-'.$t['id']);
            $pg->prepare('INSERT INTO technicians(id,user_id,technician_code,specialization,status,emergency_contact,notes,created_at)
                          VALUES(?,?,?,?,?,?,?,?)
                          ON CONFLICT(technician_code) DO UPDATE SET
                          user_id=EXCLUDED.user_id,specialization=EXCLUDED.specialization,status=EXCLUDED.status')
               ->execute([$tid,$uid,$code,substr((string)($t['specialization']??''),0,100),
                          strtoupper((string)($t['status']??''))==='ACTIVE'?'ACTIVE':'INACTIVE',
                          clean($em),clean($t['address']??null),dt($t['created_at']??null)]);
            $q=$pg->prepare('SELECT id FROM technicians WHERE technician_code=?');$q->execute([$code]);$tid=$q->fetchColumn();
            mapPut($pg,'st_technicians',(int)$t['id'],'technicians',$tid);
        }
        logx($logs,'Technicians','Migrated '.$counts['st_technicians'].' technicians.');

        /* 7 — SITES + PROJECTS */
        foreach($src->query('SELECT * FROM st_sites ORDER BY id') as $s){
            $siteId=mapId($pg,'st_sites',(int)$s['id'],'sites')??uuid($pg);
            $q=$pg->prepare('SELECT id FROM customers WHERE organization_id=? AND (company_name=? OR contact_person=?) LIMIT 1');
            $q->execute([$orgId,$s['customer_name'],$s['customer_name']]);$cid=$q->fetchColumn();
            if(!$cid){
                if(!in_array((string)$s['customer_name'],APPROVED_NEW_SITE_CUSTOMERS,true)) {
                    throw new RuntimeException('Site '.$s['id'].' customer is not approved for automatic creation.');
                }
                $customerCode='CUS-SITE-'.str_pad((string)$s['id'],4,'0',STR_PAD_LEFT);
                $q=$pg->prepare('SELECT id FROM customers WHERE organization_id=? AND customer_code=?');
                $q->execute([$orgId,$customerCode]); $cid=$q->fetchColumn();
                if(!$cid){
                    $cid=uuid($pg);
                    $pg->prepare('INSERT INTO customers(id,organization_id,customer_code,customer_type,company_name,contact_person,status,notes)
                                  VALUES(?,?,?,?,?,?,?,?)')
                       ->execute([$cid,$orgId,$customerCode,'COMPANY',$s['customer_name'],null,'ACTIVE',
                                  'Created during migration for legacy site '.$s['site_name']]);
                }
            }

            $pg->prepare('INSERT INTO sites(id,customer_id,site_code,site_name,address,latitude,longitude,notes,created_at)
                          VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT(customer_id,site_code) DO UPDATE SET
                          site_name=EXCLUDED.site_name,address=EXCLUDED.address,latitude=EXCLUDED.latitude,
                          longitude=EXCLUDED.longitude,notes=EXCLUDED.notes
                          RETURNING id')
               ->execute([$siteId,$cid,'SITE-'.str_pad((string)$s['id'],4,'0',STR_PAD_LEFT),$s['site_name'],
                          clean($s['location']??null),clean($s['completion_latitude']??null),
                          clean($s['completion_longitude']??null),clean($s['notes']??null),dt($s['created_at']??null)]);
            $q=$pg->prepare('SELECT id FROM sites WHERE customer_id=? AND site_code=?');
            $q->execute([$cid,'SITE-'.str_pad((string)$s['id'],4,'0',STR_PAD_LEFT)]); $siteId=$q->fetchColumn();
            if(!$siteId) throw new RuntimeException('Could not resolve migrated site '.$s['id']);
            mapPut($pg,'st_sites',(int)$s['id'],'sites',$siteId);

            $projectCode='PROJ-'.str_pad((string)$s['id'],4,'0',STR_PAD_LEFT);
            $pg->prepare('INSERT INTO projects
                (id,organization_id,project_code,customer_id,site_id,project_name,description,project_type,start_date,expected_end_date,status,priority,created_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(organization_id,project_code) DO UPDATE SET
                customer_id=EXCLUDED.customer_id,site_id=EXCLUDED.site_id,project_name=EXCLUDED.project_name')
              ->execute([uuid($pg),$orgId,$projectCode,$cid,$siteId,$s['site_name'],
                         clean($s['completion_notes']??null),'SITE',d($s['start_date']??null),d($s['due_date']??null),
                         pstatus((string)$s['status']),'NORMAL',dt($s['created_at']??null)]);
            $q=$pg->prepare('SELECT id FROM projects WHERE organization_id=? AND project_code=?');$q->execute([$orgId,$projectCode]);$projectId=$q->fetchColumn();
            mapPut($pg,'st_sites',(int)$s['id'],'projects',$projectId);
        }
        logx($logs,'Sites/Projects','Migrated '.$counts['st_sites'].' sites and projects.');

        /* 8 — TASKS, ASSIGNMENTS, DAILY UPDATES */
        foreach($src->query('SELECT * FROM st_site_tasks ORDER BY id') as $x){
            $project=mapId($pg,'st_sites',(int)$x['site_id'],'projects'); if(!$project) continue;
            $tech=mapId($pg,'st_technicians',(int)($x['assigned_to']??0),'technicians');
            $id=uuid($pg);
            $pg->prepare('INSERT INTO project_tasks(id,project_id,task_code,title,description,assigned_to,status,completed_at,created_at)
                          VALUES(?,?,?,?,?,?,?,?,?)')
               ->execute([$id,$project,'TASK-'.str_pad((string)$x['id'],5,'0',STR_PAD_LEFT),$x['task_title'],
                          clean($x['notes']??null),$tech,tstatus((string)$x['status']),dt($x['completed_at']??null),dt($x['created_at']??null)]);
            mapPut($pg,'st_site_tasks',(int)$x['id'],'project_tasks',$id);
        }
        foreach($src->query('SELECT * FROM st_site_technicians ORDER BY id') as $x){
            $project=mapId($pg,'st_sites',(int)$x['site_id'],'projects');
            $tech=mapId($pg,'st_technicians',(int)$x['technician_id'],'technicians');
            if(!$project||!$tech) continue;
            $id=uuid($pg);
            $pg->prepare('INSERT INTO technician_assignments(id,project_id,technician_id,status,created_at)
                          VALUES(?,?,?,?,?)')->execute([$id,$project,$tech,'ACTIVE',dt($x['created_at']??null)]);
            mapPut($pg,'st_site_technicians',(int)$x['id'],'technician_assignments',$id);
        }
        foreach($src->query('SELECT * FROM st_daily_updates ORDER BY id') as $x){
            $project=mapId($pg,'st_sites',(int)$x['site_id'],'projects');
            $tech=mapId($pg,'st_technicians',(int)$x['technician_id'],'technicians');
            if(!$project) continue;
            $notes=trim(($x['work_done']??'')."\nBlockers: ".($x['blockers']??'')."\nNext steps: ".($x['next_steps']??''));
            $id=uuid($pg);
            $pg->prepare('INSERT INTO site_visits(id,project_id,technician_id,visit_date,latitude,longitude,purpose,notes,status,check_in_photo,created_at)
                          VALUES(?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([$id,$project,$tech,d($x['update_date']??null)??date('Y-m-d'),
                          clean($x['latitude']??null),clean($x['longitude']??null),'Legacy daily update',$notes,
                          'CLOSED',clean($x['photo']??null),dt($x['created_at']??null)]);
            mapPut($pg,'st_daily_updates',(int)$x['id'],'site_visits',$id);
        }
        logx($logs,'Field Service','Migrated tasks, assignments and daily updates.');

        /* 9 — TOOLS + MOVEMENTS */
        foreach($src->query('SELECT * FROM st_tools ORDER BY id') as $x){
            $id=mapId($pg,'st_tools',(int)$x['id'],'tools')??uuid($pg);
            $code=$x['tool_code']??('TOOL-'.$x['id']);
            $pg->prepare('INSERT INTO tools
                (id,organization_id,tool_code,name,serial_number,purchase_date,purchase_price,condition,status,location,description,created_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(organization_id,tool_code) DO UPDATE SET
                name=EXCLUDED.name,serial_number=EXCLUDED.serial_number,status=EXCLUDED.status')
              ->execute([$id,$orgId,$code,$x['tool_name'],clean($x['serial_no']??null),d($x['purchase_date']??null),
                         clean($x['value_amount']??null),strtoupper((string)($x['condition_status']??'GOOD')),
                         strtoupper((string)($x['status']??'AVAILABLE')),clean($x['storage_location']??null),
                         clean($x['notes']??null),dt($x['created_at']??null)]);
            $q=$pg->prepare('SELECT id FROM tools WHERE organization_id=? AND tool_code=?');$q->execute([$orgId,$code]);$id=$q->fetchColumn();
            mapPut($pg,'st_tools',(int)$x['id'],'tools',$id);
        }
        foreach($src->query('SELECT * FROM st_tool_movements ORDER BY id') as $x){
            $tool=mapId($pg,'st_tools',(int)$x['tool_id'],'tools');
            $tech=mapId($pg,'st_technicians',(int)($x['technician_id']??0),'technicians');
            $project=mapId($pg,'st_sites',(int)($x['site_id']??0),'projects');
            if(!$tool) continue;
            $status=strtoupper((string)($x['status']??'')); $status=$status==='COMPLETED'?'RETURNED':($status==='REJECTED'?'REJECTED':'ASSIGNED');
            $id=uuid($pg);
            $pg->prepare('INSERT INTO tool_assignments
                (id,tool_id,technician_id,project_id,assigned_at,expected_return_date,returned_at,condition_before,condition_after,status,notes)
                VALUES(?,?,?,?,?,?,?,?,?,?,?)')
               ->execute([$id,$tool,$tech,$project,dt($x['issued_at']??$x['created_at']??null),d($x['due_date']??null),
                          dt($x['returned_at']??null),clean($x['condition_status']??null),clean($x['return_condition']??null),
                          $status,clean($x['notes']??null)]);
            mapPut($pg,'st_tool_movements',(int)$x['id'],'tool_assignments',$id);
        }
        logx($logs,'Tools','Migrated tools and tool movement history.');

        /* 10 — MATERIALS */
        foreach($src->query('SELECT * FROM st_material_requests ORDER BY id') as $x){
            $project=mapId($pg,'st_sites',(int)$x['site_id'],'projects');
            $user=mapId($pg,'st_technicians',(int)$x['technician_id'],'technicians');
            $id=uuid($pg);
            $pg->prepare('INSERT INTO material_requests(id,request_number,project_id,status,request_date,notes)
                          VALUES(?,?,?,?,?,?)')
               ->execute([$id,'MR-'.str_pad((string)$x['id'],5,'0',STR_PAD_LEFT),$project,
                          strtoupper((string)($x['status']??'PENDING')),dt($x['created_at']??null),clean($x['reason']??null)]);
            mapPut($pg,'st_material_requests',(int)$x['id'],'material_requests',$id);
        }
        foreach($src->query('SELECT * FROM st_material_movements ORDER BY id') as $x){
            $prod=mapId($pg,'products',(int)$x['material_id'],'products'); if(!$prod) continue;
            $id=uuid($pg);
            $pg->prepare('INSERT INTO stock_movements
                (id,product_id,warehouse_id,movement_type,quantity,reference_type,performed_by,notes,created_at)
                VALUES(?,?,?,?,?,?,?,?,?)')
               ->execute([$id,$prod,$warehouseId,smtype((string)($x['movement_type']??'')),(float)$x['quantity'],
                          'legacy_material_movement',null,clean($x['reason']??null),dt($x['created_at']??null)]);
            mapPut($pg,'st_material_movements',(int)$x['id'],'stock_movements',$id);
        }
        logx($logs,'Materials','Migrated existing material requests/movements.');

        /* 11 — QUOTATIONS + ITEMS */
        foreach($src->query('SELECT * FROM quotations ORDER BY id') as $x){
            $id=mapId($pg,'quotations',(int)$x['id'],'quotations')??uuid($pg);
            $customer=mapId($pg,'customers',(int)$x['customer_id'],'customers');
            $user=mapId($pg,'users',(int)($x['prepared_by']??0),'users');
            $num=$x['quotation_no'];
            $pg->prepare('INSERT INTO quotations
                (id,organization_id,quotation_number,customer_id,quotation_date,valid_until,currency_code,subtotal,tax_amount,total,status,notes,terms_conditions,created_by,created_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(organization_id,quotation_number) DO UPDATE SET
                customer_id=EXCLUDED.customer_id,subtotal=EXCLUDED.subtotal,tax_amount=EXCLUDED.tax_amount,total=EXCLUDED.total,status=EXCLUDED.status')
               ->execute([$id,$orgId,$num,$customer,d($x['created_at'])??date('Y-m-d'),d($x['valid_until']??null),'TZS',
                          (float)$x['subtotal'],(float)$x['vat'],(float)$x['total'],qstatus((string)$x['status']),
                          clean($x['notes']??null),clean($x['terms']??null),$user,dt($x['created_at']??null)]);
            $real=qid($pg,'quotations',$num,$orgId); mapPut($pg,'quotations',(int)$x['id'],'quotations',$real);
        }
        foreach($src->query('SELECT * FROM quotation_items ORDER BY id') as $x){
            $qid=mapId($pg,'quotations',(int)$x['quotation_id'],'quotations'); if(!$qid) continue;
            $prod=mapId($pg,'products',(int)($x['product_id']??0),'products');
            $id=uuid($pg);
            $pg->prepare('INSERT INTO quotation_items(id,quotation_id,product_id,description,quantity,unit_id,unit_price,line_total,sort_order)
                          VALUES(?,?,?,?,?,?,?,?,?)')
               ->execute([$id,$qid,$prod,$x['description']??'Item',(float)$x['qty'],$unitId,(float)$x['price'],(float)$x['subtotal'],(int)$x['id']]);
            mapPut($pg,'quotation_items',(int)$x['id'],'quotation_items',$id);
        }
        logx($logs,'Quotations','Migrated quotations and items.');

        /* 12 — PROFORMAS */
        foreach($src->query('SELECT * FROM proforma_invoices ORDER BY id') as $x){
            $id=mapId($pg,'proforma_invoices',(int)$x['id'],'proforma_invoices')??uuid($pg);
            $customer=mapId($pg,'customers',(int)$x['customer_id'],'customers');
            $user=mapId($pg,'users',(int)($x['prepared_by']??0),'users');
            $num=$x['proforma_no'];
            $pg->prepare('INSERT INTO proforma_invoices
                (id,organization_id,proforma_number,customer_id,issue_date,due_date,currency_code,subtotal,tax_amount,total,status,notes,terms_conditions,created_by,created_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(organization_id,proforma_number) DO UPDATE SET
                customer_id=EXCLUDED.customer_id,total=EXCLUDED.total,status=EXCLUDED.status')
               ->execute([$id,$orgId,$num,$customer,d($x['created_at'])??date('Y-m-d'),d($x['valid_until']??null),'TZS',
                          (float)$x['subtotal'],(float)$x['vat'],(float)$x['total'],docstatus((string)$x['status']),
                          clean($x['notes']??null),clean($x['terms']??null),$user,dt($x['created_at']??null)]);
            $real=qid($pg,'proforma_invoices',$num,$orgId); mapPut($pg,'proforma_invoices',(int)$x['id'],'proforma_invoices',$real);
        }
        foreach($src->query('SELECT * FROM proforma_items ORDER BY id') as $x){
            $pid=mapId($pg,'proforma_invoices',(int)$x['proforma_id'],'proforma_invoices'); if(!$pid) continue;
            $prod=mapId($pg,'products',(int)($x['product_id']??0),'products');
            $id=uuid($pg);
            $pg->prepare('INSERT INTO proforma_items(id,proforma_invoice_id,product_id,description,quantity,unit_id,unit_price,line_total,sort_order)
                          VALUES(?,?,?,?,?,?,?,?,?)')
               ->execute([$id,$pid,$prod,$x['description']??'Item',(float)$x['qty'],$unitId,(float)$x['price'],(float)$x['subtotal'],(int)$x['id']]);
            mapPut($pg,'proforma_items',(int)$x['id'],'proforma_items',$id);
        }
        logx($logs,'Proformas','Migrated proformas and items.');

        /* 13 — INVOICES + AGGREGATE PAYMENTS */
        foreach($src->query('SELECT * FROM invoices ORDER BY id') as $x){
            $id=mapId($pg,'invoices',(int)$x['id'],'invoices')??uuid($pg);
            $customer=mapId($pg,'customers',(int)$x['customer_id'],'customers');
            $user=mapId($pg,'users',(int)($x['prepared_by']??0),'users');
            $num=$x['invoice_no']; $total=(float)($x['grand_total']??$x['total']??0); $paid=(float)($x['paid']??0);
            $status=$paid>0 && $paid+0.001<$total?'PARTIAL':($paid>0 && $paid+0.001>=$total?'PAID':'UNPAID');
            $pg->prepare('INSERT INTO invoices
                (id,organization_id,invoice_number,customer_id,issue_date,currency_code,subtotal,tax_amount,total,amount_paid,balance_due,status,created_by,created_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(organization_id,invoice_number) DO UPDATE SET
                amount_paid=EXCLUDED.amount_paid,balance_due=EXCLUDED.balance_due,status=EXCLUDED.status')
               ->execute([$id,$orgId,$num,$customer,d($x['created_at'])??date('Y-m-d'),'TZS',
                          (float)($x['subtotal']??0),(float)($x['vat']??0),$total,$paid,max(0,$total-$paid),$status,$user,dt($x['created_at']??null)]);
            $real=qid($pg,'invoices',$num,$orgId); mapPut($pg,'invoices',(int)$x['id'],'invoices',$real);
            if($paid>0){
                $pg->prepare('INSERT INTO payments
                    (id,payment_number,invoice_id,customer_id,payment_date,amount,currency_code,payment_method,received_by,notes)
                    VALUES(?,?,?,?,?,?,?,?,?,?)
                    ON CONFLICT(payment_number) DO NOTHING')
                   ->execute([uuid($pg),'PAY-'.$num,$real,$customer,d($x['created_at'])??date('Y-m-d'),$paid,'TZS','OTHER',$user,
                              'Imported aggregate paid amount from legacy invoice']);
            }
        }
        foreach($src->query('SELECT * FROM invoice_items ORDER BY id') as $x){
            $iid=mapId($pg,'invoices',(int)$x['invoice_id'],'invoices'); if(!$iid) continue;
            $prod=mapId($pg,'products',(int)($x['product_id']??0),'products');
            $id=uuid($pg);
            $pg->prepare('INSERT INTO invoice_items(id,invoice_id,product_id,description,quantity,unit_id,unit_price,line_total,sort_order)
                          VALUES(?,?,?,?,?,?,?,?,?)')
               ->execute([$id,$iid,$prod,$x['description']??'Item',(float)$x['qty'],$unitId,(float)$x['price'],(float)$x['subtotal'],(int)$x['id']]);
            mapPut($pg,'invoice_items',(int)$x['id'],'invoice_items',$id);
        }
        logx($logs,'Invoices','Migrated invoices, items and aggregate payments.');

        /* 14 — EXPENSES */
        foreach($src->query('SELECT DISTINCT category FROM expenses') as $x){
            $name=trim((string)$x['category']); if($name==='') continue;
            $pg->prepare('INSERT INTO expense_categories(id,name) VALUES(?,?) ON CONFLICT(name) DO NOTHING')
               ->execute([uuid($pg),$name]);
        }
        foreach($src->query('SELECT * FROM expenses ORDER BY id') as $x){
            $q=$pg->prepare('SELECT id FROM expense_categories WHERE name=?');$q->execute([$x['category']]);$cat=$q->fetchColumn();
            $uid=mapId($pg,'users',(int)($x['created_by']??0),'users');
            $id=uuid($pg);
            $desc=trim(($x['description']??'')."\nPaid by: ".($x['paid_by']??'')."\nNotes: ".($x['notes']??''));
            $pg->prepare('INSERT INTO expenses
                (id,expense_number,category_id,amount,currency_code,expense_date,description,paid_by,status,created_at)
                VALUES(?,?,?,?,?,?,?,?,?,?)')
               ->execute([$id,'EXP-'.str_pad((string)$x['id'],5,'0',STR_PAD_LEFT),$cat,(float)$x['amount'],'TZS',
                          d($x['expense_date'])??date('Y-m-d'),$desc,$uid,'POSTED',dt($x['created_at']??null)]);
            mapPut($pg,'expenses',(int)$x['id'],'expenses',$id);
        }
        logx($logs,'Expenses','Migrated '.$counts['expenses'].' expenses.');

        /* 15 — NOTIFICATIONS */
        foreach($src->query('SELECT * FROM st_notifications ORDER BY id') as $x){
            $uid=mapId($pg,'users',(int)($x['recipient_user_id']??0),'users');
            if(!$uid) $uid=$pg->query('SELECT id FROM users ORDER BY created_at LIMIT 1')->fetchColumn();
            $id=uuid($pg);
            $pg->prepare('INSERT INTO notifications(id,user_id,title,message,notification_type,is_read,created_at)
                          VALUES(?,?,?,?,?,?,?)')
               ->execute([$id,$uid,$x['notification_type']??'Legacy notification',$x['message'],
                          $x['notification_type']??'LEGACY',strtolower((string)($x['status']??''))==='sent',dt($x['created_at']??null)]);
            mapPut($pg,'st_notifications',(int)$x['id'],'notifications',$id);
        }
        logx($logs,'Notifications','Migrated '.$counts['st_notifications'].' notifications.');

        /* 16 — AUDIT LOG */
        foreach($src->query('SELECT * FROM st_audit_log ORDER BY id') as $x){
            $uid=null; $who=trim((string)($x['performed_by']??''));
            if($who!==''){
                $q=$pg->prepare('SELECT id FROM users WHERE full_name=? OR email=? LIMIT 1');$q->execute([$who,$who]);$uid=$q->fetchColumn();
            }
            $id=uuid($pg);
            $new=json_encode(['legacy_entity_id'=>$x['entity_id']??null,'details'=>$x['details']??null],JSON_UNESCAPED_UNICODE);
            $pg->prepare('INSERT INTO audit_logs
                (id,organization_id,user_id,action,entity_type,old_values,new_values,created_at)
                VALUES(?,?,?,?,?,?,?,?)')
               ->execute([$id,$orgId,$uid,$x['action']??'LEGACY',$x['entity_type']??'legacy',null,$new,dt($x['created_at']??null)]);
            mapPut($pg,'st_audit_log',(int)$x['id'],'audit_logs',$id);
        }
        logx($logs,'Audit','Migrated '.$counts['st_audit_log'].' audit records.');

        /* 17 — COMPANY SETTINGS */
        if($settings){
            $pg->prepare('INSERT INTO settings(id,organization_id,key_name,value_text,updated_at)
                          VALUES(?,?,?,?,NOW())
                          ON CONFLICT(organization_id,key_name)
                          DO UPDATE SET value_text=EXCLUDED.value_text,updated_at=NOW()')
               ->execute([uuid($pg),$orgId,'company_profile',json_encode($settings,JSON_UNESCAPED_UNICODE)]);
        }

        $pg->commit();
        $migrated=true;
        logx($logs,'COMMIT','Migration committed successfully.');
    } else {
        foreach($counts as $t=>$n) logx($logs,'DRY_RUN',$t.' = '.$n.' source rows');
    }

} catch(Throwable $e) {
    if($pg && $pg->inTransaction()) $pg->rollBack();
    $errors[]=$e->getMessage();
    logx($logs,'ROLLBACK',$e->getMessage(),'ERROR');
}
?>
<!doctype html>
<html lang="sw">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Zan Tronix AI — Migration Engine v3</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:24px;color:#17202a}
.wrap{max-width:1150px;margin:auto}.card{background:#fff;border-radius:12px;padding:20px;margin-bottom:18px;box-shadow:0 2px 10px rgba(0,0,0,.06)}
.badge{display:inline-block;padding:5px 10px;border-radius:20px;font-weight:bold;font-size:12px}.OK{background:#dff6e5;color:#146c2e}.ERROR{background:#ffdede;color:#9b1c1c}
.notice{background:#eef5ff;padding:14px;border-radius:10px}.success{background:#ecfff1;padding:14px;border-radius:10px}.danger{background:#fff0f0;padding:14px;border-radius:10px}
table{width:100%;border-collapse:collapse}th,td{padding:9px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}pre{white-space:pre-wrap;background:#f7f7f7;padding:12px;border-radius:8px}
</style>
</head>
<body><div class="wrap">
<div class="card"><h1>🚀 Zan Tronix AI — Migration Engine v3</h1>
<p><b>Mode:</b> <span class="badge <?=h(MODE)?>"><?=h(MODE)?></span></p>
<p>DRY_RUN haisogezi data. MIGRATE hutumia PostgreSQL transaction; error yoyote husababisha ROLLBACK. SQLite haibadilishwi.</p></div>

<?php if($migrated): ?>
<div class="card success"><h2>✅ MIGRATION IMEKAMILIKA</h2><p>Transaction ime-commit bila error.</p></div>
<?php elseif($errors): ?>
<div class="card danger"><h2>❌ MIGRATION IMESITISHWA</h2><p>Transaction ime-rollback; hakuna mabadiliko ya migration hii yaliyocommitwa.</p><pre><?=h(implode("\n",$errors))?></pre></div>
<?php elseif($blockers): ?>
<div class="card danger"><h2>DRY_RUN IMEZUIWA</h2><p>Hakuna data iliyobadilishwa. Rekebisha haya kabla ya MIGRATE.</p><pre><?=h(implode("\n",$blockers))?></pre></div>
<?php elseif(MODE==='DRY_RUN'): ?>
<div class="card notice"><h2>🔎 DRY_RUN IMEKAMILIKA</h2><p>Hakuna data iliyobadilishwa. Hii ndiyo hali salama ya kwanza.</p></div>
<?php endif; ?>

<div class="card"><h2>Hatua</h2><table><tr><th>Stage</th><th>Status</th><th>Maelezo</th></tr>
<?php foreach($logs as $l): ?><tr><td><?=h($l['stage'])?></td><td><span class="badge <?=h($l['status'])?>"><?=h($l['status'])?></span></td><td><?=h($l['message'])?></td></tr><?php endforeach; ?>
</table></div>

<div class="card"><h2>SQLite source counts</h2><table><tr><th>Table</th><th>Rows</th></tr>
<?php foreach($counts as $t=>$n): ?><tr><td><?=h($t)?></td><td><?=h($n)?></td></tr><?php endforeach; ?>
</table></div>

<div class="card"><h2>⚠️ Muhimu</h2>
<ul>
<li>Usifute <code>db/zantronix.sqlite</code>.</li>
<li>Usiweke <code>MIGRATE</code> bado mpaka DRY_RUN ya engine hii ikaguliwe.</li>
<li>Legacy product items ambazo product ID yake haipo kwenye products table zitaendelea kama line item yenye <code>product_id = NULL</code>, hivyo invoice/quotation haitakwama.</li>
<li>Malipo ya zamani yanaingizwa kama aggregate payment kwa invoice kwa sababu SQLite ya zamani haina payment table tofauti.</li>
</ul></div>
</div></body></html>

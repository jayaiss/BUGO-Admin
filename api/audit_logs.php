<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Manila');

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}

require_once __DIR__ . '/../include/connection.php';
require_once __DIR__ . '/../include/encryption.php';
require_once __DIR__ . '/../include/redirects.php';
$mysqli = db_connection();

/* -----------------------------
   Helpers
----------------------------- */
function transform_logs_filename($logs_name) {
    switch ((int)$logs_name) {
        case 1: return 'EMPLOYEE'; case 2: return 'RESIDENTS'; case 3: return 'APPOINTMENTS';
        case 4: return 'CEDULA'; case 5: return 'CASES'; case 6: return 'ARCHIVE';
        case 7: return 'LOGIN'; case 8: return 'LOGOUT'; case 9: return 'URGENT REQUEST';
        case 10: return 'URGENT CEDULA'; case 11: return 'EVENTS'; case 12: return 'BARANGAY OFFICIALS';
        case 13: return 'BARANGAY INFO'; case 14: return 'BARANGAY LOGO'; case 15: return 'BARANGAY CERTIFICATES';
        case 16: return 'BARANGAY CERTIFICATES PURPOSES'; case 17: return 'ZONE LEADERS'; case 18: return 'ZONE';
        case 19: return 'GUIDELINES'; case 20: return 'FEEDBACKS'; case 21: return 'TIME SLOT';
        case 22: return 'HOLIDAY'; case 23: return 'ARCHIVED RESIDENTS'; case 24: return 'ARCHIVED EMPLOYEE';
        case 25: return 'ARCHIVED APPOINTMENTS'; case 26: return 'ARCHIVED EVENTS'; case 27: return 'ARCHIVED FEEDBACKS';
        case 28: return 'BESO LIST'; case 29: return 'ANNOUNCEMENTS'; case 30: return 'EMPLOYEE FORGOT PASSWORD';
        default: return '';
    }
}
function transform_action_made($action_made) {
    switch ((int)$action_made) {
        case 1: return 'ARCHIVED'; case 2: return 'EDITED'; case 3: return 'ADDED'; case 4: return 'VIEWED';
        case 5: return 'RESTORED'; case 6: return 'LOGIN'; case 7: return 'LOGOUT'; case 8: return 'UPDATE_STATUS';
        case 9: return 'BATCH_ADD'; case 10: return 'URGENT_REQUEST'; case 11: return 'PRINT'; default: return '';
    }
}
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function selected_attr($cur,$val){ return ((string)$cur===(string)$val && $val!=='')?' selected':''; }

/* -----------------------------
   Pagination + Filters setup
----------------------------- */
$limit=20;
$page=isset($_GET['pagenum'])&&is_numeric($_GET['pagenum'])&& (int)$_GET['pagenum']>0?(int)$_GET['pagenum']:1;
$offset=($page-1)*$limit;

$logsFilter=isset($_GET['logs_name'])&&is_numeric($_GET['logs_name'])?(int)$_GET['logs_name']:'';
$actionFilter=isset($_GET['action_made'])&&is_numeric($_GET['action_made'])?(int)$_GET['action_made']:'';
$roleFilter=isset($_GET['role_id'])&&is_numeric($_GET['role_id'])?(int)$_GET['role_id']:'';
$actorFilter=trim($_GET['actor']??'');
$dateFrom=trim($_GET['date_from']??'');
$dateTo=trim($_GET['date_to']??'');

$validDate=function($d){ if($d==='')return''; $p=explode('-',$d); return(count($p)===3&&checkdate((int)$p[1],(int)$p[2],(int)$p[0]))?sprintf('%04d-%02d-%02d',$p[0],$p[1],$p[2]):''; };
$dateFrom=$validDate($dateFrom);
$dateTo=$validDate($dateTo);

/* build filters string */
$filterQS=[];
if($logsFilter!=='')$filterQS['logs_name']=$logsFilter;
if($actionFilter!=='')$filterQS['action_made']=$actionFilter;
if($roleFilter!=='')$filterQS['role_id']=$roleFilter;
if($actorFilter!=='')$filterQS['actor']=$actorFilter;
if($dateFrom!=='')$filterQS['date_from']=$dateFrom;
if($dateTo!=='')$filterQS['date_to']=$dateTo;
$filtersQueryString=http_build_query($filterQS);

/* Role options */
$roleOptions=[];
if($res=$mysqli->query("SELECT Role_Id, Role_Name FROM employee_roles ORDER BY Role_Name")){
  while($r=$res->fetch_assoc()){ $roleOptions[(int)$r['Role_Id']]=$r['Role_Name']; }
  $res->free();
}

/* -----------------------------
   Core SELECT with dynamic WHERE
----------------------------- */
$select="SELECT audit_info.id,audit_info.logs_id,audit_info.logs_name,
    employee_roles.Role_Name AS role_name,audit_info.action_made,
    action_by.employee_fname AS action_by_fname,action_by.employee_lname AS action_by_lname,
    audit_info.date_created,audit_info.old_version,audit_info.new_version
 FROM audit_info
 LEFT JOIN employee_list AS action_by ON audit_info.action_by=action_by.employee_id
 LEFT JOIN employee_roles ON action_by.Role_Id=employee_roles.Role_Id";
$where=[];$types='';$vals=[];
if($logsFilter!==''){ $where[]='audit_info.logs_name=?';$types.='i';$vals[]=$logsFilter; }
if($actionFilter!==''){ $where[]='audit_info.action_made=?';$types.='i';$vals[]=$actionFilter; }
if($roleFilter!==''){ $where[]='employee_roles.Role_Id=?';$types.='i';$vals[]=$roleFilter; }
if($actorFilter!==''){ $where[]='CONCAT(action_by.employee_fname,\" \",action_by.employee_lname) LIKE ?';$types.='s';$vals[]='%'.$actorFilter.'%'; }
if($dateFrom!==''&&$dateTo!==''){ $where[]='audit_info.date_created BETWEEN ? AND ?';$types.='ss';$vals[]=$dateFrom.' 00:00:00';$vals[]=$dateTo.' 23:59:59'; }
elseif($dateFrom!==''){ $where[]='audit_info.date_created>=?';$types.='s';$vals[]=$dateFrom.' 00:00:00'; }
elseif($dateTo!==''){ $where[]='audit_info.date_created<=?';$types.='s';$vals[]=$dateTo.' 23:59:59'; }
$sql=$select;if(!empty($where))$sql.=" WHERE ".implode(' AND ',$where)." ";$sql.=" ORDER BY audit_info.date_created DESC LIMIT ? OFFSET ?";
$typesWithLimit=$types.'ii';$valsWithLimit=$vals; $valsWithLimit[]=$limit;$valsWithLimit[]=$offset;
$stmt=$mysqli->prepare($sql); if(!$stmt){http_response_code(500);exit('Unable to prepare query.');}
$stmt->bind_param($typesWithLimit,...$valsWithLimit);$stmt->execute();$result=$stmt->get_result();

/* Count */
$countSql="SELECT COUNT(*) AS total FROM audit_info LEFT JOIN employee_list AS action_by ON audit_info.action_by=action_by.employee_id LEFT JOIN employee_roles ON action_by.Role_Id=employee_roles.Role_Id";
if(!empty($where))$countSql.=" WHERE ".implode(' AND ',$where);
$countStmt=$mysqli->prepare($countSql);if(!$countStmt){http_response_code(500);exit('Unable to prepare count.');}
if($types!=='')$countStmt->bind_param($types,...$vals);$countStmt->execute();$countRes=$countStmt->get_result();
$totalRows=(int)($countRes->fetch_assoc()['total']??0);$total_pages=max(1,(int)ceil($totalRows/$limit));

$baseUrl=enc_page('audit');$basePath=strtok($baseUrl,'?');$baseQueryStr=(string)parse_url($baseUrl,PHP_URL_QUERY);
parse_str($baseQueryStr,$baseQueryArr);$pageToken=$baseQueryArr['page']??($_GET['page']??'');
?>
<link rel="stylesheet" href="css/audit/adminAudit.css?v=1">

<h3><i class="fa fa-clipboard-list"></i> Admin - Audit Logs List</h3>

<div class="card card--flush shadow-sm mb-4">
  <div class="filters">
    <form class="mb-0" method="get" action="<?= h($basePath) ?>">
      <input type="hidden" name="page" value="<?= h($pageToken) ?>">
      <input type="hidden" name="pagenum" value="1">
      <div class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label small">Module</label>
          <select name="logs_name" class="form-select">
            <option value="">All</option>
            <?php for($i=1;$i<=30;$i++):$label=transform_logs_filename($i);if($label==='')continue;?>
              <option value="<?=$i?>"<?=selected_attr($logsFilter,$i)?>><?=h($label)?></option>
            <?php endfor;?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small">Action</label>
          <select name="action_made" class="form-select">
            <option value="">All</option>
            <?php for($i=1;$i<=11;$i++):$label=transform_action_made($i);if($label==='')continue;?>
              <option value="<?=$i?>"<?=selected_attr($actionFilter,$i)?>><?=h($label)?></option>
            <?php endfor;?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small">Role</label>
          <select name="role_id" class="form-select">
            <option value="">All</option>
            <?php foreach($roleOptions as $rid=>$rname):?>
              <option value="<?=$rid?>"<?=selected_attr($roleFilter,$rid)?>><?=h($rname)?></option>
            <?php endforeach;?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small">Actor</label>
          <input type="text" name="actor" class="form-control" value="<?=h($actorFilter)?>">
        </div>
        <div class="col-6 col-md-3"><label class="form-label small">From</label><input type="date" name="date_from" class="form-control" value="<?=h($dateFrom)?>"></div>
        <div class="col-6 col-md-3"><label class="form-label small">To</label><input type="date" name="date_to" class="form-control" value="<?=h($dateTo)?>"></div>
        <div class="col-md-6 d-flex gap-2 justify-content-md-end">
          <button type="submit" class="btn btn-outline-primary"><i class="fa fa-filter me-1"></i> Apply</button>
          <a class="btn btn-outline-secondary" href="<?=h($baseUrl)?>"><i class="fa fa-rotate-left me-1"></i> Reset</a>
          <!--<button type="button" class="btn btn-primary" onclick="window.print()"><i class="fa fa-print me-1"></i> Print</button>-->
        </div>
      </div>
    </form>
  </div>

  <div class="card-body">
    <div class="table-wrapper">
      <table class="audit-table align-middle">
        <thead><tr><th style="width: 350px;">Logs Name</th><th style="width: 350px;">Roles</th><th style="width: 350px;">Action Made</th><th style="width: 350px;">Action By</th><th style="width: 350px;">Date</th></tr></thead>
        <tbody>
          <?php while($row=$result->fetch_assoc()): ?>
          <tr>
            <td><span class="pill pill-default"><?=h(transform_logs_filename($row['logs_name']??''))?></span></td>
            <td><span class="pill pill-role"><?=h($row['role_name']??'N/A')?></span></td>
            <td><?php $a=transform_action_made($row['action_made']??''); echo "<span class='pill pill-".strtolower(str_replace(' ','_',$a))."'>".h($a)."</span>";?></td>
            <td><?=h(($row['action_by_fname']??'').' '.($row['action_by_lname']??''))?></td>
            <td><?=h(date('M d, Y h:i A',strtotime($row['date_created'])))?></td>
            <!--<td><?php if((int)($row['action_made']??0)===2):?><button class="btn btn-outline-primary btn-sm">View</button><?php endif;?></td>-->
          </tr>
          <?php endwhile; if($result->num_rows===0):?>
          <tr><td colspan="6" class="text-center text-muted py-4">No logs found.</td></tr><?php endif;?>
        </tbody>
      </table>
    </div>

    <?php
    $qs=$filtersQueryString?'&'.$filtersQueryString:''; if(!isset($pageBase)){
      $baseUrl=enc_page('audit');$basePath=strtok($baseUrl,'?');$baseQueryStr=(string)parse_url($baseUrl,PHP_URL_QUERY);
      parse_str($baseQueryStr,$baseQueryArr);$pageToken=$baseQueryArr['page']??($_GET['page']??'');
      $pageBase=$basePath.'?page='.urlencode($pageToken);
    }
    $maxLinks=5;$half=(int)floor($maxLinks/2);$start=max(1,$page-$half);$end=min($total_pages,$start+$maxLinks-1);$start=max(1,$end-$maxLinks+1);
    ?>
    <nav aria-label="Page navigation">
      <ul class="pagination justify-content-end">
        <?php if($page<=1):?><li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-left"></i></span></li>
        <?php else:?><li class="page-item"><a class="page-link" href="<?=$pageBase.$qs.'&pagenum=1'?>"><i class="fa fa-angle-double-left"></i></a></li><?php endif;?>
        <?php if($page<=1):?><li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-left"></i></span></li>
        <?php else:?><li class="page-item"><a class="page-link" href="<?=$pageBase.$qs.'&pagenum='.($page-1)?>"><i class="fa fa-angle-left"></i></a></li><?php endif;?>
        <?php if($start>1):?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif;?>
        <?php for($i=$start;$i<=$end;$i++):?><li class="page-item <?=($i==$page)?'active':''?>"><a class="page-link" href="<?=$pageBase.$qs.'&pagenum='.$i?>"><?=$i?></a></li><?php endfor;?>
        <?php if($end<$total_pages):?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif;?>
        <?php if($page>=$total_pages):?><li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-right"></i></span></li>
        <?php else:?><li class="page-item"><a class="page-link" href="<?=$pageBase.$qs.'&pagenum='.($page+1)?>"><i class="fa fa-angle-right"></i></a></li><?php endif;?>
        <?php if($page>=$total_pages):?><li class="page-item disabled"><span class="page-link"><i class="fa fa-angle-double-right"></i></span></li>
        <?php else:?><li class="page-item"><a class="page-link" href="<?=$pageBase.$qs.'&pagenum='.$total_pages?>"><i class="fa fa-angle-double-right"></i></a></li><?php endif;?>
      </ul>
    </nav>
  </div>
</div>

<style>
.card {
  background-color: #fff;
  border-radius: 10px;
  border: 1px solid #dee2e6;
  box-shadow: 0 2px 6px rgba(0,0,0,.05);
}
.card-body { padding: 1.5rem; }

.filters .form-label { margin-bottom: .25rem; }
.filters .form-select, .filters .form-control { height: 38px; }

.table-wrapper {
  max-height: 500px;
  overflow-y: auto;
  overflow-x: auto;
  width: 100%;
  padding: 0 1rem;
  border-radius: 6px;
}

.custom-audit-table {
  width: 100%;
  min-width: 950px;
  table-layout: auto;
  border-collapse: separate;
  border-spacing: 0 8px;
}
.custom-audit-table thead th {
  position: sticky;
  top: 0;
  background-color: #fff;
  z-index: 2;
}

/* Pills */
.pill { font-size: .75rem; font-weight: 500; text-transform: uppercase; padding: 4px 10px; border-radius: 999px; display: inline-block; }
.pill-role { background-color: #f8f9fa; color: #495057; border: 1px solid #ced4da; font-weight: bold; }
.pill-archived { background-color: #f8d7da; color: #842029; font-weight: bold; }
.pill-edited { background-color: #fff3cd; color: #856404; font-weight: bold; }
.pill-added { background-color: #d1e7dd; color: #0f5132; font-weight: bold; }
.pill-viewed { background-color: #dee2e6; color: #495057; font-weight: bold; }
.pill-restored { background-color: #d1e7dd; color: #0f5132; font-weight: bold; }
.pill-login { background-color: #cfe2ff; color: #084298; font-weight: bold; }
.pill-logout { background-color: #e2e3e5; color: #343a40; font-weight: bold; }
.pill-update_status { background-color: #d1c4e9; color: #4527a0; font-weight: bold; }
.pill-batch_add { background-color: #d1e7dd; color: #0f5132; font-weight: bold; }
.pill-urgent_request { background-color: #d1e7dd; color: #0f5132; font-weight: bold; }
.pill-print { background-color: #d1e7dd; color: #0f5132; font-weight: bold; }
.pill-default { font-weight: bold; background-color: #e9ecef; color: #000; }

@media (max-width: 768px) {
  .custom-audit-table { font-size: .85rem; }
  .btn-sm { padding: 2px 8px; font-size: .75rem; }
}
</style>

<script src="js/audit.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

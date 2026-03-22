<?php
/**
 * view.php — Public-Facing Module Router
 * Handles both ?UniqueToken=XXX and ?verify=TOKEN requests
 */
error_reporting(0);
ini_set('display_errors', 0);

// ── Database ────────────────────────────────────────────
try {
    $db = new SQLite3(__DIR__.'/invoices.db');
    $db->enableExceptions(true);
} catch (Exception $e) {
    header('Location: https://www.hidk.in'); exit();
}

// ── Shared helpers ──────────────────────────────────────
function gs($key,$def=''){
    global $db;
    $s=$db->prepare("SELECT setting_value FROM settings WHERE setting_key=:k");
    $s->bindValue(':k',$key,SQLITE3_TEXT);
    $r=$s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r?$r['setting_value']:$def;
}
function numFmt($n,$dec=2){return number_format(floatval($n),$dec);}
function genQR($txt,$sz=160){
    if(empty($txt))return '';
    return 'https://api.qrserver.com/v1/create-qr-code/?size='.$sz.'x'.$sz.'&data='.urlencode($txt).'&format=png&margin=10';
}
function upiLink($bal,$ref,$desc='',$isNonGST=false){
    $upi=$isNonGST?(gs('payment_upi_id_nongst')?:gs('payment_upi_id','')):gs('payment_upi_id','');
    if(empty($upi))return '';
    $amt=max(1,round(floatval($bal)));
    return 'upi://pay?'.http_build_query(['pa'=>$upi,'pn'=>gs('company_name','D K ASSOCIATES'),'am'=>$amt,'tn'=>substr($desc?:$ref,0,50),'cu'=>'INR'],'','&',PHP_QUERY_RFC3986);
}
function getNonGSTDb(){
    static $ndb=null;
    if($ndb===null){
        try{
            $ndb=new SQLite3(__DIR__.'/nongst_invoices.db');
            $ndb->enableExceptions(true);
        }catch(Exception $e){return null;}
    }
    return $ndb;
}
function getBaseUrl(){
    $proto=isset($_SERVER['HTTPS'])?'https://':'http://';
    $host=$_SERVER['HTTP_HOST']??'localhost';
    $dir=rtrim(dirname($_SERVER['PHP_SELF']),'/');
    return $proto.$host.$dir.'/';
}

// ── QR Verification page ────────────────────────────────
if(isset($_GET['verify'])){
    $vtok=preg_replace('/[^A-Za-z0-9]/','',$_GET['verify']??'');
    if(empty($vtok)){header('Location: https://www.hidk.in');exit();}
    $vr=null;
    try{
        $s=$db->prepare("SELECT * FROM qr_verifications WHERE token=:t AND is_active=1 LIMIT 1");
        $s->bindValue(':t',$vtok,SQLITE3_TEXT);
        $vr=$s->execute()->fetchArray(SQLITE3_ASSOC);
    }catch(Exception $e){}
    $co=gs('company_name','D K ASSOCIATES');
    $logo=gs('logo_path');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Document Verification — <?php echo htmlspecialchars($co);?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',system-ui,sans-serif;background:linear-gradient(135deg,#1e1b4b 0%,#312e81 50%,#4f46e5 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border-radius:20px;padding:40px;max-width:460px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3);text-align:center}
.icon{font-size:64px;margin-bottom:16px}
.doc-type{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:2px;margin-bottom:8px}
h1{font-size:24px;font-weight:800;margin-bottom:6px}
.doc-number{font-size:18px;font-weight:700;color:#4f46e5;margin-bottom:4px}
.doc-title{color:#64748b;font-size:14px;margin-bottom:16px}
.badge{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:30px;font-size:14px;font-weight:700;margin-bottom:20px}
.valid{background:#dcfce7;color:#15803d}
.invalid{background:#fee2e2;color:#dc2626}
.meta{font-size:12px;color:#94a3b8;margin-bottom:20px}
.company{font-size:13px;color:#475569;font-weight:600;padding-top:16px;border-top:1px solid #e2e8f0}
.countdown{font-size:13px;color:#94a3b8;margin-top:12px}
#cdown{font-weight:700;color:#4f46e5}
</style>
</head>
<body>
<div class="card">
<?php if($logo&&file_exists($logo)):?><img src="<?php echo htmlspecialchars($logo);?>" style="max-height:50px;max-width:150px;margin-bottom:16px" alt="Logo"><br><?php endif;?>
<?php if($vr): ?>
<div class="icon">✅</div>
<div class="doc-type"><?php echo htmlspecialchars(strtoupper(str_replace('_',' ',$vr['doc_type'])));?></div>
<h1>Document Verified</h1>
<div class="doc-number"><?php echo htmlspecialchars($vr['doc_number']??'');?></div>
<div class="doc-title"><?php echo htmlspecialchars($vr['doc_title']??'');?></div>
<div class="badge valid">✓ Authentic Document</div>
<div class="meta">Issued by: <strong><?php echo htmlspecialchars($co);?></strong><br>Verified on: <?php echo date('d M Y, h:i A');?></div>
<?php else: ?>
<div class="icon">❌</div>
<h1>Verification Failed</h1>
<div class="badge invalid">✗ Document Not Found or Invalid</div>
<div class="meta">This QR code could not be verified. It may have been regenerated or does not exist.</div>
<?php endif;?>
<div class="company">📋 <?php echo htmlspecialchars($co);?></div>
<div class="countdown">Redirecting to website in <span id="cdown">10</span>s</div>
</div>
<script>
var n=10;
var t=setInterval(function(){n--;document.getElementById('cdown').textContent=n;if(n<=0){clearInterval(t);window.location='https://www.hidk.in';}},1000);
</script>
</body>
</html>
<?php exit(); }

// ── Token routing ───────────────────────────────────────
$raw_token = $_GET['UniqueToken'] ?? '';
if(empty($raw_token)){header('Location: https://www.hidk.in');exit();}
$prefix = strtolower(substr($raw_token,0,3));
$token  = substr($raw_token,3);
if(empty($token)||!in_array($prefix,['inv','bkn','enr','cer','yat','pay'])){
    header('Location: https://www.hidk.in');exit();
}

// ── Shared page CSS ─────────────────────────────────────
$pageCss = '<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Segoe UI",system-ui,sans-serif;background:#f1f5f9;color:#1e293b;font-size:14px;line-height:1.6}
.wrap{max-width:820px;margin:0 auto;padding:20px}
.inv{background:#fff;border-radius:12px;border:1px solid #e2e8f0;box-shadow:0 4px 16px rgba(0,0,0,.08);padding:28px;margin-bottom:20px}
.hdr{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px;padding-bottom:14px;border-bottom:2px solid #eee;gap:16px}
.co{flex:2}.meta{text-align:right;flex:1;font-size:13px}
.cust{background:#f8fafc;padding:12px 16px;border-radius:8px;margin-bottom:14px;border-left:4px solid #4f46e5;font-size:13px}
table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:14px}
th{background:#f8fafc;font-weight:700;color:#64748b;border:1px solid #e2e8f0;padding:8px 10px;text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.4px}
td{border:1px solid #e2e8f0;padding:8px 10px;vertical-align:middle}
tfoot td{font-weight:700;background:#f8fafc}
.sum{display:flex;justify-content:flex-end;margin-bottom:14px}
.sum-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;min-width:240px}
.sum-box table{font-size:13px;margin:0}
.sum-box td{border:none;padding:3px 8px}
.sum-box td:last-child{font-weight:700;text-align:right}
.total-row td{font-size:16px;font-weight:800;border-top:2px solid #e2e8f0!important}
.qr-sec{text-align:center;margin:16px 0;padding:16px;background:#f8fafc;border-radius:8px}
.footer{text-align:center;font-size:12px;color:#94a3b8;margin-top:16px;padding-top:14px;border-top:1px solid #eee}
.badge{padding:3px 9px;border-radius:12px;font-size:11px;font-weight:700;display:inline-block}
.badge.paid{background:#dcfce7;color:#15803d}
.badge.unpaid{background:#fee2e2;color:#dc2626}
.badge.partially_paid,.badge.partial{background:#fef3c7;color:#d97706}
.btn-pay{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;background:linear-gradient(135deg,#4f46e5,#06b6d4);color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;margin-top:8px;border:none;cursor:pointer}
.pnr-box{background:#1e1b4b;color:#fff;padding:10px 18px;border-radius:10px;display:inline-block;margin:8px 0;text-align:center}
.pnr-box .label{font-size:10px;letter-spacing:1px;opacity:.8;margin-bottom:2px}
.pnr-box .pnr{font-family:monospace;font-size:24px;font-weight:800;letter-spacing:5px}
.verify-qr{text-align:center;margin-top:8px}
.verify-qr img{border:1px solid #e2e8f0;border-radius:4px;padding:3px}
.verify-qr .vl{font-size:9px;color:#94a3b8;margin-top:2px}
@media print{.no-print{display:none!important}.wrap{padding:0}body{background:#fff}}
@media(max-width:600px){.hdr{flex-direction:column}.meta{text-align:left}}
</style>';

// ══════════════════════════════════════════════════════
// INVOICE (inv prefix)
// ══════════════════════════════════════════════════════
if($prefix==='inv'){
    // Look in GST db first
    $s=$db->prepare("SELECT * FROM invoice_shares WHERE share_token=:t AND is_active=1");
    $s->bindValue(':t',$raw_token,SQLITE3_TEXT);
    $sr=$s->execute()->fetchArray(SQLITE3_ASSOC);
    $isNonGST=false;
    if(!$sr){
        $ndb=getNonGSTDb();
        if($ndb){
            $s2=$ndb->prepare("SELECT * FROM invoice_shares WHERE share_token=:t AND is_active=1");
            $s2->bindValue(':t',$raw_token,SQLITE3_TEXT);
            $sr=$s2->execute()->fetchArray(SQLITE3_ASSOC);
            $isNonGST=(bool)$sr;
        }
    }
    if(!$sr){header('Location: https://www.hidk.in');exit();}
    $tdb=$isNonGST?getNonGSTDb():$db;
    $inv_id=$sr['invoice_id'];
    $inv=$tdb->prepare("SELECT * FROM invoices WHERE id=:id");
    $inv->bindValue(':id',$inv_id,SQLITE3_INTEGER);
    $inv=$inv->execute()->fetchArray(SQLITE3_ASSOC);
    if(!$inv){header('Location: https://www.hidk.in');exit();}

    // Fetch items/purchases/payments
    $items=[]; $res=$tdb->query("SELECT * FROM invoice_items WHERE invoice_id=$inv_id ORDER BY s_no");
    while($r=$res->fetchArray(SQLITE3_ASSOC))$items[]=$r;
    $purchases=[]; $res=$tdb->query("SELECT * FROM purchases WHERE invoice_id=$inv_id ORDER BY s_no");
    while($r=$res->fetchArray(SQLITE3_ASSOC))$purchases[]=$r;
    $payments=[]; $res=$tdb->query("SELECT * FROM payments WHERE invoice_id=$inv_id ORDER BY payment_date");
    while($r=$res->fetchArray(SQLITE3_ASSOC))$payments[]=$r;

    // Compute totals
    $at=$st=$dt=$pt=$pr=0;
    foreach($items as $i){$at+=floatval($i['amount']);$st+=floatval($i['service_charge']);$dt+=floatval($i['discount']);}
    foreach($purchases as $p){$pa=floatval($p['purchase_amount']);if($pa==0&&$p['qty']>0&&$p['rate']>0)$pa=$p['qty']*$p['rate'];$pt+=$pa;$pr+=floatval($p['amount_received']);}
    $scp=$st-$dt;$pp=$pt-$pr;$sub=$scp+$pp;
    $gr=floatval($inv['gst_rate']??0);$gs_amt=floatval($inv['gst_amount']??0);$gi=(bool)($inv['gst_inclusive']??0);
    $isGST=($gr>0||$gs_amt>0||$gi);
    $geff=$tb_base=0;$tp=$sub;
    if($isGST){if($gi){$geff=round($sub*$gr/(100+$gr),2);$tp=$sub;$tb_base=round($sub-$geff,2);}else{$geff=round($sub*$gr/100,2);$tp=$sub+$geff;$tb_base=$sub;}}
    $rt=round($tp);$pa_tot=floatval($inv['paid_amount']??0);$bal=$rt-$pa_tot;
    $cur=gs('currency_symbol','₹');

    // QR verify token
    $vtok=null;
    if(!$isNonGST){
        $vr=$db->querySingle("SELECT token FROM qr_verifications WHERE doc_type='invoice' AND doc_id=$inv_id AND is_active=1 LIMIT 1");
        $vtok=$vr?:null;
    }
    $vurl=$vtok?getBaseUrl().'view.php?verify='.$vtok:null;

    // UPI
    $upi=upiLink($bal,$inv['invoice_number'],'Invoice '.$inv['invoice_number'],$isNonGST);
    $qr_url=$bal>0?genQR($upi,160):'';
    $tpl_name=gs('invoice_template','default');
    $pc='#3498db';$sc='#2c3e50';
    $tpl=$db->querySingle("SELECT template_data FROM invoice_templates WHERE template_name='".SQLite3::escapeString($tpl_name)."' LIMIT 1");
    if($tpl){$td=json_decode($tpl,true);$pc=$td['styling']['primary_color']??$pc;$sc=$td['styling']['secondary_color']??$sc;}
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Invoice <?php echo htmlspecialchars($inv['invoice_number']);?> — <?php echo htmlspecialchars(gs('company_name','D K ASSOCIATES'));?></title>
<?php echo $pageCss;?></head><body>
<div class="wrap">
<div class="inv">
<div class="hdr">
<div class="co">
<?php $lp=gs('logo_path');if($lp&&file_exists($lp))echo '<img src="'.htmlspecialchars($lp).'" style="max-height:70px;max-width:150px;margin-bottom:6px"><br>';?>
<h2 style="font-size:18px;font-weight:800;color:<?php echo htmlspecialchars($sc);?>"><?php echo htmlspecialchars(gs('company_name','D K ASSOCIATES'));?></h2>
<div style="font-size:12px;color:#555;line-height:1.7;margin-top:4px">
<?php $adr=gs('office_address');if($adr)echo nl2br(htmlspecialchars($adr)).'<br>';?>
<?php $ph=gs('office_phone');if($ph)echo '📞 '.htmlspecialchars($ph).'<br>';?>
<?php $em=gs('company_email');if($em)echo '✉️ '.htmlspecialchars($em).'<br>';?>
<?php if(!$isNonGST){$gno=gs('company_gst_number');if($gno)echo '<strong>GSTIN:</strong> '.htmlspecialchars($gno).'<br>';}?>
</div>
</div>
<div class="meta">
<div style="font-size:20px;font-weight:800;color:<?php echo htmlspecialchars($sc);?>;margin-bottom:8px"><?php echo $isNonGST?'NON-GST INVOICE':'TAX INVOICE';?></div>
<div><strong>Invoice #:</strong> <?php echo htmlspecialchars($inv['invoice_number']);?></div>
<div><strong>Date:</strong> <?php echo $inv['invoice_date']?date('d-m-Y',strtotime($inv['invoice_date'])):'';?></div>
<div style="margin-top:6px"><span class="badge <?php echo $inv['payment_status'];?>"><?php echo ucfirst(str_replace('_',' ',$inv['payment_status']));?></span></div>
<?php if($vurl): ?>
<div class="verify-qr"><img src="<?php echo htmlspecialchars(genQR($vurl,80));?>" width="80" alt="Verify"><div class="vl">Scan to verify</div></div>
<?php endif;?>
</div>
</div>

<div class="cust">
<strong style="color:<?php echo htmlspecialchars($pc);?>">BILL TO:</strong>
<strong><?php echo htmlspecialchars($inv['customer_name']);?></strong><br>
<?php if(!empty($inv['customer_phone']))echo '📞 '.htmlspecialchars($inv['customer_phone']).'<br>';?>
<?php if(!empty($inv['customer_email']))echo '✉️ '.htmlspecialchars($inv['customer_email']).'<br>';?>
<?php if(!empty($inv['customer_address']))echo '📍 '.htmlspecialchars($inv['customer_address']).'<br>';?>
<?php if(!empty($inv['customer_gst_number'])&&$inv['customer_gst_number']!=='NA')echo '<strong>GSTIN:</strong> '.htmlspecialchars($inv['customer_gst_number']);?>
</div>

<?php if(!empty($items)):?>
<table><thead><tr><th>#</th><th>Particulars</th><th>Amount</th><th>Svc Charge</th><th>Discount</th><th>Remark</th></tr></thead>
<tbody><?php foreach($items as $i=>$it):?><tr><td><?php echo $i+1;?></td><td><?php echo htmlspecialchars($it['particulars']);?></td><td><?php echo $cur.' '.numFmt($it['amount']);?></td><td><?php echo $cur.' '.numFmt($it['service_charge']);?></td><td><?php echo $cur.' '.numFmt($it['discount']);?></td><td><?php echo htmlspecialchars($it['remark']??'');?></td></tr><?php endforeach;?>
<tfoot><tr><td colspan="2"><strong>Subtotal</strong></td><td><strong><?php echo $cur.' '.numFmt($at);?></strong></td><td><strong><?php echo $cur.' '.numFmt($st);?></strong></td><td><strong><?php echo $cur.' '.numFmt($dt);?></strong></td><td></td></tr></tfoot>
</table><?php endif;?>

<?php if(!empty($purchases)):?>
<table><thead><tr><th>#</th><th>Particulars</th><th>Qty</th><th>Rate</th><th>Purchase Amt</th><th>Received</th></tr></thead>
<tbody><?php foreach($purchases as $i=>$p):$pa=floatval($p['purchase_amount']);if($pa==0&&$p['qty']>0&&$p['rate']>0)$pa=$p['qty']*$p['rate'];?><tr><td><?php echo $i+1;?></td><td><?php echo htmlspecialchars($p['particulars']);?></td><td><?php echo numFmt($p['qty']);?></td><td><?php echo $cur.' '.numFmt($p['rate']);?></td><td><?php echo $cur.' '.numFmt($pa);?></td><td><?php echo $cur.' '.numFmt($p['amount_received']);?></td></tr><?php endforeach;?>
</table><?php endif;?>

<div class="sum"><div class="sum-box"><table>
<tr><td>Service Charge Payable</td><td><?php echo $cur.' '.numFmt($scp);?></td></tr>
<?php if($pp!=0):?><tr><td>Purchase Payable</td><td><?php echo $cur.' '.numFmt($pp);?></td></tr><?php endif;?>
<?php if($isGST&&$geff>0):?><tr><td>GST (<?php echo numFmt($gr,1);?>%)<?php echo $gi?' (Incl.)':'';?></td><td><?php echo $cur.' '.numFmt($geff);?></td></tr><?php endif;?>
<tr class="total-row"><td>Total</td><td style="color:#4f46e5"><?php echo $cur.' '.numFmt($rt,0);?></td></tr>
<tr><td style="color:#15803d">Paid</td><td style="color:#15803d"><?php echo $cur.' '.numFmt($pa_tot);?></td></tr>
<?php if($bal>0):?><tr><td style="color:#dc2626;font-weight:700">Balance Due</td><td style="color:#dc2626;font-weight:700"><?php echo $cur.' '.numFmt($bal);?></td></tr><?php endif;?>
</table></div></div>

<?php if($bal>0&&$qr_url):?>
<div class="qr-sec">
<p style="font-size:12px;font-weight:600;margin-bottom:8px">Scan QR to pay <?php echo $cur.' '.numFmt($bal);?></p>
<img src="<?php echo htmlspecialchars($qr_url);?>" width="160" alt="Payment QR">
<?php if($upi):?><br><a href="<?php echo htmlspecialchars($upi);?>" class="btn-pay">💳 Pay Now</a><?php endif;?>
</div>
<?php endif;?>

<?php $pn=gs('payment_note');if($pn):?><div style="background:#fffbeb;border-left:4px solid #f59e0b;padding:10px 14px;border-radius:6px;font-size:12px;margin:12px 0"><?php echo htmlspecialchars($pn);?></div><?php endif;?>

<div class="footer">
<p>Thank You for Your Business!</p>
<p style="margin-top:4px"><?php echo htmlspecialchars(gs('company_name','D K ASSOCIATES'));?> | <?php echo htmlspecialchars(gs('company_website',''));?></p>
</div>
</div></div></body></html>
<?php exit();}

// ══════════════════════════════════════════════════════
// BOOKING RECEIPT (bkn prefix)
// ══════════════════════════════════════════════════════
if($prefix==='bkn'){
    $s=$db->prepare("SELECT * FROM booking_shares WHERE share_token=:t AND is_active=1");
    $s->bindValue(':t',$raw_token,SQLITE3_TEXT);
    $sr=$s->execute()->fetchArray(SQLITE3_ASSOC);
    if(!$sr){header('Location: https://www.hidk.in');exit();}
    $bid=$sr['booking_id'];
    $bk=$db->querySingle("SELECT * FROM bookings WHERE id=$bid",true);
    if(!$bk){header('Location: https://www.hidk.in');exit();}
    $items=[]; $res=$db->query("SELECT * FROM booking_items WHERE booking_id=$bid ORDER BY s_no");
    while($r=$res->fetchArray(SQLITE3_ASSOC))$items[]=$r;
    $pmts=[]; $res=$db->query("SELECT * FROM booking_payments WHERE booking_id=$bid ORDER BY payment_date");
    while($r=$res->fetchArray(SQLITE3_ASSOC))$pmts[]=$r;
    $tp=array_sum(array_column($pmts,'amount'));
    $bal=floatval($bk['total_estimated_cost'])-$tp;
    $cur=gs('currency_symbol','₹');
    $upi=upiLink($bal,$bk['booking_number'],'Booking '.$bk['booking_number']);
    $qr_url=$bal>0?genQR($upi,160):'';
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Booking <?php echo htmlspecialchars($bk['booking_number']);?></title>
<?php echo $pageCss;?></head><body>
<div class="wrap"><div class="inv">
<div class="hdr">
<div class="co">
<?php $lp=gs('logo_path');if($lp&&file_exists($lp))echo '<img src="'.htmlspecialchars($lp).'" style="max-height:60px;max-width:120px;margin-bottom:4px"><br>';?>
<h2 style="font-size:18px;font-weight:800"><?php echo htmlspecialchars(gs('company_name','D K ASSOCIATES'));?></h2>
<div style="font-size:12px;color:#555"><?php $ph=gs('office_phone');if($ph)echo '📞 '.htmlspecialchars($ph);?></div>
</div>
<div class="meta">
<div style="font-size:18px;font-weight:800;margin-bottom:8px">SERVICE BOOKING</div>
<div><strong>Booking #:</strong> <?php echo htmlspecialchars($bk['booking_number']);?></div>
<div><strong>Date:</strong> <?php echo $bk['booking_date']?date('d-m-Y',strtotime($bk['booking_date'])):'';?></div>
<div><strong>Status:</strong> <?php echo ucfirst(str_replace('_',' ',$bk['status']));?></div>
</div>
</div>
<div class="cust">
<strong><?php echo htmlspecialchars($bk['customer_name']);?></strong><br>
<?php if($bk['customer_phone'])echo '📞 '.htmlspecialchars($bk['customer_phone']).'<br>';?>
<?php if($bk['customer_address'])echo '📍 '.htmlspecialchars($bk['customer_address']);?>
</div>
<div style="margin-bottom:12px"><strong>Service:</strong> <?php echo htmlspecialchars($bk['service_description']??'');?></div>
<?php if(!empty($items)):?>
<table><thead><tr><th>#</th><th>Description</th><th>Estimated</th><th>Actual</th></tr></thead>
<tbody><?php foreach($items as $i=>$it):?><tr><td><?php echo $i+1;?></td><td><?php echo htmlspecialchars($it['description']);?></td><td><?php echo $cur.' '.numFmt($it['estimated_amount']);?></td><td><?php echo $cur.' '.numFmt($it['actual_amount']);?></td></tr><?php endforeach;?></tbody></table>
<?php endif;?>
<div class="sum"><div class="sum-box"><table>
<tr><td>Total Estimated</td><td><?php echo $cur.' '.numFmt($bk['total_estimated_cost']);?></td></tr>
<tr><td style="color:#15803d">Amount Paid</td><td style="color:#15803d"><?php echo $cur.' '.numFmt($tp);?></td></tr>
<?php if($bal>0):?><tr class="total-row"><td style="color:#dc2626">Balance</td><td style="color:#dc2626"><?php echo $cur.' '.numFmt($bal);?></td></tr><?php endif;?>
</table></div></div>
<?php if($bal>0&&$qr_url):?><div class="qr-sec"><p style="font-size:12px;font-weight:600;margin-bottom:8px">Scan to Pay</p><img src="<?php echo htmlspecialchars($qr_url);?>" width="150" alt="QR"><?php if($upi):?><br><a href="<?php echo htmlspecialchars($upi);?>" class="btn-pay">💳 Pay Now</a><?php endif;?></div><?php endif;?>
<div class="footer"><p>Thank You!</p><p><?php echo htmlspecialchars(gs('company_name','D K ASSOCIATES'));?></p></div>
</div></div></body></html>
<?php exit();}

// ══════════════════════════════════════════════════════
// ENROLLMENT RECEIPT (enr prefix)
// ══════════════════════════════════════════════════════
if($prefix==='enr'){
    $s=$db->prepare("SELECT * FROM academy_enrollment_shares WHERE share_token=:t AND is_active=1 AND share_type='enrollment'");
    $s->bindValue(':t',$raw_token,SQLITE3_TEXT);
    $sr=$s->execute()->fetchArray(SQLITE3_ASSOC);
    if(!$sr){header('Location: https://www.hidk.in');exit();}
    $eid=$sr['enrollment_id'];
    $enr=$db->querySingle("SELECT e.*,c.course_name,c.course_code FROM academy_enrollments e LEFT JOIN academy_courses c ON e.course_id=c.id WHERE e.id=$eid",true);
    if(!$enr){header('Location: https://www.hidk.in');exit();}
    $pmts=[]; $res=$db->query("SELECT * FROM academy_payments WHERE enrollment_id=$eid ORDER BY payment_date");
    while($r=$res->fetchArray(SQLITE3_ASSOC))$pmts[]=$r;
    $cur=gs('currency_symbol','₹');
    $upi=upiLink($enr['balance'],$enr['enrollment_id'],'Academy Fee '.$enr['enrollment_id']);
    $qr_url=floatval($enr['balance'])>0?genQR($upi,160):'';
    // verify QR
    $vtok=$db->querySingle("SELECT token FROM qr_verifications WHERE doc_type='enrollment' AND doc_id=$eid AND is_active=1 LIMIT 1");
    $vurl=$vtok?getBaseUrl().'view.php?verify='.$vtok:null;
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Enrollment Receipt — <?php echo htmlspecialchars($enr['enrollment_id']);?></title>
<?php echo $pageCss;?></head><body>
<div class="wrap"><div class="inv">
<div class="hdr">
<div class="co">
<?php $lp=gs('logo_path');if($lp&&file_exists($lp))echo '<img src="'.htmlspecialchars($lp).'" style="max-height:60px;max-width:120px;margin-bottom:4px"><br>';?>
<h2 style="font-size:18px;font-weight:800"><?php echo htmlspecialchars(gs('academy_name','Skill Training Academy'));?></h2>
<div style="font-size:12px;color:#555"><?php $ph=gs('office_phone');if($ph)echo '📞 '.htmlspecialchars($ph);?></div>
</div>
<div class="meta">
<div style="font-size:18px;font-weight:800;margin-bottom:6px">ENROLLMENT RECEIPT</div>
<div><strong>Enr. ID:</strong> <?php echo htmlspecialchars($enr['enrollment_id']);?></div>
<div><strong>Date:</strong> <?php echo $enr['created_at']?date('d-m-Y',strtotime($enr['created_at'])):'';?></div>
<div style="margin-top:4px"><span class="badge <?php echo $enr['status']==='active'?'paid':($enr['status']==='completed'?'paid':'unpaid');?>"><?php echo ucfirst($enr['status']);?></span></div>
<?php if($vurl):?><div class="verify-qr"><img src="<?php echo htmlspecialchars(genQR($vurl,80));?>" width="80" alt="Verify"><div class="vl">Scan to verify</div></div><?php endif;?>
</div>
</div>
<div class="cust">
<div style="display:flex;gap:20px;flex-wrap:wrap">
<div>
<strong>Candidate:</strong> <?php echo htmlspecialchars($enr['candidate_name']);?><br>
<?php echo htmlspecialchars($enr['relation']??'F/o').' '.htmlspecialchars($enr['relative_name']??'');?><br>
<?php if($enr['phone'])echo '📞 '.htmlspecialchars($enr['phone']).'<br>';?>
<?php if($enr['address'])echo '📍 '.htmlspecialchars($enr['address']);?>
</div>
<div>
<strong>Course:</strong> <?php echo htmlspecialchars($enr['course_name']??'');?><br>
<?php if($enr['course_code'])echo '<strong>Code:</strong> '.htmlspecialchars($enr['course_code']).'<br>';?>
<strong>Shift:</strong> <?php echo htmlspecialchars($enr['shift']??'');?><br>
<strong>Batch:</strong> <?php echo $enr['batch_start_date']?date('d-m-Y',strtotime($enr['batch_start_date'])):'?';?> — <?php echo $enr['batch_end_date']?date('d-m-Y',strtotime($enr['batch_end_date'])):'?';?>
</div>
</div>
</div>
<div class="sum"><div class="sum-box"><table>
<tr><td>Admission Fee</td><td><?php echo $cur.' '.numFmt($enr['admission_fee']);?></td></tr>
<tr><td>Course Fee</td><td><?php echo $cur.' '.numFmt($enr['course_fee_total']??$enr['total_fee']);?></td></tr>
<?php if(floatval($enr['exam_fee']??0)>0):?><tr><td>Exam Fee</td><td><?php echo $cur.' '.numFmt($enr['exam_fee']);?></td></tr><?php endif;?>
<?php if(floatval($enr['certificate_fee']??0)>0):?><tr><td>Certificate Fee</td><td><?php echo $cur.' '.numFmt($enr['certificate_fee']);?></td></tr><?php endif;?>
<?php if(floatval($enr['discount']??0)>0):?><tr><td>Discount</td><td style="color:#15803d">-<?php echo $cur.' '.numFmt($enr['discount']);?></td></tr><?php endif;?>
<tr class="total-row"><td>Total Fee</td><td style="color:#4f46e5"><?php echo $cur.' '.numFmt($enr['total_fee']);?></td></tr>
<tr><td style="color:#15803d">Amount Paid</td><td style="color:#15803d"><?php echo $cur.' '.numFmt($enr['amount_paid']);?></td></tr>
<?php if(floatval($enr['balance'])>0):?><tr><td style="color:#dc2626;font-weight:700">Balance Due</td><td style="color:#dc2626;font-weight:700"><?php echo $cur.' '.numFmt($enr['balance']);?></td></tr><?php endif;?>
</table></div></div>
<?php if(!empty($pmts)):?>
<h3 style="font-size:13px;font-weight:700;margin:12px 0 8px">Payment History</h3>
<table><thead><tr><th>Date</th><th>Amount</th><th>Type</th><th>Method</th></tr></thead>
<tbody><?php foreach($pmts as $p):?><tr><td><?php echo date('d-m-Y',strtotime($p['payment_date']));?></td><td><?php echo $cur.' '.numFmt($p['amount']);?></td><td><?php echo ucfirst($p['fee_type']??'');?></td><td><?php echo htmlspecialchars($p['payment_method']??'');?></td></tr><?php endforeach;?></tbody></table>
<?php endif;?>
<?php if(floatval($enr['balance'])>0&&$qr_url):?><div class="qr-sec"><p style="font-size:12px;font-weight:600;margin-bottom:8px">Scan to Pay Balance</p><img src="<?php echo htmlspecialchars($qr_url);?>" width="150" alt="QR"><?php if($upi):?><br><a href="<?php echo htmlspecialchars($upi);?>" class="btn-pay">💳 Pay Now</a><?php endif;?></div><?php endif;?>
<div class="footer"><p>Thank You!</p><p><?php echo htmlspecialchars(gs('academy_name','Skill Training Academy'));?></p></div>
</div></div></body></html>
<?php exit();}

// ══════════════════════════════════════════════════════
// CERTIFICATE (cer prefix) — delegate to certificate.php
// ══════════════════════════════════════════════════════
if($prefix==='cer'){
    $s=$db->prepare("SELECT * FROM academy_enrollment_shares WHERE share_token=:t AND is_active=1 AND share_type='certificate'");
    $s->bindValue(':t',$raw_token,SQLITE3_TEXT);
    $sr=$s->execute()->fetchArray(SQLITE3_ASSOC);
    if(!$sr){header('Location: https://www.hidk.in');exit();}
    $enrollment_id=$sr['enrollment_id'];
    require __DIR__.'/certificate.php';
    exit();
}

// ══════════════════════════════════════════════════════
// YATRA BOOKING (yat prefix)
// ══════════════════════════════════════════════════════
if($prefix==='yat'){
    $s=$db->prepare("SELECT * FROM yatra_booking_shares WHERE share_token=:t AND is_active=1");
    $s->bindValue(':t',$raw_token,SQLITE3_TEXT);
    $sr=$s->execute()->fetchArray(SQLITE3_ASSOC);
    if(!$sr){header('Location: https://www.hidk.in');exit();}
    $bid=$sr['yatra_booking_id'];
    $bk=$db->querySingle("SELECT yb.*,y.departure_date,y.return_date,y.bus_details,y.destination FROM yatra_bookings yb LEFT JOIN yatras y ON yb.yatra_id=y.id WHERE yb.id=$bid",true);
    if(!$bk){header('Location: https://www.hidk.in');exit();}
    $pass=[]; $res=$db->query("SELECT * FROM yatra_passengers WHERE booking_id=$bid ORDER BY id");
    while($r=$res->fetchArray(SQLITE3_ASSOC))$pass[]=$r;
    $pmts=[]; $res=$db->query("SELECT * FROM yatra_payments WHERE booking_id=$bid ORDER BY payment_date");
    while($r=$res->fetchArray(SQLITE3_ASSOC))$pmts[]=$r;
    $cur=gs('currency_symbol','₹');
    $bal=floatval($bk['total_amount'])-floatval($bk['amount_paid']);
    $upi=upiLink($bal,$bk['booking_ref'],'Yatra: '.$bk['booking_ref']);
    $qr_url=$bal>0?genQR($upi,160):'';
    $vtok=$db->querySingle("SELECT token FROM qr_verifications WHERE doc_type='yatra' AND doc_id=$bid AND is_active=1 LIMIT 1");
    $vurl=$vtok?getBaseUrl().'view.php?verify='.$vtok:null;
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Yatra Ticket — <?php echo htmlspecialchars($bk['pnr']??$bk['booking_ref']);?></title>
<?php echo $pageCss;?></head><body>
<div class="wrap"><div class="inv">
<div class="hdr">
<div class="co">
<?php $lp=gs('logo_path');if($lp&&file_exists($lp))echo '<img src="'.htmlspecialchars($lp).'" style="max-height:60px;max-width:120px;margin-bottom:4px"><br>';?>
<h2 style="font-size:18px;font-weight:800"><?php echo htmlspecialchars(gs('company_name','D K ASSOCIATES'));?></h2>
<div style="font-size:12px;color:#555"><?php $ph=gs('office_phone');if($ph)echo '📞 '.htmlspecialchars($ph);?></div>
</div>
<div class="meta">
<div style="font-size:18px;font-weight:800;margin-bottom:6px">🚌 YATRA TICKET</div>
<?php if(!empty($bk['pnr'])):?>
<div class="pnr-box"><div class="label">PNR</div><div class="pnr"><?php echo htmlspecialchars($bk['pnr']);?></div></div><br>
<?php endif;?>
<div><strong>Ref #:</strong> <?php echo htmlspecialchars($bk['booking_ref']);?></div>
<div><strong>Date:</strong> <?php echo $bk['booking_date']?date('d-m-Y',strtotime($bk['booking_date'])):'';?></div>
<?php if(!empty($bk['departure_date'])):?><div><strong>Departure:</strong> <?php echo date('d-m-Y',strtotime($bk['departure_date']));?></div><?php endif;?>
<?php if($vurl):?><div class="verify-qr"><img src="<?php echo htmlspecialchars(genQR($vurl,80));?>" width="80" alt="Verify"><div class="vl">Scan to verify</div></div><?php endif;?>
</div>
</div>
<div class="cust">
<div style="display:flex;gap:20px;flex-wrap:wrap">
<div>
<strong>Lead Passenger:</strong> <?php echo htmlspecialchars($bk['lead_passenger_name']);?><br>
<?php if($bk['phone'])echo '📞 '.htmlspecialchars($bk['phone']).'<br>';?>
<?php if($bk['address'])echo '📍 '.htmlspecialchars($bk['address']);?>
</div>
<div>
<strong>Yatra:</strong> <?php echo htmlspecialchars($bk['yatra_name']??'');?><br>
<?php if($bk['destination'])echo '📍 '.$bk['destination'].'<br>';?>
<?php if(!empty($bk['bus_details']))echo '🚌 '.htmlspecialchars($bk['bus_details']).'<br>';?>
<?php if($bk['return_date'])echo 'Return: '.date('d-m-Y',strtotime($bk['return_date']));?>
</div>
<?php if(!empty($bk['emergency_contact'])):?>
<div><strong>Emergency:</strong> <?php echo htmlspecialchars($bk['emergency_contact_name']??'');?><br><?php echo htmlspecialchars($bk['emergency_contact']);?></div>
<?php endif;?>
</div>
</div>
<?php if(!empty($pass)):?>
<h3 style="font-size:13px;font-weight:700;margin:14px 0 8px">👥 Passengers (<?php echo count($pass);?>)</h3>
<table><thead><tr><th>#</th><th>Name</th><th>Age</th><th>Gender</th><th>ID Proof</th></tr></thead>
<tbody><?php foreach($pass as $i=>$p):?><tr><td><?php echo $i+1;?></td><td><?php echo htmlspecialchars($p['name']);?></td><td><?php echo $p['age']?$p['age']:'—';?></td><td><?php echo htmlspecialchars($p['gender']??'');?></td><td><?php echo htmlspecialchars($p['id_proof_type']??'');?> <?php echo htmlspecialchars($p['id_proof_number']??'');?></td></tr><?php endforeach;?></tbody></table>
<?php endif;?>
<div class="sum"><div class="sum-box"><table>
<tr><td>Total Amount</td><td><?php echo $cur.' '.numFmt($bk['total_amount']);?></td></tr>
<tr><td style="color:#15803d">Amount Paid</td><td style="color:#15803d"><?php echo $cur.' '.numFmt($bk['amount_paid']);?></td></tr>
<?php if($bal>0):?><tr class="total-row"><td style="color:#dc2626">Balance</td><td style="color:#dc2626"><?php echo $cur.' '.numFmt($bal);?></td></tr><?php endif;?>
</table></div></div>
<?php if($bal>0&&$qr_url):?><div class="qr-sec"><p style="font-size:12px;font-weight:600;margin-bottom:8px">Scan to Pay Balance</p><img src="<?php echo htmlspecialchars($qr_url);?>" width="150" alt="QR"><?php if($upi):?><br><a href="<?php echo htmlspecialchars($upi);?>" class="btn-pay">💳 Pay Now</a><?php endif;?></div><?php endif;?>
<?php $pn=gs('payment_note');if($pn):?><div style="background:#fffbeb;border-left:4px solid #f59e0b;padding:10px 14px;border-radius:6px;font-size:12px;margin:12px 0"><?php echo htmlspecialchars($pn);?></div><?php endif;?>
<div class="footer"><p>Thank You! Safe Journey 🙏</p><p><?php echo htmlspecialchars(gs('company_name','D K ASSOCIATES'));?></p></div>
</div></div></body></html>
<?php exit();}

// ══════════════════════════════════════════════════════
// PAYMENT LINK (pay prefix)
// ══════════════════════════════════════════════════════
if($prefix==='pay'){
    $s=$db->prepare("SELECT * FROM payment_links WHERE token=:t AND is_active=1");
    $s->bindValue(':t',$raw_token,SQLITE3_TEXT);
    $pl=$s->execute()->fetchArray(SQLITE3_ASSOC);
    if(!$pl){header('Location: https://www.hidk.in');exit();}
    // Check expiry
    if(!empty($pl['expires_at'])&&strtotime($pl['expires_at'])<time()){
        header('Location: https://www.hidk.in');exit();
    }
    $cur=gs('currency_symbol','₹');
    $upi=upiLink($pl['amount'],$pl['purpose'],$pl['purpose']);
    $qr_url=genQR($upi,200);
    $co=gs('company_name','D K ASSOCIATES');
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payment Request — <?php echo htmlspecialchars($co);?></title>
<?php echo $pageCss;?>
<style>.plcard{max-width:440px;margin:40px auto;background:#fff;border-radius:20px;padding:36px;box-shadow:0 16px 48px rgba(0,0,0,.14);text-align:center}.plcard h1{font-size:22px;font-weight:800;margin-bottom:6px}.plcard .amt{font-size:40px;font-weight:800;color:#4f46e5;margin:12px 0}.plcard .purpose{color:#64748b;font-size:14px;margin-bottom:20px}.plcard img{max-width:200px;border-radius:8px;border:1px solid #e2e8f0;padding:6px;margin-bottom:16px}</style>
</head><body style="background:#f1f5f9">
<div class="plcard">
<?php $lp=gs('logo_path');if($lp&&file_exists($lp))echo '<img src="'.htmlspecialchars($lp).'" style="max-height:50px;max-width:130px;margin-bottom:12px"><br>';?>
<h1><?php echo htmlspecialchars($co);?></h1>
<div class="purpose"><?php echo htmlspecialchars($pl['purpose']);?></div>
<?php if(!empty($pl['customer_name'])):?><div style="font-size:13px;color:#475569;margin-bottom:8px">For: <strong><?php echo htmlspecialchars($pl['customer_name']);?></strong></div><?php endif;?>
<div class="amt"><?php echo $cur.' '.numFmt($pl['amount']);?></div>
<?php if($qr_url):?><img src="<?php echo htmlspecialchars($qr_url);?>" alt="Pay QR"><br><?php endif;?>
<?php if($upi):?><a href="<?php echo htmlspecialchars($upi);?>" class="btn-pay" style="justify-content:center;display:inline-flex">💳 Pay Now</a><?php endif;?>
<div style="font-size:12px;color:#94a3b8;margin-top:16px">This payment link is valid until <?php echo !empty($pl['expires_at'])?date('d-m-Y',strtotime($pl['expires_at'])):'—';?></div>
<?php $pn=gs('payment_note');if($pn):?><div style="background:#fffbeb;border-left:4px solid #f59e0b;padding:10px 14px;border-radius:6px;font-size:12px;margin:16px 0;text-align:left"><?php echo htmlspecialchars($pn);?></div><?php endif;?>
</div>
</body></html>
<?php exit();}

// Fallback
header('Location: https://www.hidk.in');exit();

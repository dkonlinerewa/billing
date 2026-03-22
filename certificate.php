<?php
/**
 * certificate.php — Ornate Certificate Template
 * Called from view.php with $enrollment_id set, or directly for testing
 */
if(!isset($db)){
    error_reporting(0);
    try{$db=new SQLite3(__DIR__.'/invoices.db');$db->enableExceptions(true);}
    catch(Exception $e){header('Location: https://www.hidk.in');exit();}
}
if(!isset($enrollment_id)) $enrollment_id=intval($_GET['enrollment_id']??0);
if(!$enrollment_id){header('Location: https://www.hidk.in');exit();}

// Fetch enrollment + course
$enr=$db->querySingle("SELECT e.*,c.course_name,c.course_code,c.duration_months FROM academy_enrollments e LEFT JOIN academy_courses c ON e.course_id=c.id WHERE e.id=".intval($enrollment_id),true);
if(!$enr){header('Location: https://www.hidk.in');exit();}

function gs($k,$d=''){global $db;$s=$db->prepare("SELECT setting_value FROM settings WHERE setting_key=:k");$s->bindValue(':k',$k,SQLITE3_TEXT);$r=$s->execute()->fetchArray(SQLITE3_ASSOC);return $r?$r['setting_value']:$d;}

$co=gs('company_name','D K ASSOCIATES');
$acName=gs('academy_name','Skill Training Academy');
$acAddr=gs('office_address','');
$logo=gs('logo_path','');
$seal=gs('seal_path','');

// QR verify token
$vtok=$db->querySingle("SELECT token FROM qr_verifications WHERE doc_type='enrollment' AND doc_id=".intval($enrollment_id)." AND is_active=1 LIMIT 1");
$vurl='';
if($vtok){
    $proto=isset($_SERVER['HTTPS'])?'https://':'http://';
    $host=$_SERVER['HTTP_HOST']??'localhost';
    $dir=rtrim(dirname($_SERVER['PHP_SELF']),'/');
    $vurl=$proto.$host.$dir.'/view.php?verify='.$vtok;
}
$vqr=$vurl?'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data='.urlencode($vurl).'&format=png&margin=8':'';

$completionDate=$enr['batch_end_date']?date('d F Y',strtotime($enr['batch_end_date'])):date('d F Y');
$issueDate=date('d F Y');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Certificate — <?php echo htmlspecialchars($enr['candidate_name']);?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700;900&family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=Great+Vibes&display=swap');
*{margin:0;padding:0;box-sizing:border-box}
body{background:#f0ead6;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;font-family:'Cormorant Garamond',Georgia,serif}
.cert-wrap{position:relative;width:100%;max-width:860px}
.cert{
    background:#fffdf4;
    border:12px solid #c8a96e;
    outline:3px solid #8b6914;
    outline-offset:6px;
    box-shadow:0 8px 40px rgba(0,0,0,.25),inset 0 0 80px rgba(200,169,110,.08);
    padding:48px 56px;
    position:relative;
    overflow:hidden;
    min-height:600px;
}
/* Corner ornaments */
.cert::before,.cert::after{
    content:'❧';
    font-size:40px;
    color:#c8a96e;
    position:absolute;
    line-height:1;
}
.cert::before{top:18px;left:18px}
.cert::after{top:18px;right:18px}
.corner-bl,.corner-br{position:absolute;font-size:40px;color:#c8a96e;line-height:1}
.corner-bl{bottom:18px;left:18px}
.corner-br{bottom:18px;right:18px}
/* Watermark */
.watermark{
    position:absolute;
    top:50%;left:50%;
    transform:translate(-50%,-50%) rotate(-30deg);
    font-size:80px;
    color:rgba(200,169,110,.06);
    font-family:'Cinzel',serif;
    font-weight:900;
    white-space:nowrap;
    pointer-events:none;
    z-index:0;
    letter-spacing:8px;
}
.content{position:relative;z-index:1;text-align:center}
.logo-row{display:flex;align-items:center;justify-content:center;gap:16px;margin-bottom:10px}
.logo-row img{max-height:64px;max-width:130px}
.org-name{font-family:'Cinzel',serif;font-size:20px;font-weight:700;color:#8b6914;letter-spacing:3px;text-transform:uppercase}
.divider{width:60%;height:2px;background:linear-gradient(90deg,transparent,#c8a96e,transparent);margin:12px auto}
.cert-title{
    font-family:'Cinzel',serif;
    font-size:42px;
    font-weight:900;
    color:#6b4f1a;
    letter-spacing:6px;
    text-transform:uppercase;
    margin:8px 0 4px;
    text-shadow:1px 1px 0 rgba(200,169,110,.4);
}
.of-completion{font-family:'Cinzel',serif;font-size:14px;color:#a0845e;letter-spacing:4px;text-transform:uppercase;margin-bottom:16px}
.certify-text{font-size:15px;color:#5a4a3a;margin:8px 0 4px;font-style:italic}
.candidate-name{
    font-family:'Great Vibes',cursive;
    font-size:56px;
    color:#4a3510;
    margin:8px 0;
    line-height:1.2;
}
.relative-line{font-size:14px;color:#6b5a3a;margin-bottom:12px}
.course-label{font-size:13px;color:#8b7355;letter-spacing:2px;text-transform:uppercase;margin-bottom:4px}
.course-name{
    font-family:'Cinzel',serif;
    font-size:22px;
    font-weight:700;
    color:#6b4f1a;
    margin-bottom:4px;
}
.course-meta{font-size:13px;color:#8b7355;margin-bottom:12px}
.date-line{font-size:13px;color:#6b5a3a;margin-bottom:20px}
.footer-row{display:flex;justify-content:space-between;align-items:flex-end;margin-top:24px;padding-top:16px;border-top:1px solid #e8d5a0}
.sig-block{text-align:center;flex:1}
.sig-line{width:130px;height:1px;background:#8b6914;margin:8px auto 4px}
.sig-label{font-size:11px;color:#8b7355;letter-spacing:1px;text-transform:uppercase}
.qr-block{text-align:center}
.qr-block img{border:2px solid #c8a96e;padding:4px;border-radius:4px}
.qr-block .qr-label{font-size:9px;color:#a0845e;margin-top:3px;letter-spacing:1px}
.enr-id{font-size:11px;color:#a0845e;margin-top:6px;letter-spacing:1px}
@media print{
    body{background:#fff;padding:0}
    .cert{box-shadow:none;outline:3px solid #8b6914}
    .no-print{display:none!important}
}
@media(max-width:600px){
    .cert{padding:28px 20px}
    .cert-title{font-size:28px}
    .candidate-name{font-size:40px}
    .footer-row{flex-direction:column;align-items:center;gap:20px}
}
</style>
</head>
<body>
<div class="cert-wrap">
<div class="cert no-print" style="display:none"></div>
<div class="no-print" style="text-align:center;margin-bottom:16px">
    <button onclick="window.print()" style="padding:10px 24px;background:#8b6914;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer">🖨️ Print Certificate</button>
    <button onclick="history.back()" style="padding:10px 20px;background:#e2e8f0;color:#1e293b;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;margin-left:8px">← Back</button>
</div>
<div class="cert">
    <div class="watermark">CERTIFIED</div>
    <span class="corner-bl">❧</span>
    <span class="corner-br" style="transform:scaleX(-1);display:inline-block">❧</span>

    <div class="content">
        <?php if($logo&&file_exists($logo)): ?>
        <div class="logo-row"><img src="<?php echo htmlspecialchars($logo);?>" alt="Logo"></div>
        <?php endif;?>
        <div class="org-name"><?php echo htmlspecialchars($acName);?></div>
        <?php if($acAddr):?><div style="font-size:11px;color:#a0845e;margin-top:3px;letter-spacing:1px"><?php echo htmlspecialchars($acAddr);?></div><?php endif;?>

        <div class="divider"></div>

        <div class="cert-title">Certificate</div>
        <div class="of-completion">of Completion</div>

        <div class="divider"></div>

        <p class="certify-text">This is to certify that</p>
        <div class="candidate-name"><?php echo htmlspecialchars($enr['candidate_name']);?></div>
        <?php if(!empty($enr['relative_name'])):?>
        <div class="relative-line"><?php echo htmlspecialchars($enr['relation']??'F/o').' '.htmlspecialchars($enr['relative_name']);?></div>
        <?php endif;?>

        <p class="certify-text">has successfully completed the course</p>
        <div class="course-label">Course of Study</div>
        <div class="course-name"><?php echo htmlspecialchars($enr['course_name']??'');?></div>
        <?php if(!empty($enr['course_code'])):?><div class="course-meta">Code: <?php echo htmlspecialchars($enr['course_code']);?><?php if(!empty($enr['duration_months'])):?> &nbsp;|&nbsp; Duration: <?php echo $enr['duration_months'];?> Month<?php echo $enr['duration_months']>1?'s':'';?><?php endif;?></div><?php endif;?>
        <?php if($enr['shift']):?><div class="course-meta">Shift: <?php echo htmlspecialchars($enr['shift']);?></div><?php endif;?>

        <div class="date-line">
            <?php if($enr['batch_start_date']&&$enr['batch_end_date']):?>
            Period: <?php echo date('d M Y',strtotime($enr['batch_start_date']));?> — <?php echo date('d M Y',strtotime($enr['batch_end_date']));?>
            <?php endif;?>
        </div>

        <div class="footer-row">
            <div class="sig-block">
                <?php if($seal&&file_exists($seal)):?>
                <img src="<?php echo htmlspecialchars($seal);?>" style="max-height:60px;max-width:80px;margin-bottom:4px" alt="Seal">
                <?php endif;?>
                <div class="sig-line"></div>
                <div class="sig-label">Director / Principal</div>
                <div style="font-size:11px;color:#8b7355;margin-top:2px"><?php echo htmlspecialchars($acName);?></div>
            </div>

            <div style="flex:1;text-align:center">
                <div style="font-size:11px;color:#a0845e;letter-spacing:1px;text-transform:uppercase;margin-bottom:4px">Date of Issue</div>
                <div style="font-family:'Cinzel',serif;font-size:14px;font-weight:700;color:#6b4f1a"><?php echo $issueDate;?></div>
                <div class="enr-id">Enrollment: <?php echo htmlspecialchars($enr['enrollment_id']);?></div>
            </div>

            <div class="qr-block">
                <?php if($vqr):?>
                <img src="<?php echo htmlspecialchars($vqr);?>" width="90" height="90" alt="Verify QR">
                <div class="qr-label">Scan to Verify</div>
                <?php else:?>
                <div style="width:90px;height:90px;border:2px solid #c8a96e;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:10px;color:#a0845e">No QR</div>
                <?php endif;?>
            </div>
        </div>

        <div style="margin-top:14px;font-size:10px;color:#a0845e;letter-spacing:1px;font-style:italic">
            This certificate is issued under the authority of <?php echo htmlspecialchars($co);?>
        </div>
    </div>
</div>
</div>
</body>
</html>

<?php
// 1. SETTINGS
date_default_timezone_set('Asia/Kolkata');
ini_set('memory_limit', '512M');
set_time_limit(300);

// Enable errors strictly for debugging (Turn off in production)
error_reporting(E_ALL); 
ini_set('display_errors', 1);

require_once('fpdf.php');
$SIGNATURE_DIR = __DIR__ . '/signatures/';

class ProductionGuard {
    private static $timers = array();
    public static function checkTime($seconds = 280) {
        if (empty(self::$timers['start'])) self::$timers['start'] = microtime(true);
        if ((microtime(true) - self::$timers['start']) > $seconds) {
            die("Error: Timeout. The date range is too large.");
        }
    }
}

/* --- HELPERS --- */
function pdf_text($str) {
    if ($str === null) return '';
    $map = array('≥'=>'>=','≤'=>'<=','μ'=>'u','±'=>'+/-','–'=>'-','—'=>'-','’'=>"'",'“'=>'"','”'=>'"','°'=>' deg ');
    $str = strtr($str, $map);
    return iconv('UTF-8', 'ISO-8859-1//IGNORE', $str);
}
function fmt_date($date) { return ($date) ? date('d-m-Y', strtotime($date)) : '-'; }
function fmt_datetime($date) { return ($date) ? date('d-m-Y H:i', strtotime($date)) : '-'; }
function calculate_age($dob) { return ($dob) ? date('Y') - date('Y', strtotime($dob)) : ''; }

/* --- DB CONNECTION --- */
$mysqli = new mysqli("localhost", "labuser", "labpass123", "nabl_import");
if ($mysqli->connect_error) die("DB Connection Error");
$mysqli->set_charset("utf8mb4");

/* --- INPUTS --- */
$mrd = isset($_POST['mrd']) ? trim($_POST['mrd']) : '';
$from_date = isset($_POST['from_date']) ? $_POST['from_date'] : '';
$to_date = isset($_POST['to_date']) ? $_POST['to_date'] : '';

if (!$mrd || !$from_date || !$to_date) die("Invalid Input");

/* --- DATA FETCHING --- */
$sql = "SELECT pm.id, pm.full_name, pm.sex, pm.dob, pm.mrd_number, lo.visit_type, lo.visit_code, lo.ordering_location 
        FROM patient_master pm JOIN lab_order lo ON lo.patient_id = pm.id WHERE pm.mrd_number = ? LIMIT 1";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("s", $mrd);
$stmt->execute();
$stmt->bind_result($pid,$name,$sex,$dob,$mrdno,$visit_type,$visit_id,$location);
if (!$stmt->fetch()) {
    echo '<h3>Patient Not Found</h3>'; exit;
}
$stmt->close();
$sex = ($sex==1)?'Male':(($sex==2)?'Female':$sex);
$age = calculate_age($dob);

// Query is same, but ORDER BY puts Interpretation next to Test logic safely
$sql = "SELECT 
            lo.service_center_abbr, sm.name, lsm.name, lo.result_value, lsm.unit_abbr,
            lo.normal_range_from, lo.normal_range_to, lo.sample_id, 
            lo.sample_generated_date_time, lo.sample_received_date_time, lo.result_certified_date_time, 
            DATE(lo.result_certified_date_time), lsm.interpretive_text, 
            ru.user_name, cu.user_name, cu.user_desc, lo.result_certified_by
        FROM lab_order lo
        LEFT JOIN lab_service_master lsm ON lsm.id=lo.lab_service_id
        LEFT JOIN specimen_master sm ON sm.id=lo.specimen_id
        LEFT JOIN his_user ru ON ru.id=lo.his_user_id
        LEFT JOIN his_user cu ON cu.id=lo.result_certified_by
        WHERE lo.patient_id = ? 
        AND lo.result_certified_date_time >= ? 
        AND lo.result_certified_date_time < DATE_ADD(?, INTERVAL 1 DAY)
        AND lo.result_certified_date_time IS NOT NULL
        ORDER BY lo.service_center_abbr, sm.name, lo.result_certified_date_time DESC, lsm.sort_id";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("iss", $pid, $from_date, $to_date);
$stmt->execute();
$stmt->bind_result($panel,$specimen,$test,$value,$unit,$min,$max,$sample_id,$collected,$received,$reported,$sample_date,$interp,$referred_by,$certified_by,$certified_desc,$certified_by_id);

$report = array();
$rowCount = 0;

while($stmt->fetch()) {
    if ($rowCount++ % 100 == 0) ProductionGuard::checkTime(280);

    $groupKey = $sample_date . '_' . $sample_id;
    if (!isset($report[$panel][$specimen][$groupKey])) {
        $report[$panel][$specimen][$groupKey] = array(
            'sample_id' => $sample_id, 
            'referred_by' => $referred_by,
            'certified_by' => $certified_by, 'certified_desc' => $certified_desc, 'certified_by_id' => $certified_by_id,
            'sample_date' => $sample_date, 
            'dates' => array('collected'=>$collected, 'received'=>$received, 'reported'=>$reported),
            'tests' => array()
        );
    }
    // STORE INTERPRETATION DIRECTLY WITH THE TEST
    $report[$panel][$specimen][$groupKey]['tests'][] = array(
        'test'=>$test,
        'value'=>$value,
        'unit'=>$unit,
        'min'=>$min,
        'max'=>$max,
        'interp'=>$interp // Attaching interp here!
    );
}
$stmt->close();

if (empty($report)) { echo '<h3>No Records Found</h3>'; exit; }

/* --- PDF ENGINE --- */
class PDF extends FPDF {
    public $showHeader = true;

    function Header() {
        if (!$this->showHeader) return;
        if (file_exists(__DIR__.'/barc.png')) $this->Image(__DIR__.'/barc.png',10,8,20);
        $this->SetFont('Arial','B',13); $this->Cell(0,6,'BHABHA ATOMIC RESEARCH CENTRE',0,1,'C');
        $this->SetFont('Arial','',11); $this->Cell(0,5,'Medical Division',0,1,'C');
        $this->SetFont('Arial','B',11); $this->Cell(0,5,'Pathology Laboratory, BARC Hospital',0,1,'C');
        $this->SetFont('Arial','',9); $this->Cell(0,5,'Anusaktinagar, Mumbai - 400094',0,1,'C');
        $this->Ln(3); $this->Line(10,$this->GetY(),200,$this->GetY());
    }
    function Footer() {
        $this->SetY(-12); $this->SetFont('Arial','',8); $this->Cell(0,8,'Page '.$this->PageNo().' of {nb}',0,0,'C');
    }
    
    // Helper to check if we need a new page
    function CheckPageBreak($h) {
        if($this->GetY()+$h > $this->PageBreakTrigger)
            $this->AddPage($this->CurOrientation);
    }
}

$pdf = new PDF();
$pdf->SetCompression(true);
$pdf->AliasNbPages();
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);

foreach ($report as $panel => $specimens) {
    foreach ($specimens as $spec => $dates) {
        foreach ($dates as $block) {
            
            ProductionGuard::checkTime(290);

            $pdf->showHeader = true; 
            $pdf->AddPage(); 
            $pdf->showHeader = false;
            $lh = 5;

            /* --- PATIENT BLOCK --- */
            $pdf->SetFont('Arial','B',9); $pdf->Cell(0,6,'Patient Information',0,1);
            $pdf->SetFont('Arial','',9);
            $pdf->Cell(95,$lh,"MRD No : $mrdno",0); $pdf->Cell(0,$lh,"Sex : $sex",0,1);
            $pdf->Cell(95,$lh,"Name : ".pdf_text($name),0); $pdf->Cell(0,$lh,"Age : $age Years",0,1);
            $pdf->Cell(95,$lh,"DOB : ".fmt_date($dob),0); $pdf->Cell(0,$lh,"Visit ID : $visit_id",0,1);
            $pdf->Cell(95,$lh,"Visit Type : $visit_type",0); $pdf->Cell(0,$lh,"Location : $location",0,1);

            /* --- SAMPLE BLOCK --- */
            $pdf->Ln(2); $pdf->SetFont('Arial','B',9); $pdf->Cell(0,6,'Sample & Report Details',0,1);
            $pdf->SetFont('Arial','',9);
            $pdf->Cell(95,$lh,"Sample Date : ".fmt_date($block['sample_date']),0);
            $pdf->Cell(0,$lh,"Referred By : ".pdf_text($block['referred_by']),0,1);
            $pdf->Cell(95,$lh,"Sample Collected : ".fmt_datetime($block['dates']['collected']),0);
            $pdf->Cell(0,$lh,"Sample Received : ".fmt_datetime($block['dates']['received']),0,1);
            $pdf->Cell(95,$lh,"Reported Date : ".fmt_datetime($block['dates']['reported']),0);
            $pdf->Cell(0,$lh,"Report Status : Final",0,1);

            /* --- PANEL HEADER --- */
            $pdf->Ln(4); 
            $pdf->SetFont('Arial','B',11); $pdf->SetFillColor(240,240,240);
            $pdf->Cell(0,8,"PANEL : $panel",0,1,'L',true);
            $pdf->SetFont('Arial','B',10);
            $pdf->Cell(0,7,"Sample Type : $spec (ID: ".$block['sample_id'].")",0,1);
            
            /* --- DIVIDER --- */
            $pdf->SetDrawColor(200,200,200);
            $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
            $pdf->Ln(2);

            
            foreach ($block['tests'] as $t) {
                
                // 1. Prepare Data
                $testName = pdf_text($t['test']);
                $resultRaw = $t['value'];
                $unit = trim((string)$t['unit']);
                $resultDisplay = ($unit !== '') ? "$resultRaw  [$unit]" : $resultRaw;
                
                // Range
                $min = trim((string)$t['min']);
                $max = trim((string)$t['max']);
                $range = ($min !== '' && $max !== '') ? "$min - $max" : '-';
                
                // Flag Logic
                $flag = '';
                $flagColor = array(0,0,0); // Default Black (PHP 5.3 safe)
                if ($min !== '' && $max !== '' && is_numeric($resultRaw)) {
                    if ((float)$resultRaw < (float)$min) { 
                        $flag = 'LOW'; 
                        $flagColor = array(200,0,0); // Red
                    } 
                    elseif ((float)$resultRaw > (float)$max) { 
                        $flag = 'HIGH'; 
                        $flagColor = array(200,0,0); // Red
                    } 
                    else { 
                        $flag = 'NORMAL'; 
                        $flagColor = array(0,128,0); // Green
                    }
                }

                // 2. Calculate Height needed
                $neededHeight = 15; // Base height for Name + Result
                if (!empty($t['interp'])) $neededHeight += 20; // Add space for interp
                $pdf->CheckPageBreak($neededHeight);

                // --- ROW 1: Test Name ---
                $pdf->SetFont('Arial','B',10);
                $pdf->Cell(0, 6, $testName, 0, 1);

                // --- ROW 2: Result | Range | Flag ---
                $pdf->SetFont('Arial','',10);
                
                $yBefore = $pdf->GetY();
                $xStart = $pdf->GetX();

                // Col 1: Label
                $pdf->SetFont('Arial','',9);
                $pdf->Cell(15, 5, 'Result:', 0, 0);

                // Col 2: The Value (Flexible Width)
                $pdf->SetFont('Arial','B',10);
                $pdf->SetXY($xStart + 15, $yBefore);
                
                // MultiCell prevents truncation of long text
                $pdf->MultiCell(90, 5, pdf_text($resultDisplay), 0, 'L');
                
                $yAfterResult = $pdf->GetY();

                // Move to Right Side for Reference Range
                $pdf->SetXY(120, $yBefore);
                $pdf->SetFont('Arial','',9);
                $pdf->Cell(25, 5, 'Ref. Range:', 0, 0);
                $pdf->Cell(30, 5, pdf_text($range), 0, 0);

                // Move to Far Right for Flag
                if($flag) {
                    $pdf->SetXY(175, $yBefore);
                    $pdf->SetTextColor($flagColor[0], $flagColor[1], $flagColor[2]);
                    $pdf->SetFont('Arial','B',8);
                    $pdf->Cell(20, 5, "[$flag]", 0, 0, 'R');
                    $pdf->SetTextColor(0);
                }

                $pdf->SetY($yAfterResult);

                // --- ROW 3: Interpretation (Attached Directly) ---
                if (!empty($t['interp'])) {
                    $pdf->Ln(1);
                    $pdf->SetX(15); 
                    $pdf->SetFont('Arial','BI',9); 
                    $pdf->Cell(30, 5, 'Interpretation:', 0, 1);
                    
                    $pdf->SetX(15);
                    $pdf->SetFont('Arial','',9); 
                    $pdf->MultiCell(0, 5, pdf_text($t['interp']), 0, 'L');
                    $pdf->Ln(2);
                } else {
                    $pdf->Ln(2); 
                }

                // --- SEPARATOR ---
                $pdf->SetDrawColor(240,240,240); 
                $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
                $pdf->Ln(2);
                $pdf->SetDrawColor(0); 
            }

            /* --- SIGNATURE --- */
            $pdf->CheckPageBreak(35);
            $pdf->Ln(5);
            $pdf->SetFont('Arial','B',9);
            $pdf->Cell(0,6,'Certified By',0,1);
            $pdf->SetFont('Arial','',9);
            
            $sig = $SIGNATURE_DIR.(int)$block['certified_by_id'].'.png';
            if (file_exists($sig)) {
                $info = getimagesize($sig);
                $h = (22 * $info[1]) / $info[0];
                $pdf->Image($sig, $pdf->GetX(), $pdf->GetY(), 22);
                $pdf->SetY($pdf->GetY()+$h);
            } else {
                $pdf->Ln(10);
            }
            $pdf->Cell(0,5,(!empty($block['certified_by'])?pdf_text($block['certified_by']):'Authorized Signatory'),0,1);
            if(!empty($block['certified_desc'])) $pdf->Cell(0,4,pdf_text($block['certified_desc']),0,1);
            
            $pdf->Ln(5);
            $pdf->SetFont('Arial','',8);
            $pdf->Cell(0,5,'--- End of Report ---',0,1,'C');
        }
    }
}
$pdf->Output();
?>
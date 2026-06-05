# NABL-Compliant Laboratory Information PDF Rendering System

**Bhabha Atomic Research Centre (BARC) — Medical Division**
---

## About This System

This system is deployed at the Pathology Laboratory of BARC Hospital, the primary healthcare facility of India's premier nuclear research institution. It interfaces directly with the Hospital Information System (HIS) database to produce cryptographically-structured, NABL-audit-compliant PDF diagnostic reports for pathology panels processed within the facility.

The system operates without any third-party framework dependency and is engineered to run on restricted, airgapped intranet infrastructure — a mandatory constraint for all internal IT systems at BARC. All computation, PDF construction, and database interaction occurs server-side in PHP, with zero client-side data exposure.

---

## Core Architecture

The system is composed of three independent report rendering engines (`nabl_report.php`, `nabl_report1.php`, `nabl_report2.php`) backed by a shared form entry interface (`nabl_report_form.php`) and the FPDF PDF generation library (`fpdf.php`).

Each rendering engine performs the following pipeline:

1. Input sanitization and validated parameter extraction from `$_POST` or `$_GET`
2. Prepared-statement SQL execution against the `nabl_import` MySQL database
3. Three-dimensional hierarchical result aggregation in-memory
4. Iterative PDF page construction via the FPDF engine
5. Binary PDF stream output directly to the HTTP response

---

## Technical Features

### Cross-Page Row Splitting with Recursive Line Decomposition

`nabl_report1.php` implements a custom `Row()` method on the FPDF class that solves the standard FPDF limitation of multi-line cells being silently truncated at page boundaries. The implementation:

- Mirrors the internal `NbLines()` character-width calculation using `CurrentFont['cw']` glyph metrics to compute exact line counts per column without rendering
- Computes the tallest column to determine the total required row height
- Compares `GetY() + rowHeight` against `PageBreakTrigger` before rendering
- When a row straddles a page boundary, it calculates `linesFit = floor(spaceLeft / lineHeight)` and slices each column's line array at that index
- Renders the partial row on the current page using manual `Rect()` borders (not `MultiCell` borders, which would misalign)
- Calls `AddPage()` and recursively invokes `Row()` on the remaining line slices

This ensures no data loss, no misaligned cell borders, and no phantom blank rows across any page boundary, regardless of content length.

---

### Three-Level Hierarchical Result Grouping

Raw result rows from the database are aggregated into a three-dimensional associative structure before any PDF output occurs:

```
$report[service_center_abbr][specimen_name][sample_date . '_' . sample_id]
    => [
        'tests'         => [ ...per-test result arrays... ],
        'dates'         => [ 'collected', 'received', 'reported' ],
        'certified_by'  => ...,
        'certified_desc'=> ...,
        'certified_by_id'=> ...,
        'referred_by'   => ...
       ]
```

This structure allows the system to correctly isolate repeat samples of the same specimen type collected on different dates — a clinically critical distinction when a patient undergoes multiple draws within one visit cycle. Each unique `sample_date + sample_id` combination produces a discrete PDF page group with its own timestamps and certifier attribution.

---

### Dynamic Signature Resolution via HIS User ID

The system resolves certifying doctor signatures at render time through a filesystem lookup keyed on `his_user.id`. The `result_certified_by` field from `lab_order` is cast to an integer and used to construct the path `signatures/{id}.png`.

`nabl_report1.php` performs additional validation before embedding:

```php
$info = @getimagesize($sig);
if ($info && $info[0] > 0) {
    $h = (22 * $info[1]) / $info[0];
    $pdf->Image($sig, $pdf->GetX(), $pdf->GetY(), 22);
}
```

- `getimagesize()` is called with error suppression to handle corrupt or zero-byte files gracefully
- The image height is computed proportionally from the known fixed width (22mm) using the pixel aspect ratio, ensuring no signature distortion across different source image dimensions
- If resolution fails at any step, the system falls back to the text string "Authorized Signatory" without interrupting PDF output

---

### UTF-8 to ISO-8859-1 Transliteration Pipeline

FPDF operates on ISO-8859-1 encoded strings. Clinical data stored in the HIS database is UTF-8 (`utf8mb4`), and frequently contains medical symbols outside the Latin-1 range. The `pdf_text()` function implements a two-stage sanitization pipeline:

**Stage 1 — Explicit symbol mapping:**

```php
$map = [
    '≥' => '>=', '≤' => '<=', 'μ' => 'u', '±' => '+/-',
    '–' => '-',  '—' => '-',  ''' => "'", '"' => '"',
    '"' => '"',  '°' => ' deg ', 'α' => 'alpha', 'β' => 'beta'
];
$str = strtr($str, $map);
```

**Stage 2 — iconv transliteration with IGNORE fallback:**

```php
return iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $str);
```

`//TRANSLIT` attempts best-fit character substitution; `//IGNORE` silently drops any character that cannot be mapped, preventing `iconv()` from returning `false` and injecting a null into the PDF cell, which would corrupt surrounding text layout.

---

### Output Buffer Management for Binary Stream Integrity

PHP's output buffering system will corrupt a PDF binary stream if any content — including PHP warnings, notices, or whitespace — is emitted before the `Content-Type: application/pdf` header. `nabl_report1.php` explicitly clears any pre-existing output buffer on load:

```php
if (ob_get_level()) ob_end_clean();
ini_set('display_errors', 0);
```

`display_errors` is set to `0` while `error_reporting` is set to `E_ALL`, meaning errors are logged to the server error log but never written to the response stream. This is the correct production configuration for any PHP script that outputs binary content.

---

### Execution Guard for Large Result Sets

`nabl_report2.php` implements a static `ProductionGuard` class to protect against unbounded execution on large date ranges:

```php
class ProductionGuard {
    private static $timers = [];
    public static function checkTime($seconds = 280) {
        if (empty(self::$timers['start'])) self::$timers['start'] = microtime(true);
        if ((microtime(true) - self::$timers['start']) > $seconds) {
            die("Error: Timeout. The date range is too large.");
        }
    }
}
```

`checkTime()` is called every 100 database rows during the fetch loop and once per outer report block during rendering. The guard uses `microtime(true)` for sub-second precision and terminates with a clean error message before Apache's or PHP-FPM's `max_execution_time` causes an uncontrolled process kill.

---

### Prepared Statement SQL Execution Across Five Joined Tables

All database queries use MySQLi prepared statements with typed parameter binding. The primary result fetch query joins five tables in a single execution:

```sql
SELECT
    lo.service_center_abbr, sm.name, lsm.name, lo.result_value,
    lsm.unit_abbr, lo.normal_range_from, lo.normal_range_to,
    lo.sample_id, lo.sample_generated_date_time,
    lo.sample_received_date_time, lo.result_certified_date_time,
    DATE(lo.result_certified_date_time), lsm.interpretive_text,
    ru.user_name, cu.user_name, cu.user_desc, lo.result_certified_by,
    lsm.test_method, lo.ext_remarks
FROM lab_order lo
LEFT JOIN lab_service_master lsm ON lsm.id = lo.lab_service_id
LEFT JOIN specimen_master sm ON sm.id = lo.specimen_id
LEFT JOIN his_user ru ON ru.id = lo.his_user_id
LEFT JOIN his_user cu ON cu.id = lo.result_certified_by
WHERE lo.patient_id = ?
AND lo.result_certified_date_time >= ?
AND lo.result_certified_date_time < DATE_ADD(?, INTERVAL 1 DAY)
AND lo.result_certified_date_time IS NOT NULL
ORDER BY lo.service_center_abbr, sm.name,
         lo.result_certified_date_time DESC, lsm.sort_id
```

`his_user` is joined twice with distinct aliases (`ru` for referring, `cu` for certifying) to resolve both doctor attributions in a single pass. The date range condition uses `DATE_ADD(?, INTERVAL 1 DAY)` on the `to_date` parameter to include results certified at any time on the end date, avoiding a common off-by-one error when filtering by `DATETIME` columns with a `DATE` boundary.

---

## Deployment Requirements

- PHP 5.6 or later (tested on Ubuntu with PHP 5.6; compatible with PHP 7.x and 8.x)
- MySQL 5.6+ or MariaDB 10+
- PHP extensions: `mysqli`, `iconv`, `gd`
- Apache or Nginx on Linux; XAMPP acceptable for Windows-based deployments
- Web root access to `/signatures/` directory for runtime signature resolution
- No internet access required — fully self-contained

---

## Security Constraints

- All SQL parameters are bound via `bind_param()` — no dynamic string interpolation in queries
- The application is intended for deployment exclusively on the BARC Hospital intranet; no public internet exposure is sanctioned
- Database credentials must be externalized to a server-side configuration file outside the document root in any hardened deployment
- The `/signatures/` directory must be restricted from direct HTTP access via `.htaccess` or Nginx `location` directives

---

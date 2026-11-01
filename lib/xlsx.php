<?php declare(strict_types=1);
/**
 * Email Case Workspace — a small .xlsx writer (one sheet), for the assistant's
 * spreadsheets (lib/ai_actions.php). No library: an .xlsx is a zip of XML.
 *
 * The header row is bold, frozen and filterable; numbers stay numbers (sums
 * work in Excel), dates "YYYY-MM-DD[ HH:MM]" become real dates; text is text.
 * Opens in Excel, LibreOffice and Google Sheets.
 */

/** Excel's serial day number for a date (1900 system). */
function ecw_xlsx_serial(string $v): ?float
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $v, $m)) return null;
    $t = gmmktime((int)($m[4] ?? 0), (int)($m[5] ?? 0), (int)($m[6] ?? 0), (int)$m[2], (int)$m[3], (int)$m[1]);
    if ($t === false) return null;
    return $t / 86400 + 25569;
}

function ecw_xlsx_col(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

/**
 * @param list<string> $headers
 * @param list<list<scalar|null>> $rows
 */
function ecw_xlsx_write(string $file, string $sheetName, array $headers, array $rows): void
{
    $x = static fn($v): string => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $sheetName = mb_substr(trim((string)preg_replace('/[\[\]\*\?\/\\\\:]/u', ' ', $sheetName)) ?: 'Sheet1', 0, 31);
    $cols = max(1, count($headers));
    $widths = array_fill(0, $cols, 10);
    $xml = '';
    $all = array_merge([$headers], $rows);
    foreach ($all as $r => $row) {
        $xml .= '<row r="' . ($r + 1) . '">';
        for ($c = 0; $c < $cols; $c++) {
            $v = $row[$c] ?? null;
            if ($v === null || $v === '') continue;
            $ref = ecw_xlsx_col($c) . ($r + 1);
            $len = mb_strlen((string)$v);
            $widths[$c] = min(60, max($widths[$c], $len + 2));
            if ($r === 0) { $xml .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . $x($v) . '</t></is></c>'; continue; }
            if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d{1,15}(\.\d{1,6})?$/', $v) && !preg_match('/^0\d/', $v))) {
                $xml .= '<c r="' . $ref . '" s="3"><v>' . (0 + $v) . '</v></c>';
            } elseif (is_string($v) && ($serial = ecw_xlsx_serial($v)) !== null) {
                $xml .= '<c r="' . $ref . '" s="' . (strlen($v) > 10 ? 4 : 2) . '"><v>' . $serial . '</v></c>';
                $widths[$c] = max($widths[$c], strlen($v) > 10 ? 17 : 12);
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $x($v) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    $colsXml = '';
    foreach ($widths as $i => $w) $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    $last = ecw_xlsx_col($cols - 1) . max(1, count($all));
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols>' . $colsXml . '</cols><sheetData>' . $xml . '</sheetData>'
        . '<autoFilter ref="A1:' . $last . '"/></worksheet>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/><numFmt numFmtId="165" formatCode="yyyy-mm-dd hh:mm"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8EEF7"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('cannot write the spreadsheet');
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . $x($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . str_replace("'", "''", $x($sheetName)) . '\'!$A$1:$' . preg_replace('/\d+$/', '', $last) . '$' . preg_replace('/^[A-Z]+/', '', $last) . '</definedName></definedNames></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->addFromString('xl/styles.xml', $styles);
    if (!$zip->close()) throw new RuntimeException('cannot write the spreadsheet');
}

/** The same table as CSV for Excel: UTF-8 with BOM (Greek intact), ";" when the workspace uses a decimal comma. */
function ecw_csv_write(string $file, array $headers, array $rows, string $sep = ','): void
{
    $out = fopen($file, 'wb');
    if ($out === false) throw new RuntimeException('cannot write the file');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, $sep, '"', '');
    foreach ($rows as $r) fputcsv($out, array_map(static fn($v) => $v === null ? '' : (string)$v, $r), $sep, '"', '');
    fclose($out);
}

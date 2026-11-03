<?php

namespace App\Services\Hrms;

use ZipArchive;

class PayrollTemplate
{
    /**
     * @var list<string>
     */
    public const HEADERS = [
        'Employee ID',
        'Employee Name',
        'Basic Salary',
        'HRA',
        'Special Allowance',
        'Other Earnings',
        'Gross Salary',
        'PF',
        'ESI',
        'Professional Tax',
        'TDS',
        'Other Deductions',
        'Total Deductions',
        'Net Salary',
    ];

    /**
     * @return array{name: string, type: string, body: string}
     */
    public function download(string $format): array
    {
        if ($format === 'csv') {
            return [
                'name' => 'Meetara_India_Payroll_Template.csv',
                'type' => 'text/csv; charset=UTF-8',
                'body' => $this->csv(),
            ];
        }

        return [
            'name' => 'Meetara_India_Payroll_Template.xlsx',
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'body' => $this->xlsx(),
        ];
    }

    private function csv(): string
    {
        $lines = ["\xEF\xBB\xBF".$this->csvLine(self::HEADERS)];

        foreach ($this->samples() as $row) {
            $lines[] = $this->csvLine($row);
        }

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * @param  list<string>  $cells
     */
    private function csvLine(array $cells): string
    {
        return implode(',', array_map(fn (string $cell): string => '"'.str_replace('"', '""', $cell).'"', $cells));
    }

    private function xlsx(): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new PayrollRejected('The Excel template cannot be created on this server. Download the CSV template instead.');
        }

        $strings = [];
        $index = [];
        $remember = function (string $value) use (&$strings, &$index): int {
            if (! isset($index[$value])) {
                $index[$value] = count($strings);
                $strings[] = $value;
            }

            return $index[$value];
        };

        $sheetRows = '';
        $line = 1;

        foreach ([self::HEADERS, ...$this->samples()] as $row) {
            $cells = '';

            foreach ($row as $column => $value) {
                $reference = $this->column($column).$line;
                $header = $line === 1;
                $money = ! $header && $column >= 2;

                if ($money) {
                    $cells .= '<c r="'.$reference.'" s="3"><v>'.$value.'</v></c>';

                    continue;
                }

                $style = $header ? ($column === 0 || $column === 13 ? '1' : '2') : '0';
                $cells .= '<c r="'.$reference.'" t="s" s="'.$style.'"><v>'.$remember($value).'</v></c>';
            }

            $sheetRows .= '<row r="'.$line.'">'.$cells.'</row>';
            $line++;
        }

        $instructionRows = '';
        $instructionLine = 1;

        foreach ($this->instructions() as $text) {
            $instructionRows .= '<row r="'.$instructionLine.'"><c r="A'.$instructionLine.'" t="s"><v>'.$remember($text).'</v></c></row>';
            $instructionLine++;
        }

        $shared = '';

        foreach ($strings as $value) {
            $shared .= '<si><t xml:space="preserve">'.htmlspecialchars($value, ENT_XML1).'</t></si>';
        }

        $path = tempnam(sys_get_temp_dir(), 'payroll-template');

        if ($path === false) {
            throw new PayrollRejected('The Excel template could not be created.');
        }

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Payroll Data" sheetId="1" r:id="rId1"/><sheet name="Instructions" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">'.$shared.'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="2" width="24" customWidth="1"/><col min="3" max="14" width="18" customWidth="1"/></cols><sheetData>'.$sheetRows.'</sheetData><autoFilter ref="A1:N3"/></worksheet>');
        $zip->addFromString('xl/worksheets/sheet2.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="120" customWidth="1"/></cols><sheetData>'.$instructionRows.'</sheetData></worksheet>');
        $zip->close();
        $body = file_get_contents($path);
        unlink($path);

        if (! is_string($body) || $body === '') {
            throw new PayrollRejected('The Excel template could not be created.');
        }

        return $body;
    }

    /**
     * @return list<list<string>>
     */
    private function samples(): array
    {
        return [
            ['SAMPLE-001', 'Sample Employee A', '30000.00', '12000.00', '5000.00', '2000.00', '49000.00', '1800.00', '368.00', '200.00', '1500.00', '500.00', '4368.00', '44632.00'],
            ['SAMPLE-002', 'Sample Employee B', '25000.00', '10000.00', '4000.00', '1000.00', '40000.00', '1800.00', '300.00', '200.00', '1000.00', '200.00', '3500.00', '36500.00'],
        ];
    }

    /**
     * @return list<string>
     */
    private function instructions(): array
    {
        return [
            'Meetara India payroll template',
            'Remove both sample rows on Payroll Data before uploading a real file. SAMPLE-001 and SAMPLE-002 are examples only and are not employees.',
            'Meetara stores the amounts in the file. It does not calculate salary, deductions, or payments.',
            'The payroll month is the year and month selected on the Payroll page. This workbook does not contain a month column.',
            'Employee ID is required. It must match the employee ID in Meetara for this company. Names are not used for matching.',
            'Employee Name is optional. If it does not match the employee record, Meetara keeps the record name on the payslip and shows a warning.',
            'Net Salary is required. Enter the final payable amount already calculated outside Meetara.',
            'Basic Salary, HRA, Special Allowance, Other Earnings, and Gross Salary are optional. When all five are present, gross must equal basic + HRA + special allowance + other earnings.',
            'PF, ESI, Professional Tax, TDS, Other Deductions, and Total Deductions are optional. When all six are present, total deductions must equal the five deduction columns.',
            'When Gross Salary, Total Deductions, and Net Salary are all present, net must equal gross minus total deductions.',
            'Use numbers with up to two decimal places. Do not leave Employee ID or Net Salary blank.',
            'Each Employee ID may appear only once. A repeated ID is shown as an error and is not imported.',
            'Rows with errors stay visible. Correct the Excel file and upload it again. Nothing is confirmed until HR or Accounts confirms the preview.',
            'A month that already has a confirmed payroll import cannot be uploaded again.',
            'Mandatory columns are highlighted in amber: Employee ID and Net Salary. The other columns are optional.',
        ];
    }

    private function column(int $index): string
    {
        $letters = '';
        $index++;

        while ($index > 0) {
            $index--;
            $letters = chr(65 + ($index % 26)).$letters;
            $index = intdiv($index, 26);
        }

        return $letters;
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'</Types>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="₹#,##0.00"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFDE68A"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'</styleSheet>';
    }
}

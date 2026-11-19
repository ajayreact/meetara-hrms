<?php

namespace App\Services\Hrms;

use ZipArchive;

class StandaloneIdCardImportTemplate
{
    /**
     * @var list<string>
     */
    public const HEADERS = [
        'Full Name',
        'Card Number',
        'Designation',
        'Department',
        'Email',
        'Phone Number',
        'Date of Birth',
        'Blood Group',
        'Emergency Contact',
        'Country',
    ];

    /**
     * @return array{name: string, type: string, body: string}
     */
    public function download(): array
    {
        return [
            'name' => 'Veltrix_Standalone_ID_Card_Import_Template.xlsx',
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'body' => $this->xlsx(),
        ];
    }

    private function xlsx(): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new StandaloneIdCardImportRejected('The Excel template cannot be created on this server.');
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
                $style = $line === 1 ? ($column === 0 || $column === 8 ? '1' : '2') : '0';
                $cells .= '<c r="'.$reference.'" t="s" s="'.$style.'"><v>'.$remember((string) $value).'</v></c>';
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

        $path = tempnam(sys_get_temp_dir(), 'standalone-id-template');

        if ($path === false) {
            throw new StandaloneIdCardImportRejected('The Excel template could not be created.');
        }

        try {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::OVERWRITE);
            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/><sheet name="Instructions" sheetId="2" r:id="rId2"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>');
            $zip->addFromString('xl/styles.xml', $this->styles());
            $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">'.$shared.'</sst>');
            $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="10" width="22" customWidth="1"/></cols><sheetData>'.$sheetRows.'</sheetData><autoFilter ref="A1:J3"/></worksheet>');
            $zip->addFromString('xl/worksheets/sheet2.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="120" customWidth="1"/></cols><sheetData>'.$instructionRows.'</sheetData></worksheet>');
            $zip->close();
            $body = file_get_contents($path);
        } finally {
            @unlink($path);
        }

        if (! is_string($body) || $body === '') {
            throw new StandaloneIdCardImportRejected('The Excel template could not be created.');
        }

        return $body;
    }

    /**
     * @return list<list<string>>
     */
    private function samples(): array
    {
        return [
            ['Priya Sample', 'STC-SAMPLE-01', 'Consultant', 'Operations', 'priya.sample@example.test', '+91-9000000100', '1994-06-15', 'O+', '+91-9000000101', 'India'],
            ['Alex Sample', '', 'Contractor', 'Facilities', 'alex.sample@example.test', '+1-555-0100', '1990-01-20', 'B+', '+1-555-0101', 'USA'],
        ];
    }

    /**
     * @return list<string>
     */
    private function instructions(): array
    {
        return [
            'Veltrix HRMS standalone ID card import template',
            'Remove both sample rows on Sheet1 before uploading a real file.',
            'Required columns: Full Name, Designation, Department, Blood Group, Emergency Contact, Country.',
            'Card Number is optional. Leave it blank to auto-generate a unique card number for this company.',
            'Date of Birth is optional in the sheet. Use YYYY-MM-DD. A card cannot be issued until a date of birth is saved.',
            'Country must be India or USA. The matching company profile must already be saved under ID card settings.',
            'Blood Group must be one of: A+, A-, B+, B-, AB+, AB-, O+, O-, Unknown.',
            'Photos are not imported. After confirmation, open each draft card and upload a photo before issuing.',
            'Duplicate card numbers in the file or already used by this company are rejected.',
            'Only Sheet1 is imported. Instructions sheet is ignored.',
            'This import never creates or changes employee, payroll, or attendance records.',
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
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFDE68A"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'</cellXfs>'
            .'</styleSheet>';
    }
}

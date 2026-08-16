<?php

declare(strict_types=1);

namespace OCA\Tickets\Service;

use RuntimeException;
use ZipArchive;

/**
 * Générateur minimaliste de fichiers .xlsx (une seule feuille, cellules texte).
 *
 * Nextcloud n'embarque aucune librairie de tableur (pas de PhpSpreadsheet dans
 * le socle), donc plutôt que d'ajouter une dépendance Composer lourde pour un
 * simple export de sauvegarde, on construit à la main le strict nécessaire du
 * format OOXML : un classeur avec une feuille, toutes les cellules en texte
 * (t="inlineStr"). Le fichier obtenu s'ouvre normalement dans Excel/LibreOffice.
 */
class XlsxWriter {
    /**
     * @param string[] $headers
     * @param array<int, array<int, string>> $rows
     */
    public function build(array $headers, array $rows): string {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tickets_xlsx_');
        if ($tmpFile === false) {
            throw new RuntimeException('Unable to create temporary file');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            unlink($tmpFile);
            throw new RuntimeException('Unable to create xlsx archive');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->relsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml($headers, $rows));
        $zip->close();

        $content = file_get_contents($tmpFile);
        unlink($tmpFile);

        return $content === false ? '' : $content;
    }

    private function contentTypesXml(): string {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML;
    }

    private function relsXml(): string {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;
    }

    private function workbookXml(): string {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Tickets" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML;
    }

    private function workbookRelsXml(): string {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML;
    }

    /**
     * Premiers caractères qu'Excel/LibreOffice/Sheets interprètent comme le début
     * d'une formule si on les laisse en tête d'une cellule texte, même déclarée
     * t="inlineStr" : le contenu affiché prime alors sur le type de cellule dès
     * l'ouverture du fichier (ex. "=cmd|'/c calc'!A1" exécuté via DDE, ou une
     * formule pointant vers une donnée d'un autre onglet). Toutes les valeurs
     * exportées proviennent de champs saisis par les utilisateurs (titre,
     * description, commentaires...), donc potentiellement hostiles.
     */
    private const FORMULA_TRIGGER_CHARS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Neutralise une valeur de cellule pouvant être interprétée comme une formule
     * par le tableur à l'ouverture (voir FORMULA_TRIGGER_CHARS) : préfixée d'une
     * apostrophe, comme le ferait un utilisateur saisissant la valeur à la main
     * pour forcer une interprétation en texte brut.
     */
    private function sanitizeCellValue(string $value): string {
        if ($value !== '' && in_array($value[0], self::FORMULA_TRIGGER_CHARS, true)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * @param string[] $headers
     * @param array<int, array<int, string>> $rows
     */
    private function sheetXml(array $headers, array $rows): string {
        $allRows = array_merge([$headers], $rows);
        $xmlRows = '';
        foreach ($allRows as $rowIndex => $row) {
            $rowNum = $rowIndex + 1;
            $cells = '';
            foreach (array_values($row) as $colIndex => $value) {
                $ref = $this->columnLetter($colIndex) . $rowNum;
                $value = $this->sanitizeCellValue((string) $value);
                $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $escaped . '</t></is></c>';
            }
            $xmlRows .= '<row r="' . $rowNum . '">' . $cells . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $xmlRows . '</sheetData>'
            . '</worksheet>';
    }

    private function columnLetter(int $index): string {
        $letter = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - 1, 26);
        }
        return $letter;
    }
}

<?php

class DmarcParser {

    /**
     * Parse a DMARC aggregate report file (.xml, .xml.gz, .zip).
     * Returns an array with keys 'report' and 'records'.
     */
    public static function parseFile(string $path): array {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $xml = match (true) {
            $ext === 'gz'  => self::readGzip($path),
            $ext === 'zip' => self::readZip($path),
            default        => file_get_contents($path),
        };

        if ($xml === false || $xml === '') {
            throw new RuntimeException("Could not read file: $path");
        }

        return self::parseXml($xml);
    }

    private static function readGzip(string $path): string {
        $handle = gzopen($path, 'rb');
        if (!$handle) {
            throw new RuntimeException("Cannot open gzip file: $path");
        }
        $content = '';
        while (!gzeof($handle)) {
            $content .= gzread($handle, 65536);
        }
        gzclose($handle);
        return $content;
    }

    private static function readZip(string $path): string {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Cannot open ZIP file: $path");
        }
        // Find the first XML file inside the ZIP
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_ends_with(strtolower($name), '.xml')) {
                $content = $zip->getFromIndex($i);
                $zip->close();
                return $content;
            }
        }
        $zip->close();
        throw new RuntimeException("No XML file found inside ZIP: $path");
    }

    public static function parseXml(string $xml): array {
        libxml_use_internal_errors(true);
        $dom = simplexml_load_string($xml);
        if ($dom === false) {
            $errors = array_map(fn($e) => $e->message, libxml_get_errors());
            libxml_clear_errors();
            throw new RuntimeException("XML parse error: " . implode('; ', $errors));
        }

        // --- Report metadata ---
        $meta   = $dom->report_metadata;
        $policy = $dom->policy_published;

        $reportData = [
            ':report_id'  => self::str($meta->report_id),
            ':org_name'   => self::str($meta->org_name),
            ':org_email'  => self::str($meta->email),
            ':domain'     => self::str($policy->domain),
            ':date_begin' => (int)($meta->date_range->begin ?? 0),
            ':date_end'   => (int)($meta->date_range->end   ?? 0),
            ':policy_p'   => self::str($policy->p)   ?: 'none',
            ':policy_sp'  => self::str($policy->sp)  ?: 'none',
            ':policy_pct' => (int)($policy->pct ?? 100),
            ':adkim'      => self::str($policy->adkim) ?: 'r',
            ':aspf'       => self::str($policy->aspf)  ?: 'r',
        ];

        // --- Records ---
        $records = [];
        foreach ($dom->record as $rec) {
            $row       = $rec->row;
            $ids       = $rec->identifiers;
            $auth      = $rec->auth_results;

            // There may be multiple DKIM results; take the first
            $dkimAuth  = $auth->dkim ?? null;
            $spfAuth   = $auth->spf  ?? null;

            $records[] = [
                ':source_ip'          => self::str($row->source_ip),
                ':count'              => (int)($row->count ?? 1),
                ':disposition'        => self::str($row->policy_evaluated->disposition),
                ':dkim_result'        => self::str($row->policy_evaluated->dkim),
                ':spf_result'         => self::str($row->policy_evaluated->spf),
                ':header_from'        => self::str($ids->header_from),
                ':envelope_to'        => self::str($ids->envelope_to),
                ':auth_dkim_domain'   => $dkimAuth ? self::str($dkimAuth->domain)   : '',
                ':auth_dkim_result'   => $dkimAuth ? self::str($dkimAuth->result)   : '',
                ':auth_dkim_selector' => $dkimAuth ? self::str($dkimAuth->selector) : '',
                ':auth_spf_domain'    => $spfAuth  ? self::str($spfAuth->domain)    : '',
                ':auth_spf_result'    => $spfAuth  ? self::str($spfAuth->result)    : '',
            ];
        }

        return ['report' => $reportData, 'records' => $records];
    }

    private static function str(?SimpleXMLElement $el): string {
        return $el !== null ? trim((string)$el) : '';
    }
}

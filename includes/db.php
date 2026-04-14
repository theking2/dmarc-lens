<?php

class Database {
    private PDO $pdo;
    private static ?Database $instance = null;

    private function __construct() {
        $dbPath = __DIR__ . '/../data/dmarc.sqlite';
        if (!is_dir(dirname($dbPath))) {
            mkdir(dirname($dbPath), 0755, true);
        }
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->migrate();
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function pdo(): PDO {
        return $this->pdo;
    }

    private function migrate(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS reports (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                report_id   TEXT    UNIQUE,
                org_name    TEXT,
                org_email   TEXT,
                domain      TEXT,
                date_begin  INTEGER,
                date_end    INTEGER,
                policy_p    TEXT,
                policy_sp   TEXT,
                policy_pct  INTEGER DEFAULT 100,
                adkim       TEXT,
                aspf        TEXT,
                uploaded_at INTEGER DEFAULT (strftime('%s','now'))
            );

            CREATE TABLE IF NOT EXISTS records (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                report_db_id        INTEGER NOT NULL,
                source_ip           TEXT,
                count               INTEGER DEFAULT 1,
                disposition         TEXT,
                dkim_result         TEXT,
                spf_result          TEXT,
                header_from         TEXT,
                envelope_to         TEXT,
                auth_dkim_domain    TEXT,
                auth_dkim_result    TEXT,
                auth_dkim_selector  TEXT,
                auth_spf_domain     TEXT,
                auth_spf_result     TEXT,
                FOREIGN KEY(report_db_id) REFERENCES reports(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_records_report ON records(report_db_id);
            CREATE INDEX IF NOT EXISTS idx_records_ip     ON records(source_ip);
            CREATE INDEX IF NOT EXISTS idx_reports_domain ON reports(domain);
        ");
    }

    public function getStats(): array {
        $row = $this->pdo->query("
            SELECT
                COUNT(DISTINCT r.id)                                        AS total_reports,
                COUNT(DISTINCT r.domain)                                    AS total_domains,
                COALESCE(SUM(rc.count), 0)                                  AS total_messages,
                COALESCE(SUM(CASE WHEN rc.dkim_result='pass' AND rc.spf_result='pass' THEN rc.count ELSE 0 END), 0) AS fully_aligned,
                COALESCE(SUM(CASE WHEN rc.dkim_result='pass' OR  rc.spf_result='pass' THEN rc.count ELSE 0 END), 0) AS partial_pass,
                COALESCE(SUM(CASE WHEN rc.dkim_result!='pass' AND rc.spf_result!='pass' THEN rc.count ELSE 0 END), 0) AS full_fail
            FROM reports r
            LEFT JOIN records rc ON rc.report_db_id = r.id
        ")->fetch();

        return $row;
    }

    public function getDomainSummary(): array {
        return $this->pdo->query("
            SELECT
                r.domain,
                COUNT(DISTINCT r.id)                                        AS report_count,
                COALESCE(SUM(rc.count), 0)                                  AS total_messages,
                COALESCE(SUM(CASE WHEN rc.dkim_result='pass' AND rc.spf_result='pass' THEN rc.count ELSE 0 END), 0) AS pass_count,
                COALESCE(SUM(CASE WHEN rc.dkim_result!='pass' AND rc.spf_result!='pass' THEN rc.count ELSE 0 END), 0) AS fail_count,
                MAX(r.date_end)                                             AS last_report
            FROM reports r
            LEFT JOIN records rc ON rc.report_db_id = r.id
            GROUP BY r.domain
            ORDER BY total_messages DESC
        ")->fetchAll();
    }

    public function getRecentReports(int $limit = 20): array {
        $stmt = $this->pdo->prepare("
            SELECT
                r.*,
                COUNT(rc.id)                AS record_count,
                COALESCE(SUM(rc.count), 0)  AS total_messages,
                COALESCE(SUM(CASE WHEN rc.dkim_result='pass' AND rc.spf_result='pass' THEN rc.count ELSE 0 END), 0) AS pass_count
            FROM reports r
            LEFT JOIN records rc ON rc.report_db_id = r.id
            GROUP BY r.id
            ORDER BY r.date_end DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    public function getReport(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM reports WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function getReportRecords(int $reportDbId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM records WHERE report_db_id = ? ORDER BY count DESC");
        $stmt->execute([$reportDbId]);
        return $stmt->fetchAll();
    }

    public function insertReport(array $data): int {
        $stmt = $this->pdo->prepare("
            INSERT OR IGNORE INTO reports
                (report_id, org_name, org_email, domain, date_begin, date_end,
                 policy_p, policy_sp, policy_pct, adkim, aspf)
            VALUES
                (:report_id, :org_name, :org_email, :domain, :date_begin, :date_end,
                 :policy_p, :policy_sp, :policy_pct, :adkim, :aspf)
        ");
        $stmt->execute($data);

        if ($this->pdo->lastInsertId() == 0) {
            // Already existed — return existing id
            $s = $this->pdo->prepare("SELECT id FROM reports WHERE report_id = ?");
            $s->execute([$data[':report_id']]);
            $row = $s->fetch();
            return $row ? (int)$row['id'] : 0;
        }
        return (int)$this->pdo->lastInsertId();
    }

    public function insertRecord(array $data): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO records
                (report_db_id, source_ip, count, disposition, dkim_result, spf_result,
                 header_from, envelope_to, auth_dkim_domain, auth_dkim_result,
                 auth_dkim_selector, auth_spf_domain, auth_spf_result)
            VALUES
                (:report_db_id, :source_ip, :count, :disposition, :dkim_result, :spf_result,
                 :header_from, :envelope_to, :auth_dkim_domain, :auth_dkim_result,
                 :auth_dkim_selector, :auth_spf_domain, :auth_spf_result)
        ");
        $stmt->execute($data);
    }

    public function deleteReport(int $id): void {
        $stmt = $this->pdo->prepare("DELETE FROM reports WHERE id = ?");
        $stmt->execute([$id]);
    }
}

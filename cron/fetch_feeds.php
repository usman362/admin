<?php
/**
 * cron/fetch_feeds.php
 * 
 * Run via cron every 30-60 minutes:
 *   php /path/to/osint-dashboard/cron/fetch_feeds.php
 * 
 * Or add to crontab:
 *   */30 * * * * php /var/www/html/osint-dashboard/cron/fetch_feeds.php >> /var/www/html/osint-dashboard/data/cron.log 2>&1
 */

require_once __DIR__ . '/../config.php';

class OSINTFetcher {

    private PDO $db;
    private array $suppliers  = [];
    private array $keywords   = [];
    private array $sources    = [];
    private int   $alertsCreated = 0;
    private array $errors     = [];
    private float $startTime;

    public function __construct() {
        $this->db        = getDB();
        $this->startTime = microtime(true);
        $this->loadSuppliers();
        $this->loadKeywords();
        $this->loadSources();
    }

    // -----------------------------------------------
    // Load active suppliers + their aliases
    // -----------------------------------------------
    private function loadSuppliers(): void {
        $rows = $this->db->query(
            'SELECT id, name, aliases FROM suppliers WHERE active = 1'
        )->fetchAll();

        foreach ($rows as $row) {
            $terms = [strtolower(trim($row['name']))];
            if (!empty($row['aliases'])) {
                foreach (explode(',', $row['aliases']) as $alias) {
                    $a = strtolower(trim($alias));
                    if ($a !== '') $terms[] = $a;
                }
            }
            $this->suppliers[] = [
                'id'    => (int)$row['id'],
                'name'  => $row['name'],
                'terms' => $terms,
            ];
        }
    }

    // -----------------------------------------------
    // Load active keywords
    // -----------------------------------------------
    private function loadKeywords(): void {
        $rows = $this->db->query(
            'SELECT keyword, risk_type, risk_level FROM keywords WHERE active = 1'
        )->fetchAll();

        foreach ($rows as $row) {
            $this->keywords[] = $row;
        }
    }

    // -----------------------------------------------
    // Load active sources due for refresh
    // -----------------------------------------------
    private function loadSources(): void {
        $this->sources = $this->db->query(
            'SELECT * FROM sources 
             WHERE active = 1 
               AND (last_fetched IS NULL 
                    OR last_fetched < NOW() - INTERVAL fetch_interval MINUTE)
             ORDER BY last_fetched ASC'
        )->fetchAll();
    }

    // -----------------------------------------------
    // Main run loop
    // -----------------------------------------------
    public function run(): void {
        echo "[" . date('Y-m-d H:i:s') . "] OSINT Fetcher started. Sources due: " . count($this->sources) . "\n";

        foreach ($this->sources as $source) {
            $itemsFetched = 0;
            try {
                $items = $this->fetchRSS($source['url']);
                $itemsFetched = count($items);
                echo "  Source: {$source['name']} — {$itemsFetched} items\n";

                foreach ($items as $item) {
                    $this->processItem($item, $source);
                }

                // Update last_fetched
                $stmt = $this->db->prepare(
                    'UPDATE sources SET last_fetched = NOW() WHERE id = ?'
                );
                $stmt->execute([$source['id']]);

            } catch (Exception $e) {
                $errMsg = "Error on [{$source['name']}]: " . $e->getMessage();
                echo "  ⚠ $errMsg\n";
                $this->errors[] = $errMsg;
            }

            // Log cron run per source
            $duration = (int)((microtime(true) - $this->startTime) * 1000);
            $stmt = $this->db->prepare(
                'INSERT INTO cron_log (source_name, items_fetched, alerts_created, errors, duration_ms)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $source['name'],
                $itemsFetched,
                $this->alertsCreated,
                implode('; ', $this->errors),
                $duration,
            ]);
        }

        echo "[" . date('Y-m-d H:i:s') . "] Done. Alerts created: {$this->alertsCreated}\n";
        $this->cleanOldAlerts();
        $this->sendPendingNotifications();
    }

    // -----------------------------------------------
    // Fetch & parse RSS feed
    // -----------------------------------------------
    private function fetchRSS(string $url): array {
        $ctx = stream_context_create([
            'http' => [
                'timeout'       => 20,
                'user_agent'    => 'OSINT-Dashboard/1.0 (+https://github.com/your-org/osint-dashboard)',
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $xml = @file_get_contents($url, false, $ctx);
        if ($xml === false || empty($xml)) {
            throw new RuntimeException("Could not fetch URL: $url");
        }

        // Suppress malformed XML warnings
        libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml);
        libxml_clear_errors();

        if ($feed === false) {
            throw new RuntimeException("Failed to parse XML from: $url");
        }

        $items = [];

        // Handle standard RSS 2.0
        if (isset($feed->channel->item)) {
            foreach ($feed->channel->item as $item) {
                $items[] = $this->normalizeRSSItem($item);
            }
        }
        // Handle Atom feeds
        elseif (isset($feed->entry)) {
            foreach ($feed->entry as $entry) {
                $items[] = $this->normalizeAtomEntry($entry);
            }
        }

        return $items;
    }

    private function normalizeRSSItem($item): array {
        $link = (string)($item->link ?? '');
        // Some feeds use <guid> as the link
        if (empty($link) && isset($item->guid)) {
            $link = (string)$item->guid;
        }
        return [
            'title'       => (string)($item->title ?? ''),
            'description' => strip_tags((string)($item->description ?? '')),
            'link'        => $link,
            'date'        => (string)($item->pubDate ?? date('r')),
        ];
    }

    private function normalizeAtomEntry($entry): array {
        $link = '';
        if (isset($entry->link)) {
            $link = (string)($entry->link['href'] ?? $entry->link ?? '');
        }
        return [
            'title'       => (string)($entry->title ?? ''),
            'description' => strip_tags((string)($entry->summary ?? $entry->content ?? '')),
            'link'        => $link,
            'date'        => (string)($entry->published ?? $entry->updated ?? date('c')),
        ];
    }

    // -----------------------------------------------
    // Process a single feed item
    // -----------------------------------------------
    private function processItem(array $item, array $source): void {
        $text = strtolower($item['title'] . ' ' . $item['description']);

        // Skip if already in DB (dedup by link)
        if (!empty($item['link'])) {
            $stmt = $this->db->prepare('SELECT id FROM alerts WHERE link = ? LIMIT 1');
            $stmt->execute([$item['link']]);
            if ($stmt->fetch()) return;
        }

        // Match keywords
        $matchedKeywords = [];
        $riskType  = 'Other';
        $riskLevel = 'Low';

        foreach ($this->keywords as $kw) {
            if (strpos($text, strtolower($kw['keyword'])) !== false) {
                $matchedKeywords[] = $kw['keyword'];
                // Escalate risk level
                if ($kw['risk_level'] === 'High') {
                    $riskLevel = 'High';
                    $riskType  = $kw['risk_type'];
                } elseif ($kw['risk_level'] === 'Medium' && $riskLevel !== 'High') {
                    $riskLevel = 'Medium';
                    $riskType  = $kw['risk_type'];
                } elseif ($riskLevel === 'Low') {
                    $riskType = $kw['risk_type'];
                }
            }
        }

        // No keywords matched → skip (noise filter)
        if (empty($matchedKeywords)) return;

        // Match supplier
        $supplierId   = null;
        $supplierName = 'General';
        foreach ($this->suppliers as $sup) {
            foreach ($sup['terms'] as $term) {
                if (strpos($text, $term) !== false) {
                    $supplierId   = $sup['id'];
                    $supplierName = $sup['name'];
                    break 2;
                }
            }
        }

        // Build summary
        $summary = $this->generateSummary($item, $matchedKeywords, $riskType, $riskLevel, $supplierName);

        // Parse date
        $alertDate = date('Y-m-d H:i:s', strtotime($item['date']) ?: time());

        // Insert alert
        $stmt = $this->db->prepare(
            'INSERT INTO alerts 
             (source, title, summary, supplier_id, supplier_name, risk_type, risk_level, alert_date, link, keywords_matched, raw_data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $source['name'],
            $item['title'],
            $summary,
            $supplierId,
            $supplierName,
            $riskType,
            $riskLevel,
            $alertDate,
            $item['link'],
            implode(', ', $matchedKeywords),
            json_encode($item),
        ]);

        $this->alertsCreated++;

        // Console feedback
        echo "    ✓ [{$riskLevel}] " . substr($item['title'], 0, 70) . "\n";
    }

    // -----------------------------------------------
    // Generate plain-English summary
    // -----------------------------------------------
    private function generateSummary(array $item, array $keywords, string $riskType, string $riskLevel, string $supplier): string {
        $kwList = implode(', ', array_slice($keywords, 0, 3));
        $desc   = trim(substr($item['description'], 0, 300));
        if (strlen($item['description']) > 300) $desc .= '...';

        $supplier_note = ($supplier !== 'General')
            ? " Potentially affects supplier: {$supplier}."
            : '';

        return "This {$riskLevel}-risk {$riskType} alert was flagged due to keywords: {$kwList}.{$supplier_note} " .
               "Excerpt: {$desc}";
    }

    // -----------------------------------------------
    // Clean alerts older than retention period
    // -----------------------------------------------
    private function cleanOldAlerts(): void {
        $days = (int)getSetting('alert_retention_days', '90');
        if ($days > 0) {
            $stmt = $this->db->prepare(
                'DELETE FROM alerts WHERE created_at < NOW() - INTERVAL ? DAY AND dismissed = 1'
            );
            $stmt->execute([$days]);
        }
    }

    // -----------------------------------------------
    // Send email notifications for unnotified High alerts
    // -----------------------------------------------
    private function sendPendingNotifications(): void {
        if (getSetting('email_notifications') !== '1') return;

        $minLevel = getSetting('min_risk_notify', 'High');
        $email    = getSetting('notification_email', '');
        if (empty($email)) return;

        $levels = match($minLevel) {
            'Low'    => ['Low', 'Medium', 'High'],
            'Medium' => ['Medium', 'High'],
            default  => ['High'],
        };

        $placeholders = implode(',', array_fill(0, count($levels), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM alerts WHERE notified = 0 AND dismissed = 0 
             AND risk_level IN ($placeholders) ORDER BY alert_date DESC LIMIT 20"
        );
        $stmt->execute($levels);
        $pending = $stmt->fetchAll();

        if (empty($pending)) return;

        $subject = '[OSINT Alert] ' . count($pending) . ' new risk alert(s) detected';
        $body    = "OSINT Supplier Risk Dashboard — New Alerts\n";
        $body   .= str_repeat('=', 60) . "\n\n";

        foreach ($pending as $alert) {
            $body .= "[{$alert['risk_level']}] [{$alert['risk_type']}] {$alert['title']}\n";
            $body .= "Supplier: {$alert['supplier_name']} | Source: {$alert['source']}\n";
            $body .= "Date: {$alert['alert_date']}\n";
            $body .= "Summary: {$alert['summary']}\n";
            $body .= "Link: {$alert['link']}\n";
            $body .= str_repeat('-', 60) . "\n\n";
        }

        // Send using PHP mail() or SMTP
        $headers = "From: OSINT Dashboard <noreply@yourdomain.com>\r\nContent-Type: text/plain; charset=UTF-8";
        if (@mail($email, $subject, $body, $headers)) {
            // Mark as notified
            $ids = array_column($pending, 'id');
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            $this->db->prepare("UPDATE alerts SET notified = 1 WHERE id IN ($ph)")->execute($ids);
            echo "  ✉ Sent notifications to: $email\n";
        } else {
            echo "  ⚠ Email failed. Check mail config.\n";
        }
    }
}

// Run the fetcher
try {
    $fetcher = new OSINTFetcher();
    $fetcher->run();
} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    error_log('[OSINT Fetcher] ' . $e->getMessage());
    exit(1);
}

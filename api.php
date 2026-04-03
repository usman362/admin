<?php
/**
 * api.php — JSON API for the dashboard frontend
 * Returns alerts, stats, and supports dismiss/filter actions.
 */
require_once __DIR__ . '/config.php';
session_start();

// Require login for API
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: ' . BASE_URL);
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$action = $_GET['action'] ?? 'alerts';

try {
    $db = getDB();

    switch ($action) {

        // -----------------------------------------------
        // GET /api.php?action=stats
        // Summary counts for dashboard cards
        // -----------------------------------------------
        case 'stats':
            $stats = [];

            $row = $db->query(
                "SELECT 
                    COUNT(*) AS total,
                    SUM(risk_level='High')   AS high,
                    SUM(risk_level='Medium') AS medium,
                    SUM(risk_level='Low')    AS low,
                    SUM(dismissed=0 AND risk_level='High') AS unread_high
                 FROM alerts WHERE dismissed = 0"
            )->fetch();

            $stats['total']       = (int)$row['total'];
            $stats['high']        = (int)$row['high'];
            $stats['medium']      = (int)$row['medium'];
            $stats['low']         = (int)$row['low'];
            $stats['unread_high'] = (int)$row['unread_high'];

            // Trend: last 7 days by day
            $trend = $db->query(
                "SELECT DATE(alert_date) AS day, 
                        COUNT(*) AS total,
                        SUM(risk_level='High') AS high
                 FROM alerts 
                 WHERE alert_date >= NOW() - INTERVAL 7 DAY AND dismissed = 0
                 GROUP BY DATE(alert_date)
                 ORDER BY day ASC"
            )->fetchAll();
            $stats['trend'] = $trend;

            // Recent by risk type
            $byType = $db->query(
                "SELECT risk_type, COUNT(*) AS cnt
                 FROM alerts WHERE dismissed = 0
                 GROUP BY risk_type ORDER BY cnt DESC"
            )->fetchAll();
            $stats['by_type'] = $byType;

            echo json_encode(['success' => true, 'data' => $stats]);
            break;

        // -----------------------------------------------
        // GET /api.php?action=alerts[&risk=High&source=X&supplier=Y&q=keyword&page=1]
        // -----------------------------------------------
        case 'alerts':
            $page     = max(1, (int)($_GET['page'] ?? 1));
            $perPage  = 25;
            $offset   = ($page - 1) * $perPage;

            $where  = ['a.dismissed = 0'];
            $params = [];

            if (!empty($_GET['risk'])) {
                $where[] = 'a.risk_level = ?';
                $params[] = $_GET['risk'];
            }
            if (!empty($_GET['risk_type'])) {
                $where[] = 'a.risk_type = ?';
                $params[] = $_GET['risk_type'];
            }
            if (!empty($_GET['source'])) {
                $where[] = 'a.source = ?';
                $params[] = $_GET['source'];
            }
            if (!empty($_GET['supplier'])) {
                $where[] = 'a.supplier_name = ?';
                $params[] = $_GET['supplier'];
            }
            if (!empty($_GET['q'])) {
                $where[]  = '(a.title LIKE ? OR a.summary LIKE ? OR a.keywords_matched LIKE ?)';
                $q = '%' . $_GET['q'] . '%';
                $params = array_merge($params, [$q, $q, $q]);
            }
            if (!empty($_GET['days'])) {
                $where[] = 'a.alert_date >= NOW() - INTERVAL ? DAY';
                $params[] = (int)$_GET['days'];
            }

            $whereSQL = implode(' AND ', $where);

            // Total count
            $countStmt = $db->prepare("SELECT COUNT(*) FROM alerts a WHERE $whereSQL");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // Sort
            $sortField = in_array($_GET['sort'] ?? '', ['alert_date','risk_level','source','supplier_name'])
                ? $_GET['sort'] : 'alert_date';
            $sortDir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

            $stmt = $db->prepare(
                "SELECT a.*, s.criticality as supplier_criticality
                 FROM alerts a
                 LEFT JOIN suppliers s ON a.supplier_id = s.id
                 WHERE $whereSQL
                 ORDER BY a.$sortField $sortDir
                 LIMIT $perPage OFFSET $offset"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'data'    => $rows,
                'meta'    => [
                    'total'    => $total,
                    'page'     => $page,
                    'per_page' => $perPage,
                    'pages'    => (int)ceil($total / $perPage),
                ],
            ]);
            break;

        // -----------------------------------------------
        // GET /api.php?action=filter_options
        // Distinct values for filter dropdowns
        // -----------------------------------------------
        case 'filter_options':
            $sources   = $db->query("SELECT DISTINCT source FROM alerts ORDER BY source")->fetchAll(PDO::FETCH_COLUMN);
            $suppliers = $db->query("SELECT DISTINCT supplier_name FROM alerts ORDER BY supplier_name")->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode([
                'success'   => true,
                'sources'   => $sources,
                'suppliers' => $suppliers,
            ]);
            break;

        // -----------------------------------------------
        // POST /api.php?action=dismiss&id=X
        // -----------------------------------------------
        case 'dismiss':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'POST required']);
                break;
            }
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($id < 1) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid ID']);
                break;
            }
            $stmt = $db->prepare('UPDATE alerts SET dismissed = 1 WHERE id = ?');
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;

        // -----------------------------------------------
        // GET /api.php?action=alert&id=X  (single alert detail)
        // -----------------------------------------------
        case 'alert':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare('SELECT * FROM alerts WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Not found']);
                break;
            }
            echo json_encode(['success' => true, 'data' => $row]);
            break;

        // -----------------------------------------------
        // GET /api.php?action=export&format=json|csv
        // -----------------------------------------------
        case 'export':
            $format = $_GET['format'] ?? 'json';
            $stmt = $db->prepare(
                'SELECT source, title, supplier_name, risk_type, risk_level, 
                        alert_date, link, keywords_matched, summary
                 FROM alerts WHERE dismissed = 0 ORDER BY alert_date DESC LIMIT 1000'
            );
            $stmt->execute();
            $rows = $stmt->fetchAll();

            if ($format === 'csv') {
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename="osint_alerts_' . date('Ymd') . '.csv"');
                $out = fopen('php://output', 'w');
                if (!empty($rows)) {
                    fputcsv($out, array_keys($rows[0]));
                    foreach ($rows as $row) fputcsv($out, $row);
                }
                fclose($out);
                exit;
            }

            header('Content-Disposition: attachment; filename="osint_alerts_' . date('Ymd') . '.json"');
            echo json_encode(['success' => true, 'data' => $rows, 'exported_at' => date('c')]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }

} catch (Throwable $e) {
    error_log('[API Error] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

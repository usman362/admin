<?php
ini_set('display_errors', 1);
ini_set('max_execution_time', 180);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

$db = getDB();

$db->exec("UPDATE sources SET active=0 WHERE name IN ('CISA Alerts', 'NVD CVE Feed', 'US-CERT')");

$newSources = [
    ['Google News - Ransomware',   'https://news.google.com/rss/search?q=ransomware+attack&hl=en-US&gl=US&ceid=US:en', 60],
    ['Google News - Data Breach',  'https://news.google.com/rss/search?q=data+breach+hack&hl=en-US&gl=US&ceid=US:en', 60],
    ['Google News - Supply Chain', 'https://news.google.com/rss/search?q=supply+chain+disruption&hl=en-US&gl=US&ceid=US:en', 60],
    ['Bleeping Computer',          'https://www.bleepingcomputer.com/feed/', 60],
    ['The Hacker News',            'https://feeds.feedburner.com/TheHackersNews', 60],
    ['Krebs on Security',          'https://krebsonsecurity.com/feed/', 60],
];
$stmt = $db->prepare("INSERT IGNORE INTO sources (name, url, fetch_interval, type) VALUES (?,?,?,'RSS')");
foreach($newSources as $s) $stmt->execute($s);

$db->exec("UPDATE sources SET last_fetched=NULL WHERE active=1");

echo '<pre style="background:#000;color:#0f0;padding:20px;font-size:12px">';

$kwRows = $db->query("SELECT keyword, risk_type, risk_level FROM keywords WHERE active=1")->fetchAll();
$sources = $db->query("SELECT * FROM sources WHERE active=1")->fetchAll();
$totalAlerts = 0;

foreach($sources as $source) {
    echo "--- {$source['name']} ---\n";
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'Mozilla/5.0'], 'ssl' => ['verify_peer' => false]]);
    $xml = @file_get_contents($source['url'], false, $ctx);
    if(!$xml) { echo "SKIP: fetch failed\n\n"; continue; }
    echo "Fetched: " . strlen($xml) . " bytes\n";
    libxml_use_internal_errors(true);
    $feed = simplexml_load_string($xml);
    libxml_clear_errors();
    if(!$feed) { echo "SKIP: parse failed\n\n"; continue; }
    $items = [];
    if(isset($feed->channel->item)) {
        foreach($feed->channel->item as $item) {
            $items[] = ['title'=>(string)($item->title??''),'desc'=>strip_tags((string)($item->description??'')),'link'=>(string)($item->link??$item->guid??''),'date'=>(string)($item->pubDate??date('r'))];
        }
    } elseif(isset($feed->entry)) {
        foreach($feed->entry as $entry) {
            $items[] = ['title'=>(string)($entry->title??''),'desc'=>strip_tags((string)($entry->summary??$entry->content??'')),'link'=>(string)($entry->link['href']??$entry->link??''),'date'=>(string)($entry->published??$entry->updated??date('c'))];
        }
    }
    echo "Items: " . count($items) . "\n";
    $sourceAlerts = 0;
    foreach($items as $item) {
        $text = strtolower($item['title'] . ' ' . $item['desc']);
        if(!empty($item['link'])) { $ex=$db->prepare("SELECT id FROM alerts WHERE link=? LIMIT 1"); $ex->execute([$item['link']]); if($ex->fetch()) continue; }
        $matched=[]; $riskType='Security'; $riskLevel='Low';
        foreach($kwRows as $kw) {
            if(strpos($text, strtolower($kw['keyword']))!==false) {
                $matched[]=$kw['keyword'];
                if($kw['risk_level']==='High'){$riskLevel='High';$riskType=$kw['risk_type'];}
                elseif($kw['risk_level']==='Medium'&&$riskLevel!=='High'){$riskLevel='Medium';$riskType=$kw['risk_type'];}
            }
        }
        if(empty($matched)) continue;
        $alertDate = date('Y-m-d H:i:s', strtotime($item['date'])?:time());
        $summary = "Flagged for: ".implode(', ',array_slice($matched,0,3)).". ".substr($item['desc'],0,200);
        $ins=$db->prepare("INSERT INTO alerts (source,title,summary,supplier_name,risk_type,risk_level,alert_date,link,keywords_matched) VALUES (?,?,?,?,?,?,?,?,?)");
        $ins->execute([$source['name'],$item['title'],$summary,'General',$riskType,$riskLevel,$alertDate,$item['link'],implode(', ',$matched)]);
        $sourceAlerts++; $totalAlerts++;
        echo "  ✓ [{$riskLevel}] " . substr($item['title'],0,70) . "\n";
    }
    echo "Alerts: {$sourceAlerts}\n\n";
    $db->prepare("UPDATE sources SET last_fetched=NOW() WHERE id=?")->execute([$source['id']]);
}

echo "\nTOTAL ALERTS: {$totalAlerts}\n";
echo "Dashboard refresh karo!\n";
echo '</pre>';
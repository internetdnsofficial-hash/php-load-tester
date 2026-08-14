<?php
// ==========================================
// BAGIAN BACKEND (LAYER 7 API - RATE LIMITED)
// ==========================================

session_start();
set_time_limit(120);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $cooldown_limit = 15;
    $current_time = time();

    if (isset($_SESSION['last_submit_time'])) {
        $time_passed = $current_time - $_SESSION['last_submit_time'];
        if ($time_passed < $cooldown_limit) {
            $sisa_waktu = $cooldown_limit - $time_passed;
            echo json_encode([
                'error' => "Harap tunggu {$sisa_waktu} detik lagi sebelum mengirim request kembali (Backend Protection)."
            ]);
            exit;
        }
    }

    $_SESSION['last_submit_time'] = $current_time;

    $url = trim($_POST['url'] ?? '');
    $custom_referrer = trim($_POST['referrer'] ?? '');
    $custom_origin   = trim($_POST['origin'] ?? '');

    $total_requests = (int)($_POST['total_requests'] ?? 1000);
    if ($total_requests < 1) $total_requests = 1;
    if ($total_requests > 1000) $total_requests = 1000;
    
    $concurrent = (int)($_POST['concurrent'] ?? 25); 
    if ($concurrent < 1) $concurrent = 1;
    if ($concurrent > 40) $concurrent = 40;

    $proxy = trim($_POST['proxy'] ?? '');
    $proxy_auth = trim($_POST['proxy_auth'] ?? '');
    
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['error' => 'URL Endpoint Target tidak valid.']);
        exit;
    }

    if (empty($proxy)) {
        echo json_encode(['error' => 'Alamat Proxy (IP:Port) wajib diisi untuk simulasi Layer 7!']);
        exit;
    }

    $results = [
        'sukses_200'  => 0, 
        'limit_429'   => 0, 
        'proxy_error' => 0, 
        'lainnya'     => 0, 
        'total'       => 0
    ];

    $ch_list = [];

    $human_headers = [
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
        "Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7",
        "Cache-Control: no-cache",
        "Connection: keep-alive",
        "Sec-Ch-Ua: \"Not A(Brand\";v=\"99\", \"Google Chrome\";v=\"121\", \"Chromium\";v=\"121\"",
        "Sec-Ch-Ua-Mobile: ?0",
        "Sec-Ch-Ua-Platform: \"Windows\"",
        "Sec-Fetch-Dest: document",
        "Sec-Fetch-Mode: navigate",
        "Sec-Fetch-Site: cross-site",
        "Sec-Fetch-User: ?1",
        "Upgrade-Insecure-Requests: 1"
    ];

    if (!empty($custom_referrer)) $human_headers[] = "Referer: " . $custom_referrer;
    if (!empty($custom_origin)) $human_headers[] = "Origin: " . $custom_origin;

    $waktu_mulai = microtime(true);

    for ($i = 0; $i < $total_requests; $i++) {
        $ch = curl_init();
        $target_url = $url . (strpos($url, '?') !== false ? '&' : '?') . 'l7_test_id=' . uniqid();
        
        curl_setopt($ch, CURLOPT_URL, $target_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $human_headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        if (!empty($proxy_auth)) curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_auth);

        $ch_list[] = $ch;
    }

    $batches = array_chunk($ch_list, $concurrent);
    $total_batches = count($batches);
    $target_total_duration = 55.0; 
    $interval_per_batch = $target_total_duration / max(1, $total_batches);

    foreach ($batches as $index => $batch) {
        $batch_start = microtime(true);
        $mh = curl_multi_init();
        foreach ($batch as $ch) { curl_multi_add_handle($mh, $ch); }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.01);
        } while ($running > 0);

        foreach ($batch as $ch) {
            $info = curl_getinfo($ch);
            $http_code = (int)$info['http_code'];
            $curl_err = curl_error($ch);
            
            if ($http_code == 200) { $results['sukses_200']++; 
            } elseif ($http_code == 429) { $results['limit_429']++; 
            } elseif (in_array($http_code, [0, 407, 502, 503, 504]) || !empty($curl_err)) {
                $results['proxy_error']++;
            } else { $results['lainnya']++; }
            
            $results['total']++;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        if ($index < $total_batches - 1) {
            $elapsed_batch_time = microtime(true) - $batch_start;
            $sleep_time = $interval_per_batch - $elapsed_batch_time;
            if ($sleep_time > 0) usleep((int)($sleep_time * 1000000));
        }
    }

    echo json_encode([
        'status'       => 'success', 
        'data'         => $results, 
        'waktu_eksekusi' => round(microtime(true) - $waktu_mulai, 2)
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Layer 7 API - Cyber Aesthetic Dashboard</title>
    <!-- Google Fonts Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-deep: #090d16;
            --bg-card: rgba(17, 24, 39, 0.7);
            --border-glass: rgba(255, 255, 255, 0.08);
            --accent-glow: rgba(99, 102, 241, 0.35);
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: var(--bg-deep);
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(99, 102, 241, 0.12) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(14, 165, 233, 0.1) 0%, transparent 40%);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .container {
            width: 100%;
            max-width: 600px;
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--border-glass);
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7), 0 0 30px var(--accent-glow);
        }

        .header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 2rem;
        }

        .header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -0.025em;
            background: linear-gradient(to right, #ffffff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .fire-icon {
            font-size: 1.75rem;
            animation: pulse-glow 2s infinite ease-in-out;
        }

        @keyframes pulse-glow {
            0%, 100% { transform: scale(1); filter: drop-shadow(0 0 2px rgba(249, 115, 22, 0.4)); }
            50% { transform: scale(1.1); filter: drop-shadow(0 0 8px rgba(249, 115, 22, 0.8)); }
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            font-size: 0.875rem;
            color: var(--text-muted);
        }

        input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--border-glass);
            border-radius: 10px;
            color: var(--text-main);
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }

        input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
            background: rgba(15, 23, 42, 0.9);
        }

        .row {
            display: flex;
            gap: 1rem;
        }

        .row .form-group {
            flex: 1;
        }

        button {
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            color: white;
            border: none;
            padding: 0.875rem;
            width: 100%;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            border-radius: 10px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(99, 102, 241, 0.4);
            margin-top: 0.5rem;
        }

        button:hover {
            opacity: 0.95;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.6);
        }

        button:active {
            transform: translateY(0);
        }

        button:disabled {
            background: #334155;
            box-shadow: none;
            cursor: not-allowed;
            opacity: 0.7;
        }

        .result-box {
            margin-top: 2rem;
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid var(--border-glass);
            border-radius: 14px;
            padding: 1.5rem;
            display: none;
            animation: fadeIn 0.4s ease-out forwards;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .result-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            font-size: 0.9rem;
            color: var(--text-muted);
            border-bottom: 1px dashed rgba(255, 255, 255, 0.05);
            padding-bottom: 0.5rem;
        }

        .stat-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .stat-value {
            font-weight: 600;
            color: var(--text-main);
        }

        .val-success { color: #34d399; }
        .val-limit { color: #f87171; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <span class="fire-icon">🔥</span>
        <h2>Layer 7 API Engine</h2>
    </div>

    <form id="testerForm">
        <div class="form-group">
            <label>URL Endpoint API</label>
            <input type="url" name="url" placeholder="https://example.com/api" required>
        </div>

        <div class="form-group">
            <label>Referrer (Wajib)</label>
            <input type="url" name="referrer" placeholder="https://example.com/" required>
        </div>

        <div class="form-group">
            <label>Origin (Wajib)</label>
            <input type="text" name="origin" placeholder="https://example.com" required>
        </div>

        <div class="row">
            <div class="form-group">
                <label>Total Request (Max 1k)</label>
                <input type="number" name="total_requests" value="1000" max="1000" required>
            </div>
            <div class="form-group">
                <label>Concurrent (Max 40)</label>
                <input type="number" name="concurrent" value="25" max="40" required>
            </div>
        </div>

        <div class="form-group">
            <label>Proxy (IP:Port)</label>
            <input type="text" name="proxy" placeholder="123.45.67.89:8080" required>
        </div>

        <div class="form-group">
            <label>Proxy Auth (Opsional)</label>
            <input type="text" name="proxy_auth" placeholder="username:password">
        </div>

        <button type="submit" id="btnSubmit">Tembak Layer 7!</button>
    </form>

    <div id="resultBox" class="result-box">
        <div class="result-title">📊 Hasil Pengujian Sistem</div>
        <div class="stat-item">
            <span>Total Terkirim:</span>
            <span id="resTotal" class="stat-value">0</span>
        </div>
        <div class="stat-item">
            <span>Masuk / Sukses (200):</span>
            <span id="res200" class="stat-value val-success">0</span>
        </div>
        <div class="stat-item">
            <span>Kena Rate Limit (429):</span>
            <span id="res429" class="stat-value val-limit">0</span>
        </div>
        <div class="stat-item">
            <span>Waktu Eksekusi:</span>
            <span id="resWaktu" class="stat-value">0 detik</span>
        </div>
    </div>
</div>

<script>
document.getElementById('testerForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmit');
    const resultBox = document.getElementById('resultBox');
    
    btn.disabled = true; 
    btn.innerText = "⏳ Sedang Menembak Layer 7...";
    resultBox.style.display = 'none';

    try {
        const res = await fetch(window.location.href, { method: 'POST', body: new FormData(this) });
        const json = await res.json();
        
        if (json.error) {
            alert(json.error);
        } else {
            document.getElementById('resTotal').innerText = json.data.total;
            document.getElementById('res200').innerText = json.data.sukses_200;
            document.getElementById('res429').innerText = json.data.limit_429;
            document.getElementById('resWaktu').innerText = json.waktu_eksekusi + " detik";
            resultBox.style.display = 'block';
        }
    } catch(err) { 
        alert("⚠️ Error Jaringan / Timeout: " + err.message); 
    } finally { 
        btn.disabled = false; 
        btn.innerText = "Tembak Layer 7!"; 
    }
});
</script>

</body>
</html>

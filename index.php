<?php
/*
 * ============================================================
 * SAFE WEB LOAD TESTER (MANDATORY PROXY, MULTI-CURL CONCURRENT 50)
 * ============================================================
 */

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? '';

    if ($action === 'start') {
        $url = trim($_POST['url'] ?? '');
        $total = (int)($_POST['total_requests'] ?? 500);
        
        $proxy = trim($_POST['proxy'] ?? '');
        $proxyUser = trim($_POST['proxy_user'] ?? '');
        $proxyPass = trim($_POST['proxy_pass'] ?? '');
        $referer = trim($_POST['referer'] ?? '');
        $origin = trim($_POST['origin'] ?? '');

        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            echo json_encode(['ok' => false, 'error' => 'URL tidak valid.']);
            exit;
        }

        if (empty($proxy)) {
            echo json_encode(['ok' => false, 'error' => 'Proxy wajib diisi! Harap masukkan IP:PORT proxy.']);
            exit;
        }

        $total = max(1, min($total, 2000));

        $_SESSION['test'] = [
            'url' => $url,
            'total' => $total,
            'proxy' => $proxy,
            'proxy_user' => $proxyUser,
            'proxy_pass' => $proxyPass,
            'referer' => $referer,
            'origin' => $origin,
            'completed' => 0,
            'success' => 0,
            'client_error' => 0,
            'server_error' => 0,
            'rate_limited' => 0,
            'proxy_failed' => 0,
            'other' => 0,
            'status' => 'running',
            'started_at' => microtime(true),
        ];

        session_write_close();
        echo json_encode(['ok' => true, 'status' => 'running']);
        exit;
    }

    if ($action === 'pause') {
        if (isset($_SESSION['test'])) {
            $_SESSION['test']['status'] = 'paused';
        }
        session_write_close();
        echo json_encode(['ok' => true, 'status' => 'paused']);
        exit;
    }

    if ($action === 'resume') {
        if (isset($_SESSION['test'])) {
            $_SESSION['test']['status'] = 'running';
        }
        session_write_close();
        echo json_encode(['ok' => true, 'status' => 'running']);
        exit;
    }

    if ($action === 'stop') {
        if (isset($_SESSION['test'])) {
            $_SESSION['test']['status'] = 'stopped';
        }
        session_write_close();
        echo json_encode(['ok' => true, 'status' => 'stopped']);
        exit;
    }

    if ($action === 'status') {
        if (!isset($_SESSION['test'])) {
            session_write_close();
            echo json_encode(['ok' => true, 'exists' => false]);
            exit;
        }
        $testData = $_SESSION['test'];
        session_write_close();
        echo json_encode(['ok' => true, 'exists' => true, 'test' => $testData]);
        exit;
    }

    /*
     * Eksekusi Multi-cURL Secara Serentak (Batch Paralel 50 Request Sekaligus)
     */
    if ($action === 'request_batch') {
        if (!isset($_SESSION['test'])) {
            session_write_close();
            echo json_encode(['ok' => false, 'error' => 'Test belum dimulai.']);
            exit;
        }

        $test =& $_SESSION['test'];

        if ($test['status'] !== 'running') {
            $testData = $test;
            session_write_close();
            echo json_encode(['ok' => true, 'status' => $testData['status'], 'test' => $testData]);
            exit;
        }

        if ($test['completed'] >= $test['total']) {
            $test['status'] = 'finished';
            $testData = $test;
            session_write_close();
            echo json_encode(['ok' => true, 'status' => 'finished', 'test' => $testData]);
            exit;
        }

        $remaining = $test['total'] - $test['completed'];
        $batchSize = min(30, $remaining); // Dibatasi menjadi 30 request sekaligus per batch

        $mh = curl_multi_init();
        $channels = [];

        $humanUserAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2.1 Safari/605.1.15',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36'
        ];

        for ($i = 0; $i < $batchSize; $i++) {
            $ch = curl_init();
            $randomUserAgent = $humanUserAgents[array_rand($humanUserAgents)];

            $curlOptions = [
                CURLOPT_URL => $test['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPGET => true,
                CURLOPT_USERAGENT => $randomUserAgent,
                CURLOPT_PROXY => $test['proxy'],
            ];

            if (!empty($test['proxy_user'])) {
                $curlOptions[CURLOPT_PROXYUSERPWD] = $test['proxy_user'] . ':' . $test['proxy_pass'];
                $curlOptions[CURLOPT_PROXYAUTH] = CURLAUTH_ANY;
            }

            $headers = [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control: no-cache',
            ];

            if (!empty($test['referer'])) {
                $curlOptions[CURLOPT_REFERER] = $test['referer'];
            }
            if (!empty($test['origin'])) {
                $headers[] = 'Origin: ' . $test['origin'];
            }
            $curlOptions[CURLOPT_HTTPHEADER] = $headers;

            curl_setopt_array($ch, $curlOptions);
            curl_multi_add_handle($mh, $ch);
            $channels[] = ['ch' => $ch, 'start_time' => microtime(true)];
        }

        $runningActive = null;
        do {
            curl_multi_exec($mh, $runningActive);
            curl_multi_select($mh);
        } while ($runningActive > 0);

        $batchResults = [];

        foreach ($channels as $item) {
            $ch = $item['ch'];
            $startTime = $item['start_time'];

            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrNo = curl_errno($ch);
            $curlError = curl_error($ch);
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            $test['completed']++;
            $statusType = 'other';
            $statusMessage = '';

            $isProxyError = false;
            if ($curlErrNo == 7 || $curlErrNo == 5 || $curlErrNo == 6 || $curlErrNo == 28 || strpos(strtolower($curlError), 'proxy') !== false) {
                $isProxyError = true;
            }

            if ($isProxyError || ($curlErrNo !== 0 && $httpCode === 0)) {
                $test['proxy_failed']++;
                $statusType = 'proxy_failed';
                $statusMessage = "Proxy Mati / Gagal: " . ($curlError ?: "Errno $curlErrNo");
            } elseif ($httpCode === 200) {
                $test['success']++;
                $statusType = 'success';
                $statusMessage = "OK 200";
            } elseif ($httpCode === 429) {
                $test['rate_limited']++;
                $statusType = 'rate_limited';
                $statusMessage = "Rate Limited 429";
            } elseif ($httpCode >= 400 && $httpCode < 500) {
                $test['client_error']++;
                $statusType = 'client_error';
                $statusMessage = "Client Error {$httpCode}";
            } elseif ($httpCode >= 500) {
                $test['server_error']++;
                $statusType = 'server_error';
                $statusMessage = "Server Error {$httpCode}";
            } else {
                $test['other']++;
                $statusType = 'other';
                $statusMessage = "HTTP Code {$httpCode}";
            }

            $batchResults[] = [
                'req_num' => $test['completed'],
                'status_message' => $statusMessage,
                'status_type' => $statusType,
                'latency' => $latency
            ];

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        if ($test['completed'] >= $test['total']) {
            $test['status'] = 'finished';
        }

        $testData = $test;
        session_write_close();

        echo json_encode([
            'ok' => true,
            'status' => $testData['status'],
            'batch_results' => $batchResults,
            'is_finished' => ($testData['status'] === 'finished'),
            'test' => $testData
        ]);
        exit;
    }

    session_write_close();
    echo json_encode(['ok' => false, 'error' => 'Action tidak dikenal.']);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>7 Layer - Concurrent 50</title>
<style>
:root {
    --bg: #080b12;
    --card: rgba(17, 24, 39, .82);
    --border: rgba(255,255,255,.08);
    --primary: #6366f1;
    --primary2: #4f46e5;
    --text: #f8fafc;
    --muted: #94a3b8;
    --success: #34d399;
    --danger: #f87171;
    --warning: #fbbf24;
    --info: #38bdf8;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    min-height: 100vh;
    background: var(--bg);
    color: var(--text);
    font-family: Inter, system-ui, sans-serif;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 25px;
}
.container {
    width: 100%;
    max-width: 700px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 22px;
    padding: 30px;
    box-shadow: 0 30px 80px rgba(0,0,0,.55);
}
.header { display: flex; align-items: center; gap: 12px; margin-bottom: 25px; }
.icon { font-size: 30px; }
.header h1 { font-size: 22px; }
.header p { margin-top: 4px; color: var(--muted); font-size: 13px; }
.form-group { margin-bottom: 18px; }
label { display: block; margin-bottom: 7px; color: var(--muted); font-size: 13px; font-weight: 600; }
input {
    width: 100%; padding: 13px 14px; border-radius: 11px;
    border: 1px solid var(--border); background: rgba(15,23,42,.75); color: var(--text); outline: none;
}
.row { display: flex; gap: 15px; }
.row > div { flex: 1; }
.buttons { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-top: 20px; }
button { border: 0; border-radius: 11px; padding: 13px; color: white; font-weight: 700; cursor: pointer; transition: .2s; }
button:disabled { opacity: .4; cursor: not-allowed; }
.start { background: linear-gradient(135deg, var(--primary), var(--primary2)); }
.pause { background: #334155; }
.stop { background: #991b1b; }
.status {
    margin-top: 25px; display: flex; justify-content: space-between; align-items: center;
    padding: 12px 15px; border-radius: 11px; background: rgba(15,23,42,.7); border: 1px solid var(--border);
}
.progress-wrap { margin-top: 18px; }
.progress-info { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px; color: var(--muted); }
.progress { height: 10px; background: #1e293b; border-radius: 99px; overflow: hidden; }
.progress-bar { width: 0%; height: 100%; background: linear-gradient(90deg, var(--primary), #22d3ee); transition: width .1s; }
.stats { margin-top: 22px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.stat { padding: 15px; border-radius: 12px; background: rgba(15,23,42,.6); border: 1px solid var(--border); }
.stat-title { font-size: 12px; color: var(--muted); margin-bottom: 5px; }
.stat-value { font-size: 18px; font-weight: 800; }
.success { color: var(--success); }
.error { color: var(--danger); }
.warning { color: var(--warning); }
.log {
    margin-top: 20px; background: #05070c; border: 1px solid var(--border); border-radius: 12px;
    padding: 13px; height: 150px; overflow-y: auto; font-family: monospace; font-size: 12px; color: #a7f3d0;
}
</style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="icon">🚀</div>
        <div>
            <h1>7 Layer (Concurrent 50)</h1>
            <p>Kirim request 7 Layer dengan batch 50 paralel.</p>
        </div>
    </div>

    <form id="testerForm">
        <div class="form-group">
            <label>URL Endpoint</label>
            <input type="url" id="url" placeholder="https://domain-kamu.com/api/health" required>
        </div>

        <div class="form-group">
            <label>Total Request (Maks: 2000)</label>
            <input type="number" id="total" value="500" min="1" max="2000" required>
        </div>

        <div class="form-group">
            <label>Proxy **(Wajib)** - Cth: 123.45.67.89:8080</label>
            <input type="text" id="proxy" placeholder="IP:PORT" required>
        </div>

        <div class="row">
            <div class="form-group">
                <label>Proxy Username (Opsional)</label>
                <input type="text" id="proxyUser" placeholder="Username">
            </div>
            <div class="form-group">
                <label>Proxy Password (Opsional)</label>
                <input type="password" id="proxyPass" placeholder="Password">
            </div>
        </div>

        <div class="row">
            <div class="form-group">
                <label>Referer Header (Opsional)</label>
                <input type="url" id="referer" placeholder="https://domain-asal.com">
            </div>
            <div class="form-group">
                <label>Origin Header (Opsional)</label>
                <input type="url" id="origin" placeholder="https://domain-asal.com">
            </div>
        </div>

        <div class="buttons">
            <button type="button" class="start" id="startBtn">▶ START</button>
            <button type="button" class="pause" id="pauseBtn" disabled>⏸ PAUSE</button>
            <button type="button" class="stop" id="stopBtn" disabled>⏹ STOP</button>
        </div>
    </form>

    <div class="status">
        <span class="status-label">STATUS</span>
        <span id="statusText" style="font-weight: 700;">IDLE</span>
    </div>

    <div class="progress-wrap">
        <div class="progress-info">
            <span id="cooldownInfo">Progress</span>
            <span id="progressText">0 / 0</span>
        </div>
        <div class="progress">
            <div class="progress-bar" id="progressBar"></div>
        </div>
    </div>

    <div class="stats">
        <div class="stat"><div class="stat-title">Total</div><div class="stat-value" id="resTotal">0</div></div>
        <div class="stat"><div class="stat-title">Success (200)</div><div class="stat-value success" id="resSuccess">0</div></div>
        <div class="stat"><div class="stat-title">Rate Limited (429)</div><div class="stat-value warning" id="resRateLimited">0</div></div>
        <div class="stat"><div class="stat-title">Proxy Mati / Gagal</div><div class="stat-value error" id="resProxyFailed">0</div></div>
        <div class="stat"><div class="stat-title">Client Error (4xx)</div><div class="stat-value warning" id="resClient">0</div></div>
        <div class="stat"><div class="stat-title">Server Error (5xx)</div><div class="stat-value error" id="resServer">0</div></div>
    </div>

    <div class="log" id="log">Tester siap digunakan (50 paralel)...</div>
</div>

<script>
const startBtn = document.getElementById('startBtn');
const pauseBtn = document.getElementById('pauseBtn');
const stopBtn = document.getElementById('stopBtn');
const statusText = document.getElementById('statusText');
const progressText = document.getElementById('progressText');
const progressBar = document.getElementById('progressBar');
const resTotal = document.getElementById('resTotal');
const resSuccess = document.getElementById('resSuccess');
const resRateLimited = document.getElementById('resRateLimited');
const resProxyFailed = document.getElementById('resProxyFailed');
const resClient = document.getElementById('resClient');
const resServer = document.getElementById('resServer');
const logBox = document.getElementById('log');

let running = false;
let stopped = false;
let isPausedState = false;

async function post(action, data = {}) {
    const form = new FormData();
    form.append('action', action);
    for (const key in data) form.append(key, data[key]);
    const response = await fetch(window.location.href, { method: 'POST', body: form, cache: 'no-store' });
    return await response.json();
}

function log(message, type = 'normal') {
    const time = new Date().toLocaleTimeString();
    let colorStyle = '#a7f3d0';
    if (type === 'success') colorStyle = '#34d399';
    else if (type === 'warning') colorStyle = '#fbbf24';
    else if (type === 'error') colorStyle = '#f87171';
    logBox.innerHTML += `<div style="color: ${colorStyle}">[${time}] ${message}</div>`;
    logBox.scrollTop = logBox.scrollHeight;
}

function updateUI(test) {
    if (!test) return;
    const total = Number(test.total);
    const completed = Number(test.completed);
    const percent = total > 0 ? (completed / total) * 100 : 0;

    progressBar.style.width = percent + '%';
    progressText.innerText = `${completed} / ${total}`;
    resTotal.innerText = completed;
    resSuccess.innerText = test.success;
    resRateLimited.innerText = test.rate_limited;
    resProxyFailed.innerText = test.proxy_failed;
    resClient.innerText = test.client_error;
    resServer.innerText = test.server_error;
    statusText.innerText = String(test.status).toUpperCase();
}

startBtn.addEventListener('click', async () => {
    let total = parseInt(document.getElementById('total').value);
    const url = document.getElementById('url').value.trim();
    const proxy = document.getElementById('proxy').value.trim();
    const proxyUser = document.getElementById('proxyUser').value.trim();
    const proxyPass = document.getElementById('proxyPass').value.trim();
    const referer = document.getElementById('referer').value.trim();
    const origin = document.getElementById('origin').value.trim();

    if (!url || !proxy) {
        alert('URL dan Proxy wajib diisi!');
        return;
    }
    if (total > 2000) total = 2000;

    const result = await post('start', { url, total_requests: total, proxy, proxy_user: proxyUser, proxy_pass: proxyPass, referer, origin });
    if (!result.ok) { alert(result.error); return; }

    running = true;
    stopped = false;
    isPausedState = false;
    startBtn.disabled = true;
    pauseBtn.disabled = false;
    stopBtn.disabled = false;

    logBox.innerHTML = '';
    log('Test 50 paralel dimulai...');
    runBatchLoop();
});

pauseBtn.addEventListener('click', async () => {
    if (!isPausedState) {
        await post('pause');
        running = false;
        isPausedState = true;
        pauseBtn.innerText = '▶ RESUME';
        log('Test dijeda.', 'warning');
    } else {
        await post('resume');
        running = true;
        isPausedState = false;
        pauseBtn.innerText = '⏸ PAUSE';
        log('Test dilanjutkan.');
        runBatchLoop();
    }
});

stopBtn.addEventListener('click', async () => {
    stopped = true;
    running = false;
    await post('stop');
    startBtn.disabled = false;
    pauseBtn.disabled = true;
    stopBtn.disabled = true;
    pauseBtn.innerText = '⏸ PAUSE';
    isPausedState = false;
    log('Test dihentikan.', 'error');
});

async function runBatchLoop() {
    if (!running || stopped) return;

    try {
        const result = await post('request_batch');
        if (!result.ok) {
            log('Error: ' + result.error, 'error');
            running = false;
            return;
        }

        updateUI(result.test);

        if (result.batch_results) {
            result.batch_results.forEach(res => {
                let lType = 'normal';
                if (res.status_type === 'success') lType = 'success';
                else if (res.status_type === 'rate_limited') lType = 'warning';
                else if (res.status_type === 'proxy_failed' || res.status_type === 'server_error') lType = 'error';
                log(`Request #${res.req_num} → [${res.status_message}] (${res.latency} ms)`, lType);
            });
        }

        if (result.is_finished || result.test.status === 'finished') {
            running = false;
            startBtn.disabled = false;
            pauseBtn.disabled = true;
            stopBtn.disabled = true;
            log('Test selesai 100%!', 'success');
            return;
        }

        setTimeout(runBatchLoop, 0);

    } catch (err) {
        log('Network error: ' + err.message, 'error');
        running = false;
    }
}
</script>

</body>
</html>

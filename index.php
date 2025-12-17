<?php
session_start();

define('DATA_DIR', __DIR__);
define('HEARTBEAT_TIMEOUT', 30);

if (!is_writable(DATA_DIR)) {
    die('<h1>Configuration Error</h1><p>Directory not writable. Run: <code>chmod 755 ' . DATA_DIR . '</code></p>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    if ($action === 'init') {
        $userId = uniqid('user_', true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['is_owner'] = true;
        echo json_encode([
            'success' => true,
            'userId' => $userId,
            'shareUrl' => $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?track=' . $userId
        ]);
        exit;
    }
    
    if ($action === 'update') {
        $userId = $_POST['userId'] ?? '';
        $lat = floatval($_POST['lat'] ?? 0);
        $lng = floatval($_POST['lng'] ?? 0);
        
        if ($userId && $lat && $lng) {
            $filename = DATA_DIR . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $userId) . '.json';
            $data = file_exists($filename) ? json_decode(file_get_contents($filename), true) : ['points' => [], 'startTime' => time(), 'isActive' => true, 'lastHeartbeat' => time()];
            
            $data['points'][] = ['lat' => $lat, 'lng' => $lng, 'timestamp' => time()];
            if (count($data['points']) > 5000) {
                $data['points'] = array_slice($data['points'], -5000);
            }
            
            $data['current'] = ['lat' => $lat, 'lng' => $lng, 'timestamp' => time()];
            if (!isset($data['startTime'])) {
                $data['startTime'] = $data['points'][0]['timestamp'];
            }
            
            $data['lastHeartbeat'] = time();
            $data['isActive'] = true;
            file_put_contents($filename, json_encode($data));
            echo json_encode(['success' => true]);
            exit;
        }
    }
    
    if ($action === 'heartbeat') {
        $userId = $_POST['userId'] ?? '';
        if ($userId) {
            $filename = DATA_DIR . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $userId) . '.json';
            if (file_exists($filename)) {
                $data = json_decode(file_get_contents($filename), true);
                $data['lastHeartbeat'] = time();
                $data['isActive'] = true;
                file_put_contents($filename, json_encode($data));
                echo json_encode(['success' => true]);
                exit;
            }
        }
        echo json_encode(['success' => false]);
        exit;
    }
    
    if ($action === 'stop') {
        $userId = $_POST['userId'] ?? '';
        if ($userId) {
            $filename = DATA_DIR . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $userId) . '.json';
            if (file_exists($filename)) {
                $data = json_decode(file_get_contents($filename), true);
                $data['isActive'] = false;
                $data['endTime'] = time();
                file_put_contents($filename, json_encode($data));
                echo json_encode(['success' => true]);
                exit;
            }
        }
        echo json_encode(['success' => false]);
        exit;
    }
    
    if ($action === 'get') {
        $userId = $_POST['userId'] ?? '';
        if ($userId) {
            $filename = DATA_DIR . '/' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $userId) . '.json';
            if (file_exists($filename)) {
                $data = json_decode(file_get_contents($filename), true);
                $lastHeartbeat = $data['lastHeartbeat'] ?? 0;
                $timeSinceHeartbeat = time() - $lastHeartbeat;
                
                if ($timeSinceHeartbeat > HEARTBEAT_TIMEOUT && ($data['isActive'] ?? false)) {
                    $data['isActive'] = false;
                    $data['endTime'] = $lastHeartbeat;
                    $data['autoStopped'] = true;
                    file_put_contents($filename, json_encode($data));
                }
                
                echo json_encode(['success' => true, 'data' => $data]);
                exit;
            }
        }
        echo json_encode(['success' => false]);
        exit;
    }
    
    echo json_encode(['success' => false]);
    exit;
}

$trackUserId = $_GET['track'] ?? '';
$isViewer = !empty($trackUserId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>xsukax Live Location Tracker</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Noto Sans', Helvetica, Arial, sans-serif; background: #ffffff; color: #24292f; line-height: 1.5; }
        .container { max-width: 1200px; margin: 0 auto; padding: 24px; }
        .header { text-align: center; margin-bottom: 32px; padding: 24px; border-bottom: 1px solid #d0d7de; }
        .header h1 { font-size: 32px; font-weight: 600; margin-bottom: 8px; color: #24292f; }
        .header p { color: #57606a; font-size: 16px; }
        .btn-primary { background: #2da44e; color: white; border: none; padding: 12px 24px; font-size: 16px; font-weight: 500; border-radius: 6px; cursor: pointer; transition: background 0.2s; display: inline-block; text-decoration: none; margin: 4px; }
        .btn-primary:hover { background: #2c974b; }
        .btn-primary:disabled { background: #94d3a2; cursor: not-allowed; }
        .btn-secondary { background: #f6f8fa; color: #24292f; border: 1px solid #d0d7de; padding: 12px 24px; font-size: 16px; font-weight: 500; border-radius: 6px; cursor: pointer; transition: all 0.2s; display: inline-block; text-decoration: none; margin: 4px; }
        .btn-secondary:hover { background: #f3f4f6; border-color: #c8cdd1; }
        .btn-danger { background: #d1242f; color: white; border: none; padding: 12px 24px; font-size: 16px; font-weight: 500; border-radius: 6px; cursor: pointer; transition: background 0.2s; display: inline-block; margin: 4px; }
        .btn-danger:hover { background: #a40e26; }
        .btn-small { padding: 6px 12px; font-size: 14px; }
        .card { background: #f6f8fa; border: 1px solid #d0d7de; border-radius: 6px; padding: 24px; margin-bottom: 24px; }
        .info-box { background: #ddf4ff; border: 1px solid #54aeff; border-radius: 6px; padding: 16px; margin-bottom: 24px; }
        .info-box strong { color: #0969da; }
        .warning-box { background: #fff8c5; border: 1px solid #d4a72c; border-radius: 6px; padding: 16px; margin-bottom: 24px; }
        .map-container { position: relative; margin-bottom: 24px; }
        #map { height: 500px; border-radius: 6px; border: 1px solid #d0d7de; box-shadow: 0 1px 3px rgba(0,0,0,0.12); }
        .fullscreen-btn { position: absolute; top: 10px; right: 10px; z-index: 1000; background: #ffffff; border: 2px solid #d0d7de; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 18px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: all 0.2s; }
        .fullscreen-btn:hover { background: #f6f8fa; transform: scale(1.05); }
        .map-fullscreen { position: fixed !important; top: 0 !important; left: 0 !important; right: 0 !important; bottom: 0 !important; width: 100vw !important; height: 100vh !important; z-index: 9999 !important; margin: 0 !important; padding: 0 !important; border-radius: 0 !important; }
        .map-fullscreen #map { height: 100vh !important; border-radius: 0 !important; }
        .map-fullscreen .fullscreen-btn { top: 10px !important; right: 10px !important; }
        .modal { display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: #ffffff; margin: 15% auto; padding: 24px; border: 1px solid #d0d7de; border-radius: 6px; width: 90%; max-width: 500px; box-shadow: 0 8px 24px rgba(0,0,0,0.2); }
        .modal-header { font-size: 20px; font-weight: 600; margin-bottom: 16px; color: #24292f; }
        .modal-body { margin-bottom: 16px; color: #57606a; }
        .modal-footer { text-align: right; }
        .status { padding: 8px 16px; border-radius: 6px; display: inline-block; font-size: 14px; font-weight: 500; margin-bottom: 16px; }
        .status-active { background: #ddf4ff; color: #0969da; border: 1px solid #54aeff; }
        .status-inactive { background: #f6f8fa; color: #57606a; border: 1px solid #d0d7de; }
        .status-replay { background: #fff8c5; color: #9a6700; border: 1px solid #d4a72c; }
        .share-url { background: #f6f8fa; border: 1px solid #d0d7de; padding: 12px; border-radius: 6px; font-family: 'Monaco', 'Courier New', monospace; font-size: 14px; word-break: break-all; margin: 16px 0; cursor: pointer; }
        .share-url:hover { background: #f3f4f6; }
        .location-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .location-info-item { background: #ffffff; border: 1px solid #d0d7de; border-radius: 6px; padding: 16px; }
        .location-info-item label { display: block; font-size: 12px; color: #57606a; margin-bottom: 4px; text-transform: uppercase; font-weight: 600; }
        .location-info-item value { display: block; font-size: 18px; font-weight: 600; color: #24292f; font-family: 'Monaco', 'Courier New', monospace; }
        .replay-controls { background: #ffffff; border: 1px solid #d0d7de; border-radius: 6px; padding: 20px; margin-bottom: 24px; }
        .replay-controls h3 { margin-bottom: 16px; font-size: 18px; font-weight: 600; }
        .progress-bar { width: 100%; height: 8px; background: #f6f8fa; border: 1px solid #d0d7de; border-radius: 4px; margin: 16px 0; position: relative; cursor: pointer; }
        .progress-fill { height: 100%; background: #0969da; border-radius: 4px; transition: width 0.3s; }
        .speed-controls { display: flex; gap: 8px; align-items: center; margin-top: 16px; }
        .speed-label { font-size: 14px; color: #57606a; font-weight: 500; }
        .btn-group { display: flex; gap: 12px; flex-wrap: wrap; justify-content: center; }
        @media (max-width: 768px) { .container { padding: 16px; } .header h1 { font-size: 24px; } #map { height: 400px; } .location-info { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📍 xsukax Live Location Tracker</h1>
            <p>Share your real-time location securely with family and friends</p>
        </div>

        <?php if (!$isViewer): ?>
            <div id="homepage">
                <div class="card" style="text-align: center;">
                    <h2 style="margin-bottom: 16px; font-size: 24px; font-weight: 600;">Track Your Location</h2>
                    <p style="color: #57606a; margin-bottom: 24px;">Click the button below to start sharing your live location. A unique shareable link will be generated for you.</p>
                    
                    <div style="max-width: 400px; margin: 0 auto 24px;">
                        <label for="initialUpdateInterval" style="display: block; font-size: 14px; color: #57606a; margin-bottom: 8px; font-weight: 500; text-align: left;">Update Interval (seconds)</label>
                        <input type="number" id="initialUpdateInterval" min="1" max="60" value="5" style="width: 100%; padding: 8px 12px; border: 1px solid #d0d7de; border-radius: 6px; font-size: 14px; margin-bottom: 8px;">
                        <div style="display: flex; gap: 4px; flex-wrap: wrap; justify-content: center; margin-bottom: 8px;">
                            <button class="btn-secondary btn-small" onclick="document.getElementById('initialUpdateInterval').value = 3; return false;">3s</button>
                            <button class="btn-secondary btn-small" onclick="document.getElementById('initialUpdateInterval').value = 5; return false;">5s</button>
                            <button class="btn-secondary btn-small" onclick="document.getElementById('initialUpdateInterval').value = 10; return false;">10s</button>
                            <button class="btn-secondary btn-small" onclick="document.getElementById('initialUpdateInterval').value = 30; return false;">30s</button>
                        </div>
                        <p style="color: #57606a; font-size: 12px; margin-top: 4px; text-align: center;">Lower = more accurate, higher battery usage.</p>
                    </div>
                    
                    <button id="startTracking" class="btn-primary">📍 Track My Location</button>
                </div>

                <div class="info-box">
                    <strong>How it works:</strong> When you start tracking, your browser will request location permission. Your location will be updated at your chosen interval and stored securely.
                    <br><br>
                    <strong>💡 Note:</strong> Your screen will stay on during tracking.
                    <br><br>
                    <strong>⚠️ Important:</strong> Keep this tab open! Tracking will automatically stop if you close the tab or refresh the page.
                </div>
            </div>

            <div id="trackingView" style="display: none;">
                <div class="status status-active" id="statusIndicator">🟢 Location Tracking Active</div>
                
                <div class="location-info">
                    <div class="location-info-item">
                        <label>Latitude</label>
                        <value id="currentLat">--</value>
                    </div>
                    <div class="location-info-item">
                        <label>Longitude</label>
                        <value id="currentLng">--</value>
                    </div>
                    <div class="location-info-item">
                        <label>Update Interval</label>
                        <value id="currentIntervalDisplay">5s</value>
                    </div>
                    <div class="location-info-item">
                        <label>Journey Duration</label>
                        <value id="journeyDuration">--</value>
                    </div>
                    <div class="location-info-item">
                        <label>Last Update</label>
                        <value id="lastUpdate">--</value>
                    </div>
                </div>

                <div class="card">
                    <h3 style="margin-bottom: 16px; font-weight: 600;">Share Your Location</h3>
                    <p style="color: #57606a; margin-bottom: 16px;">Copy and share this URL:</p>
                    <div class="share-url" id="shareUrl" onclick="copyShareUrl()">Generating...</div>
                    <p style="color: #57606a; font-size: 14px;">Click the URL above to copy to clipboard</p>
                </div>

                <div class="warning-box">
                    <strong>⚠️ Important:</strong> Tracking will automatically stop if you close this tab, refresh, or navigate away.
                    <br><br>
                    <strong>🎬 After Stopping:</strong> You and viewers can replay the complete journey.
                </div>

                <div class="card">
                    <h3 style="margin-bottom: 16px; font-weight: 600;">Tracking Settings</h3>
                    <div style="margin-bottom: 16px;">
                        <label for="updateInterval" style="display: block; font-size: 14px; color: #57606a; margin-bottom: 8px; font-weight: 500;">Update Interval (seconds)</label>
                        <input type="number" id="updateInterval" min="1" max="60" value="5" onkeypress="if(event.key==='Enter') updateTrackingInterval()" style="width: 100%; padding: 8px 12px; border: 1px solid #d0d7de; border-radius: 6px; font-size: 14px; margin-bottom: 8px;">
                        <div style="display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 8px;">
                            <button class="btn-secondary btn-small" onclick="setIntervalPreset(3)">3s - High Accuracy</button>
                            <button class="btn-secondary btn-small" onclick="setIntervalPreset(5)">5s - Balanced</button>
                            <button class="btn-secondary btn-small" onclick="setIntervalPreset(10)">10s - Battery Saver</button>
                            <button class="btn-secondary btn-small" onclick="setIntervalPreset(30)">30s - Low Usage</button>
                        </div>
                        <p style="color: #57606a; font-size: 12px; margin-top: 4px;">Lower values = more accurate, higher battery usage (1-60 seconds).</p>
                    </div>
                    <button class="btn-primary btn-small" onclick="updateTrackingInterval()">✓ Apply Changes</button>
                </div>

                <div class="replay-controls" id="replayControls" style="display: none;">
                    <h3>🎬 Journey Replay</h3>
                    <div class="progress-bar" onclick="seekReplay(event)">
                        <div class="progress-fill" id="progressFill"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 12px; color: #57606a; margin-bottom: 16px;">
                        <span id="replayCurrentTime">00:00</span>
                        <span id="replayTotalTime">00:00</span>
                    </div>
                    <div class="btn-group">
                        <button class="btn-secondary btn-small" onclick="toggleReplay()">▶️ <span id="playPauseText">Play</span></button>
                        <button class="btn-secondary btn-small" onclick="stopReplay()">⏹️ Stop</button>
                        <button class="btn-secondary btn-small" onclick="restartReplay()">⏮️ Restart</button>
                    </div>
                    <div class="speed-controls">
                        <span class="speed-label">Speed:</span>
                        <button class="btn-secondary btn-small" onclick="setReplaySpeed(0.5)">0.5x</button>
                        <button class="btn-secondary btn-small" onclick="setReplaySpeed(1)">1x</button>
                        <button class="btn-secondary btn-small" onclick="setReplaySpeed(2)">2x</button>
                        <button class="btn-secondary btn-small" onclick="setReplaySpeed(5)">5x</button>
                    </div>
                </div>

                <div class="map-container" id="mapContainer">
                    <button class="fullscreen-btn" onclick="toggleFullscreen()" title="Toggle Fullscreen">⛶</button>
                    <div id="map"></div>
                </div>

                <div style="margin-top: 24px;" class="btn-group">
                    <button id="showReplayBtn" class="btn-primary" onclick="showReplayMode()" style="display: none;">🎬 Replay Journey</button>
                    <button id="stopTracking" class="btn-danger">⏹️ Stop Tracking</button>
                </div>
            </div>

        <?php else: ?>
            <div id="viewerMode">
                <div class="info-box">
                    <strong>👁️ Viewing Mode:</strong> You are viewing live location.
                    <br><br>
                    <strong>🎬 Journey Replay:</strong> When tracking stops, you can replay the complete journey.
                </div>
                
                <div class="status status-active" id="viewerStatusIndicator">👁️ Viewing Live Location</div>
                
                <div class="location-info">
                    <div class="location-info-item">
                        <label>Latitude</label>
                        <value id="viewerLat">Loading...</value>
                    </div>
                    <div class="location-info-item">
                        <label>Longitude</label>
                        <value id="viewerLng">Loading...</value>
                    </div>
                    <div class="location-info-item">
                        <label>Journey Duration</label>
                        <value id="viewerDuration">Loading...</value>
                    </div>
                    <div class="location-info-item">
                        <label>Last Update</label>
                        <value id="viewerLastUpdate">Loading...</value>
                    </div>
                </div>

                <div class="replay-controls" id="viewerReplayControls" style="display: none;">
                    <h3>🎬 Journey Replay</h3>
                    <div class="progress-bar" onclick="seekReplay(event)">
                        <div class="progress-fill" id="viewerProgressFill"></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 12px; color: #57606a; margin-bottom: 16px;">
                        <span id="viewerReplayCurrentTime">00:00</span>
                        <span id="viewerReplayTotalTime">00:00</span>
                    </div>
                    <div class="btn-group">
                        <button class="btn-secondary btn-small" onclick="toggleReplay()">▶️ <span id="viewerPlayPauseText">Play</span></button>
                        <button class="btn-secondary btn-small" onclick="stopReplay()">⏹️ Stop</button>
                        <button class="btn-secondary btn-small" onclick="restartReplay()">⏮️ Restart</button>
                    </div>
                    <div class="speed-controls">
                        <span class="speed-label">Speed:</span>
                        <button class="btn-secondary btn-small" onclick="setReplaySpeed(0.5)">0.5x</button>
                        <button class="btn-secondary btn-small" onclick="setReplaySpeed(1)">1x</button>
                        <button class="btn-secondary btn-small" onclick="setReplaySpeed(2)">2x</button>
                        <button class="btn-secondary btn-small" onclick="setReplaySpeed(5)">5x</button>
                    </div>
                </div>

                <div class="map-container" id="viewerMapContainer">
                    <button class="fullscreen-btn" onclick="toggleFullscreen()" title="Toggle Fullscreen">⛶</button>
                    <div id="map"></div>
                </div>

                <div style="margin-top: 24px;" class="btn-group">
                    <button class="btn-primary" onclick="showReplayMode()" id="viewerReplayBtn" style="display: none;">🎬 Replay Journey</button>
                    <button class="btn-secondary" onclick="location.reload()">🔄 Refresh</button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div id="modal" class="modal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle">Notice</div>
            <div class="modal-body" id="modalBody">Modal content</div>
            <div class="modal-footer">
                <button class="btn-primary" onclick="closeModal()">OK</button>
            </div>
        </div>
    </div>

    <script>
        let map, marker, routeLine, userId = null, trackingInterval = null, heartbeatInterval = null, viewingInterval = null, replayInterval = null;
        let isReplayMode = false, replayPlaying = false, replaySpeed = 1, replayIndex = 0, replayData = null, startTime = null, wakeLock = null;
        let currentUpdateInterval = 5000, isTracking = false, isFullscreen = false;
        const isViewer = <?php echo $isViewer ? 'true' : 'false'; ?>;
        const trackUserId = '<?php echo htmlspecialchars($trackUserId); ?>';

        async function requestWakeLock() {
            try {
                if ('wakeLock' in navigator) {
                    wakeLock = await navigator.wakeLock.request('screen');
                    wakeLock.addEventListener('release', () => console.log('Wake Lock released'));
                }
            } catch (err) {
                console.error('Wake Lock failed:', err);
            }
        }

        async function releaseWakeLock() {
            if (wakeLock !== null) {
                try {
                    await wakeLock.release();
                    wakeLock = null;
                } catch (err) {}
            }
        }

        document.addEventListener('visibilitychange', async () => {
            if (wakeLock !== null && document.visibilityState === 'visible') {
                await requestWakeLock();
            }
        });

        function initMap(lat, lng) {
            if (!map) {
                map = L.map('map').setView([lat, lng], 15);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap | <a href="https://github.com/xsukax/xsukax-Live-Location-Tracker" target="_blank">xsukax</a>',
                    maxZoom: 19
                }).addTo(map);
            } else {
                map.setView([lat, lng], 15);
            }
        }

        function updateMarker(lat, lng, label = 'Current Location') {
            if (!marker) {
                const icon = L.divIcon({
                    html: '<div style="background: #2da44e; width: 20px; height: 20px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);"></div>',
                    iconSize: [20, 20],
                    className: ''
                });
                marker = L.marker([lat, lng], { icon: icon }).addTo(map);
                marker.bindPopup(`<strong>${label}</strong>`).openPopup();
            } else {
                marker.setLatLng([lat, lng]);
                marker.getPopup().setContent(`<strong>${label}</strong>`);
                if (!isReplayMode) map.panTo([lat, lng]);
            }
        }

        function drawRoute(points) {
            if (routeLine) map.removeLayer(routeLine);
            if (points && points.length > 1) {
                const latLngs = points.map(p => [p.lat, p.lng]);
                routeLine = L.polyline(latLngs, { color: '#0969da', weight: 3, opacity: 0.7 }).addTo(map);
            }
        }

        function formatDuration(seconds) {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            const secs = seconds % 60;
            if (hours > 0) return `${hours}h ${minutes}m ${secs}s`;
            if (minutes > 0) return `${minutes}m ${secs}s`;
            return `${secs}s`;
        }

        function formatTime(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
        }

        function toggleFullscreen() {
            const container = isViewer ? document.getElementById('viewerMapContainer') : document.getElementById('mapContainer');
            
            if (!isFullscreen) {
                container.classList.add('map-fullscreen');
                document.body.style.overflow = 'hidden';
                isFullscreen = true;
            } else {
                container.classList.remove('map-fullscreen');
                document.body.style.overflow = '';
                isFullscreen = false;
            }
            
            setTimeout(() => { if (map) map.invalidateSize(); }, 100);
        }

        function showModal(title, body) {
            document.getElementById('modalTitle').textContent = title;
            document.getElementById('modalBody').textContent = body;
            document.getElementById('modal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('modal').style.display = 'none';
        }

        function copyShareUrl() {
            const url = document.getElementById('shareUrl').textContent;
            navigator.clipboard.writeText(url).then(() => {
                const original = document.getElementById('shareUrl').textContent;
                document.getElementById('shareUrl').textContent = '✓ Copied!';
                setTimeout(() => { document.getElementById('shareUrl').textContent = original; }, 2000);
            });
        }

        function setIntervalPreset(seconds) {
            document.getElementById('updateInterval').value = seconds;
            updateTrackingInterval();
        }

        function updateTrackingInterval() {
            const input = document.getElementById('updateInterval');
            let seconds = parseInt(input.value);
            if (isNaN(seconds) || seconds < 1) seconds = 1;
            if (seconds > 60) seconds = 60;
            input.value = seconds;
            
            currentUpdateInterval = seconds * 1000;
            document.getElementById('currentIntervalDisplay').textContent = seconds + 's';
            
            if (trackingInterval && !isViewer) {
                clearInterval(trackingInterval);
                startLocationTracking();
                showModal('Settings Updated', `Location updates every ${seconds} second${seconds !== 1 ? 's' : ''}.`);
            } else {
                showModal('Settings Saved', `Will update every ${seconds} second${seconds !== 1 ? 's' : ''}.`);
            }
        }

        function showReplayMode() {
            isReplayMode = true;
            if (isViewer) {
                document.getElementById('viewerStatusIndicator').className = 'status status-replay';
                document.getElementById('viewerStatusIndicator').textContent = '🎬 Replay Mode';
                document.getElementById('viewerReplayControls').style.display = 'block';
                clearInterval(viewingInterval);
            } else {
                document.getElementById('statusIndicator').className = 'status status-replay';
                document.getElementById('statusIndicator').textContent = '🎬 Replay Mode';
                document.getElementById('replayControls').style.display = 'block';
                document.getElementById('showReplayBtn').style.display = 'none';
            }
            loadReplayData();
        }

        function loadReplayData() {
            const targetUserId = isViewer ? trackUserId : userId;
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=get&userId=${targetUserId}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.data.points && data.data.points.length > 1) {
                    replayData = data.data.points;
                    replayIndex = 0;
                    drawRoute(replayData);
                    map.setView([replayData[0].lat, replayData[0].lng], 15);
                    const totalDuration = replayData[replayData.length - 1].timestamp - replayData[0].timestamp;
                    const timeElement = isViewer ? 'viewerReplayTotalTime' : 'replayTotalTime';
                    document.getElementById(timeElement).textContent = formatTime(totalDuration);
                    showModal('Replay Ready', `Journey has ${replayData.length} points spanning ${formatDuration(totalDuration)}.`);
                } else {
                    showModal('No Data', 'Not enough location data to replay.');
                }
            });
        }

        function toggleReplay() {
            if (!replayData || replayData.length < 2) return;
            replayPlaying = !replayPlaying;
            const textElement = isViewer ? 'viewerPlayPauseText' : 'playPauseText';
            document.getElementById(textElement).textContent = replayPlaying ? 'Pause' : 'Play';
            if (replayPlaying) {
                playReplay();
            } else {
                clearInterval(replayInterval);
            }
        }

        function playReplay() {
            if (!replayData || replayIndex >= replayData.length) {
                stopReplay();
                return;
            }
            replayInterval = setInterval(() => {
                if (replayIndex < replayData.length) {
                    const point = replayData[replayIndex];
                    updateMarker(point.lat, point.lng, `Point ${replayIndex + 1}`);
                    const progress = (replayIndex / (replayData.length - 1)) * 100;
                    const fillElement = isViewer ? 'viewerProgressFill' : 'progressFill';
                    document.getElementById(fillElement).style.width = progress + '%';
                    const currentTime = point.timestamp - replayData[0].timestamp;
                    const timeElement = isViewer ? 'viewerReplayCurrentTime' : 'replayCurrentTime';
                    document.getElementById(timeElement).textContent = formatTime(currentTime);
                    replayIndex++;
                } else {
                    stopReplay();
                    showModal('Replay Complete', 'Journey replay finished.');
                }
            }, 100 / replaySpeed);
        }

        function stopReplay() {
            replayPlaying = false;
            clearInterval(replayInterval);
            const textElement = isViewer ? 'viewerPlayPauseText' : 'playPauseText';
            document.getElementById(textElement).textContent = 'Play';
        }

        function restartReplay() {
            stopReplay();
            replayIndex = 0;
            const fillElement = isViewer ? 'viewerProgressFill' : 'progressFill';
            document.getElementById(fillElement).style.width = '0%';
            const timeElement = isViewer ? 'viewerReplayCurrentTime' : 'replayCurrentTime';
            document.getElementById(timeElement).textContent = '00:00';
            if (replayData && replayData.length > 0) {
                updateMarker(replayData[0].lat, replayData[0].lng, 'Start Point');
            }
        }

        function setReplaySpeed(speed) {
            replaySpeed = speed;
            if (replayPlaying) {
                stopReplay();
                toggleReplay();
            }
        }

        function seekReplay(event) {
            if (!replayData || replayData.length < 2) return;
            const rect = event.currentTarget.getBoundingClientRect();
            const x = event.clientX - rect.left;
            const percentage = x / rect.width;
            replayIndex = Math.floor(percentage * (replayData.length - 1));
            const point = replayData[replayIndex];
            updateMarker(point.lat, point.lng, `Point ${replayIndex + 1}`);
            const fillElement = isViewer ? 'viewerProgressFill' : 'progressFill';
            document.getElementById(fillElement).style.width = (percentage * 100) + '%';
            const currentTime = point.timestamp - replayData[0].timestamp;
            const timeElement = isViewer ? 'viewerReplayCurrentTime' : 'replayCurrentTime';
            document.getElementById(timeElement).textContent = formatTime(currentTime);
        }

        function stopTrackingNow() {
            if (!userId) return;
            isTracking = false;
            clearInterval(trackingInterval);
            clearInterval(heartbeatInterval);
            trackingInterval = null;
            heartbeatInterval = null;
            
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=stop&userId=${userId}`
            }).catch(err => console.error('Stop failed:', err));
            
            const formData = new FormData();
            formData.append('action', 'stop');
            formData.append('userId', userId);
            navigator.sendBeacon('', new URLSearchParams(formData));
        }

        if (!isViewer) {
            document.getElementById('startTracking').addEventListener('click', function() {
                if (!navigator.geolocation) {
                    showModal('Error', 'Geolocation not supported.');
                    return;
                }

                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        fetch('', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'action=init'
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                userId = data.userId;
                                startTime = Date.now();
                                isTracking = true;
                                
                                const intervalSeconds = parseInt(document.getElementById('initialUpdateInterval').value) || 5;
                                currentUpdateInterval = intervalSeconds * 1000;
                                document.getElementById('updateInterval').value = intervalSeconds;
                                document.getElementById('currentIntervalDisplay').textContent = intervalSeconds + 's';
                                document.getElementById('shareUrl').textContent = data.shareUrl;
                                document.getElementById('homepage').style.display = 'none';
                                document.getElementById('trackingView').style.display = 'block';
                                
                                requestWakeLock();
                                initMap(position.coords.latitude, position.coords.longitude);
                                updateMarker(position.coords.latitude, position.coords.longitude);
                                startLocationTracking();
                                startHeartbeat();
                            }
                        });
                    },
                    function(error) {
                        let message = 'Unable to get location. ';
                        if (error.code === 1) message += 'Permission denied.';
                        else if (error.code === 2) message += 'Position unavailable.';
                        else message += 'Timeout.';
                        showModal('Location Error', message);
                    },
                    { enableHighAccuracy: true }
                );
            });

            document.getElementById('stopTracking').addEventListener('click', function() {
                if (confirm('Stop tracking? Viewers will be able to replay your journey.')) {
                    stopTrackingNow();
                    document.getElementById('stopTracking').style.display = 'none';
                    document.getElementById('statusIndicator').className = 'status status-inactive';
                    document.getElementById('statusIndicator').textContent = '⏹️ Tracking Stopped';
                    document.getElementById('showReplayBtn').style.display = 'inline-block';
                    releaseWakeLock();
                    showModal('Tracking Stopped', 'Viewers can now replay your journey.');
                }
            });
        }

        function startHeartbeat() {
            heartbeatInterval = setInterval(() => {
                if (userId && isTracking) {
                    fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=heartbeat&userId=${userId}`
                    }).catch(err => console.error('Heartbeat failed:', err));
                }
            }, 10000);
        }

        function startLocationTracking() {
            trackingInterval = setInterval(() => {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        document.getElementById('currentLat').textContent = lat.toFixed(6);
                        document.getElementById('currentLng').textContent = lng.toFixed(6);
                        document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString();
                        const duration = Math.floor((Date.now() - startTime) / 1000);
                        document.getElementById('journeyDuration').textContent = formatDuration(duration);
                        updateMarker(lat, lng);
                        
                        fetch('', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `action=update&userId=${userId}&lat=${lat}&lng=${lng}`
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success) {
                                fetch('', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                    body: `action=get&userId=${userId}`
                                })
                                .then(r => r.json())
                                .then(routeData => {
                                    if (routeData.success && routeData.data.points) {
                                        drawRoute(routeData.data.points);
                                    }
                                });
                            }
                        });
                    },
                    function(error) {
                        console.error('Location error:', error);
                    },
                    { enableHighAccuracy: true, maximumAge: 0 }
                );
            }, currentUpdateInterval);
        }

        window.addEventListener('beforeunload', (e) => {
            releaseWakeLock();
            if (!isViewer && userId && isTracking) {
                e.preventDefault();
                e.returnValue = '';
                stopTrackingNow();
            }
        });

        if (isViewer) {
            requestWakeLock();
            
            function updateViewerLocation() {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=get&userId=${trackUserId}`
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.data.current) {
                        const current = data.data.current;
                        const isActive = data.data.isActive !== false;
                        
                        if (!map) initMap(current.lat, current.lng);
                        
                        if (isActive) {
                            document.getElementById('viewerStatusIndicator').className = 'status status-active';
                            document.getElementById('viewerStatusIndicator').textContent = '👁️ Viewing Live Location';
                            document.getElementById('viewerReplayBtn').style.display = 'none';
                        } else {
                            document.getElementById('viewerStatusIndicator').className = 'status status-inactive';
                            document.getElementById('viewerStatusIndicator').textContent = '⏹️ Journey Completed';
                            document.getElementById('viewerReplayBtn').style.display = 'inline-block';
                            clearInterval(viewingInterval);
                            releaseWakeLock();
                            
                            if (!isReplayMode) {
                                const lastCheck = sessionStorage.getItem('lastCheck_' + trackUserId);
                                if (lastCheck !== 'completed') {
                                    sessionStorage.setItem('lastCheck_' + trackUserId, 'completed');
                                    setTimeout(() => {
                                        showModal('Journey Completed', 'Tracking ended. You can now replay the journey.');
                                    }, 500);
                                }
                            }
                        }
                        
                        updateMarker(current.lat, current.lng);
                        if (data.data.points && data.data.points.length > 1) {
                            drawRoute(data.data.points);
                        }
                        
                        document.getElementById('viewerLat').textContent = current.lat.toFixed(6);
                        document.getElementById('viewerLng').textContent = current.lng.toFixed(6);
                        document.getElementById('viewerLastUpdate').textContent = new Date(current.timestamp * 1000).toLocaleTimeString();
                        
                        if (data.data.startTime) {
                            const duration = current.timestamp - data.data.startTime;
                            document.getElementById('viewerDuration').textContent = formatDuration(duration);
                        }
                    } else {
                        showModal('No Data', 'No location data available yet.');
                    }
                })
                .catch(err => console.error('Error:', err));
            }
            
            updateViewerLocation();
            viewingInterval = setInterval(updateViewerLocation, 5000);
        }
    </script>
</body>
</html>

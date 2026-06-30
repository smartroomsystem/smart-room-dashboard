<?php
/**
 * user_dashboard.php
 * Read-only interface for standard user profiles.
 * Uses only style.css-defined classes. No delete/write controls.
 */
session_start();

if (
    !isset($_SESSION['admin_id']) ||
    !isset($_SESSION['otp_verified']) ||
    $_SESSION['otp_verified'] !== true ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'user'
) {
    header('Location: login.php');
    exit;
}

// Auto logout after 2 hours inactivity
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 7200) {
    session_unset();
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['login_time'] = time();

$username = htmlspecialchars($_SESSION['admin_username']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Smart Room — Monitor</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__ . '/style.css'); ?>">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<div class="layout">
  <aside id="sidebar" class="sidebar">
    <div class="sidebar-brand">&#9651; SMART ROOM</div>
    <button class="nav-btn active-tab" onclick="showTab('live', event)">LIVE DASHBOARD</button>
    <button class="nav-btn" onclick="showTab('history', event)">SENSOR LOGS</button>
    <hr>
    <div class="sidebar-controls">
      <div style="font-family:'JetBrains Mono',monospace; font-size:0.65rem; color:var(--muted); text-align:center; padding:8px; border:1px solid var(--border); border-radius:4px;">
        &#9632; READ-ONLY MODE
      </div>
    </div>
  </aside>

  <main class="main-content">
    <header class="top-nav">
      <button class="toggle-btn" onclick="toggleSidebar()">☰</button>
      <input type="text" id="searchBar" placeholder="Search… or use field:value (temp:30, fan:ON, status:HIGH)" onkeyup="filterTable()">
      <div class="user-info">
        <span id="adminStatus" class="admin-badge">&#9679; Admin: --</span>
        <span class="admin-badge">&#9632; User: <?= $username ?></span>
        <a href="logout.php" class="btn-logout">LOGOUT</a>
      </div>
    </header>

    <div id="staleBanner" class="alert alert-timeout" style="display:none; margin-bottom:10px;">
      <span id="staleText">No recent sensor data.</span>
    </div>

    <!-- LIVE TAB -->
    <div id="live-tab" class="tab-content active">
      <div class="cards-grid">
        <div class="card">
          <div class="card-label">TEMPERATURE</div>
          <p id="temp" class="card-value">-- °C</p>
        </div>
        <div class="card">
          <div class="card-label">FAN STATE</div>
          <p id="fan" class="card-value">--</p>
        </div>
        <div class="card">
          <div class="card-label">CLIMATE STATUS</div>
          <p id="status" class="card-value status-disconnected">--</p>
        </div>
        <div class="card">
          <div class="card-label">HIGH TEMP EVENTS</div>
          <p id="highCount" class="card-value counter-high">0</p>
        </div>
        <div class="card">
          <div class="card-label">NORMAL EVENTS</div>
          <p id="normalCount" class="card-value counter-normal">0</p>
        </div>
      </div>

      <div class="analytics-grid">
        <div class="card summary-card min-card">
          <div class="card-label">MINIMUM TEMPERATURE</div>
          <p id="minTemp" class="card-value">-- °C</p>
        </div>
        <div class="card summary-card avg-card">
          <div class="card-label">AVERAGE TEMPERATURE</div>
          <p id="avgTemp" class="card-value">-- °C</p>
        </div>
        <div class="card summary-card max-card">
          <div class="card-label">MAXIMUM TEMPERATURE</div>
          <p id="maxTemp" class="card-value">-- °C</p>
        </div>
      </div>

      <div class="chart-container-wrapper">
        <h2 class="section-title">&#9632; REAL-TIME CLIMATE GRAPH</h2>
        <div class="canvas-holder">
          <canvas id="liveClimateChart"></canvas>
        </div>
      </div>
    </div>

    <!-- HISTORY TAB (read-only — no delete button) -->
    <div id="history-tab" class="tab-content">
      <section class="history-section">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
          <h2 class="section-title" style="margin-bottom:0;">&#9632; SENSOR HISTORY</h2>
          <button class="btn btn-secondary" style="width:auto; padding:5px 12px;" onclick="exportCSV()">&#8595; EXPORT CSV</button>
        </div>
        <div class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>TIME</th>
                <th>TEMPERATURE</th>
                <th>FAN</th>
                <th>STATUS</th>
              </tr>
            </thead>
            <tbody id="historyBody">
              <tr>
                <td colspan="4" style="text-align:center; color:var(--muted); padding:24px;">
                  Synchronizing active logs...
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

  </main>
</div>

<script>
"use strict";

let climateChart = null;
let lastRecords = [];
const MAX_GRAPH_POINTS = 15;

function toggleSidebar() {
  document.getElementById("sidebar").classList.toggle("active");
}

function showTab(tabId, event) {
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.getElementById(tabId + '-tab').classList.add('active');
  document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('active-tab'));
  if (event && event.currentTarget) event.currentTarget.classList.add('active-tab');
}

function filterTable() {
  const raw   = document.getElementById("searchBar").value.trim();
  const query = raw.toUpperCase();

  const rows = document.querySelectorAll(".data-table tbody tr");
  if (!rows.length) return;

  const FIELD_ALIASES = {
    "TEMP"   : "temp",
    "FAN"    : "fan",
    "STATUS" : "status",
    "TIME"   : "time"
  };

  let targetCol = null;
  let searchVal = query;

  const colonIdx = query.indexOf(":");
  if (colonIdx > 0) {
    const prefix = query.substring(0, colonIdx).trim();
    if (FIELD_ALIASES[prefix] !== undefined) {
      targetCol = FIELD_ALIASES[prefix];
      searchVal = query.substring(colonIdx + 1).trim();
    }
  }

  rows.forEach(row => {
    let match = false;
    if (!searchVal) {
      match = true;
    } else if (targetCol) {
      const cell = row.querySelector(`[data-col="${targetCol}"]`);
      match = cell ? cell.innerText.toUpperCase().includes(searchVal) : false;
    } else {
      match = row.innerText.toUpperCase().includes(searchVal);
    }
    row.style.display = match ? "" : "none";
  });
}

// Heartbeat while admin is connected saves a row at least every 10s,
// so a fresh row within ~15s means the admin's Arduino is actively connected.
const LIVE_THRESHOLD_MS = 15000;
const STALE_THRESHOLD_MS = 120000;

function timeAgoText(ms) {
  const sec = Math.floor(ms / 1000);
  if (sec < 60) return sec + "s ago";
  const min = Math.floor(sec / 60);
  if (min < 60) return min + "m ago";
  const hr = Math.floor(min / 60);
  return hr + "h ago";
}

function checkStaleness(latestTimestamp) {
  const banner = document.getElementById("staleBanner");
  const textEl = document.getElementById("staleText");
  const adminStatusEl = document.getElementById("adminStatus");

  if (!latestTimestamp) {
    if (banner) { banner.style.display = "block"; banner.className = "alert alert-timeout"; }
    if (textEl) textEl.innerText = "LAST DATA: -- ............ OFFLINE DISCONNECTED";
    if (adminStatusEl) {
      adminStatusEl.innerText = "● Admin: Offline";
      adminStatusEl.style.color = "var(--muted)";
    }
    return;
  }

  const ageMs = Date.now() - new Date(latestTimestamp).getTime();
  const isLive = ageMs <= LIVE_THRESHOLD_MS;

  if (banner) {
    banner.style.display = "block";
    if (ageMs > STALE_THRESHOLD_MS) {
      banner.className = "alert alert-timeout";
      if (textEl) textEl.innerText = `LAST DATA: ${timeAgoText(ageMs)} ............ OFFLINE DISCONNECTED`;
    } else {
      banner.className = "alert alert-success";
      if (textEl) textEl.innerText = "● LIVE";
    }
  }

  if (adminStatusEl) {
    if (isLive) {
      adminStatusEl.innerText = "● Admin: Connected";
      adminStatusEl.style.color = "var(--green)";
    } else {
      adminStatusEl.innerText = "● Admin: Offline";
      adminStatusEl.style.color = "var(--muted)";
    }
  }
}

function exportCSV() {
  if (!lastRecords.length) return;
  const rows = [["Time", "Temperature", "Fan", "Status"]];
  lastRecords.forEach(r => {
    rows.push([
      new Date(r.recorded_at).toLocaleString(),
      parseFloat(r.temperature).toFixed(1),
      r.fan_status,
      r.system_status
    ]);
  });
  const csvContent = rows.map(r => r.map(v => `"${v}"`).join(",")).join("\n");
  const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `sensor_history_${Date.now()}.csv`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

// Groups chronologically-ordered records into one point per minute,
// averaging multiple readings that land in the same minute.
function bucketByMinute(chronologicalRecords) {
  const buckets = new Map();
  chronologicalRecords.forEach(row => {
    const d = new Date(row.recorded_at);
    const minuteKey = `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}-${d.getHours()}-${d.getMinutes()}`;
    const tempValue = parseFloat(row.temperature);
    if (isNaN(tempValue)) return;
    if (!buckets.has(minuteKey)) buckets.set(minuteKey, { sum: 0, count: 0, date: d });
    const bucket = buckets.get(minuteKey);
    bucket.sum += tempValue;
    bucket.count += 1;
  });
  return Array.from(buckets.values()).map(bucket => ({
    label: bucket.date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
    avgTemp: parseFloat((bucket.sum / bucket.count).toFixed(1))
  }));
}

function initChart() {
  const ctx = document.getElementById('liveClimateChart').getContext('2d');
  const greenColor = getComputedStyle(document.documentElement).getPropertyValue('--green').trim() || '#00e676';
  climateChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: [],
      datasets: [{
        label: 'Temperature (°C)',
        data: [],
        borderColor: greenColor,
        backgroundColor: 'rgba(0, 230, 118, 0.05)',
        borderWidth: 2,
        pointBackgroundColor: greenColor,
        pointRadius: 4,
        tension: 0.3,
        fill: true
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        x: { grid: { color: 'rgba(0,230,118,0.03)' }, ticks: { color: '#4a6070', font: { family: 'JetBrains Mono' } } },
        y: { suggestedMin: 15, suggestedMax: 40, grid: { color: 'rgba(0,230,118,0.05)' }, ticks: { color: '#4a6070', font: { family: 'JetBrains Mono' } } }
      },
      plugins: { legend: { display: false } }
    }
  });
}

async function syncDashboard() {
  try {
    const res = await fetch('get_history.php');
    const result = await res.json();

    if (result.status !== 'success' || !result.data.length) return;

    const records = result.data;
    const latest = records[0];

    lastRecords = records;
    checkStaleness(latest.recorded_at);

    // Update live cards
    const tempVal = parseFloat(latest.temperature).toFixed(1);
    document.getElementById("temp").innerText = tempVal + " °C";
    document.getElementById("temp").style.color = latest.system_status === "HIGH TEMP" ? "var(--orange)" : "var(--green)";
    document.getElementById("fan").innerText = latest.fan_status;

    const statusEl = document.getElementById("status");
    statusEl.innerText = latest.system_status;
    statusEl.style.color = latest.system_status === "HIGH TEMP" ? "var(--orange)" : "var(--green)";

    // Counters & temperatures
    let highCount = 0, normalCount = 0, temps = [];
    records.forEach(row => {
      if (row.system_status === "HIGH TEMP") highCount++;
      else normalCount++;
      temps.push(parseFloat(row.temperature));
    });
    document.getElementById("highCount").innerText = highCount;
    document.getElementById("normalCount").innerText = normalCount;

    if (temps.length) {
      const min = Math.min(...temps), max = Math.max(...temps);
      const avg = temps.reduce((s, v) => s + v, 0) / temps.length;
      document.getElementById("minTemp").innerText = min.toFixed(1) + " °C";
      document.getElementById("avgTemp").innerText = avg.toFixed(1) + " °C";
      document.getElementById("maxTemp").innerText = max.toFixed(1) + " °C";
    }

    // Build history table (read-only — no delete column)
    const tbody = document.getElementById("historyBody");
    const rowsHtml = records.map(row => {
      const formattedTime = new Date(row.recorded_at).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit', second:'2-digit'});
      const statusClass = row.system_status === "HIGH TEMP" ? "high-temp" : "normal";
      return `
        <tr>
          <td data-col="time">${formattedTime}</td>
          <td data-col="temp">${parseFloat(row.temperature).toFixed(1)} °C</td>
          <td data-col="fan">${row.fan_status}</td>
          <td data-col="status" class="${statusClass}">${row.system_status}</td>
        </tr>`;
    }).join("");
    tbody.innerHTML = rowsHtml;

    // Chart — bucket into one averaged point per minute so the graph
    // reads "per minute" regardless of how often readings are saved.
    if (climateChart) {
      const perMinute = bucketByMinute([...records].reverse());
      const graphSlice = perMinute.slice(-MAX_GRAPH_POINTS);
      climateChart.data.labels = graphSlice.map(p => p.label);
      climateChart.data.datasets[0].data = graphSlice.map(p => p.avgTemp);
      climateChart.update('none');
    }

  } catch (err) {
    console.error("Sync error:", err);
  }
}

window.addEventListener('DOMContentLoaded', () => {
  initChart();
  syncDashboard();
  setInterval(syncDashboard, 3000);
});
</script>
</body>
</html>
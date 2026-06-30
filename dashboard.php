<?php
/**
 * dashboard.php
 * Protected page — redirects to login.php if not fully authenticated with OTP.
 * Admin-only verification layer applied.
 */
session_start();

// Session + OTP verification guard
if (
    !isset($_SESSION['admin_id']) ||
    !isset($_SESSION['otp_verified']) ||
    $_SESSION['otp_verified'] !== true
) {
    header('Location: login.php');
    exit;
}

// Restrict access exclusively to Administrators
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: user_dashboard.php');
    exit;
}

// Auto logout after 2 hours inactivity
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 7200) {
    session_unset();
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}

// Refresh session timer
$_SESSION['login_time'] = time();

$adminUsername = htmlspecialchars($_SESSION['admin_username']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Smart Room — Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="./style.css?v=<?php echo filemtime(__DIR__ . '/style.css'); ?>">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<div class="layout">
  <aside id="sidebar" class="sidebar">
    <div class="sidebar-brand">&#9651; SMART ROOM</div>
    <button class="nav-btn active-tab" onclick="showTab('live', event)">LIVE DASHBOARD</button>
    <button class="nav-btn" onclick="showTab('history', event)">SENSOR LOGS</button>
    <button class="nav-btn" onclick="showTab('activity', event)">USER ACTIVITY</button>
    <hr>
    <div class="sidebar-controls">
        <button class="btn btn-primary" onclick="connectSerial()">&#9632; CONNECT</button>
        <button class="btn btn-danger" onclick="disconnectArduino()">&#9632; DISCONNECT</button>
        <button class="btn btn-secondary" onclick="clearData()">&#9003; CLEAR LOGS</button>
    </div>
  </aside>

  <main class="main-content">
    <header class="top-nav">
      <button class="toggle-btn" onclick="toggleSidebar()">☰</button>
      <input type="text" id="searchBar" placeholder="Search… or use field:value (temp:30, fan:ON, status:HIGH)" onkeyup="filterTable()">
      <div class="user-info">
        <span class="admin-badge">&#9632; Admin: <?= $adminUsername ?></span>
        <a href="logout.php" class="btn-logout">LOGOUT</a>
      </div>
    </header>

    <div id="staleBanner" class="alert alert-timeout" style="display:none; margin-bottom:10px;">
      <span id="staleText">No recent sensor data.</span>
    </div>

    <div id="live-tab" class="tab-content active">
      <div class="cards-grid">
        <div class="card">
          <div class="card-label">TEMPERATURE</div>
          <p id="temp" class="card-value">-- °C</p>
        </div>
        <div class="card">
          <div class="card-label">FAN STATE</div>
          <p id="fan" class="card-value">OFF</p>
        </div>
        <div class="card">
          <div class="card-label">SYSTEM STATUS</div>
          <p id="status" class="card-value status-disconnected">DISCONNECTED</p>
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

    <div id="history-tab" class="tab-content">
      <section class="history-section">
        <h2 class="section-title">&#9632; SENSOR HISTORY</h2>
        <div class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>TIME</th>
                <th>TEMPERATURE</th>
                <th>FAN</th>
                <th>STATUS</th>
                <th>ACTION</th>
              </tr>
            </thead>
            <tbody id="historyBody"></tbody>
          </table>
        </div>
      </section>
    </div>

    <div id="activity-tab" class="tab-content">
      <section class="history-section">
        <h2 class="section-title">&#9632; USER ACTIVITY LOGS</h2>
        <div class="table-wrapper">
          <table class="data-table">
              <thead>
                <tr>
                  <th>USER</th>
                  <th>ACTION</th>
                  <th>IP ADDRESS</th>
                  <th>TIMESTAMP</th>
                </tr>
              </thead>
              <tbody id="activityBody">
                <tr>
                  <td colspan="4" style="text-align: center; color: var(--muted); padding: 24px;">Loading activity logs...</td>
                </tr>
              </tbody>
          </table>
        </div>
      </section>
    </div>
  </main>
</div>

<script src="script.js?v=<?php echo filemtime(__DIR__ . '/script.js'); ?>"></script>
</body>
</html>
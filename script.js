/**
 * script.js — Smart Room Climate Control
 * Web Serial API bridge to Arduino DHT11 system with active MySQL synchronization.
 *
 * History table reads from get_history.php (sensor_history raw rows).
 * Stats (min/avg/max) and chart data are pulled from minute_avg.php.
 * State (cards, chart, counters) is preserved across polls; nothing resets
 * unless the user disconnects or logs out.
 */

"use strict";

let port, reader, writer;
let isReading = false;
let lastTemp = null, lastStatus = null;

// Chart.js instance handle
let climateChart = null;
const MAX_GRAPH_POINTS = 15;

// Auto-refresh history every 3s when not connected to serial
let historyPollTimer = null;

// Track what was last written to the DB so we only insert on a real
// change or on a periodic heartbeat — not on every 500ms serial line.
let lastSavedTemp = null, lastSavedFan = null, lastSavedStatus = null, lastSaveTime = 0;
const DB_HEARTBEAT_MS = 10000; // persist a row at least this often even if unchanged

// ─── PER-MINUTE BUCKETING (matches user dashboard graph) ──────
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

// ─── STALE DATA BANNER ──────────────────────────────────────
const STALE_THRESHOLD_MS = 120000; // 2 minutes — no new row in this long = offline

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
    if (!banner) return;

    banner.style.display = "block";

    if (!latestTimestamp) {
        banner.className = "alert alert-timeout";
        if (textEl) textEl.innerText = "LAST DATA: -- ............ OFFLINE DISCONNECTED";
        return;
    }

    const ageMs = Date.now() - new Date(latestTimestamp).getTime();
    if (ageMs > STALE_THRESHOLD_MS) {
        banner.className = "alert alert-timeout";
        if (textEl) textEl.innerText = `LAST DATA: ${timeAgoText(ageMs)} ............ OFFLINE DISCONNECTED`;
    } else {
        banner.className = "alert alert-success";
        if (textEl) textEl.innerText = "● LIVE";
    }
}

// ─── INITIALIZE CHART ────────────────────────────────────────
function initClimateChart() {
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
                x: {
                    grid: { color: 'rgba(0, 230, 118, 0.03)' },
                    ticks: { color: '#4a6070', font: { family: 'JetBrains Mono' } }
                },
                y: {
                    grid: { color: 'rgba(0, 230, 118, 0.05)' },
                    ticks: { color: '#4a6070', font: { family: 'JetBrains Mono' } },
                    suggestedMin: 20,
                    suggestedMax: 35
                }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });
}

// ─── CONNECT ─────────────────────────────────────────────────
async function connectSerial() {
    if (!("serial" in navigator)) {
        showToast("Web Serial API is not supported.", "error");
        return;
    }

    try {
        port = await navigator.serial.requestPort();
        await port.open({ baudRate: 9600 });

        // Give DHT11 time to stabilize after power-on before first read
        await new Promise(resolve => setTimeout(resolve, 2500));

        const encoder = new TextEncoderStream();
        encoder.readable.pipeTo(port.writable);
        writer = encoder.writable.getWriter();

        const decoder = new TextDecoderStream();
        port.readable.pipeTo(decoder.writable);
        reader = decoder.readable.getReader();

        isReading = true;
        await writer.write("CMD_START\n");
        sessionStorage.setItem("sr_was_connected", "1");
        setStatus("CONNECTED", "normal");
        showToast("Arduino Connected", "success");

        // Serial is now live — stop the passive 3s poll. The table/chart/
        // counters are instead refreshed directly from
        // saveRecordToDatabase() every time a reading is successfully
        // written, so nothing goes stale while connected.
        stopHistoryPolling();
        readData();
    } catch (err) {
        console.error(err);
        showToast("Connection Failed", "error");
    }
}

// ─── DISCONNECT ──────────────────────────────────────────────
async function disconnectArduino() {
    if (!port && !reader) {
        showToast("No device is currently connected.", "info");
        return;
    }

    isReading = false;
    stopHistoryPolling();

    // Signal the Arduino to stop all outputs before closing the port
    if (writer) {
        try { await writer.write("CMD_STOP\n"); } catch (_) {}
    }

    // Small delay so the Arduino has time to process CMD_STOP
    await new Promise(resolve => setTimeout(resolve, 300));

    if (reader) {
        try { reader.releaseLock(); } catch (_) {}
        reader = null;
    }

    if (writer) {
        try { writer.releaseLock(); } catch (_) {}
        writer = null;
    }

    if (port) {
        try { await port.close(); } catch (_) {}
        port = null;
    }

    lastTemp = null;
    lastStatus = null;
    sessionStorage.removeItem("sr_was_connected");

    // Clear all live cards to empty state
    document.getElementById("temp").innerText        = "-- °C";
    document.getElementById("temp").style.color      = "var(--green, #00e676)";
    document.getElementById("fan").innerText         = "--";
    document.getElementById("highCount").innerText   = "0";
    document.getElementById("normalCount").innerText = "0";
    document.getElementById("minTemp").innerText     = "-- °C";
    document.getElementById("avgTemp").innerText     = "-- °C";
    document.getElementById("maxTemp").innerText     = "-- °C";

    setStatus("DISCONNECTED", "disconnected");
    showToast("Arduino Disconnected — waiting for reconnect…", "info");

    // Resume passive DB polling to keep history table and chart updated
    startHistoryPolling();
}

// ─── HISTORY POLLING ─────────────────────────────────────────
function startHistoryPolling() {
    stopHistoryPolling();
    fetchAndRenderTable();
    historyPollTimer = setInterval(fetchAndRenderTable, 3000);
}

function stopHistoryPolling() {
    if (historyPollTimer) {
        clearInterval(historyPollTimer);
        historyPollTimer = null;
    }
}

// ─── READ DATA ───────────────────────────────────────────────
async function readData() {
    let buffer = "";
    while (isReading) {
        const { value, done } = await reader.read();
        if (done) break;

        buffer += value;
        const lines = buffer.split("\n");
        buffer = lines.pop();

        for (const line of lines) {
            const data = line.trim();
            if (!data) continue;

            const parts = data.split(",");
            if (parts.length !== 3) continue;

            const tempRaw = parseFloat(parts[0]);
            const status  = parts[1].trim();
            const fan     = parts[2].trim();

            // Guard against sensor noise / bad reads
            if (isNaN(tempRaw) || tempRaw < 15 || tempRaw > 45) {
                console.warn("Discarding out-of-range reading from serial:", tempRaw);
                continue;
            }

            const temp = tempRaw.toFixed(1);

            // Update live cards immediately from serial
            document.getElementById("temp").innerText = temp + " °C";
            document.getElementById("temp").style.color = status === "HIGH TEMP" ? "var(--orange, #ff5722)" : "var(--green, #00e676)";
            document.getElementById("fan").innerText  = fan;
            setStatus(status, status === "HIGH TEMP" ? "high" : "normal");

            lastTemp   = temp;
            lastStatus = status;

            // Write to DB only when something changed or heartbeat elapsed
            const now = Date.now();
            const changed = temp !== lastSavedTemp || fan !== lastSavedFan || status !== lastSavedStatus;
            if (changed || (now - lastSaveTime) >= DB_HEARTBEAT_MS) {
                lastSavedTemp   = temp;
                lastSavedFan    = fan;
                lastSavedStatus = status;
                lastSaveTime    = now;
                saveRecordToDatabase(temp, fan, status);
            }
        }
    }
}

// ─── DATABASE SAVE ────────────────────────────────────────────
async function saveRecordToDatabase(temp, fan, status) {
    try {
        const res = await fetch("save_reading.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                temperature: temp,
                fan_status: fan,
                system_status: status
            })
        });

        const result = await res.json();

        if (result.status === "success") {
            // FIX: while connected, the 3s poll is stopped (see
            // connectSerial()), so nothing else refreshes the table,
            // chart, or counters. Trigger that refresh here, right after
            // each successful write, so the UI reflects the new row
            // immediately instead of only updating after Disconnect.
            fetchAndRenderTable();
        } else {
            console.error("save_reading.php rejected the write:", result.message);
            showToast("Save failed: " + result.message, "error");
        }
    } catch (error) {
        console.error("Failed to connect to save_reading.php:", error);
        showToast("Network error while saving reading.", "error");
    }
}

// ─── FETCH & RENDER TABLE (from get_history.php) ─────────────
// Reads raw sensor_history rows. Live cards and chart are updated from
// this data when serial is NOT connected. When serial IS connected,
// the live cards are driven by readData() above.
async function fetchAndRenderTable() {
    try {
        const [historyRes, statsRes] = await Promise.all([
            fetch("get_history.php"),
            fetch("minute_avg.php")
        ]);

        const historyResult = await historyRes.json();
        const statsResult   = await statsRes.json();

        if (!historyRes.ok || historyResult.status !== "success") {
            console.error("Failed to retrieve sensor history:", historyResult.message);
            return;
        }

        const rows  = historyResult.data; // newest-first
        const tbody = document.getElementById("historyBody");

        checkStaleness(rows.length ? rows[0].recorded_at : null);

        // ── Live cards (temp/fan/status) are only updated by readData() when
        //    serial is connected. Counters and min/avg/max, however, reflect
        //    the whole dataset and must always be refreshed from this poll,
        //    connected or not — otherwise they stay stuck at 0 / "--". ──

        // ── HIGH/NORMAL counters from the same rows used in the table ──
        let highCount = 0, normalCount = 0;
        rows.forEach(row => {
            if (row.system_status === "HIGH TEMP") highCount++;
            else normalCount++;
        });
        const highCountEl   = document.getElementById("highCount");
        const normalCountEl = document.getElementById("normalCount");
        if (highCountEl)   highCountEl.innerText   = highCount;
        if (normalCountEl) normalCountEl.innerText = normalCount;

        // ── MIN/AVG/MAX from minute_avg.php's aggregate stats ──
        if (statsResult.status === "success" && statsResult.stats) {
            const { min, avg, max } = statsResult.stats;
            const minEl = document.getElementById("minTemp");
            const avgEl = document.getElementById("avgTemp");
            const maxEl = document.getElementById("maxTemp");
            if (minEl) minEl.innerText = (min !== null ? min.toFixed(1) : "--") + " °C";
            if (avgEl) avgEl.innerText = (avg !== null ? avg.toFixed(1) : "--") + " °C";
            if (maxEl) maxEl.innerText = (max !== null ? max.toFixed(1) : "--") + " °C";
        }

        // ── Live cards (temp/fan/status) are only updated by readData() when
        //    serial is connected. When disconnected they stay as placeholders
        //    (set by disconnectArduino() / the USB disconnect handler). ──

        // ── Render history table (single write — avoids the lag/jank from
        //    rebuilding and re-parsing the growing HTML string row-by-row) ──
        if (!rows.length) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:var(--muted); padding:24px;">No sensor records yet.</td></tr>`;
        } else {
            const rowsHtml = rows.map(row => {
                const formattedTime = new Date(row.recorded_at).toLocaleString([], {
                    month: 'short', day: '2-digit',
                    hour: '2-digit', minute: '2-digit', second: '2-digit'
                });
                const statusClass = row.system_status === "HIGH TEMP" ? "high-temp" : "normal";
                return `
                  <tr>
                    <td data-col="time">${formattedTime}</td>
                    <td data-col="temp">${parseFloat(row.temperature).toFixed(1)} °C</td>
                    <td data-col="fan">${row.fan_status}</td>
                    <td data-col="status" class="${statusClass}">${row.system_status}</td>
                    <td>
                      <button onclick="deleteRecord(${row.id})">DELETE</button>
                    </td>
                  </tr>`;
            }).join("");
            tbody.innerHTML = rowsHtml;
        }

        // ── Update chart: bucket raw rows into one averaged point per
        //    minute (same logic as the user dashboard) instead of relying
        //    on minute_avg.php's raw 15-row slice. Temp log/table above is
        //    untouched — this only changes what feeds the graph. ──
        if (climateChart && rows.length) {
            const perMinute = bucketByMinute([...rows].reverse());
            const graphSlice = perMinute.slice(-MAX_GRAPH_POINTS);
            climateChart.data.labels = graphSlice.map(p => p.label);
            climateChart.data.datasets[0].data = graphSlice.map(p => p.avgTemp);
            climateChart.update('none');
        }

    } catch (error) {
        console.error("Error fetching sensor history:", error);
    }
}

// ─── DELETE SINGLE RECORD ─────────────────────────────────────
async function deleteRecord(id) {
    if (!confirm("Delete this record?")) return;

    try {
        const response = await fetch("delete_reading.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id })
        });

        const result = await response.json();
        if (result.status === "success") {
            showToast("Record deleted.", "success");
            fetchAndRenderTable();
        } else {
            showToast("Delete failed: " + result.message, "error");
        }
    } catch (error) {
        console.error("Error deleting record:", error);
    }
}

// ─── CLEAR ALL DATA ───────────────────────────────────────────
async function clearData() {
    if (!confirm("Clear all sensor history from the database? This cannot be undone.")) return;

    try {
        const response = await fetch("delete_reading.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action: "clear_all" })
        });

        if (response.ok) {
            showToast("Database sensor log successfully cleared.", "success");
            fetchAndRenderTable();
        }
    } catch (error) {
        console.error("Error clearing entire history dataset:", error);
    }
}

// ─── STATUS HELPER ────────────────────────────────────────────
function setStatus(text, type) {
    const el = document.getElementById("status");
    el.innerText = text;
    el.className = "card-value";
    if (type === "high")        el.style.color = "var(--orange, #ff5722)";
    else if (type === "normal") el.style.color = "var(--green, #00e676)";
    else                        el.style.color = "var(--muted, #4a6070)";
}

// ─── TOAST ───────────────────────────────────────────────────
function showToast(message, type = "info") {
    const toast = document.createElement("div");
    const colors = { success: "#00e676", error: "#ff1744", info: "#4a90d9" };
    toast.style.cssText = `
        position:fixed; bottom:24px; right:24px; z-index:9999;
        background:#0d1318; border:1px solid ${colors[type] || "#4a6070"};
        color:${colors[type] || "#c8d8e8"}; padding:12px 18px;
        font-family:'JetBrains Mono',monospace; font-size:0.8rem;
        border-radius:4px; box-shadow:0 4px 20px rgba(0,0,0,0.4);
    `;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3500);
}

// ─── SIDEBAR / TAB HELPERS ────────────────────────────────────
function toggleSidebar() {
    document.getElementById("sidebar").classList.toggle("active");
}

function showTab(tabId, event) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.getElementById(tabId + '-tab').classList.add('active');
    document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('active-tab'));
    if (event && event.currentTarget) {
        event.currentTarget.classList.add('active-tab');
    }
    // Re-apply current search filter when switching tabs
    filterTable();
}

// ─── USER ACTIVITY LOG ────────────────────────────────────────
let activityPollTimer = null;

function startActivityPolling() {
    stopActivityPolling();
    fetchAndRenderActivity();
    activityPollTimer = setInterval(fetchAndRenderActivity, 5000);
}

function stopActivityPolling() {
    if (activityPollTimer) {
        clearInterval(activityPollTimer);
        activityPollTimer = null;
    }
}

async function fetchAndRenderActivity() {
    const tbody = document.getElementById("activityBody");
    if (!tbody) return;

    try {
        const response = await fetch("get_activity.php");
        const result = await response.json();

        if (!response.ok || result.status !== "success") {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:var(--muted);">Unable to load activity logs.</td></tr>`;
            return;
        }

        const logs = result.data;

        if (!logs.length) {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:var(--muted);">No activity recorded yet.</td></tr>`;
            return;
        }

        tbody.innerHTML = "";
        logs.forEach(row => {
            const formattedTime = new Date(row.created_at).toLocaleString([], {
                month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit'
            });
            tbody.innerHTML += `
              <tr>
                <td data-col="user">${row.email}</td>
                <td data-col="action">${row.action}</td>
                <td data-col="ip">${row.ip_address}</td>
                <td data-col="time">${formattedTime}</td>
              </tr>`;
        });
    } catch (error) {
        console.error("Error fetching activity logs:", error);
        tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; color:var(--muted);">Unable to load activity logs.</td></tr>`;
    }
}

// ─── SMART SEARCH ─────────────────────────────────────────────
function filterTable() {
    const raw   = document.getElementById("searchBar").value.trim();
    const query = raw.toUpperCase();

    const activeTab = document.querySelector('.tab-content.active');
    if (!activeTab) return;

    const rows = activeTab.querySelectorAll(".data-table tbody tr");
    if (!rows.length) return;

    const FIELD_ALIASES = {
        "TEMP"   : "temp",
        "FAN"    : "fan",
        "STATUS" : "status",
        "TIME"   : "time",
        "USER"   : "user",
        "ACTION" : "action",
        "IP"     : "ip"
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

// ─── USB DISCONNECT EVENT ────────────────────────────────────
if ("serial" in navigator) {
    navigator.serial.addEventListener("disconnect", async () => {
        isReading = false;
        stopHistoryPolling();

        if (writer) {
            try { await writer.write("CMD_STOP\n"); } catch (_) {}
        }
        try {
            if (reader) { reader.releaseLock(); reader = null; }
            if (writer) { writer.releaseLock(); writer = null; }
            port = null;
        } catch (_) {}

        lastTemp = null;
        lastStatus = null;
        sessionStorage.removeItem("sr_was_connected");

        // Clear all live cards to empty state
        document.getElementById("temp").innerText        = "-- °C";
        document.getElementById("temp").style.color      = "var(--green, #00e676)";
        document.getElementById("fan").innerText         = "--";
        document.getElementById("highCount").innerText   = "0";
        document.getElementById("normalCount").innerText = "0";
        document.getElementById("minTemp").innerText     = "-- °C";
        document.getElementById("avgTemp").innerText     = "-- °C";
        document.getElementById("maxTemp").innerText     = "-- °C";

        setStatus("DISCONNECTED", "disconnected");
        showToast("USB Device Removed — waiting for reconnect…", "error");

        startHistoryPolling();
    });
}

// ─── ON LOAD ─────────────────────────────────────────────────
window.addEventListener("load", () => {
    initClimateChart();

    // Web Serial ports cannot survive a page refresh.
    const hadPort = sessionStorage.getItem("sr_was_connected");
    if (hadPort) {
        sessionStorage.removeItem("sr_was_connected");
        showToast("Page refreshed — please reconnect the Arduino.", "info");
    }

    setStatus("DISCONNECTED", "disconnected");

    // Populate history table and keep it refreshing
    startHistoryPolling();

    // Activity log polls every 5s regardless of tab
    startActivityPolling();
});

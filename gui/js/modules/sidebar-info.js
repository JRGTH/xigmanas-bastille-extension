// modules/sidebar-info.js

import {
  currentFileData,
  setCurrentFileData,
  getBaseFormData,
} from "./state.js";

export async function showFileInfo(filePath) {
  const sidebar = document.getElementById("ide-info-sidebar");
  const content = document.getElementById("info-sidebar-content");

  sidebar.classList.add("open");
  content.innerHTML =
    '<div style="text-align:center; padding-top:50px; color:#adb5bd;">Loading...</div>';

  const formData = getBaseFormData();
  formData.append("ajax_get_info", "1");
  formData.append("target_path", filePath);

  try {
    const response = await fetch(window.location.href, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
    });
    setCurrentFileData(await response.json());

    if (currentFileData.success) {
      // Update Header
      document.getElementById("sidebar-filename").innerText =
        currentFileData.name;
      // Force Overview tab when opening
      switchTab("overview", document.querySelector(".tab-link"));
    }
  } catch (e) {
    console.error("[showFileInfo] error:", e);
    content.innerHTML =
      '<div class="modern-card" style="color:red">Connection error</div>';
  }
}

// --- FILE INFO SIDEBAR LOGIC ---
export function closeInfoSidebar() {
  document.getElementById("ide-info-sidebar").classList.remove("open");
}

export function switchTab(tabName, element) {
  if (!currentFileData) return;

  // // UI: Manage active classes
  if (element) {
    document
      .querySelectorAll(".tab-link")
      .forEach((t) => t.classList.remove("active"));
    element.classList.add("active");
  }

  const content = document.getElementById("info-sidebar-content");
  let html = "";

  if (tabName === "overview") {
    html = `
            <div class="modern-card">
                <div class="card-label">Item Type</div>
                <div class="card-value">${currentFileData.type}</div>
            </div>
            <div class="modern-card">
                <div class="card-label">Last Modified</div>
                <div class="card-value">${currentFileData.modified}</div>
            </div>
            <div class="modern-card">
                <div class="card-label">Full Path</div>
                <div class="card-value" style="font-size:11px; color:#6c757d;">${currentFileData.path}</div>
            </div>
        `;
  } else if (tabName === "security") {
    html = `
            <div class="modern-card">
                <div class="card-label">Permissions</div>
                <div class="card-value">${currentFileData.permissions} (${currentFileData.octal})</div>
            </div>
            <div class="modern-card">
                <div class="card-label">Ownership</div>
                <div class="card-value">${currentFileData.owner}:${currentFileData.group}</div>
            </div>
        `;
  } else if (tabName === "storage") {
    html = `
            <div class="modern-card">
                <div class="card-label">Actual Size</div>
                <div class="card-value">${currentFileData.size}</div>
            </div>
            <div class="modern-card">
                <div class="card-label">Volume Impact</div>
                <div id="storage-chart-container" style="min-height: 220px;"></div>
            </div>
        `;
  }

  content.innerHTML = html;

  // Graphics rendering
  if (tabName === "storage") {
    setTimeout(() => {
      renderStorageChart();
    }, 100);
  }
}

/*
 * We created a donut chart to show the free and used space for the selected file or directory.
 */
export function renderStorageChart() {
  if (!currentFileData || !currentFileData.chart) {
    return;
  }

  let realUsage = currentFileData.chart.usage;

  let visualUsage = realUsage > 0 && realUsage < 1 ? 1 : realUsage;
  let visualOthers = 100 - visualUsage;

  const chartColor = realUsage > 50 ? "#e74c3c" : "#3875d6";

  const options = {
    series: [visualUsage, visualOthers],
    chart: {
      type: "donut",
      height: 220,
      fontFamily: "Inter, sans-serif",
    },
    colors: [chartColor, "#78AAFF"],
    labels: ["This Item", "Free Space"],
    stroke: { show: true, colors: "#ffffff", width: 2 },
    dataLabels: { enabled: false },
    fill: { type: "gradient" },
    states: {
      hover: {
        filter: {
          type: "darken",
          value: 0.15,
        },
      },
      active: {
        allowMultipleDataPointsSelection: false,
        filter: {
          type: "darken",
          value: 0.2,
        },
      },
    },
    tooltip: {
      theme: "dark",
      fillSeriesColor: false,
      y: {
        formatter: function (val, opts) {
          if (opts.seriesIndex === 0) return realUsage + "% of total volume";
          return (100 - realUsage).toFixed(2) + "% of total volume";
        },
      },
    },

    plotOptions: {
      pie: {
        donut: {
          size: "78%",
          labels: {
            show: true,
            name: {
              show: true,
              color: "#adb5bd",
              fontSize: "11px",
              fontWeight: 700,
            },

            value: {
              show: true,
              color: "#1a1e23",
              fontSize: "18px",
              fontWeight: 600,
              formatter: function (val) {
                let numVal = parseFloat(val);
                if (isNaN(numVal)) {
                  return val;
                }
                if (numVal === visualUsage) {
                  return realUsage + "%";
                }
                return numVal.toFixed(2) + "%";
              },
            },
            total: {
              show: true,
              showAlways: true,
              label: "ITEM SIZE",
              color: "#adb5bd",
              formatter: function () {
                return currentFileData.size;
              },
            },
          },
        },
      },
    },
    legend: {
      position: "bottom",
      fontSize: "12px",
      markers: { radius: 12 },
    },
  };

  const chartContainer = document.querySelector("#storage-chart-container");
  chartContainer.innerHTML = "";

  const chart = new ApexCharts(chartContainer, options);
  chart.render();
}

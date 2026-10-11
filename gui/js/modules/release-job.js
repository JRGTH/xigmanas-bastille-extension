// modules/release-job.js
// Starts a download/destroy job, polls its log and re-attaches to it when the page is
// opened again. Needs window.RELEASE_CONFIG (see release-api.js) and the elements:
// #btn-download / #btn-destroy (data-job-action), #log-container, #job-status, #log-area,
// #partial-box (buttons with data-partial-action + data-release), and the form fields
// release_item, lib32, ports and src.

import { releaseCfg, postAction, fetchStatus, fetchPage } from "./release-api.js";
import { initLog, resetLog, appendLog } from "./release-log.js";

const $ = (id) => document.getElementById(id);
const field = (name) => document.getElementsByName(name)[0];

let offset = 0;
let timer = null;
let polling = false;
// True once this page started a job or saw one running: refresh the lists when it ends.
let watching = false;

export function initReleaseJob() {
  initLog($("log-area"));
  document.addEventListener("click", onClick);
  startPolling(); // re-attach to a running job, or show the last result
}

// --- events -----------------------------------------------------------------------------

function onClick(event) {
  const jobButton = event.target.closest("[data-job-action]");
  if (jobButton) {
    startJob(jobButton.dataset.jobAction, field("release_item").value);
    return;
  }
  const partialButton = event.target.closest("[data-partial-action]");
  if (partialButton) {
    const { partialAction, release } = partialButton.dataset;
    if (partialAction === "resume") {
      field("release_item").value = release;
      startJob("bootstrap", release);
    } else {
      discardPartial(release);
    }
  }
}

// --- actions ----------------------------------------------------------------------------

async function startJob(mode, release) {
  if (!release) {
    return;
  }
  if (mode === "destroy" && !confirm(releaseCfg.text.confirmDestroy)) {
    return;
  }
  setBusy(true);
  try {
    const result = await postAction({
      action: "start",
      mode,
      release,
      lib32: field("lib32").checked,
      ports: field("ports").checked,
      src: field("src").checked,
    });
    if (!result.ok) {
      alert(result.error);
      setBusy(false);
      return;
    }
    offset = 0;
    watching = true;
    resetLog();
    $("log-container").style.display = "block";
    startPolling();
  } catch (error) {
    alert(error);
    setBusy(false);
  }
}

async function discardPartial(release) {
  if (!confirm(releaseCfg.text.confirmDiscard)) {
    return;
  }
  try {
    const result = await postAction({ action: "discard", release });
    if (!result.ok) {
      alert(result.error ?? "Error");
    }
  } catch (error) {
    alert(error);
  }
  refreshLists();
}

// --- polling ----------------------------------------------------------------------------

function startPolling() {
  if (!timer) {
    timer = setInterval(poll, releaseCfg.pollMs);
  }
  poll();
}

function stopPolling() {
  clearInterval(timer);
  timer = null;
}

async function poll() {
  if (polling) {
    return;
  }
  polling = true;
  try {
    const status = await fetchStatus(offset);
    if (status.state === "none") {
      stopPolling();
      setBusy(false);
      return;
    }
    $("log-container").style.display = "block";
    if (status.text) {
      offset = status.offset;
      appendLog(status.text);
    }
    showState(status);
  } catch {
    // Network hiccup: the job keeps running on the server, keep polling.
    setStatus(releaseCfg.text.connectionLost);
  } finally {
    polling = false;
  }
}

function showState({ state, mode, release }) {
  const isDownload = mode === "bootstrap";
  const text = releaseCfg.text;
  if (state === "running") {
    watching = true;
    setBusy(true);
    setStatus(`${isDownload ? text.downloading : text.destroying} ${release} ...`);
    return;
  }

  stopPolling();
  setBusy(false);
  const messages = {
    done: text.completed,
    failed: isDownload ? text.failedDownload : text.failedDestroy,
    interrupted: isDownload ? text.interruptedDownload : text.interruptedDestroy,
  };
  setStatus(`${release}: ${messages[state]}`);
  if (watching) {
    watching = false;
    refreshLists();
  }
}

// Refresh the "Installed Base" list and the partial downloads from the server without
// reloading the page (a reload would flicker the log).
async function refreshLists() {
  try {
    const doc = await fetchPage();
    const swap = (selector, property) => {
      const fresh = doc.querySelector(selector);
      const current = document.querySelector(selector);
      if (fresh && current) {
        current[property] = fresh[property];
      }
    };
    swap("table.area_data_settings thead", "innerHTML");
    swap("#partial-box", "innerHTML");
    swap('input[name="authtoken"]', "value");
  } catch {
    // The lists refresh on the next page load.
  }
}

// --- UI state ---------------------------------------------------------------------------

function setBusy(busy) {
  $("btn-download").disabled = $("btn-destroy").disabled = busy;
  document.querySelectorAll(".partial-btn").forEach((button) => {
    button.disabled = busy;
  });
}

function setStatus(text) {
  const element = $("job-status");
  if (element.textContent !== text) {
    element.textContent = text;
  }
}

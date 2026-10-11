// modules/release-notify.js
// Notification of a finished release job, shared by the Base Releases page and by any
// other Bastille page that wants to tell the user about it (see release_watch_main.js).
//
// A running job is remembered in localStorage so that the notification the user would
// otherwise miss is shown when they come back. Same idea as "bastille_pending_job" for the
// editor, but a separate key: the editor expects a compression job in that one.

import { releaseCfg, fetchStatus } from "./release-api.js";
import { showNotification } from "./notification.js";

const PENDING_KEY = "bastille_pending_release_job";
// Asking for the status with an offset past the end of the log avoids transferring the log.
const NO_LOG = Number.MAX_SAFE_INTEGER;

export function savePending(mode, release) {
  try {
    localStorage.setItem(PENDING_KEY, JSON.stringify({ mode, release }));
  } catch {
    // Storage blocked: the notification for a job finished while away is simply not shown.
  }
}

export function hasPending() {
  try {
    return localStorage.getItem(PENDING_KEY) !== null;
  } catch {
    return false;
  }
}

// Take the pending entry. True for whoever gets it first, so that two tabs watching the same
// job do not both notify.
export function claimPending() {
  try {
    if (localStorage.getItem(PENDING_KEY) === null) {
      return false;
    }
    localStorage.removeItem(PENDING_KEY);
    return true;
  } catch {
    return false;
  }
}

// "Download of version 14.4-RELEASE completed successfully." and its variants.
function notificationBody(mode, state, release) {
  const operation = mode === "bootstrap" ? "Download" : "Destroy";
  const result = { done: "Done", failed: "Failed", interrupted: "Interrupted" }[state];
  return releaseCfg.text[`notify${operation}${result}`].replace("%s", release);
}

export function notifyEnded(mode, state, release) {
  showNotification(releaseCfg.text.notificationTitle, notificationBody(mode, state, release));
}

// For pages that do not show the job: if one was left running, wait for it to end and notify.
export function watchPendingRelease() {
  if (!hasPending()) {
    return;
  }
  const timer = setInterval(check, releaseCfg.pollMs);
  check();

  async function check() {
    try {
      const status = await fetchStatus(NO_LOG);
      if (status.state === "running") {
        return;
      }
      clearInterval(timer);
      if (claimPending() && status.state !== "none") {
        notifyEnded(status.mode, status.state, status.release);
      }
    } catch {
      // Network hiccup: try again on the next tick.
    }
  }
}

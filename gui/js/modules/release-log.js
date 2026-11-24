// modules/release-log.js
// Shows the raw job log, emulating what a terminal does with \r and ANSI colors.

let area = null;
let raw = "";
let lastText = null;
let textNode = null;

export function initLog(areaElement) {
  area = areaElement;
}

export function resetLog() {
  raw = "";
  renderLog();
}

export function appendLog(chunk) {
  raw += chunk;
  renderLog();
}

function renderLog() {
  // fetch progress lines overwrite themselves with \r.
  let text = "";
  for (const ch of raw.replace(/\x1b\[[0-9;]*[A-Za-z]/g, "")) {
    if (ch === "\r") {
      text = text.slice(0, text.lastIndexOf("\n") + 1);
    } else {
      text += ch;
    }
  }
  if (text === lastText) {
    return; // nothing visible changed: leave the DOM alone (avoids flicker)
  }
  lastText = text;

  const atBottom = area.scrollHeight - area.scrollTop - area.clientHeight < 30;
  if (!textNode) {
    area.textContent = "";
    textNode = document.createTextNode("");
    area.appendChild(textNode);
  }
  textNode.data = text;
  if (atBottom) {
    area.scrollTop = area.scrollHeight;
  }
}

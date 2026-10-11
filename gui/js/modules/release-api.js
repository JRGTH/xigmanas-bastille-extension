// modules/release-api.js
// Requests of the "Base Releases" page to bastille_manager_tarballs.php.

export const releaseCfg = window.RELEASE_CONFIG;

const authToken = () =>
  document.querySelector('input[name="authtoken"]')?.value ?? "";

// POST an action (start / discard). Resolves with the decoded JSON payload.
export async function postAction(params) {
  const body = new FormData();
  body.append("authtoken", authToken());
  for (const [key, value] of Object.entries(params)) {
    body.append(key, value);
  }
  const response = await fetch(releaseCfg.endpoint, { method: "POST", body });
  if (!response.ok) {
    throw new Error(`HTTP ${response.status}`);
  }
  return response.json();
}

// Job state and the log text written since `offset`.
export async function fetchStatus(offset) {
  const response = await fetch(
    `${releaseCfg.endpoint}?action=status&offset=${offset}`,
    { cache: "no-store" },
  );
  return response.json();
}

// The page itself, parsed, to refresh parts of it without reloading.
export async function fetchPage() {
  const response = await fetch(releaseCfg.endpoint, { cache: "no-store" });
  return new DOMParser().parseFromString(await response.text(), "text/html");
}

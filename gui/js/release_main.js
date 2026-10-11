// release_main.js
// Entry point of the "Base Releases" page (bastille_manager_tarballs.php).
// main.js is the editor's entry point, so this page has its own.

import { initReleaseJob } from "./modules/release-job.js";

document.addEventListener("DOMContentLoaded", initReleaseJob);

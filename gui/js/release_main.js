// release_main.js
// Entry point of the "Base Releases" page (bastille_manager_tarballs.php).
// Each view has its own entry point (the editor's is editor_main.js).

import { initReleaseJob } from "./modules/release-job.js";

document.addEventListener("DOMContentLoaded", initReleaseJob);

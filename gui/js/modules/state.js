// modules/state.js

export const cfg = window.IDE_CONFIG;

// Editor state
export let searchTimer      = null;
export let selectedIndex    = -1;
export let isDirty          = false;
export let isInjectingCode  = false;
export let sidebarTimer     = null;
export let originalSidebarHTML = '';
export let currentFileData  = null;
export let diffEditorInstance  = null;
export let currentDiffFilepath = '';
export let contextMenu      = null;
export let cmTargetData     = null;

// Disk health state
export let diskWatcherInterval = null;
export let diskHealthChart     = null;
export const diskHealthHistory = { avg: [], hot: [] };

// Setters — necesarios porque ES6 exports son live bindings solo para const
export function setIsDirty(v)             { isDirty = v; }
export function setIsInjectingCode(v)     { isInjectingCode = v; }
export function setCurrentFileData(v)     { currentFileData = v; }
export function setDiffEditorInstance(v)  { diffEditorInstance = v; }
export function setCurrentDiffFilepath(v) { currentDiffFilepath = v; }
export function setContextMenu(v)         { contextMenu = v; }
export function setCmTargetData(v)        { cmTargetData = v; }
export function setDiskWatcherInterval(v) { diskWatcherInterval = v; }
export function setDiskHealthChart(v)     { diskHealthChart = v; }
export function setSearchTimer(v)         { searchTimer = v; }
export function setSidebarTimer(v)        { sidebarTimer = v; }
export function setSelectedIndex(v)       { selectedIndex = v; }
export function setOriginalSidebarHTML(v) { originalSidebarHTML = v; }
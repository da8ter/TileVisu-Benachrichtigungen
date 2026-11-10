// Einrichtung öffnen: nach der Installation und beim Klick auf das Symbol.
chrome.runtime.onInstalled.addListener(function (info) {
  if (info.reason === 'install') chrome.runtime.openOptionsPage();
});
chrome.action.onClicked.addListener(function () { chrome.runtime.openOptionsPage(); });

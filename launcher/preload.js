const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('api', {
    getStatus:      ()       => ipcRenderer.invoke('get-status'),
    startWatcher:   ()       => ipcRenderer.invoke('start-watcher'),
    stopWatcher:    ()       => ipcRenderer.invoke('stop-watcher'),
    gitPull:        ()       => ipcRenderer.invoke('git-pull'),
    buildZip:       ()       => ipcRenderer.invoke('build-zip'),
    openWpAdmin:    ()       => ipcRenderer.invoke('open-wp-admin'),
    openFolder:     (folder) => ipcRenderer.invoke('open-folder', folder),
    onLog:          (cb)     => ipcRenderer.on('log', (_, data) => cb(data)),
    onWatcherStatus:(cb)     => ipcRenderer.on('watcher-status', (_, running) => cb(running)),
});

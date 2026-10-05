function database() {
    return new Promise((resolve, reject) => {
        if (!globalThis.indexedDB) { reject(new Error('This browser cannot save media drafts.')); return; }
        const request = globalThis.indexedDB.open('spectra-campaign-media', 1);
        request.onupgradeneeded = () => request.result.createObjectStore('drafts');
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}
async function transaction(key, mode, run) {
    const db = await database();
    try {
        return await new Promise((resolve, reject) => {
            const tx = db.transaction('drafts', mode);
            const request = run(tx.objectStore('drafts'), key);
            tx.oncomplete = () => resolve(request.result);
            tx.onerror = () => reject(tx.error);
            tx.onabort = () => reject(tx.error || new Error('Media draft save was interrupted.'));
        });
    } finally { db.close(); }
}
export const loadMediaDraft = key => transaction(key, 'readonly', (store, id) => store.get(id));
export const saveMediaDraft = (key, media) => transaction(key, 'readwrite', (store, id) => store.put(media, id));
export const deleteMediaDraft = key => transaction(key, 'readwrite', (store, id) => store.delete(id));

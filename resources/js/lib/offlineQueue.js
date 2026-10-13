function openDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('makerly-offline', 1);
        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains('sales')) {
                db.createObjectStore('sales', { keyPath: 'id', autoIncrement: true });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

export async function queueSale(record) {
    const db = await openDb();
    await new Promise((resolve, reject) => {
        const tx = db.transaction('sales', 'readwrite');
        tx.objectStore('sales').add(record);
        tx.oncomplete = resolve;
        tx.onerror = () => reject(tx.error);
    });
    db.close();
}

export async function pendingSales(tenantId) {
    const db = await openDb();
    const rows = await new Promise((resolve, reject) => {
        const tx = db.transaction('sales', 'readonly');
        const request = tx.objectStore('sales').getAll();
        request.onsuccess = () => resolve(request.result || []);
        request.onerror = () => reject(request.error);
    });
    db.close();

    return rows.filter((row) => row.tenant_id === tenantId);
}

export async function clearSales(tenantId) {
    const db = await openDb();
    const rows = await pendingSales(tenantId);
    await new Promise((resolve, reject) => {
        const tx = db.transaction('sales', 'readwrite');
        rows.forEach((row) => tx.objectStore('sales').delete(row.id));
        tx.oncomplete = resolve;
        tx.onerror = () => reject(tx.error);
    });
    db.close();
}

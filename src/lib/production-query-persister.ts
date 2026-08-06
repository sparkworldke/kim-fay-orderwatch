import type { PersistedClient, Persister } from "@tanstack/react-query-persist-client";

const DATABASE = "kimfay-production-cache";
const STORE = "tanstack-query";
const KEY = "production-v1";

function openDatabase(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DATABASE, 1);
    request.onupgradeneeded = () => {
      if (!request.result.objectStoreNames.contains(STORE)) request.result.createObjectStore(STORE);
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function transact<T>(mode: IDBTransactionMode, action: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  const database = await openDatabase();
  return new Promise((resolve, reject) => {
    const request = action(database.transaction(STORE, mode).objectStore(STORE));
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  }).finally(() => database.close());
}

export const productionQueryPersister: Persister = {
  persistClient: async (client) => {
    if (typeof indexedDB === "undefined") return;
    await transact("readwrite", (store) => store.put(client, KEY));
  },
  restoreClient: async () => {
    if (typeof indexedDB === "undefined") return undefined;
    return transact<PersistedClient | undefined>("readonly", (store) => store.get(KEY));
  },
  removeClient: async () => {
    if (typeof indexedDB === "undefined") return;
    await transact("readwrite", (store) => store.delete(KEY));
  },
};

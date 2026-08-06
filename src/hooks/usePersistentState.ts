import { useCallback, useEffect, useState } from "react";

export function usePersistentState<T>(key: string, initial: T) {
  const [value, setValue] = useState<T>(initial);
  const [hydrated, setHydrated] = useState(false);

  useEffect(() => {
    try {
      const raw = window.localStorage.getItem(key);
      if (raw) setValue({ ...initial, ...(JSON.parse(raw) as T) });
    } catch {
      /* ignore malformed storage */
    }
    setHydrated(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);

  useEffect(() => {
    if (!hydrated) return;
    try {
      window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
      /* storage unavailable */
    }
  }, [key, value, hydrated]);

  const reset = useCallback(() => setValue(initial), [initial]);

  return { value, setValue, reset, hydrated };
}
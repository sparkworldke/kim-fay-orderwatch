/** Keep only values that are still valid; used to auto-clear dependent selections. */
export function reconcileSelection(selected: string[], valid: string[]): string[] {
  const set = new Set(valid);
  const next = selected.filter((v) => set.has(v));
  return next.length === selected.length ? selected : next;
}

export function toggleValue<T>(list: T[], value: T): T[] {
  return list.includes(value) ? list.filter((v) => v !== value) : [...list, value];
}

export const matchesSearch = (search: string, fields: (string | undefined)[]) => {
  const q = search.trim().toLowerCase();
  if (!q) return true;
  return fields.some((f) => (f ?? "").toLowerCase().includes(q));
};
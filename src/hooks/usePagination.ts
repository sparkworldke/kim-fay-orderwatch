import { useCallback, useState } from "react";

/**
 * Shared page/per-page state for list views. Changing perPage resets to page 1,
 * matching the reset-on-change behavior every route previously hand-rolled.
 */
export function usePagination(initialPerPage = 20) {
  const [page, setPage] = useState(1);
  const [perPage, setPerPageState] = useState(initialPerPage);

  const setPerPage = useCallback((next: number) => {
    setPerPageState(next);
    setPage(1);
  }, []);

  const resetPage = useCallback(() => setPage(1), []);

  return { page, perPage, setPage, setPerPage, resetPage };
}

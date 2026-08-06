import { useInfiniteQuery } from "@tanstack/react-query";
import { useEffect, useMemo } from "react";
import { inventoryService } from "@/services/Stock/inventory.service";

export function useProductionInventory(ownership: "manufactured" | "partner") {
  const query = useInfiniteQuery({
    queryKey: ["production-stock", ownership],
    initialPageParam: 1,
    queryFn: ({ pageParam }) => inventoryService.getInventoryPage(ownership, pageParam),
    getNextPageParam: (lastPage) => lastPage.page < lastPage.lastPage ? lastPage.page + 1 : undefined,
    staleTime: 60_000,
    gcTime: 24 * 60 * 60 * 1000,
    refetchOnWindowFocus: false,
  });

  useEffect(() => {
    if (!query.hasNextPage || query.isFetchingNextPage) return;
    let cancelled = false;
    const load = () => {
      if (!cancelled) void query.fetchNextPage();
    };
    const idleWindow = window as Window & {
      requestIdleCallback?: (callback: () => void, options?: { timeout: number }) => number;
      cancelIdleCallback?: (id: number) => void;
    };
    const id = idleWindow.requestIdleCallback
      ? idleWindow.requestIdleCallback(load, { timeout: 1500 })
      : window.setTimeout(load, 500);
    return () => {
      cancelled = true;
      if (idleWindow.cancelIdleCallback && idleWindow.requestIdleCallback) idleWindow.cancelIdleCallback(id);
      else window.clearTimeout(id);
    };
  }, [query.hasNextPage, query.isFetchingNextPage, query.fetchNextPage]);

  const items = useMemo(() => query.data?.pages.flatMap((page) => page.items) ?? [], [query.data]);
  return { ...query, items, total: query.data?.pages[0]?.total ?? 0 };
}

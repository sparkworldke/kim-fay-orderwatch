import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiFetch, downloadApiFile } from "@/lib/api";

export type ExportDownloadType = "backorders" | "fill_rate" | "inventory" | "items_not_delivered";

export type ExportDownload = {
  id: number;
  type: ExportDownloadType | string;
  type_label: string;
  status: "queued" | "processing" | "ready" | "failed" | "expired" | string;
  filename: string | null;
  size_bytes: number | null;
  row_count: number | null;
  filters: Record<string, unknown> | null;
  error_message: string | null;
  started_at: string | null;
  finished_at: string | null;
  expires_at: string | null;
  downloaded_at: string | null;
  created_at: string | null;
  download_url: string | null;
  ready: boolean;
  recipient_email?: string | null;
  delivery_mode?: "dashboard" | "attachment" | "link" | string;
  public_download_url?: string | null;
  emailed_at?: string | null;
};

export function useExportDownloads(poll = false) {
  return useQuery({
    queryKey: ["export-downloads"],
    queryFn: () => apiFetch<{ data: ExportDownload[] }>("downloads"),
    refetchInterval: poll ? 5_000 : false,
  });
}

export function useQueueExportDownload() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: {
      type: ExportDownloadType;
      filters?: Record<string, string | number | boolean | undefined | null>;
      recipient_email?: string;
      delivery_mode?: "dashboard" | "attachment" | "link";
    }) => {
      const filters: Record<string, string | number | boolean> = {};
      for (const [k, v] of Object.entries(body.filters ?? {})) {
        if (v === undefined || v === null || v === "") continue;
        filters[k] = v;
      }
      return apiFetch<{ message: string; download: ExportDownload }>("downloads", {
        method: "POST",
        body: {
          type: body.type,
          filters,
          recipient_email: body.recipient_email || undefined,
          delivery_mode: body.delivery_mode ?? "dashboard",
        },
      });
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["export-downloads"] });
    },
  });
}

export async function downloadExportFile(id: number, filename?: string | null) {
  await downloadApiFile(
    `downloads/${id}/file`,
    filename || `export-${id}.xlsx`,
    { timeoutMs: 180_000 },
  );
}

export function useDeleteExportDownload() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) =>
      apiFetch<{ message: string }>(`downloads/${id}`, { method: "DELETE" }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["export-downloads"] });
    },
  });
}

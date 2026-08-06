import { useEffect, useMemo, useState } from "react";
import {
  Download,
  ExternalLink,
  FileSpreadsheet,
  FileText,
  Image as ImageIcon,
  Loader2,
} from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { API_BASE_URL } from "@/lib/api";
import { getToken } from "@/lib/auth";
import { cn } from "@/lib/utils";

export type AttachmentRef = {
  id: number;
  original_name?: string | null;
  name?: string | null;
  mime?: string | null;
  content_type?: string | null;
  size?: number | null;
  kind?: string | null;
  download_url?: string | null;
  view_url?: string | null;
  preview_url?: string | null;
};

type PreviewPayload = {
  kind: "image" | "pdf" | "table" | "text" | "binary" | string;
  name: string;
  mime: string | null;
  size: number;
  sheets?: Array<{ name: string; headers: string[]; rows: string[][] }>;
  text?: string;
  message?: string;
  download_url?: string;
  view_url?: string;
};

function fileName(att: AttachmentRef): string {
  return att.original_name || att.name || `file-${att.id}`;
}

function formatBytes(n?: number | null): string {
  if (!n || n <= 0) return "";
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

function resolveUrl(pathOrUrl: string | null | undefined): string | null {
  if (!pathOrUrl) return null;
  if (pathOrUrl.startsWith("http://") || pathOrUrl.startsWith("https://")) return pathOrUrl;
  const base = API_BASE_URL.replace(/\/api\/?$/, "");
  return `${base}${pathOrUrl.startsWith("/") ? "" : "/"}${pathOrUrl}`;
}

async function authFetch(url: string, init?: RequestInit): Promise<Response> {
  const token = getToken();
  const headers = new Headers(init?.headers);
  if (token) headers.set("Authorization", `Bearer ${token}`);
  headers.set("Accept", init?.headers && (init.headers as Record<string, string>)["Accept"]
    ? (init.headers as Record<string, string>)["Accept"]
    : "application/json");
  return fetch(url, { ...init, headers });
}

/** Chip list + optional auto-open viewer. */
export function AttachmentList({
  attachments,
  className,
}: {
  attachments: AttachmentRef[];
  className?: string;
}) {
  const [active, setActive] = useState<AttachmentRef | null>(null);

  if (!attachments?.length) {
    return <p className="text-sm text-muted-foreground">No attachments.</p>;
  }

  return (
    <>
      <div className={cn("flex flex-wrap gap-2", className)}>
        {attachments.map((att) => (
          <button
            key={att.id}
            type="button"
            onClick={() => setActive(att)}
            className="inline-flex max-w-full items-center gap-1.5 rounded-md border bg-muted/30 px-2.5 py-1.5 text-left text-xs transition-colors hover:border-primary hover:bg-primary/5"
          >
            <KindIcon kind={att.kind} name={fileName(att)} />
            <span className="truncate font-medium">{fileName(att)}</span>
            {att.size ? (
              <span className="shrink-0 text-muted-foreground">{formatBytes(att.size)}</span>
            ) : null}
          </button>
        ))}
      </div>
      <AttachmentViewerDialog
        attachment={active}
        open={!!active}
        onOpenChange={(open) => !open && setActive(null)}
      />
    </>
  );
}

export function AttachmentViewerDialog({
  attachment,
  open,
  onOpenChange,
}: {
  attachment: AttachmentRef | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const [preview, setPreview] = useState<PreviewPayload | null>(null);
  const [blobUrl, setBlobUrl] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [sheetIndex, setSheetIndex] = useState(0);
  const [error, setError] = useState<string | null>(null);

  const name = attachment ? fileName(attachment) : "";
  const previewUrl = attachment
    ? resolveUrl(attachment.preview_url ?? `/api/kp/fol/attachments/${attachment.id}/preview`)
    : null;
  const viewUrl = attachment
    ? resolveUrl(attachment.view_url ?? `/api/kp/fol/attachments/${attachment.id}/view`)
    : null;
  const downloadUrl = attachment
    ? resolveUrl(attachment.download_url ?? `/api/kp/fol/attachments/${attachment.id}/download`)
    : null;

  useEffect(() => {
    if (!open || !attachment || !previewUrl) {
      setPreview(null);
      setError(null);
      return;
    }

    let cancelled = false;
    let objectUrl: string | null = null;

    async function load() {
      setLoading(true);
      setError(null);
      setPreview(null);
      setBlobUrl(null);
      setSheetIndex(0);
      try {
        const res = await authFetch(previewUrl!);
        if (!res.ok) {
          throw new Error(`Preview failed (${res.status})`);
        }
        const data = (await res.json()) as PreviewPayload;
        if (cancelled) return;
        setPreview(data);

        if ((data.kind === "image" || data.kind === "pdf") && viewUrl) {
          const fileRes = await authFetch(viewUrl, {
            headers: { Accept: data.mime || "*/*" },
          });
          if (!fileRes.ok) throw new Error(`Unable to load file (${fileRes.status})`);
          const blob = await fileRes.blob();
          objectUrl = URL.createObjectURL(blob);
          if (!cancelled) setBlobUrl(objectUrl);
        }
      } catch (e) {
        if (!cancelled) {
          setError(e instanceof Error ? e.message : "Unable to load preview");
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    void load();

    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [open, attachment?.id, previewUrl, viewUrl]);

  async function handleDownload() {
    if (!downloadUrl) return;
    try {
      const res = await authFetch(downloadUrl, { headers: { Accept: "*/*" } });
      if (!res.ok) throw new Error(`Download failed (${res.status})`);
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = name;
      a.click();
      URL.revokeObjectURL(url);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : "Download failed");
    }
  }

  const sheet = preview?.sheets?.[sheetIndex];

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[92vh] w-[min(96vw,1100px)] max-w-[1100px] flex-col gap-0 overflow-hidden p-0">
        <DialogHeader className="shrink-0 border-b px-4 py-3">
          <div className="flex items-start justify-between gap-3 pr-8">
            <div className="min-w-0">
              <DialogTitle className="truncate text-base">{name}</DialogTitle>
              <DialogDescription className="mt-0.5 flex flex-wrap items-center gap-2 text-xs">
                {preview?.kind && (
                  <Badge variant="outline" className="text-[10px] uppercase">
                    {preview.kind}
                  </Badge>
                )}
                <span>{formatBytes(preview?.size ?? attachment?.size)}</span>
                {preview?.mime && <span className="text-muted-foreground">{preview.mime}</span>}
              </DialogDescription>
            </div>
            <div className="flex shrink-0 gap-1.5">
              <Button type="button" size="sm" variant="outline" onClick={() => void handleDownload()}>
                <Download className="mr-1 h-3.5 w-3.5" /> Download
              </Button>
              {viewUrl && (
                <Button type="button" size="sm" variant="ghost" asChild>
                  <a href={viewUrl} target="_blank" rel="noreferrer">
                    <ExternalLink className="mr-1 h-3.5 w-3.5" /> Open
                  </a>
                </Button>
              )}
            </div>
          </div>
        </DialogHeader>

        <div className="min-h-0 flex-1 overflow-auto bg-muted/20 p-4">
          {loading && (
            <div className="flex flex-col items-center justify-center gap-3 py-16 text-sm text-muted-foreground">
              <Loader2 className="h-8 w-8 animate-spin" />
              Loading preview…
            </div>
          )}

          {!loading && error && (
            <div className="rounded-md border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
              {error}
              <div className="mt-3">
                <Button size="sm" variant="outline" onClick={() => void handleDownload()}>
                  Download instead
                </Button>
              </div>
            </div>
          )}

          {!loading && !error && preview?.kind === "image" && blobUrl && (
            <div className="flex justify-center">
              <img
                src={blobUrl}
                alt={name}
                className="max-h-[70vh] max-w-full rounded-md border bg-white object-contain shadow-sm"
              />
            </div>
          )}

          {!loading && !error && preview?.kind === "pdf" && blobUrl && (
            <iframe
              title={name}
              src={blobUrl}
              className="h-[70vh] w-full rounded-md border bg-white shadow-sm"
            />
          )}

          {!loading && !error && preview?.kind === "table" && preview.sheets && (
            <div className="space-y-3">
              {preview.sheets.length > 1 && (
                <Select value={String(sheetIndex)} onValueChange={(v) => setSheetIndex(Number(v))}>
                  <SelectTrigger className="h-9 w-56 bg-background">
                    <SelectValue placeholder="Sheet" />
                  </SelectTrigger>
                  <SelectContent>
                    {preview.sheets.map((s, i) => (
                      <SelectItem key={s.name + i} value={String(i)}>
                        {s.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
              {sheet && (
                <div className="overflow-auto rounded-md border bg-card shadow-sm">
                  <table className="w-full min-w-max text-xs">
                    <thead className="sticky top-0 bg-muted/80 backdrop-blur">
                      <tr>
                        {sheet.headers.map((h, i) => (
                          <th
                            key={i}
                            className="border-b px-3 py-2 text-left font-semibold whitespace-nowrap"
                          >
                            {h || `Col ${i + 1}`}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody className="divide-y">
                      {sheet.rows.length === 0 && (
                        <tr>
                          <td
                            colSpan={Math.max(sheet.headers.length, 1)}
                            className="px-3 py-8 text-center text-muted-foreground"
                          >
                            No rows in this sheet.
                          </td>
                        </tr>
                      )}
                      {sheet.rows.map((row, ri) => (
                        <tr key={ri} className="hover:bg-muted/30">
                          {row.map((cell, ci) => (
                            <td key={ci} className="max-w-[280px] truncate px-3 py-1.5 align-top">
                              {cell}
                            </td>
                          ))}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  {sheet.rows.length >= 500 && (
                    <p className="border-t px-3 py-2 text-[11px] text-muted-foreground">
                      Showing first 500 rows. Download the file for the full workbook.
                    </p>
                  )}
                </div>
              )}
            </div>
          )}

          {!loading && !error && preview?.kind === "text" && (
            <pre className="max-h-[70vh] overflow-auto whitespace-pre-wrap rounded-md border bg-card p-4 text-xs leading-relaxed">
              {preview.text || "(empty)"}
            </pre>
          )}

          {!loading && !error && preview?.kind === "binary" && (
            <div className="flex flex-col items-center justify-center gap-3 py-16 text-center">
              <FileText className="h-10 w-10 text-muted-foreground/50" />
              <p className="text-sm text-muted-foreground">
                {preview.message || "Preview not available for this file type."}
              </p>
              <Button size="sm" onClick={() => void handleDownload()}>
                <Download className="mr-1 h-3.5 w-3.5" /> Download file
              </Button>
            </div>
          )}

          {!loading && !error && !preview && (
            <Skeleton className="h-48 w-full" />
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
}

function KindIcon({ kind, name }: { kind?: string | null; name: string }) {
  const k = kind || guessKind(name);
  if (k === "image") return <ImageIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />;
  if (k === "table") return <FileSpreadsheet className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />;
  return <FileText className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />;
}

function guessKind(name: string): string {
  const ext = name.split(".").pop()?.toLowerCase() ?? "";
  if (["jpg", "jpeg", "png", "gif", "webp"].includes(ext)) return "image";
  if (["xlsx", "xls", "csv"].includes(ext)) return "table";
  if (ext === "pdf") return "pdf";
  return "binary";
}

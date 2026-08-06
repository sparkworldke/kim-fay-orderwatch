import { Link } from "@tanstack/react-router";
import { CheckCircle2, Download } from "lucide-react";
import { useEffect, useState } from "react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  downloadExportFile,
  useExportDownloads,
  type ExportDownload,
} from "@/hooks/useExportDownloads";

const NOTIFIED_KEY = "kf-ready-download-notifications";

function notifiedIds(): number[] {
  try {
    const parsed = JSON.parse(window.sessionStorage.getItem(NOTIFIED_KEY) ?? "[]");
    return Array.isArray(parsed) ? parsed.filter((id): id is number => Number.isInteger(id)) : [];
  } catch {
    return [];
  }
}

function rememberNotification(id: number) {
  const ids = new Set(notifiedIds());
  ids.add(id);
  window.sessionStorage.setItem(NOTIFIED_KEY, JSON.stringify([...ids].slice(-100)));
}

export function DownloadReadyNotifier() {
  const { data } = useExportDownloads(true);
  const [readyDownload, setReadyDownload] = useState<ExportDownload | null>(null);
  const [downloading, setDownloading] = useState(false);

  useEffect(() => {
    if (readyDownload || !data?.data.length) return;

    const notified = new Set(notifiedIds());
    const next = data.data.find(
      (download) => download.ready && !download.downloaded_at && !notified.has(download.id),
    );
    if (!next) return;

    rememberNotification(next.id);
    setReadyDownload(next);
  }, [data, readyDownload]);

  async function startDownload() {
    if (!readyDownload) return;
    setDownloading(true);
    try {
      await downloadExportFile(readyDownload.id, readyDownload.filename);
      toast.success("Download started.");
      setReadyDownload(null);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to download file.");
    } finally {
      setDownloading(false);
    }
  }

  return (
    <Dialog open={Boolean(readyDownload)} onOpenChange={(open) => !open && setReadyDownload(null)}>
      <DialogContent className="max-w-sm text-center">
        <DialogHeader className="items-center text-center">
          <div className="mb-2 grid h-12 w-12 place-items-center rounded-full bg-emerald-100 text-emerald-700">
            <CheckCircle2 className="h-6 w-6" />
          </div>
          <DialogTitle>Your download is ready</DialogTitle>
          <DialogDescription>
            {readyDownload?.type_label} has finished processing
            {readyDownload?.filename ? `: ${readyDownload.filename}` : "."}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter className="mt-2 flex-col gap-2 sm:flex-col sm:space-x-0">
          <Button onClick={() => void startDownload()} disabled={downloading}>
            <Download className="mr-2 h-4 w-4" />
            {downloading ? "Starting download…" : "Download file"}
          </Button>
          <Button asChild variant="outline">
            <Link to="/app/downloads" onClick={() => setReadyDownload(null)}>
              View all downloads
            </Link>
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

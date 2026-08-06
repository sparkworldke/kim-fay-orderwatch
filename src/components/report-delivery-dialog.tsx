import { useState } from "react";
import { FileSpreadsheet, Loader2, Mail } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader,
  DialogTitle, DialogTrigger,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { useQueueExportDownload, type ExportDownloadType } from "@/hooks/useExportDownloads";
import { getErrorMessage } from "@/lib/api";

const REPORTS: Array<{ value: ExportDownloadType; label: string }> = [
  { value: "inventory", label: "Inventory" },
  { value: "backorders", label: "Backorders" },
  { value: "fill_rate", label: "Fill Rate" },
  { value: "items_not_delivered", label: "Items Not Delivered (MTD)" },
];

export function ReportDeliveryDialog({ defaultEmail = "" }: { defaultEmail?: string }) {
  const queue = useQueueExportDownload();
  const [open, setOpen] = useState(false);
  const [type, setType] = useState<ExportDownloadType>("inventory");
  const [email, setEmail] = useState(defaultEmail);
  const [delivery, setDelivery] = useState<"dashboard" | "attachment" | "link">("dashboard");

  const submit = async () => {
    if (delivery !== "dashboard" && !email.trim()) {
      toast.error("Enter the recipient's email address.");
      return;
    }
    try {
      await queue.mutateAsync({
        type,
        recipient_email: delivery === "dashboard" ? undefined : email.trim(),
        delivery_mode: delivery,
      });
      toast.success(delivery === "dashboard"
        ? "Excel report queued. You will be notified when it is ready."
        : `Excel report queued for ${email.trim()}.`);
      setOpen(false);
    } catch (error) {
      toast.error(getErrorMessage(error, "Could not queue the report."));
    }
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button variant="ghost" size="icon" aria-label="Export or email report" title="Export or email report">
          <FileSpreadsheet className="h-4 w-4" />
        </Button>
      </DialogTrigger>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Export Excel report</DialogTitle>
          <DialogDescription>
            Download from the dashboard or email the report as an attachment or an expiring public link.
          </DialogDescription>
        </DialogHeader>
        <div className="grid gap-3">
          <label className="grid gap-1">
            <span className="text-xs font-medium">Report</span>
            <Select value={type} onValueChange={(value) => setType(value as ExportDownloadType)}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                {REPORTS.map((report) => <SelectItem key={report.value} value={report.value}>{report.label}</SelectItem>)}
              </SelectContent>
            </Select>
          </label>
          <label className="grid gap-1">
            <span className="text-xs font-medium">Delivery</span>
            <Select value={delivery} onValueChange={(value) => setDelivery(value as typeof delivery)}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="dashboard">Dashboard download</SelectItem>
                <SelectItem value="attachment">Email attachment</SelectItem>
                <SelectItem value="link">Email public download link</SelectItem>
              </SelectContent>
            </Select>
          </label>
          {delivery !== "dashboard" && (
            <label className="grid gap-1">
              <span className="text-xs font-medium">Recipient email</span>
              <Input
                type="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                placeholder="name@company.com"
                autoComplete="email"
              />
            </label>
          )}
          {delivery === "link" && (
            <p className="rounded-md bg-muted px-3 py-2 text-[10px] text-muted-foreground">
              The recipient will not need to log in. The private link expires after three days.
            </p>
          )}
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
          <Button onClick={submit} disabled={queue.isPending}>
            {queue.isPending ? <Loader2 className="mr-1 h-4 w-4 animate-spin" /> : <Mail className="mr-1 h-4 w-4" />}
            Queue report
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

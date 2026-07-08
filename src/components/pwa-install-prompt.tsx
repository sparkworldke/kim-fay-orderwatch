import { Download, Share, X } from "lucide-react";
import { useEffect, useState } from "react";
import { Button } from "@/components/ui/button";

const DISMISS_KEY = "orderwatch_pwa_dismissed";

type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
};

function isStandalone(): boolean {
  return (
    window.matchMedia("(display-mode: standalone)").matches ||
    ("standalone" in navigator && Boolean((navigator as Navigator & { standalone?: boolean }).standalone))
  );
}

function isIos(): boolean {
  return /iphone|ipad|ipod/i.test(navigator.userAgent);
}

export function PwaInstallPrompt() {
  const [deferredPrompt, setDeferredPrompt] = useState<BeforeInstallPromptEvent | null>(null);
  const [showIosHint, setShowIosHint] = useState(false);
  const [visible, setVisible] = useState(false);
  const [installing, setInstalling] = useState(false);

  useEffect(() => {
    if (typeof window === "undefined") return;

    // The service worker caches JS chunks aggressively — in dev those go stale
    // on every code change and cause hydration errors. Keep dev browsers clean.
    if (import.meta.env.DEV) {
      if ("serviceWorker" in navigator) {
        navigator.serviceWorker.getRegistrations().then((regs) => {
          regs.forEach((reg) => reg.unregister());
        });
      }
      if ("caches" in window) {
        caches.keys().then((keys) => keys.forEach((key) => caches.delete(key)));
      }
      return;
    }

    if (isStandalone()) return;
    if (localStorage.getItem(DISMISS_KEY) === "1") return;

    if ("serviceWorker" in navigator) {
      navigator.serviceWorker.register("/sw.js").catch((error) => {
        console.warn("Service worker registration failed", error);
      });
    }

    const onBeforeInstall = (event: Event) => {
      event.preventDefault();
      setDeferredPrompt(event as BeforeInstallPromptEvent);
      setVisible(true);
    };

    window.addEventListener("beforeinstallprompt", onBeforeInstall);

    if (isIos()) {
      setShowIosHint(true);
      setVisible(true);
    }

    return () => window.removeEventListener("beforeinstallprompt", onBeforeInstall);
  }, []);

  function dismiss() {
    localStorage.setItem(DISMISS_KEY, "1");
    setVisible(false);
  }

  async function install() {
    if (!deferredPrompt) return;
    setInstalling(true);
    try {
      await deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      if (outcome === "accepted") {
        setVisible(false);
      }
    } finally {
      setDeferredPrompt(null);
      setInstalling(false);
    }
  }

  if (!visible) return null;

  return (
    <div
      role="region"
      aria-label="Install app"
      className="fixed bottom-4 left-4 right-4 z-50 mx-auto max-w-md rounded-xl border bg-card p-4 shadow-lg sm:left-auto sm:right-4"
    >
      <div className="flex items-start gap-3">
        <div className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white shadow-sm">
          <img src="/kim-fay-logo.png" alt="" className="h-7 w-auto object-contain" />
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold text-foreground">Install OrderWatch</p>
          {showIosHint && !deferredPrompt ? (
            <p className="mt-1 text-xs text-muted-foreground">
              Tap <Share className="inline h-3.5 w-3.5" /> Share, then &quot;Add to Home Screen&quot; to install on
              iPhone or iPad.
            </p>
          ) : (
            <p className="mt-1 text-xs text-muted-foreground">
              Add to your home screen or desktop for quick access — works on mobile and desktop.
            </p>
          )}
          <div className="mt-3 flex flex-wrap gap-2">
            {deferredPrompt && (
              <Button size="sm" onClick={install} disabled={installing}>
                <Download className="mr-1.5 h-3.5 w-3.5" />
                {installing ? "Installing…" : "Install app"}
              </Button>
            )}
            <Button size="sm" variant="ghost" onClick={dismiss}>
              Not now
            </Button>
          </div>
        </div>
        <button
          type="button"
          onClick={dismiss}
          className="shrink-0 rounded-md p-1 text-muted-foreground hover:text-foreground"
          aria-label="Dismiss install prompt"
        >
          <X className="h-4 w-4" />
        </button>
      </div>
    </div>
  );
}
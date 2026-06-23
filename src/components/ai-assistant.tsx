import { useEffect, useRef, useState } from "react";
import { useRouterState } from "@tanstack/react-router";
import { Bot, Send, Sparkles, X, Loader2, RotateCcw, AlertTriangle, TrendingUp, TrendingDown, Minus, ExternalLink } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { ScrollArea } from "@/components/ui/scroll-area";
import { Badge } from "@/components/ui/badge";
import { apiFetch } from "@/lib/api";
import { cn } from "@/lib/utils";

// ── Types ───────────────────────────────────────────────────────────────────

interface Message {
  role: "user" | "assistant";
  content: string;
  cards?: InsightCard[];
  sources?: string[];
  actions?: ActionItem[];
}

interface InsightCard {
  type: "kpi" | "comparison" | "risk" | "customer" | "match" | "action";
  title: string;
  value: string | number;
  subtitle?: string;
  severity?: "low" | "medium" | "high";
  trend?: { direction: "up" | "down" | "flat"; percent?: number; label?: string };
  items?: { label: string; value: string | number }[];
}

interface ActionItem {
  label: string;
  url: string;
}

interface AiChatResponse {
  message: string;
  provider: string;
  cards?: InsightCard[];
  sources?: string[];
  actions?: ActionItem[];
  intent?: string;
}

// ── Page label mapping ───────────────────────────────────────────────────────

const PAGE_LABELS: Record<string, string> = {
  "/app":               "Dashboard",
  "/app/orders":        "Orders",
  "/app/customers":     "Customers",
  "/app/mailbox":       "Mailbox",
  "/app/administration":"Administration",
  "/app/so-imports":    "Sales Order Imports",
  "/app/profile":       "Profile",
};

function resolvePageLabel(pathname: string): string {
  if (PAGE_LABELS[pathname]) return PAGE_LABELS[pathname];
  for (const [prefix, label] of Object.entries(PAGE_LABELS)) {
    if (pathname.startsWith(prefix + "/")) return label;
  }
  return "OrderWatch";
}

// ── Card components ──────────────────────────────────────────────────────────

const SEVERITY_STYLES: Record<string, string> = {
  high:   "border-destructive/50 bg-destructive/5 text-destructive",
  medium: "border-orange-300/50 bg-orange-50/50 text-orange-700 dark:text-orange-400",
  low:    "border-yellow-300/50 bg-yellow-50/50 text-yellow-700 dark:text-yellow-400",
};

function KpiCard({ card }: { card: InsightCard }) {
  return (
    <div className="rounded-lg border bg-card px-3 py-2 text-card-foreground">
      <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">{card.title}</p>
      <p className="mt-0.5 text-lg font-bold leading-none">{card.value}</p>
      {card.subtitle && <p className="mt-0.5 text-[10px] text-muted-foreground">{card.subtitle}</p>}
    </div>
  );
}

function RiskCard({ card }: { card: InsightCard }) {
  const style = SEVERITY_STYLES[card.severity ?? "medium"];
  return (
    <div className={cn("rounded-lg border px-3 py-2", style)}>
      <div className="flex items-center gap-1.5">
        <AlertTriangle className="h-3 w-3 shrink-0" />
        <p className="text-[10px] font-medium uppercase tracking-wide">{card.title}</p>
      </div>
      <p className="mt-0.5 text-base font-bold leading-none">{card.value}</p>
      {card.subtitle && <p className="mt-0.5 text-[10px] opacity-80">{card.subtitle}</p>}
    </div>
  );
}

function ComparisonCard({ card }: { card: InsightCard }) {
  const dir = card.trend?.direction;
  const TrendIcon = dir === "up" ? TrendingUp : dir === "down" ? TrendingDown : Minus;
  const trendColor = dir === "up" ? "text-green-600 dark:text-green-400" : dir === "down" ? "text-destructive" : "text-muted-foreground";

  return (
    <div className="rounded-lg border bg-card px-3 py-2">
      <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">{card.title}</p>
      <div className="mt-0.5 flex items-end gap-2">
        <p className="text-lg font-bold leading-none">{card.value}</p>
        {card.trend?.label && (
          <div className={cn("flex items-center gap-0.5 text-[10px] font-medium pb-0.5", trendColor)}>
            <TrendIcon className="h-3 w-3" />
            {card.trend.label}
          </div>
        )}
      </div>
      {card.subtitle && <p className="mt-0.5 text-[10px] text-muted-foreground">{card.subtitle}</p>}
    </div>
  );
}

function MatchCard({ card }: { card: InsightCard }) {
  return (
    <div className="rounded-lg border bg-card px-3 py-2 col-span-2">
      <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground mb-1.5">{card.title}</p>
      <div className="grid grid-cols-2 gap-x-4 gap-y-0.5">
        {card.items?.map((item) => (
          <div key={item.label} className="flex items-center justify-between">
            <span className="text-[10px] text-muted-foreground">{item.label}</span>
            <span className="text-xs font-semibold">{item.value}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

function CustomerCard({ card }: { card: InsightCard }) {
  return (
    <div className="rounded-lg border bg-card px-3 py-2">
      <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">{card.title}</p>
      <p className="mt-0.5 text-sm font-bold leading-tight truncate">{card.value}</p>
      {card.subtitle && <p className="mt-0.5 text-[10px] text-muted-foreground">{card.subtitle}</p>}
    </div>
  );
}

function InsightCardGrid({ cards }: { cards: InsightCard[] }) {
  if (!cards.length) return null;
  return (
    <div className="mt-2 grid grid-cols-2 gap-1.5">
      {cards.map((card, i) => {
        if (card.type === "match") return <MatchCard key={i} card={card} />;
        if (card.type === "risk")  return <RiskCard key={i} card={card} />;
        if (card.type === "comparison") return <ComparisonCard key={i} card={card} />;
        if (card.type === "customer")   return <CustomerCard key={i} card={card} />;
        return <KpiCard key={i} card={card} />;
      })}
    </div>
  );
}

// ── Source badges ────────────────────────────────────────────────────────────

const SOURCE_LABELS: Record<string, string> = {
  orders:    "Orders",
  emails:    "Emails",
  matches:   "Matches",
  customers: "Customers",
  cron:      "Cron Jobs",
};

function SourceBadges({ sources }: { sources: string[] }) {
  if (!sources.length) return null;
  return (
    <div className="mt-2 flex flex-wrap gap-1">
      {sources.map((s) => (
        <Badge key={s} variant="secondary" className="text-[9px] h-4 px-1.5">
          {SOURCE_LABELS[s] ?? s}
        </Badge>
      ))}
    </div>
  );
}

// ── Action chips ─────────────────────────────────────────────────────────────

function ActionChips({ actions }: { actions: ActionItem[] }) {
  if (!actions.length) return null;
  return (
    <div className="mt-2 flex flex-wrap gap-1">
      {actions.map((a) => (
        <a
          key={a.url}
          href={a.url}
          className="inline-flex items-center gap-1 rounded-full border bg-background px-2 py-0.5 text-[10px] text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
        >
          <ExternalLink className="h-2.5 w-2.5" />
          {a.label}
        </a>
      ))}
    </div>
  );
}

// ── Main component ────────────────────────────────────────────────────────────

export function AiAssistant() {
  const [open, setOpen]         = useState(false);
  const [input, setInput]       = useState("");
  const [messages, setMessages] = useState<Message[]>([]);
  const [loading, setLoading]   = useState(false);
  const [error, setError]       = useState<string | null>(null);
  const bottomRef               = useRef<HTMLDivElement>(null);
  const textareaRef             = useRef<HTMLTextAreaElement>(null);

  const pathname  = useRouterState({ select: (r) => r.location.pathname });
  const pageLabel = resolvePageLabel(pathname);

  useEffect(() => {
    if (open) bottomRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages, open]);

  useEffect(() => {
    if (open) setTimeout(() => textareaRef.current?.focus(), 50);
  }, [open]);

  async function send() {
    const prompt = input.trim();
    if (!prompt || loading) return;

    const userMsg: Message = { role: "user", content: prompt };
    const next = [...messages, userMsg];
    setMessages(next);
    setInput("");
    setError(null);
    setLoading(true);

    try {
      const res = await apiFetch<AiChatResponse>("ai/chat", {
        method: "POST",
        body: {
          prompt,
          page:    pageLabel,
          history: messages.slice(-10).map(({ role, content }) => ({ role, content })),
        },
      });

      const assistantMsg: Message = {
        role:    "assistant",
        content: res.message,
        cards:   res.cards   ?? [],
        sources: res.sources ?? [],
        actions: res.actions ?? [],
      };
      setMessages([...next, assistantMsg]);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Request failed.");
    } finally {
      setLoading(false);
    }
  }

  function handleKey(e: React.KeyboardEvent<HTMLTextAreaElement>) {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      send();
    }
  }

  function reset() {
    setMessages([]);
    setError(null);
    setInput("");
  }

  return (
    <>
      {/* Floating trigger */}
      <button
        type="button"
        aria-label="Open AI assistant"
        onClick={() => setOpen((v) => !v)}
        className={cn(
          "fixed bottom-5 right-5 z-50 flex h-12 w-12 items-center justify-center rounded-full shadow-lg transition-all duration-200",
          "bg-primary text-primary-foreground hover:bg-primary/90",
          open && "rotate-90 opacity-0 pointer-events-none",
        )}
      >
        <Sparkles className="h-5 w-5" />
      </button>

      {/* Panel */}
      <div
        className={cn(
          "fixed bottom-5 right-5 z-50 flex flex-col rounded-xl border bg-background shadow-2xl transition-all duration-200",
          "w-[380px] sm:w-[420px]",
          open
            ? "translate-y-0 opacity-100 pointer-events-auto"
            : "translate-y-4 opacity-0 pointer-events-none",
        )}
        style={{ height: "560px" }}
      >
        {/* Header */}
        <div className="flex items-center gap-2 border-b px-4 py-3">
          <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary/10">
            <Bot className="h-4 w-4 text-primary" />
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-sm font-semibold leading-tight">AI Assistant</p>
            <p className="text-[10px] text-muted-foreground truncate">{pageLabel}</p>
          </div>
          <Button variant="ghost" size="icon" className="h-7 w-7 shrink-0" onClick={reset} title="Clear conversation">
            <RotateCcw className="h-3.5 w-3.5" />
          </Button>
          <Button variant="ghost" size="icon" className="h-7 w-7 shrink-0" onClick={() => setOpen(false)}>
            <X className="h-4 w-4" />
          </Button>
        </div>

        {/* Messages */}
        <ScrollArea className="flex-1 px-4 py-3">
          {messages.length === 0 && (
            <div className="flex flex-col items-center justify-center h-full gap-3 text-center py-8">
              <Sparkles className="h-8 w-8 text-muted-foreground/40" />
              <p className="text-sm text-muted-foreground">
                Ask about your orders, customers, emails, or how this system works.
              </p>
              <div className="flex flex-wrap gap-1.5 justify-center">
                {[
                  "Summarise today's orders",
                  "Show unmatched emails",
                  "Which customers are at risk?",
                  "Compare this week vs last week",
                ].map((s) => (
                  <button
                    key={s}
                    type="button"
                    onClick={() => setInput(s)}
                    className="rounded-full border px-3 py-1 text-xs text-muted-foreground hover:bg-muted transition-colors"
                  >
                    {s}
                  </button>
                ))}
              </div>
            </div>
          )}

          <div className="space-y-4">
            {messages.map((m, i) => (
              <div key={i} className={cn("flex gap-2", m.role === "user" ? "justify-end" : "justify-start")}>
                {m.role === "assistant" && (
                  <div className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10">
                    <Bot className="h-3.5 w-3.5 text-primary" />
                  </div>
                )}
                <div className={cn("min-w-0", m.role === "user" ? "max-w-[80%]" : "flex-1")}>
                  <div
                    className={cn(
                      "rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed whitespace-pre-wrap break-words",
                      m.role === "user"
                        ? "bg-primary text-primary-foreground rounded-br-sm"
                        : "bg-muted rounded-bl-sm",
                    )}
                  >
                    {m.content}
                  </div>

                  {/* Insight cards */}
                  {m.role === "assistant" && !!m.cards?.length && (
                    <InsightCardGrid cards={m.cards} />
                  )}

                  {/* Source badges + actions */}
                  {m.role === "assistant" && (
                    <>
                      {!!m.sources?.length && <SourceBadges sources={m.sources} />}
                      {!!m.actions?.length && <ActionChips actions={m.actions} />}
                    </>
                  )}
                </div>
              </div>
            ))}

            {loading && (
              <div className="flex gap-2 justify-start">
                <div className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10">
                  <Bot className="h-3.5 w-3.5 text-primary" />
                </div>
                <div className="rounded-2xl rounded-bl-sm bg-muted px-3.5 py-2.5">
                  <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                </div>
              </div>
            )}

            {error && (
              <p className="text-xs text-destructive text-center py-1">{error}</p>
            )}
          </div>

          <div ref={bottomRef} />
        </ScrollArea>

        {/* Input */}
        <div className="border-t p-3">
          <div className="flex items-end gap-2">
            <Textarea
              ref={textareaRef}
              value={input}
              onChange={(e) => setInput(e.target.value)}
              onKeyDown={handleKey}
              placeholder="Ask anything… (Enter to send)"
              className="min-h-[40px] max-h-[120px] resize-none text-sm py-2.5"
              rows={1}
              disabled={loading}
            />
            <Button
              size="icon"
              className="h-10 w-10 shrink-0"
              onClick={send}
              disabled={!input.trim() || loading}
            >
              <Send className="h-4 w-4" />
            </Button>
          </div>
          <p className="mt-1.5 text-[10px] text-muted-foreground text-center">
            Shift+Enter for new line · context: {pageLabel}
          </p>
        </div>
      </div>
    </>
  );
}

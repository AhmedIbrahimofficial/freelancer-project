import { useMemo, useState } from "react";
import { Link, useRouterState } from "@tanstack/react-router";
import { motion } from "motion/react";
import {
  Bell,
  Gavel,
  LayoutDashboard,
  Menu,
  Plus,
  ShieldCheck,
  UserRound,
  Wallet,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Sheet, SheetContent, SheetTrigger } from "@/components/ui/sheet";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { ScrollArea } from "@/components/ui/scroll-area";
import { useStore } from "@/lib/store";
import { cn } from "@/lib/utils";

const nav = [
  { to: "/dashboard", label: "Contracts", Icon: LayoutDashboard },
  { to: "/wallet", label: "Escrow & payouts", Icon: Wallet },
  { to: "/verify", label: "Verification", Icon: ShieldCheck },
  { to: "/profile/maya-okonkwo", label: "Public profile", Icon: UserRound },
];

export function Logo({ className }: { className?: string | undefined }) {
  return (
    <Link to="/" className={cn("group flex items-center gap-2", className)}>
      <span className="grid h-8 w-8 place-items-center rounded-md bg-primary text-primary-foreground">
        <Gavel className="h-4 w-4" aria-hidden />
      </span>
      <span className="text-display text-lg tracking-tight">Escrowa</span>
    </Link>
  );
}

function timeAgo(iso: string) {
  const diff = Date.now() - new Date(iso).getTime();
  const mins = Math.round(diff / 60000);
  if (mins < 60) return `${Math.max(mins, 1)}m ago`;
  const hours = Math.round(mins / 60);
  if (hours < 24) return `${hours}h ago`;
  const days = Math.round(hours / 24);
  return `${days}d ago`;
}

function NotificationBell() {
  const { notifications, markAllRead, markRead } = useStore();
  const unread = notifications.filter((n) => !n.read).length;
  const [open, setOpen] = useState(false);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button variant="ghost" size="icon" className="press relative" aria-label="Notifications">
          <Bell className="h-5 w-5" aria-hidden />
          {unread > 0 && (
            <motion.span
              initial={{ scale: 0.6, opacity: 0 }}
              animate={{ scale: 1, opacity: 1 }}
              className="numeric absolute -top-0.5 -right-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-destructive px-1 text-[10px] font-semibold text-destructive-foreground"
            >
              {unread}
            </motion.span>
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-[22rem] p-0">
        <div className="flex items-center justify-between border-b border-border px-4 py-3">
          <p className="text-sm font-medium">Notifications</p>
          <button
            onClick={markAllRead}
            className="text-xs text-primary transition-colors hover:underline"
          >
            Mark all read
          </button>
        </div>
        <ScrollArea className="max-h-80">
          <ul className="divide-y divide-border">
            {notifications.map((n) => (
              <li key={n.id}>
                <Link
                  to={n.href ?? "/dashboard"}
                  onClick={() => {
                    markRead(n.id);
                    setOpen(false);
                  }}
                  className="block px-4 py-3 transition-colors hover:bg-secondary"
                >
                  <div className="flex items-start gap-2.5">
                    <span
                      className={cn(
                        "mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full",
                        n.read
                          ? "bg-transparent"
                          : n.tone === "warning"
                            ? "bg-warning"
                            : n.tone === "success"
                              ? "bg-success"
                              : "bg-primary",
                      )}
                      aria-hidden
                    />
                    <div className="min-w-0">
                      <p
                        className={cn(
                          "text-sm leading-snug",
                          n.read ? "text-muted-foreground" : "font-medium text-foreground",
                        )}
                      >
                        {n.title}
                      </p>
                      <p className="mt-0.5 text-xs leading-relaxed text-muted-foreground">
                        {n.body}
                      </p>
                      <p className="mt-1 text-[11px] text-muted-foreground">{timeAgo(n.at)}</p>
                    </div>
                  </div>
                </Link>
              </li>
            ))}
          </ul>
        </ScrollArea>
      </PopoverContent>
    </Popover>
  );
}

export function AppShell({ children }: { children: React.ReactNode }) {
  const { user } = useStore();
  const pathname = useRouterState({ select: (s) => s.location.pathname });
  const items = useMemo(() => nav, []);

  return (
    <div className="flex min-h-screen flex-col bg-background">
      <header className="sticky top-0 z-40 border-b border-border bg-background/85 backdrop-blur">
        <div className="mx-auto grid max-w-6xl grid-cols-[auto_1fr_auto] items-center gap-4 px-4 py-3 sm:px-6">
          <div className="flex min-w-0 items-center gap-6">
            <Logo />
            <nav className="hidden items-center gap-1 lg:flex">
              {items.map((item) => (
                <Link
                  key={item.to}
                  to={item.to}
                  className={cn(
                    "rounded-md px-3 py-1.5 text-sm transition-colors",
                    pathname.startsWith(item.to)
                      ? "bg-secondary font-medium text-foreground"
                      : "text-muted-foreground hover:text-foreground",
                  )}
                >
                  {item.label}
                </Link>
              ))}
            </nav>
          </div>
          <div />
          <div className="flex items-center gap-1.5">
            <Button asChild size="sm" className="press hidden sm:inline-flex">
              <Link to="/contracts/new">
                <Plus className="mr-1 h-4 w-4" aria-hidden />
                New contract
              </Link>
            </Button>
            <NotificationBell />
            <Link to="/profile/$handle" params={{ handle: user.handle }} className="press ml-1">
              <Avatar className="h-8 w-8 border border-border">
                <AvatarFallback className="bg-secondary text-xs font-medium">
                  {user.initials}
                </AvatarFallback>
              </Avatar>
            </Link>
            <Sheet>
              <SheetTrigger asChild>
                <Button variant="ghost" size="icon" className="lg:hidden" aria-label="Menu">
                  <Menu className="h-5 w-5" aria-hidden />
                </Button>
              </SheetTrigger>
              <SheetContent side="right" className="w-72 p-6">
                <Logo className="mb-6" />
                <nav className="flex flex-col gap-1">
                  {items.map((item) => (
                    <Link
                      key={item.to}
                      to={item.to}
                      className="flex items-center gap-2.5 rounded-md px-3 py-2.5 text-sm text-foreground transition-colors hover:bg-secondary"
                    >
                      <item.Icon className="h-4 w-4 text-muted-foreground" aria-hidden />
                      {item.label}
                    </Link>
                  ))}
                  <Link
                    to="/contracts/new"
                    className="mt-2 flex items-center gap-2.5 rounded-md bg-primary px-3 py-2.5 text-sm font-medium text-primary-foreground"
                  >
                    <Plus className="h-4 w-4" aria-hidden />
                    New contract
                  </Link>
                </nav>
              </SheetContent>
            </Sheet>
          </div>
        </div>
      </header>

      <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6 sm:py-10">{children}</main>

      <footer className="border-t border-border py-6">
        <div className="mx-auto flex max-w-6xl flex-col gap-2 px-4 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6">
          <p>
            Escrowa is a contract and mediation platform. Funds are held by Modulr FS, a licensed
            escrow partner — never by Escrowa.
          </p>
          <p className="numeric">Demo data · no live money moves</p>
        </div>
      </footer>
    </div>
  );
}
